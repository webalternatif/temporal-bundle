<?php

declare(strict_types=1);

namespace Webf\TemporalBundle\Worker;

use Psr\Container\ContainerInterface;
use Temporal\Worker\WorkerFactoryInterface;
use Temporal\Worker\WorkerOptions;

/**
 * Registers every workflow and activity of the application on a new worker.
 */
final readonly class WorkerFactoryConfigurator
{
    /**
     * @param array<string, class-string> $activityClasses activity classes indexed by service id
     * @param list<class-string> $workflowClasses
     * @param iterable<ActivityResetterInterface> $activityResetters
     */
    public function __construct(
        private array $activityClasses,
        private array $workflowClasses,
        private ContainerInterface $activityContainer,
        private iterable $activityResetters = [],
    ) {}

    /**
     * Adds a worker polling the given task queue to the factory. It is the responsibility of the caller to dispatch
     * workflows and activities on the right task queue.
     */
    public function configure(WorkerFactoryInterface $factory, string $taskQueue, WorkerOptions $options): void
    {
        $worker = $factory->newWorker($taskQueue, $options);

        foreach ($this->activityClasses as $serviceId => $activityClass) {
            $worker->registerActivity($activityClass, function () use ($serviceId): mixed {
                foreach ($this->activityResetters as $resetter) {
                    $resetter->reset();
                }

                return $this->activityContainer->get($serviceId);
            });
        }

        $worker->registerWorkflowTypes(...$this->workflowClasses);
    }
}
