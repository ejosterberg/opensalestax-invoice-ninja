# Current state — opensalestax-invoice-ninja

> Updated 2026-05-15 at v0.2.0 release.

## Shipped

- **v0.2.0** — first stable release, validated end-to-end against a real Invoice Ninja v5 deployment.
  - 83 unit tests on the sidecar, all green (PHP 8.2 and PHP 8.4)
  - PHPStan level max — 0 errors
  - PHPCS PSR-12 — 0 errors
  - `composer audit` clean
  - **Live-IN integration test passing** on VM 918 (`invoice-ninja-test`, 10.32.161.63): 3 invoices (MN/55401, $100 line item) round-tripped end-to-end through IN webhook → sidecar → engine → IN REST API. All picked up `tax_name1="OpenSalesTax"`, `tax_rate1=9.025`, `total_taxes=9.03`. Engine RTT: 892 ms cold, 920–992 ms warm.
  - HMAC signing path exercised via a direct patch to `app/Jobs/Util/WebhookSingle.php` in the IN container (production-safe variant of the Laravel shim).
  - Laravel signing shim sub-package (`middleware/`, package `ejosterberg/opensalestax-invoice-ninja-shim`) shipped with 48 unit tests including a cross-package parity test against the sidecar's verifier.
  - Payload parser fix: now reads `client.settings.currency_id`, which is where IN v5's stock webhook actually puts it.

- **v0.1.0-alpha.1** — first public alpha. Sidecar architecture (Shape B). 81 unit tests, PHPStan max clean, SonarQube 0/0/0/0.

## In progress

None — release branch.

## Open / deferred (next-release candidates)

- Per-jurisdiction tax lines (Invoice Ninja v5 supports up to three named taxes per invoice — currently the sidecar collapses to a single weighted-average `tax_rate1`)
- Recurring-invoice tax recalculation per cycle
- Multi-replica replay / rate-limit state (Redis backing)
- Trusted-proxy list for `X-Forwarded-For`
- Refund / credit-note tax handling
- Category mapping from Invoice Ninja product custom-fields to engine categories
- End-to-end test of the **composer-installed** Laravel shim package (v0.2 ships the package + a parity test, but the live-IN test used a manual `WebhookSingle.php` patch instead of `composer require`-ing the shim into the IN container)

## Pending publishing

- Trusted Publishing config on Packagist for tag-driven releases.
