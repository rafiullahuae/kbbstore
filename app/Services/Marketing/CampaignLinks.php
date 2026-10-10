<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Support\SiteUrl;
use Illuminate\Support\Str;

/**
 * "Came to the website": the UTM tags on a campaign's links.        (Lane ER)
 *
 * Every tracked link that lands on THIS shop leaves the click redirect
 * (/email/c/{token}/{n}) with
 *
 *     utm_source=email & utm_medium=marketing & utm_campaign=mkt-<id>-<slug>
 *
 * added — each one only when the link does not already carry it, so a link the
 * owner tagged by hand keeps his tag and nothing is ever doubled. A link to
 * another site (Instagram, WhatsApp) is left exactly as written.
 *
 * Tagged at the REDIRECT, not in the message: the email's links are the click
 * tracker's own addresses, the destination is read from mkt_links, and the
 * renderer is untouched (Lane EC owns it). It also means a campaign sent
 * before this existed is tagged from its next click on.
 *
 * The site's analytics (hit.js → Tracker) records utm_campaign on the session's
 * first page and Rollup keeps it per day in an_dims, so the report reads the
 * campaign's visits back by the `mkt-<id>` prefix — which survives a rename,
 * because the id comes first.
 */
final class CampaignLinks
{
    public const SOURCE = 'email';

    public const MEDIUM = 'marketing';

    public static function utmCampaign(int $id, string $name): string
    {
        $slug = trim(substr(Str::slug($name), 0, 40), '-');

        return 'mkt-' . $id . ($slug !== '' ? '-' . $slug : '');
    }

    /** The campaign id an utm_campaign value names, or null. */
    public static function campaignId(string $utm): ?int
    {
        return preg_match('/^mkt-(\d{1,10})(?:-|$)/', strtolower($utm), $m) === 1 ? (int) $m[1] : null;
    }

    /** $url with the three tags it does not already have, when it lands on this shop. */
    public static function tag(string $url, int $id, string $name): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['host']) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || ! self::isShop((string) $parts['host'])) {
            return $url;
        }

        $have = [];

        foreach (explode('&', (string) ($parts['query'] ?? '')) as $pair) {
            $have[strtolower(rawurldecode((string) strtok($pair, '=')))] = true;
        }

        $add = [];

        foreach (['utm_source' => self::SOURCE, 'utm_medium' => self::MEDIUM, 'utm_campaign' => self::utmCampaign($id, $name)] as $k => $v) {
            if (! isset($have[$k])) {
                $add[] = $k . '=' . rawurlencode($v);
            }
        }

        if ($add === []) {
            return $url;
        }

        [$head, $hash] = array_pad(explode('#', $url, 2), 2, null);
        $query = implode('&', $add);
        $head = str_contains($head, '?') ? rtrim($head, '&') . (str_ends_with($head, '?') ? '' : '&') . $query : $head . '?' . $query;

        return $head . ($hash !== null ? '#' . $hash : '');
    }

    private static function isShop(string $host): bool
    {
        $bare = static fn (string $h): string => preg_replace('/^www\./', '', strtolower($h)) ?? '';
        $own = (string) parse_url(SiteUrl::externalOrigin(), PHP_URL_HOST);

        return $own !== '' && $bare($host) === $bare($own);
    }
}
