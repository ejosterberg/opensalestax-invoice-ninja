<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace EJOsterberg\OpenSalesTax\InvoiceNinja\Shim;

/**
 * Thrown by the shim when the host application is missing required
 * configuration (typically a signing secret) and the shim therefore
 * cannot safely sign outbound webhooks.
 */
final class ShimConfigurationException extends \RuntimeException
{
}
