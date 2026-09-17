<?php

declare(strict_types=1);

/**
 * Phase 15 — `site_title`: read by the storefront, written by nothing.
 * (Lane FW, from docs/FO-HOMEPAGE-INVENTORY.md §2 and §6)
 *
 * ── WHAT WAS WRONG ──────────────────────────────────────────────────────────
 *
 * Two storefront surfaces read this key: store/home.blade.php prints it as the
 * page's <h1> whenever the hero slider is switched off or has no slides, and
 * store/review-wall.blade.php prints it as the wordmark at the top of the
 * shareable review page. Nothing in the tree wrote it — no SETTING_RULES entry,
 * no module endpoint, no seeder, no ->set(). Both readers fell back to a
 * literal, so the key looked configurable and was not.
 *
 * Lane FO reported it and said it belongs with the general settings rather than
 * on an Appearance screen. Verified, and that is where it now is: Store →
 * Business Details → Store identity, beside `store_name`, saved through the
 * generic settings endpoint.
 *
 * ── THE HALF THAT IS NOT "ADD IT TO THE LIST" ───────────────────────────────
 *
 * An empty box. ConvertEmptyStringsToNull turns a cleared field into `null`
 * inside the posted `settings` array, which is the trap four settings
 * controllers on this project have been bitten by. It is NOT the trap here —
 * checkSetting()'s `text` branch does `trim((string) $raw)`, so null lands as
 * '' — and this file proves that rather than reasoning about it.
 *
 * The trap here is one step later, at the READER. A cleared box stores '' and
 * does not delete the row, and SettingsService::get() answers its default only
 * when the ROW IS ABSENT. `get('site_title', 'K-Beauty Bliss …')` therefore
 * returns '' for a shop that has cleared the field, and the homepage's only
 * <h1> becomes empty. Both readers use `?:` for that reason and the tests below
 * post a blank through the real endpoint and read the rendered page back.
 *
 * ── EVERY TEST BELOW WAS RUN AGAINST THE UNFIXED TREE ───────────────────────
 *
 * The mutations are listed in docs/FW-APPEARANCE-LEFTOVERS.md.
 */

use App\Models\AdminUser;
use App\Models\Setting;
use App\Services\HomepageSections;
use App\Services\SettingsService;
use App\Http\Controllers\Admin\AdminController;

/** An owner, signed in on the admin guard. */
function siteTitleAdmin(): AdminUser
{
    $admin = AdminUser::create([
        'name' => 'Site Title Owner',
        'email' => 'site-title-owner-' . uniqid() . '@example.test',
        'password' => 'password-long-enough',
        'role' => 'owner',
    ]);

    test()->actingAs($admin, 'admin');

    return $admin;
}

/** Switch the hero off on both devices, which is when the quiet <h1> renders. */
function siteTitleHeroOff(): void
{
    $payload = [];
    $order = 0;

    foreach (HomepageSections::REGISTRY as $key => $row) {
        $payload[$key] = [
            'desktop' => $key !== 'hero',
            'mobile' => $key !== 'hero',
            'order' => $order++,
            'skin' => $row[3],
        ];
    }

    app(HomepageSections::class)->save($payload);
    SettingsService::forgetMemo();
}

/** The text of the page's single <h1>. */
function siteTitleH1(string $html): string
{
    if (preg_match('/<h1[^>]*>(.*?)<\/h1>/s', $html, $m) !== 1) {
        return '';
    }

    return trim($m[1]);
}

function siteTitleFetch(string $uri): string
{
    SettingsService::forgetMemo();

    return test()->get($uri)->getContent();
}

/* ─────────────────────────────── §1 the key has a writer now */

it('accepts site_title on the settings endpoint the Business Details screen posts to', function () {
    siteTitleAdmin();

    test()->putJson('/admin-api/settings', ['settings' => ['site_title' => 'Seoul Skin Bar']])
        ->assertOk()
        ->assertJsonPath('saved', 1);

    expect(Setting::query()->where('key', 'site_title')->value('value'))->toBe('Seoul Skin Bar');
});

it('is on the rule list, which is what stops the endpoint answering ok and writing nothing', function () {
    // The standing failure mode of this screen, written at the top of
    // SETTING_RULES: a key that is not on the list is DROPPED while the
    // response still says ok. Asserted against the constant as well as the
    // round trip above, because the round trip would also pass if the endpoint
    // stopped whitelisting at all.
    expect(AdminController::SETTING_RULES)->toHaveKey('site_title');
    expect(AdminController::SETTING_RULES['site_title'][0])->toBe('text');
});

it('reaches both of the storefront surfaces that read it', function () {
    siteTitleAdmin();
    siteTitleHeroOff();

    test()->putJson('/admin-api/settings', ['settings' => ['site_title' => 'Seoul Skin Bar']])->assertOk();

    expect(siteTitleH1(siteTitleFetch('/')))->toBe('Seoul Skin Bar');
    expect(siteTitleFetch('/reviews'))->toContain('Seoul Skin Bar');
});

/* ─────────────────────────────── §2 the empty box */

it('stores a cleared box as an empty string, not as a refusal', function () {
    // ConvertEmptyStringsToNull reaches INSIDE the posted `settings` array, so
    // what the controller sees for a cleared field is null and not ''. Posted
    // as '' here, exactly as the browser posts it, so the middleware is in the
    // path rather than being described.
    siteTitleAdmin();

    test()->putJson('/admin-api/settings', ['settings' => ['site_title' => 'Seoul Skin Bar']])->assertOk();
    test()->putJson('/admin-api/settings', ['settings' => ['site_title' => '']])
        ->assertOk()
        ->assertJsonPath('saved', 1);

    expect(Setting::query()->where('key', 'site_title')->value('value'))->toBe('');
});

it('takes an explicit null the same way, which is what the middleware actually hands it', function () {
    siteTitleAdmin();

    test()->putJson('/admin-api/settings', ['settings' => ['site_title' => null]])
        ->assertOk()
        ->assertJsonPath('saved', 1);

    expect(Setting::query()->where('key', 'site_title')->value('value'))->toBe('');
});

it('puts the shipped line back on the homepage when the box is cleared, not an empty h1', function () {
    siteTitleAdmin();
    siteTitleHeroOff();

    test()->putJson('/admin-api/settings', ['settings' => ['site_title' => 'Seoul Skin Bar']])->assertOk();
    expect(siteTitleH1(siteTitleFetch('/')))->toBe('Seoul Skin Bar');

    test()->putJson('/admin-api/settings', ['settings' => ['site_title' => '']])->assertOk();

    $html = siteTitleFetch('/');

    // The row EXISTS and is empty, which is the case get()'s default argument
    // does not cover.
    expect(Setting::query()->where('key', 'site_title')->value('value'))->toBe('');
    expect(siteTitleH1($html))->toBe('K-Beauty Bliss — authentic Korean skincare in the UAE');

    // And there is still exactly one of them. An empty <h1> is not a smaller
    // heading, it is a page with no heading at all.
    expect(substr_count($html, '<h1'))->toBe(1);
});

it('does the same on the review wall, whose wordmark reads the same key', function () {
    siteTitleAdmin();

    test()->putJson('/admin-api/settings', ['settings' => ['site_title' => 'Seoul Skin Bar']])->assertOk();
    expect(siteTitleFetch('/reviews'))->toContain('Seoul Skin Bar');

    test()->putJson('/admin-api/settings', ['settings' => ['site_title' => '']])->assertOk();

    $html = siteTitleFetch('/reviews');

    expect(str_contains($html, 'Seoul Skin Bar'))->toBeFalse('The cleared value is still on the page.');
    expect($html)->toContain('sr-logo');
    expect($html)->toContain('K-Beauty Bliss');
});

/* ─────────────────────────────── §3 a shop that has never opened the box */

it('renders exactly what it rendered before the key had a writer', function () {
    // No row at all: both readers answer their own literal, which is the state
    // every existing shop is in. The homepage was also diffed byte for byte
    // against the unpatched tree — see the lane report.
    siteTitleHeroOff();

    expect(Setting::query()->where('key', 'site_title')->exists())->toBeFalse();
    expect(siteTitleH1(siteTitleFetch('/')))->toBe('K-Beauty Bliss — authentic Korean skincare in the UAE');
    expect(siteTitleFetch('/reviews'))->toContain('K-Beauty Bliss');
});

/* ─────────────────────────────── §4 the console block stays appliable */

it('still finds both console anchors verbatim in the admin script', function () {
    // resources/views/admin/app.blade.php is owned by another lane, so the box
    // itself ships as anchor → replacement blocks in
    // docs/FW-APPEARANCE-LEFTOVERS.md. An anchor is a quotation of a file
    // several lanes edit at once, and a quotation rots. This fails the moment
    // one of them stops matching, which is a loud stale block instead of a
    // silent one. Same arrangement HomepageSectionOrderTest §5 uses for Lane
    // FR's two blocks.
    $doc = file_get_contents(base_path('docs/FW-APPEARANCE-LEFTOVERS.md'));
    $script = file_get_contents(base_path('resources/views/admin/app.blade.php'));

    expect($doc)->toContain('<!-- ANCHOR-1 -->');
    expect($doc)->toContain('<!-- ANCHOR-2 -->');

    foreach ([1, 2] as $n) {
        $at = strpos($doc, '<!-- ANCHOR-' . $n . ' -->');
        $open = strpos($doc, '```', $at);
        $start = strpos($doc, "\n", $open) + 1;
        $anchor = substr($doc, $start, strpos($doc, "\n```", $start) - $start);

        expect($anchor)->not->toBe('');

        // The replacement sits in the next fenced block after the anchor's.
        $rOpen = strpos($doc, '```', strpos($doc, "\n```", $start) + 4);
        $rStart = strpos($doc, "\n", $rOpen) + 1;

        /*
         * INTEGRATOR: the anchor is CONSUMED once the block is applied, which
         * is the normal end of a block's life — this guard was written while it
         * was still pending and would then fail for the one reason that is not
         * a problem. It now accepts either state and still catches the one it
         * exists for: a block that matches NEITHER is stale, and stale is what
         * silently ships nothing.
         */
        $replacement = substr($doc, $rStart, strpos($doc, "\n```", $rStart) - $rStart);

        $pending = str_contains($script, $anchor);
        $applied = $replacement !== '' && str_contains($script, $replacement);

        expect($pending || $applied)->toBeTrue(
            'console block '.$n.' matches the admin script neither as its anchor nor as its replacement — it has gone stale'
        );
    }
});

it('names the field id the replacement block introduces, in both blocks', function () {
    // The two blocks are one change: the field paints from the first and saves
    // from the second, and a screen that paints a box which saves nothing is
    // this project's own standing defect. Asserted across the pair so that
    // applying one without the other cannot look complete.
    $doc = file_get_contents(base_path('docs/FW-APPEARANCE-LEFTOVERS.md'));

    expect(substr_count($doc, "bdField('set_site_title','Site title'"))->toBe(1);
    expect(substr_count($doc, "site_title: sval('set_site_title')"))->toBe(1);
});
