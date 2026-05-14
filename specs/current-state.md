# Current state — opensalestax-invoice-ninja

> Updated 2026-05-13 at v0.1.0-alpha.1 release.

## Shipped

- **v0.1.0-alpha.1** — first public alpha. Sidecar architecture (Shape B).
  - 81 unit tests, all green
  - PHPStan level max — 0 errors
  - PHPCS PSR-12 — 0 errors
  - SonarQube quality gate clean (0 bugs / 0 vulnerabilities / 0 code smells / 0 security hotspots)
  - `composer audit` clean
  - Live engine smoke test passing against the production OpenSalesTax engine at `10.32.161.126:8080` (six MN jurisdictions resolved on ZIP 55401)

## In progress

None — release branch.

## Open / deferred (v0.2 candidates)

- Per-jurisdiction tax lines (Invoice Ninja v5 supports up to three named taxes per invoice — currently the sidecar collapses to a single weighted-average `tax_rate1`)
- Recurring-invoice tax recalculation per cycle
- Multi-replica replay / rate-limit state (Redis backing)
- Trusted-proxy list for `X-Forwarded-For`
- Refund / credit-note tax handling
- Category mapping from Invoice Ninja product custom-fields to engine categories

## Pending validation (orchestrator agent owns)

- Live integration test against Invoice Ninja v5 on the pre-provisioned VM (VMID 918, `invoice-ninja-test`)
- Trusted Publishing config on Packagist for tag-driven releases
