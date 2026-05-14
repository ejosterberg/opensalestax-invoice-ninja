# Security Policy

## Reporting a vulnerability

Email **ejosterberg@gmail.com** with subject line starting `[opensalestax-invoice-ninja] security:`. Include the affected version, reproduction steps, and impact. Do not open a public GitHub issue for security reports.

Acknowledgement target: 7 days. For critical issues (tax-correctness, signature-bypass, or unauthenticated writeback), mark `[critical]` in the subject line and expect a faster turnaround.

## Supported versions

The latest minor on `main` is supported. Older releases are not back-patched.

## Scope

This policy covers the OpenSalesTax sidecar for Invoice Ninja v5 (`ejosterberg/opensalestax-invoice-ninja`). Vulnerabilities in upstream Invoice Ninja, the OpenSalesTax engine, or merchant infrastructure should be reported to their respective maintainers.

## Threat surface

The sidecar exposes an inbound HTTP endpoint that receives webhooks from Invoice Ninja. See `docs/SECURITY-REVIEW.md` for the full threat model, including:

- HMAC signature verification on inbound webhooks
- Replay-attack protection (timestamp window + nonce cache)
- SSRF defense on outbound calls to the OpenSalesTax engine
- Rate-limit on the inbound webhook endpoint
- TLS verification ON by default
