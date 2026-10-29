<?php

declare(strict_types=1);

namespace Tests\Webf\TemporalBundle\Client;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Webf\TemporalBundle\Client\OidcApiKey;
use Webf\TemporalBundle\Exception\OidcTokenException;

#[CoversClass(OidcApiKey::class)]
final class OidcApiKeyTest extends TestCase
{
    private const string TOKEN_ENDPOINT = 'https://oidc.example.com/token';

    private MockClock $clock;

    #[\Override]
    protected function setUp(): void
    {
        $this->clock = new MockClock('@1000000000');
    }

    public function test_token_is_fetched_with_client_credentials(): void
    {
        $requests = [];
        $apiKey = $this->createApiKey(static function (string $method, string $url, array $options) use (&$requests) {
            $requests[] = [$method, $url, $options['body']];

            return new JsonMockResponse(['access_token' => 'token', 'expires_in' => 3600]);
        });

        static::assertSame('token', (string) $apiKey);
        static::assertSame(
            [['POST', self::TOKEN_ENDPOINT, 'client_id=id&client_secret=secret&grant_type=client_credentials']],
            $requests,
        );
    }

    public function test_token_is_reused_until_half_of_its_lifetime(): void
    {
        $apiKey = $this->createApiKey([
            new JsonMockResponse(['access_token' => 'first', 'expires_in' => 3601]),
            new JsonMockResponse(['access_token' => 'second', 'expires_in' => 3600]),
        ]);

        static::assertSame('first', (string) $apiKey);

        $this->clock->sleep(1799);
        static::assertSame('first', (string) $apiKey);

        $this->clock->sleep(1);
        static::assertSame('second', (string) $apiKey);
    }

    public function test_will_refresh_at_fetches_a_token_first(): void
    {
        $apiKey = $this->createApiKey([
            new JsonMockResponse(['access_token' => 'first', 'expires_in' => 3600]),
            new JsonMockResponse(['access_token' => 'second', 'expires_in' => 60]),
        ]);

        static::assertSame(1_000_001_800, $apiKey->willRefreshAt());
        static::assertSame(1_000_001_800, $apiKey->willRefreshAt());

        $this->clock->sleep(1800);
        static::assertSame(1_000_001_830, $apiKey->willRefreshAt());
        static::assertSame('second', (string) $apiKey);
    }

    public function test_http_errors_are_wrapped(): void
    {
        $apiKey = $this->createApiKey([new MockResponse('', ['http_code' => 500])]);

        try {
            (string) $apiKey;
            static::fail('An exception must be thrown.');
        } catch (OidcTokenException $e) {
            static::assertSame('Unable to fetch a token from "https://oidc.example.com/token".', $e->getMessage());
            static::assertInstanceOf(ServerExceptionInterface::class, $e->getPrevious());
        }
    }

    /**
     * @param array<string, mixed> $response
     */
    #[DataProvider('provideInvalidResponses')]
    public function test_invalid_responses_are_rejected(array $response): void
    {
        $apiKey = $this->createApiKey([new JsonMockResponse($response)]);

        $this->expectException(OidcTokenException::class);
        $this->expectExceptionMessage(
            'The response of "https://oidc.example.com/token" does not contain a valid token.',
        );

        (string) $apiKey;
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideInvalidResponses(): iterable
    {
        yield 'no access token' => [['expires_in' => 3600]];
        yield 'no expiration' => [['access_token' => 'token']];
        yield 'non string access token' => [['access_token' => 42, 'expires_in' => 3600]];
        yield 'non integer expiration' => [['access_token' => 'token', 'expires_in' => '3600']];
    }

    public function test_default_http_client_and_clock_are_usable(): void
    {
        $apiKey = new OidcApiKey(self::TOKEN_ENDPOINT, 'id', 'secret');

        static::assertInstanceOf(\Stringable::class, $apiKey);
    }

    /**
     * @param callable|iterable<MockResponse> $responses
     */
    private function createApiKey(callable|iterable $responses): OidcApiKey
    {
        return new OidcApiKey(self::TOKEN_ENDPOINT, 'id', 'secret', new MockHttpClient($responses), $this->clock);
    }
}
