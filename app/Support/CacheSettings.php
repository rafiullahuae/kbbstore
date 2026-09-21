<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Middleware\CacheHeaders;
use App\Services\SettingsService;

/**
 * Platform → Cache. The three stored values, and the two strings they build.
 *
 * ---------------------------------------------------------------------------
 * WHY THERE IS A SWITCH AT ALL, WHEN THE POLICY IS ALREADY WRITTEN DOWN
 * ---------------------------------------------------------------------------
 *
 * App\Http\Middleware\CacheHeaders has existed, complete and tested, since
 * Lane FQ, and has been registered NOWHERE: its registration was written out in
 * docs/FQ-CACHE-HEADERS.md as a hand-edit to bootstrap/app.php, because
 * bootstrap/ is on BuildPackage::NEVER_SHIP and UpdateGuard::FORBIDDEN_PREFIXES
 * and no package can carry it. The hand-edit was never applied. That is the
 * same way /ar shipped switched on with the middleware that makes it work
 * absent — see the long note in AppServiceProvider::boot().
 *
 * So the registration moves to AppServiceProvider, which ships. And the moment
 * it ships, applying one package changes the Cache-Control header on every page
 * of a LIVE shop, silently, with nobody having asked for it. This shop is
 * live at extrabeauty.ae and takes real orders.
 *
 * Hence `cache.headers_enabled`, DEFAULT FALSE. The middleware is in the web
 * group on every install and does nothing at all until the owner switches it on
 * from the Cache screen, where the screen says what will change before he does.
 * This is the same shape as CanonicalHost, which is registered unconditionally
 * and inert until `canonical_host` is filled in: "inert until configured" is
 * how this repository ships a middleware to a shop it cannot log in to.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS NOT A SETTING, AND WILL NOT BECOME ONE
 * ---------------------------------------------------------------------------
 *
 * The no-store on the customer's own pages. CacheHeaders::PRIVATE_PREFIXES —
 * my-account, my-wishlist, wishlist, cart, checkout, track-my-order — is not
 * reachable from this class, there is no key for it below, and the screen draws
 * it as a statement rather than a control. A shop that caches a signed-in
 * shopper's cart page in a shared proxy hands one customer's basket to the
 * next, and a switch that can do that is a switch somebody eventually flips.
 * CacheControlScreenTest asserts both halves: that the pages are no-store
 * whatever the two numbers below are set to, and that no stored setting can
 * turn it off.
 *
 * The same goes for `public`. Storefront HTML carries a cart badge, a signed-in
 * name and a CSRF token; NoStoreAdminApi's own docblock records that shared
 * hosting commonly caches GET responses by default. storefrontHeader() below
 * cannot emit the word `public` for any value of HTML_MAX_AGE, and a case
 * pins that across the whole legal range rather than at the default.
 *
 * ---------------------------------------------------------------------------
 * AND ONE SETTING THAT CHANGES NO HEADER THIS APPLICATION SENDS
 * ---------------------------------------------------------------------------
 *
 * ASSET_MAX_AGE. Hashed build files and generated image variants are served
 * straight off disk by the web server and never reach PHP on this host —
 * docs/IMAGE-PIPELINE-AND-CACHE.md §9.4 — so there is no PHP hook to hang a
 * header on, and this number's only effect is the text htaccess() prints for
 * the owner to paste. The screen says that in those words rather than drawing a
 * control that looks like it does something. It is settable because the owner
 * asked for full control of caching and a year is a long time to be unable to
 * shorten; it is honest about where the number has to be carried by hand.
 */
final class CacheSettings
{
    public const ENABLED = 'cache.headers_enabled';

    public const HTML_MAX_AGE = 'cache.html_max_age';

    public const ASSET_MAX_AGE = 'cache.asset_max_age';

    /**
     * An hour, and the reason is the cart badge.
     *
     * A storefront page reused out of the BROWSER's own cache without asking
     * this server shows the basket count, the signed-in name and the CSRF token
     * it was rendered with. `private` keeps it out of every shared cache, so
     * the exposure is to the one person already looking at it — but a stale
     * badge is a support ticket, and beyond an hour the owner is trading a
     * visible wrongness for a saving he cannot measure. 0 is the shipped value
     * and reproduces the policy exactly.
     */
    public const HTML_MAX_AGE_CEILING = 3600;

    /** One year: the ceiling every major browser honours. CacheHeaders::IMMUTABLE. */
    public const ASSET_MAX_AGE_CEILING = 31536000;

    /**
     * The extensions the pasted .htaccess claims.
     *
     * Kept here rather than only in the file, because the file lives under
     * docs/ — which is on BuildPackage::NEVER_SHIP and therefore does NOT
     * EXIST on a customer's server. A screen that read it off disk would show
     * the owner an empty box on the only machine where he needs it. The text
     * is built here and CacheControlScreenTest holds it byte-for-byte against
     * the directives in docs/cache-headers.htaccess, so the two cannot drift.
     */
    public const ASSET_EXTENSIONS = 'css|js|mjs|map|jpe?g|png|webp|gif|svg|ico|woff2?';

    /** key => [type, default, min, max]. */
    public const SCHEMA = [
        self::ENABLED => ['bool', false, 0, 1],
        self::HTML_MAX_AGE => ['int', 0, 0, self::HTML_MAX_AGE_CEILING],
        self::ASSET_MAX_AGE => ['int', self::ASSET_MAX_AGE_CEILING, 0, self::ASSET_MAX_AGE_CEILING],
    ];

    /**
     * The name the SCREEN uses for each key, and why the two differ.
     *
     * ▲ A DOT IN A FIELD NAME IS NOT A CHARACTER TO LARAVEL'S VALIDATOR, IT IS
     * A PATH. `$request->validate(['cache.headers_enabled' => ...])` does not
     * validate a field of that name: it validates `headers_enabled` INSIDE an
     * object called `cache`, finds nothing there, and -- because every rule
     * below is `sometimes` -- passes, returning an empty set. The save then
     * writes nothing and the screen reads back the value it had before, which
     * looks exactly like a control that does not work.
     *
     * Found by running it. The first version of this feature posted the
     * settings under their storage keys, and every save was a silent no-op
     * except that it answered 200; the ceiling rule did not fire either, so a
     * max-age of 99999 was accepted and then quietly clamped on the way out.
     * Escaping the dot is possible and is a trick nobody reading the next
     * version would know to look for, so the wire names simply have no dots in
     * them and this map is the one place the two vocabularies meet.
     */
    public const FIELDS = [
        'headers_enabled' => self::ENABLED,
        'html_max_age' => self::HTML_MAX_AGE,
        'asset_max_age' => self::ASSET_MAX_AGE,
    ];

    /**
     * Every setting, keyed the way the SCREEN names them.
     *
     * @return array<string, bool|int>
     */
    public static function all(SettingsService $settings): array
    {
        $out = [];

        foreach (self::FIELDS as $field => $key) {
            [, $default] = self::SCHEMA[$key];

            $out[$field] = self::normalise($key, $settings->get($key, $default));
        }

        return $out;
    }

    /**
     * Is the middleware allowed to touch a response?
     *
     * Read at REQUEST time and not at boot. The registration in
     * AppServiceProvider is unconditional on purpose: a provider that queried
     * the settings table to decide whether to register would run that query on
     * every console command too, including `migrate` on an install whose
     * settings table does not exist yet — which is an application that cannot
     * boot to run the migration that would fix it. There is no shell here to
     * fix that with.
     */
    public static function enabled(SettingsService $settings): bool
    {
        try {
            return (bool) self::normalise(self::ENABLED, $settings->get(self::ENABLED, false));
        } catch (\Throwable) {
            /*
             * FALSE, WHICH IS THE BEHAVIOUR OF A SHOP WITHOUT THIS FEATURE.
             *
             * Nothing normal reaches here -- SettingsService::all() is already
             * read by the layout's view composer on every storefront page, so a
             * settings table this could not read is a shop that is already
             * down. But this middleware is now in the `web` group on EVERY
             * install, and the one thing it must never be is a new way for a
             * page to 500. A caching policy is worth exactly nothing next to a
             * shop that renders.
             */
            return false;
        }
    }

    public static function normalise(string $key, mixed $value): bool|int
    {
        [$type, $default, $min, $max] = self::SCHEMA[$key]
            ?? throw new \InvalidArgumentException("Unknown cache setting [{$key}].");

        return match ($type) {
            // The shapes a false comes back as from a longText column. Copied
            // in spirit from ReviewSettings::normalise(), which learned them
            // from an actual WordPress export: PHP's own (bool) reads '' and
            // '0' as false but reads the word 'false' as TRUE.
            'bool' => ! in_array(
                mb_strtolower(trim((string) (is_bool($value) ? ($value ? '1' : '0') : $value))),
                ['', '0', 'false', 'off', 'no', 'null'],
                true
            ),
            default => max((int) $min, min((int) $max, (int) $value)),
        };
    }

    /**
     * What storefront HTML leaves with, for a given max-age.
     *
     * AT ZERO THIS IS CacheHeaders::REVALIDATE, THE SAME STRING, and that is
     * asserted rather than arranged: the shipped default has to be the policy
     * that docs/IMAGE-PIPELINE-AND-CACHE.md §9.1 states and that
     * CacheHeaderPolicyTest already pins, or turning the feature on would be
     * two changes at once and only one of them asked for.
     *
     * Above zero, `no-cache` goes and `max-age` arrives — those are the two
     * halves of the same decision and a header carrying both says nothing a
     * cache can act on. `private` and `must-revalidate` stay in every case.
     * `private` is the one that matters: it is what keeps the page out of
     * every cache that is not this one browser's.
     */
    public static function storefrontHeader(int $maxAge): string
    {
        $maxAge = max(0, min(self::HTML_MAX_AGE_CEILING, $maxAge));

        return $maxAge === 0
            ? CacheHeaders::REVALIDATE
            : "private, max-age={$maxAge}, must-revalidate";
    }

    /** The Cache-Control the web server is asked to put on content-addressed files. */
    public static function assetHeader(int $maxAge): string
    {
        $maxAge = max(0, min(self::ASSET_MAX_AGE_CEILING, $maxAge));

        return $maxAge === self::ASSET_MAX_AGE_CEILING
            ? CacheHeaders::IMMUTABLE
            : "public, max-age={$maxAge}, immutable";
    }

    /**
     * The directives to paste, and nothing else.
     *
     * No RewriteRule, no Options, no ErrorDocument, and every line inside an
     * <IfModule mod_headers.c> guard. That is not tidiness: §9.4 of the
     * pipeline document works through the three things nobody can check from
     * here, and the third — whether AllowOverride grants FileInfo — turns a
     * directive Apache may not process into a 500 on EVERY FILE IN THAT
     * DIRECTORY. For /build/ that is a shop with no stylesheet, on a host with
     * no shell. The guard scopes the blast radius to "does nothing".
     */
    public static function htaccess(int $assetMaxAge): string
    {
        $header = self::assetHeader($assetMaxAge);

        return "<IfModule mod_headers.c>\n"
            . '    <FilesMatch "\.(' . self::ASSET_EXTENSIONS . ')$">' . "\n"
            . '        Header set Cache-Control "' . $header . '"' . "\n"
            . "    </FilesMatch>\n"
            . '</IfModule>';
    }
}
