<?php

declare(strict_types=1);

namespace Tests\Webf\TemporalBundle\Client;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Webf\TemporalBundle\Client\StaticApiKey;

#[CoversClass(StaticApiKey::class)]
final class StaticApiKeyTest extends TestCase
{
    public function test_key_is_given_as_is(): void
    {
        static::assertSame('secret', (string) new StaticApiKey('secret'));
    }

    public function test_null_key_is_empty_so_that_no_key_is_sent(): void
    {
        static::assertSame('', (string) new StaticApiKey(null));
    }
}
