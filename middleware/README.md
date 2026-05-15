# OpenSalesTax sidecar signing shim for Invoice Ninja v5

> **v0.1.0-alpha.1.** Companion Laravel package to the
> [`opensalestax-invoice-ninja`](../) sidecar. Signs outbound webhooks
> with the `X-Sidecar-Signature` HMAC-SHA256 header the sidecar requires.
> 48 unit tests pass. Not yet validated against a real Invoice Ninja v5
> deployment.

## Why this exists

Invoice Ninja v5's stock webhook subscriber emits **unsigned** POSTs.
The OpenSalesTax sidecar rejects unsigned requests with 401. This
package closes that gap.

It is a small Laravel service provider that:

1. Auto-registers when `composer require`'d into an Invoice Ninja v5
   install (Laravel 11 auto-discovery).
2. Installs a Guzzle middleware that recognizes outbound POSTs to the
   sidecar's configured URL and attaches a fresh, signed
   `X-Sidecar-Signature: t=<unix-seconds>,v1=<hex-hmac-sha256>` header.
3. Ships an artisan command (`opensalestax-sidecar-shim:test`) for
   one-shot install verification.

The signature wire format is identical to the sidecar's own
`SignatureVerifier::sign()` — algorithm parity is guaranteed by the
`SidecarVerifyParityTest` integration test in the sidecar repo.

## Install

In your Invoice Ninja v5 install directory:

```bash
composer require ejosterberg/opensalestax-invoice-ninja-shim
php artisan vendor:publish --tag=opensalestax-sidecar-shim-config
```

Then edit `.env`:

```ini
OST_SIDECAR_SIGNING_SECRET=<32+ char shared secret, same as sidecar's IN_WEBHOOK_SIGNING_SECRET>
OST_SIDECAR_URL=https://your-sidecar-host/webhooks/invoice-ninja
# Optional:
# OST_SIDECAR_HEADER_NAME=X-Sidecar-Signature
# OST_SIDECAR_SHIM_ENABLED=true
```

Restart the queue workers (if Invoice Ninja dispatches webhooks via a
queue, which it does in default v5 configs):

```bash
php artisan queue:restart
```

Verify with the bundled smoke command:

```bash
php artisan opensalestax-sidecar-shim:test
```

Full step-by-step in [`docs/SHIM-INSTALL.md`](docs/SHIM-INSTALL.md).

## How signing happens at runtime

1. Invoice Ninja v5 enqueues a webhook delivery job for a configured
   subscriber URL.
2. The webhook job dispatches an outbound HTTP POST via Guzzle.
3. This shim's Guzzle middleware intercepts the request. If the
   target URL matches the configured sidecar URL prefix and the
   method is POST, it computes
   `HMAC-SHA256(secret, <unix-time>.<raw-body>)` and attaches the
   `X-Sidecar-Signature` header.
4. The sidecar's `SignatureVerifier` parses the header, recomputes
   the same HMAC, and accepts the request if the digests match in
   constant time and the timestamp is inside the replay window (300s
   default).

## Quality bar

- Apache-2.0 license
- DCO sign-off required on every commit (`git commit -s`)
- 48 PHPUnit tests covering: known-vector signatures, header format,
  service-provider container bindings, Guzzle middleware behavior,
  artisan command flow, end-to-end roundtrip against the actual
  sidecar `SignatureVerifier`
- PHPStan level **max** clean
- PSR-12 clean (PHP_CodeSniffer)
- `composer audit` clean

## License

Apache-2.0. See [`../LICENSE`](../LICENSE).
