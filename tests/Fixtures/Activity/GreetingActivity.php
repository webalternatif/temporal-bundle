<?php

declare(strict_types=1);

namespace Tests\Webf\TemporalBundle\Fixtures\Activity;

use Temporal\Activity\ActivityInterface;

#[ActivityInterface]
final class GreetingActivity
{
    public function greet(string $name): string
    {
        return sprintf('Hello %s!', $name);
    }
}
