# Decision 002 — Shim ships as a sub-package inside this repo

**Status:** Accepted
**Decided:** 2026-05-13
**Decider:** Eric Osterberg (delegated to claude/clever-wright-209725)

## Context

Invoice Ninja v5's stock webhook subscriber emits **unsigned** POSTs. The
sidecar (Shape B in [Decision 001](001-shape-a-vs-shape-b.md)) rejects
unsigned requests with 401. Closing that gap requires a small Laravel
package installed inside the merchant's Invoice Ninja deployment that
adds the `X-Sidecar-Signature: t=…,v1=…` header on outbound webhook
POSTs.

Two shipping options were on the table:

- **Sub-package** — a `middleware/` subdirectory inside this repo with
  its own `composer.json`, published to Packagist as
  `ejosterberg/opensalestax-invoice-ninja-shim`. Two packages, one repo.
- **Separate repo** — `ejosterberg/opensalestax-invoice-ninja-shim` as a
  standalone GitHub repository with its own release cycle.

## Decision

**Ship as a sub-package** (`middleware/` subdirectory). The shim lives
in this repo alongside the sidecar it serves.

## Rationale

1. **Signature contract co-located with its consumer.** The shim's
   signing algorithm must stay byte-for-byte identical with the
   sidecar's `SignatureVerifier::sign()` algorithm. Keeping the two in
   the same repo makes a contract drift impossible to miss in code
   review and trivially testable in cross-package integration tests.

2. **One CHANGELOG / one release cadence.** Operators install the
   sidecar and the shim as a matched pair against the same Invoice Ninja
   version. A single release tag carrying both eliminates "which shim
   version goes with which sidecar version?" ambiguity.

3. **Lower contributor friction.** A single PR touches signature contract
   tests, the verifier, and the signer simultaneously. Splitting into
   two repos would force coordinated PRs and slow contributors.

4. **Composer supports the pattern natively.** Packagist accepts
   sub-package publishes via `path` repositories (for local dev) and
   tag-based releases (for production). Symfony, Laravel, and Filament
   all use the monorepo + sub-package shape.

## Trade-offs accepted

- **Sub-package release tag is more verbose** — both packages tag from
  the same SHA; the sidecar gets `v0.2.0-alpha.1` and the shim gets the
  same tag (Composer reads `composer.json` at that tag). Packagist auto-
  detects the sub-package via its own URL path. Documented in the
  release procedure.
- **`composer require` path is slightly less obvious** —
  `composer require ejosterberg/opensalestax-invoice-ninja-shim` works
  but the source URL is the parent repo; this is normal for monorepos
  and documented in `middleware/README.md`.

## Revisit triggers

- A non-trivial fraction of merchants want the shim without the sidecar
  (e.g. they wrote a competing tax calculator that uses the same
  `X-Sidecar-Signature` wire format).
- The sub-package outgrows a few hundred lines and starts carrying
  meaningful issue traffic that should not be filed against this repo.
