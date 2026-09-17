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
/**
 * Claims whose STOREFRONT half has landed and whose admin box has not.
 *
 * ── WHY THIS LIST IS ALLOWED TO EXIST ───────────────────────────────────────
 *
 * The same split this file's header describes, one round later. Lane DR made
 * seven claims editable and could not build their boxes because another lane
 * held resources/views/admin/app.blade.php; Lane DT added the eighth —
 * `product_authentic_text`, the product page's "100% authentic" chip, the last
 * literal claim in the storefront — and that file was held again.
 *
 * ── WHAT IS AND IS NOT EXCUSED ──────────────────────────────────────────────
 *
 * ONLY the two shell lines. A key in here is still required to be writable at
 * the endpoint, to read back as removed when cleared, and to open showing what
 * the page prints — every other test in this file loops over the whole of
 * TrustClaims::CLAIMS with no exemption at all, so the half that can silently
 * destroy an owner's claims is pinned for this key exactly as for the rest.
 * What is missing is only the owner's ability to REACH it, and the guard below
 * still reports it rather than passing in silence.
 *
 * ── THE INTEGRATOR'S TWO LINES, VERBATIM ────────────────────────────────────
 *
 * In the Claims panel, immediately after the "At the checkout" bdSec block and
 * before "On the announcement strip" (~line 15325):
 *
 *   bdSec('On the product page',
 *     'The reassurance chips under the Add to basket button, on every product
 *      in the shop. Delivery and returns beside this one are set elsewhere.',
 *     '<div class="bd-grid">'+
 *       bdField('set_product_authentic_text','Beside delivery and returns',
 *         '<input id="set_product_authentic_text"
 *          value="'+sesc(bdClaim('product_authentic_text'))+'"
 *          placeholder="empty — the chip is removed">',
 *         'Currently reads “100% authentic”. Empty removes the chip and its
 *          shield icon, and the other chips close up around it. This is a
 *          separate box from the checkout one above on purpose: clearing one
 *          must not silently clear the other.')+
 *     '</div>')+
 *
 * and in the Business Details save payload, after the
 * `checkout_authentic_text` line (~line 15471):
 *
 *   product_authentic_text: sval('set_product_authentic_text'),
 *
 * THEN DELETE THIS LIST'S ENTRY. The guard turns itself back on the moment the
 * box exists, and a stale entry here is a hole, not a note.
 *
 * @return list<string>
 */
function ctPendingShellControl(): array
{
    /*
     * EMPTY, AND IT SHOULD STAY EMPTY.
     *
     * Lane DT added `product_authentic_text` to TrustClaims::CLAIMS and could
     * not build its box, because the admin shell was another lane's that round.
     * Rather than weaken the loop below, it left the key here and wrote a test
     * that fails the moment the shell DOES draw the box — so the exemption
     * could not quietly outlive the reason for it. The integrator drew the box
     * and the payload line in the same commit as this line, and that test is
     * what told us to.
     *
     * A key belongs here only while its box genuinely does not exist yet, and
     * only with the same forcing test still in place. The default for a new
     * claim is to build both halves together; this list is the exception, not
     * the workflow.
     */
    return [];
}

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

    $pending = ctPendingShellControl();

    foreach (array_keys(TrustClaims::CLAIMS) as $key) {
        $drawn = str_contains($shell, "id=\"set_" . $key . "\"");
        $saved = str_contains($shell, $key . ": sval('set_" . $key . "')");

        if (in_array($key, $pending, true)) {
            /*
             * Awaiting the integrator — see ctPendingShellControl() for the two
             * lines and where they go. A half-wired claim is worse than an
             * unwired one (a box that saves nothing, or a payload key with no
             * box), so the two lines have to arrive together or not at all.
             */
            expect($drawn)->toBe(
                $saved,
                "{$key} is half-wired into the admin shell: a box with no payload line saves nothing, and a payload line with no box posts a blank that would DELETE the claim. Add both, then drop {$key} from ctPendingShellControl().",
            );

            continue;
        }

        expect($drawn)
            ->toBeTrue("The Claims tab has to draw a box for {$key}.");

        expect($saved)
            ->toBeTrue("The save payload has to carry {$key}, or its box saves nothing.");
    }
});

it('leaves no claim pending a box that the admin shell already draws', function () {
    // The exemption above is a note about work in flight, and a note that has
    // stopped being true is a hole. This closes it the moment the box lands.
    $shell = file_get_contents(resource_path('views/admin/app.blade.php'));

    // Stated first so this test asserts something even when the list is empty,
    // which is its healthy state: an empty loop is a test that passes without
    // checking anything, and a pending list is meant to be unusual.
    expect(ctPendingShellControl())
        ->toBeArray('the pending list must stay a list, even when nothing is on it');

    foreach (ctPendingShellControl() as $key) {
        expect(str_contains($shell, "id=\"set_" . $key . "\""))
            ->toBeFalse("The Claims tab now draws a box for {$key}, so it must be removed from ctPendingShellControl() and guarded like every other claim.");
    }
});

it('makes a claim awaiting its box reachable at the endpoint all the same', function () {
    // The storefront half has shipped for these, so the ONLY thing missing is
    // the control. If the endpoint rule were missing too, the integrator would
    // paste the box in and it would silently save nothing — which is the exact
    // defect `reassure_auth_text` shipped with and this file exists to catch.
    //
    // Every claim NOT on the pending list is already covered by the loop in
    // the first test in this file, so the strongest thing to say here when the
    // list is empty is that it is empty — which is the state we want.
    expect(ctPendingShellControl())
        ->toBeArray('the pending list must stay a list, even when nothing is on it');

    foreach (ctPendingShellControl() as $key) {
        expect(\App\Http\Controllers\Admin\AdminController::SETTING_RULES)
            ->toHaveKey($key);

        expect(\App\Http\Controllers\Admin\AdminController::SETTING_RULES[$key][0])
            ->toBe('text', "{$key} must accept a blank value, because blank is how the claim is withdrawn.");
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
