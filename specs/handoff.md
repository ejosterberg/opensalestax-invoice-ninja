# Handoff — opensalestax-invoice-ninja

> Updated 2026-05-13 at v0.1.0-alpha.1 release.

## Pick up here

1. **Live integration validation (out of this repo's scope).** The orchestrator agent in the hub repo owns spinning up the Invoice Ninja test VM (VMID 918), deploying the sidecar, wiring the webhook subscriber, and driving a real invoice through. Once that passes:
   - Graduate `v0.1.0-alpha.1` → `v0.1.0` stable (tag + release)
   - Add a row to the hub repo's connector matrix
   - Draft a launch post for the Invoice Ninja community

2. **Trusted Publishing on Packagist.** First publish to Packagist is manual (interactive 2FA). After the package exists, configure OIDC trusted publishing so future tag pushes auto-publish.

## Decision 1 — Sidecar (Shape B) over Laravel package (Shape A)

Decided 2026-05-13. See `specs/decisions/001-shape-a-vs-shape-b.md`. Rationale: Invoice Ninja v5 does not publish a stable in-process package-extension SPI for tax providers; the supported surfaces are the REST API and webhook subscriber list. The sidecar uses both. Reversing this would require a meaningful upstream contribution to Invoice Ninja itself, which is out of scope for v0.1.

## v0.2 priorities (rough ordering)

1. Per-jurisdiction tax lines via Invoice Ninja's `tax_name2` / `tax_name3` fields (or surface the breakdown in invoice notes)
2. Redis-backed replay + rate-limit cache so the sidecar can run as multiple replicas
3. Refund / credit-note tax handling
4. Category mapping (Invoice Ninja product custom-fields → engine categories)
5. Trusted-proxy list to honor `X-Forwarded-For` for accurate per-IP rate limiting

## Known limitations to flag in v0.1 release notes

- Single-process replay cache (multi-replica deployments need v0.2)
- Single weighted-average tax rate (no per-jurisdiction line yet)
- HMAC signing on Invoice Ninja's outgoing webhook may require an upstream shim if your IN version doesn't natively sign — example shim documented in README

## What did NOT change (intentionally)

- No engine internals imported. SDK is the contract.
- No filing / remittance.
- No non-USD support.
- No Invoice Ninja v4 support (EOL).
