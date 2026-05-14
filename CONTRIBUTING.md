# Contributing to opensalestax-invoice-ninja

Thanks for considering a contribution. The bar is "small-merchant production-quality" — please read the constitution at [`specs/constitution.md`](specs/constitution.md) before opening a PR that changes behavior.

## Developer Certificate of Origin (DCO)

Every commit must carry a DCO sign-off:

```bash
git commit -s -m "your message"
```

The `-s` flag appends `Signed-off-by: Name <email>` asserting your right to contribute under the project license. See <https://developercertificate.org/>.

## No AI co-author trailers

Do not add `Co-Authored-By:` trailers attributing AI assistants. Human contributors take responsibility for their contributions.

## Branch model

Single `main` branch, semver tags. Topic branches off `main`, PR back to `main`. No long-lived release branches.

## License

By contributing, you agree your contribution is licensed under Apache-2.0 (see `LICENSE`).

## Quality gate

Before opening a PR, run `composer check` locally. It runs:

- `vendor/bin/phpunit` (unit tests)
- `vendor/bin/phpstan analyse` (level max type analysis)
- `vendor/bin/phpcs` (PSR-12)
- `composer audit` (security advisories on dependencies)

PRs that fail CI cannot merge.

## Style points

- `declare(strict_types=1);` at the top of every PHP file
- SPDX header (`// SPDX-License-Identifier: Apache-2.0`) on every new PHP file
- PHPDoc on every public method
- `final` on production classes by default (sidecar pattern — no plugin/proxy concerns)
- No `mixed` return types without an inline justification
- Constructor promotion + `readonly` properties where possible
- No raw `array<mixed>` payload arrays without a typed wrapper

## Reporting bugs

Open a GitHub issue with the affected Invoice Ninja version, the sidecar version, and a reproduction. For security issues see `SECURITY.md`.
