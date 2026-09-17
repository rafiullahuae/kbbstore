<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SettingsService;

/**
 * The checkout's live field validation — the `inline_validation` module,
 * ported (Lane FI).
 *
 * ── WHAT THE PLUGIN SHIPS, AND WHAT IS THEREFORE BEING PORTED ───────────────
 *
 * The plugin's entry is "Live green/red validation as the customer types", and
 * its implementation is a settings LINK pointing at **kbb-theme**, which was
 * never supplied. §2 of the master plan files it under "blocked on a missing
 * source" on exactly that basis, and that half is still true: there is no code
 * to copy.
 *
 * What there IS, and what makes this a port rather than an invention, is the
 * CONTRACT the markup already carries. Every checkout row on this storefront is
 * rendered by components/checkout/field.blade.php, which writes WooCommerce's
 * own `validate-required`, `validate-email`, `validate-state` and
 * `validate-phone` classes onto the `.form-row`. That component's own header
 * says what this module is for, in as many words:
 *
 *     "Nothing in this repo's CSS or JS reads `validate-*` or `required_field`,
 *      but they are part of the markup the live theme serves."
 *
 * WooCommerce's checkout.js reads those classes and puts `woocommerce-invalid`
 * or `woocommerce-validated` on the row; the theme styles the result. This
 * module is that reader. The class names below are Woo's, not invented, so a
 * stylesheet carried over from the live theme keeps matching.
 *
 * ── WHY OFF BY DEFAULT, WHEN THE PLUGIN SHIPS THE CHECKOUT GROUP ON ─────────
 *
 * `legal_notice` and the five order emails are on by default and each row says
 * why; the rule they share is that the default is measured against WHAT THE
 * STORE DOES WITHOUT THE SWITCH, not against a blank slate.
 *
 * Measured that way this one goes the other direction. Without the switch this
 * checkout shows no marks at all: the only validation a shopper meets is the
 * browser's own bubble on Place order, and checkout.js's long comment about
 * scrolling the first `:invalid` control into view is the whole of it. So ON by
 * default would mean applying a package and finding that the shop's one form
 * that takes money had started colouring itself in — a visible change to the
 * page every order passes through, made by nobody. Off, and applying the
 * package changes this page by exactly nothing, which is the property
 * ModuleInlineValidationTest pins first.
 *
 * ── THE THREE SETTINGS, AND WHY THEY ARE THESE THREE ────────────────────────
 *
 *   WHEN     `blur` or `type`. The plugin's blurb says "as the customer types"
 *            and `type` is that, faithfully; `blur` is the default because
 *            "as they type" judges an email address wrong at the first letter
 *            and then stays red for the next twenty keystrokes. Under `blur` a
 *            field is left alone until the shopper leaves it, and only then is
 *            it watched live, so a correction is confirmed instantly and a
 *            first attempt is never interrupted. Both are real; which one a
 *            shop wants is the owner's to choose, which is why it is a control
 *            and not a constant.
 *
 *   OK       The green half. Off, only faults are marked — the quieter reading
 *            of the same feature, and a legitimate one: a tick beside every
 *            correct field is nine ticks on this form.
 *
 *   HINT     The words under a wrong field. Off, the red mark stands alone.
 *
 * Every one of the three is read by partials/checkout/inline-validation.blade.php
 * and changes what that partial renders. None of them is a control that saves
 * and does nothing, which is the fault ModuleSchema's header catalogues and the
 * one this project keeps paying for.
 *
 * ── AND WHY THE CONFIG COMES BACK AS null RATHER THAN A DISABLED FLAG ───────
 *
 * null means the caller renders NO ELEMENT — no style block, no script, no
 * data island. A flag would tempt the partial into shipping the machinery and
 * leaving it switched off in the browser, which is markup on a page a shopper
 * loads and therefore not "no trace". Support\CheckoutLegalNotice and
 * Services\StockAlerts::formLabel() make the same choice for the same reason.
 */
final class InlineValidation
{
    /** When the marks first appear. */
    public const KEY_WHEN = 'checkout_validate_when';

    /** Whether a correct field is marked as well as a wrong one. */
    public const KEY_OK = 'checkout_validate_ok';

    /** Whether a wrong field gets a sentence under it. */
    public const KEY_HINT = 'checkout_validate_hint';

    /** value => admin label, and the only two values `when` may hold. */
    public const WHEN = [
        'blur' => 'After they leave the field',
        'type' => 'As they type',
    ];

    public const DEFAULT_WHEN = 'blur';

    /**
     * The module's configuration, or null when it is switched off.
     *
     * @return array{when: string, ok: bool, hint: bool}|null
     */
    public static function config(SettingsService $settings): ?array
    {
        /*
         * The key is spelled out rather than referenced through a constant.
         * ModuleFrameworkGuardTest finds a module's reader by TOKENISING for a
         * `moduleEnabled('<key>')` call with a literal first argument, and
         * treats a row it cannot find one for as a switch that does nothing —
         * so a constant here would make this module look unported to the guard
         * whose whole job is to notice that. Services\StockAlerts records the
         * same reasoning against the same guard.
         */
        if (! $settings->moduleEnabled('inline_validation', false)) {
            return null;
        }

        $when = (string) $settings->get(self::KEY_WHEN, self::DEFAULT_WHEN);

        return [
            // An unknown value falls back rather than reaching the browser: the
            // script compares against 'type' and anything else behaves as
            // 'blur', but a stored typo should not be the thing that decides
            // that.
            'when' => isset(self::WHEN[$when]) ? $when : self::DEFAULT_WHEN,
            'ok' => (bool) $settings->get(self::KEY_OK, true),
            'hint' => (bool) $settings->get(self::KEY_HINT, true),
        ];
    }
}
