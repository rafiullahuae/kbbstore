<?php

declare(strict_types=1);

namespace App\Services\Orders;

/** Thrown inside OrderPaymentEdit's transaction to undo every write; its message is the operator's answer. */
final class OrderPaymentEditRefused extends \RuntimeException {}
