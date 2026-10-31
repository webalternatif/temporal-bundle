<?php

declare(strict_types=1);

namespace Webf\TemporalBundle\Client;

/**
 * Credentials of an OIDC client, and how they are sent to the token endpoint.
 */
final readonly class ClientConfig
{
    public function __construct(
        public string $id,
        #[\SensitiveParameter]
        public string $secret,
        public ClientAuthMethod $authMethod = ClientAuthMethod::ClientSecretBasic,
    ) {}
}
