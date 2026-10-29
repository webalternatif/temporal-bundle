<?php

declare(strict_types=1);

namespace Webf\TemporalBundle\Worker;

use Temporal\Worker\ServiceCredentials;

/**
 * Gives the API key to RoadRunner when the worker starts, so its Temporal plugin is authenticated even before the
 * `temporal:refresh-api-key` command (if any) pushes its first key.
 */
final class ServiceCredentialsFactory
{
    public static function create(#[\SensitiveParameter] \Stringable|string $apiKey): ServiceCredentials
    {
        return ServiceCredentials::create()->withApiKey((string) $apiKey);
    }
}
