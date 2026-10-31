<?php

declare(strict_types=1);

namespace Tests\Webf\TemporalBundle\Fixtures;

use Temporal\Client\GRPC\ServiceClient;

/**
 * A service client that can be built without the gRPC extension (which the test environment does not have), to check
 * how the bundle configures it. It cannot make any call.
 */
final class GrpcLessServiceClient extends ServiceClient
{
    #[\Override]
    public static function create(string $address): static
    {
        return (new \ReflectionClass(static::class))->newInstanceWithoutConstructor();
    }
}
