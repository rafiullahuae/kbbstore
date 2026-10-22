<?php

declare(strict_types=1);

use App\Services\CartPage;
use App\Services\CartPanel;
use App\Services\CheckoutPage;
use App\Services\SettingsService;
use App\Support\TextSizes;

/**
 * TEXT SIZES ON THE CART PANEL, THE CART PAGE AND THE CHECKOUT (Lane CK,
 * 22 October 2026): "a little bigger", desktop and mobile, English and Arabic,
 * with a live preview -- and every new control ships at TODAY'S size.
 *
 * What each block pins, and the defect it would catch on the shop:
 *   1. Defaults print NOTHING new: no property, no class, no style block. A
 *      new default that printed would move every shop page on apply.
 *   2. Every EXISTING size key still prints exactly what it printed before
 *      (tests/Fixtures/lane-ck-sizes-before.json was captured from the code
 *      before this lane), and the stylesheet still reads it.
 *   3. A new role reaches its own text and the stylesheet carries the rule.
 *   4. Arabic values reach /ar pages only; "Same as English" prints nothing.
 *   5. Small phones: the media-query block exists only when a phone size is
 *      above today, never shrinks below today, and the maths is exact.
 *   6. No JavaScript was added for any of it; the 16px iOS floor stands.
 */
function ckSet(string $key, mixed $value): void
{
    app(SettingsService::class)->set($key, $value);
    SettingsService::forgetMemo();
}

function ckGolden(): array
{
    return json_decode((string) file_get_contents(base_path('tests/Fixtures/lane-ck-sizes-before.json')), true);
}

function ckCss(string $file): string
{
    return (string) file_get_contents(resource_path('css/kbb/'.$file));
}

/* ── 1. Defaults print nothing new ───────────────────────────────────────── */

it('prints nothing new on any of the three surfaces while every size is today\'s', function () {
    // MUTATION: give d_t_rows a default of 105. RED: a property appears.
    expect(app(CheckoutPage::class)->cssVariables())->toBe('')
        ->and(app(CheckoutPage::class)->fitStyle())->toBe('')
        ->and(app(CartPage::class)->styleAttr())->toBe('')
        ->and(app(CartPage::class)->typeVariables())->toBe('')
        ->and(app(CartPage::class)->fitStyle())->toBe('')
        ->and(app(CartPanel::class)->fitStyle())->toBe('')
        // The panel prints its full set as it always has, and not one property more.
        ->and(app(CartPanel::class)->cssVariables())->toBe('--cp-w:380px;--cp-w-m:77vw;--cp-pad:16px;--cp-pad-m:11px;--cp-rowpad:9px;--cp-rowpad-m:9px;--cp-thumb:42px;--cp-thumb-m:38px;--cp-name:13px;--cp-name-m:13px;--cp-lines:2;--cp-lines-m:2;--cp-price:1;--cp-price-m:1;--cp-step:22px;--cp-step-m:22px;--cp-rm:13px;--cp-rm-m:15px;--cp-rmbox-m:44px;--cp-x:28px;--cp-x-m:44px;--cp-xg:13px;--cp-xg-m:13px;--cp-tabh-m:44px;--cp-btngap:8px;--cp-btngap-m:6px;--cp-btnpad:12px;--cp-btnpad-m:10px;--cp-btnh-m:44px;--cp-btnr:99px;--cp-btnr-m:99px;--cp-btnf:1;--cp-btnf-m:1;--cp-accent:#C13E63;--cp-cta-bg:#C13E63;--cp-cta-fg:#FFFFFF;--cp-footgap-m:50px');

    // Every new key ships at today: 100% for a role, 0 for Overall.
    foreach ([CheckoutPage::TYPE_VARS, CartPage::TYPE_VARS, CartPanel::TYPE_VARS] as $i => $vars) {
        $schema = [CheckoutPage::SCHEMA, CartPage::SCHEMA, CartPanel::SCHEMA][$i];
        foreach ($vars as $key => [, $kind]) {
            if ($kind === 'pct') {
                expect($schema[$key][2])->toBe(100, "{$key} does not ship at today's size");
            } elseif ($kind === 'ov') {
                expect($schema[$key][2])->toBe(0, "{$key} does not ship at today's size");
            }
        }
    }
});

it('ships the field labels grey once lifted, the one default that moved, as asked', function () {
    expect(CheckoutPage::SCHEMA['fl_tone'][2])->toBe('grey')
        ->and(app(CheckoutPage::class)->bodyClass())->not->toContain('cop-flpink');

    ckSet('checkoutpage_fl_tone', 'pink');
    expect(app(CheckoutPage::class)->bodyClass())->toContain('cop-flpink');

    // A value that is not one of the select's own options stores the default.
    app(CheckoutPage::class)->save(['fl_tone' => 'red;color:red']);
    SettingsService::forgetMemo();
    expect(app(CheckoutPage::class)->get('fl_tone'))->toBe('grey');
});

/* ── 2. Existing keys: same property, same value, same selector ─────────── */

it('prints every existing checkout size key exactly as before', function () {
    foreach (ckGolden()['checkout'] as $key => $before) {
        ckSet('checkoutpage_'.$key, 120);
        // MUTATION: rename --cop-d-rowf in RATIO_VARS. RED for d_row_font.
        expect(app(CheckoutPage::class)->cssVariables())->toBe($before, $key);
        ckSet('checkoutpage_'.$key, null);
    }
});

it('prints the cart panel and the cart page row sizes exactly as before', function () {
    foreach (['name_size' => 15, 'price_size' => 110, 'btn_size' => 115, 'name_size_m' => 14, 'price_size_m' => 95, 'btn_size_m' => 120] as $k => $v) {
        ckSet('cartpanel_'.$k, $v);
    }
    foreach (['ci_brand_f' => 12, 'ci_name_f' => 140, 'ci_brand_f_m' => 11, 'ci_name_f_m' => 135] as $k => $v) {
        ckSet('cartpage_'.$k, $v);
    }

    expect(app(CartPanel::class)->cssVariables())->toBe(ckGolden()['panel'])
        ->and(app(CartPage::class)->rowCss())->toBe(ckGolden()['cartrows']);
});

it('still reads every existing size property in the same selector of the stylesheet', function () {
    $co = ckCss('kbb-checkout.css');
    $kbb = ckCss('kbb.css');

    // Each existing factor is still multiplied in where it always was (now also by the overall factor).
    expect($co)->toContain('.kbb-checkout h1.co-h{font-size:calc((19px * var(--cop-ttitle))*var(--ckt,1))')
        ->and($co)->toContain('.kbb-checkout .co-lead{font-size:calc((12.5px * var(--cop-tlead))*var(--ckt,1))')
        ->and($co)->toContain('.kbb-checkout .sec > h2{font-size:calc((15px * var(--cop-th2))*var(--ckt,1))')
        ->and($co)->toContain('.kbb-checkout .form-row label{display:block;font-size:calc(11px * var(--cop-tlabel));')
        ->and($co)->toContain('.kbb-checkout .form-row label{font-size:calc((11px * var(--cop-tlabel))*var(--ckt,1))')
        ->and($co)->toContain('--cop-ttitle:var(--cop-d-ttitle,1);')
        ->and($co)->toContain('--cop-ttitle:var(--cop-m-ttitle,1);')
        ->and($kbb)->toContain('.kc-nm{font-size:calc(var(--cp-name,12.5px)*var(--kpt,1))')
        ->and($kbb)->toContain('.kc-pr{font-size:calc((12.5px * var(--cp-price,1))*var(--kpt,1))')
        ->and($kbb)->toContain('font-size:calc((13.5px * var(--cp-btnf,1))*var(--kpt,1))');
});

it('keeps the 16px iOS floor outside every factor', function () {
    // MUTATION: put the factor around the max() instead of inside it. RED.
    expect(ckCss('kbb-checkout.css'))->toContain('font-size:max(16px, calc((16px * var(--cop-tinput))*var(--ckt,1)))');
});

it('never puts a newer CSS function next to var() outside @supports', function () {
    // CLAUDE.md's landmine: round() / color-mix() beside var() has no fallback.
    foreach (['kbb-checkout.css', 'kbb-cart.css', 'kbb.css'] as $file) {
        $css = ckCss($file);
        expect(preg_match_all('/(round|color-mix)\([^;{}]*var\(--(ckt|cks|cgt|cgs|kpt|kps)/', $css))->toBe(0, $file);
    }
});

/* ── 3. The new roles ───────────────────────────────────────────────────── */

it('prints a moved role and Overall on the checkout, and the stylesheet sets their factor', function () {
    ckSet('checkoutpage_d_t_rows', 115);
    ckSet('checkoutpage_m_ov_text', 10);

    // The lock is on (as it ships), so Spacing % follows Text %.
    expect(app(CheckoutPage::class)->cssVariables())->toBe('--cop-d-trows:1.15;--cop-m-ovt:1.1;--cop-m-ovs:1.1');

    $co = ckCss('kbb-checkout.css');
    expect($co)->toContain('--cop-trows:var(--cop-d-trows,1);')
        ->and($co)->toContain('--cop-trows:var(--cop-m-trows,1);')
        ->and($co)->toContain('.kbb-checkout :is(.sumrow,.srow){--ckt:calc(var(--cop-ovt)*var(--cop-trows))}')
        ->and($co)->toContain('--ckt:var(--cop-ovt);')
        ->and($co)->toContain('--cop-ovt:var(--cop-m-ovt,1);');
});

it('makes Spacing % follow Text % while the lock is on, and not when it is off', function () {
    ckSet('checkoutpage_d_ov_text', 12);
    ckSet('checkoutpage_d_ov_space', 4);
    expect(app(CheckoutPage::class)->cssVariables())->toBe('--cop-d-ovt:1.12;--cop-d-ovs:1.12');

    ckSet('checkoutpage_d_ov_link', false);
    expect(app(CheckoutPage::class)->cssVariables())->toBe('--cop-d-ovt:1.12;--cop-d-ovs:1.04');
});

it('prints the cart page and cart panel roles only when moved', function () {
    ckSet('cartpage_tx_total_m', 120);
    ckSet('cartpanel_tabs_size', 110);

    expect(app(CartPage::class)->styleAttr())->toBe(' style="--cgz-m-ttotal:1.2"')
        ->and(app(CartPanel::class)->cssVariables())->toEndWith(';--cp-footgap-m:50px;--cpz-d-ttabs:1.1')
        ->and(ckCss('kbb-cart.css'))->toContain('.kbb-cartpage :is(.srow.tot,.cpg-totband){--cgt:calc(var(--cgz-ovt)*var(--cgz-ttotal))}')
        ->and(ckCss('kbb.css'))->toContain('#cart .kc-tabs .kc-tab{--kpt:calc(var(--cpz-ovt)*var(--cpz-ttabs))}');
});

it('takes Overall to a row size the owner already set on the cart page', function () {
    ckSet('cartpage_ci_name_f', 140);
    expect(app(CartPage::class)->rowCss())->toContain('.cn{font-size:14px;');

    ckSet('cartpage_tx_ov_text', 10);
    expect(app(CartPage::class)->rowCss())->toContain('.cn{font-size:calc(14px*var(--cgt,1));');
});

/* ── 4. Arabic ──────────────────────────────────────────────────────────── */

it('reaches /ar pages only with an Arabic size, and prints nothing new while Arabic follows English', function () {
    ckSet('checkoutpage_d_t_title', 110);
    app(CheckoutPage::class)->saveAr([]);
    SettingsService::forgetMemo();

    app()->setLocale('ar');
    $inheriting = app(CheckoutPage::class)->cssVariables();
    app()->setLocale('en');
    // MUTATION: overlay the Arabic map on every page. RED on the English half below.
    expect($inheriting)->toBe(app(CheckoutPage::class)->cssVariables())->toBe('--cop-d-ttitle:1.1');

    app(CheckoutPage::class)->saveAr(['d_t_title' => 130, 'm_t_rows' => 120]);
    SettingsService::forgetMemo();

    expect(app(CheckoutPage::class)->cssVariables())->toBe('--cop-d-ttitle:1.1');
    app()->setLocale('ar');
    expect(app(CheckoutPage::class)->cssVariables())->toBe('--cop-d-ttitle:1.3;--cop-m-trows:1.2');
    app()->setLocale('en');
});

it('clamps an Arabic value like its slider and refuses a key that is not a size', function () {
    app(CartPanel::class)->saveAr(['tabs_size' => 999, 'accent' => '#000000']);
    SettingsService::forgetMemo();

    expect(app(CartPanel::class)->arValues())->toBe(['tabs_size' => 160]);
});

/* ── 5. Small phones ────────────────────────────────────────────────────── */

it('writes the small-phone block only for phone sizes above today, never below today', function () {
    ckSet('checkoutpage_m_ov_text', 15);   // 1.15
    ckSet('checkoutpage_m_t_title', 90);   // below today: never touched
    ckSet('checkoutpage_m_t_total', 102);  // just above: floored at 1

    // MUTATION: drop the max() with today in reducedMilli. RED: m-ttotal goes to 0.969.
    expect(app(CheckoutPage::class)->fitCss())->toBe(
        '@media (max-width:374px){.kbb-checkout{--cop-m-ovt:1.093!important;--cop-m-ovs:1.093!important;--cop-m-ttotal:1!important}}'
        .'@media (max-width:340px){.kbb-checkout{--cop-m-ovt:1.035!important;--cop-m-ovs:1.035!important;--cop-m-ttotal:1!important}}'
    )->and(app(CheckoutPage::class)->fitStyle())->toStartWith('<style id="kbb-cofit">@media (max-width:374px)');

    // Both steps at 0: nothing.
    ckSet('checkoutpage_m_fit_1', 0);
    ckSet('checkoutpage_m_fit_2', 0);
    expect(app(CheckoutPage::class)->fitCss())->toBe('');
});

it('does the sums in integer thousandths, the same ones the admin preview does', function () {
    expect(TextSizes::fromMilli('pct', TextSizes::reducedMilli('pct', 115, 100, 5)))->toBe('1.093')
        ->and(TextSizes::fromMilli('ov', TextSizes::reducedMilli('ov', 25, 0, 10)))->toBe('1.125')
        ->and(TextSizes::fromMilli('px', TextSizes::reducedMilli('px', 15, 13, 5)))->toBe('14.25px')
        ->and(TextSizes::fromMilli('px10', TextSizes::reducedMilli('px10', 140, 125, 10)))->toBe('12.6px')
        ->and(TextSizes::fromMilli('px', TextSizes::reducedMilli('px', 13, 13, 10)))->toBe('13px')
        ->and(TextSizes::emit('ov', -10))->toBe('0.9')
        ->and(TextSizes::emit('pct', 95))->toBe('0.95');

    // The admin preview's copy of the same sums (shop-type-live) uses the same formula.
    $js = (string) file_get_contents(resource_path('views/admin/partials/shop-type-live.blade.php'));
    expect($js)->toContain('Math.floor((m * (100 - cut) + 50) / 100)')
        ->and($js)->toContain("if (kind === 'ov') return (100 + v) * 10;");
});

it('reduces the cart page product-row sizes on small phones with the same rule', function () {
    ckSet('cartpage_ci_name_f_m', 140);

    expect(app(CartPage::class)->fitCss())->toBe(
        '@media (max-width:374px){.kbb-cartpage .items .ci:not(.ci-set) .cn{font-size:calc(13.3px*var(--cgt,1))!important}}'
        .'@media (max-width:340px){.kbb-cartpage .items .ci:not(.ci-set) .cn{font-size:calc(12.6px*var(--cgt,1))!important}}'
    );
});

/* ── 6. No script, and nothing on the shop's head ─────────────────────── */

it('adds no JavaScript for any of it: the small-phone block is a style element and nothing else', function () {
    ckSet('cartpanel_ov_text_m', 20);

    $style = app(CartPanel::class)->fitStyle();
    expect($style)->toStartWith('<style id="kbb-cpfit">')->and($style)->not->toContain('<script');

    // No layout-measuring API anywhere in the three stylesheets' new rules or the shared helper.
    $helper = (string) file_get_contents(app_path('Support/TextSizes.php'));
    expect($helper)->not->toContain('getBoundingClientRect')->and($helper)->not->toContain('offsetWidth');
});
