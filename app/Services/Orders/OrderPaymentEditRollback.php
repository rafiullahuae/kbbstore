<?php

declare(strict_types=1);

namespace App\Services\Orders;

/** Unwinds OrderPaymentEdit's coupon pricing cart: the only way out of a transaction that must not commit. */
final class OrderPaymentEditRollback extends \RuntimeException {}
