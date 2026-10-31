<?php

declare(strict_types=1);

namespace Webf\TemporalBundle\Client;

/**
 * The API key of the `api_key` option, read only when the key is used.
 *
 * An env placeholder (e.g. `%env(default::TEMPORAL_API_KEY)%`) hides the value of the option at compile time, so the
 * bundle cannot skip authentication for a null key, as it does when the option itself is null. A null key becomes an
 * empty string instead, because the SDK (clients and RoadRunner credentials) sends no key when it is empty.
 */
final readonly class StaticApiKey implements \Stringable
{
    public function __construct(
        #[\SensitiveParameter]
        private ?string $key,
    ) {}

    #[\Override]
    public function __toString(): string
    {
        return $this->key ?? '';
    }
}
