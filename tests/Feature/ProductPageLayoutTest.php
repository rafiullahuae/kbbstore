<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Product;
use App\Services\ProductLayout;
use App\Services\ProductSections;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * =============================================================================
 * Appearance → Product page → Layout: the controls, and the promise that they
 * change nothing until they are moved.                          (Lane PDP2, R4)
 * =============================================================================
 *
 * ── THE OWNER, VERBATIM ─────────────────────────────────────────────────────
 *
 *   "also i have control on the product page spacing between sections and
 *    elements etc. and fonts sizes control etc. pleas give me proper tabs for
 *    that on the product page > Layout."
 *
 * ── WHAT WAS THERE BEFORE ───────────────────────────────────────────────────
 *
 * A FLAT LIST of seventeen on/off switches out of ProductSections::REGISTRY, and
 * nothing else. No spacing control, no font-size control, no tab grouping.
 *
 * ── THE TWO FAILURES THIS FILE IS POINTED AT ────────────────────────────────
 *
 *   1. A CONTROL THAT SAVES AND MOVES NOTHING. This project's recurring defect,
 *      and the reason ModuleSchema exists: `single_name` saved and nothing read
 *      it, `reassure_auth_text` had no control at all. Here the equivalent is a
 *      slider whose custom property no rule reads, or a rule whose `var()` name
 *      nothing emits. `it drives every property it emits` compares the two
 *      lists against each other, both ways.
 *
 *   2. A DEFAULT THAT MOVES THE SHOP. He asked for the CONTROLS, not for a new
 *      look, so applying this package must move nothing. That has two halves
 *      and both are pinned: storefrontCss() answers '' at the shipped values
 *      (so the page emits no <style> at all), AND every shipped value is the
 *      same number as the `var(--pl-…, <fallback>)` the stylesheet falls back
 *      to when it is absent. A default and a fallback that disagree would move
 *      the page the first time anybody touched any OTHER slider on the screen,
 *      which is the nastier half and the one only the second assertion catches.
 *
 * ── THE FALSE GREEN THIS FILE REFUSES ───────────────────────────────────────
 *
 * "The page did not move" is satisfied by a page that rendered nothing at all,
 * so every case that asserts an absence also asserts a presence: the tab-count
 * cases count fields and read their labels, and the "no style block" case
 * asserts in the same breath that the page really rendered its buy column.
 */
function pdpLayAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Layout Owner',
        'email' => 'layout-owner-'.Str::random(6).'@example.test',
        'password' => Hash::make('secret-secret'),
        'role' => 'owner',
    ]);
}

function pdpLayProduct(array $overrides = []): Product
{
    return Product::create(array_merge([
        'slug' => 'pl-'.Str::random(8),
        'name' => 'Heartleaf 77% Soothing Toner 250ml',
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 9900,
        'stock_status' => 'instock',
        'short_description' => 'A gentle daily toner for skin that reacts to everything.',
        'description' => '<p>Built around a single idea.</p>',
    ], $overrides));
}

/** The source stylesheet this screen drives. */
function pdpLaySheet(): string
{
    return file_get_contents(resource_path('css/kbb/kbb-product.css'));
}

/** `--pl-x` => `value`, out of one emitted block. @return array<string, string> */
function pdpLayParse(string $css): array
{
    preg_match_all('/(--pl-[a-z0-9-]+):([^;}]+)/', $css, $m, PREG_SET_ORDER);

    $out = [];

    foreach ($m as $row) {
        $out[$row[1]] = trim($row[2]);
    }

    return $out;
}

/* ─────────────────────────── the schema itself ────────────────────────────── */

it('groups every layout control into a tab, and no control into two', function () {
    // The guard in ModuleFrameworkGuardTest asserts this for every enrolled
    // module; it is restated here because THIS screen's whole request was
    // "proper tabs", so a field that fell out of TABS would be the feature
    // missing rather than a framework slip.
    $placed = [];

    foreach (ProductLayout::TABS as $key => [$label, $description, $keys]) {
        expect($label)->not->toBe('', "tab {$key} has no label for the strip");
        expect($description)->not->toBe('', "tab {$key} has no description for its card");
        expect($keys)->not->toBeEmpty("tab {$key} is empty, so the strip would draw a 0");

        foreach ($keys as $k) {
            $placed[] = $k;
        }
    }

    // Five since Lane PV added "Photo & badge" — the top of the page.
    expect(count(ProductLayout::TABS))->toBe(5);
    expect(array_values(array_diff(array_keys(ProductLayout::SCHEMA), $placed)))->toBe([]);
    expect(array_values(array_diff($placed, array_keys(ProductLayout::SCHEMA))))->toBe([]);
    expect(count($placed))->toBe(count(array_unique($placed)));
});

it('ships every value at the number the stylesheet already falls back to', function () {
    /*
     * THE CORE OF THE ROUND. Each property is emitted by ProductLayout::css()
     * and read in kbb-product.css as `var(--pl-x, <literal>)`. The literal is
     * what the page renders when this screen has never been touched, so if the
     * two ever disagree, the day somebody moves ONE slider every other value on
     * the page silently jumps to a number nobody chose.
     *
     * MUTATION NOTE. Change `'sec_pad' => ['range', …, 34, …]` to 36 in
     * ProductLayout::SCHEMA, or change `var(--pl-sec-pad,34px)` to 36px in
     * kbb-product.css, and this names --pl-sec-pad and both numbers. RUN: both
     * mutations were applied and each went red on its own.
     */
    $emitted = pdpLayParse(ProductLayout::css(ProductLayout::defaults()));
    $sheet = pdpLaySheet();

    // 30, and Lane PV's sixteen: the photo & badge five, ten laptop/phone
    // halves of the buy column's gaps, and the brand's size. Lane RG: the tab row's laptop gap, Tabby & Tamara's laptop gap. Lane RI: Buy these together's right-column gap.
    // Lane PX: + the brand capsule's three (on/off, tint, radius).
    expect($emitted)->toHaveCount(52);

    foreach ($emitted as $name => $value) {
        preg_match_all('/var\('.preg_quote($name, '/').'\s*,\s*([^)]+)\)/', $sheet, $m);

        expect($m[1])->not->toBeEmpty(
            "{$name} is emitted by ProductLayout::css() and read by no rule in kbb-product.css — "
            .'a control that saves and moves nothing'
        );

        foreach ($m[1] as $fallback) {
            expect(trim($fallback))->toBe($value,
                "{$name} ships at {$value} but kbb-product.css falls back to ".trim($fallback)
                .' — applying this package would move the page');
        }
    }
});

it('drives every property the stylesheet reads, and reads every one it drives', function () {
    // The other direction. A `var(--pl-…)` nobody emits is a rule that can
    // never be moved; the loop above only catches the opposite.
    preg_match_all('/var\((--pl-[a-z0-9-]+)\s*,/', pdpLaySheet(), $m);

    $read = array_values(array_unique($m[1]));
    $emitted = array_keys(pdpLayParse(ProductLayout::css(ProductLayout::defaults())));

    sort($read);
    sort($emitted);

    expect($read)->toBe($emitted);
});

/* ─────────────────────────── what reaches the page ────────────────────────── */

it('emits no style block at all while every value is where it shipped', function () {
    $product = pdpLayProduct();

    $html = $this->get('/product/'.$product->slug)->assertOk()->getContent();

    // ── THE FALSE GREEN GUARD. "No style block" is also true of a page that
    //    500ed or rendered nothing, so the page is proved to have drawn the
    //    thing the block would have styled before the absence is asserted.
    expect(substr_count($html, 'class="bb-head"'))->toBe(1);
    expect($html)->toContain('Heartleaf 77% Soothing Toner 250ml');

    expect($html)->not->toContain('kbb-pdp-layout');
    expect($html)->not->toContain('--pl-');
    expect(app(ProductLayout::class)->storefrontCss())->toBe('');
});

it('puts one style block on the page the moment a single value moves', function () {
    /*
     * MUTATION NOTE. Delete the @include of partials.product-layout-css from
     * resources/views/store/product.blade.php and this goes red on the missing
     * id — which is the "control wired end to end in the source, shipped, and
     * moving nothing" failure this shop has already paid for once. RUN.
     */
    app(ProductLayout::class)->save(['title_m' => 240]);

    $html = $this->get('/product/'.pdpLayProduct()->slug)->assertOk()->getContent();

    expect(substr_count($html, '<style id="kbb-pdp-layout">'))->toBe(1);

    preg_match('/<style id="kbb-pdp-layout">(.*?)<\/style>/s', $html, $m);

    $vars = pdpLayParse($m[1]);

    // The one that moved, and the twenty-nine that did not — emitted at exactly
    // the numbers the stylesheet would have fallen back to anyway, so the rest
    // of the page is unchanged.
    expect($vars['--pl-title-m'])->toBe('24px');
    expect($vars['--pl-title-d'])->toBe('30px');
    expect($vars['--pl-sec-pad'])->toBe('34px');
    expect($vars)->toHaveCount(52); // Lane RG: + tab_body_gap_d, paylater_gap_d; Lane RI: + bt_gap_d; Lane PX: + brand_cap, brand_bg, brand_r
});

it('prints a half-pixel size as a decimal and never as its stored integer', function () {
    // 13.5px is stored as 135. A screen or a sheet that printed the stored
    // number would render 135px text and look like a catastrophic bug rather
    // than a rounding one.
    app(ProductLayout::class)->save(['desc_s' => 145, 'desc_lh' => 175]);

    $vars = pdpLayParse(app(ProductLayout::class)->storefrontCss());

    expect($vars['--pl-desc-s'])->toBe('14.5px');
    expect($vars['--pl-desc-lh'])->toBe('1.75');
});

/* ──────────────────────────────── the screen ──────────────────────────────── */

it('answers both halves of the screen from the one endpoint', function () {
    $this->actingAs(pdpLayAdmin(), 'admin');

    $body = $this->getJson('/admin-api/product-page')->assertOk()->json();

    expect($body['sections'])->toHaveCount(count(ProductSections::REGISTRY));
    expect($body['layout'])->toHaveCount(5);

    $counts = [];

    foreach ($body['layout'] as $tab) {
        $counts[$tab['label']] = count($tab['fields']);
    }

    // The strip draws these five words and these five numbers. (Lane PV:
    // "Photo & badge" is new and first, because it is the top of the page;
    // buybox_gap moved from Page to Buy column, beside its laptop half.)
    expect($counts)->toBe([
        'Photo & badge' => 5,
        'Spacing · Page' => 5,          // Lane RG: + the tab row's laptop gap
        'Spacing · Buy column' => 21,   // Lane RG: + space above Tabby & Tamara · laptop; Lane RI: + Buy these together · right column
        'Type · Buy column' => 16,      // Lane PX: + the brand capsule's on/off, tint and radius
        'Type · Sections & tabs' => 5,
    ]);

    $photo = $body['layout'][0]['fields'];
    expect(array_column($photo, 'key'))->toBe(['gal_top_m', 'gal_top_d', 'thumb_over', 'badge_bg', 'badge_fg']);
    expect($photo[0]['value'])->toBe(0);
    expect($photo[2]['value'])->toBe('0');
    expect($photo[3]['type'])->toBe('colour');
    expect($photo[3]['value'])->toBe('#1F9D55');

    // Not merely present: a field carries what the renderer needs to draw it.
    $first = $body['layout'][1]['fields'][0];

    expect($first['key'])->toBe('sec_pad');
    expect($first['type'])->toBe('range');
    expect($first['value'])->toBe(34);
    expect($first['default'])->toBe(34);
    expect($first['label'])->toBe('Space between page sections');
});

it('saves either half on its own and leaves the other alone', function () {
    /*
     * The endpoint stopped requiring `sections` this round so the Layout tabs
     * could save without re-posting seventeen switches they never drew. This is
     * the half of that change that could go wrong: a payload with one half
     * silently blanking the other.
     */
    $this->actingAs(pdpLayAdmin(), 'admin');

    app(ProductSections::class)->save(['tabs' => ['desktop' => false, 'mobile' => false]]);

    $this->postJson('/admin-api/product-page', ['layout' => ['sec_pad' => 50]])
        ->assertOk()->assertJson(['ok' => true, 'saved' => 1]);

    expect(app(ProductLayout::class)->all()['sec_pad'])->toBe(50);
    // The switch the Layout post never mentioned is still where it was.
    expect(app(ProductSections::class)->all()['tabs']['desktop'])->toBeFalse();

    $this->postJson('/admin-api/product-page', ['sections' => [
        ['key' => 'tabs', 'desktop' => true, 'mobile' => true],
    ]])->assertOk()->assertJson(['ok' => true]);

    expect(app(ProductSections::class)->all()['tabs']['desktop'])->toBeTrue();
    // And the layout value the Sections post never mentioned survived it.
    expect(app(ProductLayout::class)->all()['sec_pad'])->toBe(50);
});

it('refuses an empty save and an unknown key rather than reporting success', function () {
    $this->actingAs(pdpLayAdmin(), 'admin');

    $this->postJson('/admin-api/product-page', [])->assertStatus(422);

    $this->postJson('/admin-api/product-page', ['layout' => ['not_a_field' => 3]])
        ->assertStatus(422)
        ->assertJson(['ok' => false]);

    // …and nothing was written on the way to the refusal.
    expect(app(ProductLayout::class)->all())->toBe(ProductLayout::defaults());
});

it('stores one of a weight control\'s own options or the shipped default', function () {
    /*
     * Rule 5, on the three fields of this screen that are printed into a
     * <style> element as a bare token rather than as a number with a unit.
     *
     * MUTATION NOTE. Make ProductLayout::weight() `return $value;` and this
     * goes red on the raw payload below. RUN.
     */
    $layout = app(ProductLayout::class);

    // THE FIRST LOCK: ModuleSchema's `select` cast, on the way in.
    $layout->save(['title_w' => '700']);
    expect($layout->all()['title_w'])->toBe('700');

    $layout->save(['title_w' => '900; color:red']);
    expect($layout->all()['title_w'])->toBe('500');

    /*
     * THE SECOND LOCK: css() itself, and it is NOT the same lock again.
     *
     * all() casts on the way out as well as on the way in, so a hand-edited
     * `settings` row — the owner has a shell on the live box — is repaired
     * before css() ever sees it. But css() is `public static` and takes a plain
     * array, so it is its own boundary and has to hold on its own, which is
     * what rule 5 means by checking the value where it is printed. Handed a
     * payload nothing cast, it still emits one of five digits.
     */
    $raw = ProductLayout::defaults();
    $raw['title_w'] = '900;}body{display:none';
    $raw['heading_w'] = ['an', 'array'];
    $raw['price_w'] = '650';

    $vars = pdpLayParse(ProductLayout::css($raw));

    expect($vars['--pl-title-w'])->toBe('500');
    expect($vars['--pl-heading-w'])->toBe('600');
    expect($vars['--pl-price-w'])->toBe('800');

    // …and a weight that IS one of the options still travels, so the guard is
    // a filter rather than a wall.
    $raw['price_w'] = '600';
    expect(pdpLayParse(ProductLayout::css($raw))['--pl-price-w'])->toBe('600');
});

it('wires the Layout tabs into the console exactly once', function () {
    /*
     * The FINISHED state, per CLAUDE.md: one tab strip, one renderer, one save
     * handler. Zero is "built, never wired up"; two registers the strip twice
     * and the second save handler would post the buffer again.
     */
    $console = file_get_contents(resource_path('views/admin/app.blade.php'));

    expect(substr_count($console, 'data-pptab="sections"'))->toBe(1);
    /*
     * ▲ ADVANCED, NOT WORKED AROUND — round 5. This read
     * `function paintProductPage(){` and the signature gained an argument:
     * `paintProductPage(rebuild)`. The preview panel holds two <iframe>s and
     * re-inserting an iframe RELOADS it, so the shell is now written once per
     * visit and a repaint refills only the parts that change; `rebuild` is how
     * renderProductPage() says it has re-fetched. The count this case is
     * really about — ONE renderer, not zero and not two — is unchanged, so the
     * needle is the name and the opening paren rather than the empty list.
     * MUTATION: declare a second `function paintProductPage(` and this reds.
     */
    expect(substr_count($console, 'function paintProductPage('))->toBe(1);
    expect(substr_count($console, 'id="ppSave"'))->toBe(1);
    // The Product page screen is still reachable from the router that draws it.
    expect(substr_count($console, 'productpage:renderProductPage'))->toBe(1);
});

it('adds no JavaScript to the product page to do any of this', function () {
    /*
     * CLAUDE.md rule 4: this project sizes with calc() and two tests forbid the
     * element-measuring APIs by name. The whole of this feature is custom
     * properties in one <style> block, so the partial that carries it may not
     * contain a script or a measurement at all.
     *
     * MUTATION NOTE. Add `<script>document.querySelector('.bb-title')
     * .getBoundingClientRect()</script>` to the partial and this names it. RUN.
     */
    $partial = file_get_contents(resource_path('views/partials/product-layout-css.blade.php'));

    foreach ([
        '<script', 'getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientWidth',
        'clientHeight', 'scrollWidth', 'getComputedStyle', 'ResizeObserver', 'addEventListener',
    ] as $forbidden) {
        expect(str_contains($partial, $forbidden))->toBeFalse(
            "partials/product-layout-css.blade.php reaches for {$forbidden}"
        );
    }

    // And it really is the file that carries the block, rather than an empty
    // file passing a search for absences.
    expect($partial)->toContain('<style id="kbb-pdp-layout">');
});
