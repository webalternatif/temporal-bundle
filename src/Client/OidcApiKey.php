<?php

declare(strict_types=1);

namespace Webf\TemporalBundle\Client;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Webf\TemporalBundle\Exception\OidcTokenException;

/**
 * API key fetched from an OIDC token endpoint with the client credentials grant.
 *
 * The Temporal client reads its key on every call, so a `Stringable` keeps a long living client authenticated without
 * having to build a new one when the token expires.
 */
final class OidcApiKey implements \Stringable
{
    private ?string $token = null;
    private int $refreshAt = 0;
    private readonly HttpClientInterface $httpClient;

    public function __construct(
        private readonly string $tokenEndpoint,
        private readonly string $clientId,
        #[\SensitiveParameter]
        private readonly string $clientSecret,
        ?HttpClientInterface $httpClient = null,
        // `Clock` delegates to the global clock, which is the one configured by FrameworkBundle when available.
        private readonly ClockInterface $clock = new Clock(),
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create();
    }

    /**
     * @throws OidcTokenException
     */
    #[\Override]
    public function __toString(): string
    {
        if (null === $this->token || $this->refreshAt <= $this->now()) {
            return $this->fetchToken();
        }

        return $this->token;
    }

    /**
     * Timestamp from which `__toString()` fetches a new token. A token is fetched if it has not been fetched yet or if
     * it should be refreshed, so the returned timestamp is always in the future.
     *
     * @throws OidcTokenException
     */
    public function willRefreshAt(): int
    {
        $this->__toString();

        return $this->refreshAt;
    }

    /**
     * @throws OidcTokenException
     */
    private function fetchToken(): string
    {
        try {
            $result = $this->httpClient->request('POST', $this->tokenEndpoint, [
                'body' => [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'grant_type' => 'client_credentials',
                ],
            ])->toArray();
        } catch (\Throwable $e) {
            throw new OidcTokenException(
                sprintf('Unable to fetch a token from "%s".', $this->tokenEndpoint),
                previous: $e,
            );
        }

        if (!is_string($result['access_token'] ?? null) || !is_int($result['expires_in'] ?? null)) {
            throw new OidcTokenException(sprintf(
                'The response of "%s" does not contain a valid token.',
                $this->tokenEndpoint,
            ));
        }

        // Half of the lifetime, so a slow call or a clock drift never leads to an expired token.
        $this->refreshAt = $this->now() + intdiv(num1: $result['expires_in'], num2: 2);

        return $this->token = $result['access_token'];
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
