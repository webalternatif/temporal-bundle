<?php

declare(strict_types=1);

namespace Webf\TemporalBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Temporal\Worker\WorkerFactoryInterface;
use Temporal\Worker\WorkerOptions;
use Webf\TemporalBundle\Worker\WorkerFactoryConfigurator;

#[AsCommand(
    name: 'temporal:worker',
    description: 'Run a Temporal worker. This command should not be called directly but by RoadRunner (`rr serve`)',
)]
final class WorkerCommand extends Command
{
    /**
     * @param non-empty-list<string> $taskQueues
     */
    public function __construct(
        private readonly WorkerFactoryInterface $workerFactory,
        private readonly WorkerFactoryConfigurator $workerFactoryConfigurator,
        private readonly array $taskQueues,
        private readonly WorkerOptions $workerOptions,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addArgument(
            'task-queue',
            InputArgument::OPTIONAL,
            sprintf('The name of the task queue the worker should poll (all if not specified, among "%s")', implode(
                '", "',
                $this->taskQueues,
            )),
        );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string|null $taskQueue */
        $taskQueue = $input->getArgument('task-queue');

        if (null !== $taskQueue && !in_array($taskQueue, $this->taskQueues, strict: true)) {
            $output->writeln(sprintf(
                '<error>Unknown task queue "%s", expected one of "%s".</error>',
                $taskQueue,
                implode('", "', $this->taskQueues),
            ));

            return Command::INVALID;
        }

        foreach (null === $taskQueue ? $this->taskQueues : [$taskQueue] as $queue) {
            $this->workerFactoryConfigurator->configure($this->workerFactory, $queue, $this->workerOptions);
        }

        /** @var int */
        return $this->workerFactory->run();
    }
}
