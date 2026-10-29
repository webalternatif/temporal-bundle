<?php

declare(strict_types=1);

namespace Tests\Webf\TemporalBundle\Fixtures\Workflow;

use Temporal\Activity\ActivityOptions;
use Temporal\Workflow;
use Temporal\Workflow\WorkflowInterface;
use Temporal\Workflow\WorkflowMethod;
use Tests\Webf\TemporalBundle\Fixtures\Activity\GreetingActivity;

#[WorkflowInterface]
final class GreetingWorkflow
{
    /**
     * @return \Generator<mixed, mixed, mixed, mixed>
     */
    #[WorkflowMethod]
    public function greet(string $name): \Generator
    {
        $activity = Workflow::newActivityStub(
            GreetingActivity::class,
            ActivityOptions::new()->withStartToCloseTimeout('5s'),
        );

        return yield $activity->greet($name);
    }
}
