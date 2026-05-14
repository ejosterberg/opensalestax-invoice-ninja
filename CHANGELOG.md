# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0-alpha.1] — 2026-05-13

First public release of the OpenSalesTax sidecar for Invoice Ninja v5.

### Added

- Sidecar HTTP service exposing `GET /health` and `POST /webhooks/invoice-ninja`.
- HMAC-SHA256 signature verification on inbound webhooks with timestamp-window
  replay protection (Stripe-style `t=…,v1=…` header).
- In-memory replay cache and per-source-IP token-bucket rate limiter.
- SSRF guard on outbound URLs (engine + Invoice Ninja API), with literal-IP
  resolution and link-local / RFC1918 / loopback rejection (opt-in).
- Typed Invoice Ninja payload parser — US-only / USD-only gates with structured
  204 No Content response on out-of-scope invoices.
- OpenSalesTax engine integration via the official PHP SDK
  (`ejosterberg/opensalestax` ^0.1).
- Invoice Ninja write-back via `PUT /api/v1/invoices/{id}` with one-shot retry
  on 409 Conflict.
- Stderr JSON logger with API-key / webhook-secret / token redaction.
- 81 unit tests covering: config, SSRF defense, HMAC signature, replay cache,
  rate limiter, payload parsing, engine gateway fail-soft, Invoice Ninja write-back
  retry, and the full webhook pipeline.
- SonarQube quality gate clean (0 bugs / 0 vulnerabilities / 0 code smells /
  0 security hotspots).

### Security

- `docs/SECURITY-REVIEW.md` documents the threat model (12 threats, mitigation
  status for each).

### Known limitations (deferred to v0.2)

- Per-jurisdiction tax lines (Invoice Ninja v5 supports up to three named taxes
  per invoice; v0.1 ships single weighted-average rate via `tax_rate1`).
- Recurring invoice tax recalculation per cycle.
- Shared replay / rate-limit state across multi-replica deployments (Redis or
  Memcached backing).
- Trusted-proxy list for honoring `X-Forwarded-For`.
- Refund / credit-note tax handling.

[0.1.0-alpha.1]: https://github.com/ejosterberg/opensalestax-invoice-ninja/releases/tag/v0.1.0-alpha.1
