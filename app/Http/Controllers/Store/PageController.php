<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Services\SettingsService;

/**
 * Editable content pages: privacy policy, terms, delivery and so on.
 *
 * Content lives in the pages table rather than in templates, so it can be
 * changed in the admin without a release.
 */
class PageController extends Controller
{
    public function __construct(private SettingsService $settings) {}

    public function show(string $slug)
    {
        $page = Page::query()
            ->where('slug', $slug)
            ->where('status', 'published')
            ->firstOrFail();

        return view('store.page', [
            'page' => $page,
            'settings' => $this->settings,
        ]);
    }

    /**
     * The blog post page — genuinely missing until now, not just misnamed.
     * The route has always called PageController::post(), which never
     * existed (only show(), which handles the unrelated Page model), so
     * every single post URL has been a 500 the entire time this route
     * existed. The view itself (store.post) turned out to be a leftover,
     * unfinished, client-side-only mockup: it read the slug from a query
     * string instead of the URL path, fetched an /api/posts endpoint that
     * was never built, and silently fell back to one hardcoded demo post
     * for every request that didn't match — meaning even a from-scratch
     * fix of just this method would still have shown the same wrong post
     * for every real slug. Rebuilt to genuinely render the real post,
     * matching the same server-rendered pattern already established and
     * working for products and categories, not another version of the
     * same client-side approach that got the page into this state.
     */
    /**
     * The blog listing — same missing-method 500 as post() below, and the
     * same root cause: the view fetched a nonexistent /api/posts endpoint
     * and silently fell back to three hardcoded demo posts regardless of
     * what was actually in the database. Rebuilt server-rendered, same as
     * the fix below.
     */
    public function blog()
    {
        $posts = \App\Models\Post::query()
            ->where('status', 'published')
            ->latest('published_at')
            ->get(['slug', 'title', 'excerpt', 'tag', 'cover', 'published_at']);

        $base = rtrim((string) (\App\Models\Setting::map()['site_url'] ?? ''), '/');
        $seo = \App\Support\Seo::render([
            'type' => 'website',
            'title' => 'The Glow Journal',
            'description' => 'Skincare tips and the K-beauty edit — honest guides on routines, ingredients and sun care, written for the UAE.',
            'url' => $base . '/blog',
            'breadcrumb' => [
                ['name' => 'Home', 'url' => $base . '/'],
                ['name' => 'Journal', 'url' => $base . '/blog'],
            ],
        ]);

        return view('store.blog', [
            'posts' => $posts,
            'seo' => $seo,
            'settings' => $this->settings,
        ]);
    }

    public function post(string $slug = '')
    {
        $post = \App\Models\Post::query()
            ->where('slug', $slug)
            ->where('status', 'published')
            ->firstOrFail();

        $related = \App\Models\Post::query()
            ->where('status', 'published')
            ->where('id', '!=', $post->id)
            ->latest('published_at')
            ->limit(3)
            ->get(['slug', 'title', 'tag', 'cover']);

        $base = rtrim((string) (\App\Models\Setting::map()['site_url'] ?? ''), '/');
        $seoOverride = is_array($post->seo) ? $post->seo : [];

        $seo = \App\Support\Seo::render([
            'type' => 'article',
            'title' => $seoOverride['title'] ?? $post->title,
            'title_is_final' => !empty($seoOverride['title']),
            'description' => $seoOverride['desc'] ?? $post->excerpt ?? '',
            'image' => $seoOverride['og_image'] ?? $post->cover,
            'url' => !empty($seoOverride['canonical']) ? $seoOverride['canonical'] : ($base . '/skincare-guide/' . $post->slug . '/'),
            'noindex' => !empty($seoOverride['noindex']),
            'article' => [
                'title' => $post->title,
                'published_at' => optional($post->published_at)->toAtomString(),
            ],
            'breadcrumb' => [
                ['name' => 'Home', 'url' => $base . '/'],
                ['name' => 'Journal', 'url' => $base . '/blog'],
                ['name' => $post->title, 'url' => $base . '/skincare-guide/' . $post->slug . '/'],
            ],
        ]);

        return view('store.post', [
            'post' => $post,
            'related' => $related,
            'seo' => $seo,
            'settings' => $this->settings,
        ]);
    }
}
