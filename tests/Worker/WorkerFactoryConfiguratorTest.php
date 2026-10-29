<?php

declare(strict_types=1);

namespace Tests\Webf\TemporalBundle\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Temporal\Worker\WorkerFactoryInterface;
use Temporal\Worker\WorkerInterface;
use Temporal\Worker\WorkerOptions;
use Tests\Webf\TemporalBundle\Fixtures\Activity\GreetingActivity;
use Tests\Webf\TemporalBundle\Fixtures\Workflow\GreetingWorkflow;
use Webf\TemporalBundle\Worker\ActivityResetterInterface;
use Webf\TemporalBundle\Worker\WorkerFactoryConfigurator;

#[CoversClass(WorkerFactoryConfigurator::class)]
final class WorkerFactoryConfiguratorTest extends TestCase
{
    public function test_workflows_and_activities_are_registered_on_a_new_worker(): void
    {
        $options = WorkerOptions::new();
        /** @var list<array{string, callable|null}> $registeredActivities */
        $registeredActivities = [];

        $worker = $this->createMock(WorkerInterface::class);
        $worker
            ->expects($this->exactly(2))
            ->method('registerActivity')
            ->willReturnCallback(static function (string $type, ?callable $factory) use (
                &$registeredActivities,
                $worker,
            ) {
                $registeredActivities[] = [$type, $factory];

                return $worker;
            });
        $worker
            ->expects($this->once())
            ->method('registerWorkflowTypes')
            ->with(GreetingWorkflow::class, \stdClass::class)
            ->willReturnSelf();

        $factory = $this->createMock(WorkerFactoryInterface::class);
        $factory->expects($this->once())->method('newWorker')->with('queue', $options)->willReturn($worker);

        $events = [];
        $activity = new GreetingActivity();
        $configurator = new WorkerFactoryConfigurator(
            ['greeting' => GreetingActivity::class, 'other' => \stdClass::class],
            [GreetingWorkflow::class, \stdClass::class],
            new ServiceLocator([
                'greeting' => static function () use (&$events, $activity): GreetingActivity {
                    $events[] = 'get greeting';

                    return $activity;
                },
                'other' => static fn(): \stdClass => new \stdClass(),
            ]),
            [$this->createResetter($events, 'first'), $this->createResetter($events, 'second')],
        );

        $configurator->configure($factory, 'queue', $options);

        static::assertSame([GreetingActivity::class, \stdClass::class], array_column($registeredActivities, 0));
        static::assertSame([], $events, 'Activities must not be instantiated before being executed.');

        $greetingFactory = $registeredActivities[0][1];
        static::assertNotNull($greetingFactory);
        static::assertSame($activity, $greetingFactory());
        static::assertSame(['reset first', 'reset second', 'get greeting'], $events);
    }

    /**
     * @param list<string> $events
     */
    private function createResetter(array &$events, string $name): ActivityResetterInterface
    {
        return new class($events, $name) implements ActivityResetterInterface {
            /**
             * @param list<string> $events
             */
            public function __construct(
                private array &$events,
                private readonly string $name,
            ) {}

            #[\Override]
            public function reset(): void
            {
                $this->events[] = 'reset ' . $this->name;
            }
        };
    }
}
