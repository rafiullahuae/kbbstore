<?php

declare(strict_types=1);

use App\Models\Page;
use App\Models\Post;
use App\Support\Seo;
use Illuminate\Support\Facades\DB;

/*
 * The Journal (/blog/) and an article (/blog/{slug}/) draw the shop's own
 * header, menu bar, phone header, phone menu and footer.            (Lane BH)
 *
 * The owner: "on blog page, and on article page, the main header is not coming
 * correct. i need the same as we have on all other pages. fix it on desktop +
 * mobile both."
 *
 * WHAT THE DEFECT LOOKED LIKE ON THE SHOP. Both pages were standalone documents
 * (their own <html>, <head> and inline stylesheet) with a hand-drawn 69px bar:
 * the wordmark, three text links -- Shop / Skin Quiz / Journal -- and a
 * magnifier and a bag that both just linked to /shop/. No search box, no
 * account, wishlist or cart count, no menu bar or mega menus, no announcement
 * bar; on a phone a burger opening a four-link drawer instead of the menu
 * sheet; and a one-line dark footer strip instead of the shop's footer.
 * Measured in Chromium (docs/lane-bh-shots): header 69px on both widths,
 * against 94px at 1280 and 127px at 390 on every other page.
 *
 * MUTATION NOTES, run in this lane's worktree:
 *   - put store/blog.blade.php back to its standalone document
 *       → the first case is red: no shared header, `<header class="head">`.
 *   - have PageController::journal() pass the rendered `seo` string again and
 *     print it in the view as well as the layout's
 *       → the SEO case is red: two <title>s and two canonicals in one head.
 *   - add @include('partials.whatsapp-button') back to store/post.blade.php
 *       → the include case is red: the article draws two WhatsApp buttons.
 *   - write `.kbb-journal .grid{` back in place of `.kbb-journal #grid.grid{`
 *       → the grid case is red (on the shop: five columns at 1280, not three).
 */

function jshPost(string $slug = 'jsh-article', array $extra = []): Post
{
    return Post::query()->create(array_merge([
        'slug' => $slug,
        'title' => 'The double cleanse, explained',
        'excerpt' => 'Oil first, then foam.',
        'body' => '<p>Oil dissolves oil.</p><h3>Why</h3><p>Sunscreen is an oil film.</p>',
        'tag' => 'Routine',
        'author' => 'K-Beauty Bliss',
        'status' => 'published',
        'published_at' => now()->subDay(),
    ], $extra));
}

/**
 * One element, outermost match, or '' -- `<header …>…</header>` and the like.
 *
 * ▲ Lane MN (2.60.441): the desktop menu now marks the page you are on with
 * aria-current on ONE link (App\Support\NavCurrent), so the bar on /blog/ is
 * meant to differ from the bar on /about/ by exactly that attribute. It is cut
 * from the menu links here and nothing else is: every other byte of the header
 * is still compared. NavCurrentTest pins the marking itself.
 */
function jshBlock(string $html, string $tag): string
{
    $block = preg_match('#<' . $tag . '\b.*?</' . $tag . '>#s', $html, $m) ? $m[0] : '';

    return (string) preg_replace('#(<a [^<>]*?) aria-current="(?:page|true)">#', '$1>', $block);
}

it('draws the same header and footer on the Journal and an article as on a content page', function () {
    jshPost();
    Page::query()->firstOrCreate(['slug' => 'about'], ['title' => 'About', 'content' => '<p>Hi.</p>', 'status' => 'published']);

    $page = (string) $this->get('/about/')->assertOk()->getContent();
    $header = jshBlock($page, 'header');
    $footer = jshBlock($page, 'footer');

    expect(strlen($header))->toBeGreaterThan(2000, 'the content page drew no shared header, so this compares nothing')
        ->and(strlen($footer))->toBeGreaterThan(1000);

    foreach (['/blog/', '/blog/jsh-article/'] as $url) {
        $html = (string) $this->get($url)->assertOk()->getContent();

        // The shared header and footer, byte for byte the content page's.
        expect(jshBlock($html, 'header'))->toBe($header, $url . ' does not draw the shop header');
        expect(jshBlock($html, 'footer'))->toBe($footer, $url . ' does not draw the shop footer');

        // Exactly once each: zero is the old page, two is a header drawn twice.
        expect(substr_count($html, '<header'))->toBe(1, $url)
            ->and(substr_count($html, '<footer'))->toBe(1, $url)
            ->and(substr_count($html, 'id="burger"'))->toBe(1, $url . ': the phone menu button')
            ->and(substr_count($html, 'id="mmenu"'))->toBe(1, $url . ': the phone menu sheet')
            ->and(substr_count($html, '<main id="content">'))->toBe(1, $url);

        // And none of the old hand-drawn chrome is left beside it.
        expect(str_contains($html, '<header class="head">'))->toBeFalse($url . ' still draws the old 69px bar')
            ->and(str_contains($html, 'class="wrap fin"'))->toBeFalse($url . ' still draws the old footer strip')
            ->and(str_contains($html, 'class="navov"'))->toBeFalse($url . ' still draws the old four-link drawer');

        // The page's own content is still there, inside its scoped wrapper.
        expect(substr_count($html, '<div class="kbb-journal">'))->toBe(1, $url);
    }

    $index = (string) $this->get('/blog/')->getContent();
    // Lane PH: the index opens on the brand page's header by default (Appearance
    // -> Site layout -> Page header (brand design)); the chips and grid are its own.
    expect($index)->toContain('id="brw-ph-title"')->toContain('id="chips"')->toContain('id="grid"')->toContain('KBB_BLOG_ALL_LABEL');

    $article = (string) $this->get('/blog/jsh-article/')->getContent();
    expect($article)->toContain('<article id="article">')->toContain('Oil dissolves oil.');
});

it('keeps every SEO tag the Journal and an article printed, once each', function () {
    jshPost();

    foreach (['/blog/', '/blog/jsh-article/'] as $url) {
        $response = $this->get($url)->assertOk();
        $html = (string) $response->getContent();
        $head = substr($html, 0, (int) strpos($html, '</head>'));

        /*
         * THE SAME BLOCK. These pages used to print Seo::render($ctx) into their
         * own head; the layout now renders it from the same context. The block
         * the old page printed is computed here from that context and must sit
         * in the head verbatim and exactly once -- once, because the layout
         * renders SEO for every page and a view printing its own as well would
         * give the page two titles, two canonicals and two JSON-LD graphs.
         */
        $ctx = $response->viewData('seoCtx');
        expect($ctx)->toBeArray();

        $block = Seo::render($ctx);
        expect(strlen($block))->toBeGreaterThan(300);
        expect(substr_count($head, $block))->toBe(1, $url . ' does not carry its own SEO block exactly once');

        expect(substr_count($head, '<title>'))->toBe(1, $url)
            ->and(substr_count($head, 'rel="canonical"'))->toBe(1, $url)
            ->and(substr_count($head, '<meta name="description"'))->toBe(1, $url)
            ->and(substr_count($head, 'property="og:title"'))->toBe(1, $url);
    }

    $index = (string) $this->get('/blog/')->getContent();
    expect($index)->toContain('"@type":"CollectionPage"')
        ->toMatch('#<link rel="canonical" href="[^"]*/blog/">#');

    $article = (string) $this->get('/blog/jsh-article/')->getContent();
    expect($article)->toContain('<meta property="og:type" content="article">')
        ->toContain('"@type":"BreadcrumbList"')
        ->toMatch('#<link rel="canonical" href="[^"]*/blog/jsh-article/">#')
        ->toContain('<title>The double cleanse, explained');
});

it('extends the layout once and re-includes nothing the layout already emits', function () {
    $layoutOwned = [
        'partials.site-app-head', 'partials.instant-nav-head', 'partials.outfit-face', 'partials.arabic-face',
        'partials.shop-appearance-css', 'partials.breadcrumb-css', 'partials.page-wash-css',
        'partials.whatsapp-button', 'partials.header', 'partials.footer', 'partials.mobile-chrome',
    ];

    foreach (['store/blog.blade.php', 'store/post.blade.php'] as $view) {
        $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents(resource_path('views/' . $view)));

        expect(substr_count($src, "@extends('layouts.store')"))->toBe(1, $view);
        expect(str_contains($src, '<html'))->toBeFalse($view . ' carries its own <html> again')
            ->and(str_contains($src, '<head>'))->toBeFalse($view . ' carries its own <head> again')
            ->and(str_contains($src, 'Analytics::class'))->toBeFalse($view . ' prints the analytics tags itself');

        foreach ($layoutOwned as $partial) {
            expect(substr_count($src, "@include('" . $partial . "'"))
                ->toBe(0, $view . ' includes ' . $partial . ', which the layout already emits -- twice on one page');
        }

        // The brand colour reaches these pages through kbb.css's :root and the
        // layout's accent block; a token declared here would take it off again.
        expect(preg_match('/--(pink|pink-deep|ink|muted):/', $src))->toBe(0, $view . ' redeclares a colour token');

        // Every rule in the page's own sheet is scoped to its content, so none
        // can reach the shared header or footer.
        preg_match('#<style id="kbb-journal-css">(.*?)</style>#s', $src, $m);
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $m[1] ?? '');
        expect($css)->not->toBe('');

        $css = (string) preg_replace('/@media[^{]*\{/', '', $css);
        preg_match_all('/([^{}]+)\{[^{}]*\}/', $css, $sel);
        foreach ($sel[1] as $selectorList) {
            foreach (explode(',', $selectorList) as $one) {
                expect(trim($one))->toStartWith('.kbb-journal', $view . ': unscoped rule `' . trim($one) . '`');
            }
        }
    }
});

it('lays the Journal out as three columns of articles, not as the product grid', function () {
    /*
     * kbb.css's product grid is `.kbb-pgrid,.rel,#grid` and the Journal's cards
     * sit in id="grid". An id outranks any pair of classes, so the first draft
     * of this change (`.kbb-journal .grid`) rendered the Journal as a product
     * listing: five columns at 1280, measured in Chromium. The rule has to
     * carry the id to win.
     */
    $src = (string) file_get_contents(resource_path('views/store/blog.blade.php'));

    expect($src)->toContain('.kbb-journal #grid.grid{display:grid;grid-template-columns:repeat(3,1fr);')
        ->toContain('@media(max-width:900px){.kbb-journal #grid.grid{grid-template-columns:1fr}');
});

it('costs the same number of queries with three articles as with forty', function () {
    /*
     * The shared header costs these pages what it costs every page (the cart
     * lookup; StorefrontQueryBudgetTest carries the numbers). What must not
     * happen is a cost per article: the grid and the related row stay one
     * query each however many posts there are.
     */
    $count = function (string $url): int {
        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });
        \App\Services\SettingsService::forgetMemo();
        app()->forgetScopedInstances();
        $this->get($url)->assertOk();

        return $n;
    };

    jshPost();
    jshPost('jsh-two', ['tag' => 'SPF']);
    jshPost('jsh-three', ['tag' => 'News']);
    $this->get('/blog/');
    $this->get('/blog/jsh-article/');

    $fewIndex = $count('/blog/');
    $fewArticle = $count('/blog/jsh-article/');

    for ($i = 0; $i < 37; $i++) {
        jshPost('jsh-more-' . $i, ['tag' => ['Routine', 'SPF', 'Ingredients'][$i % 3]]);
    }

    expect($count('/blog/'))->toBe($fewIndex)
        ->and($count('/blog/jsh-article/'))->toBe($fewArticle);
});
