# OpenSalesTax for Invoice Ninja

> **v0.1.0-alpha.1.** Installable; passes 81 unit tests; SonarQube quality gate clean (0/0/0/0); not yet validated against a real Invoice Ninja v5 storefront. See `specs/` for the build plan.

A free, self-hostable **webhook sidecar** that adds destination-based US sales tax to [Invoice Ninja](https://invoiceninja.com) v5 invoices via the [OpenSalesTax engine](https://github.com/ejosterberg/opensalestax). No per-transaction fees, no SaaS lock-in — small agencies and freelancers self-host both Invoice Ninja and the OpenSalesTax engine on their own infrastructure.

## How it works (sidecar model)

```
+--------------+   1. webhook /webhooks/invoice-ninja        +-----------+
| Invoice      |  --------------------------------------->  |  Sidecar  |
| Ninja v5     |                                            |   (this)  |
|              |   3. PUT /api/v1/invoices/{id}             |           |
|              |  <---------------------------------------  |           |
+--------------+                                            +-----------+
                                                                  |
                                                  2. /v1/calculate v
                                                            +-----------+
                                                            | OpenSales |
                                                            | Tax engine|
                                                            +-----------+
```

1. Invoice Ninja fires a webhook (e.g. `invoice.created`) at the sidecar.
2. The sidecar pulls the destination ZIP and line items, calls the OpenSalesTax engine, gets a calculated tax rate.
3. The sidecar writes the rate back to the invoice via Invoice Ninja's REST API (`PUT /api/v1/invoices/{id}` with `tax_name1`/`tax_rate1`).

The whole loop completes in well under a second. If anything goes wrong (engine unreachable, malformed payload, non-US destination) the sidecar **fails soft** — the invoice is left untaxed and the operator sees a structured log line, rather than the customer seeing a broken invoice.

## Why a sidecar and not a Laravel package?

Invoice Ninja v5 does not publish a stable package-extension SPI for in-process tax providers; the supported integration surfaces are its REST API and its webhook subscriber list. The sidecar pattern uses both of those — meaning it doesn't require modifying Invoice Ninja's source tree and survives Invoice Ninja upgrades without regressions. See `specs/decisions/001-shape-a-vs-shape-b.md` for the full architectural decision record.

## What this sidecar does NOT do

- File or remit tax (calculation only — the merchant remits)
- Validate addresses
- Handle non-USD currencies or non-US destinations (returns 204, leaves the invoice alone)
- Validate tax-exempt customer certificates
- Ship with the engine bundled — point it at your own [OpenSalesTax engine](https://github.com/ejosterberg/opensalestax)

## Disclaimer

> Tax calculations are provided as-is for convenience. The merchant is solely responsible for tax-collection accuracy and remittance to the appropriate jurisdictions. Verify against your state Department of Revenue before remitting.

## Compatibility matrix

| Component | Tested | Notes |
|---|---|---|
| Invoice Ninja v5 | ✔ (alpha — live-test pending) | v4 is EOL and unsupported. |
| OpenSalesTax engine | v0.55.x | Tracks the engine's v1 HTTP API. |
| PHP | 8.1, 8.2, 8.3 | CI matrix. |
| OS | Linux | Tested on Debian 13. Should run on any POSIX with PHP-FPM. |

## Install

```bash
composer create-project ejosterberg/opensalestax-invoice-ninja /opt/ost-in-sidecar
cd /opt/ost-in-sidecar
cp .env.example .env
# edit .env with your values
```

## Configure (env vars)

| Var | Required | Default | Purpose |
|---|---|---|---|
| `OST_ENGINE_URL` | yes | — | Base URL of your OpenSalesTax engine (e.g. `http://10.0.0.5:8080`) |
| `OST_API_KEY` | no | — | Bearer token if the engine requires auth |
| `OST_TIMEOUT_SECONDS` | no | `10` | Outbound HTTP timeout, range `(0, 60]` |
| `IN_API_URL` | yes | — | Base URL of your Invoice Ninja instance |
| `IN_API_TOKEN` | yes | — | Invoice Ninja API token (`X-Api-Token` header) |
| `IN_WEBHOOK_SIGNING_SECRET` | yes | — | HMAC-SHA256 secret shared with Invoice Ninja; min 32 chars |
| `SIDECAR_ALLOW_PRIVATE_NETWORKS` | no | `1` | Allow RFC1918 destinations (same-VM deployment). Set `0` if exposed to the internet. |
| `SIDECAR_REPLAY_WINDOW_SECONDS` | no | `300` | Max age of a signed webhook before it's rejected as replay, range `[30, 3600]` |
| `SIDECAR_TLS_VERIFY` | no | `1` | TLS peer-verify on outbound calls |
| `SIDECAR_RATE_LIMIT_PER_MINUTE` | no | `120` | Per-source-IP rate limit on the inbound webhook endpoint |

## Run

For development:

```bash
php -S 0.0.0.0:8181 bin/sidecar.php
```

For production, behind nginx + PHP-FPM. The sidecar exposes two paths:

- `GET /health` — health probe, returns `{"status":"ok",...}`
- `POST /webhooks/invoice-ninja` — the webhook endpoint Invoice Ninja calls

## Wire up the Invoice Ninja webhook

In Invoice Ninja, Settings → Integrations → Webhooks, create a subscriber:

- URL: `https://your-sidecar-host/webhooks/invoice-ninja`
- Event: `invoice.created` (and `invoice.updated` if you want recalculation on edit)
- Method: POST

Then sign each request with HMAC-SHA256 of `t.body` (Stripe-style) and include the `X-Sidecar-Signature: t=<unix-seconds>,v1=<hex-digest>` header. **Unsigned requests are rejected with 401.**

Invoice Ninja v5's stock webhook subscriber emits **unsigned** POSTs, so this repo ships a companion Laravel signing shim that closes the gap — see [`middleware/`](middleware/) (Composer package: `ejosterberg/opensalestax-invoice-ninja-shim`). One-line install:

```bash
composer require ejosterberg/opensalestax-invoice-ninja-shim
```

Walkthrough in [`docs/SIGNING-SHIM.md`](docs/SIGNING-SHIM.md) and [`middleware/docs/SHIM-INSTALL.md`](middleware/docs/SHIM-INSTALL.md).

## Security

The sidecar exposes an inbound HTTP endpoint and writes back to Invoice Ninja with admin credentials, so it has a meaningful threat surface. The full threat model and mitigations are in [`docs/SECURITY-REVIEW.md`](docs/SECURITY-REVIEW.md). Key defenses:

- **HMAC signature verification** on every inbound request, constant-time compare
- **Replay protection** via timestamp window + body-hash cache
- **Rate-limit** per source IP
- **SSRF guard** on outbound URLs (rejects file://, ftp://, link-local, etc.)
- **TLS verification** ON by default
- **No secrets in logs** — API keys / tokens redacted in the structured logger
- **No PII in logs** — customer addresses and full payloads are never logged

## Calculation-only

This sidecar calculates. The merchant remits.

## Development

```bash
composer install
composer check          # phpunit + phpstan + phpcs + composer audit
```

See [`CONTRIBUTING.md`](CONTRIBUTING.md) for the DCO sign-off requirement and quality gate.

## License

Dual-licensed under your choice of [Apache-2.0](LICENSE-APACHE.txt) OR [GPL-2.0-or-later](LICENSE-GPL.txt). See [`LICENSE`](LICENSE).

## Related projects

- [`ejosterberg/opensalestax`](https://github.com/ejosterberg/opensalestax) — the tax-calculation engine
- [`ejosterberg/opensalestax-php`](https://github.com/ejosterberg/opensalestax-php) — the PHP SDK this sidecar depends on
- [`ejosterberg/opensalestax-invoice-ninja-shim`](middleware/) — companion Laravel signing shim installed inside Invoice Ninja v5 (this repo, `middleware/` sub-package)
- [`ejosterberg/opensalestax-magento`](https://github.com/ejosterberg/opensalestax-magento) — sibling connector for Magento 2
- [`ejosterberg/opensalestax-medusa`](https://github.com/ejosterberg/opensalestax-medusa) — sibling connector for Medusa.js
