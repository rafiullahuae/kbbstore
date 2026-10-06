<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Support\Url;
use Illuminate\Support\Facades\DB;

/**
 * Where a push may send a shopper (Lane PN): a page on THIS shop, nothing
 * else. Checked on save, again when the payload is built, and a third time
 * by the worker (sw.js shopUrl()), so a stored value that somehow went bad
 * still opens the home page rather than another site.
 *
 * Accepted: a path ("/product/cosrx-snail-mucin/", "/sale/?ref=push") or a
 * full address on the shop's own host, which is reduced to its path. Refused:
 * another host, a protocol-relative "//", a backslash (browsers read "/\evil"
 * as "//evil"), any scheme but http(s) on the shop's host (javascript:,
 * data:), whitespace or control characters, the back office and the API.
 */
final class PushLinks
{
    public const MAX = 300;

    private const BLOCKED_ROOTS = ['/api', '/admin-api', '/admin', '/owner-app', '/payments'];

    /** The stored form of a link (a path, no base prefix), or null when it is not a page on this shop. */
    public static function path(mixed $link): ?string
    {
        if (! is_string($link)) {
            return null;
        }
        $link = trim($link, ' ');
        if ($link === '' || strlen($link) > self::MAX || preg_match('/[\x00-\x20\x7F\\\\]/', $link) === 1) {
            return null;
        }

        if (preg_match('#\A[a-z][a-z0-9+.-]*:#i', $link) === 1) {
            $p = parse_url($link);
            $host = strtolower((string) ($p['host'] ?? ''));
            if (! is_array($p) || ! in_array(strtolower((string) ($p['scheme'] ?? '')), ['http', 'https'], true)
                || $host === '' || ! in_array($host, self::shopHosts(), true) || isset($p['user']) || isset($p['pass'])) {
                return null;
            }
            $link = ($p['path'] ?? '/').(isset($p['query']) ? '?'.$p['query'] : '').(isset($p['fragment']) ? '#'.$p['fragment'] : '');
        }

        if (! str_starts_with($link, '/') || str_starts_with($link, '//')) {
            return null;
        }

        $base = Url::base();
        if ($base !== '' && ($link === $base || str_starts_with($link, $base.'/'))) {
            $link = substr($link, strlen($base)) ?: '/';
        }

        $bare = strtolower((string) strtok($link, '?#'));
        foreach (self::BLOCKED_ROOTS as $root) {
            if ($bare === $root || str_starts_with($bare, $root.'/')) {
                return null;
            }
        }

        return $link;
    }

    /** @return list<string> the shop's own host names */
    private static function shopHosts(): array
    {
        $hosts = [];
        foreach ([config('app.url'), app()->bound('request') ? request()->getSchemeAndHttpHost() : null] as $u) {
            $h = strtolower((string) parse_url((string) $u, PHP_URL_HOST));
            if ($h !== '') {
                $hosts[] = $h;
                $hosts[] = str_starts_with($h, 'www.') ? substr($h, 4) : 'www.'.$h;
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * The link picker: products, categories and brands whose name contains
     * $q, eight of each at most, as {type, name, path}. Three statements
     * whatever the catalogue's size.
     *
     * @return list<array{type:string, name:string, path:string}>
     */
    public static function search(string $q): array
    {
        $q = trim((string) preg_replace('/[\x00-\x1F\x7F%_\\\\]+/u', ' ', $q));
        if (mb_strlen($q) < 2) {
            return [];
        }
        $like = '%'.mb_substr($q, 0, 60).'%';
        $out = [];

        try {
            $products = DB::table('products')->where('name', 'like', $like)->where('status', 'publish')
                ->orderByDesc('total_sales')->orderBy('id')->limit(8)->get(['name', 'slug']);
            foreach ($products as $p) {
                $out[] = ['type' => 'Product', 'name' => (string) $p->name, 'path' => '/product/'.$p->slug.'/'];
            }
        } catch (\Throwable) {
        }
        foreach ([['categories', 'Category', \App\Models\Category::class], ['brands', 'Brand', \App\Models\Brand::class]] as [$table, $type, $model]) {
            try {
                foreach ($model::query()->where('name', 'like', $like)->limit(8)->get() as $m) {
                    $path = self::path(parse_url($m->url(), PHP_URL_PATH) ?: null);
                    if ($path !== null) {
                        $out[] = ['type' => $type, 'name' => (string) $m->name, 'path' => $path];
                    }
                }
            } catch (\Throwable) {
            }
        }

        return $out;
    }
}
