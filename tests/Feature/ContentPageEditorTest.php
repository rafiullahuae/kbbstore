<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\PageEditorApiController;
use App\Http\Controllers\Store\SeoFilesController;
use App\Models\AdminUser;
use App\Models\Page;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Services\Translation\TranslationStore;
use App\Support\AdminCapabilities;
use App\Support\PageTitle;
use App\Support\RoutedPages;
use Illuminate\Support\Facades\Route;
use Tests\Support\ArabicShop;
use Tests\Support\KeyOrder;
use Tests\Support\PageEditorRoutes;

/**
 * =============================================================================
 * Pages → User pages → Edit — the last genuine gap in the SEO work
 * =============================================================================
 *
 * ── THE DEFECT, MEASURED AGAINST THE ROUTER ─────────────────────────────────
 *
 * `pages` carries the same `{title, desc, og_image, canonical, noindex}` column
 * that `products`, `categories`, `brands` and `posts` carry. Lane S6 made the
 * storefront READ it and made /sitemap.xml honour its `noindex`.
 * `Support\SeoAudit::scanPages()` raises findings against those very fields.
 *
 * And NOTHING COULD WRITE THEM. Driven against the route collection before this
 * lane: ZERO non-GET routes whose URI mentions `pages`. Admin\PagesApiController
 * had exactly two methods, both GET, both listing, and neither returned the
 * `seo` column — so no screen could have rendered a form over it. The console
 * said so in its own words: the New page button called
 * `toast('Page editor arrives with the CMS in Phase 11')`.
 *
 * So an audit finding raised against a content page was not actionable anywhere
 * in this software, and the seven pages the footer links to from every page of
 * the shop — the privacy policy, the terms, delivery, returns, the FAQ, about,
 * contact — could not be edited at all.
 *
 * ── AND THE ONE THAT WOULD HAVE COST AN INDEXED URL ─────────────────────────
 *
 * The obvious shape for this screen is "list the pages table, add a New page
 * button". It would have shipped a defect: a content page is served by a LITERAL
 * route in routes/web.php carrying `->defaults('slug', 'about')`, and the
 * site-root catch-all `/{slug}/` reaches PageController::post(), which queries
 * `Post` and nothing else. A page created at any other slug is a row no request
 * can ever reach.
 *
 * Not hypothetical: Store → Demo Content → Demo Pages already writes four such
 * rows, published, and all four 404. This editor is the first screen in the
 * console that says so.
 *
 * Every case below names what it would look like on the shop, and the mutation
 * that makes it red is written beside it.
 */

/* ------------------------------------------------------------------ set-up */

beforeEach(function () {
    PageEditorRoutes::wire($this->app);

    foreach ([
        'site_url' => 'https://kbeautybliss.test',
        'seo_site_name' => 'K-Beauty Bliss',
        'seo_default_description' => 'Korean skincare, delivered across the UAE.',
        'sitemap_enabled' => '1',
    ] as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => (string) $value, 'autoload' => true]);
    }

    Setting::flushMap();
    SettingsService::forgetMemo();
});

function cpeAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'CPE '.$role,
        'email' => 'cpe-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => $role,
    ]);
}

/** The `faqs` row, which is one of the seven the router serves. */
function cpePage(): Page
{
    return Page::query()->where('slug', 'faqs')->firstOrFail();
}

/** The payload the screen posts: every field, every time, as the form does. */
function cpePayload(array $overrides = []): array
{
    $page = cpePage();

    return array_merge([
        'title' => PageTitle::decoded($page->title),
        'content' => (string) $page->content,
        'status' => 'published',
        'seo' => ['title' => '', 'desc' => '', 'og_image' => '', 'canonical' => '', 'noindex' => false],
        'translations' => [],
    ], $overrides);
}

/** Save through the real endpoint, as the owner. */
function cpeSave(array $overrides = [], ?int $id = null): \Illuminate\Testing\TestResponse
{
    test()->actingAs(cpeAdmin(), 'admin');

    return test()->postJson(
        '/admin-api/page-editor-save/'.($id ?? cpePage()->id),
        cpePayload($overrides)
    );
}

/** The <head> of a page, so a match cannot come from the body or a script. */
function cpeHead(string $path): string
{
    $html = test()->get($path)->assertOk()->getContent();

    expect((bool) preg_match('#<head>(.*?)</head>#s', $html, $m))
        ->toBeTrue($path.' rendered no <head> at all; the parse is wrong, not the assertion.');

    return $m[1];
}

/* =================== 1. the gap: a page's SEO can be written =============== */

it('writes the per-row SEO fields the storefront was already reading', function () {
    /*
     * THE DEFECT: there was no endpoint at all. `pages.seo` was null on every
     * shipped row and nothing in this application could make it anything else,
     * while Store\PageController::show() read all five keys and SeoAudit raised
     * findings against them.
     *
     * MUTATION, RUN: delete the `save` route from routes/page-editor-admin.php
     * and this is a 404 — the state the shop was found in, where POST anything
     * at a page answered 405.
     */
    expect(cpePage()->seo)->toBeNull();

    cpeSave(['seo' => [
        'title' => 'Delivery, returns and the questions we are asked most',
        'desc' => 'How delivery, returns and order tracking work at K-Beauty Bliss.',
        'og_image' => '/storage/pages/faq.jpg',
        'canonical' => '',
        'noindex' => false,
    ]])->assertOk()->assertJson(['ok' => true]);

    /*
     * ▲ KEY ORDER IS THE ENGINE'S, NOT THIS ENDPOINT'S. `pages.seo` is a `json`
     * column, and MySQL's JSON type does not store object key order — it sorts
     * members by (key length, then bytewise). So the three keys written
     * title/desc/og_image come back desc/title/og_image on the engine the shop
     * runs, `toBe()` is order-sensitive, and this case was red on
     * -c phpunit-mysql.xml with the endpoint behaving perfectly. SQLite has no
     * JSON type, stores the bytes it was given, and hid it.
     *
     * Every key and every value is still asserted — including that `canonical`
     * and `noindex` were DROPPED rather than stored blank, which is what the
     * three-key expectation says. Tests\Support\KeyOrder carries the measurement.
     */
    expect(KeyOrder::canonical(cpePage()->seo))->toBe(KeyOrder::canonical([
        'title' => 'Delivery, returns and the questions we are asked most',
        'desc' => 'How delivery, returns and order tracking work at K-Beauty Bliss.',
        'og_image' => '/storage/pages/faq.jpg',
    ]));

    // And on the wire, which is the only claim that matters.
    $head = cpeHead('/faqs/');

    expect($head)->toContain('<title>Delivery, returns and the questions we are asked most</title>')
        ->toContain('<meta name="description" content="How delivery, returns and order tracking work at K-Beauty Bliss.">')
        ->toContain('https://kbeautybliss.test/storage/pages/faq.jpg');
});

it('stores only the SEO keys this screen offers, and stores an unset one as absent', function () {
    /*
     * RULE 5: an allowlist, never the request. And the ABSENT-vs-EMPTY half,
     * which is not tidiness: Store\PageController::show() guards on
     * `trim(...) !== ''`, so a key that is present and blank is "no override" by
     * a longer route — and PageSeoOverridesTest's second case exists because the
     * panel posts every field on every save.
     *
     * MUTATION, RUN: take the `if ($value === '') continue;` out of
     * PageEditorApiController::seo() and `seo` comes back as five empty keys
     * instead of null — red on the first expectation.
     */
    cpeSave(['seo' => [
        'title' => '', 'desc' => '', 'og_image' => '', 'canonical' => '', 'noindex' => false,
        // Not on the allowlist. Each of these is a real column name on another
        // table, which is what a copy-paste from another editor looks like.
        'admin_path' => '/hacked', 'schema_type' => 'Product', 'noindex_all' => true,
    ]])->assertOk();

    expect(cpePage()->seo)->toBeNull();

    cpeSave(['seo' => ['desc' => 'Only this one.', 'admin_path' => '/hacked']])->assertOk();

    expect(cpePage()->seo)->toBe(['desc' => 'Only this one.'])
        ->and(array_keys((array) cpePage()->seo))->not->toContain('admin_path');
});

/* ============ 2. the ten PageSeoOverridesTest states, driven through it ==== */

it('can produce every state PageSeoOverridesTest pins, from the form', function (
    array $seo,
    array $expect,
    array $absent
) {
    /*
     * THE POINT OF THE WHOLE LANE. PageSeoOverridesTest pins ten states of a
     * rendered content page against a `seo` column written DIRECTLY — by the
     * test, with Page::updateOrCreate. That proved the storefront reads the
     * column. It said nothing about whether anything could write it, and nothing
     * could.
     *
     * So each state is produced here by POSTing the form, and the assertion is
     * made against the bytes a crawler is served. If the editor cannot express
     * one of these, the column has a state the owner cannot reach.
     *
     * MUTATION, RUN: drop `title` from PageEditorApiController::SEO_KEYS and the
     * title and Yoast-token rows go red — 6 failed, measured, not 2: the SEO
     * title is also what four other cases here read back, which is the point of
     * running a mutation instead of predicting one.
     */
    cpeSave(['seo' => $seo])->assertOk();

    $head = cpeHead('/faqs/');

    foreach ($expect as $needle) {
        expect(str_contains($head, $needle))->toBeTrue('missing from <head>: '.$needle);
    }

    foreach ($absent as $needle) {
        expect(str_contains($head, $needle))->toBeFalse('should not be in <head>: '.$needle);
    }
})->with([
    // 1 — no override at all. The shop as it ships, and the first case in
    //     PageSeoOverridesTest for the same reason: applying this must move
    //     nothing.
    'no override' => [
        [],
        [
            '<title>Frequently Asked Questions · K-Beauty Bliss</title>',
            '<meta name="description" content="Korean skincare, delivered across the UAE.">',
            '<meta name="robots" content="index, follow">',
            '<link rel="canonical" href="https://kbeautybliss.test/faqs/">',
        ],
        [],
    ],
    // 2 — the panel posts every field on every save. Five blanks is "no
    //     override", not an empty title.
    'every field blank' => [
        ['title' => '', 'desc' => '', 'og_image' => '', 'canonical' => '', 'noindex' => false],
        [
            '<title>Frequently Asked Questions · K-Beauty Bliss</title>',
            '<meta name="description" content="Korean skincare, delivered across the UAE.">',
            '<meta name="robots" content="index, follow">',
        ],
        ['<title></title>'],
    ],
    // 3 — the per-row title.
    'title override' => [
        ['title' => 'Delivery, returns and the questions we get asked most'],
        ['<title>Delivery, returns and the questions we get asked most</title>'],
        [],
    ],
    // 4 — Yoast's own shipped template, which is what most of an imported
    //     corpus holds. TitleTemplate DELETES a token it was not handed, so
    //     without `title_token` this publishes the site name alone.
    'Yoast tokens in the title' => [
        ['title' => '%%title%% %%sep%% %%sitename%%'],
        ['<title>Frequently Asked Questions | K-Beauty Bliss</title>'],
        ['%%'],
    ],
    // 5 — the per-row description, instead of the site-wide one every content
    //     page otherwise shares.
    'description override' => [
        ['desc' => 'How delivery, returns and order tracking work at K-Beauty Bliss.'],
        ['<meta name="description" content="How delivery, returns and order tracking work at K-Beauty Bliss.">'],
        ['Korean skincare, delivered across the UAE.'],
    ],
    // 6 — a canonical pointing at another page of this shop.
    'canonical override' => [
        ['canonical' => 'https://kbeautybliss.test/delivery/'],
        ['<link rel="canonical" href="https://kbeautybliss.test/delivery/">'],
        [],
    ],
    // 7 — a rooted canonical, which is the other half of what the box accepts
    //     and the shape SeoAudit::canonicalIsSafe() calls safe.
    'rooted canonical' => [
        ['canonical' => '/delivery/'],
        ['<link rel="canonical" href="https://kbeautybliss.test/delivery/">'],
        [],
    ],
    // 8 — the social image.
    'og:image override' => [
        ['og_image' => 'https://cdn.example.test/faq.png'],
        ['<meta property="og:image" content="https://cdn.example.test/faq.png">'],
        [],
    ],
    // 9 — noindex, the half that matters beyond tidiness: SeoAudit stops
    //     auditing a noindexed page, so a page that said "index, follow" while
    //     the audit called it deindexed was two documents agreeing with each
    //     other and both disagreeing with the owner.
    'noindex' => [
        ['noindex' => true],
        ['<meta name="robots" content="noindex, nofollow">'],
        ['content="index, follow"'],
    ],
    // 10 — every field at once, which is the state a real operator leaves
    //      behind and the one no single-field case covers.
    'all five at once' => [
        [
            'title' => 'Everything at once',
            'desc' => 'All five fields set from the form.',
            'og_image' => '/storage/pages/faq.jpg',
            'canonical' => 'https://kbeautybliss.test/faqs/',
            'noindex' => true,
        ],
        [
            '<title>Everything at once</title>',
            '<meta name="description" content="All five fields set from the form.">',
            '<meta name="robots" content="noindex, nofollow">',
            '<link rel="canonical" href="https://kbeautybliss.test/faqs/">',
        ],
        ['Korean skincare, delivered across the UAE.'],
    ],
]);

it('takes a page out of the sitemap from the same tick that noindexes it', function () {
    /*
     * The eleventh state, and it is a different FILE from the one above: Lane S6
     * fixed SeoFilesController's pages query to select the `seo` column, and this
     * is the half that proves the fix is reachable from a screen. Asserted in
     * both directions in one case, because "absent" on its own is also what a
     * broken sitemap looks like.
     *
     * MUTATION, RUN: take `noindex` out of PageEditorApiController::SEO_KEYS and
     * the second expectation goes red while the first stays green.
     */
    expect(test()->get('/sitemap.xml')->getContent())
        ->toContain('<loc>https://kbeautybliss.test/faqs/</loc>');

    cpeSave(['seo' => ['noindex' => true]])->assertOk();

    $sitemap = (string) test()->get('/sitemap.xml')->getContent();

    expect($sitemap)->not->toContain('<loc>https://kbeautybliss.test/faqs/</loc>')
        // Per row, not per block: the other six stay in.
        ->toContain('<loc>https://kbeautybliss.test/about/</loc>')
        ->toContain('<loc>https://kbeautybliss.test/delivery/</loc>');
});

it('takes a page off the shop with Draft, and leaves its address recoverable', function () {
    /*
     * The only way this editor can take a page down, and the reason there is no
     * delete route: /about/ is a literal route the footer links to, so deleting
     * the row would make an advertised, sitemapped URL a 404 with nothing to put
     * back. A draft 404s too — but the row, the body and the address survive.
     *
     * MUTATION, RUN: let `status` through unvalidated (drop the `in:` rule and
     * the re-read in fill()) and a typo like 'publish' stores, taking the page
     * down with no way to tell from the select why.
     */
    cpeSave(['status' => 'draft'])->assertOk();

    test()->get('/faqs/')->assertNotFound();

    expect(cpePage()->content)->not->toBeNull()
        ->and(cpePage()->slug)->toBe('faqs');

    cpeSave(['status' => 'published'])->assertOk();
    test()->get('/faqs/')->assertOk();

    // And the select cannot store anything but its own options.
    cpeSave(['status' => 'archived'])->assertStatus(422);
    expect(cpePage()->status)->toBe('published');
});

/* ================= 3. create, delete and the slug — refused ================ */

it('registers no route that could create or delete a page', function () {
    /*
     * NOT an omission. A content page is served by a LITERAL route carrying
     * `->defaults('slug', …)`; the site-root catch-all /{slug}/ reaches
     * PageController::post(), which queries Post only. So a created page is a
     * row no request can reach — it appears in this editor's own list with a
     * green Published pill and 404s for every reader and every crawler — and a
     * deleted page is a 404 at an address the footer advertises.
     *
     * Asserted over the ROUTER rather than by reading the controller, because
     * that is the question: does any endpoint exist.
     *
     * ── THIS CASE ASSERTED NOTHING AT ALL UNTIL THE MUTATION WAS RUN ────────
     *
     * It used to sweep `PageEditorRoutes::registered()`, which filters the router
     * down to the four URIs in `PageEditorRoutes::URIS`. A create route is BY
     * DEFINITION not one of those four, so it was invisible to the sweep: adding
     * `POST /admin-api/page-editor-create` left this case green, and the header
     * above it claimed in as many words that it would go red. Measured — mutation
     * 6 of this lane's run — 47 passed.
     *
     * A "no such endpoint exists" claim cannot be checked against a list of the
     * endpoints that do exist. So the sweep is over the WHOLE route collection
     * now, for every non-GET route whose URI names a page, and the set must be
     * exactly the one save route. That also catches a write route another lane
     * mounts under `admin-api/pages/…`, which is where the capability rules would
     * have decided its guard by list order.
     *
     * MUTATION, RUN: add a `POST /page-editor-create` route and this is red,
     * naming it — 1 failed. Green before the fix.
     */
    expect(PageEditorRoutes::registered())->toHaveCount(count(PageEditorRoutes::URIS));

    $writes = [];

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();

        if (! str_contains($uri, 'pages') && ! str_contains($uri, 'page-editor')) {
            continue;
        }

        foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
            if ($method !== 'GET') {
                $writes[] = $method.' /'.$uri;
            }
        }
    }

    sort($writes);

    expect($writes)->toBe(['POST /admin-api/page-editor-save/{id}'],
        'a route that writes a page exists outside this editor, or the editor grew one');

    // And nothing anywhere in the router deletes a page.
    $deletes = [];

    foreach (Route::getRoutes() as $route) {
        if (in_array('DELETE', $route->methods(), true) && str_contains($route->uri(), 'page')) {
            $deletes[] = '/'.$route->uri();
        }
    }

    expect($deletes)->toBe([]);

    // The server says so to the screen as well, so the button and the endpoint
    // cannot disagree about what exists.
    test()->actingAs(cpeAdmin(), 'admin');

    test()->getJson('/admin-api/page-editor-bootstrap')
        ->assertOk()
        ->assertJson(['can_create' => false, 'can_delete' => false]);
});

it('refuses to move a page, and says what moving it would cost', function () {
    /*
     * Stronger than the same rule on an article. Changing `pages.about` to
     * `about-us` would 404 the page at BOTH addresses: the route still looks up
     * `about` and finds no row, and `/about-us/` has no route at all. The page
     * would simply vanish, with a green Published pill beside it.
     *
     * `prohibited`, not "silently dropped": a field that is ignored is a field
     * the owner thinks worked.
     *
     * MUTATION, RUN: change the rule to `nullable` and the slug is accepted and
     * ignored — this goes red on the status, and /faqs/ still works, which is
     * exactly how the silent version would look.
     */
    $response = cpeSave(['slug' => 'frequently-asked-questions']);

    $response->assertStatus(422);

    expect((string) json_encode($response->json()))->toContain('Redirects');
    expect(cpePage()->slug)->toBe('faqs');
    test()->get('/faqs/')->assertOk();
});

/* ==================== 4. the title: text in an HTML column ================= */

it('round-trips a shipped title byte-for-byte through the box', function () {
    /*
     * RULE 1, and the case that decided how this column is handled at all.
     *
     * `pages.title` is HTML: store/page.blade.php prints it with {!! !!} and
     * seed_policy_pages stores the literal `Terms &amp; Conditions`. A box bound
     * straight to the column would show the owner `Terms &amp; Conditions`, and
     * pressing Save with nothing changed would store `Terms &amp;amp;
     * Conditions` — the page's own heading corrupted by opening the screen, on a
     * page nobody meant to edit.
     *
     * MUTATION, RUN: drop the decode from PageTitle::stored() and the heading
     * gains an `amp;` on every save — red here, naming the stored bytes.
     */
    $terms = Page::query()->where('slug', 'terms-and-conditions')->firstOrFail();

    expect($terms->title)->toBe('Terms &amp; Conditions');

    $before = (string) test()->get('/terms-and-conditions/')->getContent();

    test()->actingAs(cpeAdmin(), 'admin');
    test()->postJson('/admin-api/page-editor-save/'.$terms->id, [
        'title' => PageTitle::decoded($terms->title),   // what the box shows
        'content' => (string) $terms->content,
        'status' => 'published',
        'seo' => [],
        'translations' => [],
    ])->assertOk();

    expect($terms->refresh()->title)->toBe('Terms &amp; Conditions');

    // And the h1 the shopper reads is the same h1.
    preg_match('#<h1>(.*?)</h1>#s', $before, $wasH1);
    preg_match('#<h1>(.*?)</h1>#s', (string) test()->get('/terms-and-conditions/')->getContent(), $nowH1);

    expect($nowH1[1] ?? 'x')->toBe($wasH1[1] ?? 'y');
});

it('cannot put a tag in the heading of a public page', function () {
    /*
     * MEASURED BEFORE THIS EDITOR EXISTED, by writing the payload straight into
     * the column: `/faqs/` served
     *
     *     <h1><script>alert(1)</script>Hi</h1>
     *
     * and the <title> read `alert(1)Hi`, because layouts/store.blade.php runs
     * strip_tags over the section title. docs/f2-operator-authored-html.md §5
     * counted the dialogs firing on the real page. The view is not changed here
     * (that would move the bytes of three live pages — rule 1); the INPUT is
     * narrowed instead, which is what §6 Option A prescribes.
     *
     * MUTATION, RUN: return `$text` unencoded from PageTitle::stored() and the
     * h1 carries a live <script> — red on the second expectation.
     */
    cpeSave(['title' => '<img src=x onerror=alert(1)>Sale & returns'])->assertOk();

    // The tag is GONE, not encoded: the box is a plain-text field, so a title is
    // reduced to its words the same way layouts/store.blade.php already reduces
    // it for <title>. The ampersand is encoded, because the column is HTML.
    expect(cpePage()->title)->toBe('Sale &amp; returns');

    $html = (string) test()->get('/faqs/')->getContent();

    expect($html)->not->toContain('onerror')
        ->toContain('<h1>Sale &amp; returns</h1>');

    // And the shape that is words rather than markup keeps its words, so the
    // stripping cannot be mistaken for the box eating input.
    cpeSave(['title' => '<script>alert(1)</script>Hi'])->assertOk();

    expect(cpePage()->title)->toBe('alert(1)Hi');
    expect((string) test()->get('/faqs/')->getContent())->not->toContain('<script>alert(1)');
});

it('refuses a title that only fits until it is escaped', function () {
    /*
     * `pages.title` is VARCHAR(255) and the box is bounded at 200 TYPED
     * characters — and encoding EXPANDS: 200 ampersands are 1,000 bytes. Without
     * the check the refusal is a bare SQLSTATE[22001] printed into the message
     * strip after the owner has written the whole page, which is the shape
     * JournalArticleEditorTest's empty-author case was found as.
     *
     * MUTATION, RUN: delete the TITLE_STORED_MAX guard from save() and this is a
     * 500 on MySQL and a silent truncation on SQLite — either way not a 422.
     */
    $response = cpeSave(['title' => str_repeat('a & b ', 33)]);

    $response->assertStatus(422);

    expect((string) json_encode($response->json()))->toContain('too long');
    expect(cpePage()->title)->toBe('Frequently Asked Questions');

    // An empty title is refused too: it is the h1 and the <title>.
    cpeSave(['title' => '   '])->assertStatus(422);
    expect(cpePage()->title)->toBe('Frequently Asked Questions');
});

it('gives the owner a per-page way out of the double-escaped title tag', function () {
    /*
     * FOUND, MEASURED, AND NOT FIXED HERE — and this is the case that says what
     * the owner can do about it today.
     *
     * Three of the seven content pages publish a double-escaped <title> right
     * now. Measured over all 24 storefront pages that emit one, exactly these
     * three and no others:
     *
     *     /terms-and-conditions/  <title>Terms &amp;amp;amp; Conditions · K-Beauty Bliss</title>
     *     /delivery/              <title>Shipping &amp;amp;amp; Delivery · K-Beauty Bliss</title>
     *     /refund_returns/        <title>Returns &amp;amp;amp; Refunds · K-Beauty Bliss</title>
     *
     * The mechanism is Blade: `@section('title', <expression>)` runs its second
     * argument through `e()` inside Factory::startSection(), and
     * layouts/store.blade.php reads that section back as `$kbbRawTitle` — a
     * variable whose name says it should be raw — and hands it to Support\Seo,
     * which escapes it again. Product, category, brand and article pages pass
     * their title through $seoCtx instead and are correct, which is why this is
     * three pages and not twenty-four. The fix is one html_entity_decode() in a
     * shared layout every lane renders through, so it is a rule-1 decision with
     * a pin to advance and it is reported rather than taken here.
     *
     * WHAT THIS EDITOR GIVES HIM IS THE WAY ROUND IT: a per-row SEO title is
     * `title_is_final`, so it never touches the Blade section and is escaped
     * exactly once.
     *
     * MUTATION, RUN: revert PageController::show()'s `title_token` to the raw
     * column and the second half goes red — the Yoast-template route publishes
     * `Terms &amp;amp; Conditions | K-Beauty Bliss`, the editor's own box
     * re-breaking the thing it is the workaround for.
     */
    $terms = Page::query()->where('slug', 'terms-and-conditions')->firstOrFail();

    // Lane AMP took that fix: the layout reads its title section as text and
    // store/page.blade.php hands it PageTitle::decoded(), so with no override at
    // all the page is escaped once too (EntityDecodeEscapesTest pins both halves).
    preg_match('#<title>(.*?)</title>#s', (string) test()->get('/terms-and-conditions/')->getContent(), $m);
    expect($m[1])->toBe('Terms &amp; Conditions · K-Beauty Bliss');

    test()->actingAs(cpeAdmin(), 'admin');

    $save = function (array $seo) use ($terms): void {
        test()->postJson('/admin-api/page-editor-save/'.$terms->id, [
            'title' => PageTitle::decoded($terms->title),
            'content' => (string) $terms->content,
            'status' => 'published',
            'seo' => $seo,
            'translations' => [],
        ])->assertOk();
    };

    // Typed in full: escaped once, which is right.
    $save(['title' => 'Terms & Conditions · K-Beauty Bliss']);
    preg_match('#<title>(.*?)</title>#s', (string) test()->get('/terms-and-conditions/')->getContent(), $m2);
    expect($m2[1])->toBe('Terms &amp; Conditions · K-Beauty Bliss');

    // And through Yoast's own shipped template, where `%%title%%` is the page's
    // own name — which is where the raw column used to re-enter.
    $save(['title' => '%%title%% %%sep%% %%sitename%%']);
    preg_match('#<title>(.*?)</title>#s', (string) test()->get('/terms-and-conditions/')->getContent(), $m3);
    expect($m3[1])->toBe('Terms &amp; Conditions | K-Beauty Bliss');
});

/* ================== 5. the body: one sanitiser, both languages ============= */

it('sanitises the page body, and the Arabic body with the same call', function () {
    /*
     * store/page.blade.php prints `content` with {!! !!}, through
     * Shortcodes::render and BodyHeadings::demoteH1. An editor that typed
     * straight into it is a stored-XSS surface on the public storefront the
     * moment a second, lesser-privileged admin account exists — and this repo
     * has already had one live XSS on the homepage ticker.
     *
     * The rule is the one that already exists: RichText::clean(), the same call
     * PostEditorApiController makes on posts.body, which
     * docs/f2-operator-authored-html.md §6 Option A says in as many words the
     * page editor should inherit "instead of reopening the hole".
     *
     * BOTH LANGUAGES OFF ONE CONSTANT. A sanitiser applied to one language is a
     * hole opened by adding the second: f2 §5 measured two dialogs firing on
     * /ar/about/ with the English left clean.
     *
     * MUTATION, RUN: drop RICH_FIELDS from the TranslationInput::clean() call
     * and the Arabic page serves a live <script> while the English one does not
     * — red on the last expectation only, which is exactly the asymmetry.
     */
    ArabicShop::on();

    cpeSave([
        'content' => '<h3>Real heading</h3><p>Body <a href="https://k.test">link</a>.</p>'
            .'<script>alert(1)</script><p style="position:fixed;inset:0">styled</p>',
        'translations' => ['ar' => [
            'title' => 'الأسئلة الشائعة',
            'content' => '<p>عربي</p><script>alert(2)</script>',
        ]],
    ])->assertOk();

    $stored = (string) cpePage()->content;

    expect($stored)->toContain('<h3>Real heading</h3>')
        // The link survives; RichText adds rel="noopener noreferrer" to an
        // external href, which is its own existing rule and not this lane's.
        ->toContain('href="https://k.test"')
        ->toContain('>link</a>')
        ->not->toContain('<script>')
        ->not->toContain('position:fixed');

    $english = (string) test()->get('/faqs/')->getContent();
    expect($english)->not->toContain('alert(1)');

    $arabic = (string) test()->get('/ar/faqs/')->assertOk()->getContent();
    expect($arabic)->toContain('عربي')->not->toContain('alert(2)');
});

it('changes nothing but entities when a shipped page is saved untouched', function () {
    /*
     * RULE 1, on the body. docs/f2-operator-authored-html.md §6 measured the cost
     * of this sanitiser on this shop's own corpus — "34 bytes across 7 rows and
     * no visible change" — and the whole of that is named HTML entities becoming
     * the characters they already rendered as. This asserts it rather than
     * quoting it: every tag and every href survives a round trip.
     *
     * MUTATION, RUN: add `a` or `h3` to RichText's drop list and this goes red,
     * naming the tag that vanished.
     */
    $before = (string) cpePage()->content;

    cpeSave()->assertOk();

    $after = (string) cpePage()->content;

    preg_match_all('#</?([a-z0-9]+)#i', $before, $wasTags);
    preg_match_all('#</?([a-z0-9]+)#i', $after, $nowTags);

    expect($nowTags[1])->toBe($wasTags[1], 'the sanitiser changed the tag sequence of a shipped page');

    // Same words, once the entities are resolved on both sides.
    $text = fn (string $html): string => trim((string) preg_replace('/\s+/u', ' ',
        html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

    expect($text($after))->toBe($text($before));
});

/* ================= 6. the two URL fields are scheme-checked ================ */

it('refuses a canonical or a social image that is not a URL this shop publishes', function (string $key, string $value) {
    /*
     * RULE 5. Both of these become an href / an og:image. Support\Seo narrows a
     * canonical to http/https/protocol-relative at RENDER time (Lane S6) — this
     * is an earlier and narrower refusal, so the value never reaches the column
     * at all, and the owner is told why instead of finding a silently ignored
     * box.
     *
     * `javascript:` is the one that matters: before Lane S6 narrowed the
     * renderer it was published verbatim in <link rel=canonical>, og:url and
     * every hreflang href.
     *
     * MUTATION, RUN: return `$value` from PageEditorApiController::safeUrl()
     * unchecked and every row here is a 200 that stores the string.
     */
    $response = cpeSave(['seo' => [$key => $value]]);

    $response->assertStatus(422);

    expect(cpePage()->seo)->toBeNull();
})->with([
    ['canonical', 'javascript:alert(1)'],
    ['canonical', 'data:text/html,<script>alert(1)</script>'],
    ['canonical', '//evil.test/faqs/'],
    ['canonical', 'mailto:someone@example.test'],
    ['canonical', 'ftp://example.test/faqs/'],
    ['og_image', 'javascript:alert(1)'],
    ['og_image', '//evil.test/logo.png'],
]);

it('keeps a canonical and an image that really are addresses, in both shapes', function (string $key, string $value) {
    // The other half: the refusal must not be so wide that the box is useless.
    cpeSave(['seo' => [$key => $value]])->assertOk();

    expect(cpePage()->seo)->toBe([$key => $value]);
})->with([
    ['canonical', 'https://kbeautybliss.test/delivery/'],
    ['canonical', 'http://kbeautybliss.test/delivery/'],
    ['canonical', '/delivery/'],
    ['og_image', 'https://cdn.example.test/faq.png'],
    ['og_image', '/storage/pages/faq.jpg'],
]);

/* ============ 7. the rows the shop does not serve, said out loud =========== */

it('asks the router which pages the shop serves, and agrees with the sitemap', function () {
    /*
     * The seven addresses are not a list in this lane's code — they are read off
     * the routes that serve them, including the slug each route carries as a
     * DEFAULT, so the key identifies the row and the value identifies the
     * address and the two are allowed to differ. Register `/about-us` with
     * `->defaults('slug', 'about')` and the shop serves /about-us/ while a copy
     * of the slugs would have said /about/, which 404s.
     *
     * Pinned against Store\SeoFilesController's own private answer by reflection,
     * because two callers computing the same set separately is two sets to
     * drift, and the sitemap is the file that would advertise the wrong one.
     *
     * MUTATION, RUN: drop the `defaults['slug']` read from RoutedPages and use
     * the URI as the slug — 1 failed, in the case below. It is a SEPARATE case
     * because this one cannot catch it: all seven shipped routes spell their URI
     * and their slug the same way, so on this shop the two readings agree and the
     * mutation is invisible here. Measured — mutation 15 of this lane's run left
     * this file 47 passed. An acknowledged blind spot is still a blind spot.
     */
    $mine = RoutedPages::paths();

    $theirs = (new ReflectionMethod(SeoFilesController::class, 'routedPagePaths'))
        ->invoke(null);

    expect($mine)->toBe($theirs);

    expect(array_keys($mine))->toBe([
        'privacy-policy', 'terms-and-conditions', 'delivery', 'refund_returns',
        'faqs', 'about', 'contact-us',
    ]);

    expect($mine['faqs'])->toBe('/faqs/');
});

it('reads the address off the route and the row off the route\'s default', function () {
    /*
     * THE CLAIM IN RoutedPages' OWN HEADER, WITH A TEST UNDER IT AT LAST.
     *
     * It says: "Register `/about-us` with `->defaults('slug', 'about')` and this
     * answers `['about' => '/about-us/']`, which is what the shop really serves.
     * A copy of the seven slugs would have answered `/about/`, which would 404."
     * Nothing checked it. All seven shipped routes spell their URI and their slug
     * identically, so the case above passes whether RoutedPages reads the default
     * or the URI — and the editor would print the wrong address, the "View on the
     * shop" link would 404, and the SEO preview would ask the server about a page
     * that is not there.
     *
     * So a route of that shape is registered here and the two halves are pulled
     * apart: the KEY must be the slug the default names (the row PageController
     * will look up) and the VALUE must be the URI the router matches (the address
     * a visitor types). Reading the URI for both, or the default for both, fails.
     *
     * MUTATION, RUN: use the URI as the slug in RoutedPages::paths() and this is
     * red — the key is `about-us`, which is a row this shop does not have.
     */
    Route::get('about-us', [\App\Http\Controllers\Store\PageController::class, 'show'])
        ->defaults('slug', 'about');

    Route::getRoutes()->refreshNameLookups();
    Route::getRoutes()->refreshActionLookups();

    $paths = RoutedPages::paths();

    // `/about` is registered first and wins, so the row keeps its own address and
    // the second route does not silently move a page that was already served.
    expect($paths['about'])->toBe('/about/');

    // And a slug only the second route names resolves to the second route's URI
    // rather than to a guess built out of the slug.
    expect(RoutedPages::pathFor('about-us'))->toBeNull(
        'RoutedPages is keying on the URI rather than on the route default: '
        .'`about-us` is not a row in this shop, it is an address'
    );

    // The same question the other way round, on a slug NO route defaults to.
    expect(RoutedPages::pathFor('nothing-routes-here'))->toBeNull();
});

it('says which pages have no address on the shop, which no screen ever has', function () {
    /*
     * THE DEFECT IT SURFACES, AND IT IS SOMEBODY ELSE'S: Store → Demo Content →
     * Demo Pages writes four rows — about-us-demo, shipping-delivery-demo,
     * returns-exchanges-demo, faq-demo — as `status: published`, under a comment
     * that reads "the whole point of this demo content is to actually be visible
     * so the page layout can be previewed". There is no route for any of them, so
     * all four 404. Before this screen, the console listed them beside the seven
     * real pages with a View link to a 404 and no way to tell the difference.
     *
     * MUTATION, RUN: hard-code `'routed' => true` in the projection and this is
     * red on both halves.
     */
    $orphan = Page::create([
        'slug' => 'about-us-demo',
        'title' => 'About Us (Demo)',
        'content' => '<p>A demo About page.</p>',
        'status' => 'published',
    ]);

    // The shop really does not serve it — that is the premise, not an assumption.
    test()->get('/about-us-demo/')->assertNotFound();

    test()->actingAs(cpeAdmin(), 'admin');

    $rows = collect(test()->getJson('/admin-api/page-editor-list')->assertOk()->json('pages'))
        ->keyBy('slug');

    expect($rows['about-us-demo']['routed'])->toBeFalse()
        ->and($rows['about-us-demo']['path'])->toBeNull()
        ->and($rows['about-us-demo']['url'])->toBeNull()
        ->and($rows['faqs']['routed'])->toBeTrue()
        ->and($rows['faqs']['path'])->toBe('/faqs/');

    // And it is still editable: the row is real, it simply has nowhere to go.
    test()->getJson('/admin-api/page-editor-load/'.$orphan->id)
        ->assertOk()
        ->assertJson(['page' => ['routed' => false, 'path' => null]]);
});

it('tells the list which rows carry an SEO override, without handing it the bag', function () {
    /*
     * The complaint the matrix row made about the old endpoint was that it "does
     * not even return the `seo` column", so no screen could render a form over
     * it. Answered — and answered with a BOOLEAN on the table, because a table
     * does not need the values and a projection that carries less cannot leak
     * more. The form gets the bag, behind the same capability.
     *
     * MUTATION, RUN: make seoIsSet() a plain `!== null` check and a row holding
     * five empty strings reads as "set", which is the state the panel leaves
     * behind and the exact thing show() treats as no override.
     */
    test()->actingAs(cpeAdmin(), 'admin');

    $before = collect(test()->getJson('/admin-api/page-editor-list')->json('pages'))->keyBy('slug');

    expect($before['faqs']['seo_set'])->toBeFalse()
        ->and(array_key_exists('seo', $before['faqs']))->toBeFalse();

    // Five blanks is NOT an override.
    Page::query()->where('slug', 'faqs')->update(['seo' => json_encode([
        'title' => '', 'desc' => '', 'og_image' => '', 'canonical' => '', 'noindex' => false,
    ])]);

    $blank = collect(test()->getJson('/admin-api/page-editor-list')->json('pages'))->keyBy('slug');
    expect($blank['faqs']['seo_set'])->toBeFalse();

    cpeSave(['seo' => ['desc' => 'A real one.']])->assertOk();

    $after = collect(test()->getJson('/admin-api/page-editor-list')->json('pages'))->keyBy('slug');
    expect($after['faqs']['seo_set'])->toBeTrue();

    // And the form does get the bag.
    test()->getJson('/admin-api/page-editor-load/'.cpePage()->id)
        ->assertOk()
        ->assertJson(['page' => ['seo' => ['desc' => 'A real one.']]]);
});

/* ==================== 8. the guard rail and the capability ================= */

it('is mounted inside the admin group and reachable by nobody else', function () {
    /*
     * /api/* in this application is unauthenticated BY DESIGN, and every case in
     * ApiSecurityTest leaked in production first. An unguarded route here would
     * hand the public the ability to rewrite this shop's privacy policy at an
     * address linked from every page of the site.
     *
     * Read off the REGISTERED routes rather than trusting the harness:
     * RouteRegistrar::middleware() REPLACES rather than appends, so a harness
     * that chains it twice guards nothing while reading as though it does.
     *
     * MUTATION, RUN: drop 'auth:admin' from PageEditorRoutes::STACK and every
     * expectation below goes red.
     */
    $routes = PageEditorRoutes::registered();

    expect($routes)->toHaveCount(count(PageEditorRoutes::URIS));

    foreach ($routes as $route) {
        expect($route->gatherMiddleware())->toContain('auth:admin')->toContain('web');
    }

    // And over HTTP, with no session at all.
    $id = cpePage()->id;

    test()->getJson('/admin-api/page-editor-bootstrap')->assertStatus(401);
    test()->getJson('/admin-api/page-editor-list')->assertStatus(401);
    test()->getJson('/admin-api/page-editor-load/'.$id)->assertStatus(401);
    test()->postJson('/admin-api/page-editor-save/'.$id, cpePayload(['title' => 'Hijacked']))
        ->assertStatus(401);

    expect(cpePage()->title)->toBe('Frequently Asked Questions');
});

it('gives every one of its endpoints its own capability, and fails closed', function () {
    /*
     * CLAUDE.md rule 5. AdminCapabilities::for() returns null for a route it has
     * never heard of, and null is owner-only — so an unmapped endpoint here would
     * work for the owner, silently refuse a manager, and nobody would notice
     * until a manager tried to fix the returns policy.
     *
     * `pages.manage` and not `content.manage`: rewriting the terms of sale is a
     * stronger thing to hand out than the mega menu, and it must be narrowable
     * without narrowing that with it.
     *
     * MUTATION, RUN: delete the four RULES rows from AdminCapabilities and every
     * for() below answers null.
     */
    foreach (PageEditorRoutes::registered() as $route) {
        expect(AdminCapabilities::for($route))->toBe(
            'pages.manage',
            $route->uri().' has no capability of its own'
        );
    }

    expect(AdminCapabilities::CAPABILITIES['pages.manage'])->toBe(['owner', 'manager', 'editor'])
        // Reading which pages exist stays where it was, exactly as GET
        // admin-api/posts does for the Journal.
        ->and(AdminCapabilities::forPath('GET', 'admin-api/pages/user'))->toBe('content.manage');
});

it('lets an editor rewrite a page and refuses a support account', function () {
    /*
     * The map is only worth anything if the request obeys it. Driven over HTTP,
     * both directions, because a rule that is never enforced and a rule that
     * refuses everybody look identical in a green run.
     *
     * MUTATION, RUN: add 'support' to the pages.manage row and the second half
     * goes red; remove 'editor' and the first half does.
     */
    $id = cpePage()->id;

    test()->actingAs(cpeAdmin('editor'), 'admin');
    test()->postJson('/admin-api/page-editor-save/'.$id, cpePayload(['title' => 'By the editor']))
        ->assertOk();

    expect(cpePage()->title)->toBe('By the editor');

    test()->actingAs(cpeAdmin('support'), 'admin');
    test()->postJson('/admin-api/page-editor-save/'.$id, cpePayload(['title' => 'By support']))
        ->assertForbidden();
    test()->getJson('/admin-api/page-editor-list')->assertForbidden();

    expect(cpePage()->title)->toBe('By the editor');
});

it('leaves no public endpoint that can read or write a page', function () {
    /*
     * CLAUDE.md: /api/* is unauthenticated, and `pages.seo` would be exactly the
     * kind of bag that leaks through a projection somebody added a column to
     * later. There is no /api route for pages today and this lane adds none —
     * asserted over the router, because the point is that none EXISTS rather
     * than that none is written in a file this lane happened to look at.
     *
     * MUTATION, RUN: register `Route::get('/pages', …)` in routes/api.php and
     * this names it.
     */
    $public = [];

    foreach (Route::getRoutes() as $route) {
        $uri = ltrim($route->uri(), '/');

        if (! str_starts_with($uri, 'api/')) {
            continue;
        }

        if (str_contains(strtolower((string) $route->getActionName()), 'page')
            || str_contains($uri, 'page')) {
            $public[] = implode('|', array_diff($route->methods(), ['HEAD'])).' /'.$uri;
        }
    }

    expect($public)->toBe([]);
});

/* ================= 9. the Google preview, on the fifth screen ============== */

it('previews a content page exactly as the content page renders it', function () {
    /*
     * LANE S7's preview reached four editors and its own report said why not the
     * fifth: "Pages still cannot have one, because there is no page editor to put
     * it in." This is that editor, so `page` is a kind now — and a kind is only
     * worth having if it answers the bytes the page really publishes.
     *
     * Pinned the way SeoRowPreviewTest pins the other four: fetch the real page
     * and compare. An EMPTY box is answered by a sub-request to the row's own URL
     * — which is exactly why the preview shows the owner the double-escaped
     * <title> his page really serves rather than a tidier one this endpoint
     * invented.
     *
     * ── AND ONE ASSERTION THIS CASE DID NOT HAVE, FOUND BY ITS OWN MUTATION ─
     *
     * The note here used to say that changing the `page` arm's `type` to 'article'
     * would make `published_known` and the empty-box answer stop agreeing with the
     * page. It does not: measured, mutation 22 of this lane's run left this file
     * and SeoBackOfficeWiringTest 58 passed. Everything /admin-api/seo-preview
     * RETURNS — title, description, url, published_known — goes through the title
     * and description engines, and 'page' and 'article' are treated alike by both.
     *
     * `type` is not inert on the real page, though, which is why the arm having a
     * wrong one matters: Support\Seo gates the FAQPage node on
     * `($ctx['type'] ?? '') === 'page'`, and Store\PageController::show() sets
     * exactly that. So the context is pinned DIRECTLY against the controller it
     * claims to quote, by reading the page's own $seoCtx off the rendered view and
     * the preview's off its private builder. An arm that drifts from show() is red
     * whichever side moves.
     *
     * MUTATION, RUN: change the `page` arm's `type` to 'article' — 1 failed. Green
     * before this assertion existed.
     */
    \Tests\Support\SeoBackOfficeRoutes::wire(app());

    test()->actingAs(cpeAdmin(), 'admin');

    $page = cpePage();

    // Empty boxes: the answer is the page's own tags, read off a real response.
    $live = cpeHead('/faqs/');
    preg_match('#<title>(.*?)</title>#s', $live, $liveTitle);
    preg_match('#<meta name="description" content="(.*?)"#s', $live, $liveDesc);

    $preview = test()->postJson('/admin-api/seo-preview', [
        'kind' => 'page',
        'id' => $page->id,
        'title' => '',
        'description' => '',
    ])->assertOk();

    expect($preview->json('title'))
        ->toBe(html_entity_decode($liveTitle[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'))
        ->and($preview->json('description'))
        ->toBe(html_entity_decode($liveDesc[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'))
        ->and($preview->json('url'))->toBe('https://kbeautybliss.test/faqs/')
        ->and($preview->json('published_known'))->toBeTrue()
        ->and($preview->json('title_is_yours'))->toBeFalse();

    // A filled box: resolved through the real title engine, and it matches what
    // the page publishes once it is saved.
    $typed = 'Delivery and returns, answered';

    $preview = test()->postJson('/admin-api/seo-preview', [
        'kind' => 'page',
        'id' => $page->id,
        'title' => $typed,
        'description' => 'Everything about delivery and returns.',
    ])->assertOk();

    expect($preview->json('title'))->toBe($typed)
        ->and($preview->json('title_is_yours'))->toBeTrue();

    cpeSave(['seo' => ['title' => $typed, 'desc' => 'Everything about delivery and returns.']])
        ->assertOk();

    preg_match('#<title>(.*?)</title>#s', cpeHead('/faqs/'), $after);

    expect(html_entity_decode($after[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'))
        ->toBe($preview->json('title'), 'the preview and the page disagree about the title');

    /*
     * The context the preview builds, against the context the page really
     * renders with. `type` is the key that gates the FAQPage node, so a preview
     * arm naming a different one is a preview of a document the shop does not
     * publish — and nothing the endpoint RETURNS would have shown it.
     */
    $ctx = (new ReflectionMethod(\App\Http\Controllers\Admin\SeoPreviewApiController::class, 'context'))
        ->invoke(app(\App\Http\Controllers\Admin\SeoPreviewApiController::class), 'page', '', '', [
            'name' => (string) $page->title,
            'description' => '',
            'path' => '/faqs/',
            'stored' => [],
        ]);

    $rendered = test()->get('/faqs/')->assertOk()->original->getData()['seoCtx'];

    expect($ctx['type'])->toBe($rendered['type'],
        'the preview arm and Store\\PageController::show() disagree about $seoCtx[type]; '
        .'Support\\Seo gates the FAQPage node on it')
        ->and($ctx['type'])->toBe('page');
});

/* ===================== 10. it ships inert, and it is wired once ============ */

it('changes no page by existing', function () {
    /*
     * RULE 1. Applying this package must move nothing: no page is created, no
     * page is edited, `seo` stays null on all seven rows, and the storefront
     * renders what it rendered before. The sanitiser runs on the way IN, so a
     * row nobody re-saves is byte-identical.
     *
     * MUTATION, RUN: have the clear_caches migration touch a page — a status, a
     * title, a seo default — and this is red.
     */
    /*
     * The two content migrations that keep a page's previous wording as ONE
     * hidden draft each: `about-previous` (Lane AB) and `contact-us-previous`
     * (Lane CT, 2027_10_15_120300_contact_us_support_copy). Never served, and
     * the seven routed pages are counted without them. (`about-previous` is
     * named here so this line is already right once int/448 is merged.)
     */
    expect(Page::query()->whereNotIn('slug', ['about-previous', 'contact-us-previous'])->count())->toBe(7)
        ->and(Page::query()->where('slug', 'contact-us-previous')->value('status'))->toBe('draft')
        ->and(Page::query()->whereNotNull('seo')->count())->toBe(0);

    foreach (RoutedPages::paths() as $path) {
        test()->get($path)->assertOk();
    }

    expect(Page::query()->where('slug', 'terms-and-conditions')->value('title'))
        ->toBe('Terms &amp; Conditions');
});

it('replaces the console\'s unescaped page table exactly once, and adds no second nav row', function () {
    /*
     * PIN THE FINISHED STATE — CLAUDE.md. Two of these are real failures and one
     * of them is a live admin XSS:
     *
     *   * ZERO assignments of window.renderUserPages: app.blade.php's own
     *     pagesTable() interpolates a page title straight into innerHTML, which
     *     was latent while titles only came from a migration and is exploitable
     *     the moment an operator can type one. This file is what stops that
     *     function being reachable.
     *   * TWO: the second would wrap or shadow the first, and the same shape one
     *     level up — a second window.go wrapper — is a failure CLAUDE.md records.
     *   * A nav entry of its own would show the owner two "User pages" rows,
     *     because 'pages-user' is already in NAV.
     *
     * Counted across every admin partial, not only this one, so the assertion is
     * "exactly one screen owns this function" rather than "this file does it
     * once".
     *
     * MUTATION, RUN: add a second `window.renderUserPages = list;` line to the
     * partial and this is red, naming the count.
     */
    $partial = (string) file_get_contents(
        resource_path('views/admin/partials/page-editor-screen.blade.php')
    );

    $assignments = 0;

    foreach (glob(resource_path('views/admin/partials/*.blade.php')) ?: [] as $file) {
        $assignments += substr_count((string) file_get_contents($file), 'window.renderUserPages =');
    }

    expect($assignments)->toBe(1, 'exactly one screen may own the User pages list');
    expect(substr_count($partial, 'window.KBBPageEditor ='))->toBe(1);
    expect(substr_count($partial, 'window.kbbAddNavEntry('))->toBe(0);

    // Lane S7's guard, because a package that ships this screen without the
    // preview partial must draw the editor rather than throw on open.
    expect(substr_count($partial, "typeof window.kbbSeoPreview === 'function'"))->toBe(1);
    expect(substr_count($partial, "mount: '#pg-seo-prev'"))->toBe(1);

    // And the screen is included at most once. It is the integrator who adds the
    // line (the route file's header carries it); asserting it is ABSENT would go
    // red the moment he does, so what is pinned is the failure that can happen
    // either side of that: twice.
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    $web = (string) file_get_contents(base_path('routes/web.php'));

    expect(substr_count($app, "@include('admin.partials.page-editor-screen')"))
        ->toBeLessThanOrEqual(1);
    expect(substr_count($web, "require __DIR__.'/page-editor-admin.php';"))
        ->toBeLessThanOrEqual(1);
});

it('leaves the page-builder columns alone in both directions', function () {
    /*
     * `doc_json`, `css` and `template` are null on every shipped row, and `css`
     * is a stylesheet printed into a page — the clickjacking primitive
     * PostEditorApiController refuses a `style` cover for. A screen that cannot
     * write them cannot be the way one gets written; a screen that BLANKED them
     * would destroy a page the Phase 11 builder had made.
     *
     * MUTATION, RUN: add 'css' to the validator and to fill() and the first
     * expectation goes red; call `$page->css = null` in fill() and the second
     * does.
     */
    Page::query()->where('slug', 'faqs')->update([
        'doc_json' => '{"blocks":[]}',
        'css' => '.policy{color:#333}',
        'template' => 'wide',
    ]);

    cpeSave([
        'title' => 'Still the FAQ',
        'doc_json' => '{"blocks":["injected"]}',
        'css' => 'body{position:fixed}',
        'template' => 'hijacked',
    ])->assertOk();

    $page = cpePage();

    expect($page->doc_json)->toBe('{"blocks":[]}')
        ->and($page->css)->toBe('.policy{color:#333}')
        ->and($page->template)->toBe('wide')
        ->and(PageTitle::decoded($page->title))->toBe('Still the FAQ');
});

it('does not widen the one sanitiser gap it found, and names it', function () {
    /*
     * FOUND AND NOT FIXED, recorded as a test so it cannot be forgotten.
     *
     * Content → Translations writes `pages.content` through
     * TranslationStore::put(), and that function's sanitiser is scoped to ONE
     * group: RICH_GROUP === 'products'. So the Arabic body of a page typed on
     * THAT screen is not cleaned, which is the live vector
     * docs/f2-operator-authored-html.md §5 measured two dialogs from on
     * /ar/about/. THIS editor cleans both languages, so it introduces no
     * asymmetry of its own — but the other door is still open, and closing it
     * means changing RICH_GROUP from a string to a map, in a file this lane does
     * not own and under a test that holds its RICH_FIELDS identical to the
     * product editor's.
     *
     * This asserts the SHAPE of the gap rather than the defect, so it goes red
     * when somebody fixes it and the comment can then be deleted.
     */
    expect(TranslationStore::RICH_GROUP)->toBe('products',
        'RICH_GROUP has changed shape — if pages.content is now sanitised there, delete this case');

    expect(PageEditorApiController::RICH_FIELDS)->toBe(['content']);
});

it('wraps the address cell on words when it holds a sentence', function () {
    /*
     * MEASURED AT 390px, IN CHROMIUM, ON THE REAL SCREEN — this is a defect the
     * screenshots found and no assertion would have.
     *
     * The address column holds one of two things. A PATH, which must be allowed to
     * break anywhere, because /everything-under-54-aed/ is wider than the column on
     * a phone. And a SENTENCE — "No address on the shop" — when the row has no
     * route at all, which is the loudest thing this screen says and the only one
     * that costs an indexed URL.
     *
     * `word-break:break-all` was on the CELL, so it applied to both, and the
     * sentence rendered as "No addres / s on the sh / op" at 390px. The rule now
     * sits on the <code> that carries the path, which is the only element that
     * needs it; the cell gets overflow-wrap:break-word, which breaks a long token
     * without chopping ordinary words.
     *
     * CSS, not JavaScript — rule 4. Nothing here measures an element.
     *
     * MUTATION, RUN: move `word-break:break-all` back onto `.pg-slugcell` and this
     * is red — 1 failed.
     */
    $partial = (string) file_get_contents(
        resource_path('views/admin/partials/page-editor-screen.blade.php')
    );

    expect((bool) preg_match('/\.pg-slugcell\{([^}]*)\}/', $partial, $cell))->toBeTrue();

    expect(str_contains($cell[1], 'word-break:break-all'))->toBeFalse(
        'break-all is back on the whole address cell, so "No address on the shop" '
        .'breaks mid-word at 390px'
    );

    expect(substr_count($partial, '.pg-slugcell code{word-break:break-all}'))->toBe(1,
        'the path itself still has to be allowed to break anywhere, or a long slug '
        .'widens the table on a phone');
});

it('sits where every file in this lane says it sits', function () {
    /*
     * RULE 3 — "Say where it sits in the admin", pinned against the console's own
     * nav rather than against prose.
     *
     * THE DEFECT THIS CAUGHT, and it was in this lane's own first pass: every
     * file here named the screen `Content → Pages → User pages`. There is no such
     * path. `Pages` is a TOP-LEVEL sidebar section in app.blade.php's NAV — it is
     * not under Content — and the console's own TITLES map spells the breadcrumb
     * for `pages-user` as exactly ['Pages','User pages']. An owner told to look
     * under Content would have opened Blog Posts, HTML Blocks and Media Library
     * and not found a page editor in any of them.
     *
     * It is asserted against app.blade.php because that file is the authority: it
     * draws the sidebar and the breadcrumb. A lane cannot edit it, which is
     * precisely why a lane must not contradict it.
     *
     * MUTATION, RUN: put "Content → Pages" back into any of the four files and
     * this is red, naming the file.
     */
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    // The console's own breadcrumb for this screen, read off its TITLES map.
    expect(str_contains($app, "'pages-user':['Pages','User pages']"))->toBeTrue(
        'app.blade.php no longer spells this screen\'s breadcrumb as Pages -> User pages; '
        .'re-read it and correct every file below rather than this expectation'
    );

    // And `Pages` really is a section of its own, not a row under Content.
    // Lane AP: the sidebar is App\Support\AdminNav's (server-rendered), not a NAV literal.
    $pages = array_values(array_filter(\App\Support\AdminNav::GROUPS, fn ($g) => $g['sec'] === 'Pages'));
    expect($pages[0]['rows'][0]['id'] ?? null)->toBe('pages-store');

    $named = [
        'app/Http/Controllers/Admin/PageEditorApiController.php',
        'routes/page-editor-admin.php',
        'resources/views/admin/partials/page-editor-screen.blade.php',
        'database/migrations/2027_02_24_000000_clear_caches_page_editor.php',
    ];

    $wrong = [];

    foreach ($named as $file) {
        $body = (string) file_get_contents(base_path($file));

        // Both spellings, because the migration's echo is plain ASCII.
        if (str_contains($body, 'Content → Pages') || str_contains($body, 'Content -> Pages')) {
            $wrong[] = $file;
        }

        // And each one says where it sits at all, which is the other half of
        // rule 3: a file that names no path cannot name a wrong one either.
        if (! str_contains($body, 'Pages → User pages') && ! str_contains($body, 'Pages -> User pages')) {
            $wrong[] = $file.' (names no admin path)';
        }
    }

    expect($wrong)->toBe([], "These files name the wrong admin path for this screen:\n  ".implode("\n  ", $wrong));
});
