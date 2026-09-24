<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Product;
use App\Models\Routine;
use App\Services\BuildMyRoutine;
use App\Services\SettingsService;
use App\Support\RoutineRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildMyRoutineRoutes;

/**
 * Lane Q round 2 — Catalog → Build my routine, rebuilt as eight tabs.
 *
 * ── THE OWNER'S WORDS ───────────────────────────────────────────────────────
 *
 * "The routine page is super long, i want tabs with every step, so i can just
 * fillup or select and the steps can be lock according. make it super easy for
 * me please."
 *
 * He was asked the two questions that would otherwise have been guesses and
 * answered both: "locked" means DONE, not disabled — nothing is greyed out and
 * no tab is gated on another — and the strip is ONE FLAT EIGHT, the five steps
 * plus Concern pages, Wording and Settings, because that is the shape of
 * Appearance → Checkout page, which he uses daily.
 *
 * ── WHAT THIS FILE PINS, AND WHY IT IS MOSTLY SERVER-SIDE ───────────────────
 *
 * The tab LABEL is the progress indicator — it is how he reads the whole job
 * off the strip without opening anything — so the two figures behind it are the
 * part worth testing properly, and both are computed in PHP:
 *
 *   coverage.by_role        the NUMBER. Live, in stock, shopper-visible.
 *   coverage.by_role_short  the VERDICT. How many routines he is SHOWING still
 *                           draw that step empty. Zero is the tick.
 *
 * The rest is drawn in a Blade the test suite cannot execute, so the structural
 * rules that a later lane could quietly break — one body per render, no tab
 * ever disabled, the row action scoped to the open tab — are read out of the
 * source with the defect named in each case's own comment.
 */
beforeEach(function () {
    BuildMyRoutineRoutes::wire(app());
    app(SettingsService::class)->setModule('build_my_routine', true);
    Product::query()->forceDelete();
});

function rstAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Q2 Owner',
        'email' => 'q2-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

function rstProduct(?string $role, array $concerns = [], array $overrides = []): Product
{
    return Product::create(array_replace([
        'slug' => 'rst-' . Str::random(8),
        'name' => 'RST ' . Str::random(4),
        'status' => 'publish',
        'is_visible' => true,
        'price' => 10000,
        'stock_status' => 'instock',
        'routine_role' => $role,
        'routine_concerns' => $concerns === [] ? null : json_encode($concerns),
    ], $overrides));
}

function rstScreen(): string
{
    return (string) file_get_contents(
        resource_path('views/admin/partials/routines-screen.blade.php')
    );
}

/* ───────────── 1. the number on the tab: live, not merely tagged ─────────── */

it('counts a step by what the storefront can draw on, not by what is tagged', function () {
    /*
     * THE DEFECT THIS WOULD HAVE BEEN: a tab reading "Toner 4" over four
     * products that are drafts, hidden or sold out. The storefront draws that
     * step as "not stocked yet" and the tab says it is fine — which is the
     * "permanently alarming and therefore ignorable" failure in reverse, and
     * worse, because it is reassuring and wrong.
     *
     * MUTATION NOTE: drop `->inStock()` from BuildMyRoutine::coverage()'s query
     * and the first expectation reads 2 instead of 1.
     */
    rstProduct('tone');
    rstProduct('tone', [], ['stock_status' => 'outofstock']);
    rstProduct('tone', [], ['status' => 'draft']);
    rstProduct('tone', [], ['is_visible' => false]);

    $c = app(BuildMyRoutine::class)->coverage();

    expect($c['by_role']['tone'])->toBe(1)
        ->and($c['by_role']['cleanse'])->toBe(0);
});

/* ─────────── 2. the tick on the tab: done means no routine is short ─────── */

it('ticks a step only when every routine it shows can fill it', function () {
    /*
     * THE DEFECT A SIMPLER RULE WOULD HAVE BEEN: ticking a step because it has
     * products. Four toners all tagged for acne leave seven routines drawing
     * the toner step empty on the storefront, and a green tab would say that
     * job was finished. The count and the verdict answer different questions
     * and neither is a proxy for the other.
     *
     * MUTATION NOTE: return `$byRole[$role] > 0 ? 0 : 8` for by_role_short and
     * the `tone` expectation flips to 0 — the tab would go green with six
     * routines still empty.
     */
    // Untargeted: suits every routine, so this step is finished outright.
    rstProduct('cleanse');

    // Targeted at two concerns only: six of the eight are still short.
    rstProduct('tone', ['acne', 'dark-spots']);

    $c = app(BuildMyRoutine::class)->coverage();

    expect($c['by_role']['cleanse'])->toBe(1)
        ->and($c['by_role_short']['cleanse'])->toBe(0)
        ->and($c['by_role']['tone'])->toBe(1)
        ->and($c['by_role_short']['tone'])->toBe(6)
        // Nothing at all fills these, so every routine is short of them.
        ->and($c['by_role_short']['protect'])->toBe(8);
});

it('does not count a routine the owner has hidden as short of anything', function () {
    /*
     * THE DEFECT: a step permanently amber because of a routine that is not on
     * the storefront. A hidden routine draws nothing anywhere, so a step it
     * cannot fill is not a gap a shopper can reach — and a tab that never goes
     * green for a reason the owner cannot see is a tab he learns to ignore,
     * which is the exact failure coverage()'s own header warns about for the
     * untagged count.
     *
     * MUTATION NOTE: delete the `if (! $shown) continue;` guard in
     * BuildMyRoutine::coverage() and this reads 1 instead of 0.
     */
    // One toner, tagged for acne only: every concern except acne is short.
    rstProduct('tone', ['acne']);

    // Hide all seven of those, leaving only acne shown.
    foreach (['hydration', 'dark-spots', 'ageing', 'sensitivity', 'pores', 'dullness', 'sun'] as $slug) {
        Routine::create(['concern' => $slug, 'is_enabled' => false]);
    }

    $c = app(BuildMyRoutine::class)->coverage();

    expect($c['by_role_short']['tone'])->toBe(0);
});

it('ships the verdict for every role, so no tab can be drawn without one', function () {
    /*
     * A missing key would draw as 0 in the screen's `|| 0`, which is the tick —
     * a step silently reported finished because its figure was absent.
     */
    $c = app(BuildMyRoutine::class)->coverage();

    expect(array_keys($c['by_role_short']))->toBe(RoutineRoles::ORDER)
        ->and(array_keys($c['by_role']))->toBe(RoutineRoles::ORDER);
});

/* ───────────────── 3. the cost: tabs did not multiply the requests ───────── */

it('does not make the screen more expensive to open', function () {
    /*
     * THE RISK THE COORDINATOR NAMED: "tabs must not turn one page load into
     * eight." They do not, and the reason is a decision rather than luck — the
     * three non-step tabs are drawn from the /routines response the screen
     * already holds, so switching to them issues nothing at all, and a step tab
     * reuses the single product request the search box always made.
     *
     * MUTATION NOTE: add a second `->count()` or a per-role query inside
     * coverage() and this goes red.
     */
    foreach (range(1, 8) as $i) {
        rstProduct('cleanse', ['acne']);
    }

    app(BuildMyRoutine::class)->coverage();          // warm

    $log = [];
    DB::listen(function ($q) use (&$log) { $log[] = $q->sql; });
    app(BuildMyRoutine::class)->coverage();
    DB::getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);

    // The products read, the routine overrides, and the one for the concern
    // pages' live check. Unchanged by round 2.
    expect(count($log))->toBeLessThanOrEqual(3);
});

it('serves the whole screen from the two endpoints it always used', function () {
    /*
     * No new endpoint means no new capability to get wrong, and the two that
     * exist are already behind catalog.view. This also pins that the screen's
     * new figure rides the EXISTING response rather than a ninth request.
     */
    rstProduct('cleanse');

    $body = test()->actingAs(rstAdmin(), 'admin')
        ->getJson('/admin-api/routines')->assertOk()->json();

    expect($body['coverage'])->toHaveKeys(['by_role', 'by_role_short', 'concern_pages', 'routines'])
        ->and($body)->toHaveKeys(['roles', 'concerns', 'routines', 'settings', 'coupons', 'tabs']);

    // And the list endpoint still takes the same three parameters it did.
    $rows = test()->actingAs(rstAdmin(), 'admin')
        ->getJson('/admin-api/routine-products?role=cleanse&q=&page=1')->assertOk()->json();

    expect($rows['total'])->toBe(1);
});

/* ──────────── 4. one press, into the open step and no other ─────────────── */

it('sets one product into one step and leaves every other row alone', function () {
    /*
     * THE DEFECT THE OWNER ALREADY REPORTED ONCE, on Appearance → Checkout
     * page: "when i click squeezed, it applies on all tabs all checkout page
     * settings, which is not correct." The per-tab action here is
     * "Use for <step>", and the same rule binds it — it may place ONE product
     * in the ONE step whose tab is open.
     *
     * The server half is pinned here; the client half is the case below.
     */
    $a = rstProduct(null);
    $b = rstProduct(null);

    test()->actingAs(rstAdmin(), 'admin')
        ->postJson('/admin-api/routine-products/' . $a->id, ['role' => 'protect'])
        ->assertOk();

    expect($a->fresh()->routine_role)->toBe('protect')
        ->and($b->fresh()->routine_role)->toBeNull();
});

it('reads the open tab at the moment of the press, so it cannot write another step', function () {
    /*
     * THE DEFECT: a "Use for this step" button that had been rendered with a
     * role baked in, or worse a handler that walked every role. The handler
     * reads `open` — the variable the strip sets — and posts that one key.
     *
     * MUTATION NOTE: change `tag(btn.dataset.rtnUse, {role: open}, …)` to
     * `{role: data.roles[0].key}` and this goes red; every press would put the
     * product in Cleanser whichever tab was showing.
     */
    $src = rstScreen();

    expect($src)->toContain('tag(btn.dataset.rtnUse, {role: open}');

    // And nothing in the handler iterates the roles.
    $handler = Str::betweenFirst($src, "document.querySelectorAll('[data-rtn-use]')", '});');

    expect($handler)->not->toContain('forEach(function(r)')
        ->and($handler)->not->toContain('data.roles');
});

/* ───────────── 5. the structural rules a later lane could break ─────────── */

it('draws exactly one tab body, never the whole page again', function () {
    /*
     * THE DEFECT THIS EXISTS AGAINST: a later lane appending a view to render()
     * "so it is easy to find", which quietly restores the 693-line scroll the
     * owner asked to be rid of.
     *
     * MUTATION NOTE: change the `else if` chain in render() to plain `if`s and
     * more than one body is concatenated — the assertion on a single
     * else-if chain is what fails.
     */
    $src = rstScreen();
    $render = Str::betweenFirst($src, 'function render(){', 'host.innerHTML');

    foreach (['stepView()', 'concernPagesView()', 'routinesView()', 'settingsView()'] as $view) {
        expect($render)->toContain($view);
    }

    // Each body is reached through the one chain, so exactly one can run.
    $chain = Str::betweenFirst($render, 'if (isStep(open)) {', 'html += settingsView();');

    expect(substr_count($chain, '} else if (open ==='))->toBe(2)
        ->and(substr_count($chain, 'html +='))->toBe(3)
        ->and($chain)->not->toContain('if (!');
});

it('never disables a tab, because locked means done and not blocked', function () {
    /*
     * THE DEFECT: sequential gating. He was asked and said no — he works in any
     * order and jumps straight to the empty steps, and a step tab he cannot
     * open because an earlier one is unfinished is the opposite of "make it
     * super easy for me".
     *
     * MUTATION NOTE: add ` disabled` to tabHTML()'s button and this is red.
     */
    $src = rstScreen();
    $tab = Str::betweenFirst($src, 'function tabHTML(key, label, badge, state){', "\n  }");

    expect($tab)->not->toContain('disabled')
        ->and($tab)->toContain('role="tab"')
        // The selected state is an attribute, matching the checkout screen.
        ->and($tab)->toContain('aria-selected');

    // Eight tabs: the five roles, mapped, plus the three that are not steps.
    $strip = Str::betweenFirst($src, 'function tabsView(){', "\n  function tabHTML");

    foreach (["'concern-pages'", "'wording'", "'settings'"] as $key) {
        expect($strip)->toContain($key);
    }
    expect($strip)->toContain('(data.roles || []).map');
});

it('keeps the open tab across a save, so saving does not cost him his place', function () {
    /*
     * THE DEFECT: `open` reset on every load(), which both "Save this routine"
     * and "Save settings" call. He is working through eight tabs; a save that
     * bounced him to Cleanser would do it every time.
     *
     * MUTATION NOTE: replace the guard in loadAll() with an unconditional
     * `open = data.roles[0].key;` and this is red.
     */
    $src = rstScreen();
    $load = Str::betweenFirst($src, 'async function loadAll(){', 'async function loadProducts');

    expect($load)->toContain('if (!isStep(open)')
        ->and($load)->toContain("'concern-pages', 'wording', 'settings'");
});

it('searches the whole catalogue when he is looking for something to add', function () {
    /*
     * THE DEFECT, and it was real in this branch before it was fixed: the step
     * tab defaults to "In this step", and searching while narrowed asks "which
     * of the products already in this step match centella". On an EMPTY step —
     * which is every step on the shop he is looking at — that is guaranteed to
     * return nothing. He types the first word off the worksheet, gets no rows,
     * and concludes the search is broken.
     *
     * MUTATION NOTE: change the role expression back to
     * `scope === 'none' ? 'none' : (scope === 'all' ? '' : open)` and the first
     * expectation fails; measured in Chromium before the fix, a search for
     * "centella" on the empty SPF tab returned 0 rows, and after it, 14.
     */
    $src = rstScreen();
    $fn = Str::betweenFirst($src, 'async function loadProducts(){', 'function stepView');

    expect($fn)->toContain("(scope === 'all' ? '' : (query ? '' : open))");

    // An explicit scope is never overridden by a term.
    expect($fn)->toContain("scope === 'none' ? 'none'");
});

/* ──────────────── 6. nothing that already worked was dropped ─────────────── */

it('still carries every control the long page had', function () {
    /*
     * The reorganisation rule: no control may change what it does and none may
     * quietly vanish. Each of these is a data- hook or an id the old screen
     * bound a behaviour to, and every one is still drawn.
     */
    $src = rstScreen();

    $hooks = [
        'data-rtn-rolefor' => 'the per-row step select',
        'data-rtn-concern' => 'the per-row concern chips',
        'data-rtn-title'   => 'a routine heading',
        'data-rtn-blurb'   => 'a routine sentence',
        'data-rtn-coupon'  => 'a routine offer coupon',
        'data-rtn-pos'     => 'a routine position',
        'data-rtn-on'      => 'shown to shoppers',
        'data-rtn-step'    => 'the routine step chips',
        'data-rtn-save'    => 'save this routine',
        'data-rtn-set'     => 'a module setting',
        'rtn-save-settings' => 'save settings',
        'rtn-q'            => 'the product search',
        'rtn-prev'         => 'the pager',
        'rtn-next'         => 'the pager',
    ];

    $missing = [];

    foreach ($hooks as $hook => $what) {
        if (! str_contains($src, $hook)) {
            $missing[] = $what . ' (' . $hook . ')';
        }
    }

    expect($missing)->toBe([], 'The tab rebuild dropped: ' . implode(', ', $missing));

    // The three tiles, which stay above the strip rather than behind a tab.
    expect($src)->toContain('On the storefront')
        ->and($src)->toContain('Still untagged')
        // "Untagged only" survived the role filter becoming the tab strip.
        ->and($src)->toContain('Untagged only');
});

it('leaves the storefront alone entirely', function () {
    /*
     * This round touched one admin partial and one figure in a service. Nothing
     * it did can reach a shopper, and the concern pages still behave exactly as
     * round 1 left them.
     */
    rstProduct('cleanse', ['acne']);

    test()->get('/concern/acne/')->assertNotFound();
    test()->get('/routines')->assertOk();   // the module is on in this file
});
