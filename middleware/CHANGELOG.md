# Changelog

All notable changes to this package will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this package adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0-alpha.1] — 2026-05-13

First public release of the Laravel signing shim for the
`opensalestax-invoice-ninja` sidecar.

### Added

- `Signer` that produces `X-Sidecar-Signature: t=<unix-seconds>,v1=<hex-hmac-sha256>`
  header values from an arbitrary request body, matching the sidecar's
  wire format byte-for-byte.
- Guzzle `SigningMiddleware` that recognizes outbound POSTs to a
  configured sidecar URL prefix and attaches the signed header. Safe
  to install on Invoice Ninja's global handler stack — non-matching
  requests are passed through unchanged.
- `SidecarShimServiceProvider` that auto-registers via Laravel 11
  package discovery, binds `Signer` and `SigningMiddleware` as
  container singletons, and publishes the config file under the
  `opensalestax-sidecar-shim-config` tag.
- Artisan command `opensalestax-sidecar-shim:test` for one-shot
  install verification — sends a sample signed webhook to the
  configured sidecar and prints the response.
- Configurable via `config/opensalestax-sidecar-shim.php` with env
  overrides for `OST_SIDECAR_SIGNING_SECRET`, `OST_SIDECAR_URL`,
  `OST_SIDECAR_HEADER_NAME`, `OST_SIDECAR_SHIM_ENABLED`.
- 48 PHPUnit tests covering known signature vectors, header format,
  service-provider bindings, middleware URL filtering, command flow,
  cross-package roundtrip against the sidecar's actual
  `SignatureVerifier`.
- PHPStan level **max** clean, PSR-12 clean, `composer audit` clean.

### Known limitations (deferred)

- Not yet exercised against a real Invoice Ninja v5 deployment.
  Graduation to v0.1.0 stable requires a live-IN smoke test by the
  orchestrator.
- Event filtering (`events` config key) is reserved for v0.2 but not
  yet wired into the middleware.

[0.1.0-alpha.1]: https://github.com/ejosterberg/opensalestax-invoice-ninja/releases/tag/v0.2.0-alpha.1
