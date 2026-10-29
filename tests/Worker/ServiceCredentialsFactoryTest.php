<?php

declare(strict_types=1);

namespace Tests\Webf\TemporalBundle\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webf\TemporalBundle\Worker\ServiceCredentialsFactory;

#[CoversClass(ServiceCredentialsFactory::class)]
final class ServiceCredentialsFactoryTest extends TestCase
{
    public function test_api_key_is_given_as_string(): void
    {
        $apiKey = new class implements \Stringable {
            #[\Override]
            public function __toString(): string
            {
                return 'stringable';
            }
        };

        static::assertSame('key', ServiceCredentialsFactory::create('key')->apiKey);
        static::assertSame('stringable', ServiceCredentialsFactory::create($apiKey)->apiKey);
    }
}
