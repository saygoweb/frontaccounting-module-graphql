<?php

/*
    Copy to config_graphql.php and set a secret. The endpoint refuses every
    request until this file exists and the secret is at least 32 bytes.

        php -r 'echo bin2hex(random_bytes(24)), PHP_EOL;'
*/

return array(
    // HS256 signing key for access tokens. Required. Changing it logs everyone out.
    'secret' => '',

    // 'issuer' => 'fa-graphql',
    // 'access_ttl' => 900,            // seconds
    // 'refresh_ttl' => 2592000,       // seconds, 30 days

    // The longest a machine token (bin/fa-token issue) may live. bin/fa-token
    // refuses to issue one for longer.
    // 'machine_ttl_max' => 31536000,  // seconds, 365 days

    // FrontAccounting stores passwords as unsalted MD5, so `login` is refused over
    // plain HTTP unless this is true. Leave it false in production.
    // 'allow_insecure_login' => false,

    // Honour X-Forwarded-Proto / X-Forwarded-For. Only behind a proxy you control.
    // 'trust_proxy' => false,

    // Include exception messages and traces in error responses.
    // 'debug' => false,

    // 'max_depth' => 12,
    // 'max_complexity' => 2000,
    // 'max_body_bytes' => 1048576,

    // FrontAccounting's root, when the module is not at <root>/modules/graphql.
    // 'fa_root' => '/var/www/frontaccounting',
);
