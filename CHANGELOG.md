# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.3.2] — 2026-05-19

### Changed

- **CP-9: bumped `ejosterberg/opensalestax` constraint from `^0.2.0` to
  `^0.3.0`.** Picks up the SDK's new `?Shipping $shipping = null` arg
  on `Client::calculate()` plus `CalculateResponse::$shipping` /
  `$coverageWarning`.

### Notes

- Invoice Ninja's webhook payload doesn't expose a separate pre-tax
  shipping field — invoices are flat line-item lists. The connector
  treats each line item as taxable per its category mapping; merchants
  who include a shipping line as a regular invoice line will see it
  taxed correctly via the existing line-item path (no per-state
  shipping-taxability rule application). No behavior change in this
  release.

## [0.3.1] — 2026-05-19

### Changed

- **CP-8 Phase 5D: bumped `ejosterberg/opensalestax` constraint to `^0.2.0`.**
  Picks up the new `OpenSalesTax\Client::capabilities()` /
  `OpenSalesTax\Client::capabilitiesCached()` helpers for engine v0.59.0's
  `/v1/capabilities` endpoint. No merchant-visible behavior change in
  this release — the helper is available to sidecar code but not yet
  wired into any feature path. Constraint bump only; Test Connection
  surface enrichment deferred to v-next.

## [0.3.0] — 2026-05-19

### Added

- **Per-state nexus filter (CP-3).** New `OST_NEXUS_STATES` env var
  accepts a comma-separated list of US 2-letter state codes
  (e.g. `MN,WI,IA`). When set, the sidecar short-circuits the engine
  call for any invoice whose ship-to / billing state is not in the
  list — the webhook returns 200 with
  `{ "applied": false, "reason": "nexus_filter_skipped" }` and
  Invoice Ninja's invoice is left untaxed. Unset / empty preserves
  v0.2 behavior (engine called for every US/USD invoice). Missing /
  unresolvable destination state with the filter active is
  fail-closed.

  Address parsing: Invoice Ninja v5 stores the state on
  `client.shipping_state` (or `client.state` as fallback) as a
  2-letter code; we accept that directly and upper-case at extract
  time. Anything that isn't a 2-letter value yields null
  (treated as unresolvable).

  Brings this connector in line with WooCommerce v0.5, Vendure v1.2,
  and Odoo v0.3, which already shipped this filter. Major win for
  merchants with limited nexus footprints — typical merchant only
  has 1–3 nexus states and was previously paying engine RTT on
  every invoice.

## [0.2.2] — 2026-05-19

### Added

- **`bin/console health:check` CLI subcommand (CP-4).** New command-line
  equivalent of the admin "Test Connection" button shipped in WooCom v0.5
  / Vendure v1.3 / Saleor v1.0. The sidecar has no admin UI (it's a
  headless webhook listener), so the CLI is the right surface here:
  ```
  $ bin/console health:check
  ✓ Engine v0.59.0 reachable — status=ok database=connected (RTT 41 ms)
  ```
  Reads the same `OST_ENGINE_URL` + `OST_API_KEY` + `OST_TIMEOUT_SECONDS`
  env vars the sidecar uses at runtime, so a successful health check
  guarantees the same auth + URL path the webhook handler will use.
  Exit codes: 0 (reachable), 1 (config error — missing/invalid env var),
  2 (engine unreachable). Catches typo'd engine URLs at deploy time
  rather than at first webhook delivery. Wired via:
  - `Cli\HealthCheckCommand` — pure command class (testable in isolation
    via Guzzle MockHandler; respects the same SSRF URL validator the
    webhook handler uses).
  - `bin/console` — thin CLI router (built so future subcommands
    `debug-replay`, `signing-key-rotate`, etc. plug into the same `switch`).
  - 5 unit tests exercising healthy / non-200 / transport-error /
    URL-rejected / db-disconnected shapes.

## [0.2.1] — 2026-05-17

### Changed

- **Dual-licensed Apache-2.0 OR GPL-2.0-or-later.** Adds GPL-2.0-or-later as
  an alternative license alongside the existing Apache-2.0 grant, enabling
  downstream redistribution in GPL-only ecosystems without giving up Apache
  compatibility. License files reorganized: `LICENSE-APACHE.txt` (existing
  Apache text, moved from `LICENSE`), `LICENSE-GPL.txt` (new, GNU GPL v2
  text), `LICENSE` (new dual-declaration). SPDX headers updated across
  source files. `composer.json` `license` field switched from string to
  array form. Brings this sidecar in line with the rest of the
  OpenSalesTax portfolio's dual-licensing standard.

### Added

- **`.github/dependabot.yml`** — weekly checks for composer + GitHub Actions
  dependencies, with grouped dev-dep PRs. Brings this repo in line with
  the rest of the OpenSalesTax connector portfolio's supply-chain hygiene
  standard.

## [0.2.0] — 2026-05-15

### Added

- **Live-IN integration test pass.** Sidecar validated end-to-end on
  the test VM against Invoice Ninja v5 (Docker, `invoiceninja-debian:latest`)
  and the OpenSalesTax engine v0.57.0. Three live invoices (MN/55401,
  $100 line) successfully picked up `tax_name1="OpenSalesTax"`,
  `tax_rate1=9.025`, `total_taxes=9.03` after a single round-trip.
  Engine RTT (cold): 892 ms. RTT (warm): 920-992 ms.

### Fixed

- **Payload parser now reads `client.settings.currency_id`.** Invoice
  Ninja v5's stock `invoice.created` webhook does NOT include
  `currency_id` at the top level; it is buried in
  `client.settings.currency_id`. The previous parser rejected real IN
  payloads with "payload has no currency identifier" and short-
  circuited every live webhook with a 204. Verified against an actual
  IN v5 outbound payload (system_logs id=8 on test VM 918) and covered
  by two new unit tests.

## [0.2.0-alpha.1] — 2026-05-13

### Added

- **Laravel signing shim** as a companion sub-package in `middleware/`,
  published to Packagist as `ejosterberg/opensalestax-invoice-ninja-shim`.
  Auto-registering Laravel 11 service provider that adds the
  `X-Sidecar-Signature` HMAC-SHA256 header to outbound webhooks
  before they leave Invoice Ninja. Closes the gap left by Invoice
  Ninja v5's stock webhook subscriber, which emits unsigned POSTs.
- Artisan command `opensalestax-sidecar-shim:test` for one-shot install
  verification.
- 48 PHPUnit tests for the shim, including a cross-package parity test
  that runs the shim's `Signer` output through the sidecar's actual
  `SignatureVerifier::verify()`.
- [`docs/SIGNING-SHIM.md`](docs/SIGNING-SHIM.md) and
  [`middleware/docs/SHIM-INSTALL.md`](middleware/docs/SHIM-INSTALL.md)
  documenting the install on a real Invoice Ninja v5 deployment.
- [`specs/decisions/002-shim-as-subpackage-or-separate-repo.md`](specs/decisions/002-shim-as-subpackage-or-separate-repo.md)
  recording the sub-package-vs-separate-repo decision.

### Quality

- Shim sub-package: PHPStan **max** clean, PSR-12 clean, `composer audit`
  clean. Sidecar (`src/`) unchanged and still at 81 tests / 0-0-0-0
  SonarQube.

### Status

- Pending: live-IN integration test by the orchestrator before
  graduating to `v0.2.0` stable.

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

[0.2.0-alpha.1]: https://github.com/ejosterberg/opensalestax-invoice-ninja/releases/tag/v0.2.0-alpha.1
[0.1.0-alpha.1]: https://github.com/ejosterberg/opensalestax-invoice-ninja/releases/tag/v0.1.0-alpha.1
