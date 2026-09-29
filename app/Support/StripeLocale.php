<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The language Stripe.js speaks on this checkout.
 *
 * ── THE DEFECT THIS EXISTS TO CLOSE ─────────────────────────────────────────
 *
 * Neither stripe.elements() call in the checkout passed a `locale`, and neither
 * Stripe() constructor did either. Stripe's documented default is `auto`, which
 * means THE BROWSER'S language — not the shop's. So on an Arabic checkout the
 * Apple Pay / Google Pay button label and every decline sentence Stripe writes
 * came back in whatever language the phone happens to be set to, wrapped in four
 * carefully translated Arabic sentences of ours. At the exact moment a payment
 * has failed, which is the worst moment for a page to look broken.
 *
 * Lane AR2 measured it and reported it rather than fixing it, because the same
 * option belongs on both files and they were two lanes' (see
 * tests/Feature/ArabicWalletRowTest.php's closing paragraph). This is that
 * option, decided in one place so the two files cannot drift.
 *
 * ── WHY A MAP AND NOT THE SHOP'S STRING ─────────────────────────────────────
 *
 * Stripe accepts `auto` plus a fixed set of language tags and nothing else; a
 * tag it does not know is not ignored, it is a Stripe.js error at boot — on the
 * card fields, which is an outage. This shop's locale codes happen to be two
 * that Stripe knows (`en`, `ar`), so passing the raw string would work TODAY and
 * would break the day somebody adds a row to Locale::LOCALES. Locale's own
 * docblock says adding French later is "a row here plus its translations; no
 * code below reads a locale code literally" — so this must not be the one place
 * that does.
 *
 * ── THE FALLBACK, AND WHICH WAY IT GOES ─────────────────────────────────────
 *
 * DELIBERATELY NOT `auto`. `auto` is the defect: it is what the checkout does
 * today and it is how the shopper ends up reading a third language. If Stripe
 * cannot speak the page's language, the next most useful thing it can speak is
 * the shop's DEFAULT language — which is what every other unresolved string on
 * this shop falls back to (Translation's fallback chain ends at
 * Locale::DEFAULT), so a shopper who is already reading some English furniture
 * reads an English decline reason rather than a surprise fourth one.
 *
 * `auto` survives as the last resort only, for a shop whose default language
 * Stripe also does not know. That is unreachable today — Locale::DEFAULT is
 * 'en' — and it is here so this class can never return something Stripe refuses.
 *
 * ── THE LIST ────────────────────────────────────────────────────────────────
 *
 * Stripe's published supported-locales appendix for Stripe.js
 * (docs.stripe.com/js/appendix/supported_locales). It is a list of tags and not
 * a rule, so it is written out rather than computed. Two properties make a stale
 * copy safe in both directions: a tag that Stripe has ADDED since simply falls
 * back here rather than being passed, and a tag Stripe has REMOVED is the only
 * way this could hand Stripe something it refuses — which is why the entries are
 * base languages and Stripe's documented regional pairs, not guesses.
 *
 * Matching is lenient in the one direction that is always safe: `pt-PT` and
 * `pt_PT` both resolve to `pt`, because a tag's base language is a tag Stripe
 * lists. It is never lenient the other way — `pt` does not become `pt-BR`,
 * because choosing a region for a shop that did not name one is a decision this
 * class has no business making.
 *
 * SAID PLAINLY: the list below could NOT be re-fetched from Stripe when this was
 * written — docs.stripe.com is refused by this container's egress proxy, and so
 * is js.stripe.com, which is also why no test here can ask Stripe.js what it
 * accepts. What that leaves unproven is whether Stripe has since added or
 * renamed a tag, and neither direction can hurt this shop today: the only two
 * locales App\Support\Locale::LOCALES holds are `en` and `ar`, both of which
 * Stripe has supported since Elements shipped. A third language added later
 * should be checked against the appendix at the same time as its translations.
 */
final class StripeLocale
{
    /**
     * Every language tag Stripe.js accepts for `locale`, plus `auto`.
     *
     * @var list<string>
     */
    public const SUPPORTED = [
        'auto',
        'ar', 'bg', 'cs', 'da', 'de', 'el', 'en', 'en-GB', 'es', 'es-419', 'et',
        'fi', 'fil', 'fr', 'fr-CA', 'he', 'hr', 'hu', 'id', 'it', 'ja', 'ko',
        'lt', 'lv', 'ms', 'mt', 'nb', 'nl', 'pl', 'pt', 'pt-BR', 'ro', 'ru',
        'sk', 'sl', 'sv', 'th', 'tr', 'vi', 'zh', 'zh-HK', 'zh-TW',
    ];

    /** What Stripe is told when it knows neither the page's language nor the shop's. */
    public const LAST_RESORT = 'auto';

    /**
     * The tag to hand Stripe for the locale this request is being served in.
     *
     * Never empty and never a tag outside self::SUPPORTED, so the two views
     * that print this can print it without a guard of their own.
     */
    public static function current(?string $locale = null): string
    {
        $locale ??= Locale::current();

        return self::tag($locale)
            ?? self::tag(Locale::DEFAULT)
            ?? self::LAST_RESORT;
    }

    /**
     * Whether the shop's language reaches Stripe as itself.
     *
     * False means a shopper reading this page gets Stripe's furniture in the
     * fallback language instead — worth saying on a screen, and worth asserting
     * in a test, so it is answerable rather than inferred from the tag.
     */
    public static function speaks(?string $locale = null): bool
    {
        return self::tag($locale ?? Locale::current()) !== null;
    }

    /**
     * One locale code as a tag Stripe lists, or null if it lists neither the
     * code nor its base language.
     */
    private static function tag(string $locale): ?string
    {
        $locale = str_replace('_', '-', trim($locale));

        if ($locale === '') {
            return null;
        }

        foreach (self::SUPPORTED as $supported) {
            // Tags are case-insensitive (BCP 47 §2.1.1) and Stripe's list is
            // written in its own casing, so the ANSWER is Stripe's spelling and
            // never the caller's.
            if (strcasecmp($supported, $locale) === 0) {
                return $supported === self::LAST_RESORT ? null : $supported;
            }
        }

        $base = strtok($locale, '-');

        if ($base === false || $base === '' || strcasecmp($base, $locale) === 0) {
            return null;
        }

        return self::tag($base);
    }
}
