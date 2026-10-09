<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\Seo\MerchantFeed;
use App\Support\SiteHost;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /feeds/meta-catalog.xml and /feeds/tiktok-catalog.xml. (Lane MP)
 *
 * The Google feed's own builder, its allowlist of attributes, its visibility
 * rule, its cache and its chunked build, with ONE difference: item ids are the
 * ids the Meta and TikTok pixels send (App\Services\Pixels\CatalogIds), so a
 * catalog ad can match a product view, an add to cart or a purchase. Both
 * Commerce Manager and TikTok Catalog Manager read RSS 2.0 with the g:
 * namespace, so one document serves both addresses; two addresses keep each
 * platform's setup step self-explanatory and let either be changed later
 * without touching the other.
 *
 * Same switch as the Google feed (Growth & Marketing → Google Shopping feed),
 * same 404 when it is off or the site is a private preview host.
 */
final class CatalogFeedController extends Controller
{
    public function __invoke(Request $request, MerchantFeed $feed): Response
    {
        if (! MerchantFeed::enabled() || SiteHost::isPrivate()) {
            return response('Feed disabled', 404)->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        $response = response($feed->cached(MerchantFeed::SCHEME_SHOP)['xml'], 200)
            ->header('Content-Type', 'application/xml; charset=UTF-8')
            ->header('X-Robots-Tag', 'noindex');

        if (! $request->hasSession()) {
            $response->headers->set('Cache-Control', SeoFilesController::PUBLIC_CACHE);
        }

        return $response;
    }
}
