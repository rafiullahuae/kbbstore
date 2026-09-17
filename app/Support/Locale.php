<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The two languages this shop speaks, and everything that follows from that.
 *
 * ── THE URL SHAPE, AND WHY ──────────────────────────────────────────────────
 *
 * English is served UNPREFIXED and Arabic is served under /ar/. The owner asked
 * for "/ar, and by default it will have /en"; /en/ exists and 301s to the
 * unprefixed form, so the address he asked for resolves and nothing already
 * indexed moves.
 *
 * The alternative — /en/ and /ar/ both real, with / redirecting — is symmetric
 * and costs a second migration of every URL on the site. This catalogue has
 * already been moved once, off WooCommerce. Moving it again would invalidate
 * every indexed address, every row in the redirects table (each would need a
 * second hop, and a 301 chain sheds ranking), the sitemap, every link in every
 * email this shop has ever sent, and every WhatsApp and Instagram link the
 * owner's customers have saved. The URL Contract exists precisely to stop that
 * happening a second time. Symmetry is not worth it.
 *
 * ── WHERE THE PREFIX IS ADDED AND REMOVED ───────────────────────────────────
 *
 * Removed once, in App\Http\Middleware\SetLocaleFromPath, BEFORE the router
 * runs. The router therefore never sees /ar at all, which is what makes this
 * cheap: not one route, redirect, sitemap entry or RESERVED_SLUGS guard has to
 * learn about a second language, and a route added by a later lane is bilingual
 * the day it is written without its author thinking about it.
 *
 * Added once, in App\Support\Url::to(), which every internal link in this
 * application already goes through. So an Arabic page links to Arabic pages and
 * canonicalises to its own Arabic address for free.
 *
 * ── WHAT `ar` MUST NOT SWALLOW ──────────────────────────────────────────────
 *
 * /wp-content/uploads/... and friends are served off disk by the web server,
 * never by PHP. Prefixing one produces a 404 for an image. localisable() is the
 * list, and Url::media() does not go through the localising path at all.
 *
 * The admin is deliberately NOT localised. It is one operator, it is not
 * indexed, and /ar/admin-api/... would be a second address for every
 * authenticated endpoint in the back office — a second surface to get wrong for
 * no gain.
 *
 * A segment containing a dot (sitemap.xml, robots.txt, llms.txt) is not
 * localisable either: those are machine-facing files with one canonical
 * address each, and a second copy of a sitemap is an SEO defect, not a feature.
 */
final class Locale
{
    /** The language served with no prefix. */
    public const DEFAULT = 'en';

    /**
     * Every language this shop speaks.
     *
     * `segment` is the URL prefix — empty for the default, which is the whole
     * of decision 1 expressed as data. Adding French later is a row here plus
     * its translations; no code below reads a locale code literally.
     *
     * @var array<string, array{name: string, native: string, dir: string, segment: string}>
     */
    public const LOCALES = [
        'en' => ['name' => 'English', 'native' => 'English', 'dir' => 'ltr', 'segment' => ''],
        'ar' => ['name' => 'Arabic', 'native' => 'العربية', 'dir' => 'rtl', 'segment' => 'ar'],
    ];

    /**
     * First path segments that must never carry a locale prefix.
     *
     * Static files (the web server answers these without PHP, so a prefixed
     * copy is a 404) and the back office (see the class doc).
     */
    public const UNLOCALISED_ROOTS = [
        'wp-content', 'storage', 'build', 'uploads', 'assets', 'images',
        'fonts', 'img-cache', 'admin-api', 'up', 'vendor',
    ];

    /**
     * The master switch: is the Arabic storefront live?
     *
     * @see self::enabled() for what "off" means and why it is a setting
     */
    public const SETTING_ENABLED = 'language_ar_enabled';

    /** The mirrored layout, switchable on its own. See self::isRtl(). */
    public const SETTING_RTL = 'language_rtl_enabled';

    /** Every language the code knows about, live or not. */
    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::LOCALES);
    }

    /**
     * Every language actually being served right now.
     *
     * This is what the sitemap, the hreflang tags and the language switcher
     * read. With Arabic off it is ['en'] and the shop is exactly the shop it is
     * today.
     *
     * @return list<string>
     */
    public static function enabledCodes(): array
    {
        return array_values(array_filter(self::codes(), static fn (string $c): bool => self::enabled($c)));
    }

    /**
     * Is this language switched on?
     *
     * ── WHAT "OFF" MEANS, EXACTLY ───────────────────────────────────────────
     *
     * /ar DOES NOT EXIST. Not "exists and is empty" — absent. The prefix is
     * never stripped, so the router sees /ar/shop/ as an ordinary path, finds
     * no route for it and 404s, which is the same answer the site gives today.
     * Url::to() adds no segment, so no link, canonical or sitemap entry points
     * there. alternatePaths() is empty, so no hreflang is emitted. The switcher
     * renders nothing.
     *
     * ── WHY THAT IS CHEAP HERE AND WOULD NOT HAVE BEEN ──────────────────────
     *
     * This is the payoff from stripping the prefix in middleware rather than
     * registering every route a second time under Route::prefix('ar'). A
     * prefixed route GROUP would have to be registered or not registered at
     * boot, and on this host the compiled route table is only cleared by a
     * migration — so flipping the switch in the admin would not take effect
     * until somebody shipped a package to delete a cache file. A switch whose
     * effect needs a release is not a switch.
     *
     * Here the decision is taken per request, from a setting, and the route
     * table never knew about it.
     *
     * ── OFF BY DEFAULT, INCLUDING ON THE DAY THIS SHIPS ─────────────────────
     *
     * No row is seeded. SettingsService::get() returns its default only when
     * the row is ABSENT, so "absent" has to mean off — which it does, and which
     * is why nothing here seeds a '0'. Applying this package changes nothing a
     * shopper can see, the same way `legal_notice` and `brands` shipped.
     */
    public static function enabled(string $locale): bool
    {
        if (! self::isSupported($locale)) {
            return false;
        }

        if ($locale === self::DEFAULT) {
            return true;
        }

        return self::setting(self::SETTING_ENABLED);
    }

    /**
     * Read a storefront language switch.
     *
     * Through SettingsService, so it is one cached table read per request and
     * not one per link — Url::to() is called about two hundred times on a busy
     * page. Guarded, because this is reached from the global middleware, which
     * runs on every request including the ones arriving while the settings
     * table is mid-migration; a language switch must never be able to 500 the
     * shop.
     */
    private static function setting(string $key): bool
    {
        try {
            return (bool) app(\App\Services\SettingsService::class)->get($key, false);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function isSupported(string $locale): bool
    {
        return array_key_exists($locale, self::LOCALES);
    }

    /**
     * The locale this request is being served in.
     *
     * Reads the framework's own locale rather than keeping a copy, so there is
     * one answer and App::setLocale() — which the middleware, the queue and a
     * test all use — is the only way to change it. Deliberately NOT memoised:
     * a memo here would be the Setting::map() trap again, and this one would
     * hand a queue worker the previous job's language.
     */
    public static function current(): string
    {
        $locale = (string) app()->getLocale();

        return self::isSupported($locale) ? $locale : self::DEFAULT;
    }

    public static function isDefault(?string $locale = null): bool
    {
        return ($locale ?? self::current()) === self::DEFAULT;
    }

    /**
     * 'ltr' or 'rtl' — what belongs in <html dir>.
     *
     * TWO SWITCHES, DELIBERATELY NOT ONE. A language being right-to-left is a
     * fact about the language; whether this shop's layout has been mirrored yet
     * is a fact about the shop, and the two finish at different times. Folding
     * them together would make "the mirrored layout is not ready, turn it back"
     * mean "take Arabic down", which is not what anyone would want to say.
     *
     * So "Arabic on, RTL off" is reachable, and it is Arabic words in a
     * left-to-right layout: legitimate while the stylesheet is being finished,
     * and wrong to an Arabic reader the day it is live. The admin screen says
     * that in those words beside the switch. It is not prevented, because the
     * owner asked for the control and a control that refuses the state you
     * asked for is not a control.
     */
    public static function direction(?string $locale = null): string
    {
        $locale = $locale ?? self::current();

        $natural = self::LOCALES[$locale]['dir'] ?? 'ltr';

        if ($natural !== 'rtl') {
            return 'ltr';
        }

        return self::setting(self::SETTING_RTL) ? 'rtl' : 'ltr';
    }

    /** Is the mirrored layout switched on AND is this a right-to-left language? */
    public static function isRtl(?string $locale = null): bool
    {
        return self::direction($locale) === 'rtl';
    }

    /** Is the mirrored layout switched on at all, whatever page we are on? */
    public static function rtlEnabled(): bool
    {
        return self::setting(self::SETTING_RTL);
    }

    /** What belongs in <html lang>. */
    public static function htmlLang(?string $locale = null): string
    {
        return $locale ?? self::current();
    }

    /**
     * The URL segment for a locale: '' for English, 'ar' for Arabic.
     *
     * A language that is switched off has NO segment, which is the single
     * choke point that makes the master switch work: Url::to() adds nothing,
     * so no link, no canonical and no sitemap entry can point at an address
     * that is not being served.
     */
    public static function segment(?string $locale = null): string
    {
        $locale = $locale ?? self::current();

        if (! self::enabled($locale)) {
            return '';
        }

        return (string) (self::LOCALES[$locale]['segment'] ?? '');
    }

    /** The locale a URL segment names, or null if it names no live locale. */
    public static function fromSegment(string $segment): ?string
    {
        foreach (self::LOCALES as $code => $meta) {
            if ($meta['segment'] !== '' && $meta['segment'] === $segment) {
                return self::enabled($code) ? $code : null;
            }
        }

        // /en/ only means anything once there is a second language to be the
        // default OF. With Arabic off, /en/ is just a path, and a 301 from it
        // would be the shop claiming a language structure it does not have.
        if (self::enabledCodes() === [self::DEFAULT]) {
            return null;
        }

        // /en/ is accepted so the address the owner asked for resolves; the
        // middleware answers it with a 301 to the unprefixed form rather than
        // serving a second copy of the page.
        return $segment === self::DEFAULT ? self::DEFAULT : null;
    }

    /**
     * Split a leading locale segment off a path.
     *
     * Works on the path INFO — the part after KBB_BASE_PATH — so /kbb-upgrade
     * and /ar compose in the one order that can be right: the base path is
     * where the application is mounted and the locale is a fact about the page,
     * so the deployment prefix is always outermost. /kbb-upgrade/ar/shop/, never
     * /ar/kbb-upgrade/shop/.
     *
     * @return array{0: string|null, 1: string} [locale or null, the rest of the path]
     */
    public static function splitPath(string $path): array
    {
        $path = '/' . ltrim($path, '/');

        $slash = strpos($path, '/', 1);
        $first = $slash === false ? substr($path, 1) : substr($path, 1, $slash - 1);

        if ($first === '') {
            return [null, $path];
        }

        $locale = self::fromSegment($first);

        if ($locale === null) {
            return [null, $path];
        }

        $rest = $slash === false ? '/' : substr($path, $slash);

        return [$locale, $rest === '' ? '/' : $rest];
    }

    /**
     * May this path carry a locale prefix at all?
     *
     * Takes the path WITHOUT any locale segment and without the base path.
     */
    public static function localisable(string $path): bool
    {
        $trimmed = trim($path, '/');

        if ($trimmed === '') {
            return true;
        }

        $segments = explode('/', $trimmed);
        $first = $segments[0];

        if (in_array($first, self::UNLOCALISED_ROOTS, true)) {
            return false;
        }

        // The configured admin path, whatever it has been moved to.
        $adminPath = trim(\App\Services\AdminPathService::current(), '/');
        if ($adminPath !== '' && $first === $adminPath) {
            return false;
        }

        // robots.txt, sitemap.xml, llms.txt, the IndexNow key file: machine-
        // facing documents with exactly one address each.
        $last = $segments[count($segments) - 1];
        if (str_contains($last, '.')) {
            return false;
        }

        return true;
    }

    /**
     * Put the locale segment onto a path (no base path — Url::to adds that).
     *
     * Idempotent: a path that already carries a locale segment keeps the one it
     * has rather than gaining a second. That matters because the canonical in
     * layouts/store.blade.php is built from the request path, and a link in a
     * translated string could carry either form.
     */
    public static function withSegment(string $path, ?string $locale = null): string
    {
        $locale = $locale ?? self::current();

        [$existing, $rest] = self::splitPath($path);

        if ($existing !== null) {
            $path = $rest;
        }

        if (! self::localisable($path)) {
            return $path;
        }

        $segment = self::segment($locale);

        if ($segment === '') {
            return $path;
        }

        return $path === '/' ? '/' . $segment . '/' : '/' . $segment . '/' . ltrim($path, '/');
    }

    /**
     * Every language's address for one page, for hreflang.
     *
     * Both directions, always — a page must advertise itself as well as its
     * alternates, or Google treats the pair as unrelated duplicates.
     *
     * @return array<string, string> locale => path, base path NOT applied
     */
    public static function alternatePaths(string $path): array
    {
        $live = self::enabledCodes();

        // One language is not a set of alternates. Emitting hreflang for a
        // single language tells Google nothing and is a documented way to get
        // the tag ignored on the pages where it will later matter.
        if (count($live) < 2) {
            return [];
        }

        [, $bare] = self::splitPath($path);

        if (! self::localisable($bare)) {
            return [];
        }

        $out = [];

        foreach ($live as $code) {
            $out[$code] = self::withSegment($bare, $code);
        }

        return $out;
    }
}
