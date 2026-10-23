<?php

declare(strict_types=1);

use App\Models\Cart;
use App\Models\Product;
use App\Services\CartPage;
use App\Services\CartPanel;
use App\Services\CartService;
use App\Services\CheckoutPage;
use App\Services\SettingsService;
use App\Support\TextSizes;
use App\Support\TextSizeTwins;
use Illuminate\Support\Str;

/**
 * TEXT SIZES ON THE CART PANEL, THE CART PAGE AND THE CHECKOUT -- AT ZERO COST
 * (Lane CK, 22 October 2026; rebuilt by Lane CK2, 23 October).
 *
 * Lane CK shipped every control by appending a "twin" of every sized rule to
 * kbb.css / kbb-cart.css / kbb-checkout.css (+4.9 KB gzip on pages that never
 * use them) and doing the size sums on every request. The owner: "I don't want
 * to compromise on speed at all." Now the twins live in a generated PHP table,
 * and ONE inline block is built at Save holding only what the moved values
 * need. What each block below pins, and the defect it would catch:
 *
 *   1. Defaults print NOTHING and store nothing: the shop is byte for byte
 *      what it was before Lane CK.
 *   2. Every EXISTING size key still prints exactly what it printed before
 *      (tests/Fixtures/lane-ck-sizes-before.json).
 *   3. A moved role prints only its own container and twins; Overall prints the
 *      multiplied twins; the lock makes Spacing follow Text.
 *   4. Arabic values reach /ar pages only.
 *   5. Small phones: only sizes above today, never below today, exact sums.
 *   6. The block is built at Save and read back from the settings map; a
 *      setting string never reaches it.
 *   7. The stylesheets carry no twin, and the admin preview builds the same
 *      string as the server (node).
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

/** The block a surface prints on this request's language, after a fresh read. */
function ckBlock(string $service): string
{
    SettingsService::forgetMemo();

    return app($service)->typeStyle();
}

/** The checkout with one line in the basket, as a shopper gets it. */
function ckCheckoutHtml(string $prefix = ''): string
{
    if ($prefix === '/ar') {
        \Tests\Support\ArabicShop::on();
    }

    $cart = Cart::create(['token' => (string) Str::uuid(), 'currency' => 'AED', 'status' => 'active',
        'shipping_country' => 'AE', 'last_activity_at' => now()]);
    $p = Product::create(['slug' => 'ck-ts-'.Str::random(8), 'name' => 'Rice Toner', 'status' => 'publish',
        'is_visible' => true, 'price' => 250, 'stock_status' => 'instock']);
    $cart->items()->create(['product_id' => $p->id, 'quantity' => 1, 'unit_price' => 25000]);

    return test()->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
        ->withUnencryptedCookie(CartService::COOKIE, $cart->token)
        ->get($prefix.'/checkout')->assertOk()->getContent();
}

/** The twins of a surface that a set of roles selects (text half), as printed. */
function ckTwinsFor(string $surface, string $role): array
{
    return array_values(array_filter(TextSizeTwins::TABLE[$surface], static fn (array $t): bool => $t[2] !== '' && in_array($role, $t[4], true)));
}

/* ── 1. Defaults: nothing printed, nothing stored, nothing computed ──────── */

it('prints nothing and stores nothing on any of the three surfaces while every size is today\'s', function () {
    // A save at the defaults (what applying the package and pressing Save does).
    app(CheckoutPage::class)->save(['d_t_rows' => 100]);
    app(CartPage::class)->save(['tx_rows' => 100]);
    app(CartPanel::class)->save(['tabs_size' => 100]);
    SettingsService::forgetMemo();

    // MUTATION: give d_t_rows a default of 105. RED: a block appears.
    expect(ckBlock(CheckoutPage::class))->toBe('')
        ->and(ckBlock(CartPage::class))->toBe('')
        ->and(ckBlock(CartPanel::class))->toBe('')
        ->and(app(SettingsService::class)->get(CheckoutPage::TYPE_CACHE))->toBeNull()
        ->and(app(SettingsService::class)->get(CartPage::TYPE_CACHE))->toBeNull()
        ->and(app(SettingsService::class)->get(CartPanel::TYPE_CACHE))->toBeNull()
        ->and(app(CheckoutPage::class)->cssVariables())->toBe('')
        ->and(app(CartPage::class)->styleAttr())->toBe('')
        // The panel prints its full set as it always has, and not one property more.
        ->and(app(CartPanel::class)->cssVariables())->toBe('--cp-w:380px;--cp-w-m:77vw;--cp-pad:16px;--cp-pad-m:11px;--cp-rowpad:9px;--cp-rowpad-m:9px;--cp-thumb:42px;--cp-thumb-m:38px;--cp-name:13px;--cp-name-m:13px;--cp-lines:2;--cp-lines-m:2;--cp-price:1;--cp-price-m:1;--cp-step:22px;--cp-step-m:22px;--cp-rm:13px;--cp-rm-m:15px;--cp-rmbox-m:44px;--cp-x:28px;--cp-x-m:44px;--cp-xg:13px;--cp-xg-m:13px;--cp-tabh-m:44px;--cp-btngap:8px;--cp-btngap-m:6px;--cp-btnpad:12px;--cp-btnpad-m:10px;--cp-btnh-m:44px;--cp-btnr:99px;--cp-btnr-m:99px;--cp-btnf:1;--cp-btnf-m:1;--cp-accent:#C13E63;--cp-cta-bg:#C13E63;--cp-cta-fg:#FFFFFF;--cp-footgap-m:50px');

    $html = ckCheckoutHtml();
    expect($html)->not->toContain('kbb-cotype')->not->toContain('kbb-cptype')->not->toContain('--ckt');

    // Every new key ships at today: 100% for a role, 0 for Overall.
    foreach ([[CheckoutPage::class, CheckoutPage::TYPE_SPEC], [CartPage::class, CartPage::typeSpec()], [CartPanel::class, CartPanel::TYPE_SPEC]] as [$class, $spec]) {
        foreach ($spec['roles'] as [$d, $m]) {
            expect($class::SCHEMA[$d][2])->toBe(100, $d)->and($class::SCHEMA[$m][2])->toBe(100, $m);
        }
        foreach ($spec['dev'] as [$text, $space, $lock]) {
            expect($class::SCHEMA[$text][2])->toBe(0)->and($class::SCHEMA[$space][2])->toBe(0)->and($class::SCHEMA[$lock][2])->toBeTrue();
        }
    }
});

it('reads the block from the memoised settings map and does no work at the defaults', function () {
    // A spy builder: at the defaults stored() must never reach it.
    $built = 0;
    $css = TextSizes::stored(app(SettingsService::class), CheckoutPage::TYPE_CACHE, function () use (&$built): array {
        $built++;

        return ['en' => 'x', 'ar' => 'x'];
    });

    // MUTATION: build in stored() instead of reading. RED: $built is 1.
    expect($css)->toBe('')->and($built)->toBe(0);
});

it('ships the field labels grey once lifted, and prints the old pink only when chosen', function () {
    expect(CheckoutPage::SCHEMA['fl_tone'][2])->toBe('grey')->and(ckBlock(CheckoutPage::class))->toBe('');

    app(CheckoutPage::class)->save(['fl_tone' => 'pink']);
    expect(ckBlock(CheckoutPage::class))->toBe('<style id="kbb-cotype">.kbb-checkout .fld.kbb-fl :is(input,textarea):is(:focus,:not(:placeholder-shown),:-webkit-autofill,:autofill) ~ label,.kbb-checkout .fld.kbb-fl select ~ label{color:var(--pink-deep)}</style>');

    // A value that is not one of the select's own options stores the default.
    app(CheckoutPage::class)->save(['fl_tone' => 'red;color:red']);
    SettingsService::forgetMemo();
    expect(app(CheckoutPage::class)->get('fl_tone'))->toBe('grey')->and(ckBlock(CheckoutPage::class))->toBe('');
});

/* ── 2. Existing keys: same property, same value ─────────────────────────── */

it('prints every existing checkout size key exactly as before', function () {
    foreach (ckGolden()['checkout'] as $key => $before) {
        ckSet('checkoutpage_'.$key, 120);
        // MUTATION: rename --cop-d-rowf in VARS. RED for d_row_font.
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

/* ── 3. A moved role prints only its rule; Overall prints the multiplied rules ── */

it('prints a moved role\'s container and only that role\'s twins', function () {
    app(CheckoutPage::class)->save(['d_t_rows' => 120]);
    $block = ckBlock(CheckoutPage::class);

    // The desktop factors: every container (they nest), rows at 1.2, the rest at 1.
    expect($block)->toStartWith('<style id="kbb-cotype">@media(min-width:901px){.kbb-checkout :is(.sumrow,.srow){--ckt:1.2}.kbb-checkout :is(.sumrow.tot,.srow.tot,.cosr-tot){--ckt:1}')
        // Not on a phone, and no Overall factor.
        ->and($block)->not->toContain('@media(max-width:900px){.kbb-checkout :is(')
        ->and($block)->not->toContain('.kbb-checkout{--ckt');

    // MUTATION: select every twin for a role. RED: the page title's twin appears.
    $rows = ckTwinsFor('checkout', 'rows');
    expect($rows)->not->toBeEmpty();
    foreach ($rows as $t) {
        expect($block)->toContain($t[1].'{'.$t[2].'}');
    }
    expect($block)->not->toContain('.kbb-checkout h1.co-h{')
        ->and(substr_count($block, 'var(--ckt,1)'))->toBe(array_sum(array_map(static fn (array $t): int => substr_count($t[2], 'var(--ckt,1)'), $rows)))
        ->and($block)->not->toContain('var(--cks,1)');
});

it('prints the multiplied rules for Overall, per device, and Spacing follows Text while locked', function () {
    app(CheckoutPage::class)->save(['m_ov_text' => 10]);
    $block = ckBlock(CheckoutPage::class);

    expect($block)->toContain('@media(max-width:900px){.kbb-checkout{--ckt:1.1;--cks:1.1}}')
        ->and($block)->not->toContain('@media(min-width:901px){.kbb-checkout{')
        // Every text twin and every spacing twin, the 16px iOS floor kept outside the factor.
        ->and(substr_count($block, 'var(--ckt,1)'))->toBe(array_sum(array_map(static fn (array $t): int => substr_count($t[2], 'var(--ckt,1)'), TextSizeTwins::TABLE['checkout'])))
        ->and($block)->toContain('.kbb-checkout h1.co-h{font-size:calc((19px * var(--cop-ttitle))*var(--ckt,1));margin-bottom:calc(2px*var(--cks,1))}')
        ->and($block)->toContain('font-size:max(16px, calc((16px * var(--cop-tinput))*var(--ckt,1)))')
        ->and(substr_count($block, 'var(--cks,1)'))->toBeGreaterThan(100);

    // Unlocked, Spacing keeps its own value.
    app(CheckoutPage::class)->save(['m_ov_link' => false, 'm_ov_space' => 4]);
    expect(ckBlock(CheckoutPage::class))->toContain('@media(max-width:900px){.kbb-checkout{--ckt:1.1;--cks:1.04}}');

    // Text back at 0 and spacing only: no text twin at all.
    app(CheckoutPage::class)->save(['m_ov_text' => 0]);
    $block = ckBlock(CheckoutPage::class);
    expect($block)->toContain('{.kbb-checkout{--cks:1.04}}')->and($block)->not->toContain('var(--ckt,1)');
});

it('prints the cart page and the cart panel roles only when moved, on their own pages', function () {
    app(CartPage::class)->save(['tx_total_m' => 120]);
    app(CartPanel::class)->save(['tabs_size' => 110]);

    $cart = ckBlock(CartPage::class);
    $panel = ckBlock(CartPanel::class);

    expect($cart)->toStartWith('<style id="kbb-carttype">@media(max-width:900px){')
        ->and($cart)->toContain('.kbb-cartpage :is(.srow.tot,.cpg-totband){--cgt:1.2}')
        ->and($panel)->toStartWith('<style id="kbb-cptype">@media(min-width:681px){')
        ->and($panel)->toContain('#cart .kc-tabs .kc-tab{--kpt:1.1}')
        ->and($panel)->toContain('.kc-tab{font-size:calc(12.5px*var(--kpt,1))}')
        // Neither surface's twins on the other.
        ->and($cart)->not->toContain('--kpt')->and($panel)->not->toContain('--cgt');
});

it('takes Overall to a row size the owner already set on the cart page', function () {
    app(CartPage::class)->save(['ci_name_f' => 140, 'tx_ov_text' => 10]);
    $block = ckBlock(CartPage::class);

    expect(app(CartPage::class)->rowCss())->toContain('.cn{font-size:14px;')
        ->and($block)->toContain('.kbb-cartpage .items .ci:not(.ci-set) .cn{font-size:calc(14px*var(--cgt,1))}')
        ->and($block)->toContain('@media (max-width:600px){.kbb-cartpage .items .ci:not(.ci-set) .cbrand{font-size:calc(10px*var(--cgt,1))}');

    // Row sizes at their defaults: rowCss prints nothing, so neither does the block.
    app(CartPage::class)->save(['ci_name_f' => 130]);
    expect(ckBlock(CartPage::class))->not->toContain('.ci:not(.ci-set) .cn{');
});

/* ── 4. Arabic ──────────────────────────────────────────────────────────── */

it('reaches /ar pages only with an Arabic size, and only where it differs', function () {
    app(CheckoutPage::class)->save(['d_t_title' => 110]);
    app(CheckoutPage::class)->saveAr(['d_t_title' => 130, 'm_t_rows' => 120]);

    expect(ckBlock(CheckoutPage::class))->toBe('');
    app()->setLocale('ar');
    $ar = ckBlock(CheckoutPage::class);
    app()->setLocale('en');

    // MUTATION: print the Arabic map on every page. RED on the English half above.
    expect($ar)->toContain('@media(max-width:900px){.kbb-checkout :is(.sumrow,.srow){--ckt:1.2}')
        ->and($ar)->toContain('.kbb-checkout{--cop-d-ttitle:1.3!important}')
        // English still prints its own value in the style attribute.
        ->and(app(CheckoutPage::class)->cssVariables())->toBe('--cop-d-ttitle:1.1');

    // In the page: /ar carries the block, / does not.
    expect(ckCheckoutHtml())->not->toContain('kbb-cotype');
    expect(ckCheckoutHtml('/ar'))->toContain('<style id="kbb-cotype">');
});

it('prints nothing on /ar while Arabic follows English', function () {
    app(CheckoutPage::class)->saveAr([]);
    app()->setLocale('ar');
    expect(ckBlock(CheckoutPage::class))->toBe('');
    app()->setLocale('en');
});

it('clamps an Arabic value like its slider and refuses a key that is not a size', function () {
    app(CartPanel::class)->saveAr(['tabs_size' => 999, 'accent' => '#000000']);
    SettingsService::forgetMemo();

    expect(app(CartPanel::class)->arValues())->toBe(['tabs_size' => 160]);
});

/* ── 5. Small phones ────────────────────────────────────────────────────── */

it('takes phone sizes above today down on small phones, never below today', function () {
    app(CheckoutPage::class)->save(['m_ov_text' => 15, 'm_t_title' => 120, 'm_t_lead' => 90, 'm_t_total' => 102]);
    $block = ckBlock(CheckoutPage::class);

    // MUTATION: drop the max() with today in reducedMilli. RED: total goes under 1.
    expect($block)->toContain('@media (max-width:374px){.kbb-checkout{--ckt:1.093;--cks:1.093}')
        ->and($block)->toContain('.kbb-checkout :is(.sumrow.tot,.srow.tot,.cosr-tot){--ckt:1.093}')
        ->and($block)->toContain('.kbb-checkout{--cop-m-ttitle:1.14!important}}')
        ->and($block)->toContain('@media (max-width:340px){.kbb-checkout{--ckt:1.035;--cks:1.035}')
        ->and($block)->toContain('.kbb-checkout{--cop-m-ttitle:1.08!important}}')
        // Below today is never touched.
        ->and($block)->not->toContain('--cop-m-tlead');

    // Both steps at 0: no media block for them.
    app(CheckoutPage::class)->save(['m_fit_1' => 0, 'm_fit_2' => 0]);
    expect(ckBlock(CheckoutPage::class))->not->toContain('max-width:374px')->not->toContain('max-width:340px');
});

it('reduces the cart page product-row sizes on small phones with the same rule', function () {
    app(CartPage::class)->save(['ci_name_f_m' => 140]);

    expect(ckBlock(CartPage::class))->toContain('@media (max-width:374px){.kbb-cartpage .items .ci:not(.ci-set) .cn{font-size:calc(13.3px*var(--cgt,1))!important}}')
        ->and(ckBlock(CartPage::class))->toContain('@media (max-width:340px){.kbb-cartpage .items .ci:not(.ci-set) .cn{font-size:calc(12.6px*var(--cgt,1))!important}}');
});

it('does the sums in integer thousandths', function () {
    expect(TextSizes::fromMilli('pct', TextSizes::reducedMilli('pct', 115, 100, 5)))->toBe('1.093')
        ->and(TextSizes::fromMilli('ov', TextSizes::reducedMilli('ov', 25, 0, 10)))->toBe('1.125')
        ->and(TextSizes::fromMilli('px', TextSizes::reducedMilli('px', 15, 13, 5)))->toBe('14.25px')
        ->and(TextSizes::fromMilli('px10', TextSizes::reducedMilli('px10', 140, 125, 10)))->toBe('12.6px')
        ->and(TextSizes::fromMilli('px', TextSizes::reducedMilli('px', 13, 13, 10)))->toBe('13px')
        ->and(TextSizes::emit('ov', -10))->toBe('0.9')
        ->and(TextSizes::emit('pct', 95))->toBe('0.95');
});

/* ── 6. Built at Save, read from the map, never a setting string ────────── */

it('rebuilds the stored block on every save and drops it back to nothing at the defaults', function () {
    app(CartPanel::class)->save(['sum_size_m' => 130]);
    $row = app(SettingsService::class)->get(CartPanel::TYPE_CACHE);
    expect($row)->toBeArray()->and($row['v'])->toBe(TextSizeTwins::VERSION)->and($row['en'])->toContain('#cart .sumrow{--kpt:1.3}');

    // MUTATION: drop refreshTypeCss() from save(). RED: the old block stays.
    app(CartPanel::class)->save(['sum_size_m' => 140]);
    expect(ckBlock(CartPanel::class))->toContain('#cart .sumrow{--kpt:1.4}');

    app(CartPanel::class)->save(['sum_size_m' => 100]);
    expect(ckBlock(CartPanel::class))->toBe('')->and(app(SettingsService::class)->get(CartPanel::TYPE_CACHE))->toBeNull();
});

it('rebuilds a block stored under another table version for the request', function () {
    app(CartPanel::class)->save(['sum_size' => 120]);
    ckSet(CartPanel::TYPE_CACHE, ['v' => 'older', 'en' => 'stale{}', 'ar' => 'stale{}']);

    expect(ckBlock(CartPanel::class))->toContain('#cart .sumrow{--kpt:1.2}')->not->toContain('stale');
});

it('never lets a setting string into the block', function () {
    // Crafted rows, written straight into the table (a save would refuse them).
    foreach (['checkoutpage_d_t_rows' => '120}</style><script>alert(1)</script>', 'checkoutpage_m_ov_text' => '10;color:red',
        'checkoutpage_fl_tone' => 'pink</style>', 'checkoutpage_m_fit_1' => 'x'] as $k => $v) {
        ckSet($k, $v);
    }
    app(CheckoutPage::class)->refreshTypeCss();
    $block = ckBlock(CheckoutPage::class);

    expect($block)->not->toContain('<script')->not->toContain('alert')->not->toContain('color:red')
        ->and(substr_count($block, '</style>'))->toBe(1)
        // Every number in the factor rules is a decimal the sums made.
        ->and(preg_match_all('/--(ckt|cks):([^;}]*)/', $block, $m))->toBeGreaterThan(0);
    foreach ($m[2] as $value) {
        expect($value)->toMatch('/^\d+(\.\d+)?$/');
    }
});

/* ── 7. The stylesheets carry no twin; the admin builds the same string ─── */

it('keeps every twin out of the stylesheets and the cart page\'s style blocks', function () {
    foreach (['kbb-checkout.css', 'kbb-cart.css', 'kbb.css'] as $file) {
        // MUTATION: append Lane CK's twins again. RED.
        expect(preg_match('/var\(--(ckt|cks|cgt|cgs|kpt|kps)\b/', ckCss($file)))->toBe(0, $file);
    }
    foreach (['store/cart-squeeze.blade.php', 'store/cart-inner.blade.php'] as $view) {
        expect(preg_match('/var\(--(cgt|cgs)\b/', (string) file_get_contents(resource_path('views/'.$view))))->toBe(0, $view);
    }
});

it('generates the twin table with plain calc() and var() only', function () {
    foreach (TextSizeTwins::TABLE as $surface => $rows) {
        expect($rows)->not->toBeEmpty();
        foreach ($rows as [$ctx, $sel, $t, $s, $roles]) {
            // CLAUDE.md's landmine: nothing newer than var() next to one.
            expect($t.$s)->not->toMatch('/(round|color-mix)\(/')
                ->and($sel.$t.$s)->not->toContain('<')
                ->and($ctx === '' || str_starts_with($ctx, '@'))->toBeTrue();
        }
    }
});

it('builds the same block in the admin preview as on the shop (node)', function () {
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    if ($node === '') {
        $this->markTestSkipped('node is not installed');
    }

    $blade = (string) file_get_contents(resource_path('views/admin/partials/shop-type-live.blade.php'));
    $from = strpos($blade, 'function milli(');
    $js = substr($blade, $from, strpos($blade, 'function links(') - $from);

    $cases = [];
    $all = static fn (string $class): array => app($class)->all();
    $sets = [
        [CheckoutPage::class, CheckoutPage::TYPE_SPEC, ['d_t_rows' => 120, 'm_ov_text' => 15, 'm_t_title' => 120, 'fl_tone' => 'pink'], ['d_t_title' => 130, 'm_t_rows' => 125]],
        [CheckoutPage::class, CheckoutPage::TYPE_SPEC, ['d_ov_text' => -5, 'd_ov_link' => false, 'd_ov_space' => 8, 'd_t_flift' => 110], []],
        [CartPage::class, CartPage::typeSpec(), ['ci_name_f_m' => 140, 'tx_ov_text' => 10, 'tx_help_m' => 130], ['ci_name_f' => 150]],
        [CartPanel::class, CartPanel::TYPE_SPEC, ['name_size_m' => 15, 'coupon_size' => 120, 'ov_space_m' => 5, 'ov_link_m' => false], ['tabs_size_m' => 110]],
    ];
    foreach ($sets as [$class, $spec, $values, $ar]) {
        app($class)->save($values);
        SettingsService::forgetMemo();
        $en = $all($class);
        $cases[] = ['spec' => $spec, 'twins' => TextSizeTwins::TABLE[$spec['surface']], 'en' => $en, 'ar' => $ar,
            'php' => [TextSizes::css($spec, $en), TextSizes::css($spec, $en, $ar)]];
    }

    $dir = storage_path('framework/testing');
    @mkdir($dir, 0777, true);
    $file = $dir.'/ck2-parity-'.getmypid().'.json';
    file_put_contents($file, json_encode($cases));
    $script = $dir.'/ck2-parity-'.getmypid().'.cjs';
    file_put_contents($script, $js."\nconst c = JSON.parse(require('fs').readFileSync(process.argv[2], 'utf8'));\n"
        ."process.stdout.write(JSON.stringify(c.map((x) => [typeCss(x.spec, x.twins, x.en, {}), typeCss(x.spec, x.twins, x.en, x.ar)])));\n");
    $out = shell_exec(escapeshellarg($node).' '.escapeshellarg($script).' '.escapeshellarg($file));
    @unlink($file);
    @unlink($script);

    $js = json_decode((string) $out, true);
    expect($js)->toBeArray();
    foreach ($cases as $i => $case) {
        // MUTATION: change the container decimals to 3 places in the JS. RED.
        expect($case['php'][0])->not->toBe('')
            ->and($js[$i][0])->toBe($case['php'][0], "case {$i} English")
            ->and($js[$i][1])->toBe($case['php'][1], "case {$i} Arabic");
    }
});

it('adds no JavaScript to the shop for any of it', function () {
    $js = (string) file_get_contents(resource_path('js/kbb/checkout.js'));
    expect($js)->not->toContain('kbb-req-miss')->not->toContain('markEmptyRequired');

    app(CartPanel::class)->save(['ov_text_m' => 20]);
    $style = ckBlock(CartPanel::class);
    expect($style)->toStartWith('<style id="kbb-cptype">')->and($style)->not->toContain('<script');

    $helper = (string) file_get_contents(app_path('Support/TextSizes.php'));
    expect($helper)->not->toContain('getBoundingClientRect')->and($helper)->not->toContain('offsetWidth');
});
