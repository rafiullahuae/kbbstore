<?php

declare(strict_types=1);

/*
 * The Journal's permalink structure, pinned (Lane GA).
 *
 * KBB-Master-Plan.md Phase 9 still carries this as an open, flagged item:
 *
 *   ▲ /skincare-guide/ — a permalink structure, not one page: the homepage
 *     builds /skincare-guide/{slug}/, the router serves /blog and /post/{slug}
 *
 * None of that is true any more, and this file is why the next reader does not
 * have to take that on trust. Established by fetching against a running server
 * before a line of it was written — the transcript is in
 * docs/GA-SKINCARE-GUIDE.md §3:
 *
 *   /skincare-guide/            200, canonical .../skincare-guide/
 *   /{slug}/                    200, canonical .../{slug}/
 *   /skincare-guide/{slug}/     301 -> .../{slug}/
 *   /blog, /post/{slug}         301, they serve nothing
 *
 * and no producer anywhere in the tree builds /skincare-guide/{slug}/: the
 * homepage rail, the Journal index, the sitemap, IndexNow and the admin's
 * Blog Posts screen all publish /{slug}/.
 *
 * WHAT IS HERE AND WHAT IS IN PostUrlTest. tests/Feature/PostUrlTest.php came
 * with the 2.60.109 move and already pins the article route, the retired
 * prefix's 301, the seeded /blog/{slug}/ rows and the sitemap's article entry.
 * That is not repeated. This file adds the four things it does not cover: the
 * exact canonical STRING on both of the shop's own addresses, the homepage
 * rail, /skincare-guide/<anything> as a census rather than one known slug, and
 * the Arabic half — which is where the defect this lane found actually lives.
 *
 * WHAT THIS FILE IS FOR. The plan has been wrong about this item for a release
 * and a half, and the cost of being wrong the other way — repointing the
 * canonical at an address the live WordPress site does not serve — is the
 * shop's existing search traffic landing on a 404. So the contract is pinned
 * here rather than described anywhere: /skincare-guide/ is the Journal INDEX
 * and nothing else, an article is /{slug}/ and nothing else, and every retired
 * form 301s to one of those two.
 */

use App\Models\Post;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\Locale;
use Tests\Support\JournalLegacyRoutes;

const JPC_BASE = 'https://kbeautybliss.test';
const JPC_SLUG = 'heartleaf-extract-transforming-k-beauty-skincare';

function jpcSettings(array $values = []): void
{
    foreach (array_merge([
        'site_url' => JPC_BASE,
        'seo_site_name' => 'K-Beauty Bliss',
        'sitemap_enabled' => '1',
    ], $values) as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => (string) $value, 'autoload' => true]);
    }

    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
}

/** Arabic on the way the Translation screen does it: a setting, not a release. */
function jpcArabicOn(): void
{
    jpcSettings([Locale::SETTING_ENABLED => '1']);
}

/** One published article, at the slug the owner confirmed is live. */
function jpcPost(string $slug = JPC_SLUG): Post
{
    return Post::create([
        'slug' => $slug,
        'title' => 'Heartleaf extract, transforming K-beauty skincare',
        'excerpt' => 'One ingredient has quietly become a staple.',
        'body' => '<p>' . str_repeat('Heartleaf is a calming botanical. ', 40) . '</p>',
        'tag' => 'Ingredients',
        'status' => 'published',
        'published_at' => now()->subDays(3),
    ]);
}

/**
 * The origin a redirect Location carries.
 *
 * NOT JPC_BASE. A canonical tag is built from the `site_url` SETTING (the SEO
 * screen writes it, and it is what the shop calls itself); a Location is built
 * by Laravel from APP_URL. They are the same string in production and
 * deliberately different here, so an assertion cannot pass by reading the
 * wrong one.
 */
function jpcOrigin(): string
{
    return rtrim((string) config('app.url'), '/');
}

/** The one <link rel="canonical"> a page publishes, or null. */
function jpcCanonical(string $html): ?string
{
    return preg_match('#<link rel="canonical" href="([^"]+)"#', $html, $m) ? $m[1] : null;
}

/**
 * Every /skincare-guide/<something> address a page links to.
 *
 * array_values(array_unique(...)) rather than expect()->not->toContain():
 * toContain is variadic, so its second argument is read as a second needle and
 * a "message" makes the assertion pass whatever the page says (CLAUDE.md, and
 * docs/fk-assertion-sweep.md). This returns the offenders so a failure names
 * them.
 *
 * @return list<string>
 */
function jpcArticleUnderGuide(string $html): array
{
    preg_match_all('#skincare-guide/[A-Za-z0-9][A-Za-z0-9\-_]*#', $html, $m);

    return array_values(array_unique($m[0]));
}

beforeEach(function () {
    jpcSettings();
    // The home rail and the Journal index are both cached for 15 minutes.
    \Illuminate\Support\Facades\Cache::flush();
});

/*
|------------------------------------------------------------------------------
| 1. The two addresses the shop owns
|------------------------------------------------------------------------------
*/

it('serves the Journal index at /skincare-guide/ and canonicalises it to itself', function () {
    jpcPost();

    $res = $this->get('/skincare-guide/')->assertOk();

    expect(jpcCanonical((string) $res->getContent()))->toBe(JPC_BASE . '/skincare-guide/');
});

it('serves an article at the site root and canonicalises it to itself', function () {
    jpcPost();

    $res = $this->get('/' . JPC_SLUG . '/')->assertOk();

    expect(jpcCanonical((string) $res->getContent()))->toBe(JPC_BASE . '/' . JPC_SLUG . '/');
});

/*
|------------------------------------------------------------------------------
| 2. The retired article prefix — the one the plan says is still the published
|    form. It is a 301, and the Location keeps the trailing slash and the
|    language, because PageController::legacyPost builds it with
|    Url::redirect() rather than route().
|------------------------------------------------------------------------------
*/

it('keeps the reader in Arabic when it 301s /ar/skincare-guide/{slug}/', function () {
    jpcArabicOn();
    jpcPost();

    // The English article address would be a language change the reader did
    // not ask for: they clicked an /ar/ link.
    $this->get('/ar/skincare-guide/' . JPC_SLUG . '/')
        ->assertStatus(301)
        ->assertRedirect(jpcOrigin() . '/ar/' . JPC_SLUG . '/');
});

it('404s an unknown slug under the retired prefix rather than bouncing it to the index', function () {
    jpcPost();

    // A 301 to the index for anything at all would tell a crawler that every
    // misspelling is a real URL, and would hide the redirects table's turn at
    // the 404 (AppServiceProvider's renderable).
    $this->get('/skincare-guide/no-such-article/')->assertNotFound();
});

/*
|------------------------------------------------------------------------------
| 3. Nothing publishes /skincare-guide/{slug}/ any more
|------------------------------------------------------------------------------
*/

it('links articles from the Journal index at the site root, not under the index', function () {
    jpcPost();

    $html = (string) $this->get('/skincare-guide/')->assertOk()->getContent();

    expect($html)->toContain('href="/' . JPC_SLUG . '/"');
    expect(jpcArticleUnderGuide($html))->toBe([]);
});

it('links articles from the homepage rail at the site root, not under the index', function () {
    jpcPost();

    $html = (string) $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('href="/' . JPC_SLUG . '/"');
    expect(jpcArticleUnderGuide($html))->toBe([]);
});

it('submits only the root article URL to the sitemap', function () {
    jpcPost();

    $xml = (string) $this->get('/sitemap.xml')->assertOk()->getContent();

    expect($xml)->toContain('<loc>' . JPC_BASE . '/' . JPC_SLUG . '/</loc>');
    // The index belongs there; an article under it does not, and neither does
    // a /post/ address. A sitemap entry that 301s is a Search Console warning
    // and a wasted crawl.
    expect($xml)->toContain('<loc>' . JPC_BASE . '/skincare-guide/</loc>');
    expect(jpcArticleUnderGuide($xml))->toBe([]);
    expect(str_contains($xml, '/post/' . JPC_SLUG))->toBeFalse();
});

/*
|------------------------------------------------------------------------------
| 4. The two Laravel-era prefixes, AS DEPLOYED TODAY — covered elsewhere
|------------------------------------------------------------------------------
|
| tests/Feature/PostUrlTest.php already pins both, and pins them the only way
| they can honestly be pinned today: tolerantly about the trailing slash. web.php
| builds both Locations with redirect()->route(), which strips it, so /blog 301s
| to /skincare-guide and /post/{slug} to /{slug}. Both of those answer 200 and
| self-canonicalise to the slashed form, so it is a wasted hop rather than lost
| traffic — but asserting the slash-less form strictly would encode the defect
| and go red the moment the integrator applies §5's replacement. Not repeated
| here.
|------------------------------------------------------------------------------
*/

/*
|------------------------------------------------------------------------------
| 5. The same two, once the integrator has applied the replacement
|------------------------------------------------------------------------------
|
| JournalLegacyRoutes::wire() drops web.php's two registrations and loads
| routes/kbb-journal-legacy.php in their place, which is the state web.php is
| in after the edit (docs/GA-SKINCARE-GUIDE.md §5 carries the exact anchor and
| replacement). Until that edit ships, these prove the replacement is right;
| they do not prove it is deployed.
|------------------------------------------------------------------------------
*/

it('wires the replacement in place of web.php, not behind it', function () {
    JournalLegacyRoutes::wire($this->app);

    // If the two superseded registrations were still there, Laravel would
    // serve them and every assertion below would pass against the old code.
    expect(JournalLegacyRoutes::actionFor('/post/' . JPC_SLUG))
        ->toContain('PageController@legacyPost');
});

it('sends /blog to the canonical index address once replaced', function () {
    JournalLegacyRoutes::wire($this->app);

    $this->get('/blog')->assertStatus(301)->assertRedirect(jpcOrigin() . '/skincare-guide/');
});

it('sends /post/{slug} to the canonical article address once replaced', function () {
    JournalLegacyRoutes::wire($this->app);
    jpcPost();

    $this->get('/post/' . JPC_SLUG)->assertStatus(301)->assertRedirect(jpcOrigin() . '/' . JPC_SLUG . '/');
});

it('sends bare /post/ to the index once replaced', function () {
    JournalLegacyRoutes::wire($this->app);

    $this->get('/post/')->assertStatus(301)->assertRedirect(jpcOrigin() . '/skincare-guide/');
});

it('keeps the reader in Arabic through /ar/blog and /ar/post/{slug} once replaced', function () {
    JournalLegacyRoutes::wire($this->app);
    jpcArabicOn();
    jpcPost();

    // Today both of these land on the English page: route() cannot know about
    // the language segment, and Url::redirect() does. This is the half of the
    // defect a reader notices.
    $this->get('/ar/blog')->assertStatus(301)->assertRedirect(jpcOrigin() . '/ar/skincare-guide/');
    $this->get('/ar/post/' . JPC_SLUG)->assertStatus(301)->assertRedirect(jpcOrigin() . '/ar/' . JPC_SLUG . '/');
});

it('404s an unknown slug under /post/ once replaced', function () {
    JournalLegacyRoutes::wire($this->app);
    jpcPost();

    $this->get('/post/no-such-article')->assertNotFound();
});
