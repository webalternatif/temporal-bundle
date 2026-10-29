<?php

declare(strict_types=1);

namespace Tests\Webf\TemporalBundle\Fixtures;

enum TaskQueue: string
{
    case First = 'first';
    case Second = 'second';
}
