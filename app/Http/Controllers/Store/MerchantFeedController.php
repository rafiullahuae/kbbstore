<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\Seo\MerchantFeed;
use App\Support\SiteHost;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /feeds/google-merchant.xml -- the product feed Merchant Center (and Meta
 * Commerce Manager) fetch on a schedule. (Lane SEO)
 *
 * Machine-facing and identical for everybody, so it is mounted in the same
 * session-less group as /sitemap.xml and carries the same public cache header
 * when -- and only when -- no session is attached. 404 while the switch at
 * Growth & Marketing -> Google Shopping feed is off, and on a private install
 * (a staging copy must not hand its catalogue to Google under its own address).
 */
final class MerchantFeedController extends Controller
{
    public function __invoke(Request $request, MerchantFeed $feed): Response
    {
        if (! MerchantFeed::enabled() || SiteHost::isPrivate()) {
            return response('Feed disabled', 404)->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        $response = response($feed->cached()['xml'], 200)
            ->header('Content-Type', 'application/xml; charset=UTF-8')
            ->header('X-Robots-Tag', 'noindex');

        if (! $request->hasSession()) {
            $response->headers->set('Cache-Control', SeoFilesController::PUBLIC_CACHE);
        }

        return $response;
    }
}
