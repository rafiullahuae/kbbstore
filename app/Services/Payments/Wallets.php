<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\PaymentProvider;
use App\Services\SettingsService;

/**
 * Apple Pay and Google Pay — the ONE answer to "can this shop take it".
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * A WALLET IS NOT A GATEWAY, AND THAT IS THE WHOLE DESIGN
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * There is no `apple_pay` row in `payment_providers`, no class under
 * Gateways/, and nothing here implements PaymentGateway. Apple Pay and Google
 * Pay are CARD wallets: what Stripe hands back when a shopper authorises one is
 * a PaymentMethod of type `card`, carrying `card.wallet.type = apple_pay` or
 * `google_pay`. The money moves over the same PaymentIntent that a typed card
 * moves over.
 *
 * Everything downstream is therefore untouched and must stay untouched:
 * `orders.payment_method` is still `stripe`, PaymentCapturer captures the same
 * intent, PaymentRefunder refunds the same charge, PaymentLedger records the
 * same event, and the reconciler matches the same `pi_...`. A second gateway
 * would have needed its own row, its own webhook route, its own refund path
 * and its own reconciliation, and every one of those is a place for a wallet
 * order to become unrefundable.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT THIS CLASS EXISTS FOR
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Before this class the shop printed "Apple Pay" and "Google Pay" in five
 * places — the footer, the slim footer, the cart trust row, the product page
 * chips and two dead buttons on the checkout — and could not take either
 * payment. Five templates each answered the question for themselves, and all
 * five answered it wrong, because none of them was asking anything: the marks
 * were printed unconditionally.
 *
 * So there is one answer and one place it comes from. A mark is drawn when
 * `offered()` says the payment can actually be taken, and at no other time.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THE FOUR GATES, AND WHY EACH ONE IS HERE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *   1. The `payment_providers` row for `stripe` says enabled. A shop that has
 *      switched the card off has switched the wallets off with it — they are
 *      the same money path.
 *   2. StripeGateway::configured() — the secret key is present, so this shop
 *      can open an intent at all.
 *   3. The publishable key is present. Without it Stripe.js never boots, so
 *      the wallet sheet can never be drawn however willing Stripe is. This is
 *      the same extra gate StripeGateway::availableFor() applies to the card
 *      option, and for the same reason.
 *   4. The merchant's own switch for THAT wallet, in
 *      Store → Payments → Credit or debit card → How this shop uses it.
 *
 * All four, because each of them alone is a way to draw a mark for a payment
 * that cannot be taken.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * OFF BY DEFAULT, AND WHY THAT IS THE ONLY HONEST DEFAULT
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Both switches ship empty, which reads as off. Applying the package therefore
 * moves no money path at all — CLAUDE.md rule 1. It is not merely a cautious
 * default either: Apple Pay on the web does not work until the domain is
 * registered with Apple through Stripe AND the association file is being
 * served (docs/WALLETS-APPLE-GOOGLE-PAY.md), and nobody but the owner can do
 * that. A switch that shipped On would draw an Apple Pay mark on a shop where
 * Apple Pay cannot yet complete, which is the exact defect this lane was sent
 * to fix.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHY THE ANSWER IS A SETTING AND NOT A QUERY
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * The footer draws payment marks on EVERY page of the shop. A
 * `payment_providers` read to decide two booleans would be one more query on
 * every page of the site — PageCostBudgetTest measured exactly that, cold, and
 * went over its ceiling on /shop and on the product page, which is the honest
 * cost of a first visitor rather than a test artefact. A cache would have hidden
 * it from a warm process and left it on the cold one.
 *
 * So the answer is a PROJECTION: two booleans written into `settings` under
 * one key, by the one thing that can change them. SettingsService reads the
 * settings table entire, once, on every request already — so reading this costs
 * nothing at all, on a cold cache as well as a warm one.
 *
 * WHAT KEEPS IT TRUE. PaymentProvider::booted() recomputes and rewrites it on
 * every save and every delete of a provider row, which is every path that can
 * move any of the four gates: GatewayCredentials::save() writes the config
 * blob, StripeConnect writes it twice more during a connect and a disconnect,
 * and PaymentsApiController writes the `enabled` column beside it. Not one of
 * them can change a gate without going through the model.
 *
 * AN ABSENT KEY MEANS NO WALLET, which is both the safe answer and the correct
 * one. A shop that has just had this package applied has never saved the
 * payments screen since it landed, so the key is absent — and both switches
 * ship off, so the truthful answer is "neither". The first save that switches a
 * wallet on is also the first save that writes the key. The two cannot be out of
 * step in the direction that matters: the projection can be stale only by
 * hiding a mark, never by inventing one.
 */
class Wallets
{
    /** The wallet ids this shop knows, mapped to their `pay_*` mark key. */
    public const MARK_KEYS = [
        'apple_pay' => 'pay_apple',
        'google_pay' => 'pay_google',
    ];

    /** The gateway-config key each wallet's switch is stored under. */
    public const CONFIG_KEYS = [
        'apple_pay' => 'wallet_apple_pay',
        'google_pay' => 'wallet_google_pay',
    ];

    /** The gateway every wallet here rides. There is only one, by design. */
    public const GATEWAY = 'stripe';

    /**
     * The settings key the projection lives under.
     *
     * A COMMA-SEPARATED LIST OF WALLET IDS, not two boolean rows and not JSON.
     * One key is one row in the snapshot SettingsService already reads, an
     * empty string is the whole of "neither", and a list read back through
     * explode() cannot produce a wallet id this class does not know — the
     * lookup is against CONFIG_KEYS either way.
     *
     * Autoloaded, so it arrives in the map SettingsService::all() serves
     * without a second read.
     */
    public const SETTING_KEY = 'wallets_offered';

    /** Per-request memo, for the pages that ask twice. */
    private ?array $memo = null;

    /** Can this shop actually take Apple Pay right now? */
    public function applePay(): bool
    {
        return $this->offered('apple_pay');
    }

    /** Can this shop actually take Google Pay right now? */
    public function googlePay(): bool
    {
        return $this->offered('google_pay');
    }

    /**
     * The one question, asked of one place.
     *
     * An unknown wallet id is false rather than an exception: this is read from
     * templates, and a typo in a Blade must lose a logo, never a page.
     */
    public function offered(string $wallet): bool
    {
        return (bool) ($this->all()[$wallet] ?? false);
    }

    /** True when at least one wallet can be taken. */
    public function any(): bool
    {
        return in_array(true, $this->all(), true);
    }

    /**
     * The answer for every wallet, keyed by the `pay_*` mark key the trust
     * rows and the chip lists already use.
     *
     * This is the shape CartPage::paymentMarks() and SlimFooter::paymentMarks()
     * want: they walk PaymentMarkArt::marks(), which is keyed `pay_apple`,
     * `pay_google` and four scheme keys, and need to know which of those keys
     * carry an extra condition. A key that is NOT in this array has no wallet
     * behind it and is governed by its own switch alone — Visa and Mastercard
     * are claims about card acceptance, which the card gateway already answers.
     *
     * @return array<string, bool>
     */
    public function markGates(): array
    {
        $out = [];

        foreach (self::MARK_KEYS as $wallet => $markKey) {
            $out[$markKey] = $this->offered($wallet);
        }

        return $out;
    }

    /**
     * Should this `pay_*` mark be drawn, given the merchant's own switch for it?
     *
     * One call per mark, so no caller has to know which of the six keys is a
     * wallet. `$switchedOn` is the appearance setting the screen already has;
     * this adds the capability gate on top of it and only for the two keys that
     * have one.
     */
    public function markAllowed(string $markKey, bool $switchedOn): bool
    {
        if (! $switchedOn) {
            return false;
        }

        $gates = $this->markGates();

        return ! array_key_exists($markKey, $gates) || $gates[$markKey];
    }

    /** Drop the per-request memo. Safe to call at any time. */
    public function forget(): void
    {
        $this->memo = null;
    }

    /**
     * Recompute the projection from the gateway rows and store it.
     *
     * Called from PaymentProvider::booted() on every save and delete — see the
     * class docblock for why that is every path that can move a gate.
     *
     * IT CANNOT THROW AND IT LEAVES NOTHING BEHIND. Read the swallowed-exception
     * landmine in CLAUDE.md before loosening this: an update that wrote an
     * attribute inside a try/catch left the model dirty, and every later save
     * on that instance re-sent it until one escaped and bricked the updater.
     * Nothing here touches the model it is hanging off. It writes ONE settings
     * row through the service that owns that table, and a failure to write it
     * leaves the shop drawing no wallet marks — which is the safe answer, and
     * recovered by the next save of the payments screen.
     */
    public function project(): void
    {
        $this->memo = null;

        try {
            $offered = array_keys(array_filter($this->compute()));

            app(SettingsService::class)->set(self::SETTING_KEY, implode(',', $offered));
        } catch (\Throwable) {
            // A settings table we cannot write is one the payments screen will
            // be saved against again. There is nothing to undo here and nothing
            // half-written: set() is a single updateOrCreate.
        }
    }

    /**
     * @return array<string, bool>
     */
    private function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        try {
            $stored = app(SettingsService::class)->get(self::SETTING_KEY, '');
        } catch (\Throwable) {
            // Same rule as GatewayCredentials::all(): a table that cannot be
            // read must not take the footer of every page down with it. "No
            // wallet" is the safe answer — it draws no mark and offers no
            // button, which is exactly the state a shop that cannot answer the
            // question should present.
            $stored = '';
        }

        $offered = is_string($stored)
            ? array_filter(array_map('trim', explode(',', $stored)))
            : [];

        $out = $this->none();

        foreach ($offered as $wallet) {
            // Only ids this class knows. A value hand-edited into the row
            // cannot introduce a wallet, only fail to match one.
            if (array_key_exists($wallet, $out)) {
                $out[$wallet] = true;
            }
        }

        return $this->memo = $out;
    }

    /** @return array<string, bool> */
    private function compute(): array
    {
        $row = PaymentProvider::find(self::GATEWAY);

        if ($row === null || ! $row->enabled) {
            return $this->none();
        }

        /*
         * The build still ships code for this gateway. GatewayRegistry::supports()
         * checks class_exists as well as its own map, for the reason its own
         * comment gives: three files went missing from a package on this project
         * once already, and a wallet button that pays through a gateway class
         * that is not there is a checkout that 500s on submit.
         */
        if (! app(GatewayRegistry::class)->supports(self::GATEWAY)) {
            return $this->none();
        }

        /*
         * READ OFF THE ROW WE ALREADY HAVE, not through GatewayCredentials.
         *
         * Same values, same encrypted column — but GatewayCredentials memoises
         * per INSTANCE and is not a singleton, so asking it here would be a
         * second `payment_providers` read on the one code path that the footer
         * of every page of the shop goes through. This method is the whole cost
         * of the wallet marks and it is exactly one query.
         *
         * The try/catch is GatewayCredentials::all()'s, for its reason: a
         * decrypt failure (the app key was rotated, the row was written by
         * another install) reads as "not configured", which is the safe answer,
         * and must not take a page down.
         */
        try {
            $config = $row->config;
        } catch (\Throwable) {
            $config = null;
        }

        $config = is_array($config) ? $config : [];

        $value = static fn (string $key): string => is_scalar($config[$key] ?? null)
            ? trim((string) $config[$key])
            : '';

        // Gates 2 and 3 together: the secret key opens the intent, the
        // publishable key boots the script that draws the sheet.
        // The keys of the CURRENT mode (Lane SR): StripeKeys is a pure function
        // of the row this method already holds, so the test/live split costs
        // the footer of every page nothing.
        $mode = (string) ($row->mode ?? 'test');

        if (\App\Services\Payments\StripeKeys::get($config, $mode, 'secret_key') === ''
            || \App\Services\Payments\StripeKeys::get($config, $mode, 'publishable_key') === '') {
            return $this->none();
        }

        $out = [];

        foreach (self::CONFIG_KEYS as $wallet => $configKey) {
            // Compared as a string, exactly as StripeGateway::linkEnabled()
            // compares its own switch: the config blob stores whatever the
            // admin posted, so the honest question is "did somebody switch
            // this on", and absent, '' and '0' are all off.
            $out[$wallet] = $value($configKey) === '1';
        }

        return $out;
    }

    /** @return array<string, bool> */
    private function none(): array
    {
        return array_fill_keys(array_keys(self::CONFIG_KEYS), false);
    }
}
