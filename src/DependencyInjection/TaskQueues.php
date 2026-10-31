<?php

declare(strict_types=1);

namespace Webf\TemporalBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * Rules of the "task_queues" option, kept out of `WebfTemporalBundle` to keep it simple.
 *
 * @internal
 */
final class TaskQueues
{
    /**
     * A string that is not a string-backed enum is rejected instead of being treated as a single task queue, so that a
     * typo in the enum name does not silently become a task queue name.
     *
     * @return list<string>
     */
    public static function normalizeEnum(string $enum): array
    {
        $reflection = enum_exists($enum) ? new \ReflectionEnum($enum) : null;
        if ('string' !== $reflection?->getBackingType()?->getName()) {
            throw new InvalidConfigurationException(sprintf(
                'The path "webf_temporal.task_queues" must be a list of task queues or the name of a string-backed enum, "%s" given.',
                $enum,
            ));
        }

        // Every case of a backed enum is a backed case.
        /** @var list<\ReflectionEnumBackedCase> $cases */
        $cases = $reflection->getCases();

        // Backing values are strings, as checked above (a cast would be an equivalent mutant for Infection).
        /** @var list<string> */
        return array_map(static fn(\ReflectionEnumBackedCase $case): int|string => $case->getBackingValue(), $cases);
    }

    /**
     * @param list<string> $taskQueues
     *
     * @return list<string>
     */
    public static function rejectDuplicates(array $taskQueues): array
    {
        // Values are compared as strings, like the names of the workers in the SDK.
        $duplicates = array_unique(array_diff_assoc($taskQueues, array_unique($taskQueues)));
        if ([] !== $duplicates) {
            throw new \InvalidArgumentException(sprintf('Task queues must be unique, "%s" given more than once.', implode(
                '", "',
                $duplicates,
            )));
        }

        return $taskQueues;
    }
}
