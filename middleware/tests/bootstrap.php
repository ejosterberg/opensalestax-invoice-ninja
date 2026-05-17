<?php

/**
 * Test bootstrap.
 *
 * Loads the package autoloader. Kept intentionally minimal â€” Laravel's
 * own helpers (`env()`, `config()`, etc.) come from the autoloader, so
 * we do not redeclare them here.
 */

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
