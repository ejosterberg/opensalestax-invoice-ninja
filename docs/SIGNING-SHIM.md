# Signing-shim for Invoice Ninja v5

Invoice Ninja v5's stock webhook subscriber emits **unsigned** POSTs.
The sidecar rejects unsigned requests with 401 — see [`docs/SECURITY-REVIEW.md`](SECURITY-REVIEW.md)
threat T1 for the rationale.

The fix is a tiny Laravel package installed on the Invoice Ninja side
that adds the `X-Sidecar-Signature` header to outbound webhook POSTs
before they leave Invoice Ninja's host. The shim ships **in this repo**
as a sub-package — see [`middleware/`](../middleware/).

## Install (TL;DR)

In Invoice Ninja's project root:

```bash
composer require ejosterberg/opensalestax-invoice-ninja-shim
php artisan vendor:publish --tag=opensalestax-sidecar-shim-config
```

Then add to Invoice Ninja's `.env`:

```ini
OST_SIDECAR_SIGNING_SECRET=<same value as the sidecar's IN_WEBHOOK_SIGNING_SECRET>
OST_SIDECAR_URL=https://your-sidecar-host/webhooks/invoice-ninja
```

Restart workers and verify:

```bash
php artisan queue:restart
php artisan opensalestax-sidecar-shim:test
```

Expected: `HTTP 200 OK`.

Full step-by-step in [`middleware/docs/SHIM-INSTALL.md`](../middleware/docs/SHIM-INSTALL.md).

## Why a separate Laravel package and not patch Invoice Ninja directly?

- **Survives Invoice Ninja upgrades.** A Composer package installed
  alongside Invoice Ninja is unaffected when the merchant upgrades
  Invoice Ninja itself; modifying Invoice Ninja's source tree would
  silently break on every upgrade.
- **Auto-discovery.** Laravel 11 picks up the service provider with
  no `config/app.php` edits.
- **Reversible.** `composer remove ejosterberg/opensalestax-invoice-ninja-shim`
  uninstalls cleanly.
- **Same signature contract.** The shim's `Signer` mirrors the
  sidecar's `SignatureVerifier::sign()` byte-for-byte; the
  cross-package parity test in `middleware/tests/Unit/Signing/SidecarVerifyParityTest.php`
  guards against drift.

## Decision record

The decision to ship the shim as a sub-package inside this repo
(rather than a separate GitHub repo) is documented in
[`specs/decisions/002-shim-as-subpackage-or-separate-repo.md`](../specs/decisions/002-shim-as-subpackage-or-separate-repo.md).
