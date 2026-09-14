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

it('resolves a blog post at its root-level slug', function () {
    $this->get('/' . LIVE_SLUG . '/')
        ->assertOk()
        ->assertSee('Heartleaf extract: transforming K-beauty skincare')
        ->assertSee('Heartleaf is the quiet workhorse of a calming routine.', escape: false);
});

it('resolves the same post without a trailing slash', function () {
    $this->get('/' . LIVE_SLUG)->assertOk();
});

it('canonicalises the article to the root URL, not the old prefix', function () {
    // The absolute part comes from the site_url setting, which is empty in a
    // fresh install, so the canonical is path-relative here. What matters is
    // the path: the article must not still claim /skincare-guide/.
    $html = $this->get('/' . LIVE_SLUG . '/')->assertOk()->getContent();

    expect($html)->toContain('rel="canonical" href="/' . LIVE_SLUG . '/"')
        ->and($html)->not->toContain('/skincare-guide/' . LIVE_SLUG);
});

it('keeps the Journal index where the nav already points', function () {
    // Only the article URL moved. Four places publish /skincare-guide/ for the
    // index — the homepage, MenuDemo, MegaMenuApiController and the admin
    // Pages screen — and none of them were part of the owner's answer.
    $this->get('/skincare-guide/')
        ->assertOk()
        ->assertSee('Heartleaf extract: transforming K-beauty skincare');
});

it('links each index card at the root slug', function () {
    $this->get('/skincare-guide/')
        ->assertOk()
        ->assertSee('href="/' . LIVE_SLUG . '/"', escape: false)
        ->assertDontSee('/skincare-guide/' . LIVE_SLUG, escape: false);
});

it('301s the retired /skincare-guide/{slug}/ article URL to the root', function () {
    $response = $this->get('/skincare-guide/' . LIVE_SLUG . '/');

    $response->assertStatus(301);
    expect($response->headers->get('Location'))->toEndWith('/' . LIVE_SLUG . '/');
});

it('301s a post written after the seeding migration ran', function () {
    // The route, not a seeded row, is what has to catch this one.
    Post::create([
        'slug' => 't-written-after-the-migration',
        'title' => 'Written later',
        'status' => 'published',
        'published_at' => now(),
    ]);

    expect(Redirect::where('source', '/skincare-guide/t-written-after-the-migration/')->exists())
        ->toBeFalse();

    $response = $this->get('/skincare-guide/t-written-after-the-migration/');

    $response->assertStatus(301);
    expect($response->headers->get('Location'))->toEndWith('/t-written-after-the-migration/');
});

it('301s the seeded WordPress /blog/{slug} URL to the root slug', function () {
    // This prefix has no route; the seeded row and the 404 handler serve it.
    // Note the test client normalises a trailing slash away before dispatch,
    // so this exercises the slash-less row — which is exactly why both forms
    // are seeded.
    $response = $this->get('/blog/' . LIVE_SLUG . '/');

    $response->assertStatus(301);

    // Compared with the trailing slash normalised off. CheckRedirects hands
    // the stored target to redirect() as a relative path, and Laravel's
    // UrlGenerator::format() trims a trailing slash off every relative path it
    // is given — so a target stored as "/slug/" is emitted as "/slug". The
    // route matches either spelling, so this lands on the article either way;
    // it is a canonical wart shared by every redirect the table serves, and is
    // flagged for the integrator rather than worked around in the seed data.
    expect(rtrim((string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH), '/'))
        ->toBe('/' . LIVE_SLUG);
});

it('seeds the /blog/ redirect map by migration, in both slash forms', function () {
    // CheckRedirects matches `source` by exact string, so a row only ever
    // catches the spelling it was stored under.
    expect(Redirect::where('source', '/blog/' . LIVE_SLUG . '/')->value('target'))
        ->toBe('/' . LIVE_SLUG . '/')
        ->and(Redirect::where('source', '/blog/' . LIVE_SLUG)->value('target'))
        ->toBe('/' . LIVE_SLUG . '/');

    expect(Redirect::where('source', '/blog/k-beauty-bliss-a-beginners-guide-to-korean-skincare/')->value('target'))
        ->toBe('/k-beauty-bliss-a-beginners-guide-to-korean-skincare/');
});

it('follows an old article URL through to a 200', function () {
    $this->followingRedirects()
        ->get('/skincare-guide/' . LIVE_SLUG . '/')
        ->assertOk()
        ->assertSee('Heartleaf extract: transforming K-beauty skincare');

    $this->followingRedirects()
        ->get('/blog/' . LIVE_SLUG . '/')
        ->assertOk()
        ->assertSee('Heartleaf extract: transforming K-beauty skincare');
});

it('hides an unpublished post from the index and from its own URL', function () {
    Post::create([
        'slug' => 't-not-ready-yet',
        'title' => 'T Not ready yet',
        'status' => 'draft',
    ]);

    $this->get('/skincare-guide/')->assertOk()->assertDontSee('T Not ready yet');
    $this->get('/t-not-ready-yet/')->assertNotFound();
    // And the old URL must not 301 to a 404 either.
    $this->get('/skincare-guide/t-not-ready-yet/')->assertNotFound();
});

it('404s an unknown root slug that has no redirect', function () {
    $this->get('/t-never-was-a-page/')->assertNotFound();
});

it('keeps web.php\'s /post/{slug} line working through the renamed route', function () {
    // routes/web.php redirects /post/{slug} with route('post', ...). That name
    // moves onto the new root-level route, so this line needs no edit — but it
    // only stays correct while the name does, which is what this pins.
    $response = $this->get('/post/' . LIVE_SLUG);

    $response->assertStatus(301);
    expect(rtrim((string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH), '/'))
        ->toBe('/' . LIVE_SLUG);
});

it('sends bare /post and /blog to the Journal index', function () {
    $this->followingRedirects()->get('/post')->assertOk()->assertSee('The Glow Journal');
    $this->followingRedirects()->get('/blog')->assertOk()->assertSee('The Glow Journal');
});
