<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;

/**
 * The product page's recommendation cards, rendered once and reused. (Lane RP)
 *
 * The three blocks at the foot of the product page draw ~43 <x-product-card>s
 * where the page used to draw 12. Their QUERIES did not move (ProductRecs: two
 * statements), but rendering a card costs ~0.25 ms of PHP with OPcache on, so
 * the page lost ~8 ms warm — against the owner's "no compromise on the speed".
 *
 * So the HTML of each card is cached and reused, byte for byte.
 *
 * ── TWO KINDS OF ENTRY, BOTH BOUNDED ───────────────────────────────────────
 *
 *   `kbb.cards.{locale}.{page}`  one product page's shared cards together,
 *                                deflated (~10 KB): a repeat view is ONE read.
 *                                At most BUNDLE_MAX cards, one per page and
 *                                language. The shopper's own recently viewed
 *                                cards are never in it.
 *   `kbb.card.{locale}.{id}`     one card → [signature, html], ~2 KB, shared by
 *                                every page that draws it: what a page's entry
 *                                falls back to (one Cache::many()) and what a
 *                                new page is built from.
 *
 * At most (products × languages) of each, however often anything changes: a
 * changed card OVERWRITES its entry rather than adding one beside it. Misses
 * are rendered with the shop's own component and written with one putMany().
 *
 * ── THE SIGNATURE IS EVERYTHING THE CARD READS ─────────────────────────────
 *
 * An entry is used only when its signature matches the one computed NOW from
 *
 *   the row      every column the card query loaded, and the brand's — so a
 *                price, stock, image, name, rating or set-rule edit is a new
 *                signature on the very next view, with no hook to forget;
 *   the clock    what the card derives from the time: the effective price and
 *                "on sale" (sale windows), "new" (30 days), the label
 *                ProductLabels chooses, the variant price range;
 *   the words    the product's and the brand's name in this language;
 *   the shop     ONE hash per request of the settings snapshot, the module
 *                switches and this language's interface strings — every
 *                Appearance control and every translated button the card can
 *                print. Read from the snapshots the page already holds: no
 *                settings read is added, and no query.
 *
 * Code changes need nothing: applying an update runs `cache:clear`.
 *
 * NOTHING PER-VISITOR IS IN A CARD — the wishlist heart is inert markup that
 * wishlist.js fills in the browser, and there is no session, cookie, cart or
 * customer read in components/product-card.blade.php (RecsBlocksTest pins
 * that), so one cached card is right for every visitor.
 */
final class CardFragments
{
    public const PREFIX = 'kbb.card.';

    /** One product page's cards together: one read for a repeat view. */
    public const PAGE_PREFIX = 'kbb.cards.';

    /** The most cards one page's entry keeps (~43 are drawn). */
    public const BUNDLE_MAX = 80;

    /** A day; a changed card replaces itself long before. */
    public const TTL = 86400;

    /**
     * Every card these products need, by product id: from ONE cache read, the
     * misses rendered and written back with one write. Nothing is memoised
     * across calls — the caller holds the map for the page it is drawing, so a
     * long-lived process (a test, a queue worker) can never serve a card from
     * before a write. See CLAUDE.md on Setting::map().
     *
     * @param  iterable<Product>  $products
     * @return array<int, string>
     */
    public static function many(iterable $products, ?int $page = null): array
    {
        $want = [];

        foreach ($products as $p) {
            $id = (int) $p->id;

            if ($id > 0 && ! isset($want[$id])) {
                $want[$id] = $p;
            }
        }

        if ($want === []) {
            return [];
        }

        $locale = Locale::current();
        $settings = app(\App\Services\SettingsService::class);
        $ctx = [
            self::shop($locale),
            now()->subDays(30)->getTimestamp(),
            $settings->moduleEnabled('product_labels', false) ? app(\App\Services\ProductLabels::class) : null,
            app(\App\Services\VariantPricing::class),
        ];
        $keys = [];
        $sigs = [];

        foreach ($want as $id => $p) {
            $keys[$id] = self::PREFIX.$locale.'.'.$id;
            $sigs[$id] = self::signature($p, $ctx);
        }

        $out = [];

        /*
         * ONE READ FOR THE WHOLE PAGE. A product page's cards are kept together
         * as well (`kbb.cards.{locale}.{page}`, deflated: ~10 KB for 43 cards),
         * so a repeat view reads one entry instead of forty-three. Each card in
         * it is still checked against its own signature; only the ones that
         * fail fall through to the shared per-card entries below.
         */
        $bundleKey = $page !== null ? self::PAGE_PREFIX.$locale.'.'.$page : null;
        $bundle = $bundleKey !== null ? self::unpack(self::get($bundleKey)) : [];

        foreach ($want as $id => $p) {
            $hit = $bundle[$id] ?? null;

            if (is_array($hit) && ($hit[0] ?? null) === $sigs[$id] && is_string($hit[1] ?? null)) {
                $out[$id] = $hit[1];
            }
        }

        $missing = array_diff_key($want, $out);

        if ($missing !== []) {
            try {
                $hits = Cache::many(array_values(array_intersect_key($keys, $missing)));
            } catch (\Throwable) {
                $hits = [];
            }

            $put = [];

            foreach ($missing as $id => $p) {
                $hit = $hits[$keys[$id]] ?? null;

                if (is_array($hit) && ($hit[0] ?? null) === $sigs[$id] && is_string($hit[1] ?? null)) {
                    $out[$id] = $hit[1];

                    continue;
                }

                $out[$id] = self::render($p);
                $put[$keys[$id]] = [$sigs[$id], $out[$id]];
            }

            if ($bundleKey !== null) {
                // Merged with what was there, so two visitors whose block 3
                // falls back to a different tenth best seller converge on one
                // entry instead of rewriting it in turn. Bounded: past
                // BUNDLE_MAX cards it starts again from this page's own.
                $fresh = count($bundle) + count($want) <= self::BUNDLE_MAX ? $bundle : [];

                foreach ($want as $id => $p) {
                    $fresh[$id] = [$sigs[$id], $out[$id]];
                }

                $put[$bundleKey] = gzdeflate(serialize($fresh), 1);
            }

            try {
                Cache::putMany($put, self::TTL);
            } catch (\Throwable) {
                // A cache that will not write costs a re-render next time, never a page.
            }
        }

        // The page's own order, as asked.
        return array_replace(array_intersect_key($want, $out), $out);
    }

    private static function get(string $key): mixed
    {
        try {
            return Cache::get($key);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<int, array{0: string, 1: string}> */
    private static function unpack(mixed $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $plain = @gzinflate($raw);
        $data = is_string($plain) ? @unserialize($plain, ['allowed_classes' => false]) : null;

        return is_array($data) ? $data : [];
    }

    /** The shop's own component, exactly as a grid draws it. */
    public static function render(Product $p): string
    {
        return Blade::render('<x-product-card :product="$product" />', ['product' => $p]);
    }

    /**
     * The per-request half of the signature: settings snapshot, module
     * switches, this language's interface strings. Hashed once per request.
     */
    private static function shop(string $locale): string
    {
        $settings = app(\App\Services\SettingsService::class);
        $modules = [];

        foreach (['wishlist', 'quick_view', 'product_labels'] as $m) {
            $modules[$m] = $settings->moduleEnabled($m, false);
        }

        $ui = Locale::isDefault($locale) ? [] : \App\Services\Translation\TranslationStore::uiMap($locale);

        return hash('xxh128', serialize([$locale, $settings->all(), $modules, $ui]));
    }

    /**
     * Everything that can make one card's HTML differ, and nothing it costs a
     * lot to ask. The row and the brand row carry price, sale window, stock,
     * image, rating, type and set rule; effectivePrice() is the one figure the
     * clock and a set's members move without touching the row; a variable
     * product's range comes from its variants; "new" and the owner's labels
     * are dates and settings. Services and the clock are taken once per call.
     *
     * @param  array{0: string, 1: int, 2: ?\App\Services\ProductLabels, 3: \App\Services\VariantPricing}  $ctx
     */
    private static function signature(Product $p, array $ctx): string
    {
        [$shop, $newSince, $labels, $variants] = $ctx;
        $brand = $p->relationLoaded('brand') ? $p->brand : null;

        // The union's source tag is not a column the card reads; leaving it in
        // made the same product sign differently cold and warm.
        $row = $p->getAttributes();
        unset($row['rp_src'], $row['ymal_src']);

        $created = $p->getAttributes()['created_at'] ?? null;

        return hash('xxh128', serialize([
            $shop,
            $row,
            $brand?->getAttributes(),
            Locale::isDefault() ? null : [$p->t('name'), $brand?->t('name')],
            $p->effectivePrice(),
            $created === null ? null : strtotime((string) $created) > $newSince,
            $labels?->for($p),
            ($row['type'] ?? null) === 'variable' ? $variants->range($p) : null,
        ]));
    }
}
