<?php

declare(strict_types=1);

namespace Webf\TemporalBundle\Command;

use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Temporal\Worker\Transport\RPCConnectionInterface;
use Webf\TemporalBundle\Client\OidcApiKey;

/**
 * The RoadRunner Temporal plugin connects to the Temporal server on its own (it is not the PHP worker that does it), so
 * it also needs a fresh API key before the previous one expires.
 */
#[AsCommand(
    name: 'temporal:refresh-api-key',
    description: 'Give a fresh API key to the Temporal plugin of RoadRunner as long as it runs. This command should not be called directly but by RoadRunner (`rr serve`)',
)]
final class RefreshApiKeyCommand extends Command
{
    /**
     * Delay (in seconds) before trying again when the key could not be pushed, and minimal delay between two pushes,
     * so a token endpoint returning short-lived tokens does not lead to a busy loop.
     */
    public const int RETRY_DELAY = 5;

    /**
     * RPC method of the RoadRunner Temporal plugin that replaces its API key. The SDK has no constant for it.
     *
     * @see \Temporal\Worker\ServiceCredentials::withApiKey()
     */
    public const string UPDATE_API_KEY_RPC_METHOD = 'temporal.UpdateAPIKey';

    public function __construct(
        #[\SensitiveParameter]
        private readonly OidcApiKey $apiKey,
        private readonly RPCConnectionInterface $rpc,
        // `Clock` delegates to the global clock, which is the one configured by FrameworkBundle when available.
        private readonly ClockInterface $clock = new Clock(),
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // RoadRunner stops this command with a signal when it stops itself.
        while (true) {
            try {
                $this->rpc->call(self::UPDATE_API_KEY_RPC_METHOD, (string) $this->apiKey);

                $delay = max(self::RETRY_DELAY, $this->apiKey->willRefreshAt() - $this->clock->now()->getTimestamp());
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>Could not update the Temporal API key: %s</error>', $e->getMessage()));

                $delay = self::RETRY_DELAY;
            }

            $this->clock->sleep($delay);
        }
    }
}
