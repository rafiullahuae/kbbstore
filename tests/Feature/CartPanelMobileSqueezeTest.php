<?php

declare(strict_types=1);

/*
 * Appearance → Cart panel → Mobile: the four tap targets, the footer-button
 * arrangement, and the screen that draws them.
 *
 * ── THE 44px RULE, WHICH IS THE POINT OF THIS FILE ──────────────────────────
 *
 * Four values on the Mobile tab are not spacing, they are TOUCH TARGETS: the
 * per-line ✕, the panel's close button, the two footer buttons and the tab
 * strip. kbb.css's 900px blocks exist for no other reason than to raise them to
 * 44px, which is the smallest box a finger hits reliably.
 *
 * The owner asked to squeeze exactly these. So all three halves are pinned here,
 * because getting any one of them wrong is a bug report:
 *
 *   1. the DEFAULT stays 44, because that is what the shop renders today;
 *   2. the SLIDER GOES BELOW IT, because he asked and it is his shop — a range
 *      whose `min` were 44 would be a silent clamp wearing a schema's clothes;
 *   3. a value below 44 is SAVED AS IT IS. Nothing clamps, nothing refuses, and
 *      the screen says what the cost is under that one slider instead. A slider
 *      that stops where the owner did not ask it to stop reads as a bug and he
 *      reports it as one.
 */

use App\Models\AdminUser;
use App\Services\CartPanel;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use Illuminate\Support\Str;

function cpOwner(): AdminUser
{
    return AdminUser::create([
        'name' => 'Owner',
        'email' => 'cpm-'.Str::random(8).'@example.com',
        'password' => bcrypt('secret'),
        'role' => 'owner',
    ]);
}

/* ------------------------------------------------------------------------
 | 1. The tap targets
 |------------------------------------------------------------------------*/

it('names all four tap targets and reaches every one of them from the Mobile tab', function () {
    /*
     * The list the screen warns on. A key missing from it is a slider that drops
     * below 44 with nothing said at all, which is the silent version of the
     * behaviour this whole file is about.
     *
     * MUTATION: drop 'tab_h_m' from CartPanel::TOUCH_TARGETS. RED on the count
     * and on the membership check. Run and confirmed.
     */
    expect(CartPanel::TOUCH_TARGETS)->toBe(['rm_tap_m', 'x_size_m', 'btn_h_m', 'tab_h_m']);

    $mobile = CartPanel::TABS['mobile'][2];

    foreach (CartPanel::TOUCH_TARGETS as $key) {
        expect(in_array($key, $mobile, true))->toBeTrue(
            "{$key} is a tap target and is not on the Mobile tab, so nothing can set it"
        );
        expect(CartPanel::SCHEMA[$key][2])->toBe(44, "cartpanel_{$key} no longer ships at 44");
    }
});

it('lets every tap-target slider go below 44 rather than clamping there', function () {
    /*
     * HALF TWO OF THE RULE, and the half a cautious author gets wrong. Raising
     * `min` to 44 would read as prudence and would be a clamp — the owner asked
     * to squeeze these and the slider would refuse, silently, from inside the
     * schema where nobody would look for it.
     *
     * MUTATION: set 'min' => 44 on x_size_m. RED here. Run and confirmed.
     */
    foreach (CartPanel::TOUCH_TARGETS as $key) {
        $min = (int) CartPanel::SCHEMA[$key][4]['min'];

        expect($min)->toBeLessThan(
            44,
            "cartpanel_{$key} cannot be dragged below 44 — its minimum is {$min}, which is a "
            .'clamp the owner did not ask for'
        );
    }
});

it('saves a tap target below 44 exactly as it was sent', function () {
    /*
     * HALF THREE. The cast clamps to the field's OWN bounds and nothing else, so
     * 28 survives the round trip and the shop really does draw a 28px box.
     *
     * The clamp still does its job at the ends of the scale: 4 is below the
     * schema's own minimum and comes back as that minimum rather than as 4, which
     * is CartPanel::POLICY's `clamp => true` and not a 44px rule wearing a
     * disguise.
     *
     * MUTATION: add `max(44, ...)` anywhere in CartPanel::cast() or in the
     * stylesheet's min-height. RED on the 28 assertions. Run and confirmed.
     */
    app(CartPanel::class)->save([
        'rm_tap_m' => 28,
        'x_size_m' => 28,
        'btn_h_m' => 28,
        'tab_h_m' => 28,
    ]);

    $all = app(CartPanel::class)->all();

    foreach (CartPanel::TOUCH_TARGETS as $key) {
        expect($all[$key])->toBe(28, "cartpanel_{$key} did not store 28; something clamped it");
    }

    // And it reaches the panel, as its own property, at 28.
    $vars = app(CartPanel::class)->cssVariables();

    expect($vars)->toContain('--cp-rmbox-m:28px')
        ->and($vars)->toContain('--cp-x-m:28px')
        ->and($vars)->toContain('--cp-btnh-m:28px')
        ->and($vars)->toContain('--cp-tabh-m:28px');

    // The policy's own clamp is untouched: below the field's minimum still pulls
    // back to the minimum.
    app(CartPanel::class)->save(['x_size_m' => 4]);
    expect(app(CartPanel::class)->all()['x_size_m'])->toBe(24);
});

it('warns under the slider instead of clamping, and only for the four', function () {
    /*
     * The screen's half of the rule. One warm line, built from the value at the
     * moment it crosses 44, under that one slider — not a dialog and not a
     * refusal.
     *
     * MUTATION: change `if (Number(values[f.key]) >= touchMin) return '';` to
     * `return '';`. RED on the first two assertions. Run and confirmed.
     */
    $screen = (string) file_get_contents(
        resource_path('views/admin/partials/cart-panel-screen.blade.php')
    );

    // The warning exists, is keyed on the touch list, and compares against the
    // number the server sent rather than a 44 written here a second time.
    expect($screen)->toContain('function warnHTML(f)')
        ->and($screen)->toContain("if (touch.indexOf(f.key) === -1) return '';")
        ->and($screen)->toContain("if (Number(values[f.key]) >= touchMin) return '';")
        ->and($screen)->toContain('a finger misses this more often')
        // It says plainly that nothing is being stopped, which is the sentence
        // that makes this a warning rather than an apology for a clamp.
        ->and($screen)->toContain('nothing here stops you')
        // And the line is replaced in place on every drag, so it appears and
        // disappears as the number crosses 44 without rebuilding the slider
        // under the pointer.
        ->and($screen)->toContain("var warn = document.querySelector('[data-cpp-warn=\"' + key + '\"]');")
        ->and($screen)->toContain('warn.innerHTML = warnHTML(fld);');

    // NOTHING IN THE SCREEN CLAMPS. Math.max on a slider value would be the same
    // defect one layer out from the schema.
    expect($screen)->not->toContain('Math.max(44')
        ->and($screen)->not->toContain('Math.max(touchMin');
});

it('sends the touch list and both breakpoints to the screen rather than repeating them', function () {
    /*
     * The widths and the list are facts about the SHOP, and a second copy of a
     * fact is a copy that goes stale. The screen prints them; the server owns
     * them.
     *
     * MUTATION: drop 'touch' from the payload. RED here, and the screen's
     * warnHTML then returns '' for every field — the warning disappears with no
     * test but this one noticing.
     */
    $body = test()->actingAs(cpOwner(), 'admin')
        ->getJson('/'.app(\App\Services\AdminPathService::class)->current().'-api/cart-panel')
        ->assertOk()
        ->json();

    expect($body['touch'])->toBe(CartPanel::TOUCH_TARGETS);
    expect($body['touchMin'])->toBe(44);
    expect($body['phoneMax'])->toBe(680);
    expect($body['tapMax'])->toBe(900);

    // And the two tabs the owner asked for are the first two, in that order.
    expect(array_column($body['tabs'], 'key'))
        ->toBe(['desktop', 'mobile', 'content', 'behaviour', 'wording', 'colour']);
});

/* ------------------------------------------------------------------------
 | 2. The footer buttons' arrangement — a select that stores its own options
 |------------------------------------------------------------------------*/

it('stacks the footer buttons with a class, and only on the phone', function () {
    /*
     * A CLASS AND NOT A CUSTOM PROPERTY, and the reason is the landmine again from
     * the other side: `grid-template-columns` is set by the 680px block, and the
     * only thing that outranks a rule inside a media query is specificity.
     * `.cp-btnstack .kc-btns` has it; an inline `--cp-btncols` would have won at
     * every width and stacked the desktop panel too.
     *
     * MUTATION: delete the `cp-btnstack` line from bodyClass(). RED on the first
     * assertion, and green everywhere else in the suite — which is why this case
     * exists. Run and confirmed.
     */
    app(CartPanel::class)->save(['btn_layout_m' => 'stack']);
    expect(app(CartPanel::class)->bodyClass())->toContain('cp-btnstack');

    // `side` is the shipped arrangement and writes NOTHING, so a shop that never
    // opens this screen carries the class attribute it carries today.
    app(CartPanel::class)->save(['btn_layout_m' => 'side']);
    expect(app(CartPanel::class)->bodyClass())->toBe('');

    /*
     * And the rule is inside the phone block, after the line it has to beat.
     * Before it, the two would tie on order and `1fr 1fr` would win at the one
     * width the control is for.
     */
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));
    $phone = substr($css, (int) strpos($css, '@media(max-width:680px){'));
    $phone = substr($phone, 0, (int) strpos($phone, '/* Cart panel switches'));

    $cols = strpos($phone, '.kc-btns{grid-template-columns:1fr 1fr;');
    $stack = strpos($phone, '.cp-btnstack .kc-btns{grid-template-columns:1fr}');

    expect($cols)->not->toBeFalse();
    expect($stack)->not->toBeFalse('the stacked arrangement is not in the phone block, so it never applies');
    expect($stack)->toBeGreaterThan((int) $cols, 'the stacked rule sits above the rule it must outrank');
});

it('stores one of the select own options or the shipped default, never what arrived', function () {
    /*
     * CLAUDE.md rule 5: "A select stores one of its own options or the default."
     * The value ends up in bodyClass(), which is printed into a class attribute
     * on every page of the shop, so a value that survives from the request is a
     * value an attacker chooses.
     *
     * MUTATION: in ModuleSchema, return the raw value for an unknown select
     * option. RED on both halves below.
     */
    foreach (['stack' => 'stack', 'side' => 'side'] as $sent => $stored) {
        app(CartPanel::class)->save(['btn_layout_m' => $sent]);
        expect(app(CartPanel::class)->all()['btn_layout_m'])->toBe($stored);
    }

    foreach (['" onload="alert(1)', 'STACK', 'stack ', '', '1', 'cp-nothumb'] as $junk) {
        app(CartPanel::class)->save(['btn_layout_m' => $junk]);

        $stored = app(CartPanel::class)->all()['btn_layout_m'];

        expect($stored)->toBeIn(
            ['side', 'stack'],
            'btn_layout_m stored '.var_export($stored, true).', which is not one of its own options'
        );

        // Nothing that is not one of this screen's own two classes can reach the
        // class attribute, whatever was posted.
        expect(app(CartPanel::class)->bodyClass())->toBeIn(['', 'cp-btnstack']);
    }
});

/* ------------------------------------------------------------------------
 | 3. The factors survive a server with `precision` raised
 |------------------------------------------------------------------------*/

it('keeps a type factor to three decimals whatever the php precision is', function () {
    /*
     * 115% reaches the panel as `1.15`, and it has to reach it as `1.15` on a
     * server whose php.ini raises `precision` — where (string)(115/100) is
     * "1.1499999999999999". Seventeen digits in a style attribute, on every page,
     * for a difference of one ten-thousandth of a pixel that nobody can see.
     *
     * This case raises `precision` itself rather than trusting the runner's ini,
     * because the default of 14 hides the defect completely: with number_format
     * removed every assertion in CartPanelDeviceSetsTest still passes. That is
     * the shape of a guarantee that only holds on the machine it was written on.
     *
     * MUTATION: replace the $factor closure with
     *     static fn (int $pct): string => (string) ($pct / 100)
     * GREEN under the default precision of 14 and RED here. Run and confirmed
     * both ways.
     */
    $was = ini_get('precision');

    try {
        ini_set('precision', '17');

        app(CartPanel::class)->save(['price_size' => 115, 'price_size_m' => 70, 'btn_size' => 85]);

        $vars = app(CartPanel::class)->cssVariables();

        expect($vars)->toContain('--cp-price:1.15')
            ->and($vars)->toContain('--cp-price-m:0.7')
            ->and($vars)->toContain('--cp-btnf:0.85');

        // And no property anywhere in the string carries more than three decimals.
        foreach (explode(';', $vars) as $pair) {
            [$name, $value] = array_pad(explode(':', $pair, 2), 2, '');

            if (! preg_match('/^-?[\d.]+$/', (string) $value)) {
                continue;
            }

            expect((string) $value)->toMatch(
                '/^\d+(\.\d{1,3})?$/',
                "{$name} is '{$value}', which is a float printed at the ini's precision"
            );
        }
    } finally {
        ini_set('precision', (string) $was);
    }
});

/* ------------------------------------------------------------------------
 | 4. The screen: two previews, both live, neither measuring anything
 |------------------------------------------------------------------------*/

it('puts the mobile preview beside its controls and the desktop one below', function () {
    /*
     * The owner's words on the checkout screen, and the reason they apply here:
     * "in all mobile tabs ... i want the preview on the right side, only in the
     * mobile tabs." A 380px desktop panel drawn in a 372px rail is the same
     * drawing at the wrong scale beside controls too narrow to read.
     *
     * MUTATION: change the side test to `/^desktop/`. RED on the first
     * assertion. Run and confirmed.
     */
    $screen = (string) file_get_contents(
        resource_path('views/admin/partials/cart-panel-screen.blade.php')
    );

    // Matched on the PREFIX rather than listed, so a phone tab added later gets
    // the layout its own name claims instead of silently falling through.
    expect(substr_count($screen, "var side = /^mobile/.test(String(open));"))->toBe(1);
    expect($screen)->toContain(".cpp-wrap.cpp-side{grid-template-columns:minmax(0,1fr) 372px")
        // sticky, so the preview follows the scroll rather than leaving the top
        // of the screen while a slider is still being dragged...
        ->and($screen)->toContain('.cpp-wrap.cpp-side [data-cpp-preview]{position:sticky')
        // ...and one column below 1180px, because a 300px control column beside a
        // phone is two things nobody can use.
        ->and($screen)->toContain('@media (max-width:1180px){')
        ->and($screen)->toContain('.cpp-wrap.cpp-side{grid-template-columns:minmax(0,1fr)}');

    // The previews are chosen by the same prefix, so the tab and its drawing
    // cannot disagree about which surface is showing.
    expect($screen)->toContain("var body = /^mobile/.test(String(open)) ? previewMobile() : previewDesktop();");
});

it('redraws the preview on input rather than on save', function () {
    /*
     * "along with live previews" — which means on INPUT. A preview that waits for
     * Save is a preview of what the panel used to look like.
     *
     * The repaint replaces the preview card alone and leaves the controls
     * standing: rebuilding them mid-drag drops the pointer capture and the slider
     * stops following the finger.
     *
     * MUTATION: delete the `paintPreview();` call at the end of the input
     * handler. RED here. Run and confirmed.
     */
    $screen = (string) file_get_contents(
        resource_path('views/admin/partials/cart-panel-screen.blade.php')
    );

    $at = strpos($screen, "document.addEventListener('input', function (e) {");
    expect($at)->not->toBeFalse();

    $handler = substr($screen, (int) $at, (int) strpos($screen, "document.addEventListener('click'", (int) $at) - (int) $at);

    expect($handler)->toContain('paintPreview();')
        // By the FIELD's type and not the element's. Three selects on the checkout
        // screen saved NaN because the branch read el.type, and a select is
        // indistinguishable from a range that way.
        ->and($handler)->toContain('var kind = fld ? fld.type : ')
        ->and($handler)->not->toContain('el.type === \'range\'');

    // Both mocks are drawn from ONE function, so neither can drift from the
    // other, and every measurement in it is a custom property named for the
    // storefront property it stands for.
    expect(substr_count($screen, 'function pvPanel(k, px, vars, isPhone) {'))->toBe(1);
    expect($screen)->toContain("pvPanel(k, pvOwn, vars, false)")
        ->and($screen)->toContain("pvPanel(k, pvPx, vars, true)");
});

it('measures nothing in javascript', function () {
    /*
     * CLAUDE.md rule 4, in as many words: "No JavaScript that measures layout —
     * this project sizes with calc() for a reason, and two tests forbid the
     * element-measuring APIs by name."
     *
     * A ResizeObserver here would mean the first paint is the wrong size on every
     * phone, and it would put the screen's preview on a different footing from
     * the panel it is drawing, which is sized entirely by custom properties.
     *
     * SEARCHED IN THE CODE AND NOT IN THE COMMENT ABOVE IT. The first draft read
     * the whole file and went red on the file's own header, which says in prose
     * that it uses none of these — so the test's only finding was that the rule
     * had been written down. Everything from the raw block on is what actually
     * runs.
     */
    $whole = (string) file_get_contents(
        resource_path('views/admin/partials/cart-panel-screen.blade.php')
    );

    $screen = substr($whole, (int) strpos($whole, '<style>'));

    expect($screen)->not->toContain('getBoundingClientRect')
        ->and($screen)->not->toContain('ResizeObserver')
        ->and($screen)->not->toContain('offsetWidth')
        ->and($screen)->not->toContain('offsetHeight')
        ->and($screen)->not->toContain('clientWidth')
        ->and($screen)->not->toContain('window.innerWidth')
        ->and($screen)->not->toContain('getComputedStyle')
        ->and($screen)->not->toContain('matchMedia');
});

it('escapes every setting it prints and never builds a colour from an unchecked string', function () {
    /*
     * CLAUDE.md rule 5. The screen prints the wording settings and the three
     * colours into HTML it assembles as a string, so each one goes through esc().
     *
     * The colours are the interesting half: they reach a `style` attribute in the
     * preview, and a value that is not a colour there is a declaration break-out.
     * They cannot be — CartPanel::POLICY's `hex => repair` makes every stored
     * colour a `#rrggbb` on the way in, and that is asserted below rather than
     * assumed, because "it is repaired upstream" is what the accent's own bug
     * report said before ModuleSchema::cast() existed.
     *
     * MUTATION: drop the esc() around values.txt_tab_cart. RED on the first
     * assertion. Run and confirmed.
     */
    $screen = (string) file_get_contents(
        resource_path('views/admin/partials/cart-panel-screen.blade.php')
    );

    foreach ([
        'txt_tab_cart', 'txt_tab_browsed', 'txt_subtotal', 'txt_btn_cart',
        'txt_btn_checkout', 'txt_ship_done', 'accent', 'checkout_bg', 'checkout_fg',
    ] as $key) {
        // Every read of one of these in the preview is wrapped. Counted rather
        // than found once: an unwrapped tenth read is the defect.
        $reads = preg_match_all('/values\.' . $key . '\b/', $screen);
        $wrapped = preg_match_all('/esc\(String\(values\.' . $key . '\b/', $screen);

        expect($wrapped)->toBe(
            $reads,
            "{$key} is read {$reads} times in the screen and escaped {$wrapped} of them"
        );
    }

    // And the stored colour really is a hex, whatever was posted.
    foreach (['e23a4e', '#E23A4E', 'red; background:url(x)', 'javascript:alert(1)', ''] as $junk) {
        app(CartPanel::class)->save(['accent' => $junk]);

        expect(app(CartPanel::class)->all()['accent'])->toMatch(
            '/^#[0-9A-Fa-f]{6}$/',
            'accent stored '.var_export(app(CartPanel::class)->all()['accent'], true)
            .', which is not a hex colour and reaches a style attribute'
        );
    }
});

it('refuses a key that is not in the schema rather than writing it', function () {
    /*
     * The endpoint's own guard, and the reason it matters here: this screen posts
     * EVERY key it holds in one payload, so a forged extra key would arrive
     * alongside thirty real ones.
     */
    $owner = cpOwner();
    $base = '/'.app(\App\Services\AdminPathService::class)->current().'-api/cart-panel';

    test()->actingAs($owner, 'admin')
        ->postJson($base, ['settings' => ['row_pad_m' => 5, 'admin_path' => 'hijacked']])
        ->assertStatus(422);

    // Nothing from the refused request was written — not even the valid key
    // beside it.
    expect(app(SettingsService::class)->get('cartpanel_row_pad_m', null))->toBeNull();
    expect(app(SettingsService::class)->get('admin_path', null))->not->toBe('hijacked');
});

/* ------------------------------------------------------------------------
 | 5. Two things measurement found that reading the CSS did not
 |------------------------------------------------------------------------*/

it('shrinks the tab strip with a height rather than a minimum', function () {
    /*
     * MEASURED, AND THE FIRST VERSION WAS WRONG. tab_h_m started as
     * `.kc-tab{min-height:var(--cp-tabh-m,44px)}`, which is where the 44 it
     * replaces lived — and .kc-tab carries `padding:11px 8px`, so its natural box
     * is 39px high. Dragging the slider to 32 moved the number on the screen and
     * left the strip at 39: a min-height cannot shrink a box below its own
     * padding. Chromium at 390px said 39 while the control said 32, which is the
     * kind of defect that only a measurement finds.
     *
     * So the rule sets `height` and takes the vertical padding out of the way.
     * At the shipped 44 it renders 44, which is exactly what min-height:44px
     * rendered — measured at 44 on / and /shop with no settings stored — and the
     * tab is already a centring flex box, so the label does not move.
     *
     * MUTATION: put `.kc-tab{min-height:var(--cp-tabh-m,44px)}` back on its own.
     * RED here, and the shop goes back to answering 39 for a slider set to 32.
     */
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));

    expect($css)->toContain(
        '.kc-tab{min-height:var(--cp-tabh-m,44px);height:var(--cp-tabh-m,44px);padding-top:0;padding-bottom:0}'
    );
});

it('reads btn_h_m on the footer buttons, whatever a page sheet does to them', function () {
    /*
     * ── FOUND AND DELIBERATELY NOT FIXED ────────────────────────────────────
     *
     * The drawer's footer buttons are `.btn-ghost` and `.cobtn`, and
     * resources/css/kbb/kbb-shop.css — the SHOP and CATEGORY pages' sheet, for
     * the buttons on a product card — sets a FIXED height on both class names
     * (`.cobtn{...height:50px...}` and `.btn-ghost{...height:44px...}`). That
     * sheet loads after kbb.css and a fixed `height` beats a `min-height`
     * outright, so on those two page types the drawer's Cart button is 44px and
     * its Checkout button 50px whatever btn_h_m says.
     *
     * MEASURED in Chromium at 390px from one set of stored settings
     * (btn_h_m = 32): /shop answered Cart 44 / Checkout 50, and the home page
     * answered Cart 32 / Checkout 32. So the control governs the product page, the
     * home page, the cart, the checkout, the blog and every content page, and is
     * overridden on /shop and /category.
     *
     * WHY IT IS NOT FIXED. The fix is one drawer-scoped line —
     * `#cart .kc-btns .btn-ghost,#cart .kc-btns .cobtn{height:auto}` — whose ID
     * outranks a page sheet whatever the order. It would also take the shop
     * page's drawer Checkout button from 50px to 44px on every shop that applied
     * it: a visual change to something that works today, on a page the owner did
     * not ask about, in another lane's file. CLAUDE.md rule 1 allows one
     * exception and it is a default the owner asked for in as many words. He asked
     * for controls; whether his two footer buttons should be the same height on
     * the shop page is a decision about his shop, so it is his.
     *
     * THIS CASE PINS THE HALF THAT IS THIS LANE'S: that the panel's own rule
     * reads the setting at all. It deliberately does NOT pin the absence of the
     * fix, and it does NOT pin kbb-shop.css's two heights — the first goes red
     * the day somebody does the right thing, and the second goes red the day
     * another lane changes its own file for its own reasons. Neither is a
     * failure. The finding lives in this comment and in the lane report.
     */
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));

    expect($css)->toContain('.kc-btns .btn-ghost,.kc-btns .cobtn{min-height:var(--cp-btnh-m,44px)');
});
