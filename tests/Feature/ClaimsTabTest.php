<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\TrustClaims;

/**
 * THE CLAIMS TAB — the control, its writer, and the read that makes it safe.
 *
 * Lane DR made seven storefront claims into settings and could not build the
 * boxes, because another lane held the admin shell that round. This is the
 * other half, and all three parts are pinned in one file on purpose: a control
 * whose key is missing from the save payload saves nothing, a payload whose key
 * is missing from AdminController::SETTING_RULES is dropped while the endpoint
 * still answers "ok", and — the one that would have done real damage — a box
 * that opens EMPTY on a shop whose pages are printing the shipped default turns
 * the owner's first Save into a silent deletion of every claim on his site.
 *
 * Needles are assembled at run time so this file cannot trip a source guard
 * reading its own explanatory prose (risk register ▲31), and the rendered-HTML
 * assertions count elements rather than searching for a class name, which the
 * page's own inlined CSS would also match (▲31 again).
 */
function ctAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Claims Owner',
        'email' => 'claims-' . uniqid() . '@example.test',
        'password' => bcrypt('secret-secret'),
        'role' => 'owner',
    ]);
}

it('draws a box for every claim, and sends every one of them on save', function () {
    $shell = file_get_contents(resource_path('views/admin/app.blade.php'));

    expect($shell)->not->toBeFalse();

    foreach (array_keys(TrustClaims::CLAIMS) as $key) {
        expect(str_contains($shell, "id=\"set_" . $key . "\""))
            ->toBeTrue("The Claims tab has to draw a box for {$key}.");

        expect(str_contains($shell, $key . ": sval('set_" . $key . "')"))
            ->toBeTrue("The save payload has to carry {$key}, or its box saves nothing.");
    }
});

it('accepts every claim key at the endpoint, blank included', function () {
    $admin = ctAdmin();

    // Blank is not a mistake on this screen: it is how a claim is withdrawn.
    // A rule that rejected it would make the removal half unreachable.
    $blanks = array_fill_keys(array_keys(TrustClaims::CLAIMS), '');

    $this->actingAs($admin, 'admin')
        ->putJson('/admin-api/settings', ['settings' => $blanks])
        ->assertOk();

    Setting::flushMap();
    SettingsService::forgetMemo();

    foreach (array_keys(TrustClaims::CLAIMS) as $key) {
        expect(Setting::query()->where('key', $key)->exists())
            ->toBeTrue("Clearing {$key} has to write an empty row, not be dropped.");

        expect(TrustClaims::get($key))
            ->toBeNull("A cleared {$key} must read back as removed, not as its shipped default.");
    }
});

/**
 * The trap this tab could have shipped, and the reason the read side resolves.
 *
 * Setting::map() is the settings TABLE. A claim nobody has ever edited has no
 * row in it, while the storefront is happily printing that claim's default. If
 * /admin-api/settings sent the raw map, the tab would open with seven empty
 * boxes on a shop whose home page says "100% original" — and because an empty
 * box on this screen MEANS "remove this claim", the owner's first Save would
 * strip every claim off his own site without him typing a character.
 */
it('opens showing what the page is actually showing, on a shop that has never edited a claim', function () {
    $admin = ctAdmin();

    foreach (array_keys(TrustClaims::CLAIMS) as $key) {
        Setting::query()->where('key', $key)->delete();
    }

    Setting::flushMap();
    SettingsService::forgetMemo();

    $sent = $this->actingAs($admin, 'admin')
        ->getJson('/admin-api/settings')
        ->assertOk()
        ->json('settings');

    foreach (TrustClaims::CLAIMS as $key => $shipped) {
        expect($sent)->toHaveKey($key);

        expect($sent[$key])->toBe(
            $shipped,
            "A shop that has never touched {$key} must see the wording its pages are printing, not an empty box that Save would turn into a deletion.",
        );
    }
});

it('sends a cleared claim back as an empty box, not as its default again', function () {
    $admin = ctAdmin();

    $this->actingAs($admin, 'admin')
        ->putJson('/admin-api/settings', ['settings' => ['trust_support_title' => '']])
        ->assertOk();

    Setting::flushMap();
    SettingsService::forgetMemo();

    $sent = $this->actingAs($admin, 'admin')
        ->getJson('/admin-api/settings')
        ->assertOk()
        ->json('settings');

    expect($sent['trust_support_title'])->toBe(
        '',
        'A claim the owner really removed must come back empty, or the screen would offer to restore it every time it loads.',
    );
});

it('puts the tab on the strip and makes it a place a link can land', function () {
    $shell = file_get_contents(resource_path('views/admin/app.blade.php'));

    // Assert on the button and the panel, not on the word "Claims", which the
    // page's prose uses too.
    expect(preg_match('/data-bdtab="claims"/', $shell))
        ->toBe(1, 'Business Details needs a Claims tab button.');

    expect(preg_match('/data-bdpanel="claims"/', $shell))
        ->toBe(1, 'The Claims tab needs a panel to open.');

    // renderStoreSettings('claims') has to be a reachable state, or a link
    // into the tab lands on Business and the owner is told to go hunting —
    // the deep-link defect seven screens already had.
    expect(str_contains($shell, "tab==='claims'"))
        ->toBeTrue('renderStoreSettings has to accept claims as an opening tab.');
});
