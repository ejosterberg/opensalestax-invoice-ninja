# Handoff — opensalestax-invoice-ninja

> Updated 2026-05-15 at v0.2.0 release.

## Pick up here

1. **Trusted Publishing on Packagist.** First publish to Packagist is manual (interactive 2FA). After the package exists, configure OIDC trusted publishing so future tag pushes auto-publish. v0.2.0 is the target version for the first Packagist publication.

2. **End-to-end test of the composer-installed shim.** v0.2 ships the Laravel shim package and a cross-package parity test, but the live-IN integration test on VM 918 used a direct patch to `app/Jobs/Util/WebhookSingle.php`. To close the loop, run `composer require ejosterberg/opensalestax-invoice-ninja-shim` inside the IN container, set the `OST_SIDECAR_*` envs, restart workers, and confirm the shim's Guzzle middleware attaches the signature header the same way the manual patch does. Algorithm parity is already test-verified; this validates the install / autoload path.

3. **Hub repo connector matrix.** Add v0.2.0 row to the hub repo's "OpenSalesTax connectors" table. Note: live-IN-validated, 9.025% MN/55401 rate confirmed.

## v0.3 priorities (rough ordering)

1. Per-jurisdiction tax lines via Invoice Ninja's `tax_name2` / `tax_name3` fields (or surface the breakdown in invoice notes)
2. Redis-backed replay + rate-limit cache so the sidecar can run as multiple replicas
3. Refund / credit-note tax handling
4. Category mapping (Invoice Ninja product custom-fields → engine categories)
5. Trusted-proxy list to honor `X-Forwarded-For` for accurate per-IP rate limiting

## Deployment notes for VM 918 (live-IN test bed)

- VM ID 918, name `invoice-ninja-test`, IP `10.32.161.63`, OS Debian 13.
- IN runs in Docker (`/home/ejosterberg/in-docker/debian/docker-compose.yml`): `invoiceninja/invoiceninja-debian:latest` + nginx + MySQL 8 + Redis. Exposed on port 80.
- IN admin: `admin@example.com` / `OstaxAdmin123`.
- IN API token (User Token): present in the `company_tokens` table; rotate before exposing this VM.
- Webhook signing secret (matches sidecar's `IN_WEBHOOK_SIGNING_SECRET` and IN container's `OST_SIDECAR_SIGNING_SECRET`): `ostax-shim-shared-secret-must-be-32-chars-or-more`. Test-only — rotate before any non-test use.
- Sidecar deployed at `/home/ejosterberg/sidecar/` (via `composer create-project ejosterberg/opensalestax-invoice-ninja`). Launch script: `/home/ejosterberg/run-sidecar.sh`. Listens on `0.0.0.0:8181`. Log: `/home/ejosterberg/sidecar.log`.
- IN webhook subscriber row points to `http://10.32.161.63:8181/webhooks/invoice-ninja` (event_id=2 = invoice.created).
- HMAC signing patch is in the IN container at `/var/www/html/app/Jobs/Util/WebhookSingle.php` (search for `BEGIN OSTAX_SIDECAR_SIGNING_PATCH`).

## Decision 1 — Sidecar (Shape B) over Laravel package (Shape A)

Decided 2026-05-13. See `specs/decisions/001-shape-a-vs-shape-b.md`. Rationale: Invoice Ninja v5 does not publish a stable in-process package-extension SPI for tax providers; the supported surfaces are the REST API and webhook subscriber list. The sidecar uses both. Reversing this would require a meaningful upstream contribution to Invoice Ninja itself, which is out of scope.

## Decision 2 — Shim as sub-package vs separate repo

Decided 2026-05-13. See `specs/decisions/002-shim-as-subpackage-or-separate-repo.md`.

## Known limitations carried into v0.3 release notes

- Single-process replay cache (multi-replica deployments need a Redis cache)
- Single weighted-average tax rate (no per-jurisdiction line yet)
- The Laravel shim composer package itself is parity-tested but not yet validated against a real IN install via `composer require`

## What did NOT change in v0.2 (intentionally)

- No engine internals imported. SDK is the contract.
- No filing / remittance.
- No non-USD support.
- No Invoice Ninja v4 support (EOL).
