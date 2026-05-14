# Installing the OpenSalesTax sidecar signing shim on Invoice Ninja v5

This walks through deploying the shim onto a real Invoice Ninja v5
install. Assumes Invoice Ninja v5 is already running (Laravel 11
under PHP-FPM on Linux is the tested target) and that the
OpenSalesTax sidecar is running and reachable from Invoice Ninja's
host.

## Prerequisites

- A working Invoice Ninja v5 deployment (Laravel 11, PHP 8.1+)
- The [opensalestax-invoice-ninja sidecar](https://github.com/ejosterberg/opensalestax-invoice-ninja) up
  and reachable from this Invoice Ninja host
- The sidecar's `IN_WEBHOOK_SIGNING_SECRET` value (32+ char shared
  secret)
- SSH access to Invoice Ninja's host as a user who can edit `.env`
  and restart queue workers

## Step 1 — install the package

From Invoice Ninja's project root (where `artisan` lives):

```bash
composer require ejosterberg/opensalestax-invoice-ninja-shim
```

Laravel package auto-discovery will register the service provider
automatically; you do not need to edit `config/app.php`.

## Step 2 — publish the config file

```bash
php artisan vendor:publish --tag=opensalestax-sidecar-shim-config
```

This drops `config/opensalestax-sidecar-shim.php` into Invoice Ninja's
config directory. You can edit it directly, but the recommended path
is to set values via `.env`.

## Step 3 — configure secrets in `.env`

Append to Invoice Ninja's `.env`:

```ini
# Required: shared HMAC secret. Must match the sidecar's IN_WEBHOOK_SIGNING_SECRET.
OST_SIDECAR_SIGNING_SECRET=put-your-32-char-shared-secret-here

# Required: full URL of the sidecar's webhook endpoint.
OST_SIDECAR_URL=https://sidecar.example.com/webhooks/invoice-ninja

# Optional: header name (default matches the sidecar's SignatureVerifier::HEADER_NAME)
# OST_SIDECAR_HEADER_NAME=X-Sidecar-Signature

# Optional: emergency kill switch
# OST_SIDECAR_SHIM_ENABLED=true
```

Generate a fresh secret if you don't already have one:

```bash
php -r 'echo bin2hex(random_bytes(32)).PHP_EOL;'
```

Set the same value in BOTH the sidecar's and Invoice Ninja's `.env`.

## Step 4 — wire Invoice Ninja's webhook subscriber

In Invoice Ninja's web UI: **Settings → Integrations → Webhooks**

- URL: same value as `OST_SIDECAR_URL` above
- Event: `invoice.created` (add `invoice.updated` if you also want
  recalculation on edit)
- Method: POST
- Format: JSON

## Step 5 — restart queue workers

Invoice Ninja v5 dispatches webhooks via the queue worker, which
loads the service provider and signing middleware on boot. After
adding the package and `.env` values you must restart the workers:

```bash
php artisan queue:restart
```

If you use `supervisord` or `systemd` to keep the worker alive,
`queue:restart` signals graceful shutdown and the supervisor will
respawn the worker with the new code loaded.

## Step 6 — verify with the bundled smoke command

```bash
php artisan opensalestax-sidecar-shim:test
```

Expected output:

```
POST https://sidecar.example.com/webhooks/invoice-ninja
X-Sidecar-Signature: t=1700000000,v1=<64-hex-chars>
HTTP 200 OK
{"status":"ok","action":"applied",...}
```

If you see `HTTP 401` (signature mismatch / missing header), either:

- The `OST_SIDECAR_SIGNING_SECRET` does not match the sidecar's
  `IN_WEBHOOK_SIGNING_SECRET` (check both `.env` files).
- The clock skew between Invoice Ninja's host and the sidecar's host
  is greater than the sidecar's replay window (`SIDECAR_REPLAY_WINDOW_SECONDS`,
  default 300s). Sync clocks via NTP.
- A reverse proxy is stripping the signature header. Configure your
  proxy to forward `X-Sidecar-Signature` verbatim, or override the
  header name in `.env` if needed.

If you see `HTTP 204` (out of scope), the sidecar accepted the
signature but the sample payload is not a US/USD invoice (the
command's default sample lacks address fields). The signature
verified — the shim is wired up correctly. Trigger a real
`invoice.created` event to see the full flow.

## Step 7 — trigger a real invoice

Create a test invoice in Invoice Ninja for a US customer with a
ZIP code, and watch the sidecar's stderr log. You should see:

```json
{"level":"info","event":"webhook_signed_in","invoice_id":"...","rtt_ms":...}
```

If the sidecar logs `signature mismatch` or `signature header missing`,
return to Step 6.

## Operational notes

- **Emergency disable:** set `OST_SIDECAR_SHIM_ENABLED=false` in
  Invoice Ninja's `.env` and run `php artisan config:clear` +
  `php artisan queue:restart`. Outbound webhooks will be sent
  unsigned, which the sidecar will reject — Invoice Ninja's
  retry policy will keep trying until you re-enable.
- **Rotating the shared secret:** update both `.env` files
  simultaneously; the sidecar's replay window means there's a small
  cutover window during which in-flight webhooks may fail. Schedule
  rotations during low-traffic periods.
- **Multi-replica Invoice Ninja:** the shim is stateless and safe
  to run on every replica simultaneously.
