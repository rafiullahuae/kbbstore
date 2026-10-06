<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\Seo\SeoSettings;
use App\Services\SpottedSettings;
use App\Support\Url;
use Illuminate\Contracts\View\View;

/**
 * GET /kbeautybliss-spotted/ — the hand-picked Instagram grid.      (Lane HB)
 *
 * Master plan row 55, item 4. The posts are the ones ticked "Spotted page" on
 * Appearance → #KBeautyBliss Spotted, in the owner's order; the wording and the
 * Google title and description are on that screen's "Spotted page" tab, each
 * falling back to a keyed English string (Arabic drafts seeded) when empty.
 *
 * SEO, per the row's own list: the title and description are passed as FINAL
 * (App\Support\Seo does not re-template them), the canonical is this page's
 * trailing-slash address, and a BreadcrumbList Home → #KBeautyBliss Spotted is
 * emitted. The page is public and indexable; it has no per-visitor content.
 */
class SpottedController extends Controller
{
    public function __invoke(SpottedSettings $spotted): View
    {
        $page = $spotted->page();

        $h1 = $page['h1'] !== '' ? $page['h1'] : __('store.spotted.page_h1');
        $intro = $page['intro'] !== '' ? $page['intro'] : __('store.spotted.page_intro');
        $seoTitle = $page['seo_title'] !== '' ? $page['seo_title'] : __('store.spotted.seo_title');
        $seoDesc = $page['seo_desc'] !== '' ? $page['seo_desc'] : __('store.spotted.seo_desc');

        // SeoSettings, not Setting::map(): the same reason CollectionController
        // gives -- the map memoises in a process-level static.
        $base = rtrim((string) SeoSettings::get('site_url', ''), '/');
        $self = Url::to(SpottedSettings::URL);
        $absolute = preg_match('#^https?://#i', $self) === 1 ? $self : $base.$self;

        // (Lane SG) Our own Instagram posts, ticked on the admin screen, when
        // the page's source is Instagram; the manual posts otherwise, and also
        // while nothing is ticked — so the page never goes blank. One cache
        // read either way on a warm page; neither path calls Instagram.
        $ig = $spotted->instagramCards($page['source']);
        $cards = $ig['cards'] === [] ? $spotted->pageCards() : [];

        return view('store.spotted', [
            'page' => $page,
            'cards' => $cards,
            'ig' => $ig,
            'h1' => $h1,
            'intro' => $intro,
            'seoTitle' => $seoTitle,
            'seoCtx' => [
                'title' => $seoTitle,
                'title_is_final' => true,
                'description' => $seoDesc,
                'url' => $absolute,
                // An empty grid is a thin page: it asks not to be indexed, and
                // SeoFilesController leaves it out of /sitemap.xml by the same
                // test (SpottedSettings::pageIsLive), so the two always agree.
                'noindex' => ($cards === [] && $ig['cards'] === []) ?: null,
                'breadcrumb' => [
                    ['name' => __('store.breadcrumb.home'), 'url' => $base.'/'],
                    ['name' => $h1, 'url' => $absolute],
                ],
            ],
        ]);
    }
}
