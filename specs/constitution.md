# Constitution — opensalestax-invoice-ninja

Non-negotiable principles for this connector. Inherits the [parent constitution](https://github.com/ejosterberg/open-sales-tax-integrations) and adds connector-specific clauses.

## §1. Apache 2.0

The connector ships under Apache 2.0. DCO sign-off on every commit. No AI co-author trailers.

## §2. Sidecar pattern, not in-process

The integration uses Invoice Ninja's public surfaces only: its webhook subscriber list (inbound) and its REST API (outbound). The sidecar is a separate process the merchant runs alongside Invoice Ninja. **We do not modify Invoice Ninja's source tree.** Rationale in `decisions/001-shape-a-vs-shape-b.md`.

## §3. Calculation only

The sidecar calculates and writes tax rate metadata back to invoices. It never files returns and never remits. The merchant remits.

## §4. US + USD only

The sidecar's gates reject non-US destinations and non-USD currencies with a structured 204. Other currencies / countries are an explicit non-goal until the engine supports them.

## §5. Fail-soft by default

If the engine is unreachable, the sidecar returns 200 with `applied: false, reason: engine_unavailable` and leaves the invoice untaxed. We never block invoice creation on engine availability.

## §6. Security primitives (cannot be downgraded without a recorded exception)

- HMAC-SHA256 signature verification on every inbound webhook, constant-time compare
- Timestamp window + replay cache before any side effect
- TLS verification ON by default on outbound calls
- SSRF validator runs on every outbound URL
- Per-source-IP rate limit on inbound endpoint
- No secrets or PII in logs

Each is exercised by at least one unit test.

## §7. Test coverage minimum

Connector v0.1 ships with ≥30 unit tests. Each security primitive in §6 has ≥1 dedicated test. PHPStan max + PHPCS PSR-12 + SonarQube quality gate clean (0/0/0/0) are release-blocking.

## §8. SDK boundary

The sidecar depends only on `ejosterberg/opensalestax` ^0.1 for engine calls. It does not import OpenSalesTax engine internals. The SDK is the contract.

## §9. Disclaimer text (constitution-required)

Every webhook response that surfaces tax output contains:

> Tax calculations are provided as-is for convenience. The merchant is solely responsible for tax-collection accuracy and remittance to the appropriate jurisdictions. Verify against your state Department of Revenue before remitting.

The README repeats the same text.
