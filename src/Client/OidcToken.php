<?php

declare(strict_types=1);

namespace Webf\TemporalBundle\Client;

/**
 * Access token fetched by `OidcApiKey`, as kept in memory and in the shared cache.
 *
 * @internal
 */
final readonly class OidcToken
{
    public function __construct(
        #[\SensitiveParameter]
        public string $value,
        public int $refreshAt,
    ) {}
}
