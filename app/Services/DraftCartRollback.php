<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Thrown to unwind a pricing transaction that must never commit.
 *
 * ManualOrderBuilder::quote() has to build a real cart — CartService and
 * CouponService both work on persisted rows — but a quote is fired on every
 * keystroke in the quantity box, so it cannot leave carts behind. Throwing is
 * the only way to tell DB::transaction() to roll back; returning would commit.
 *
 * Its own class, and its own file, so a catch block cannot accidentally
 * swallow a real failure alongside it.
 */
class DraftCartRollback extends \RuntimeException {}
