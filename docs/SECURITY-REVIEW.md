# Security Review — opensalestax-invoice-ninja v0.1.0-alpha.1

> Threat model and mitigation status as of 2026-05-13.

## Architecture in scope

```
Invoice Ninja v5  ──(webhook)──>  Sidecar (this)  ──(REST)──>  Invoice Ninja v5
                                       │
                                       └──(HTTP)──>  OpenSalesTax engine
```

The sidecar runs as a long-lived PHP-FPM process on a host the merchant owns.
It receives signed webhooks from Invoice Ninja (potentially over the public
internet if Invoice Ninja is cloud-hosted), calls the engine, and writes
back to Invoice Ninja's REST API using an admin-scoped API token. The
threat surface is therefore: **inbound HTTP (webhook listener)**,
**outbound HTTP (engine + Invoice Ninja API)**, and **at-rest secrets**.

## Threat model

| # | Threat | Severity | Mitigation | Status |
|---|---|---|---|---|
| T1 | **Webhook forgery** — attacker POSTs a crafted invoice payload, sidecar writes back a 100% tax rate that pockets the next real customer's money | Critical | HMAC-SHA256 signature verification, constant-time compare via `hash_equals()`, 32-char min secret | Implemented (`SignatureVerifier`) |
| T2 | **Webhook replay** — attacker captures a legitimate signed webhook and resends it | High | Timestamp window (default 300s) + per-body-hash replay cache, both enforced before any side effects | Implemented (`SignatureVerifier` + `ReplayCache`) |
| T3 | **SSRF via engine URL** — operator misconfigures `OST_ENGINE_URL` to `http://169.254.169.254/`, sidecar leaks cloud metadata creds | High | URL validator rejects non-http(s) schemes; private/loopback/link-local rejected when `SIDECAR_ALLOW_PRIVATE_NETWORKS=0`. Operators on cloud must set the flag to 0 | Implemented (`UrlValidator`) |
| T4 | **SSRF via Invoice Ninja URL** — same as T3 but for `IN_API_URL` | High | Same `UrlValidator` runs on every write-back | Implemented |
| T5 | **Secret leakage in logs** — `OST_API_KEY` / `IN_API_TOKEN` / `IN_WEBHOOK_SIGNING_SECRET` ends up in stderr | High | `StderrLogger` redact list; `Config::__debugInfo()` masks the same keys; engine SDK does not log payloads. **No raw webhook body is ever logged** | Implemented (`StderrLogger`, `Config`) |
| T6 | **PII leakage** — customer addresses in stderr | Medium | Logger only emits structured metadata (invoice id, ZIP5, RTT, http status, line count). Raw payloads never logged | Implemented |
| T7 | **DoS via inbound floods** | Medium | Per-source-IP token-bucket rate limiter (default 120/min). Tunable | Implemented (`RateLimiter`); **caveat**: in-memory only, not shared across processes — flagged for v0.2 |
| T8 | **DoS via huge payloads** | Medium | PHP-FPM's `client_max_body_size` / `php.ini post_max_size` is the natural choke point. The sidecar's `json_decode` depth is capped at 64. We do not buffer beyond what php://input gives us | Mitigated (operator-tunable) |
| T9 | **TLS downgrade / MITM** on outbound calls | High | `SIDECAR_TLS_VERIFY=1` by default; Guzzle's `verify` option enforces peer cert validation. **Disabling requires explicit opt-in** | Implemented |
| T10 | **Path traversal via invoice id** — webhook tries `id: "../etc/passwd"` so `PUT /api/v1/invoices/../etc/passwd` hits an unintended endpoint | Medium | `InvoiceNinjaClient` rejects any id not matching `^[A-Za-z0-9]{1,32}$` before constructing the URL | Implemented |
| T11 | **Cross-process state confusion** — multi-replica deploy where replay cache is per-process means an attacker who finds one replica can replay against another | Medium | Documented as a single-process-per-host limitation in v0.1. v0.2 will swap in a Redis-backed `ReplayCache` | Known limitation; documented in `CHANGELOG.md` |
| T12 | **Trusted proxy spoofing** — attacker spoofs `X-Forwarded-For` to evade per-IP rate limit | Low | `PhpSapiAdapter::extractSourceIp` ignores `X-Forwarded-For` entirely and uses `REMOTE_ADDR`. Operators behind a trusted reverse proxy can still see the proxy's IP, which the rate limiter then naturally rate-limits against — they should provision higher limits | Mitigated (documented) |

## Cryptographic primitives used

- HMAC-SHA256 via PHP's `hash_hmac()` (constant-time digest with `hash_equals`).
- No custom crypto. No homegrown signature scheme. The wire format is Stripe-compatible (`t=<unix>,v1=<hex64>`).

## Secrets handling

| Secret | Source | Storage | Memory lifetime |
|---|---|---|---|
| `OST_API_KEY` | env var | not stored | per-request — passed into SDK Client constructor |
| `IN_API_TOKEN` | env var | not stored | per-request — passed as `X-Api-Token` header |
| `IN_WEBHOOK_SIGNING_SECRET` | env var | not stored | per-request — fed to HMAC, never logged |

None of these are persisted to disk by the sidecar. Operator is responsible for keeping `.env` out of source control (the bundled `.gitignore` does this).

## Dependencies (composer audit)

- `ejosterberg/opensalestax` ^0.1 — first-party SDK
- `guzzlehttp/guzzle` ^7.8 — vetted; CVEs tracked
- `psr/log` ^1|2|3 — interface package, no executable code

`composer audit` clean as of release.

## Static analysis

- PHPStan **level max** (level 9 + extras) — 0 errors.
- PHP CodeSniffer PSR-12 — 0 errors.
- SonarQube quality gate — 0 / 0 / 0 / 0.

## Test coverage of security paths

- `SignatureVerifier`: 8 tests including tamper, wrong secret, missing header, stale timestamp, future timestamp, malformed digest.
- `ReplayCache`: 5 tests including duplicate rejection, bounded eviction.
- `RateLimiter`: 3 tests including independence per source, refill correctness.
- `UrlValidator`: 10 tests including private-IP rejection, file:// rejection, metadata-IP rejection, unresolvable host rejection.
- `WebhookHandler`: 12 tests including signature missing, signature tampered, replayed request, rate-limit, non-US, non-USD, engine fail-soft, write-back failure.

## Disclosure

Email `ejosterberg@gmail.com` per `SECURITY.md`. Critical issues acknowledged within 7 days.
