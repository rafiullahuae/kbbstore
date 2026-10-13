<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The 13 October layout as ONE thing that can be put back (2.60.495).
 *
 * The owner, approving the build: "also map the old stuff in case we messed
 * something, so it can be recovered with one click from the backend."
 *
 * Every switch the layout added is listed here with the value that draws the
 * page as it was BEFORE 2.60.495 and the value he chose. The one stored value
 * the package changes rather than adds -- the cart's checkout button wording --
 * is snapshotted by the migration before it is written, so "put back" restores
 * whatever HE had there, not a guess at it.
 *
 * Writes go through CheckoutPage::save() / CartPage::save(), so every value is
 * cast by the same schema that reads it. Nothing here touches an order, a
 * payment setting or a gateway.
 */
class CheckoutLayout
{
    /** Where the migration keeps the values it overwrote. */
    public const SNAPSHOT = 'checkout_layout_before_2_60_495';

    /** CheckoutPage key => [before 2.60.495, the 13 October layout]. */
    public const CHECKOUT = [
        'lo_head_cart' => [false, true],
        'lo_title' => [true, false],
        'lo_sum_green' => [false, true],
        'lo_coupon_in' => [false, true],
        'lo_coupon_look' => ['classic', 'ticket'],
        'lo_one_step' => [false, true],
        'lo_email_hint_on' => [false, true],
        'lo_addr_rows' => ['classic', 'one'],
        'lo_uae_short' => [false, true],
        'lo_state_lang' => ['both', 'en'],
        'lo_detected' => [true, false],
        'lo_remember_tick' => [true, false],
        'lo_dl_note_free' => [false, true],
        'lo_pay_secure_on' => [false, true],
        'lo_bag_pics' => [true, false],
        'lo_total_only' => [false, true],
        'lo_btn_words' => [false, true],
        'lo_trust_on' => [false, true],
        'lo_apple_row' => [false, true],
    ];

    /** CartPage key => [before, after]. */
    public const CART = [
        'lo_ov_box' => [false, true],
        'lo_ship_on' => [false, true],
        'lo_coupon_x' => [true, true],
        'lo_btn_black' => [false, true],
        'lo_btn_icon' => ['none', 'lock'],
        'lo_btn_case' => ['normal', 'caps'],
        'lo_cont' => [true, false],
        'lo_cont_url' => ['/shop/', '/'],
    ];

    /** Existing CartPage values this package CHANGES: key => the new value. */
    public const CART_CHANGED = [
        'co_label' => 'Secure checkout',
    ];

    public function __construct(
        private SettingsService $settings,
        private CheckoutPage $checkout,
        private CartPage $cart,
    ) {}

    /**
     * Keep the values about to be overwritten -- once. A second run (a package
     * re-applied) must not replace the owner's own values with the ones this
     * package wrote the first time.
     */
    public function snapshot(): void
    {
        if ($this->settings->get(self::SNAPSHOT) !== null) {
            return;
        }

        $kept = [];

        foreach (array_keys(self::CART_CHANGED) as $key) {
            $kept[$key] = $this->settings->get('cartpage_'.$key);
        }

        // An array: SettingsService stores it as JSON and hands it back decoded.
        $this->settings->set(self::SNAPSHOT, $kept, false);
    }

    /** The cart and checkout exactly as they were before 2.60.495. */
    public function restore(): void
    {
        $this->checkout->save(array_map(fn (array $pair) => $pair[0], self::CHECKOUT));
        $this->cart->save(array_map(fn (array $pair) => $pair[0], self::CART));

        $kept = $this->settings->get(self::SNAPSHOT);
        $kept = is_array($kept) ? $kept : json_decode(is_string($kept) ? $kept : '{}', true);

        foreach (array_keys(self::CART_CHANGED) as $key) {
            $before = is_array($kept) ? ($kept[$key] ?? null) : null;

            // Never set before this package: unset again, so the schema's own
            // default is what draws it -- exactly as it did.
            if ($before === null) {
                $this->settings->set('cartpage_'.$key, null);
            } else {
                $this->cart->save([$key => $before]);
            }
        }
    }

    /** The 13 October layout, as he chose it. */
    public function apply(): void
    {
        $this->checkout->save(array_map(fn (array $pair) => $pair[1], self::CHECKOUT));
        $this->cart->save(array_map(fn (array $pair) => $pair[1], self::CART) + self::CART_CHANGED);
    }

    /** 'new' | 'old' | 'mixed' -- what the tab says above its two buttons. */
    public function state(): string
    {
        $new = $old = true;

        foreach (self::CHECKOUT as $key => [$before, $after]) {
            $now = $this->checkout->get($key);
            $new = $new && $now === $after;
            $old = $old && $now === $before;
        }

        foreach (self::CART as $key => [$before, $after]) {
            $now = $this->cart->get($key);
            $new = $new && $now === $after;
            $old = $old && $now === $before;
        }

        return $new ? 'new' : ($old ? 'old' : 'mixed');
    }
}
