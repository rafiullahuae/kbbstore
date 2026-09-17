<?php

declare(strict_types=1);

namespace App\Services\Translation;

use App\Support\Locale;

/**
 * The interface strings the shop's JavaScript reads.
 *
 * ── WHY ONLY SOME OF THEM ───────────────────────────────────────────────────
 *
 * A page has no use for a table of strings it does not say. Everything the
 * Blade templates render is already rendered — server side, in the shopper's
 * language, by __(). What the front-end needs is the handful of messages it
 * builds itself: a toast after a failed add, the wording on a toggle it flips,
 * the placeholder it drops into a slot while a fetch is in flight. Those keys
 * are the `store.js.*` group, and this class is the one place that decides the
 * table is exactly that group and nothing else — not the cart's wording, not
 * the checkout's field labels, and never a setting.
 *
 * ── AND WHY NOT AT ALL IN ENGLISH ───────────────────────────────────────────
 *
 * resources/js/kbb/i18n.js carries the English of every one of these at its own
 * call site, because the bundle on this host is built off-server and is
 * routinely older than this repository — see that file's header. On an English
 * page the table would therefore be a copy of what the bundle already says, so
 * this returns an empty array and the template emits no <script> at all. That
 * also keeps the English page byte-for-byte what it was before the conversion,
 * which is the bar tests/Feature/StorefrontEnglishUnchangedTest.php holds.
 */
final class FrontEndStrings
{
    /** The Laravel group and the key prefix the front-end table is drawn from. */
    private const GROUP = 'store';

    private const PREFIX = 'js.';

    /**
     * key => wording, for one locale. Empty for the default language.
     *
     * @return array<string, string>
     */
    public static function forLocale(string $locale): array
    {
        if ($locale === Locale::DEFAULT) {
            return [];
        }

        $out = [];

        foreach (self::keys() as $key) {
            $out[$key] = (string) __($key, [], $locale);
        }

        return $out;
    }

    /**
     * One page's own table, for a page that is not part of the shared one.
     *
     * WHY A SECOND TABLE EXISTS AT ALL. forLocale() above ships EVERY store.js.*
     * key to every page, which is right while that set is the handful of
     * messages the bundled modules build themselves — 33 of them today. The
     * skin quiz is a different shape: it is a standalone document that does not
     * extend layouts.store, does not include partials/js-strings.blade.php, and
     * builds its whole interface in one inline <script> worth ~70 strings.
     * Putting those in the shared group would nearly quadruple the table on
     * every Arabic page in the shop to carry strings only /skin-quiz says,
     * which is precisely what this class's header argues against.
     *
     * So the quiz asks for its own prefix and emits its own window.KBB_T. The
     * fallback contract is unchanged: every call site in that script carries
     * its English, so a page with no table renders exactly as it did.
     *
     * @return array<string, string>
     */
    public static function forPrefix(string $prefix, string $locale): array
    {
        if ($locale === Locale::DEFAULT) {
            return [];
        }

        $out = [];

        foreach (array_keys(InterfaceStrings::group(self::GROUP)) as $key) {
            if (! str_starts_with($key, $prefix)) {
                continue;
            }

            $full = self::GROUP . '.' . $key;
            $out[$full] = (string) __($full, [], $locale);
        }

        return $out;
    }

    /**
     * Every front-end key, fully qualified.
     *
     * Read off InterfaceStrings rather than listed again here, so a key added
     * to the English source is in the table the moment it exists and a key
     * removed from it cannot linger as a stale entry nothing renders.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        $keys = [];

        foreach (array_keys(InterfaceStrings::group(self::GROUP)) as $key) {
            if (str_starts_with($key, self::PREFIX)) {
                $keys[] = self::GROUP . '.' . $key;
            }
        }

        return $keys;
    }
}
