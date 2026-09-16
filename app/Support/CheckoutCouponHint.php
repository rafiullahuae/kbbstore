<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Coupon;
use App\Services\SettingsService;

/**
 * The discount code the checkout offers as a clickable badge — but only when
 * there is one.
 *
 * WHAT WAS WRONG. partials/checkout/coupon-hint.blade.php read
 * `$settings->get('checkout_coupon') ?: 'GLOW30'` and
 * `$settings->get('checkout_coupon_text') ?: 'Need more discount? Try {code}
 * for 30% off ✨'`. Both defaults were literals, so a shop that had never
 * configured a code — which is every fresh install, and is this shop —
 * advertised GLOW30 for 30% off at the moment of payment. The badge is
 * clickable: tapping it applied a code that does not exist, and the shopper was
 * answered "That code is not valid." by the checkout that had just recommended
 * it. An advertised discount that is refused the instant it is tapped is worse
 * than no hint at all.
 *
 * THE TWO HALVES OF THE FIX. The code must be one the owner actually set AND
 * one CouponService would accept today — a code that expired last month, has
 * not started yet, or has been fully redeemed is not on offer, so it is not
 * advertised. And when the owner has written no wording of their own, the
 * sentence states what the coupon is REALLY worth, read off the coupon row,
 * rather than the template's own "30% off" over whatever the code happens to
 * be set to.
 *
 * The three conditions mirror CouponService::validate() exactly, in the same
 * order and for the same reasons, so the hint and the answer to tapping it
 * cannot drift apart. The basket-shaped rules it also checks — minimum amount,
 * eligible items, per-customer limits — are deliberately NOT repeated here:
 * they depend on what is in the bag and on who is buying, they change as the
 * shopper edits the basket, and a hint that flickered in and out of the page
 * as a line was added would be its own defect. What this promises is that the
 * code exists and is live, which is what the old default could not promise at
 * all.
 */
final class CheckoutCouponHint
{
    /**
     * The checkout's badge: the owner's own wording, or a sentence built from
     * what the coupon is really worth.
     *
     * @return array{code: string, text: string}|null
     */
    public static function offer(SettingsService $settings): ?array
    {
        return self::resolve(
            $settings,
            'checkout_coupon_text',
            'Need more discount? Try {code} for {worth} ✨',
        );
    }

    /**
     * The same code, as the cart page and the mini-cart drawer say it.
     *
     * CartController::payload() carried its own copy of this defect, with its
     * own literal: `$settings->get('cart_coupon_text', 'Use code <b
     * data-code="GLOW30">GLOW30</b> for an extra 30% off.')`. Same invented
     * code, same invented percentage, same clickable badge, on the cart page
     * and inside the drawer that the layout renders on EVERY page of the shop.
     * One code, checked once, said in each screen's own words.
     *
     * @return array{code: string, text: string}|null
     */
    public static function cartOffer(SettingsService $settings): ?array
    {
        return self::resolve(
            $settings,
            'cart_coupon_text',
            'Use code {code} for an extra {worth}.',
        );
    }

    /**
     * The sentence as HTML, with the code as the clickable badge the storefront
     * JS listens for.
     *
     * The text is escaped and the badge is not — the badge is markup this class
     * builds from a code it has just read out of the database, and the code
     * itself is escaped into it. A wording with no {code} placeholder gets the
     * badge appended, which is what the old partial did.
     */
    public static function html(?array $offer): string
    {
        if ($offer === null) {
            return '';
        }

        $badge = '<b data-code="' . e($offer['code']) . '">' . e($offer['code']) . '</b>';

        return str_contains($offer['text'], '{code}')
            ? str_replace('{code}', $badge, e($offer['text']))
            : trim(e($offer['text']) . ' ' . $badge);
    }

    /**
     * @return array{code: string, text: string}|null
     *         null when nothing should be advertised.
     */
    private static function resolve(SettingsService $settings, string $textKey, string $fallback): ?array
    {
        $code = strtoupper(trim((string) ($settings->get('checkout_coupon') ?: '')));

        // No default. An unconfigured shop advertises nothing, which is the
        // whole repair.
        if ($code === '') {
            return null;
        }

        $coupon = Coupon::code($code)->first();

        if ($coupon === null) {
            return null;
        }

        $now = now();

        if ($coupon->starts_at && $now->lt($coupon->starts_at)) {
            return null;
        }

        if ($coupon->expires_at && $now->gt($coupon->expires_at)) {
            return null;
        }

        if ($coupon->usage_limit !== null && $coupon->usage_count >= $coupon->usage_limit) {
            return null;
        }

        $text = trim((string) ($settings->get($textKey) ?: ''));

        if ($text === '') {
            $text = str_replace('{worth}', self::worth($coupon), $fallback);
        }

        return ['code' => strtoupper((string) $coupon->code), 'text' => $text];
    }

    /**
     * What the code takes off, in the shopper's terms.
     *
     * `percent` is stored as hundredths of a percent — 30% is 3000 — which is
     * the reading CouponService::discountFor() takes when it divides by 10000.
     * Everything else is an amount in fils.
     */
    private static function worth(Coupon $coupon): string
    {
        if ((string) $coupon->type === 'percent') {
            $percent = (int) $coupon->amount / 100;

            return rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.') . '% off';
        }

        return Money::plain((int) $coupon->amount) . ' off';
    }
}
