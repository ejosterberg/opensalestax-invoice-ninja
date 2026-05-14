# Integration Check — live engine smoke test

> Confirms the deployed sidecar can talk to a live OpenSalesTax engine. Run this once after installing on a new host, then again before each release.

## What we test

A minimal PHP script (no Invoice Ninja involved) constructs the SDK client, calls `/v1/health` and `/v1/calculate` against the engine, and verifies the response shape. This proves the dependency chain works end-to-end on the deployment target.

## Pre-conditions

- Engine reachable from the sidecar host (or run locally on the dev machine)
- PHP 8.1+ with `php` on PATH (or use the XAMPP binary on Windows)
- `composer install` already run in the sidecar repo

## Script

Save as `tools/smoke-engine.php`:

```php
<?php
require __DIR__ . '/../vendor/autoload.php';

use OpenSalesTax\Address;
use OpenSalesTax\Client;
use OpenSalesTax\LineItem;

$engineUrl = $argv[1] ?? 'http://10.32.161.126:8080';
$client = new Client(baseUrl: $engineUrl);

$health = $client->health();
echo "engine health: status={$health->status} version={$health->version}\n";

$response = $client->calculate(
    new Address('55401'),
    [new LineItem('100.00', 'general')],
);
echo "calculate: subtotal={$response->subtotal} tax={$response->taxTotal}\n";
foreach ($response->lines[0]->jurisdictions as $j) {
    echo "  - {$j->name} ({$j->type}) {$j->ratePct}%\n";
}
```

## Run

```bash
/c/xampp/8.2.4/php/php.exe tools/smoke-engine.php http://10.32.161.126:8080
```

## Expected output (against engine v0.55.x, ZIP 55401 Minneapolis MN)

```
engine health: status=ok version=0.55.4
calculate: subtotal=100.00 tax=9.0250
  - Minneapolis (city) 0.50000%
  - Hennepin County (county) 0.15000%
  - Minnesota (state) 6.87500%
  - Hennepin County Transit Sales Tax (district) 0.50000%
  - Metro Area Transportation Sales Tax (district) 0.75000%
  - Metro Area Sales and Use Tax for Housing (district) 0.25000%
```

The exact rates vary as the engine ingests new state filings, but **the script must complete without exception and the `tax` value must be non-zero**.

## When this run lasts

| Date | Engine URL | Engine version | Result |
|---|---|---|---|
| 2026-05-13 | http://10.32.161.126:8080 | v0.55.4 | ✔ health reachable; calculate returned tax=9.0250 on ZIP 55401 / $100 / general — six jurisdictions resolved (city + county + state + 3 districts). v0.1.0-alpha.1 release pre-flight |

## Live Invoice Ninja end-to-end test

Out of scope for this script — handled by the main orchestrator agent with the pre-provisioned `invoice-ninja-test` VM (VMID 918). That test:

1. Boots Invoice Ninja v5 on the VM
2. Deploys this sidecar alongside
3. Creates an invoice with a US ZIP destination
4. Verifies Invoice Ninja's webhook fires, the sidecar applies tax, and the invoice's `tax_rate1` reflects the engine-derived rate

Results land in the hub's `specs/current-state.md` after a successful run.
