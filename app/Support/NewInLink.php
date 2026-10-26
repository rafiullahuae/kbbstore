<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Middleware\CheckRedirects;
use App\Models\Category;
use App\Services\NewArrivals;
use Illuminate\Http\Request;

/**
 * /new-in/ -> the New Arrivals collection. (Lane NA)
 *
 * The owner: "the /new-in link should also opens the new arrivals collection".
 *
 * A 301 to the category's own address rather than a second copy of the page:
 * one canonical URL for one list, so search engines, the sitemap and
 * "Most viewed" all count one page. /new-in/ stays the link in the header,
 * footer and emails (those are the owner's own links, left as they are); it is
 * one same-origin hop, prefetched like any other shop link.
 *
 * Arabic: SetLocaleFromPath strips /ar before routing and Url::redirect() puts
 * it back, so /ar/new-in/ lands on /ar/collections/new-arrivals/.
 *
 * WHEN IT DOES NOT REDIRECT, and /new-in/ is the old listing exactly as before:
 *   - no category keeps itself (Catalog -> New Arrivals switched off, or no
 *     such category);
 *   - a row on Store -> SEO & Meta -> Redirects & 404s sends the category's
 *     address back to /new-in/. Following both would be a loop, and the row
 *     is the owner's visible decision, so it wins. (A row FOR /new-in/ needs
 *     no handling here: CheckRedirects runs before routing and answers first.)
 */
final class NewInLink
{
    public const PATH = '/new-in/';

    public static function target(Request $request): ?string
    {
        $ids = array_keys(NewArrivals::activeRules());

        if ($ids === []) {
            return null;
        }

        $category = Category::query()->find(min($ids));

        if ($category === null) {
            return null;
        }

        $path = UrlScheme::collection((string) ($category->path ?: $category->buildPath()));

        if (self::loops($path)) {
            return null;
        }

        $query = (string) $request->getQueryString();

        return Url::redirect($path, $request).($query !== '' ? '?'.$query : '');
    }

    private static function loops(string $path): bool
    {
        try {
            $row = CheckRedirects::lookup($path);
        } catch (\Throwable) {
            return false;
        }

        if ($row === null) {
            return false;
        }

        $to = '/'.trim((string) parse_url((string) $row->target, PHP_URL_PATH), '/').'/';

        return $to === self::PATH;
    }
}
