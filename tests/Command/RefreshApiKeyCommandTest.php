<?php

declare(strict_types=1);

namespace Tests\Webf\TemporalBundle\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Temporal\Worker\Transport\RPCConnectionInterface;
use Tests\Webf\TemporalBundle\Fixtures\StopLoop;
use Tests\Webf\TemporalBundle\Fixtures\StoppingClock;
use Webf\TemporalBundle\Client\ClientConfig;
use Webf\TemporalBundle\Client\OidcApiKey;
use Webf\TemporalBundle\Command\RefreshApiKeyCommand;

#[CoversClass(RefreshApiKeyCommand::class)]
#[UsesClass(ClientConfig::class)]
#[UsesClass(OidcApiKey::class)]
final class RefreshApiKeyCommandTest extends TestCase
{
    public function test_key_is_pushed_before_it_expires(): void
    {
        $clock = $this->createClock(sleepsBeforeStop: 2);
        $apiKey = $this->createApiKey($clock, [
            new JsonMockResponse(['access_token' => 'first', 'expires_in' => 3600]),
            new JsonMockResponse(['access_token' => 'second', 'expires_in' => 3600]),
        ]);

        $rpc = $this->createMock(RPCConnectionInterface::class);
        $calls = [];
        $rpc
            ->expects($this->exactly(2))
            ->method('call')
            ->willReturnCallback(static function (string $method, mixed $payload) use (&$calls): mixed {
                $calls[] = [$method, $payload];

                return null;
            });

        $tester = new CommandTester(new RefreshApiKeyCommand($apiKey, $rpc, $clock));

        $this->executeUntilStopped($tester);

        static::assertSame(
            [
                [RefreshApiKeyCommand::UPDATE_API_KEY_RPC_METHOD, 'first'],
                [RefreshApiKeyCommand::UPDATE_API_KEY_RPC_METHOD, 'second'],
            ],
            $calls,
        );
        static::assertSame([1800, 1800], $clock->sleeps);
        static::assertSame('', $tester->getDisplay());
    }

    public function test_keys_are_not_pushed_more_often_than_the_retry_delay(): void
    {
        $clock = $this->createClock(sleepsBeforeStop: 1);
        $apiKey = $this->createApiKey($clock, [new JsonMockResponse(['access_token' => 'token', 'expires_in' => 2])]);

        $tester = new CommandTester(
            new RefreshApiKeyCommand($apiKey, $this->createStub(RPCConnectionInterface::class), $clock),
        );

        $this->executeUntilStopped($tester);

        static::assertSame([RefreshApiKeyCommand::RETRY_DELAY], $clock->sleeps);
    }

    public function test_failures_are_retried(): void
    {
        $clock = $this->createClock(sleepsBeforeStop: 2);
        $apiKey = $this->createApiKey($clock, [
            new MockResponse('', ['http_code' => 500]),
            new JsonMockResponse(['access_token' => 'token', 'expires_in' => 60]),
        ]);

        $rpc = $this->createMock(RPCConnectionInterface::class);
        $rpc->expects($this->once())->method('call')->with(RefreshApiKeyCommand::UPDATE_API_KEY_RPC_METHOD, 'token');

        $tester = new CommandTester(new RefreshApiKeyCommand($apiKey, $rpc, $clock));

        $this->executeUntilStopped($tester);

        static::assertSame([RefreshApiKeyCommand::RETRY_DELAY, 30], $clock->sleeps);
        static::assertSame(
            'Could not update the Temporal API key: Unable to fetch a token from "https://oidc.example.com/token".'
            . PHP_EOL,
            $tester->getDisplay(),
        );
    }

    public function test_command_is_named(): void
    {
        $command = new RefreshApiKeyCommand(
            $this->createApiKey(new MockClock(), []),
            $this->createStub(RPCConnectionInterface::class),
        );

        static::assertSame('temporal:refresh-api-key', $command->getName());
    }

    private function executeUntilStopped(CommandTester $tester): void
    {
        try {
            $tester->execute([]);
        } catch (StopLoop) {
            return;
        }

        static::fail('The command must loop until it is stopped.');
    }

    private function createClock(int $sleepsBeforeStop): StoppingClock
    {
        return new StoppingClock(new MockClock('@1000000000'), $sleepsBeforeStop);
    }

    /**
     * @param iterable<MockResponse> $responses
     */
    private function createApiKey(ClockInterface $clock, iterable $responses): OidcApiKey
    {
        return new OidcApiKey(
            'https://oidc.example.com/token',
            new ClientConfig('id', 'secret'),
            new MockHttpClient($responses),
            $clock,
        );
    }
}
