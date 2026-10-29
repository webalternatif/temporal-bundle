<?php

declare(strict_types=1);

namespace Tests\Webf\TemporalBundle\Fixtures;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

/**
 * Clock which stops infinite loops by throwing {@see StopLoop} from `sleep()`.
 */
final class StoppingClock implements ClockInterface
{
    /** @var list<float|int> */
    public array $sleeps = [];

    public function __construct(
        private readonly MockClock $clock,
        private readonly int $sleepsBeforeStop,
    ) {}

    #[\Override]
    public function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }

    /**
     * @throws StopLoop
     */
    #[\Override]
    public function sleep(float|int $seconds): void
    {
        $this->sleeps[] = $seconds;
        $this->clock->sleep($seconds);

        if (count($this->sleeps) >= $this->sleepsBeforeStop) {
            throw new StopLoop();
        }
    }

    #[\Override]
    public function withTimeZone(\DateTimeZone|string $timezone): static
    {
        throw new \LogicException('Not implemented.');
    }
}
