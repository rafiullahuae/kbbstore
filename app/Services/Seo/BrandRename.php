<?php

declare(strict_types=1);

namespace App\Services\Seo;

use App\Models\Setting;
use App\Services\SettingsService;
use App\Services\Translation\TranslationStore;
use App\Support\BrandName;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * "Extra Beauty" → "K-Beauty Bliss" in the shop's STORED brand text. Lane BR.
 *
 * The code has said K-Beauty Bliss for months; what the live shop shows comes
 * from the settings table and the owner's own content. This finds the old name
 * there and replaces it — the migration runs it once, Store → SEO Keywords →
 * Brand runs it again on demand, with a dry-run count first.
 *
 * ── WHERE IT LOOKS, AND THE LIST IS THE SAFETY ──────────────────────────────
 * TARGETS is an allowlist of brand-bearing columns: settings and module
 * settings, the interface-string overrides and the translations of pages,
 * articles, menus and video sections, the email and marketing templates (and
 * only UNSENT campaigns), content pages and articles, menus, banners and grid
 * headings, reusable blocks, tags, the SEO boxes of products / categories /
 * brands, and the SEO keyword sets.
 *
 * Lane EB widened it, 6 October, because the owner still saw the name on the
 * live shop: "remove the Extra Beauty word from whole site from everywhere".
 * Now also the catalogue (product names, descriptions, ingredients, how-to,
 * tabs, image alt text, variant labels, attributes), category and brand
 * names, descriptions, headers and banners, the media library's alt text,
 * REVIEWS (customers' own words and the shop's replies — he said everywhere),
 * routines, Spotted and Instagram captions, video clips, the checkout's
 * shipping and payment titles, tax and zone names, coupon descriptions and
 * unsent push notifications.
 *
 * NOTHING ELSE IS READ. Orders, order items, customers, payments, refunds,
 * import history, mail logs, sent campaigns and sent pushes, search history and
 * the keyword bank are RECORDS of what happened and are not on the list, so
 * there is no code path that could touch them — BrandNameTest and
 * BrandRenameEverywhereTest seed "Extra Beauty" into each and prove it.
 * payment_providers.config is encrypted and is never read either.
 *
 * ── WHAT IT LEAVES, EVEN INSIDE A TARGET ───────────────────────────────────
 * BrandName::OLD only matches the old name used AS A NAME: extrabeauty.ae,
 * emails, handles and legal entities ("Extra Beauty Trading LLC") are left,
 * and settings whose key is a credential, host, address or URL are skipped
 * outright. The check screen lists what was left and why.
 *
 * ── NEVER THROWS ────────────────────────────────────────────────────────────
 * Each target is its own try/catch, a missing table or column is skipped, and
 * a write that fails is counted as an error and logged. A brand rename that
 * aborted a Core Update would be the 2.60.x updater incident again.
 */
final class BrandRename
{
    /**
     * table => [primary key, columns, extra where (column => list of allowed values), lowercase keyword table?, JSON columns walked value by value]
     *
     * @var array<string, array{0: string, 1: list<string>, 2?: array<string, list<string>>, 3?: bool, 4?: list<string>}>
     */
    public const TARGETS = [
        'settings' => ['key', ['value']],
        'module_settings' => ['id', ['value']],
        'translations' => ['id', ['value'], ['group' => ['ui', 'pages', 'posts', 'menu_items', 'menus', 'ugc_sections',
            // Lane EB: the Arabic (and any other locale) of the catalogue and the clips.
            'products', 'categories', 'brands', 'product_tabs', 'ugc_videos']]],
        'email_templates' => ['id', ['subject', 'preheader', 'heading', 'body', 'blocks']],
        'mkt_templates' => ['id', ['name', 'subject', 'preheader', 'blocks']],
        'mkt_campaigns' => ['id', ['name', 'subject', 'preheader', 'from_name', 'blocks'], ['status' => ['draft', 'scheduled', 'paused']]],
        'mkt_segments' => ['id', ['name']],
        'pages' => ['id', ['title', 'content', 'doc_json', 'seo']],
        'posts' => ['id', ['title', 'excerpt', 'body', 'author', 'seo']],
        'menus' => ['id', ['name']],
        'menu_items' => ['id', ['label', 'badge']],
        'banner_sets' => ['id', ['name']],
        'banner_cards' => ['id', ['alt', 'heading', 'body', 'button_label']],
        'grid_sections' => ['id', ['name', 'heading', 'subheading', 'card_label', 'view_all_label']],
        'ugc_sections' => ['id', ['title', 'heading', 'subheading']],
        'blocks' => ['id', ['name', 'content']],
        'tags' => ['id', ['name']],
        // ── Lane EB, 6 October: "remove the Extra Beauty word from whole site
        //    from everywhere". The catalogue, its pictures' alt text, the
        //    customers' reviews and the checkout's own labels. JSON columns
        //    that carry picture or link addresses are walked value by value
        //    (index 4), so a key or a URL is never rewritten.
        'products' => ['id', ['name', 'short_description', 'description', 'ingredients', 'how_to_use', 'custom_tabs', 'image_alts', 'seo', 'seo_json'], [], false, ['custom_tabs', 'image_alts']],
        'product_variants' => ['id', ['tag']],
        'product_tabs' => ['id', ['title', 'body']],
        'attributes' => ['id', ['name']],
        'attribute_values' => ['id', ['name']],
        'categories' => ['id', ['name', 'description', 'header_title', 'header_subtitle', 'header_description', 'banner', 'seo'], [], false, ['banner']],
        'brands' => ['id', ['name', 'description', 'header_title', 'header_subtitle', 'header_description', 'banner', 'header_layout', 'seo'], [], false, ['banner', 'header_layout']],
        'media' => ['id', ['alt']],
        'reviews' => ['id', ['author_name', 'title', 'content', 'reply']],
        'routines' => ['id', ['title', 'blurb', 'steps'], [], false, ['steps']],
        'spotted_posts' => ['id', ['image_alt', 'caption']],
        'instagram_posts' => ['id', ['caption']],
        'ugc_videos' => ['id', ['title', 'caption']],
        'shipping_methods' => ['id', ['title', 'settings'], [], false, ['settings']],
        'shipping_zones' => ['id', ['name']],
        'payment_providers' => ['id', ['title']],
        'tax_rates' => ['id', ['name']],
        'coupons' => ['id', ['description']],
        'push_campaigns' => ['id', ['title', 'body', 'link_label'], ['status' => ['draft', 'scheduled']]],
        'seo_page_keywords' => ['id', ['keywords', 'layers', 'primary_kw', 'suggest'], [], true],
    ];

    /**
     * What the check screen calls each target. A table not named here is
     * shown by its table name.
     */
    public const AREAS = [
        'settings' => 'Settings, footer, header and homepage text',
        'module_settings' => 'Module options',
        'translations' => 'Translations (Arabic and other languages)',
        'email_templates' => 'Email templates',
        'mkt_templates' => 'Marketing templates',
        'mkt_campaigns' => 'Marketing campaigns not yet sent',
        'mkt_segments' => 'Marketing segment names',
        'pages' => 'Pages (FAQ, legal, about…)',
        'posts' => 'Journal posts',
        'menus' => 'Menus',
        'menu_items' => 'Menu labels and badges',
        'banner_sets' => 'Banner set names',
        'banner_cards' => 'Banners',
        'grid_sections' => 'Product grid headings',
        'ugc_sections' => 'Shoppable video sections',
        'blocks' => 'Reusable blocks',
        'tags' => 'Tags',
        'products' => 'Products — name, descriptions, tabs, image alt, SEO',
        'product_variants' => 'Variant labels',
        'product_tabs' => 'Product tabs',
        'attributes' => 'Attributes',
        'attribute_values' => 'Attribute values (variation names)',
        'categories' => 'Categories — name, description, header, banner, SEO',
        'brands' => 'Brands — name, description, header, banner, SEO',
        'media' => 'Media library alt text',
        'reviews' => 'Reviews (customers’ own words, and your replies)',
        'routines' => 'Routines',
        'spotted_posts' => 'Spotted posts',
        'instagram_posts' => 'Instagram captions',
        'ugc_videos' => 'Shoppable video clips',
        'shipping_methods' => 'Shipping methods (checkout)',
        'shipping_zones' => 'Shipping zones',
        'payment_providers' => 'Payment method titles (checkout)',
        'tax_rates' => 'Tax rate names',
        'coupons' => 'Coupon descriptions',
        'push_campaigns' => 'Push notifications not yet sent',
        'seo_page_keywords' => 'SEO keyword sets',
    ];

    /*
     * Inside a JSON column walked value by value: a value under one of these
     * keys, or a value that is itself an address, is never rewritten. A raw
     * space in an upload path ("/uploads/Extra Beauty Box.jpg") would
     * otherwise match the name.
     */
    private const URL_KEY = '/(url|src|href|link|image|img|path|file|logo|icon|video|poster|slug|handle|email)$/i';

    private const URL_VALUE = '~^\s*(?:(?:https?:)?//|/)\S*\s*$|^\s*(?:(?:https?:)?//|/)[^<>"\n]*\.(?:jpe?g|png|webp|gif|svg|avif|mp4|webm|pdf)\s*$~i';

    /*
     * Settings that are never brand text even when they mention the old name:
     * secrets, hosts, URLs, addresses and identifiers. A host or an email here
     * is the DOMAIN, which the cutover moves, not this.
     */
    private const SKIP_KEY = '/(pass|secret|token|api_?key|private|_url$|^url|url_|host|domain|email|mail_from_address|_address$|username|verification|webhook|_dsn|_id$|^seo_kw_live$|^admin_path$)/i';

    /** @internal tests only: a target list to walk instead of TARGETS. */
    public static ?array $targets = null;

    /** At most this many rows are read per column — a ceiling, not an expectation. */
    private const CAP = 5000;

    /**
     * Count, never write.
     *
     * @return array{rows: list<array{table: string, column: string, id: string, label: string, count: int, before: string, after: string}>, left: list<array{table: string, column: string, id: string, label: string, sample: string, why: string}>, total: int, errors: list<string>}
     */
    public static function scan(): array
    {
        return self::walk(false);
    }

    /** Replace, then flush the caches the changed rows feed. Same shape as scan(). */
    public static function apply(): array
    {
        $out = self::walk(true);

        if ($out['total'] > 0) {
            self::flush();
        }

        return $out;
    }

    private static function walk(bool $write): array
    {
        $rows = [];
        $left = [];
        $errors = [];
        $total = 0;
        $touched = [];
        $areas = [];

        foreach (self::$targets ?? self::TARGETS as $table => $spec) {
            [$pk, $columns] = $spec;
            $where = $spec[2] ?? [];
            $lower = $spec[3] ?? false;
            $json = $spec[4] ?? [];

            $areas[$table] ??= ['table' => $table, 'label' => self::AREAS[$table] ?? $table, 'found' => 0, 'left' => 0, 'checked' => false];

            try {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                $areas[$table]['checked'] = true;
                $have = Schema::getColumnListing($table);
                $columns = array_values(array_intersect($columns, $have));
                if ($columns === [] || ! in_array($pk, $have, true)) {
                    continue;
                }

                foreach ($columns as $col) {
                    $q = DB::table($table)->select([$pk, $col]);
                    if ($table === 'settings' || $table === 'module_settings') {
                        $q->addSelect('key');
                    }
                    foreach ($where as $wc => $allowed) {
                        if (in_array($wc, $have, true)) {
                            $q->whereIn($wc, $allowed);
                        }
                    }
                    /*
                     * A coarse, index-free prefilter; the regex is the real test.
                     * LOWER(CAST(… AS CHAR)) because MySQL compares a JSON column
                     * (products.seo, pages.seo …) with a BINARY collation, so a
                     * plain LIKE '%extra%' misses "Extra Beauty" there — measured
                     * on the MySQL suite. SQLite reads CHAR as TEXT.
                     */
                    $text = 'LOWER(CAST('.DB::getQueryGrammar()->wrap($col).' AS CHAR))';
                    $q->where(static function ($w) use ($text): void {
                        $w->whereRaw($text.' LIKE ?', ['%extra%beauty%'])
                            ->orWhereRaw($text.' LIKE ?', ['%كسترا%بيوت%'])
                            ->orWhereRaw($text.' LIKE ?', ['%u0643%u0633%u062a%u0631%u0627%']);
                    });

                    foreach ($q->limit(self::CAP)->get() as $r) {
                        $value = $r->{$col};
                        if (! is_string($value) || $value === '') {
                            continue;
                        }
                        $id = (string) $r->{$pk};
                        $label = isset($r->key) ? (string) $r->key : $table.'#'.$id;

                        if (isset($r->key) && preg_match(self::SKIP_KEY, (string) $r->key)) {
                            // No sample: the key may hold a credential, and this list reaches the screen.
                            if (BrandName::anyCount($value) > 0) {
                                $areas[$table]['left'] += BrandName::anyCount($value);
                                $left[] = self::leftRow($table, $col, $id, $label, '', 'an address, URL or credential — the domain moves at the cutover, not here');
                            }
                            continue;
                        }

                        [$new, $n] = in_array($col, $json, true)
                            ? self::replaceJson($value, $lower)
                            : BrandName::replace($value, $lower);
                        $remaining = BrandName::anyCount($new);

                        if ($n > 0) {
                            if ($write) {
                                // Only if nobody changed it since it was read. Compared as
                                // text, so a MySQL JSON column matches the text it gave us.
                                $changed = DB::table($table)->where($pk, $r->{$pk})
                                    ->whereRaw('CAST('.DB::getQueryGrammar()->wrap($col).' AS CHAR) = ?', [$value])
                                    ->update([$col => $new]);
                                if ($changed < 1) {
                                    continue; // somebody edited it in between; theirs wins
                                }
                                self::restamp($table, $id, $col, $value, $new);
                                $touched[$table][$id] = true;
                            }
                            $total += $n;
                            $areas[$table]['found'] += $n;
                            $rows[] = [
                                'table' => $table, 'column' => $col, 'id' => $id, 'label' => $label, 'count' => $n,
                                'before' => self::excerpt($value), 'after' => self::excerpt($new),
                            ];
                        }

                        if ($remaining > 0) {
                            $areas[$table]['left'] += $remaining;
                            $left[] = self::leftRow($table, $col, $id, $label, $new, self::why($new));
                        }
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = $table.': '.$e->getMessage();
                Log::warning('BrandRename: '.$table.' skipped', ['error' => $e->getMessage()]);
            }
        }

        if ($write && $touched !== []) {
            self::forgetRows($touched);
        }

        if ($write) {
            $per = [];
            foreach ($rows as $r) {
                $k = $r['table'] === 'settings' ? 'settings.'.$r['label'] : $r['table'].'.'.$r['column'];
                $per[$k] = ($per[$k] ?? 0) + $r['count'];
            }
            Log::info('BrandRename: Extra Beauty → K-Beauty Bliss', ['total' => $total, 'per' => $per, 'errors' => $errors]);
        }

        return ['rows' => $rows, 'left' => array_slice($left, 0, 200), 'total' => $total, 'errors' => $errors, 'areas' => array_values($areas)];
    }

    /**
     * BrandName::replace() on the STRING VALUES of a JSON document only — never
     * a key, never a value that is an address or sits under an address-shaped
     * key. image_alts is a map KEYED BY IMAGE URL (see WebpReferences): a
     * rewritten key would orphan every custom alt text on the product.
     * Not JSON after all: falls back to the plain replacement.
     *
     * @return array{0: string, 1: int}
     */
    private static function replaceJson(string $raw, bool $lower): array
    {
        $data = json_decode($raw, false);
        if (json_last_error() !== JSON_ERROR_NONE || ! (is_array($data) || is_object($data))) {
            return BrandName::replace($raw, $lower);
        }

        $n = 0;
        $walk = static function (mixed &$node, int|string|null $key) use (&$walk, &$n, $lower): void {
            if (is_array($node) || is_object($node)) {
                foreach ($node as $k => &$child) {
                    $walk($child, $k);
                }
                unset($child);

                return;
            }
            if (! is_string($node) || $node === ''
                || (is_string($key) && preg_match(self::URL_KEY, $key))
                || preg_match(self::URL_VALUE, $node)) {
                return;
            }
            [$node, $c] = BrandName::replace($node, $lower);
            $n += $c;
        };
        $walk($data, null);

        if ($n === 0) {
            return [$raw, 0];
        }
        $out = json_encode($data, JSON_PRESERVE_ZERO_FRACTION);

        return $out === false ? [$raw, 0] : [$out, $n];
    }

    /**
     * A translated English column that the rename changed keeps its
     * translations CURRENT: their source_hash was taken from the old English,
     * and left alone every Arabic product name would read "stale" on the
     * translation screens for a change that was only ever the shop's name
     * (whose Arabic spelling the translations target renames too). Only a
     * translation that was current against the old text is re-stamped.
     */
    private static function restamp(string $table, string $id, string $col, string $old, string $new): void
    {
        static $translated = null;

        try {
            if ($translated === null) {
                $translated = [];
                foreach (\App\Services\Translation\TranslationEstimate::CONTENT as $class => $cols) {
                    $translated[(new $class())->getTable()] = $cols;
                }
            }
            if (! in_array($col, $translated[$table] ?? [], true)) {
                return;
            }
            DB::table('translations')->where('group', $table)->where('item_id', (int) $id)->where('field', $col)
                ->where('source_hash', sha1($old))->update(['source_hash' => sha1($new)]);
        } catch (\Throwable) {
            // A stale flag is a nuisance on an admin screen; never a failed rename.
        }
    }

    /**
     * The per-row caches a model's saved hook would have cleared — the rename
     * writes through the query builder, so no hook fires.
     *
     * @param  array<string, array<string, true>>  $touched
     */
    private static function forgetRows(array $touched): void
    {
        foreach (array_keys($touched['products'] ?? []) as $id) {
            try {
                \App\Services\ProductRecs::forget((int) $id);
                \App\Services\BuyTogether::forget((int) $id);
            } catch (\Throwable) {
            }
        }
    }

    private static function leftRow(string $table, string $col, string $id, string $label, string $value, string $why): array
    {
        return ['table' => $table, 'column' => $col, 'id' => $id, 'label' => $label, 'sample' => $value === '' ? '' : self::excerpt($value), 'why' => $why];
    }

    private static function why(string $value): string
    {
        if (preg_match('/extra[\s\-]?beauty\.[a-z]|[@\/]extra[\s\-_]?beauty/i', $value)) {
            return 'part of a web address, email or handle';
        }
        if (preg_match('/#extra/i', $value)) {
            return 'a hashtag';
        }
        if (preg_match('/extra[\s\-]?beauty\s+(general\s+)?(trading|llc|l\.l\.c|fze|fzco|fz|est|establishment|company|co\b|ltd|limited|inc|group)/i', $value)) {
            return 'a legal company name — change it only if your trade licence says K-Beauty Bliss';
        }

        return 'lower case inside a sentence — reads like ordinary words, so it is yours to judge';
    }

    /** ~90 characters around the first occurrence, as plain text. */
    private static function excerpt(string $value): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags(str_replace(['\\n', '\\r', '\\t'], ' ', $value))));
        $at = 0;
        if (preg_match(BrandName::ANY, $text, $m, PREG_OFFSET_CAPTURE) || preg_match('/K-Beauty Bliss/i', $text, $m, PREG_OFFSET_CAPTURE)) {
            $at = max(0, mb_strlen(substr($text, 0, (int) $m[0][1])) - 40);
        }
        $cut = mb_substr($text, $at, 90);

        return ($at > 0 ? '…' : '').$cut.(mb_strlen($text) > $at + 90 ? '…' : '');
    }

    private static function flush(): void
    {
        foreach ([
            static fn () => Setting::flushMap(),
            static fn () => SettingsService::forgetMemo(),
            static fn () => TranslationStore::flush(),
            static fn () => app(\App\Services\NavigationService::class)->flush(),
            static fn () => Cache::forget('kbb.settings'),
            // Lane EB: the content caches that hold product, category, brand
            // and review TEXT. Named keys only, never Cache::flush() — the
            // store holds sessions on some drivers (see the 2027_04_21
            // clear_caches migration).
            static function (): void {
                foreach (['kbb.settings.map', 'kbb.home.rails', 'kbb.home.routine', 'kbb.home.reviews', 'kbb.home.cats', 'kbb.home.brands',
                    'kbb.home.posts', 'kbb.shop.cats', 'kbb.shop.brands', 'kbb.search.starter.products', 'kbb.search.starter.brands',
                    'kbb.search.brandnames', \App\Support\SearchSpelling::CACHE_KEY, 'kbb.admin.cats', 'kbb.admin.brands', \App\Support\AdminSearchIndex::CACHE_KEY] as $key) {
                    Cache::forget($key);
                }
            },
            static fn () => \App\Support\Shortcodes::flush(),
            static fn () => \App\Services\GridSections::flush(),
            static fn () => \App\Support\ProductTabs::flush(),
            static fn () => \App\Services\UgcRail::flush(),
            static fn () => \App\Services\InstagramFeed::flush(),
            static fn () => \App\Services\BuyTogetherPairs::forget(),
            static fn () => \App\Services\ShippingService::flushZones(),
            static fn () => app(\App\Services\PageHeaders::class)->forget(),
            static fn () => app(\App\Services\CategoryHeaders::class)->forget(),
            static fn () => app(\App\Services\PageBanners::class)->forget(),
            // A new stamp re-keys every cached page keyword set at once.
            static fn () => Setting::query()->where('key', \App\Services\Seo\Keywords\KeywordConfig::LIVE)->exists()
                ? \App\Services\Seo\Keywords\KeywordConfig::stampLive() : null,
        ] as $step) {
            try {
                $step();
            } catch (\Throwable) {
                // A cache that cannot be cleared expires on its own TTL.
            }
        }
    }
}
