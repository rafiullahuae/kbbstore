<?php

declare(strict_types=1);

namespace App\Services\Seo\Keywords;

use App\Support\Locale;
use App\Support\Url;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What a storefront page publishes, read the cheapest way there is.
 *
 * INERT UNTIL THE FIRST SYNC. `seo_kw_live` rides Setting::map(), which every
 * page has already loaded; while it is empty this class returns before it
 * touches the cache or the database, so a shop that never opens SEO → Keywords
 * renders exactly as it did — same bytes, same query count.
 *
 * After a sync, one page's payload is ONE cache read (file cache on the live
 * host: no query at all), and on a cold cache one keyed select by the unique
 * index, after which it is cached for 30 days. The stamp is in the key, so a
 * new sync or an undo invalidates every page at once without a flush.
 *
 * The payload is published in exactly three places, none of them visible text:
 * schema.org `keywords` in the page's JSON-LD, <meta name="keywords"> (switch,
 * ≤10), and — only if the owner switches it on — the Popular searches block of
 * real internal links on category and brand pages.
 */
final class PageKeywords
{
    public const TTL_DAYS = 30;

    private const ENTITY = '/^(product|category|brand|collection|page|post):[a-z0-9-]{1,64}$/';

    /** @return array{k: list<string>, p: list<array{0: string, 1: string}>} */
    public static function for(?string $entity, array $settings, ?string $locale = null): array
    {
        $none = ['k' => [], 'p' => []];
        $live = (string) ($settings[KeywordConfig::LIVE] ?? '');

        if ($live === '' || $entity === null || ! preg_match(self::ENTITY, $entity)) {
            return $none;
        }

        $locale ??= Locale::current();
        [$type, $id] = explode(':', $entity, 2);

        $payload = Cache::remember(
            'seo_kw:'.$live.':'.$entity.':'.$locale,
            now()->addDays(self::TTL_DAYS),
            static function () use ($type, $id, $locale): array {
                $row = DB::table('seo_page_keywords')
                    ->where('entity_type', $type)->where('entity_id', $id)->where('locale', $locale)
                    ->first(['keywords', 'links']);

                return [
                    'k' => self::list($row->keywords ?? null),
                    'p' => self::links($row->links ?? null),
                ];
            }
        );

        return is_array($payload) ? $payload + $none : $none;
    }

    /** The entity a page's SEO context names, or the home page's. */
    public static function entityOf(array $ctx): ?string
    {
        if (is_string($ctx['seo_entity'] ?? null)) {
            return $ctx['seo_entity'];
        }

        return ($ctx['type'] ?? '') === 'home' ? 'page:home' : null;
    }

    /**
     * Popular searches for a category or brand page: [label, href] pairs, or []
     * when the switch is off. Hrefs are built here, through Url::to(), from
     * root-relative paths the sync stored; nothing else can become a link.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function popular(?string $entity): array
    {
        $s = \App\Services\Seo\SeoSettings::map();

        if (! KeywordConfig::popularOn($s)) {
            return [];
        }

        $out = [];
        foreach (self::for($entity, $s)['p'] as [$label, $path]) {
            $out[] = [$label, Url::to($path)];
        }

        return $out;
    }

    /** @return list<string> */
    private static function list(mixed $json): array
    {
        $raw = is_string($json) ? json_decode($json, true) : null;
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $k) {
            $k = KeywordText::clean($k);
            if ($k !== null && ! in_array($k, $out, true)) {
                $out[] = $k;
            }
        }

        return array_slice($out, 0, KeywordComposer::MAX);
    }

    /** @return list<array{0: string, 1: string}> */
    private static function links(mixed $json): array
    {
        $raw = is_string($json) ? json_decode($json, true) : null;
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $l) {
            $label = KeywordText::clean($l[0] ?? null);
            $path = is_string($l[1] ?? null) ? $l[1] : '';
            // Root-relative, one leading slash, no scheme, no //host — an
            // internal page of this shop and nothing else.
            if ($label !== null && preg_match('#^/(?!/)[\p{L}\p{N}\-._~/%]*$#u', $path)) {
                $out[] = [$label, $path];
            }
        }

        return array_slice($out, 0, 8);
    }
}
