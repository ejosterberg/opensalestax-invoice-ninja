<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

/**
 * Configuration for the OpenSalesTax sidecar signing shim.
 *
 * Publish into your application with:
 *   php artisan vendor:publish --tag=opensalestax-sidecar-shim-config
 *
 * Every entry can be overridden via environment variables in your .env
 * file. The defaults match the sidecar's own defaults so a clean install
 * works out of the box.
 */
return [

    /*
    |---------------------------------------------------------------------------
    | Shared signing secret
    |---------------------------------------------------------------------------
    |
    | Must match the IN_WEBHOOK_SIGNING_SECRET value in the sidecar's .env.
    | Minimum 32 characters; generate one with:
    |
    |     php -r 'echo bin2hex(random_bytes(32)).PHP_EOL;'
    |
    */
    'secret' => env('OST_SIDECAR_SIGNING_SECRET', ''),

    /*
    |---------------------------------------------------------------------------
    | Sidecar webhook URL
    |---------------------------------------------------------------------------
    |
    | The full URL the shim will sign requests for. Only outbound POSTs
    | whose URL starts with this prefix get a signed header attached; this
    | is the safety net that keeps the secret away from unrelated outbound
    | calls Invoice Ninja makes (e.g. to Stripe, mail providers, etc.).
    |
    */
    'sidecar_url' => env('OST_SIDECAR_URL', ''),

    /*
    |---------------------------------------------------------------------------
    | Header name
    |---------------------------------------------------------------------------
    |
    | Defaults to X-Sidecar-Signature, matching the sidecar's
    | SignatureVerifier::HEADER_NAME constant. Override only if you have
    | a custom reverse-proxy stripping or renaming headers.
    |
    */
    'header_name' => env('OST_SIDECAR_HEADER_NAME', 'X-Sidecar-Signature'),

    /*
    |---------------------------------------------------------------------------
    | Enabled
    |---------------------------------------------------------------------------
    |
    | Set to false to disable signing without uninstalling the package.
    | Useful for emergency shutoff if a configuration issue is suspected.
    |
    */
    'enabled' => filter_var(env('OST_SIDECAR_SHIM_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    /*
    |---------------------------------------------------------------------------
    | Events
    |---------------------------------------------------------------------------
    |
    | Invoice Ninja v5 event names that should be signed. Empty array
    | (default) signs every webhook event. Use this to scope signing if
    | you have a separate, untrusted webhook subscriber consuming the
    | same firehose.
    |
    */
    'events' => [],

];
