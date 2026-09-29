<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Services\PageWash;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use Illuminate\Http\Request;
use Tests\Support\PageWashRoutes;

/**
 * Appearance → Page background: off by default, the gate, and the endpoints.
 *                                                                    (Lane BG)
 *
 * routes/page-wash-admin.php is required from routes/web.php by the INTEGRATOR
 * — no lane may edit that file — so the route file is declared and left
 * unwired, and Tests\Support\PageWashRoutes mounts it here from the real file
 * with the real middleware stack. That is deliberate rather than convenient:
 * this suite exercises the file the integrator will require, including its
 * names and its ordering, so a typo in it fails here rather than after a
 * package has been applied to the live shop.
 *
 * ▲ THIS FILE PINS THE FINISHED STATE, NEVER THE UNWIRED ONE — see
 * PageWashScreenTest, which carries the `=== 1` counts, and CLAUDE.md's "Rules
 * for parallel work" for the three days this project lost to the other shape.
 */
function bgAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'Wash '.$role,
        'email' => 'wash-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => $role,
    ]);
}

beforeEach(function () {
    PageWashRoutes::wire($this->app);
});

/* ═════════════════════════════════════════ rule 1: off by default ═══ */

it('sends not one byte until somebody switches it on', function () {
    /*
     * The whole of rule 1 for this package, and it is checkable rather than
     * promised: `wash_on` is absent, absent means the shipped default, the
     * shipped default is FALSE, and css() answers the empty string — so the
     * layout's conditional never opens and the <style> element does not exist.
     *
     * MUTATION: change SCHEMA['on']'s default from false to true and this fails
     * on the first assertion, and every storefront page below grows a
     * 1,785-byte stylesheet it did not have.
     */
    $wash = app(PageWash::class);

    expect($wash->get('on'))->toBeFalse()
        ->and($wash->css())->toBe('')
        ->and($wash->isDefault())->toBeTrue();
});

it('leaves the rendered head of every storefront page exactly as it was', function () {
    /*
     * Not the same assertion as the one above in a different costume: that one
     * is about the SERVICE and this one is about the six documents that include
     * it. A partial that emits nothing can still cost a page a blank line, and
     * a blank line on forty pages is precisely what
     * StorefrontEnglishUnchangedTest exists to report. See the whitespace note
     * in resources/views/partials/page-wash-css.blade.php.
     */
    foreach (['/', '/shop/', '/cart/', '/skincare-guide/'] as $path) {
        $html = $this->get($path)->getContent();

        expect(str_contains($html, 'kbb-page-wash'))
            ->toBeFalse($path.' carries the wash with nothing switched on');
        expect(str_contains($html, 'kbb-wash-a'))
            ->toBeFalse($path.' carries the keyframes with nothing switched on');
    }
});

/* ══════════════════════════════════════════════════ the preview gate ═══ */

it('ignores the preview parameter for a visitor with no admin session', function () {
    /*
     * The gate, asserted over HTTP rather than described. `?kbbwash=b` on the
     * live shop must be inert: no style block, and a page byte-identical to the
     * one without the parameter once the CSRF token is masked.
     *
     * MUTATION: delete the `auth()->guard('admin')->check()` arm of
     * PageWash::previewTreatment() and this fails with the style block present
     * — which would mean handing every visitor a switch for an unreleased
     * design by typing it into the address bar.
     */
    $plain = bgMask($this->get('/shop/')->getContent());
    $probed = bgMask($this->get('/shop/?kbbwash=b')->getContent());

    expect($probed)->not->toContain('kbb-page-wash')
        ->and($probed)->toBe($plain, 'the parameter changed the page for a signed-out visitor');
});

it('renders the treatment for a signed-in admin, on the real page', function () {
    $this->actingAs(bgAdmin(), 'admin');

    $html = $this->get('/shop/?kbbwash=b')->getContent();

    expect($html)->toContain('<style id="kbb-page-wash">')
        ->and($html)->toContain('@keyframes kbb-wash-a')
        ->and($html)->toContain('@media(prefers-reduced-motion:no-preference)')
        /* Treatment b is peach_rose_pearl at intensity 100, so its lightest
           moment is the palette's third colour exactly. */
        ->and($html)->toContain('rgb(248,245,249)');
});

it('lets only its own constants select a treatment', function () {
    /*
     * Rule 5. The parameter is compared against the keys of TREATMENTS and
     * nothing else, so nothing a visitor can type reaches a declaration, a
     * selector, a property or a file path.
     *
     * MUTATION: replace the array_key_exists() check with a bare
     * `TREATMENTS[$key] ?? …` lookup on an unvalidated string and the first two
     * cases below start emitting a stylesheet built from a missing preset.
     */
    $this->actingAs(bgAdmin(), 'admin');

    foreach (['e', 'A', '../a', '<script>', 'a b', '', '0', 'page'] as $probe) {
        $html = $this->get('/shop/?kbbwash='.urlencode($probe))->getContent();

        expect(str_contains($html, 'kbb-page-wash'))
            ->toBeFalse('the value '.var_export($probe, true).' selected a treatment');
    }
});

it('costs a shopper nothing when the parameter is absent', function () {
    /*
     * Rule 4, at the only place this feature can cost anything on a page
     * nobody is previewing: previewTreatment() reads a query value FIRST and
     * returns before it touches the auth guard, which is a session read.
     *
     * Measured rather than asserted: the same page is fetched with and without
     * the parameter and the statement count is compared. It is the query budget
     * in miniature, held here as well because StorefrontQueryBudgetTest does
     * not know about this parameter.
     */
    $count = function (string $path): int {
        \DB::flushQueryLog();
        \DB::enableQueryLog();
        $this->get($path);
        $n = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        return $n;
    };

    /*
     * WARMED FIRST, and this line is the whole reason the case is written this
     * way. Setting::map() memoises in a process-level static as well as in the
     * cache, so the FIRST request of a process pays for settings that every
     * later one gets free -- 19 statements against 3, measured. Comparing a
     * cold request with a warm one measures the memo, not this parameter.
     */
    $count('/shop/');

    $bare = $count('/shop/');
    $probed = $count('/shop/?kbbwash=b');

    expect($probed)->toBe($bare, 'the preview parameter costs a signed-out visitor '.($probed - $bare).' extra queries');
});

/* ═══════════════════════════════════════════════ rule 5: the colours ═══ */

it('drops a stored colour that is not six hex digits, rather than printing it', function () {
    /*
     * The endpoint refuses an invalid colour at save time, so the only way one
     * can be in the table is a direct write — an import, a restored backup, a
     * hand-edited row. This is the second of the two locks: paletteOf() checks
     * every custom colour again at render time and replaces an invalid one with
     * the shipped default for that same slot, so nothing a row can contain
     * reaches a gradient stop.
     *
     * MUTATION: delete the Color::isValidHex() arm in PageWash::paletteOf() and
     * this fails with `url(javascript:` in the emitted stylesheet.
     */
    $settings = app(SettingsService::class);
    $settings->set('wash_on', '1');
    /* drift 100 so each moment is a palette colour EXACTLY, which is what makes
       "the slot fell back to its shipped value" a literal string match below
       rather than a comparison against a mix. */
    $settings->set('wash_drift', '100');
    $settings->set('wash_palette', 'custom');
    $settings->set('wash_c1', 'red;}body{background:url(javascript:alert(1))');
    $settings->set('wash_c2', '#GGGGGG');
    $settings->set('wash_c3', '#123');

    $css = app(PageWash::class)->css();

    expect($css)->not->toContain('javascript')
        ->and($css)->not->toContain('url(')
        ->and($css)->not->toContain('alert')
        ->and($css)->not->toContain('GGGGGG')
        ->and($css)->not->toContain('#123')
        /* The three slots fell back to their shipped values, which are the
           default palette's three colours. */
        ->and($css)->toContain('rgb(255,247,238)');

    /*
     * And it is still a stylesheet rather than a broken one: balanced braces,
     * exactly one `body{` rule and exactly three layers. An emitter that
     * dropped an invalid colour by omitting its STOP would pass every
     * assertion above and leave a gradient with a missing argument.
     */
    expect(substr_count($css, '{'))->toBe(substr_count($css, '}'));
    expect(substr_count($css, 'body{'))->toBe(2, 'one body rule plus the print reset');
    expect(substr_count($css, 'radial-gradient('))->toBe(9);
});

it('does not let a stored palette name reach the stylesheet', function () {
    $settings = app(SettingsService::class);
    $settings->set('wash_on', '1');
    $settings->set('wash_drift', '100');
    $settings->set('wash_palette', 'cream_blush_lilac";}body{display:none');

    $css = app(PageWash::class)->css();

    expect($css)->not->toContain('body{display:none')
        ->and($css)->not->toContain('";}')
        ->and($css)->not->toContain('cream_blush_lilac')
        /* An unknown palette name falls back to the shipped one, so the wash is
           still drawn -- it is not a way to blank the page either. */
        ->and($css)->toContain('rgb(255,247,238)');
});

/* ═══════════════════════════════════════════════ where it applies ═══ */

it('leaves the money pages plain when the owner asks it to', function () {
    /*
     * ASKED OF css() WITH A REQUEST RATHER THAN FETCHED. /checkout/ redirects
     * to the basket when the basket is empty, so an HTTP assertion about it
     * would really be an assertion about a redirect page — which carries no
     * wash for a reason that has nothing to do with this setting, and would
     * pass with isPlainPath() deleted entirely. One real fetch below proves the
     * wiring; the path decision itself is asked directly.
     *
     * THE LANGUAGE SEGMENT IS STRIPPED FIRST. With Arabic on the checkout is
     * /ar/checkout/, and a first-segment compare that did not know about the
     * prefix would leave the Arabic checkout washed while the English one was
     * plain — the shop saying two different things to two shoppers.
     *
     * MUTATION: delete the Locale::codes() shift in PageWash::isPlainPath() and
     * the two /ar/ money pages below start carrying the wash.
     */
    $settings = app(SettingsService::class);
    $settings->set('wash_on', '1');
    $settings->set('wash_where', 'content');
    $settings->set(\App\Support\Locale::SETTING_ENABLED, true);

    $wash = app(PageWash::class);

    foreach (['/checkout/', '/my-account/', '/order-received/9', '/ar/checkout/', '/ar/my-account/'] as $plain) {
        expect($wash->css(Request::create($plain)))->toBe('', $plain.' still carries the wash');
    }

    foreach (['/', '/shop/', '/cart/', '/skincare-guide/', '/ar/', '/ar/shop/', '/ar/cart/'] as $washed) {
        expect($wash->css(Request::create($washed)))->not->toBe('', $washed.' lost the wash');
    }

    // And the wiring, once, over HTTP.
    expect($this->get('/shop/')->getContent())->toContain('kbb-page-wash');
});

it('covers every page when it is left at "every page of the shop"', function () {
    $settings = app(SettingsService::class);
    $settings->set('wash_on', '1');

    $wash = app(PageWash::class);

    expect($wash->get('where'))->toBe('all');

    foreach (['/checkout/', '/my-account/', '/ar/checkout/'] as $path) {
        expect($wash->css(Request::create($path)))->not->toBe('', $path.' is plain at the shipped reach');
    }
});

/* ══════════════════════════════════════════════ the two endpoints ═══ */

it('maps both routes, and maps neither to the owner-only default', function () {
    foreach ([['GET', 'admin-api/page-wash'], ['POST', 'admin-api/page-wash']] as [$verb, $path]) {
        expect(AdminCapabilities::forPath($verb, $path))->toBe('pagewash.manage', $verb.' '.$path);
    }

    /*
     * The same three roles that hold sitelayout.manage, cartpage.manage and
     * slimfooter.manage -- this is storefront appearance -- and its OWN
     * capability, so that narrowing one never silently narrows another from a
     * different file.
     */
    expect(AdminCapabilities::CAPABILITIES['pagewash.manage'])->toBe(['owner', 'manager', 'editor']);
    expect(AdminCapabilities::CAPABILITIES['pagewash.manage'])
        ->toBe(AdminCapabilities::CAPABILITIES['sitelayout.manage']);
});

it('refuses both verbs to an account without the capability', function () {
    $this->actingAs(bgAdmin('support'), 'admin');

    $this->getJson('/admin-api/page-wash')->assertStatus(403);
    $this->postJson('/admin-api/page-wash', ['settings' => ['on' => true]])->assertStatus(403);

    // And nothing was written by the refusal.
    expect(app(PageWash::class)->get('on'))->toBeFalse();
});

it('answers the screen with every field, the treatments and the contrast table', function () {
    $this->actingAs(bgAdmin(), 'admin');

    $body = $this->getJson('/admin-api/page-wash')->assertOk()->json();

    expect(array_column($body['tabs'], 'key'))->toBe(['colour', 'motion', 'reach']);

    $keys = [];
    foreach ($body['tabs'] as $tab) {
        foreach ($tab['fields'] as $field) {
            $keys[] = $field['key'];
        }
    }

    expect($keys)->toBe(array_keys(PageWash::SCHEMA));
    expect(array_keys($body['treatments']))->toBe(['a', 'b', 'c', 'd']);
    expect($body['css'])->toBe('', 'a shop at its shipped values should be told it sends nothing');
    expect($body['is_default'])->toBeTrue();
    expect($body['preview_param'])->toBe('kbbwash');
    expect($body['contrast'])->toHaveKeys(array_keys(PageWash::TEXT_TOKENS));

    /* The preview addresses, with a REAL product slug rather than an invented
       one — a frame pointed at /product/demo/ shows the owner a 404 and calls
       it his shop. */
    expect(array_column($body['preview_pages'], 'label'))->toContain('Home', 'Shop', 'Cart', 'Journal');
});

it('saves and reads back every field it drew', function () {
    $this->actingAs(bgAdmin(), 'admin');

    $written = [
        'on' => true,
        'palette' => 'mint_cream_blush',
        'c1' => '#ABCDEF',
        'c2' => '#123456',
        'c3' => '#FEDCBA',
        'intensity' => 60,
        'drift' => 25,
        'cycle' => 600,
        'spread' => 'top',
        'where' => 'content',
    ];

    $this->postJson('/admin-api/page-wash', ['settings' => $written])->assertOk();

    $back = app(PageWash::class)->all();

    foreach ($written as $key => $value) {
        expect($back[$key])->toBe($value, $key.' did not survive the round trip');
    }
});

it('refuses a value outside a control, and says which one', function () {
    $this->actingAs(bgAdmin(), 'admin');

    // A select that is not one of its own options is REFUSED, not substituted:
    // a screen reading back "Palette: mint" on a shop that stored nothing is a
    // screen lying about the shop.
    $this->postJson('/admin-api/page-wash', ['settings' => ['palette' => 'not-a-palette']])
        ->assertStatus(422)
        ->assertJsonPath('rejected', ['palette']);

    $this->postJson('/admin-api/page-wash', ['settings' => ['c1' => 'rebeccapurple']])
        ->assertStatus(422)
        ->assertJsonPath('rejected', ['c1']);

    // An unknown key is refused before anything is written.
    $this->postJson('/admin-api/page-wash', ['settings' => ['on' => true, 'sneaky' => 1]])
        ->assertStatus(422);

    expect(app(PageWash::class)->get('on'))->toBeFalse('a refused POST wrote something');

    // A slider outside its range is pulled to its bound rather than refused.
    $this->postJson('/admin-api/page-wash', ['settings' => ['cycle' => 99999]])->assertOk();
    expect(app(PageWash::class)->get('cycle'))->toBe(900);
});

/* ══════════════════════════════════════════════════════ helpers ═══ */

/** Everything that legitimately differs between two renders of one page. */
function bgMask(string $html): string
{
    return (string) preg_replace('/[A-Za-z0-9]{40}/', 'TOKEN', $html);
}
