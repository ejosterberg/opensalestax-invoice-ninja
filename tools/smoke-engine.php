<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use OpenSalesTax\Address;
use OpenSalesTax\Client;
use OpenSalesTax\LineItem;

$engineUrl = $argv[1] ?? 'http://10.32.161.126:8080';
$client = new Client(baseUrl: $engineUrl);

$health = $client->health();
echo sprintf("engine health: status=%s version=%s\n", $health->status, $health->version);

$response = $client->calculate(
    new Address('55401'),
    [new LineItem('100.00', 'general')],
);
echo sprintf("calculate: subtotal=%s tax=%s\n", $response->subtotal, $response->taxTotal);
foreach ($response->lines[0]->jurisdictions as $j) {
    echo sprintf("  - %s (%s) %s%%\n", $j->name, $j->type, $j->ratePct);
}
