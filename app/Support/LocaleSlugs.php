<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Seo\SeoSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A second address per row, per language, and the switch that decides whether
 * the shop uses one.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * READ docs/SEO-ARABIC-SLUGS.md FIRST. THE RECOMMENDATION IS NOT TO USE THIS.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * This class exists because the owner asked for the choice to exist before
 * Arabic launches rather than after — the one moment in this shop's life when
 * changing an Arabic address is free, because none is indexed yet. It ships at
 * `shared`, which is today's behaviour: ONE slug per row, the language carried
 * by the /ar prefix, `/ar/product/anua-heartleaf-toner/`. Every method below
 * answers as though this table were empty while the setting says that, so
 * applying the package moves nothing.
 *
 * ── THE THREE OPTIONS, AND WHY TWO OF THEM ARE THIS ONE SWITCH ──────────────
 *
 * "Transliterate" is not a mode. `Str::slug()` is the only transliterator in
 * this dependency set, and measured on real names it produces `oaky-alshms` for
 * واقي الشمس and `mrtb-alogh-balshay-alakhdr` for a toner — Arabic script omits
 * short vowels, so the romanisation drops them and the result is unreadable to
 * an Arabic reader AND to an English one. Worse, it collides: مرطب and مُرَطِب
 * reduce to one slug. So transliteration is not offered as a policy; it is
 * available as a STRING the owner may type into the Arabic slug box if he wants
 * it, which is the same mechanism with none of the false promise.
 *
 * ── NORMALISATION IS NOT COSMETIC HERE. IT IS THE COLLATION BUG. ────────────
 *
 * `config/database.php` declares `utf8mb4_unicode_ci`, and under that collation
 * — and under MySQL 8's default `utf8mb4_0900_ai_ci` — Arabic combining marks
 * and the tatweel are IGNORED at the primary level. Measured against the real
 * server:
 *
 *     'مرطب' = 'مُرَطِب'      →  1   (unicode_ci and 0900_ai_ci)
 *     'مرطبـــ' = 'مرطب'      →  1   (tatweel ignored)
 *     the same two                →  0   (utf8mb4_general_ci, and SQLite)
 *
 * So two Arabic slugs differing only by a harakat or a kashida are the SAME
 * address on the live shop and DIFFERENT addresses in this test suite. A unique
 * index would accept both here and reject the second there, and a lookup could
 * return the other row. That is a defect no test on SQLite can see, which is the
 * worst kind.
 *
 * normalise() therefore removes them at the door: NFC, then the Arabic
 * diacritics block and the tatweel, then the shared slug shape. Two spellings
 * that MySQL would merge become one string BEFORE either the index or the
 * lookup sees them, so the two engines cannot disagree. Nothing is lost that a
 * reader sees — Arabic is written without harakat in ordinary prose, and a
 * kashida is justification, not spelling.
 */
final class LocaleSlugs
{
    /** Store → SEO & Meta → Arabic addresses. */
    public const SETTING = 'seo_arabic_slugs';

    /** One slug per row, language in the /ar prefix. Today, and the default. */
    public const SHARED = 'shared';

    /** A second, genuinely Arabic slug per row, for rows that have one. */
    public const TRANSLATED = 'translated';

    /** @var list<string> */
    public const POLICIES = [self::SHARED, self::TRANSLATED];

    /**
     * The groups this may address, and the URL each one lives at.
     *
     * NOT every table with a slug. `brands` and `posts` are absent because
     * their ROUTES cannot carry a non-ASCII slug: `/korean-skincare-brands/
     * {slug}/` is constrained to `[A-Za-z0-9\-_]+` and the site-root article
     * route to `PageController::slugPattern()`, whose character class is
     * `[a-z0-9]`. Both 404 an Arabic slug in either spelling — measured in
     * ArabicSlugPolicyTest — and widening either regex also widens what the
     * root catch-all will swallow, which is what RESERVED_SLUGS and
     * RootSlugCollisionTest rest on. Listing them here would offer the owner a
     * box whose value produces a 404, which is worse than not offering it.
     *
     * @var array<string, string> group => the path prefix its slug sits under
     */
    public const ADDRESSABLE = [
        'products' => '/product/',
        'categories' => '/product-category/',
    ];

    /** Cleared by the same clear_caches migration that ships this. */
    private const CACHE_KEY = 'kbb.locale_slugs.v1';

    private const CACHE_TTL = 300;

    /**
     * Which policy is live.
     *
     * Anything that is not a policy this class knows is `shared`. Rule 5: the
     * value decides behaviour on every storefront request, so an unknown string
     * — an older build, a hand-edited row — has to fall to the safe answer
     * rather than to the interesting one.
     */
    public static function policy(): string
    {
        $value = SeoSettings::get(self::SETTING, self::SHARED);

        return in_array($value, self::POLICIES, true) ? $value : self::SHARED;
    }

    public static function translating(): bool
    {
        return self::policy() === self::TRANSLATED;
    }

    /**
     * One address, reduced to the only form this table stores.
     *
     * The order is load-bearing. NFC first, so a letter written as a base plus a
     * combining mark becomes the single code point it has one of; THEN the marks
     * that have no precomposed form are dropped, because NFC would not have
     * touched them. Doing it the other way round leaves a decomposed alef-hamza
     * whose hamza has been deleted, which is a different letter.
     */
    public static function normalise(string $slug): string
    {
        $slug = trim($slug);

        if ($slug === '') {
            return '';
        }

        if (class_exists(\Normalizer::class)) {
            $normalised = \Normalizer::normalize($slug, \Normalizer::FORM_C);

            if (is_string($normalised)) {
                $slug = $normalised;
            }
        }

        /*
         * Arabic harakat (U+064B-U+065F), the superscript alef (U+0670) and the
         * tatweel (U+0640) — every code point utf8mb4_unicode_ci ignores. See
         * the class header for the measurement that makes this a correctness
         * fix rather than tidying.
         */
        $slug = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $slug);

        // Lowercase, because an address is one address however it is typed.
        // mb_strtolower, not strtolower: the latter is byte-wise and would leave
        // a Latin-1 accented letter alone while lowering the ASCII beside it.
        $slug = mb_strtolower($slug, 'UTF-8');

        // Spaces and underscores become the separator this shop's URLs use, and
        // runs of separators collapse — the same shape Str::slug() produces for
        // Latin, so an Arabic address and an English one read alike.
        $slug = (string) preg_replace('/[\s_]+/u', '-', $slug);
        $slug = (string) preg_replace('/-{2,}/', '-', $slug);

        return trim($slug, '-');
    }

    /**
     * Is this a slug this application would serve?
     *
     * Refuses what the ROUTER and the storefront cannot survive rather than
     * what looks untidy:
     *
     *   empty, or longer than the column;
     *   a slash, a dot, a question mark, a hash or a percent — each of which
     *   changes what the address IS rather than how it is spelled. A percent is
     *   refused because a slug containing one cannot be distinguished from an
     *   escape when it comes back off the wire;
     *   a reserved first segment, because `/ar/<slug>/` is answered by the
     *   storefront and never by a row — the same list PageController uses, and
     *   asked of it rather than copied.
     */
    public static function isServable(string $slug): bool
    {
        if ($slug === '' || strlen($slug) > 191) {
            return false;
        }

        if (preg_match('#[/?\#%.\\\\]#', $slug) === 1) {
            return false;
        }

        // Control characters, including the bidi overrides that would let one
        // address render as another.
        if (preg_match('/[\x{0000}-\x{001F}\x{007F}\x{200E}\x{200F}\x{202A}-\x{202E}]/u', $slug) === 1) {
            return false;
        }

        return ! in_array(
            $slug,
            \App\Http\Controllers\Store\PageController::RESERVED_SLUGS,
            true,
        );
    }

    /**
     * Write one row's address in one language, or clear it with a blank.
     *
     * Blank DELETES, exactly as `HasTranslations::writeTranslation()` does and
     * for the same reason: "no Arabic address" and "an Arabic address that is
     * the empty string" must not be two states, or nothing can count how much is
     * left to do.
     *
     * @return bool false when the slug is not servable or is already another row's
     */
    public static function put(string $locale, string $group, int $itemId, ?string $slug): bool
    {
        if (! array_key_exists($group, self::ADDRESSABLE) || ! Locale::isSupported($locale)) {
            return false;
        }

        $slug = self::normalise((string) $slug);

        if ($slug === '') {
            DB::table('locale_slugs')
                ->where('locale', $locale)->where('group', $group)->where('item_id', $itemId)
                ->delete();

            self::flush();

            return true;
        }

        if (! self::isServable($slug)) {
            return false;
        }

        // Taken by another row in this language. Checked before the write rather
        // than caught afterwards, so the caller gets an answer it can show
        // instead of an exception it has to translate.
        $owner = self::itemFor($locale, $group, $slug);

        if ($owner !== null && $owner !== $itemId) {
            return false;
        }

        DB::table('locale_slugs')->updateOrInsert(
            ['locale' => $locale, 'group' => $group, 'item_id' => $itemId],
            ['slug' => $slug, 'updated_at' => now(), 'created_at' => now()],
        );

        self::flush();

        return true;
    }

    /** This row's address in this language, or null. */
    public static function slugFor(string $locale, string $group, int $itemId): ?string
    {
        return self::map()[$locale][$group]['byItem'][$itemId] ?? null;
    }

    /** Which row owns this address in this language, or null. */
    public static function itemFor(string $locale, string $group, string $slug): ?int
    {
        $slug = self::normalise($slug);

        return self::map()[$locale][$group]['bySlug'][$slug] ?? null;
    }

    /**
     * The whole table, one cached array.
     *
     * The same argument `TranslationStore` makes for the translation map and
     * `SettingsService` for the settings table: this is consulted on every
     * storefront request in the translated policy, it is configuration-sized
     * (at most one row per addressable row — 730 on this catalogue), and a query
     * per request would land on StorefrontQueryBudgetTest.
     *
     * Guarded and schema-checked, because this runs from middleware: a request
     * arriving while the migration set is mid-flight must not 500 the shop.
     *
     * @return array<string, array<string, array{byItem: array<int,string>, bySlug: array<string,int>}>>
     */
    public static function map(): array
    {
        try {
            $map = \Illuminate\Support\Facades\Cache::remember(
                self::CACHE_KEY,
                self::CACHE_TTL,
                static function (): array {
                    if (! Schema::hasTable('locale_slugs')) {
                        return [];
                    }

                    $out = [];

                    foreach (DB::table('locale_slugs')->get(['locale', 'group', 'item_id', 'slug']) as $row) {
                        $locale = (string) $row->locale;
                        $group = (string) $row->group;
                        $out[$locale][$group]['byItem'][(int) $row->item_id] = (string) $row->slug;
                        $out[$locale][$group]['bySlug'][(string) $row->slug] = (int) $row->item_id;
                    }

                    return $out;
                },
            );

            return is_array($map) ? $map : [];
        } catch (\Throwable) {
            return [];
        }
    }

    public static function flush(): void
    {
        try {
            \Illuminate\Support\Facades\Cache::forget(self::CACHE_KEY);
        } catch (\Throwable) {
            // A migration can write this table before the cache store has one.
        }
    }

    /**
     * Turn an address as a READER sees it into the address the ROUTER serves.
     *
     * `/product/مرطب-الوجه/` → `/product/anua-heartleaf-toner/`, and everything
     * else back unchanged. This is what `ResolveLocaleSlugs` calls before the
     * router runs, and it is the whole reason not one route, controller or query
     * has to learn that a second address exists — the same trick, and the same
     * argument, as `SetLocaleFromPath` stripping /ar.
     *
     * Returns null when nothing about the path is translated, so the caller can
     * tell "no change" from "changed to the same thing".
     */
    public static function toCanonicalPath(string $path, string $locale): ?string
    {
        if (! self::translating() || $locale === Locale::DEFAULT) {
            return null;
        }

        foreach (self::ADDRESSABLE as $group => $prefix) {
            if (! str_starts_with($path, $prefix)) {
                continue;
            }

            $rest = substr($path, strlen($prefix));
            $trailing = str_ends_with($rest, '/');
            $bare = trim($rest, '/');

            if ($bare === '') {
                return null;
            }

            /*
             * A CATEGORY IS A PATH AND A PRODUCT IS A SEGMENT, and the leaf is
             * the only part either has a slug for. `/product-category/skincare/
             * toners/` is nested, and only `toners` is a row — the ancestry is
             * rebuilt by CategoryPath from the row it lands on, which is why the
             * leaf alone is translated and the prefix is dropped. That is not a
             * shortcut: CategoryPath::resolve() reads the LEAF and 301s any
             * ancestry that is not the real one, so handing it the canonical
             * leaf gets the canonical Arabic path for free.
             */
            $segments = explode('/', $bare);
            $leaf = array_pop($segments);

            $itemId = self::itemFor($locale, $group, $leaf);

            if ($itemId === null) {
                return null;
            }

            $canonicalLeaf = self::canonicalSlugFor($group, $itemId);

            if ($canonicalLeaf === null || $canonicalLeaf === $leaf) {
                return null;
            }

            $segments[] = $canonicalLeaf;

            return $prefix . implode('/', $segments) . ($trailing ? '/' : '');
        }

        return null;
    }

    /**
     * The reverse: the address a reader should SEE for a canonical path.
     *
     * `/product/anua-heartleaf-toner/` → `/product/مرطب-الوجه/` in Arabic. This
     * is what `Seo` asks when it builds a canonical or an hreflang, so the
     * document advertises the address the shopper is on rather than the one the
     * router matched.
     */
    public static function toDisplayPath(string $path, string $locale): ?string
    {
        if (! self::translating() || $locale === Locale::DEFAULT) {
            return null;
        }

        foreach (self::ADDRESSABLE as $group => $prefix) {
            if (! str_starts_with($path, $prefix)) {
                continue;
            }

            $rest = substr($path, strlen($prefix));
            $trailing = str_ends_with($rest, '/');
            $bare = trim($rest, '/');

            if ($bare === '') {
                return null;
            }

            $segments = explode('/', $bare);
            $leaf = array_pop($segments);

            $itemId = self::canonicalItemFor($group, $leaf);

            if ($itemId === null) {
                return null;
            }

            $translated = self::slugFor($locale, $group, $itemId);

            if ($translated === null || $translated === $leaf) {
                return null;
            }

            $segments[] = $translated;

            return $prefix . implode('/', $segments) . ($trailing ? '/' : '');
        }

        return null;
    }

    /** The English slug of one row, read from the row's own table. */
    public static function canonicalSlugFor(string $group, int $itemId): ?string
    {
        if (! array_key_exists($group, self::ADDRESSABLE)) {
            return null;
        }

        $slug = DB::table($group)->where('id', $itemId)->value('slug');

        return is_string($slug) && $slug !== '' ? $slug : null;
    }

    /** Which row carries this English slug, or null. */
    public static function canonicalItemFor(string $group, string $slug): ?int
    {
        if (! array_key_exists($group, self::ADDRESSABLE)) {
            return null;
        }

        $id = DB::table($group)->where('slug', $slug)->value('id');

        return $id === null ? null : (int) $id;
    }
}
