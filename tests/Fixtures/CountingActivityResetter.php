<?php

declare(strict_types=1);

namespace Tests\Webf\TemporalBundle\Fixtures;

use Webf\TemporalBundle\Worker\ActivityResetterInterface;

final class CountingActivityResetter implements ActivityResetterInterface
{
    public int $count = 0;

    #[\Override]
    public function reset(): void
    {
        ++$this->count;
    }
}
