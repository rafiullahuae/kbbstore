<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Brand;
use App\Models\Category;
use App\Services\SettingsService;
use App\Services\SiteLayout;

/**
 * Catalog → Pagination: whether a product listing pages at all.      (Lane PG)
 *
 * The owner: "also i allow option to turn off the pagination function
 * completely for any page, any category or brand, in case of turned off, all
 * the products will show at once. keep this option under Catelog > Pagination."
 *
 * ── ONE MECHANISM, NOT A SECOND ONE ────────────────────────────────────────
 *
 * "All the products at once" already exists on this shop: a brand page with
 * Appearance → Site layout → Brand page → "Show every product of the brand on
 * one page" asks for SiteLayout::BRAND_ALL_CAP products as its page, and an old
 * ?paged=N address 301s to page one while the brand fits. Pagination OFF here
 * is exactly that, offered to every listing: the page size becomes CAP (the
 * same constant), so the controllers' own arithmetic gives lastPage = 1, no
 * pager is drawn, no "load more" batch is ever asked for, and the canonical is
 * the plain listing URL. Past CAP the ordinary pager carries the rest, so no
 * listing can become an enormous page.
 *
 * ── WHAT "FOLLOW" MEANS, AND WHY NOTHING MOVES ON APPLY ────────────────────
 *
 * The owner did not ask to turn pagination off anywhere yet, so this ships
 * with the global switch ON and no overrides — every listing asks for exactly
 * the page size it asked for before (CLAUDE.md rule 1). "Follow" on a brand
 * keeps the brand page's own switch in charge while the global switch is on,
 * because that switch is ON already and is what a brand page does today.
 *
 * ── COST ──────────────────────────────────────────────────────────────────
 *
 * Two autoloaded settings rows, read through SettingsService's cached map: no
 * query on a listing. Stored as `pagination_on` ('1'/'0', absent = on) and
 * `pagination_overrides` (JSON: kind => id => 'on'|'off'; 'follow' is absence).
 */
final class ListingPagination
{
    public const KEY_ON = 'pagination_on';

    public const KEY_OVERRIDES = 'pagination_overrides';

    /** The safety cap "off" draws up to: the brand page's, not a second one. */
    public const CAP = SiteLayout::BRAND_ALL_CAP;

    /** The three kinds of listing an override can name. */
    public const KINDS = ['category', 'brand', 'page'];

    /** A stored override is one of these; 'follow' is the absence of one. */
    public const STATES = ['on', 'off'];

    /**
     * The listings that are neither a category nor a brand: key => [label, path].
     *
     * The keys are the ones the controllers already use — ShopController's
     * /shop/ (and search, which renders through it), CollectionController's
     * four COLLECTIONS keys and 'concern-<slug>' for each concern page.
     *
     * @return array<string, array{0:string,1:string}>
     */
    public static function pages(): array
    {
        $out = [
            'shop' => ['Shop (every product, and search results)', '/shop/'],
            'new-in' => ['New In', '/new-in/'],
            'best-sellers' => ['Best Sellers', '/best-sellers/'],
            'super-sale' => ['Super Sale', '/super-sale/'],
            'under-54' => ['Everything under AED 54', '/everything-under-54-aed/'],
        ];

        foreach (ConcernCollections::ENABLED as $slug) {
            $out['concern-'.$slug] = [
                'Concern: '.(string) __(RoutineConcerns::labelKey($slug), [], 'en'),
                '/concern/'.$slug.'/',
            ];
        }

        return $out;
    }

    /** The global switch: true unless the owner has turned it off. */
    public static function globalOn(): bool
    {
        $raw = self::settings()->get(self::KEY_ON, '1');

        return ! in_array($raw, ['0', 0, false, 'false', 'off'], true);
    }

    /**
     * Every stored override, cleaned on the way out: an unknown kind, a state
     * that is not 'on'/'off' or a key that is not a plain id is dropped, so a
     * row written behind the screen's back cannot reach a controller.
     *
     * @return array{category: array<string,string>, brand: array<string,string>, page: array<string,string>}
     */
    public static function overrides(): array
    {
        $raw = self::settings()->get(self::KEY_OVERRIDES, []);
        $raw = is_array($raw) ? $raw : [];
        $out = ['category' => [], 'brand' => [], 'page' => []];

        foreach (self::KINDS as $kind) {
            foreach ((array) ($raw[$kind] ?? []) as $id => $state) {
                $id = (string) $id;
                $ok = $kind === 'page' ? preg_match('/^[a-z0-9-]{1,64}$/', $id) === 1 : ctype_digit($id);

                if ($ok && in_array($state, self::STATES, true)) {
                    $out[$kind][$id] = $state;
                }
            }
        }

        return $out;
    }

    /** 'follow', 'on' or 'off' for one listing. */
    public static function override(string $kind, int|string $id): string
    {
        return self::overrides()[$kind][(string) $id] ?? 'follow';
    }

    /**
     * True when this listing shows every product at once (up to CAP).
     *
     * A brand that follows the global switch while it is on keeps the brand
     * page's own "Show every product of the brand on one page" in charge —
     * which is on as shipped, so a brand page shows everything today and
     * still does.
     */
    public static function showsAll(string $kind, int|string $id): bool
    {
        return match (self::override($kind, $id)) {
            'off' => true,
            'on' => false,
            default => ! self::globalOn()
                || ($kind === 'brand' && (bool) app(SiteLayout::class)->get('brand_all')),
        };
    }

    /**
     * Products per page for this listing. Pagination on: what the listing
     * asked for before, through SiteLayout::perPage() (arrows, one scroll
     * batch, or "Load all"). Pagination off: CAP.
     */
    public static function perPage(string $kind, int|string $id, int $arrows): int
    {
        return self::showsAll($kind, $id) ? self::CAP : app(SiteLayout::class)->perPage($arrows);
    }

    /**
     * The 301 an old ?paged=N / ?page=N address on a listing with pagination
     * off gets: to page one, exactly as the listing prints its own URL.
     *
     * redirect()->to() goes through UrlGenerator::to(), which strips the
     * trailing slash every storefront URL carries (U-01) -- measured on the
     * preview, the Location read /collections/toners and the shopper paid a
     * second hop. So the address is made absolute here and handed over as is.
     */
    public static function toPageOne(string $url): \Illuminate\Http\RedirectResponse
    {
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            $url = request()->getSchemeAndHttpHost().$url;
        }

        return redirect()->away($url, 301);
    }

    /**
     * Save the screen. Returns the refusals by field; nothing is written when
     * there is one, so a half-valid post cannot leave half a setting behind.
     *
     * @param  array<string, mixed>  $overrides  kind => id => 'follow'|'on'|'off'
     * @return array<string, string>  field => why
     */
    public static function save(bool $on, array $overrides): array
    {
        $errors = [];
        $clean = ['category' => [], 'brand' => [], 'page' => []];

        foreach ($overrides as $kind => $rows) {
            if (! in_array($kind, self::KINDS, true)) {
                $errors['overrides'] = 'Unknown kind of listing: '.mb_substr((string) $kind, 0, 40);

                continue;
            }

            if (! is_array($rows)) {
                $errors[$kind] = 'Expected a list.';

                continue;
            }

            foreach ($rows as $id => $state) {
                $id = (string) $id;

                if (! in_array($state, ['follow', 'on', 'off'], true)) {
                    $errors["{$kind}.{$id}"] = 'Choose Follow, On or Off.';

                    continue;
                }

                if ($kind === 'page' ? ! array_key_exists($id, self::pages()) : ! ctype_digit($id)) {
                    $errors["{$kind}.{$id}"] = 'Unknown '.$kind.'.';

                    continue;
                }

                if ($state !== 'follow') {
                    $clean[$kind][$id] = $state;
                }
            }
        }

        // The ids must name rows that exist: one query per kind, flat in the
        // number of overrides.
        foreach (['category' => Category::class, 'brand' => Brand::class] as $kind => $model) {
            $ids = array_map('intval', array_keys($clean[$kind]));

            if ($ids === []) {
                continue;
            }

            $found = $model::query()->whereIn('id', $ids)->pluck('id')->map(fn ($v) => (int) $v)->all();

            foreach (array_diff($ids, $found) as $missing) {
                $errors["{$kind}.{$missing}"] = 'No such '.$kind.'.';
            }
        }

        if ($errors !== []) {
            return $errors;
        }

        foreach ($clean as $kind => $rows) {
            ksort($rows, SORT_NATURAL);
            $clean[$kind] = $rows;
        }

        $settings = self::settings();
        $settings->set(self::KEY_ON, $on ? '1' : '0');
        $settings->set(self::KEY_OVERRIDES, $clean);

        return [];
    }

    private static function settings(): SettingsService
    {
        return app(SettingsService::class);
    }
}
