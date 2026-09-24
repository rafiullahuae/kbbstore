<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Something was put in a basket without the option it is sold by.
 *
 * ── WHAT IT GUARDS, AND WHY IT IS AN EXCEPTION AND NOT A RETURN ─────────────
 *
 * A variable product is bought by its VARIATION. The parent row carries no
 * price — `products.price` is NULL and every figure lives in
 * `product_variants` — and Product::effectivePrice() ends `return (int)
 * $this->price`, which makes that ZERO. CartService::add() priced the line at
 * `$variant?->effectivePrice() ?? $product->effectivePrice()` and wrote it, so
 * a variable parent with no variant became a basket line at AED 0 that the
 * checkout would go on to take money for. Nothing anywhere refused it.
 *
 * Throwing rather than returning is the same decision StockUnavailable's
 * comment records, and for the same reason: add() returns a CartItem, and a
 * caller that ignored a falsy return would write the zero-priced line anyway —
 * the original defect wearing a different hat. An exception cannot be ignored
 * by accident.
 *
 * ── IT IS A BACKSTOP, NOT THE MESSAGE THE SHOPPER READS ─────────────────────
 *
 * Every caller checks Product::requiresVariant() first and answers in its own
 * words and its own shape: Store\CartController::add() and
 * Store\CheckoutController::browsedAdd() return the `['ok' => false, 'error' =>
 * …]` JSON those endpoints already use for "that option is not available" and
 * "that product is sold out", and Services\ManualOrderBuilder turns it into a
 * ManualOrderFailure exactly as it already turns a CouponExhausted into one.
 *
 * This exists for the caller nobody has written yet. The tile is a suggestion;
 * the endpoint is the door — and a fetch call, a stale tab, or a tile cached
 * before this shipped all arrive at the door. It carries a sentence written
 * for a shopper so that a caller which only reports the message still says
 * something true.
 */
class VariantRequired extends RuntimeException
{
}
