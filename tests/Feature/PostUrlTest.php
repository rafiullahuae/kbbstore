<?php

declare(strict_types=1);

use App\Models\Post;
use App\Models\Redirect;
use Tests\Support\Phase9Routes;

/** One of the five slugs the owner confirmed live at the site root. */
const LIVE_SLUG = 'heartleaf-extract-transforming-k-beauty-skincare';

beforeEach(function () {
    Phase9Routes::wire($this->app);

    $this->post = Post::create([
        'slug' => LIVE_SLUG,
        'title' => 'Heartleaf extract: transforming K-beauty skincare',
        'excerpt' => 'Why houttuynia cordata turns up in every calming serum.',
        'body' => '<p>Heartleaf is the quiet workhorse of a calming routine.</p>',
        'tag' => 'Ingredients',
        'status' => 'published',
        'published_at' => now()->subDay(),
    ]);
});

it('resolves a blog post under /blog/', function () {
    /*
     * THE ADDRESS MOVED. This read "resolves a blog post at its root-level
     * slug" and fetched /{slug}/, which is where Phase 9 put articles and where
     * the WordPress site served them. The address scheme moved them to
     * /blog/{slug}/ — a detail page under its listing's prefix — because
     * PageController::RESERVED_SLUGS owns the first segment at the root, so a
     * live article slugged `about`, `feed` or `brands` was an address this
     * application could never serve. The root form still 301s here, which the
     * test below pins.
     */
    /*
     * ▲ THE HEADLINE NEEDLE NAMES THE <h1>, AND IT HAS TO. (Lane PLC)
     *
     * This read `assertSee('Heartleaf extract: transforming K-beauty skincare')`
     * and could not see the headline at all: the article page carries its title
     * FOUR more times over — in <title>, in the og:title and description metas,
     * and in the JSON-LD Article `headline` — so the assertion was answered by
     * the head of the document whether or not the article rendered a heading.
     *
     * MUTATION, run: blank the <h1> in resources/views/store/post.blade.php, so
     * every article on the shop renders with no headline, and this file was
     * 16 passed / 0 failed. With the needle below it is red.
     */
    $this->get('/blog/' . LIVE_SLUG . '/')
        ->assertOk()
        ->assertSee('<h1>Heartleaf extract: transforming K-beauty skincare</h1>', escape: false)
        ->assertSee('Heartleaf is the quiet workhorse of a calming routine.', escape: false);
});

it('resolves the same post without a trailing slash', function () {
    $this->get('/blog/' . LIVE_SLUG)->assertOk();
});

it('canonicalises the article to the root URL, not the old prefix', function () {
    // Canonicals are absolute since 2.60.109 (Seo::canonical passes them through
    // Url::to), so this asserts the path host-agnostically rather than pinning
    // the origin. What matters is that the article no longer claims the old
    // /blog/ prefix.
    $html = $this->get('/blog/' . LIVE_SLUG . '/')->assertOk()->getContent();

    expect($html)->toMatch('#rel="canonical" href="(https?://[^"/]+)?/blog/' . preg_quote(LIVE_SLUG, '#') . '/?"#')
        ->and($html)->not->toContain('"/' . LIVE_SLUG . '/"');
});

it('keeps the Journal index where the nav already points', function () {
    // Only the article URL moved. Four places publish /blog/ for the
    // index — the homepage, MenuDemo, MegaMenuApiController and the admin
    // Pages screen — and none of them were part of the owner's answer.
    $this->get('/blog/')
        ->assertOk()
        ->assertSee('Heartleaf extract: transforming K-beauty skincare');
});

it('links each index card at the article address', function () {
    $this->get('/blog/')
        ->assertOk()
        ->assertSee('href="/blog/' . LIVE_SLUG . '/"', escape: false)
        ->assertDontSee('href="/' . LIVE_SLUG . '/"', escape: false);
});

it('301s the article\'s WordPress root address to /blog/', function () {
    /*
     * The address Google holds for every article the old site published.
     * PageController::rootArticle() answers it, in ONE hop, and 404s a slug
     * that names no published post rather than forwarding blindly.
     *
     * MUTATION NOTE. Remove the abort_unless from rootArticle() and the last
     * test in this file ("404s an unknown root slug") goes red instead.
     */
    $response = $this->get('/' . LIVE_SLUG . '/');

    $response->assertStatus(301);
    expect($response->headers->get('Location'))->toEndWith('/blog/' . LIVE_SLUG . '/');
});

it('301s the retired /skincare-guide/{slug}/ article URL straight to /blog/', function () {
    /*
     * ONE HOP, not two. This used to point at the site root, which is itself a
     * 301 now — so left alone it would have cost every indexed
     * /skincare-guide/ URL two hops.
     */
    $response = $this->get('/skincare-guide/' . LIVE_SLUG . '/');

    $response->assertStatus(301);
    expect($response->headers->get('Location'))->toEndWith('/blog/' . LIVE_SLUG . '/');
});

it('301s a post written after the seeding migration ran', function () {
    // The route, not a seeded row, is what has to catch this one.
    Post::create([
        'slug' => 't-written-after-the-migration',
        'title' => 'Written later',
        'status' => 'published',
        'published_at' => now(),
    ]);

    expect(Redirect::where('source', '/t-written-after-the-migration/')->exists())
        ->toBeFalse();

    $response = $this->get('/t-written-after-the-migration/');

    $response->assertStatus(301);
    expect($response->headers->get('Location'))->toEndWith('/blog/t-written-after-the-migration/');
});

it('leaves no seeded row shadowing the article\'s own address', function () {
    /*
     * ── THE TWO TESTS THIS REPLACES, AND WHY THEY COULD NOT BE ADVANCED ────
     *
     * They read "301s the seeded WordPress /blog/{slug} URL to the root slug"
     * and "seeds the /blog/ redirect map by migration, in both slash forms".
     * Both described 2026_09_14_160000_seed_phase9_post_url_redirects, which
     * wrote ten rows shaped /blog/{slug}/ -> /{slug}/ back when /blog/ was a
     * dead prefix.
     *
     * /blog/{slug}/ is the article's CANONICAL address now, and CheckRedirects
     * runs BEFORE the router, so those rows would have sent the canonical
     * address to the site root and rootArticle() would have sent it straight
     * back — an infinite loop that CheckRedirects::loops() cannot see, because
     * it walks the TABLE and the second hop is a route. There is no version of
     * the old assertions that is both true and safe.
     *
     * 2027_04_05_000100_url_scheme_redirect_rows removes them, matched on
     * shape rather than on the posts table so a fresh install (where the seed
     * runs before any article exists) is covered too.
     *
     * MUTATION NOTE. Delete deleteSeededArticleLoops() from that migration and
     * this is red on the first expectation, and the article's own address
     * starts answering 301.
     */
    foreach ([
        '/blog/' . LIVE_SLUG . '/',
        '/blog/' . LIVE_SLUG,
        '/blog/k-beauty-bliss-a-beginners-guide-to-korean-skincare/',
    ] as $source) {
        expect(Redirect::where('source', $source)->exists())
            ->toBeFalse($source . ' still carries a row, which would loop against rootArticle()');
    }

    $this->get('/blog/' . LIVE_SLUG . '/')->assertOk();
});

it('follows every old article URL through to a 200', function () {
    foreach ([
        '/' . LIVE_SLUG . '/',
        '/skincare-guide/' . LIVE_SLUG . '/',
        '/post/' . LIVE_SLUG,
    ] as $old) {
        /*
         * The same anchor as the case above, for the same reason: a redirect
         * that landed on a page carrying only the article's <head> would have
         * passed on the bare string.
         */
        $this->followingRedirects()
            ->get($old)
            ->assertOk()
            ->assertSee('<h1>Heartleaf extract: transforming K-beauty skincare</h1>', escape: false);
    }
});

it('hides an unpublished post from the index and from its own URL', function () {
    Post::create([
        'slug' => 't-not-ready-yet',
        'title' => 'T Not ready yet',
        'status' => 'draft',
    ]);

    $this->get('/blog/')->assertOk()->assertDontSee('T Not ready yet');
    $this->get('/blog/t-not-ready-yet/')->assertNotFound();
    // And no old URL may 301 to a 404 either.
    $this->get('/t-not-ready-yet/')->assertNotFound();
    $this->get('/skincare-guide/t-not-ready-yet/')->assertNotFound();
});

it('404s an unknown root slug that has no redirect', function () {
    $this->get('/t-never-was-a-page/')->assertNotFound();
});

it('sends the retired /post/{slug} prefix straight to the article', function () {
    // ONE HOP. legacyPost() answers the final address rather than the site
    // root, which is itself a redirect now.
    $response = $this->get('/post/' . LIVE_SLUG);

    $response->assertStatus(301);
    expect(rtrim((string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH), '/'))
        ->toBe('/blog/' . LIVE_SLUG);
});

it('sends bare /post and /skincare-guide to the Journal index', function () {
    /*
     * ▲ THREE NEEDLES THAT NAMED THE INDEX'S EYEBROW, NOT ITS <title>.
     *                                                          (Lane PLC)
     *
     * 'The Glow Journal' is `store.journal.eyebrow`, drawn in `.ey` above the
     * index's heading — and it is ALSO the <title>, the og:title and the
     * JSON-LD name of that page. So all three assertions were satisfied by the
     * document head, and "did the redirect land on the Journal index" was
     * really "did it land on something whose head says Journal".
     *
     * MUTATION, run: blank `<div class="ey">` in store/blog.blade.php, so the
     * index draws no eyebrow, and this file was 16 passed / 0 failed. With the
     * needles below it is red.
     */
    $eyebrow = '<div class="ey">The Glow Journal</div>';

    $this->followingRedirects()->get('/post')->assertOk()->assertSee($eyebrow, escape: false);
    $this->followingRedirects()->get('/skincare-guide')->assertOk()->assertSee($eyebrow, escape: false);
    // And /blog IS the index rather than a hop onto one.
    $this->get('/blog')->assertOk()->assertSee($eyebrow, escape: false);
});

/*
 * The sitemap is the one place a stale article URL does lasting damage: search
 * engines are handed the redirecting form directly, which is what the move was
 * meant to stop. Lane B moved the article route but these four callers kept
 * building the old shape.
 */
it('lists articles in the sitemap at /blog/, not at any retired address', function () {
    $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

    expect(str_contains($xml, '/blog/' . LIVE_SLUG . '/'))
        ->toBeTrue('the sitemap does not list the article at its own address');
    expect(str_contains($xml, '<loc>http://localhost/' . LIVE_SLUG . '/</loc>'))
        ->toBeFalse('the sitemap still submits the article at the site root, which now 301s');
    expect(str_contains($xml, '/skincare-guide/'))
        ->toBeFalse('the sitemap still submits the retired journal index');
});

it('lists the Journal index at its new address', function () {
    expect(str_contains($this->get('/sitemap.xml')->getContent(), '<loc>http://localhost/blog/</loc>'))
        ->toBeTrue('the sitemap does not list the Journal index');
});
