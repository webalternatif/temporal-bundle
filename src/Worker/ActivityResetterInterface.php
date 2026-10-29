<?php

declare(strict_types=1);

namespace Webf\TemporalBundle\Worker;

/**
 * Services implementing this interface are called before each activity instantiation, to give a clean state to
 * activities executed in long-running worker processes (e.g. a Doctrine entity manager closed by a previous activity).
 */
interface ActivityResetterInterface
{
    public function reset(): void;
}
