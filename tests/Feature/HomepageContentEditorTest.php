<?php

declare(strict_types=1);

/**
 * Phase 15 — the homepage's words, and the screen that finally owns them.
 * (Lane FO)
 *
 * ── WHAT WAS WRONG ──────────────────────────────────────────────────────────
 *
 * Appearance → Homepage has owned which of the seventeen sections render, per
 * device, with which grid skin, for many releases. Nothing owned what they say.
 * Three keys were READ by the storefront and written by NOTHING in the tree —
 * not by AdminController::SETTING_RULES, not by any module endpoint, not by a
 * seeder, and by no ->set() anywhere:
 *
 *   home_banners   the hero slider: three slides compiled into HomeController,
 *                  carrying the page's only <h1>.
 *   about_text     the About us paragraph.
 *   home_ticker    the scrolling promo chip.
 *
 * The same fault TrustClaims was built for, one section along. The remedy is
 * the same: the shipped wording becomes the default, and clearing a box means
 * say nothing.
 *
 * ── EVERY TEST BELOW FAILS AGAINST THE TREE AS IT WAS ───────────────────────
 *
 * Checked by reverting, not assumed. The reverting is recorded per group:
 *
 *   §1  the endpoint did not exist, so the request 404s.
 *   §2  home.blade.php printed the headline through {!! !!}, so the tag lands
 *       in the document. This is the one that matters most: the editor is what
 *       turns that line from a literal in a controller into a box an owner
 *       types into, and the escaping is what makes the box safe.
 *   §3  Url::to() passes anything with a scheme through untouched, so a
 *       javascript: link reached the href.
 *   §4  HomeController used `?:`, which reads an empty slide list as "not set"
 *       and puts the shipped marketing copy back — so the one edit that removes
 *       it silently undid itself.
 *   §5  the About paragraph was printed unconditionally, so an emptied box
 *       rendered <p></p>.
 *   §6  no route existed for the capability map to resolve.
 *   §7  passes before AND after, and says so: it is the promise that a shop
 *       which saves nothing sees no change at all.
 *
 * A guard that asserts a setting round-trips proves nothing about the rendered
 * page, so every assertion here that matters reads the STOREFRONT HTML after
 * going through the admin endpoint. The round-trip is checked once, in §1, as
 * the precondition for the rest.
 */

use App\Services\HomepageContent;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use Tests\Support\HomepageContentAdminRoutes;

if (! function_exists('anAdminUser')) {
    function anAdminUser(): \App\Models\AdminUser
    {
        return \App\Models\AdminUser::create([
            'name' => 'A Owner',
            'email' => 'a-owner-' . uniqid() . '@example.test',
            'password' => 'secret-secret',
            'role' => 'owner',
        ]);
    }
}

/** Wire this lane's route file and sign in — the normal case. */
function asHomepageAdmin(): void
{
    HomepageContentAdminRoutes::wire(app());
    test()->actingAs(anAdminUser(), 'admin');
}

/** One slide, every field filled, so a test only says what it is testing. */
function foSlide(array $overrides = []): array
{
    return array_merge([
        'kicker' => 'Our own eyebrow',
        'heading' => "Our own headline\nsecond line",
        'text' => 'Our own supporting line.',
        'button' => 'Our own button',
        'url' => '/shop/',
        'bg_from' => '#112233', 'bg_mid' => '#445566', 'bg_to' => '#778899',
        'card_from' => '#AABBCC', 'card_to' => '#DDEEFF',
    ], $overrides);
}

function foHome(): string
{
    SettingsService::forgetMemo();

    return test()->get('/')->getContent();
}

/**
 * The hero band only.
 *
 * NOT the whole document, and the reason is a trap this lane fell into once:
 * "Age-R Booster Pro" is ALSO the name of a seeded catalogue product, so it is
 * on the page in the recommended rail whatever the hero says. An assertion that
 * the shop's unapproved marketing copy is gone has to look where that copy is,
 * or it is testing the catalogue.
 */
function foHero(): string
{
    $html = foHome();
    $at = strpos($html, 'id="slider"');

    if ($at === false) {
        return '';
    }

    $end = strpos($html, 'class="sdots"', $at);

    return substr($html, $at, ($end === false ? strlen($html) : $end) - $at);
}

/* ─────────────────────────────────────────────────────────── §1 it reaches */

it('puts the owner’s hero on the storefront, through the admin endpoint', function () {
    asHomepageAdmin();

    // The shipped slides are what a fresh shop shows.
    expect(foHero())->toContain('Medicube · limited-time offer');

    $response = $this->postJson('/admin-api/homepage/content', [
        'slides' => [foSlide()],
        'copy' => [],
    ]);

    $response->assertOk();
    expect($response->json('ok'))->toBeTrue();
    expect($response->json('rejected'))->toBe([]);

    // The round-trip, once, as the precondition for everything below it.
    expect($response->json('slides.0.values.kicker'))->toBe('Our own eyebrow');

    // And then the thing that actually matters: the rendered page.
    $hero = foHero();

    expect($hero)->toContain('Our own eyebrow');
    expect($hero)->toContain('Our own button');
    expect($hero)->toContain('<h1>Our own headline<br>second line</h1>');

    // The shop's unapproved marketing copy is gone from the band, not merely
    // pushed down it.
    expect($hero)->not->toContain('Medicube · limited-time offer');
    expect($hero)->not->toContain('Shop the Super Sale');
    expect($hero)->not->toContain('93 brands · sourced direct');
});

it('builds the slide’s gradients from validated colours and never from typed CSS', function () {
    asHomepageAdmin();

    $this->postJson('/admin-api/homepage/content', [
        'slides' => [foSlide(['bg_from' => '#010203', 'bg_mid' => '#040506', 'bg_to' => '#070809'])],
        'copy' => [],
    ])->assertOk();

    expect(foHero())->toContain('linear-gradient(118deg,#010203,#040506 58%,#070809)');
});

it('refuses a colour that is not one and says which field it refused', function () {
    asHomepageAdmin();

    $response = $this->postJson('/admin-api/homepage/content', [
        'slides' => [foSlide(['bg_mid' => 'red;position:fixed;inset:0'])],
        'copy' => [],
    ]);

    $response->assertOk();

    expect($response->json('rejected'))->toHaveKey('slide 1 · Background · middle');

    $hero = foHero();

    /*
     * Anchored on the WHOLE refused value, not on `position:fixed` — which is
     * in the storefront's own inlined stylesheet on every page and would have
     * failed this test for a reason that has nothing to do with it. The
     * class-name trap, one level along.
     */
    expect($hero)->not->toContain('red;position:fixed');

    // And the slide still rendered, with the field's default colour rather
    // than being dropped: a refused field must not cost the owner the slide.
    expect($hero)->toContain('Our own eyebrow');
    expect($hero)->toContain('#E0567B');
});

/* ──────────────────────────────────────────────── §2 the headline is escaped */

it('escapes a headline an owner types, and still renders the shipped line break', function () {
    asHomepageAdmin();

    $this->postJson('/admin-api/homepage/content', [
        'slides' => [foSlide(['heading' => "<script>alert(1)</script>\nsecond line"])],
        'copy' => [],
    ])->assertOk();

    $hero = foHero();

    // The sink, closed. Anchored on the tag rather than on the word, because
    // the word appears in this file and in the settings row either way.
    expect($hero)->not->toContain('<script>alert(1)</script>');
    expect($hero)->toContain('&lt;script&gt;');

    // And the newline is still a line break, which is what the shipped
    // headlines needed the raw echo for in the first place.
    expect($hero)->toContain('<br>second line');
});

it('escapes the eyebrow, the supporting line and the button wording too', function () {
    asHomepageAdmin();

    $this->postJson('/admin-api/homepage/content', [
        'slides' => [foSlide([
            'kicker' => '<b>k</b>',
            'text' => '<b>t</b>',
            'button' => '<b>u</b>',
        ])],
        'copy' => [],
    ])->assertOk();

    $hero = foHero();

    foreach (['<b>k</b>', '<b>t</b>', '<b>u</b>'] as $raw) {
        expect($hero)->not->toContain($raw);
    }

    expect($hero)->toContain('&lt;b&gt;k&lt;/b&gt;');
});

/* ─────────────────────────────────────────────────────── §3 the link is a path */

it('refuses a link that is not a page on this shop', function () {
    asHomepageAdmin();

    /*
     * `shop/` — a path with no leading slash — is in this list on purpose. It is
     * the honest mistake, not the attack: Url::to() would turn it into a link
     * relative to whatever page the shopper is standing on, so it works on the
     * home page and 404s from everywhere the hero is shortcoded.
     */
    foreach (['javascript:alert(1)', 'https://evil.test/', '//evil.test/', 'shop/'] as $bad) {
        $response = $this->postJson('/admin-api/homepage/content', [
            'slides' => [foSlide(['url' => $bad])],
            'copy' => [],
        ]);

        $response->assertOk();
        expect($response->json('rejected'))->toHaveKey('slide 1 · Where the slide links');
        expect($response->json('slides.0.values.url'))->toBe('/shop/');

        $hero = foHero();

        // The refused link is not the href, and the slide fell back to the
        // field default, so it is still a working link rather than a dead one.
        expect($hero)->not->toContain('href="' . $bad . '"');
        expect($hero)->toContain('href="/shop/"');
    }
});

it('keeps a query string on a path that is one', function () {
    asHomepageAdmin();

    $response = $this->postJson('/admin-api/homepage/content', [
        'slides' => [foSlide(['url' => '/shop/?on_sale=1'])],
        'copy' => [],
    ]);

    expect($response->json('rejected'))->toBe([]);
    expect(foHero())->toContain('/shop/?on_sale=1');
});

/* ────────────────────────────────────────────── §4 no slides means no slider */

it('lets the owner delete every slide without the shipped copy coming back', function () {
    asHomepageAdmin();

    $this->postJson('/admin-api/homepage/content', ['slides' => [], 'copy' => []])->assertOk();

    $html = foHome();

    expect($html)->not->toContain('Medicube · limited-time offer');
    expect($html)->not->toContain('93 brands · sourced direct');
    expect($html)->not->toContain('Shop the Super Sale');
    expect($html)->not->toContain('id="slider"');

    // The page keeps exactly one <h1>: with no hero to carry it, the quiet one
    // above the band is what renders. home.blade.php already had that branch;
    // this proves an emptied slider reaches it.
    expect(substr_count($html, '<h1'))->toBe(1);

    // And it survives a reload, which is the half `?:` used to undo.
    expect(foHome())->not->toContain('Medicube · limited-time offer');
});

/* ──────────────────────────────────────────── §5 the other wording, and empty */

it('gives the About paragraph and the promo chip an owner, and drops them when cleared', function () {
    asHomepageAdmin();

    $this->postJson('/admin-api/homepage/content', [
        'slides' => [foSlide()],
        'copy' => ['about_text' => 'We are a shop in Dubai.', 'home_ticker' => 'Our own chip'],
    ])->assertOk();

    $html = foHome();
    expect($html)->toContain('We are a shop in Dubai.');
    expect($html)->toContain('Our own chip');

    // Cleared means SAY NOTHING, not print an empty element.
    /*
     * The empty strings below are what the SCREEN sends, and they do not arrive
     * as empty strings: ConvertEmptyStringsToNull is global middleware in this
     * application, so a cleared box reaches the controller as null. That is why
     * `rejected` is asserted here and not just the rendering — the first
     * version of this feature reported "refused" for exactly this call and left
     * the shipped sentence on the shop's front page.
     */
    $cleared = $this->postJson('/admin-api/homepage/content', [
        'slides' => [foSlide()],
        'copy' => ['about_text' => '', 'home_ticker' => ''],
    ]);

    $cleared->assertOk();
    expect($cleared->json('rejected'))->toBe([]);

    $html = foHome();
    expect($html)->not->toContain('We are a shop in Dubai.');
    expect($html)->not->toContain('Our own chip');

    // The About band is still there — its heading, its counted figures and its
    // link — with no paragraph and no empty one either.
    expect($html)->toContain('About K-Beauty Bliss');
    expect($html)->not->toContain('<p></p>');
});

it('lets a slide field be cleared, which is what "empty hides it" promises', function () {
    asHomepageAdmin();

    /*
     * The SLIDE half of the cleared-box problem, which the copy tab's test
     * above covers for the other half. Both go through the same middleware —
     * ConvertEmptyStringsToNull turns every one of these into null before the
     * controller sees it — and both would report "refused" without
     * HomepageContent::emptyIsEmpty(). This one has a visible consequence the
     * screen states in as many words under the field: "Empty hides the button."
     */
    $response = $this->postJson('/admin-api/homepage/content', [
        'slides' => [foSlide(['kicker' => '', 'text' => '', 'button' => ''])],
        'copy' => [],
    ]);

    $response->assertOk();
    expect($response->json('rejected'))->toBe([]);

    $hero = foHero();

    // The button's pill is a white box with padding, so an empty one is a blank
    // lozenge on the banner rather than nothing. It is not rendered.
    expect($hero)->not->toContain('class="b"');

    // The headline is still there, so the slide has not been emptied by
    // accident, and the page still has exactly one <h1>.
    expect($hero)->toContain('Our own headline');
    expect(substr_count(foHome(), '<h1'))->toBe(1);

    // And a cleared field stays cleared across a reload rather than falling
    // back to the shipped wording.
    expect(foHero())->not->toContain('Medicube');
});

/* ─────────────────────────────────────────────────── §6 who may call it at all */

it('is refused to anyone who is not signed in, and is content.manage on the map', function () {
    HomepageContentAdminRoutes::wire(app());

    $paths = HomepageContentAdminRoutes::paths();

    // Read off the router, so a route added later is covered without this list
    // being remembered.
    expect($paths)->toHaveCount(2);

    foreach ($paths as [$method, $uri]) {
        $this->withHeaders(['Accept' => 'application/json'])
            ->json($method, $uri, ['slides' => []])
            ->assertStatus(401);

        // AdminCapabilities fails closed, so an unmapped path is a 403 for
        // every role but the owner. Both verbs resolve, write and read alike.
        expect(AdminCapabilities::forPath($method, ltrim($uri, '/')))->toBe('content.manage');
    }
});

/* ──────────────────────────── §7 a shop that changes nothing sees no change */

it('renders the three shipped slides byte for byte when nothing has been saved', function () {
    $html = foHome();

    $hero = foHero();

    foreach ([
        'Medicube · limited-time offer',
        '<h1>Age-R Booster Pro<br>with a free gift set</h1>',
        'The authentic<br>K-beauty store',
        'Pay later,<br>delivered in 1–3 days',
        'Shop Medicube',
        'Shop all brands',
        'Shop the Super Sale',
        '93 brands · sourced direct',
        'linear-gradient(118deg,#F7C6D4,#E0567B 58%,#A82F53)',
        'linear-gradient(150deg,#FFE6EE,#EFA8BE)',
        'linear-gradient(118deg,#CDE6DA,#3E8F6E 58%,#2A6A50)',
        'linear-gradient(118deg,#FFE1A8,#E8A33D 58%,#C07F1E)',
    ] as $shipped) {
        expect($hero)->toContain($shipped);
    }

    // Three slides, and the first one carries the page's only <h1>.
    expect(substr_count($hero, 'class="sl"'))->toBe(3);
    expect(substr_count($html, '<h1'))->toBe(1);
});

it('holds the defaults in one place, and they are the ones the page renders', function () {
    // The shape this pins is the one HomeController used to own: nothing else
    // in the tree may carry a second copy of the hero's words.
    $controller = (string) file_get_contents(base_path('app/Http/Controllers/Store/HomeController.php'));

    expect($controller)->not->toContain('Age-R Booster Pro');
    expect(HomepageContent::DEFAULT_SLIDES)->toHaveCount(3);

    $slides = app(HomepageContent::class)->slides();

    expect($slides[0]['gradient'])->toBe('linear-gradient(118deg,#F7C6D4,#E0567B 58%,#A82F53)');
    expect($slides[0]['heading'])->toBe("Age-R Booster Pro\nwith a free gift set");

    /*
     * THE BYTES THE SERVER IS SERVING TODAY, spelled out.
     *
     * The stored shape changed -- a literal `<br>` inside the heading became a
     * newline, so that the template can escape what an owner types -- and the
     * RENDERED shape did not. This is the assertion that says so, because
     * StorefrontEnglishUnchangedTest structurally cannot: it rolls
     * resources/views back to BASE_COMMIT and leaves the PHP in the working
     * tree, so its "before" is the OLD template echoing the NEW default raw,
     * which is a page that has never existed. Its diff for this lane is that
     * artefact and nothing else; BASE_COMMIT moves forward, which is what that
     * file's own header says to do.
     */
    expect(foHero())->toContain('<h1>Age-R Booster Pro<br>with a free gift set</h1>');
});

/* ───────────────────────────────── §8 the screen draws itself from the schema */

it('draws every control from the schema, naming no setting in the console', function () {
    $whole = (string) file_get_contents(
        base_path('resources/views/admin/partials/homepage-content-screen.blade.php')
    );

    // The CODE, not the file: the header explains what the screen is for and
    // names the three settings that had no owner, which is the paragraph a
    // reader most needs and the one an over-literal guard would delete.
    $screen = substr($whole, (int) strpos($whole, '<script>'));

    /*
     * THE POINT OF THE WHOLE LANE, PINNED.
     *
     * "Reusing the settings schemas" is not a claim a round-trip can prove. It
     * means the console draws what the SERVER describes and holds no list of
     * its own — so adding a field to HomepageContent makes a control appear
     * without this partial changing, and the control cannot disagree with the
     * validator, because both come off ModuleSchema.
     *
     * The way to check that is that no setting is NAMED in the screen. Every
     * key below is one the server sends; if any of them appears in the partial,
     * somebody has started keeping a second list there.
     */
    foreach (array_keys(HomepageContent::SLIDE_SCHEMA) as $key) {
        // 'text', 'url' and 'button' are ordinary English and ordinary DOM
        // words, so only the keys that could only be a setting are checked —
        // the colours, which are five of the ten and the ones a hand-written
        // control would have to name.
        if (! str_contains($key, '_')) {
            continue;
        }

        expect($screen)->not->toContain("'" . $key . "'");
    }

    foreach (array_keys(HomepageContent::SCHEMA) as $key) {
        expect($screen)->not->toContain($key);
    }

    // And it switches on the schema's own type vocabulary rather than on keys.
    foreach (['colour', 'textarea', 'select', 'bool'] as $type) {
        expect($screen)->toContain("f.type === '" . $type . "'");
    }
});
