<?php

declare(strict_types=1);

namespace Tests\Webf\TemporalBundle\Fixtures;

use Temporal\Client\GRPC\ServiceClientInterface;

/**
 * Keeps the service client in the container, which removes unused private services before tests can get them.
 */
final readonly class ServiceClientHolder
{
    public function __construct(
        public ServiceClientInterface $client,
    ) {}
}
