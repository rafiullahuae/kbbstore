<?php

declare(strict_types=1);

/*
 * Appearance → Checkout page: the screen, the endpoints, and the promise the
 * whole thing rests on — that a shop which never opens it renders the checkout
 * exactly as before.
 *
 * ── WHY THIS RENDERS THE SCREEN INSTEAD OF READING THE FILE ────────────────
 *
 * resources/views/admin/app.blade.php is a raw block for thousands of lines,
 * and a previous lane nearly took the whole admin panel down by putting a
 * Blade directive inside it: it shipped as literal text and was a SyntaxError
 * in the script block that builds half the console. `php -l` is green over it
 * — it is not PHP — and so is every test that reads the file, because the
 * characters it looks for are all present. The only thing that catches it is
 * rendering the console and looking at what came out.
 */

use App\Models\AdminUser;
use App\Services\CheckoutPage;
use App\Support\AdminCapabilities;
use Illuminate\Support\Str;

function checkoutScreenOwner(): AdminUser
{
    return AdminUser::create([
        'name' => 'Owner',
        'email' => 'chp-'.Str::random(8).'@example.com',
        'password' => bcrypt('secret'),
        'role' => 'owner',
    ]);
}

/* ------------------------------------------------------------------------
 | 1. The screen reaches the browser as JavaScript
 |------------------------------------------------------------------------*/

it('renders the checkout page screen as script, not as literal Blade', function () {
    $html = test()->actingAs(checkoutScreenOwner(), 'admin')
        ->get('/'.app(\App\Services\AdminPathService::class)->current())
        ->assertOk()
        ->getContent();

    expect($html)->toContain("var SCREEN = 'checkoutpage';")
        ->and($html)->toContain('kbbAddNavEntry')
        ->and($html)->toContain('.chp-wrap{');

    $from = strpos($html, "var SCREEN = 'checkoutpage';");
    expect($from)->not->toBeFalse();

    $mine = substr($html, (int) $from, 20000);

    expect($mine)->not->toContain('@json(')
        ->and($mine)->not->toContain('@if (')
        ->and($mine)->not->toContain('@endif')
        ->and($mine)->not->toContain('@php');
});
// MUTATION: put `@json([1,2])` inside this partial's raw block. RED on the
// `@json(` assertion.

it('appears in the Appearance group under the cart page, wrapping window.go', function () {
    $partial = (string) file_get_contents(
        resource_path('views/admin/partials/checkout-page-screen.blade.php')
    );

    expect($partial)->toContain("group: 'Appearance'")
        ->and($partial)->toContain("label: 'Checkout page'")
        // window.go is WRAPPED, not replaced. A partial that forgot to call the
        // previous handler would black out every screen registered before it —
        // and this one registers last, so it would black out all of them.
        ->and($partial)->toContain('var previousGo = window.go;')
        ->and($partial)->toContain('return previousGo.apply(this, arguments);');

    // The console includes it, which is the whole of the change to that file.
    $console = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    expect($console)->toContain("@include('admin.partials.checkout-page-screen')");
});

/* ------------------------------------------------------------------------
 | 2. The endpoints
 |------------------------------------------------------------------------*/

it('hands the screen every field, grouped into the two tabs', function () {
    $body = test()->actingAs(checkoutScreenOwner(), 'admin')
        ->getJson('/admin-api/checkout-page')->assertOk()->json();

    expect(collect($body['tabs'])->pluck('key')->all())->toBe([
        'desktop', 'desktop_head', 'desktop_type', 'desktop_rows',
        'mobile', 'mobile_head', 'mobile_type', 'mobile_rows',
        'cues',
    ])
        ->and($body['mobileMax'])->toBe(CheckoutPage::MOBILE_MAX);

    /*
     * THE TWO ROW TABS OFFER THE SAME SIX CONTROLS IN THE SAME ORDER. The
     * owner compares them by looking at them, and a slider present on one
     * surface and missing on the other is the complaint that started this:
     * "on the checkout page (desktop and mobile both) i can not control the
     * products rows".
     */
    $rowFields = fn (string $tab) => collect(collect($body['tabs'])->firstWhere('key', $tab)['fields'])
        ->pluck('key')
        ->map(fn ($k) => substr($k, 2))
        ->all();

    expect($rowFields('desktop_rows'))
        ->toBe(['items_pt', 'row_h', 'row_pt', 'row_pr', 'row_pb', 'row_pl',
            'row_gap', 'row_font', 'row_bold', 'qty_size', 'rm_size',
            'tab_min', 'tab_pad', 'tab_font', 'tab_gap'])
        ->and($rowFields('mobile_rows'))->toBe($rowFields('desktop_rows'));

    /*
     * AND SO DO THE OTHER TWO PAIRS. The header and the text sizes are the
     * same jobs on two surfaces; a control on one tab and not its twin is the
     * shape of the complaint that opened every one of these rounds.
     */
    expect($rowFields('desktop_head'))
        ->toBe(['head_pad_y', 'head_pad_x', 'head_max', 'head_logo', 'head_badge', 'head_sticky'])
        ->and($rowFields('mobile_head'))->toBe($rowFields('desktop_head'))
        /*
         * The type tabs are the one pair that is NOT identical, and the
         * difference is named rather than left to be noticed: the phone
         * carries the iOS zoom floor, and a desktop has nothing to floor.
         */
        ->and($rowFields('desktop_type'))
        ->toBe(['t_title', 't_lead', 't_h2', 't_label', 't_input', 't_ph', 't_trust'])
        ->and($rowFields('mobile_type'))
        ->toBe(['t_title', 't_lead', 't_h2', 't_label', 't_input', 't_input_floor', 't_ph', 't_trust']);

    $keys = collect($body['tabs'])->flatMap(fn ($t) => collect($t['fields'])->pluck('key'))->all();

    /*
     * Every schema key that belongs on a tab is on one, and nothing is on a tab
     * twice. A value with no control is a setting nobody can reach; a control
     * with no value saves nowhere.
     *
     * Compared as a SET, because the tabs are ordered for reading -- desktop
     * layout, desktop rows, mobile layout, mobile rows -- and the schema is
     * grouped by when each control was added. Neither order is wrong and
     * pinning one to the other would only forbid ever reordering the tabs.
     */
    expect($keys)->toBe(collect(CheckoutPage::TABS)->flatMap(fn ($t) => $t[2])->all())
        ->and($keys)->toEqualCanonicalizing(array_keys(CheckoutPage::SCHEMA))
        ->and(count($keys))->toBe(count(array_unique($keys)));
});
// MUTATION: add a key to SCHEMA and not to TABS. RED.

it('keeps the desktop and mobile values apart', function () {
    $owner = checkoutScreenOwner();

    test()->actingAs($owner, 'admin')
        ->postJson('/admin-api/checkout-page', ['settings' => ['d_sec_pad' => 28]])
        ->assertOk();

    $page = app(CheckoutPage::class);

    // The desktop slider moved and the mobile one did not. They are two rows,
    // not one row read through a breakpoint — which is the point of the whole
    // two-tab design.
    expect($page->get('d_sec_pad'))->toBe(28)
        ->and($page->get('m_sec_pad'))->toBe(16);
});

it('refuses a setting it does not know rather than dropping it in silence', function () {
    test()->actingAs(checkoutScreenOwner(), 'admin')
        ->postJson('/admin-api/checkout-page', ['settings' => ['d_gap' => 40, 'made_up' => 1]])
        ->assertStatus(422)
        ->assertJsonPath('ok', false);

    // And nothing from that request was written.
    expect(app(CheckoutPage::class)->get('d_gap'))->toBe(26);
});
// MUTATION: drop the $unknown check in save(). RED — a 200, and d_gap is 40.

it('clamps a slider that arrives outside its range', function () {
    test()->actingAs(checkoutScreenOwner(), 'admin')
        ->postJson('/admin-api/checkout-page', ['settings' => ['d_max' => 90000, 'm_sec_pad' => 0]])
        ->assertOk();

    expect(app(CheckoutPage::class)->get('d_max'))->toBe(1440)
        ->and(app(CheckoutPage::class)->get('m_sec_pad'))->toBe(6);
});
// MUTATION: return (int) $value from cast()'s 'range' arm without the clamp.
// RED — a 90000px checkout.

/* ------------------------------------------------------------------------
 | 3. The capability
 |------------------------------------------------------------------------*/

it('puts the screen behind a capability of its own, and fails closed', function () {
    expect(AdminCapabilities::CAPABILITIES)->toHaveKey('checkoutpage.manage')
        ->and(AdminCapabilities::CAPABILITIES['checkoutpage.manage'])
        ->toBe(['owner', 'manager', 'editor']);

    $rules = collect(AdminCapabilities::RULES);

    expect($rules->contains(['*', 'admin-api/checkout-page', 'checkoutpage.manage']))->toBeTrue()
        // NOT a reuse of cartpage.manage: two storefront screens that can be
        // delegated apart, and narrowing one must not narrow the other from
        // another file.
        ->and($rules->contains(['*', 'admin-api/checkout-page', 'cartpage.manage']))->toBeFalse();
});

it('keeps a support account out of it', function () {
    $support = AdminUser::create([
        'name' => 'Support', 'email' => 'chp-sup-'.Str::random(8).'@example.com',
        'password' => bcrypt('secret'), 'role' => 'support',
    ]);

    test()->actingAs($support, 'admin')
        ->postJson('/admin-api/checkout-page', ['settings' => ['d_gap' => 40]])
        ->assertForbidden();

    expect(app(CheckoutPage::class)->get('d_gap'))->toBe(26);
});

it('puts the preview beside the controls on the mobile tabs only', function () {
    /*
     * "in all mobile tabs for checkout page, i want the preview on the right
     * side, only in the mobile tabs."
     *
     * ONLY THE MOBILE ONES, and that is not a preference. The phone mock is a
     * 320px frame and sits happily in a side column; the desktop mock stands
     * for a 1040px page with two columns in it, and drawn 372px wide its
     * tracks are a few dozen pixels each and show nothing anybody can judge.
     * "Fields & attention" previews the desktop page, so it stays stacked too
     * — which is why the test is on the PREFIX and not on a list of names.
     */
    $screen = (string) file_get_contents(
        resource_path('views/admin/partials/checkout-page-screen.blade.php')
    );

    expect($screen)->toContain("var side = /^mobile/.test(String(open));")
        ->and($screen)->toContain(".chp-wrap.chp-side{grid-template-columns:minmax(0,1fr) 372px")
        // The controls and the notes go in a column of their own, or grid
        // auto-placement puts a note beside the preview and the card under it.
        ->and($screen)->toContain("(side ? '<div class=\"chp-col\">' : '')")
        ->and($screen)->toContain(".chp-wrap.chp-side > .chp-col{display:grid")
        // The controls are the long column, so the preview follows the scroll
        // rather than leaving the screen while a slider is still being dragged.
        ->and($screen)->toContain('.chp-wrap.chp-side [data-chp-preview]{position:sticky')
        // And it folds back before the two columns are too narrow to use.
        ->and($screen)->toContain('@media (max-width:1180px){');

    // Every mobile tab is caught by that prefix, and no other tab is.
    $mobile = array_filter(array_keys(\App\Services\CheckoutPage::TABS),
        fn ($k) => str_starts_with($k, 'mobile'));

    expect($mobile)->toHaveCount(4);
});
