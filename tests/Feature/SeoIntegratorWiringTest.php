<?php

declare(strict_types=1);

use App\Http\Middleware\CheckRedirects;
use App\Http\Middleware\ResolveLocaleSlugs;
use App\Models\Page;
use App\Models\Post;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\Seo;
use Illuminate\Contracts\Http\Kernel as KernelContract;

/**
 * =============================================================================
 * THE HALVES THE LANES COULD NOT WIRE THEMSELVES
 * =============================================================================
 *
 * Lane S6 built `Services\Seo\FaqSchema`, a canonical scheme check and two
 * Article keys; Lane S5 built `ResolveLocaleSlugs`, `Seo::readerAlternatePaths()`
 * and `LocaleSlugs::toDisplayPath()`. Each lane said, in its own report and in
 * its own words, that its tests are **green either way** — because the other
 * half of every one of those changes sits in a file the lane does not own:
 * `AppServiceProvider`, `Url.php`, `SeoFilesController`, `PageController`,
 * `Seo.php`, `AdminController` and `app.blade.php`.
 *
 * So six features were complete, tested, merged and emitting nothing, and no
 * assertion anywhere would have noticed. That is the exact shape CLAUDE.md
 * records as "built, never wired up" — the article editor, the homepage preview
 * and the shoppable-video screen, three times in one day.
 *
 * ── WHAT THIS FILE IS, AND WHAT IT DELIBERATELY IS NOT ──────────────────────
 *
 * It pins the FINISHED state, never the absence of one. CLAUDE.md is explicit
 * about why: an assertion that a lane's work is NOT mounted goes red the moment
 * the integrator does the one thing the lane asked for, and the only way to
 * green it as written is to UNMOUNT the feature. Every case below is green on
 * the wired tree and red on the unwired one — which is the direction that can
 * actually regress, because the wiring is one line in somebody else's file and
 * a merge conflict resolved the wrong way drops it silently.
 *
 * Every mutation note names the line to delete and what goes red.
 */
function siwSettings(array $values = []): void
{
    foreach (array_merge([
        'site_url' => 'https://kbeautybliss.test',
        'seo_site_name' => 'K-Beauty Bliss',
        'seo_default_description' => 'Korean skincare, delivered across the UAE.',
        'sitemap_enabled' => '1',
    ], $values) as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => (string) $value, 'autoload' => true]);
    }

    Setting::flushMap();
    SettingsService::forgetMemo();
}

beforeEach(function () {
    siwSettings();
});

/**
 * A content page written as questions. Two pairs, because
 * FaqSchema::MIN_QUESTIONS is 2 and one question is a heading, not an FAQ.
 */
function siwFaqPage(): Page
{
    return Page::updateOrCreate(
        ['slug' => 'faqs'],
        [
            'title' => 'Frequently Asked Questions',
            'status' => 'published',
            'content' => '<h2>How much is delivery?</h2>'
                . '<p>Charges are calculated for your address at checkout and shown in full before you pay.</p>'
                . '<h2>Do you accept returns?</h2>'
                . '<p>We do not accept returns at this time.</p>'
                . '<h2>Our promise</h2>'
                . '<p>This heading is not a question, so it is not a pair.</p>',
            'seo' => null,
        ]
    );
}

/** Every JSON-LD graph in a page's head, decoded. */
function siwGraphs(string $path): array
{
    $html = test()->get($path)->assertOk()->getContent();

    expect((bool) preg_match('#<head>(.*?)</head>#s', $html, $m))
        ->toBeTrue($path . ' rendered no <head>; the parse is wrong, not the assertion.');

    preg_match_all(
        '#<script type="application/ld\+json">(.*?)</script>#s',
        $m[1],
        $scripts
    );

    $out = [];

    foreach ($scripts[1] as $json) {
        $decoded = json_decode($json, true);

        expect($decoded)->not->toBeNull('a ld+json block on ' . $path . ' is not valid JSON');

        // The graph is emitted either as one node or as a list of them.
        foreach (array_is_list($decoded) ? $decoded : [$decoded] as $node) {
            $out[] = $node;
        }
    }

    return $out;
}

/** The nodes of one @type in a page's graph. */
function siwNodes(string $path, string $type): array
{
    return array_values(array_filter(
        siwGraphs($path),
        fn (array $n) => ($n['@type'] ?? null) === $type
    ));
}

/** The <head> of a page, so a match cannot come from the body or a script. */
function siwHead(string $path): string
{
    $html = test()->get($path)->assertOk()->getContent();

    expect((bool) preg_match('#<head>(.*?)</head>#s', $html, $m))->toBeTrue($path . ' rendered no <head>');

    return $m[1];
}

/* ─────────────────────── rule 1 before anything else ────────────────────── */

it('publishes no FAQ node on the shop as it ships', function () {
    /*
     * RULE 1, FIRST CASE. `faq_schema` ships at '0' and no row is seeded, so
     * applying the package that carries all of this must add nothing to the
     * seven content pages Search Console has already fetched. The page below is
     * written as questions on purpose: if the flag were being ignored, THIS is
     * the page that would sprout a node.
     */
    siwFaqPage();

    expect(siwNodes('/faqs/', 'FAQPage'))->toBe([]);

    // And the flag really is the only thing standing between them, which the
    // next case proves by turning it on and nothing else.
    expect(\App\Services\Seo\FaqSchema::pairs((string) siwFaqPage()->content))->toHaveCount(2);
});

/* ───────────── S6 section 5.3 — FaqSchema reaches the graph ─────────────── */

it('publishes a FAQPage node once the flag is on, and only on a content page', function () {
    /*
     * BOTH HALVES OR NOTHING. `FaqSchema` was complete, tested and emitted
     * nothing: the one place a node can join the graph is `Seo::jsonLd()`, and
     * the two keys it reads (`type` and `page_body`) are built by
     * `Store\PageController::show()`.
     *
     * MUTATION: delete either the `=== 'page'` block in Seo::jsonLd() or the two
     * $seoCtx keys in PageController::show() — this case is red on either one
     * alone, which is what makes it the pin for a pair of one-line changes in
     * two different files.
     */
    siwSettings(['faq_schema' => '1']);
    siwFaqPage();

    $faq = siwNodes('/faqs/', 'FAQPage');

    expect($faq)->toHaveCount(1);

    $node = $faq[0];

    // The questions, in the page's own order, and NOT the heading that does not
    // end in a question mark.
    $questions = array_map(fn (array $q) => $q['name'], $node['mainEntity']);

    expect($questions)->toBe(['How much is delivery?', 'Do you accept returns?']);

    expect($node['mainEntity'][1]['acceptedAnswer']['text'])
        ->toBe('We do not accept returns at this time.');

    // The node identifies itself with this page's own absolute canonical, not a
    // root-relative path Google reports as invalid.
    expect($node['url'])->toBe('https://kbeautybliss.test/faqs/');

    /*
     * ONE GRAPH, NOT TWO. SEO-BUILD-PLAN item 3 refuses a second emitter
     * because two emitters is two Organization nodes on every page. The FAQ node
     * is merged into the graph this application already builds, so the count of
     * Organization nodes is unchanged by turning the flag on.
     */
    expect(siwNodes('/faqs/', 'Organization'))->toHaveCount(1);
});

it('publishes no FAQ node on a page that is not written as questions, flag on', function () {
    /*
     * The flag says WHETHER; FaqSchema says WHAT. A shop that ticks the box does
     * not get an empty FAQPage on its Terms page — and this is the case that
     * would catch `type => 'page'` being passed with a `page_body` that is not
     * the page's body, because the wrong body yields no pairs and no node.
     */
    siwSettings(['faq_schema' => '1']);

    Page::updateOrCreate(
        ['slug' => 'terms-and-conditions'],
        [
            'title' => 'Terms',
            'status' => 'published',
            'content' => '<h2>Our terms</h2><p>Prose, with no question anywhere in it.</p>',
            'seo' => null,
        ]
    );

    expect(siwNodes('/terms-and-conditions/', 'FAQPage'))->toBe([]);
});

it('publishes no FAQ node on a product or an article, flag on', function () {
    /*
     * `type` is the guard, and it is an EQUALITY against 'page' rather than a
     * negative list, so a surface that has never heard of the flag cannot grow a
     * node. A product description written as questions is a real thing.
     */
    siwSettings(['faq_schema' => '1']);

    Post::create([
        'slug' => 'siw-questions',
        'title' => 'Five questions about cleansing',
        'body' => '<h2>How much is delivery?</h2><p>Calculated at checkout.</p>'
            . '<h2>Do you accept returns?</h2><p>No.</p>',
        'status' => 'published',
        'published_at' => now()->subDay(),
    ]);

    expect(siwNodes('/siw-questions/', 'FAQPage'))->toBe([]);
});

/* ──────── S6 section 5.1 — the Article node's url and dateModified ──────── */

it('gives an Article a url and a dateModified', function () {
    /*
     * `dateModified` is on Google's recommended list for Article and
     * posts.updated_at was already on the row. Both halves again: the key on the
     * node is in `Seo::jsonLd()`, the value is put in the `article` array by
     * `Store\PageController::post()`.
     *
     * MUTATION: drop 'updated_at' from PageController::post()'s article array
     * and `dateModified` disappears entirely rather than arriving null, because
     * jsonLd() wraps the node in array_filter() — which is exactly why the
     * Seo half alone is inert and why this case is the pin for the pair.
     */
    $post = Post::create([
        'slug' => 'siw-dated',
        'title' => 'A dated article',
        'body' => '<p>Body.</p>',
        'status' => 'published',
        'published_at' => now()->subDays(3),
    ]);

    $post->forceFill(['updated_at' => now()->subDay()])->saveQuietly();

    $article = siwNodes('/siw-dated/', 'Article');

    expect($article)->toHaveCount(1);

    expect($article[0])->toHaveKeys(['url', 'datePublished', 'dateModified']);

    expect($article[0]['url'])->toBe('https://kbeautybliss.test/siw-dated/');

    // Atom, the same format datePublished is already in, and genuinely the row's
    // own value rather than "now".
    expect($article[0]['dateModified'])->toBe($post->fresh()->updated_at->toAtomString());

    expect($article[0]['dateModified'])->not->toBe($article[0]['datePublished']);
});

/* ───── S6 section 5.2 — a canonical override is scheme-checked ──────────── */

it('refuses to publish a javascript: canonical override anywhere in the head', function () {
    /*
     * `Seo::canonicalAbsolute()` returned any `scheme:` value verbatim, so a
     * per-row canonical of `javascript:alert(1)` was published in
     * <link rel="canonical">, og:url, the node identifier and every hreflang
     * href — on products, categories, brands, posts and content pages alike.
     *
     * SEVERITY IS LOW AND IS STATED AS LOW: a rel=canonical href is not
     * navigable and everything is htmlspecialchars'd with ENT_QUOTES, so it
     * cannot break out of the attribute. The reason it is fixed is CLAUDE.md
     * rule 5, in as many words: "A URL from a setting is scheme-checked before
     * it becomes an href."
     *
     * MUTATION: widen the regex in canonicalAbsolute() back to
     * '#^([a-z][a-z0-9+.-]*:|//)#i' and the string appears in the head.
     */
    Page::updateOrCreate(
        ['slug' => 'faqs'],
        [
            'title' => 'Frequently Asked Questions',
            'status' => 'published',
            'content' => '<p>Body.</p>',
            'seo' => ['canonical' => 'javascript:alert(1)'],
        ]
    );

    $head = siwHead('/faqs/');

    /*
     * THE ASSERTION IS ABOUT THE SCHEME, NOT THE SUBSTRING, and the first draft
     * of this test got that wrong: `not->toContain('javascript:')` went red on
     * the FIXED tree, because the value falls through to the path branch and is
     * published as https://kbeautybliss.test/javascript:alert(1) — an address on
     * THIS site, in which "javascript:" is a legitimate substring. Measured, not
     * assumed. What must never appear is javascript as the scheme of an href.
     */
    expect($head)->not->toContain('href="javascript:')
        ->not->toContain('content="javascript:');

    // Not merely stripped: the page still declares a canonical, on this site,
    // rather than declaring none — which would be its own regression.
    expect($head)->toContain('<link rel="canonical" href="https://kbeautybliss.test/javascript:alert(1)">');
});
/*
 * NO ->skip() GUARD ON THE CASE ABOVE, and the first draft had one: it skipped
 * itself unless the narrowed regex was present in Seo.php. Running the mutation
 * is what exposed it — widening the regex back made the case SKIP rather than
 * FAIL, so the guard disarmed the only assertion protecting the fix. That is the
 * shape tests/Feature/ExpectationsThatCannotFailTest.php exists to find, and it
 * was written here out of defensiveness about a change that had already landed.
 */

it('still publishes an http and a protocol-relative canonical override verbatim', function () {
    /*
     * THE OTHER HALF OF THE SAME RULE, so the narrowed regex cannot quietly
     * break the feature it guards. A CDN or a cross-domain canonical an operator
     * really typed must still work, and `//` must still mean protocol-relative
     * rather than a path on this site.
     */
    foreach ([
        'https://kbeautyarabia.example/products/x' => 'https://kbeautyarabia.example/products/x',
        'http://kbeautyarabia.example/products/x' => 'http://kbeautyarabia.example/products/x',
        '//kbeautyarabia.example/products/x' => '//kbeautyarabia.example/products/x',
    ] as $saved => $published) {
        Page::updateOrCreate(
            ['slug' => 'faqs'],
            [
                'title' => 'Frequently Asked Questions',
                'status' => 'published',
                'content' => '<p>Body.</p>',
                'seo' => ['canonical' => $saved],
            ]
        );

        expect(siwHead('/faqs/'))
            ->toContain('<link rel="canonical" href="' . $published . '">');
    }
});

/* ───────── S5 — ResolveLocaleSlugs is mounted, once, after redirects ────── */

it('mounts ResolveLocaleSlugs in the real kernel exactly once', function () {
    /*
     * PIN THE FINISHED STATE, NOT THE ABSENCE OF ONE. CLAUDE.md is explicit:
     * `substr_count(...) === 1` is green in the lane's worktree the day it is
     * written AND after the integrator wires it, while `not->toContain` is green
     * only until the wiring lands.
     *
     * Exactly once matters on both sides. Zero is "built, never wired up" — the
     * class serves no Arabic address and `tests/Support/LocaleSlugMiddleware`
     * quietly covers for it in the suite, so nothing would go red. Two runs the
     * rewrite twice on one request.
     *
     * The stack is read from the container's own kernel WITHOUT calling
     * LocaleSlugMiddleware::wire(), which is the whole point: the harness's
     * registrar is idempotent and would hide a missing mount.
     *
     * MUTATION: delete the pushMiddleware line from AppServiceProvider::boot()
     * and this is red while every one of Lane S5's own cases stays green.
     */
    $kernel = app(KernelContract::class);

    $property = new \ReflectionProperty($kernel, 'middleware');
    $property->setAccessible(true);

    $stack = array_values($property->getValue($kernel));

    expect(array_count_values(array_map('strval', $stack))[ResolveLocaleSlugs::class] ?? 0)
        ->toBe(1, 'ResolveLocaleSlugs must be mounted exactly once in the global stack');

    /*
     * AND AFTER CheckRedirects, which is why the registration is
     * `pushMiddleware` and not `prependMiddleware`. The retrofit writes a
     * redirect from the English address to the Arabic one; run the rewrite first
     * and it turns the Arabic address back into the English one, CheckRedirects
     * forwards that to the Arabic one, and the visitor bounces for ever.
     */
    $resolve = array_search(ResolveLocaleSlugs::class, $stack, true);
    $redirects = array_search(CheckRedirects::class, $stack, true);

    expect($redirects)->not->toBeFalse('CheckRedirects is not mounted at all');
    expect($resolve)->toBeGreaterThan($redirects);
});

/* ───────── S5 section 7.3 — the sitemap asks Seo, not Locale ──────────── */

it('builds sitemap hreflang alternates through Seo rather than Locale', function () {
    /*
     * `Locale::alternatePaths()` takes a PATH and no row, so with the
     * Arabic-slug policy on it answers the SHARED address under /ar while the
     * page itself canonicalises to the Arabic one. A cluster that disagrees with
     * itself about what page it is gets dropped, and the sitemap is the half
     * Google reads first. docs/SEO-ARABIC-SLUGS.md section 7.3, and Lane S5's
     * standing instruction: do not switch the policy on before this is done.
     *
     * Byte-identical while the policy is `shared`, which is today — so the
     * assertion that can actually hold is that the call goes through the one
     * choke point, and that the two agree on this tree.
     *
     * MUTATION: put `Locale::alternatePaths($path)` back and the first
     * expectation is red.
     */
    $source = (string) file_get_contents(base_path('app/Http/Controllers/Store/SeoFilesController.php'));

    // The one live call site. Comments elsewhere in this file and in
    // BrandController still NAME Locale::alternatePaths, which is why this
    // counts the assignment rather than the mention.
    expect(substr_count($source, '$alternates = \App\Support\Seo::readerAlternatePaths($path);'))->toBe(1);
    expect(substr_count($source, '$alternates = Locale::alternatePaths($path);'))->toBe(0);

    // And the two really do agree today, so the swap moved no byte of
    // /sitemap.xml on this shop.
    foreach (['/', '/shop/', '/faqs/'] as $path) {
        expect(Seo::readerAlternatePaths($path))->toBe(\App\Support\Locale::alternatePaths($path));
    }
});

/* ───────── S5 section 5 — an internal link in the reader's spelling ─────── */

it('costs an English render nothing for the Arabic display-path lookup', function () {
    /*
     * Url::to() is called around two hundred times on a storefront page, and the
     * display-path lookup sits INSIDE the existing `Locale::segment() !== ''`
     * fast path for exactly that reason: the guard is empty on every English
     * page, which is every page this shop serves today, so an English render
     * does not pay even the cached settings read that LocaleSlugs::translating()
     * would cost.
     *
     * MUTATION: hoist the toDisplayPath() call above the `if` and this is red —
     * which is the mistake worth pinning, because hoisting it is the obvious
     * "tidier" refactor and it puts a settings read on 200 calls per page.
     */
    $source = (string) file_get_contents(base_path('app/Support/Url.php'));

    expect((bool) preg_match(
        '#if \(Locale::segment\(\) !== \'\'\) \{(.*?)\n        \}#s',
        $source,
        $m
    ))->toBeTrue('the Url::to() fast-path branch is not where this test expects it');

    expect($m[1])->toContain('LocaleSlugs::toDisplayPath');

    // The English answer is unchanged, which is rule 1 measured rather than
    // asserted: identical strings, not merely both non-empty.
    expect(\App\Support\Url::to('/product/anua-heartleaf-toner/'))
        ->toBe('/product/anua-heartleaf-toner/');
});

/* ───────── the two admin controls exist and can be saved ──────────────── */

it('accepts faq_schema and merchant_returns on the settings endpoint', function () {
    /*
     * A KEY ABSENT FROM SETTING_RULES IS A KEY THE SCREEN POSTS AND THE SERVER
     * SILENTLY DROPS — "Saved" on screen, no row in the table, which is the
     * failure SeoVerificationTagsTest was written after. Both keys are new this
     * round and both have a control on the SEO screen, so both must round-trip.
     *
     * MUTATION: remove either entry from AdminController::SETTING_RULES and the
     * matching expectation is red.
     */
    /*
     * AdminUser on the `admin` GUARD, which is the console's own, and not
     * User::factory() on the default one. Two things the first draft got wrong
     * and both were measured rather than reasoned about: User::factory() throws
     * "Class Faker\Factory not found" (Faker is not in this checkout's
     * dependency set), and a default-guard actingAs() gets 401 from this
     * endpoint — which is the endpoint failing closed, exactly as it should.
     */
    $admin = \App\Models\AdminUser::create([
        'name' => 'SIW Owner',
        'email' => 'siw-owner-' . uniqid() . '@example.test',
        'password' => 'password-long-enough',
        'role' => 'owner',
    ]);

    test()->actingAs($admin, 'admin')
        ->putJson('/admin-api/settings', ['settings' => [
            'faq_schema' => '1',
            'merchant_returns' => 'MerchantReturnNotPermitted',
        ]])
        ->assertOk();

    Setting::flushMap();
    SettingsService::forgetMemo();

    expect(Setting::where('key', 'faq_schema')->value('value'))->toBe('1');
    expect(Setting::where('key', 'merchant_returns')->value('value'))->toBe('MerchantReturnNotPermitted');

    /*
     * Asserted against the constant AS WELL AS the round trip, the way
     * SiteTitleSettingTest does: the round trip alone would also pass if the
     * endpoint stopped allowlisting keys at all, which is the opposite defect
     * and a worse one on an endpoint that writes settings.
     */
    expect(\App\Http\Controllers\Admin\AdminController::SETTING_RULES)
        ->toHaveKey('faq_schema')
        ->toHaveKey('merchant_returns');

    expect(\App\Http\Controllers\Admin\AdminController::SETTING_RULES['faq_schema'][0])->toBe('flag');
    expect(\App\Http\Controllers\Admin\AdminController::SETTING_RULES['merchant_returns'][0])->toBe('enum');
});

it('draws a control for every setting key the SEO screen can post', function () {
    /*
     * THE OTHER DIRECTION, WHICH IS THE ONE NOTHING WALKED. A key in
     * SETTING_RULES with no control is a setting the owner cannot reach, and
     * CLAUDE.md rule 3 says every patch that adds a control names its exact
     * path. These two are at:
     *
     *   Store -> SEO & Meta -> Settings -> Sitemap & robots -> "FAQ markup on content pages"
     *   Store -> SEO & Meta -> Settings -> Google Merchant -> "Returns policy"
     *
     * MUTATION: delete either smField() call from app.blade.php and this is red.
     * `substr_count === 1` and not `toContain`, because two copies of a control
     * register the same id twice and the second silently wins.
     */
    $blade = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    foreach ([
        "smField('seo_faq_schema','FAQ markup on content pages'",
        "smField('seo_merch_returns','Returns policy'",
    ] as $control) {
        expect(substr_count($blade, $control))->toBe(1, $control . ' is not drawn exactly once');
    }

    // And each one's value reaches the save payload, which is the half that is
    // invisible on screen: a control that renders and is not in the payload
    // looks saved and is not.
    foreach (["faq_schema:sval('seo_faq_schema')", "merchant_returns:sval('seo_merch_returns')"] as $posted) {
        expect(substr_count($blade, $posted))->toBe(1, $posted . ' is not posted exactly once');
    }

    /*
     * AND `seo_arabic_slugs` IS DELIBERATELY NOT ON THE ORDINARY SAVE. Changing
     * it is a change of ADDRESS, not of a setting: the redirects for all 730
     * rows that move have to be written in the same transaction, or a shopper's
     * bookmark 404s. It gets its own screen, its own button and its own confirm
     * — docs/SEO-ARABIC-SLUGS.md section 6. This is a pin on a DELIBERATE
     * absence from one payload, not on a feature being unwired.
     */
    expect(substr_count($blade, "seo_arabic_slugs:sval("))->toBe(0);
});
