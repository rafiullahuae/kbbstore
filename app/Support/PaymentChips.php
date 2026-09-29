<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Payments\Wallets;

/**
 * The payment names printed as text chips, and which of them are true.
 *
 * ── WHAT THIS REPLACED, AND WHY IT HAD TO ───────────────────────────────────
 *
 * Three storefront templates each carried the same hand-typed run:
 *
 *     <span>Tabby</span><span>Tamara</span><span>Visa</span>
 *     <span>Mastercard</span><span>Apple Pay</span>
 *
 * printed unconditionally, on a shop that could not take an Apple Pay payment
 * at all. There was no gateway for it, no button that did anything, and no
 * setting to switch it off — it was decoration that read as a claim. That is
 * the defect this class exists to close: the chips now say what the shop can
 * actually do, and they read it from ONE place,
 * App\Services\Payments\Wallets.
 *
 * ── WHY THE NAMES MOVED INTO PHP ────────────────────────────────────────────
 *
 * Exactly the argument CartPage::paymentMarks() already made for the drawn
 * marks, and it applies twice as hard once the list is conditional. They are
 * COMPANY NAMES: a translated one is a different company, and English prose in
 * a storefront Blade is what StorefrontStringsAreKeyedTest exists to catch.
 * Wrapping each <span> in an @if would have left five brand names in the
 * template AND cut the scanner's runs in half. In PHP the template holds no
 * English at all, which is the state that guard wants.
 *
 * "COD" is NOT here. It is an English abbreviation rather than a company, it
 * goes through __('store.footer.pay_cod'), and it is appended by the three
 * templates themselves — as it always was.
 *
 * ── AND WHY IT IS A CONSTANT ────────────────────────────────────────────────
 *
 * Nothing user-supplied reaches this list — same rule as PaymentMarkArt. These
 * are printed with {{ }} and escaped, unlike the drawn marks, so this is a
 * smaller promise than that class makes; it is kept anyway, because a chip row
 * assembled from a setting is a stored-XSS sink waiting for somebody to switch
 * the escaping off.
 */
final class PaymentChips
{
    /**
     * The rows, in the order each page already printed them.
     *
     * Three rows and not one, because the three pages genuinely differ: the
     * cart leads with the card schemes and the footer and the product page lead
     * with the instalment providers. That ordering is what shipped and this
     * class does not get to have an opinion about it — CLAUDE.md rule 1.
     *
     * `Google Pay` IS in all three and was in none of them, which is the one
     * addition here. It only ever appears once the owner has switched Google
     * Pay on in Store → Payments, so applying this package adds nothing: the
     * switch ships off. A shop that HAS switched it on and then does not say so
     * is the other half of the same defect.
     *
     * @var array<string, list<string>>
     */
    private const ROWS = [
        // resources/views/partials/footer.blade.php — .fpay
        'footer' => ['Tabby', 'Tamara', 'Visa', 'Mastercard', 'Apple Pay', 'Google Pay'],
        // resources/views/store/cart-inner.blade.php — .paylogos, full layout
        'cart' => ['Visa', 'Mastercard', 'Tabby', 'Tamara', 'Apple Pay', 'Google Pay'],
        // resources/views/store/product.blade.php — .paychips
        'product' => ['Tabby', 'Tamara', 'Visa', 'Mastercard', 'Apple Pay', 'Google Pay'],
    ];

    /**
     * Which chips carry a wallet behind them, and which wallet.
     *
     * Visa, Mastercard, Tabby and Tamara are deliberately absent. Their
     * acceptance is a separate question with a separate answer — the card
     * gateway and the two BNPL gateways — and this lane was sent for the two
     * that were lying, not to re-decide the other four. A lane that gates them
     * later adds them here and nothing else changes.
     *
     * @var array<string, string>
     */
    private const WALLET_OF = [
        'Apple Pay' => 'apple_pay',
        'Google Pay' => 'google_pay',
    ];

    /**
     * The chips one row may honestly print, in that row's own order.
     *
     * An unknown row name is an empty list rather than an exception: this is
     * called from a template, and a typo in a Blade must cost a row of logos,
     * never a page.
     *
     * @return list<string>
     */
    public static function row(string $name): array
    {
        $wallets = app(Wallets::class);

        return array_values(array_filter(
            self::ROWS[$name] ?? [],
            static fn (string $chip): bool => ! isset(self::WALLET_OF[$chip])
                || $wallets->offered(self::WALLET_OF[$chip]),
        ));
    }

    /**
     * Every row's full membership, gates ignored.
     *
     * For the tests, which need to know what a row WOULD print so they can
     * assert what it does not.
     *
     * @return array<string, list<string>>
     */
    public static function rows(): array
    {
        return self::ROWS;
    }

    /** @return array<string, string> */
    public static function walletOf(): array
    {
        return self::WALLET_OF;
    }
}
