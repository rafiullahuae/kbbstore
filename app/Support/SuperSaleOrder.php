<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Http;

/**
 * /super-sale/ IN THE OLD SITE'S EXACT ORDER, COPIED BY THE SERVER. (2.60.388)
 *
 * The owner, 5 October: "https://extrabeauty.ae/super-sale/ i want this page
 * as we are using super sale category in it, want the same squence and sorting
 * of products as in original site https://kbeautybliss.com/super-sale/ ... i
 * want this to be done by u, bcz manual sorting will take time ... don't
 * disturb anything else."
 *
 * WHY NOT products.position. Catalog → Reorder writes one number per product,
 * and every category page sorts on it. Writing the old Super Sale order there
 * would reorder Skincare, Toners and the rest -- the "anything else" he said
 * not to disturb. So the copied order is its OWN list, one setting, read only
 * by SuperSale::apply(). Everything not in it keeps the Reorder order, after it.
 *
 * WHY THE SERVER FETCHES IT. The old page is the only record of that order
 * (WordPress kept it, not the export), and the live server can reach it. The
 * address is a constant -- nothing typed is ever fetched -- redirects are not
 * followed, each page is capped in time and size, and the pages are read only
 * for /product/<slug>/ links, which are then matched to this shop's own
 * products in one query.
 *
 * Stored as `super_sale_order`: {"ids":[...], "missing":[...], "at":"..."}.
 */
final class SuperSaleOrder
{
    public const KEY = 'super_sale_order';

    /** The old shop's Super Sale page. A constant: the only URL this fetches. */
    public const SOURCE = 'https://kbeautybliss.com/super-sale/';

    /** Ample for the campaign (48 a page on the old site), bounded either way. */
    public const MAX_PAGES = 12;

    public const MAX_IDS = 3000;

    private const MAX_BYTES = 4_000_000;

    /**
     * The copied order, as product ids. Read from the settings map already in
     * memory: no query.
     *
     * @return list<int>
     */
    public static function ids(SettingsService $settings): array
    {
        $raw = $settings->get(self::KEY);
        $data = is_array($raw) ? $raw : (is_string($raw) ? json_decode($raw, true) : null);
        $ids = is_array($data['ids'] ?? null) ? $data['ids'] : [];

        $out = [];
        foreach ($ids as $id) {
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $id = (int) $id;
                if ($id > 0 && ! isset($out[$id])) {
                    $out[$id] = $id;
                }
            }
            if (count($out) >= self::MAX_IDS) {
                break;
            }
        }

        return array_values($out);
    }

    /** What the admin tab shows about the stored copy. */
    public static function summary(SettingsService $settings): array
    {
        $raw = $settings->get(self::KEY);
        $data = is_array($raw) ? $raw : (is_string($raw) ? json_decode($raw, true) : null);
        $missing = is_array($data['missing'] ?? null) ? array_values(array_filter($data['missing'], 'is_string')) : [];

        return [
            'count' => count(self::ids($settings)),
            'at' => is_string($data['at'] ?? null) ? $data['at'] : null,
            'missing' => array_slice($missing, 0, 40),
            'missing_total' => count($missing),
            'source' => self::SOURCE,
        ];
    }

    /**
     * The product slugs a listing page links to, in page order, once each.
     * Only the product grid (<ul class="... products ...">) is read when the
     * page has one, so a product linked from the header or footer never jumps
     * the queue.
     *
     * @return list<string>
     */
    public static function slugsFromHtml(string $html): array
    {
        $scope = $html;
        if (preg_match_all('#<ul\b[^>]*\bclass\s*=\s*["\'][^"\']*\bproducts\b[^"\']*["\'][^>]*>.*?</ul>#is', $html, $m) && $m[0] !== []) {
            $scope = implode("\n", $m[0]);
        }

        preg_match_all('#href\s*=\s*["\']https?://(?:www\.)?kbeautybliss\.com/product/([^/"\'?\#\s]+)/?(?:[?\#][^"\']*)?["\']#i', $scope, $links);

        $out = [];
        foreach ($links[1] as $slug) {
            $slug = strtolower(rawurldecode($slug));
            if ($slug !== '' && strlen($slug) <= 200 && ! isset($out[$slug])) {
                $out[$slug] = $slug;
            }
        }

        return array_values($out);
    }

    /**
     * Fetch the old page (and its /page/N/ pages) and return the slugs in order.
     *
     * @return array{slugs: list<string>, pages: int, error: ?string}
     */
    public static function fetchSlugs(): array
    {
        $slugs = [];
        $pages = 0;
        $error = null;
        $started = microtime(true);

        for ($n = 1; $n <= self::MAX_PAGES; $n++) {
            if (microtime(true) - $started > 45) {
                break;
            }

            $url = $n === 1 ? self::SOURCE : self::SOURCE.'page/'.$n.'/';

            try {
                $res = Http::timeout(12)->connectTimeout(6)->withoutRedirecting()
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; KBB-order-copy/1.0)', 'Accept' => 'text/html'])
                    ->get($url);
            } catch (\Throwable) {
                $error = $n === 1 ? 'The old site did not answer.' : null;
                break;
            }

            if ($res->status() !== 200) {
                if ($n === 1) {
                    $error = 'The old site answered '.$res->status().' for '.self::SOURCE.'.';
                }
                break;
            }

            $body = $res->body();
            if (strlen($body) > self::MAX_BYTES) {
                $error = $n === 1 ? 'The old page was too large to read.' : null;
                break;
            }

            $new = 0;
            foreach (self::slugsFromHtml($body) as $slug) {
                if (! isset($slugs[$slug])) {
                    $slugs[$slug] = $slug;
                    $new++;
                }
            }
            $pages = $n;

            if ($new === 0) {
                break;
            }
        }

        return ['slugs' => array_values($slugs), 'pages' => $pages, 'error' => $error];
    }

    /**
     * Store the order for these slugs. One query matches them to products.
     *
     * @param  list<string>  $slugs
     * @return array{count:int, missing:list<string>}
     */
    public static function store(SettingsService $settings, array $slugs): array
    {
        $slugs = array_slice(array_values(array_unique(array_filter($slugs, 'is_string'))), 0, self::MAX_IDS);
        $bySlug = $slugs === [] ? [] : Product::query()->whereIn('slug', $slugs)->pluck('id', 'slug')->all();

        $ids = [];
        $missing = [];
        foreach ($slugs as $slug) {
            if (isset($bySlug[$slug])) {
                $ids[] = (int) $bySlug[$slug];
            } else {
                $missing[] = $slug;
            }
        }

        $settings->set(self::KEY, json_encode([
            'ids' => $ids,
            'missing' => $missing,
            'at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES));

        return ['count' => count($ids), 'missing' => $missing];
    }

    /** Forget the copy: /super-sale/ goes back to the Reorder order. */
    public static function clear(SettingsService $settings): void
    {
        $settings->set(self::KEY, '');
    }
}
