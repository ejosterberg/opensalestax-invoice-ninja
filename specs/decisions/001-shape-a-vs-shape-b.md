# Decision 001 — Sidecar (Shape B) over Laravel package (Shape A)

**Status:** Accepted
**Decided:** 2026-05-13
**Decider:** Eric Osterberg (delegated to claude/clever-wright-209725)

## Context

Invoice Ninja v5 is a Laravel application. Two integration shapes were on the table for OpenSalesTax:

- **Shape A — In-process Laravel package.** Ship a Composer package the merchant installs into the Invoice Ninja codebase. A service provider hooks into invoice creation events (`InvoiceWasCreated`, `InvoiceWasUpdated`, etc.) and modifies tax fields in-process before persistence.
- **Shape B — Sidecar webhook listener.** Ship a standalone PHP HTTP service the merchant runs alongside Invoice Ninja. It receives `invoice.created` webhooks from Invoice Ninja, calls the OpenSalesTax engine, and writes back via Invoice Ninja's REST API.

## Decision

**Ship Shape B.** Shape A is out of scope for v0.1; revisit in v0.3+ if upstream Invoice Ninja publishes a stable tax-provider SPI.

## Rationale

1. **No stable in-process SPI.** Invoice Ninja v5's published documentation describes the REST API and the webhook subscriber list. There is no public, versioned package-extension contract for tax providers — meaning a Shape A implementation would couple us to internal Laravel events that can shift between minor versions. Shape B uses only the publicly versioned surfaces.

2. **Survives Invoice Ninja upgrades.** Shape B is just an HTTP client on each side of the boundary. Invoice Ninja's webhook payload shape and REST endpoints are versioned (`/api/v1/...`); breaking changes are visible and patchable in one place.

3. **No modification of Invoice Ninja's source tree.** Merchants can run upstream Invoice Ninja unmodified, which dramatically simplifies their upgrade path and removes a class of "did the plugin break my install?" support tickets.

4. **Better testability.** A standalone sidecar is easier to unit-test than a Laravel package that needs the full Invoice Ninja test harness booted. We ship 81 unit tests; getting equivalent coverage in Shape A would have required Invoice Ninja itself as a dev dependency.

5. **Architectural symmetry with future connectors.** A webhook-listener pattern is the natural shape for several upcoming targets (Akaunting, OpenCart, possibly Crater). Investing in the pattern here pays off in those repos.

## Trade-offs accepted

- **Operator runs an extra process.** Mitigated by shipping `bin/sidecar.php` as a one-liner under PHP's built-in server for dev, and a simple PHP-FPM config example for production.
- **Inbound HTTP surface to defend.** Mitigated by HMAC signature verification, replay cache, rate limiter, and TLS-on-by-default — all exercised by unit tests and documented in `docs/SECURITY-REVIEW.md`.
- **HMAC signing may need an upstream shim.** Invoice Ninja's stock webhook subscriber emits unsigned POSTs; merchants whose Invoice Ninja version lacks native signing deploy a tiny Laravel middleware to sign outbound webhooks before they hit the sidecar. Example in the README.

## Revisit triggers

- Invoice Ninja v6 publishes a stable tax-provider SPI
- A named maintainer wants to ship a Laravel-package twin and has the Invoice Ninja internals knowledge to keep it current across upgrades
- The Akaunting / OpenCart connectors using the same sidecar pattern hit a wall that suggests in-process is actually the better universal shape
