<?php

declare(strict_types=1);

namespace Webf\TemporalBundle\Client;

/**
 * How `OidcApiKey` authenticates to the token endpoint. The values are the names registered for the
 * `token_endpoint_auth_methods_supported` server metadata.
 *
 * @see https://openid.net/specs/openid-connect-core-1_0.html#ClientAuthentication
 */
enum ClientAuthMethod: string
{
    /** HTTP Basic authentication, which RFC 6749 §2.3.1 requires every server to support. */
    case ClientSecretBasic = 'client_secret_basic';

    /** Credentials in the request body, which RFC 6749 §2.3.1 makes optional for servers. */
    case ClientSecretPost = 'client_secret_post';
}
