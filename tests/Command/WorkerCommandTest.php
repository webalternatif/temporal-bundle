<?php

declare(strict_types=1);

namespace Tests\Webf\TemporalBundle\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Temporal\Worker\WorkerFactoryInterface;
use Temporal\Worker\WorkerInterface;
use Temporal\Worker\WorkerOptions;
use Webf\TemporalBundle\Command\WorkerCommand;
use Webf\TemporalBundle\Worker\WorkerFactoryConfigurator;

#[CoversClass(WorkerCommand::class)]
#[UsesClass(WorkerFactoryConfigurator::class)]
final class WorkerCommandTest extends TestCase
{
    public function test_all_task_queues_are_polled_by_default(): void
    {
        $options = WorkerOptions::new();
        $factory = $this->createWorkerFactory(['first', 'second'], $options, exitCode: 42);

        $tester = new CommandTester(
            new WorkerCommand($factory, $this->createConfigurator(), ['first', 'second'], $options),
        );

        static::assertSame(42, $tester->execute([]));
    }

    public function test_a_single_task_queue_can_be_polled(): void
    {
        $options = WorkerOptions::new();
        $factory = $this->createWorkerFactory(['second'], $options, exitCode: 0);

        $tester = new CommandTester(
            new WorkerCommand($factory, $this->createConfigurator(), ['first', 'second'], $options),
        );

        static::assertSame(Command::SUCCESS, $tester->execute(['task-queue' => 'second']));
    }

    public function test_unknown_task_queues_are_rejected(): void
    {
        $factory = $this->createMock(WorkerFactoryInterface::class);
        $factory->expects($this->never())->method('newWorker');
        $factory->expects($this->never())->method('run');

        $tester = new CommandTester(
            new WorkerCommand($factory, $this->createConfigurator(), ['first', 'second'], WorkerOptions::new()),
        );

        static::assertSame(Command::INVALID, $tester->execute(['task-queue' => 'unknown']));
        static::assertSame(
            'Unknown task queue "unknown", expected one of "first", "second".' . PHP_EOL,
            $tester->getDisplay(),
        );
    }

    public function test_known_task_queues_are_listed_in_the_argument_description(): void
    {
        $command = new WorkerCommand(
            $this->createStub(WorkerFactoryInterface::class),
            $this->createConfigurator(),
            ['first', 'second'],
            WorkerOptions::new(),
        );

        static::assertSame('temporal:worker', $command->getName());
        $argument = $command->getDefinition()->getArgument('task-queue');
        static::assertFalse($argument->isRequired());
        static::assertSame(
            'The name of the task queue the worker should poll (all if not specified, among "first", "second")',
            $argument->getDescription(),
        );
    }

    /**
     * @param list<string> $expectedTaskQueues
     */
    private function createWorkerFactory(
        array $expectedTaskQueues,
        WorkerOptions $expectedOptions,
        int $exitCode,
    ): WorkerFactoryInterface {
        $events = [];
        $factory = $this->createMock(WorkerFactoryInterface::class);
        $factory
            ->expects($this->exactly(count($expectedTaskQueues)))
            ->method('newWorker')
            ->willReturnCallback(function (string $taskQueue, WorkerOptions $options) use (&$events, $expectedOptions) {
                static::assertSame($expectedOptions, $options);
                $events[] = $taskQueue;

                return $this->createStub(WorkerInterface::class);
            });
        $factory
            ->expects($this->once())
            ->method('run')
            ->willReturnCallback(static function () use (&$events, $expectedTaskQueues, $exitCode): int {
                static::assertSame($expectedTaskQueues, $events, 'Workers must be created before running the factory.');

                return $exitCode;
            });

        return $factory;
    }

    private function createConfigurator(): WorkerFactoryConfigurator
    {
        return new WorkerFactoryConfigurator([], [], new ServiceLocator([]));
    }
}
