<?php

declare(strict_types=1);

use App\Http\Middleware\CheckRedirects;
use App\Models\AdminUser;
use App\Models\NotFoundLog;
use App\Models\Redirect;

/**
 * A row that points its own source back at itself, refused by every writer.
 *
 * =============================================================================
 * WHAT THIS LOOKS LIKE ON THE SHOP, WHICH IS THE POINT
 * =============================================================================
 *
 * Nothing. That is the whole complaint. `CheckRedirects::loops()` catches a
 * self-pointing row at read time with no query at all, so the storefront serves
 * the page exactly as it always did and no visitor is ever harmed. What the row
 * costs is the owner: it sits on Store → SEO & Meta → Redirects looking correct,
 * its `hits` column never moves off zero, and nothing on the screen says why.
 * A row that can never fire is a row somebody will one day stare at.
 *
 * `store()` has refused one since it was written. The other two writers did not,
 * and nothing anywhere tested any of the three:
 *
 *   resolveNotFound()  takes its source from a logged 404 and its target from
 *                      the request, which makes it the likeliest way to author
 *                      one by accident — the owner is looking at a path that
 *                      404s and types that same path as where it should go.
 *
 *   toggle()           cannot author a target, but it can switch a disabled
 *                      self-pointing row back ON. `RedirectMap` only learned to
 *                      refuse writing one recently, so anything written before
 *                      that is in the owner's database today, one click away.
 *
 * ADMIN PATH: Store → SEO & Meta → Redirects. The 404 log and its "redirect
 * this" action are the lower half of the same screen.
 */
function spOwner(): AdminUser
{
    return AdminUser::query()->firstOrCreate(
        ['email' => 'sp-owner@kbb.test'],
        ['name' => 'SP Owner', 'password' => bcrypt('sp-owner-password'), 'role' => 'owner']
    );
}

/* ------------------------------------------------------------------- store() */

it('refuses a self-pointing row on the writer that always refused one', function () {
    /*
     * The guard that already existed, pinned for the first time. It was
     * untested, which is how the other two writers came to be missing it
     * without anything going red.
     */
    test()->actingAs(spOwner(), 'admin')
        ->postJson('/admin-api/redirects', [
            'source' => '/sp-loop/',
            'target' => '/sp-loop/',
            'code' => 301,
        ])
        ->assertStatus(422)
        ->assertJsonPath('ok', false);

    expect(Redirect::query()->where('source', '/sp-loop/')->exists())->toBeFalse();
});

it('refuses a target that is the same page wearing a query string', function () {
    /*
     * THE WRITE-TIME AND READ-TIME HALVES USED TO DISAGREE, and this is where.
     *
     * store() compared the two strings whole, so `/sp-qs/` → `/sp-qs/?utm=x`
     * was written. `CheckRedirects` then refused to follow it, because its loop
     * guard compares `parse_url($target, PHP_URL_PATH)` — the query string is
     * not part of what a redirect matches. One half wrote a row the other half
     * would never read.
     *
     * MUTATION NOTE: change `pointsAtItself()` back to comparing the whole
     * target string and this goes red, along with the agreement test at the
     * foot of this file. The three ordinary-row cases below stay green, which
     * is what says the stricter rule refuses only rows that could never fire.
     */
    test()->actingAs(spOwner(), 'admin')
        ->postJson('/admin-api/redirects', [
            'source' => '/sp-qs/',
            'target' => '/sp-qs/?utm_source=newsletter',
            'code' => 301,
        ])
        ->assertStatus(422);

    expect(Redirect::query()->where('source', '/sp-qs/')->exists())->toBeFalse();
});

it('still writes the ordinary rows a redirect table is for', function (string $source, string $target) {
    /*
     * The guard is a superset of the old one, so this is the half that says it
     * did not become a superset of everything. An off-host target cannot
     * collide with a path this application is asked for, so it is never
     * self-pointing however much of the source it repeats — the same reasoning
     * CheckRedirects::targetPath() uses.
     */
    test()->actingAs(spOwner(), 'admin')
        ->postJson('/admin-api/redirects', [
            'source' => $source,
            'target' => $target,
            'code' => 301,
        ])
        ->assertOk();

    expect(Redirect::query()->where('source', $source)->exists())->toBeTrue();
})->with([
    'a plain move' => ['/sp-old/', '/sp-new/'],
    // Trailing slash is a real difference: /a and /a/ are two addresses, and
    // the shop deliberately keeps the slashed one (U-01).
    'the slashed form of itself' => ['/sp-slash', '/sp-slash/'],
    'off-host, same-looking path' => ['/sp-policies/', 'https://example.com/sp-policies/'],
]);

/* --------------------------------------------------------- resolveNotFound() */

it('refuses to resolve a logged 404 onto itself', function () {
    /*
     * MUTATION NOTE: drop the `pointsAtItself()` guard from
     * resolveNotFound() and this goes red twice over — the 422 becomes a 200
     * AND the row appears in the table.
     */
    $log = NotFoundLog::query()->create([
        'path' => '/sp-missing/',
        'hits' => 4,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    test()->actingAs(spOwner(), 'admin')
        ->postJson('/admin-api/redirects/not-found/' . $log->id . '/resolve', [
            'target' => '/sp-missing/',
            'code' => 301,
        ])
        ->assertStatus(422)
        ->assertJsonPath('ok', false);

    expect(Redirect::query()->where('source', '/sp-missing/')->exists())->toBeFalse();
});

it('keeps the 404 log entry when it refuses to resolve it', function () {
    /*
     * The second half of that refusal, and the one worth asserting separately:
     * resolveNotFound() DELETES the log row on success, so a guard placed after
     * the write would have destroyed the entry the owner was working on while
     * writing nothing in its place. He would have lost the address as well as
     * the redirect, with no way to get it back short of somebody hitting it
     * again.
     *
     * MUTATION NOTE: move the guard below `$notFound->delete()` and BOTH this
     * and the test above go red — which is the finding, not an untidy mutation.
     * A guard placed there still answers 422, so the screen looks as if nothing
     * happened, while `updateOrCreate` has already written the row and the log
     * entry is already gone. The refusal has to come before either write or it
     * is not a refusal.
     */
    $log = NotFoundLog::query()->create([
        'path' => '/sp-keep-me/',
        'hits' => 9,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    test()->actingAs(spOwner(), 'admin')
        ->postJson('/admin-api/redirects/not-found/' . $log->id . '/resolve', [
            'target' => '/sp-keep-me/',
            'code' => 301,
        ])
        ->assertStatus(422);

    expect(NotFoundLog::query()->whereKey($log->id)->exists())->toBeTrue();
});

it('still resolves a logged 404 onto a real destination', function () {
    $log = NotFoundLog::query()->create([
        'path' => '/sp-gone/',
        'hits' => 2,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    test()->actingAs(spOwner(), 'admin')
        ->postJson('/admin-api/redirects/not-found/' . $log->id . '/resolve', [
            'target' => '/shop/',
            'code' => 301,
        ])
        ->assertOk()
        ->assertJsonPath('ok', true);

    expect(Redirect::query()->where('source', '/sp-gone/')->value('target'))->toBe('/shop/')
        ->and(NotFoundLog::query()->whereKey($log->id)->exists())->toBeFalse();
});

/* ------------------------------------------------------------------ toggle() */

it('refuses to switch a self-pointing row back on', function () {
    /*
     * The row this is about is one nobody is authoring today — it is already in
     * the database. `RedirectMap` refuses to write one now; anything written
     * before it learned to, by hand or by an earlier import, is still there.
     * Disabled, it is inert and harmless. Enabled, it is a row the storefront
     * has to discard on every request to the address it claims.
     *
     * Written with the query builder rather than through the endpoint,
     * BECAUSE the endpoint refuses it — which is the arrangement this test
     * exists to produce.
     *
     * MUTATION NOTE: drop the guard from toggle() and this goes red on the
     * status, the message and `enabled`.
     */
    $row = Redirect::query()->create([
        'source' => '/sp-legacy/',
        'target' => '/sp-legacy/',
        'code' => 301,
        'enabled' => false,
        'auto_created' => false,
    ]);

    test()->actingAs(spOwner(), 'admin')
        ->postJson('/admin-api/redirects/' . $row->id . '/toggle')
        ->assertStatus(422)
        ->assertJsonPath('ok', false);

    expect(Redirect::query()->whereKey($row->id)->value('enabled'))->toBeFalsy();
});

it('still lets a self-pointing row be switched OFF', function () {
    /*
     * The guard is on the DIRECTION, not on the endpoint. Turning one off is
     * the thing the owner most wants to do with a row like this, and a guard
     * that refused both ways would leave him with a row he can neither disable
     * nor edit — there is no update endpoint on this controller.
     *
     * MUTATION NOTE: drop the `! $redirect->enabled` half of the condition and
     * this goes red: the owner is locked out of his own row.
     */
    $row = Redirect::query()->create([
        'source' => '/sp-legacy-on/',
        'target' => '/sp-legacy-on/',
        'code' => 301,
        'enabled' => true,
        'auto_created' => false,
    ]);

    test()->actingAs(spOwner(), 'admin')
        ->postJson('/admin-api/redirects/' . $row->id . '/toggle')
        ->assertOk()
        ->assertJsonPath('enabled', false);

    expect(Redirect::query()->whereKey($row->id)->value('enabled'))->toBeFalsy();
});

it('still toggles an ordinary row both ways', function () {
    $row = Redirect::query()->create([
        'source' => '/sp-ordinary/',
        'target' => '/shop/',
        'code' => 301,
        'enabled' => false,
        'auto_created' => false,
    ]);

    test()->actingAs(spOwner(), 'admin')
        ->postJson('/admin-api/redirects/' . $row->id . '/toggle')
        ->assertOk()
        ->assertJsonPath('enabled', true);

    test()->actingAs(spOwner(), 'admin')
        ->postJson('/admin-api/redirects/' . $row->id . '/toggle')
        ->assertOk()
        ->assertJsonPath('enabled', false);
});

/* ------------------------------------- the two halves agree on the same rows */

it('refuses at write time exactly what the storefront refuses at read time', function () {
    /*
     * THE PROPERTY WORTH HAVING, stated against both halves at once rather than
     * against either one's idea of the rule.
     *
     * The read-time guard is not going away and must not: rows written before
     * any of this are in the owner's database. What this asserts is that the
     * two now draw the line in the same place, so a row the writer accepts is a
     * row the storefront will actually follow — and the writer stops being a
     * source of rows that can only ever be discarded.
     *
     * `CheckRedirects::lookup()` returning null IS the discard: it is what
     * makes the middleware call `$next($request)` and serve the page.
     */
    $selfPointing = ['/sp-agree/' => '/sp-agree/', '/sp-agree-qs/' => '/sp-agree-qs/?a=b'];

    foreach ($selfPointing as $source => $target) {
        // The writer refuses it.
        test()->actingAs(spOwner(), 'admin')
            ->postJson('/admin-api/redirects', ['source' => $source, 'target' => $target, 'code' => 301])
            ->assertStatus(422);

        // And had it been written anyway — as rows already in the table were —
        // the storefront would refuse to follow it.
        Redirect::query()->create([
            'source' => $source,
            'target' => $target,
            'code' => 301,
            'enabled' => true,
            'auto_created' => false,
        ]);

        expect(CheckRedirects::lookup($source))->toBeNull();
    }

    // And the converse, so this is not vacuously true of every row.
    Redirect::query()->create([
        'source' => '/sp-agree-real/',
        'target' => '/shop/',
        'code' => 301,
        'enabled' => true,
        'auto_created' => false,
    ]);

    expect(CheckRedirects::lookup('/sp-agree-real/'))->not->toBeNull();
});
