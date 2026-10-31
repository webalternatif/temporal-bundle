<?php

declare(strict_types=1);

namespace Webf\TemporalBundle\Client;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Webf\TemporalBundle\Exception\OidcTokenException;

/**
 * API key fetched from an OIDC token endpoint with the client credentials grant.
 *
 * The Temporal client reads its key on every call, so a `Stringable` keeps a long living client authenticated without
 * having to build a new one when the token expires.
 *
 * Each PHP-FPM request and each RoadRunner worker builds its own instance, so the token is also kept in an optional
 * cache shared between processes. Otherwise each of them would fetch its own token.
 */
final class OidcApiKey implements \Stringable
{
    private ?OidcToken $token = null;
    private readonly HttpClientInterface $httpClient;

    public function __construct(
        private readonly string $tokenEndpoint,
        private readonly ClientConfig $client,
        ?HttpClientInterface $httpClient = null,
        // `Clock` delegates to the global clock, which is the one configured by FrameworkBundle when available.
        private readonly ClockInterface $clock = new Clock(),
        // Symfony cache contracts rather than PSR-6, because their `get()` protects against cache stampedes: processes
        // that miss the token at the same time do not all fetch a new one.
        private readonly ?CacheInterface $cache = null,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create();
    }

    /**
     * @throws OidcTokenException
     */
    #[\Override]
    public function __toString(): string
    {
        return $this->getToken()->value;
    }

    /**
     * Timestamp from which `__toString()` fetches a new token. A token is fetched if it has not been fetched yet or if
     * it should be refreshed, so the returned timestamp is always in the future.
     *
     * @throws OidcTokenException
     */
    public function willRefreshAt(): int
    {
        return $this->getToken()->refreshAt;
    }

    /**
     * @throws OidcTokenException
     */
    private function getToken(): OidcToken
    {
        if (null === $this->token || $this->token->refreshAt <= $this->now()) {
            $this->token = $this->getSharedToken();
        }

        return $this->token;
    }

    /**
     * @throws OidcTokenException
     */
    private function getSharedToken(): OidcToken
    {
        $cache = $this->cache;
        if (null === $cache) {
            return $this->fetchToken();
        }

        // Hashed because PSR-6 forbids some characters of URLs (such as ":" and "/") in keys. The secret is not part of
        // the key: a token stays valid when the secret changes.
        $key = 'webf_temporal.oidc_token.' . hash('xxh128', serialize([$this->tokenEndpoint, $this->client->id]));
        $fetchToken = function (ItemInterface $item): OidcToken {
            $token = $this->fetchToken();
            $item->expiresAfter($token->refreshAt - $this->now());

            return $token;
        };

        $token = $cache->get($key, $fetchToken);
        // The cache expires items with its own clock, and other processes may run on hosts whose clock is late. A beta
        // of `INF` forces a new fetch (see `CacheInterface::get()`).
        if ($token->refreshAt <= $this->now()) {
            return $cache->get($key, $fetchToken, INF);
        }

        return $token;
    }

    /**
     * @throws OidcTokenException
     */
    private function fetchToken(): OidcToken
    {
        $options = match ($this->client->authMethod) {
            // RFC 6749 §2.3.1 encodes both values with "application/x-www-form-urlencoded" before joining them, so a
            // ":" in the client ID or any non ASCII character survives. `auth_basic` does not encode them itself.
            ClientAuthMethod::ClientSecretBasic => [
                'auth_basic' => [urlencode($this->client->id), urlencode($this->client->secret)],
                'body' => ['grant_type' => 'client_credentials'],
            ],
            ClientAuthMethod::ClientSecretPost => [
                'body' => [
                    'client_id' => $this->client->id,
                    'client_secret' => $this->client->secret,
                    'grant_type' => 'client_credentials',
                ],
            ],
        };

        try {
            $result = $this->httpClient->request('POST', $this->tokenEndpoint, $options)->toArray();
        } catch (\Throwable $e) {
            throw new OidcTokenException(
                sprintf('Unable to fetch a token from "%s".', $this->tokenEndpoint),
                previous: $e,
            );
        }

        $expiresIn = self::parseExpiresIn($result['expires_in'] ?? null);
        if (!is_string($result['access_token'] ?? null) || null === $expiresIn || $expiresIn < 1) {
            throw new OidcTokenException(sprintf(
                'The response of "%s" does not contain a valid token.',
                $this->tokenEndpoint,
            ));
        }

        // Half of the lifetime, so a slow call or a clock drift never leads to an expired token. At least one second,
        // otherwise a token living less than two seconds would be fetched again on every gRPC call.
        return new OidcToken($result['access_token'], $this->now() + max(1, intdiv(num1: $expiresIn, num2: 2)));
    }

    /**
     * Some servers, such as Azure AD v1, send the lifetime as a string. `is_numeric()` is not used because it accepts
     * floats, exponents and surrounding whitespace, and `\z` because `$` also matches before a final "\n".
     */
    private static function parseExpiresIn(mixed $expiresIn): ?int
    {
        if (is_int($expiresIn)) {
            return $expiresIn;
        }

        if (is_string($expiresIn) && 1 === preg_match('/\A\d+\z/', $expiresIn)) {
            return (int) $expiresIn;
        }

        return null;
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
