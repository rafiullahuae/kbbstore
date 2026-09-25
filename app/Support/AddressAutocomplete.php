<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SettingsService;

/**
 * Address autocomplete — the `address_autocomplete` module, built as far as it
 * can honestly be built (Lane M).
 *
 * ── WHAT IS HERE AND WHAT IS NOT ────────────────────────────────────────────
 *
 * This lane could not finish this module and is not pretending to. Two things
 * are missing and neither is code:
 *
 *   1. A Google Cloud project, a Places API key and a billing account. Places
 *      Autocomplete is metered and billed per session. Nobody has supplied one,
 *      and egress from the build container is blocked, so not one request to
 *      Google has been made or could be made from here.
 *   2. The owner's answer to a PRIVACY question — see CONSENT below.
 *
 * So what is built is everything that does not need either: the gate, the key
 * setting, the consent setting, the schema, the checkout integration, and one
 * seam — scriptUrl() — where the third-party origin appears, so a test can
 * stand in for a network that is not reachable from here.
 *
 * THE MODULE SHIPS OFF, AND ANSWERS null UNTIL ALL THREE ARE TRUE: switched on,
 * a key stored, and consent given. Any one of them missing and the checkout
 * renders exactly what it renders today — no script, no element, and no address
 * leaving the shopper's browser. That is not a courtesy; it is the only state
 * this lane is in a position to ship, because nobody has agreed to the other one.
 *
 * ── CONSENT: THE DECISION THAT IS NOT A DEVELOPER'S ─────────────────────────
 *
 * Google's Places widget posts EVERY CHARACTER typed into the address field to
 * Google, as the shopper types, before any form is submitted and whether or not
 * the order is ever placed. A shopper half-typing their home address into a
 * checkout is sending that fragment to a third party.
 *
 * This shop already asks the owner to decide about marketing pixels, which are
 * the same class of decision — data about a shopper leaving the shop — and it
 * asks it as a control rather than assuming. This does the same. The setting
 * has THREE values and ships `unanswered`, which behaves exactly like `no`:
 *
 *     unanswered  nothing is sent, and the screen asks the question
 *     no          nothing is sent; the owner has decided
 *     yes         the widget runs, when a key is also stored
 *
 * `unanswered` exists as its own value rather than defaulting to `no` because
 * the two mean different things to the person reading the screen, and a
 * question that defaults to an answer is a question nobody is ever shown. The
 * shop behaves identically under both.
 *
 * ── WHY NOT JUST A KEY FIELD ────────────────────────────────────────────────
 *
 * Because a key field alone would make this a decision taken by whoever happens
 * to paste a key in, which is precisely the shape the project notes call out:
 * "Any NEW setting ships at the value the page already has, so applying the
 * package moves nothing until somebody moves a slider." Pasting a key is
 * somebody moving a slider; agreeing to send shoppers' half-typed home
 * addresses to Google is not the same act and should not be the same gesture.
 *
 * ── THE SEAM ────────────────────────────────────────────────────────────────
 *
 * scriptUrl() is the ONLY place in this app where Google's origin is written,
 * and it is a pure function of the stored key. It is therefore the one thing a
 * test has to stand in for to test everything else — and the tests do exactly
 * that, asserting the URL this builds rather than fetching it, because the
 * container this was built in cannot reach Google and neither can CI.
 *
 * The country restriction is 'ae' and is not a setting. This shop delivers in
 * the UAE; a restriction that could be widened by a control is a control whose
 * wrong value quietly spends the owner's Places budget on suggestions for
 * addresses they do not ship to.
 */
final class AddressAutocomplete
{
    /** The Google Places API key. Empty means the module cannot run. */
    public const KEY_API = 'checkout_address_key';

    /** The owner's answer to the privacy question. */
    public const KEY_CONSENT = 'checkout_address_consent';

    /** value => admin label, and the only three values consent may hold. */
    public const CONSENT = [
        'unanswered' => 'Not decided yet — nothing is sent',
        'no' => 'No — do not send anything to Google',
        'yes' => 'Yes — send what is typed to Google for suggestions',
    ];

    public const DEFAULT_CONSENT = 'unanswered';

    /** Where the shop delivers, so suggestions are addresses it can reach. */
    public const COUNTRY = 'ae';

    /**
     * The one place the third-party origin is written.
     *
     * Null for an empty or implausible key rather than a URL with a blank
     * parameter — a script tag pointing at Google with no key is a request that
     * fails in the shopper's browser and tells Google the page exists anyway.
     *
     * The key is restricted to the characters Google issues (its browser keys
     * are `AIza` + 35 URL-safe characters) before it is interpolated. That is
     * a rule-5 check, not a courtesy: this string is written into a `src`
     * attribute, and a key containing a quote or a space would otherwise close
     * the attribute. Refused rather than escaped, because a key that needs
     * escaping is not a key.
     */
    public static function scriptUrl(string $key): ?string
    {
        $key = trim($key);

        if (preg_match('/^[A-Za-z0-9_\-]{20,64}$/', $key) !== 1) {
            return null;
        }

        return 'https://maps.googleapis.com/maps/api/js'
            .'?key='.rawurlencode($key)
            .'&libraries=places'
            .'&loading=async';
    }

    /**
     * The module's configuration, or null when it must not run.
     *
     * Null is returned for a switched-off module, a missing or malformed key,
     * and an owner who has not said yes — three separate reasons, one answer,
     * because the page renders the same nothing for all three and the shopper
     * is owed the same silence.
     *
     * @return array{url: string, country: string}|null
     */
    public static function config(SettingsService $settings): ?array
    {
        /*
         * Spelled out rather than referenced through a constant.
         * ModuleFrameworkGuardTest finds a module's reader by TOKENISING for a
         * `moduleEnabled('<key>')` call with a literal first argument, so a
         * constant here would make this module look unported to the guard whose
         * whole job is to notice that. InlineValidation and StockAlerts record
         * the same reasoning against the same guard.
         */
        if (! $settings->moduleEnabled('address_autocomplete', false)) {
            return null;
        }

        if (self::consent($settings) !== 'yes') {
            return null;
        }

        $url = self::scriptUrl((string) $settings->get(self::KEY_API, ''));

        if ($url === null) {
            return null;
        }

        return ['url' => $url, 'country' => self::COUNTRY];
    }

    /** The owner's stored answer, falling back to the value that sends nothing. */
    public static function consent(SettingsService $settings): string
    {
        $value = (string) $settings->get(self::KEY_CONSENT, self::DEFAULT_CONSENT);

        // A select holds one of its own options or the default — and here the
        // default is the safe one, so a stored typo cannot turn sending on.
        return isset(self::CONSENT[$value]) ? $value : self::DEFAULT_CONSENT;
    }

    /**
     * Whether the owner has a key stored, for the admin screen to say so
     * without echoing the key back into a page.
     */
    public static function hasKey(SettingsService $settings): bool
    {
        return self::scriptUrl((string) $settings->get(self::KEY_API, '')) !== null;
    }
}
