<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Product;
use App\Services\ProductDesktopSections;
use App\Services\ProductLayout;
use App\Services\ProductMobileSections;
use App\Services\ProductSections;
use App\Services\SettingsService;
use App\Support\RichText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Lane RG — the product page's laptop switches, the buy column's laptop order,
 * Tabby & Tamara on laptops, and the details block.
 *
 *   "in fact there is control but i turned it off, still it's showing bundle
 *    section on desktop."
 *   "also give control to hide unhide any section on desktop too"
 *   "Also i want tabby tamara section in desktop also with controls."
 *   "and also controls for changing positions of the sections on desktop too."
 *   "give controls of details tab heading and the content between spacing as
 *    marked, also seperate for mobile."
 *   "need spacing beteen the tab heading and content in mobile, and desktop
 *    both. also the section heading also i want to hide. and text THE DETAILS
 *    and section heading. give controls for desktop and mobile both. and keep
 *    hide by default."
 *
 * WHAT A DEFECT LOOKS LIKE ON THE SHOP is in each case's own comment.
 * MUTATION NOTES marked RUN were made and reverted on this branch, each one
 * turning the named case red.
 */
function rgAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'Lane RG '.$role,
        'email' => 'rg-'.$role.'-'.Str::random(6).'@example.test',
        'password' => Hash::make('secret-secret'),
        'role' => $role,
    ]);
}

function rgProduct(array $over = []): Product
{
    return Product::create($over + [
        'slug' => 'rg-'.Str::lower(Str::random(8)),
        'name' => 'Isntree Hyaluronic Acid Watery Sun Gel',
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 8000,
        'stock_status' => 'instock',
        'short_description' => '<p>A light daily sun gel.</p>',
        'description' => '<p>Hydrating sun gel.</p>',
    ]);
}

function rgPage(Product $product): string
{
    return (string) test()->get('/product/'.$product->slug.'/')->assertOk()->getContent();
}

function rgFlush(): void
{
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();
}

function rgWrapper(string $html): string
{
    expect(preg_match('#<div class="wrap pdp-page[^"]*" style="[^"]*">#', $html, $m))->toBe(1, 'the product page wrapper is missing');

    return $m[0];
}

function rgCss(): string
{
    return (string) file_get_contents(resource_path('css/kbb/kbb-product.css'));
}

/** The stylesheet's rules without its comments (which name these classes in prose). */
function rgRules(): string
{
    return (string) preg_replace('#/\*.*?\*/#s', '', rgCss());
}

/** Every `@media <query>{…}` block in the product stylesheet, joined. */
function rgMedia(string $query): string
{
    $css = rgRules();
    $out = '';
    $offset = 0;
    $open = '@media '.$query.'{';

    while (($at = strpos($css, $open, $offset)) !== false) {
        $i = $at + strlen($open);
        $depth = 1;

        while ($depth > 0 && $i < strlen($css)) {
            $depth += $css[$i] === '{' ? 1 : ($css[$i] === '}' ? -1 : 0);
            $i++;
        }

        $out .= substr($css, $at + strlen($open), $i - $at - strlen($open) - 1)."\n";
        $offset = $i;
    }

    return $out;
}

/** The stylesheet with every @media block cut out: what applies at EVERY width. */
function rgOutsideMedia(): string
{
    $css = rgRules();
    $out = '';
    $i = 0;

    while (($at = strpos($css, '@media', $i)) !== false) {
        $out .= substr($css, $i, $at - $i);
        $j = strpos($css, '{', $at) + 1;
        $depth = 1;

        while ($depth > 0 && $j < strlen($css)) {
            $depth += $css[$j] === '{' ? 1 : ($css[$j] === '}' ? -1 : 0);
            $j++;
        }

        $i = $j;
    }

    return $out.substr($css, $i);
}

/* ═══════════════ 1. THE BUNDLES BUG ══════════════════════════════════════ */

it('hides the whole bundles block on a laptop when Sections → Options / bundles → Desktop is off', function () {
    /*
     * THE DEFECT, AS HE SAW IT: on his laptop (about 1608px), "Isntree -
     * Hyaluronic Acid Watery Sun Gel", a SIMPLE product, still showed "Choose
     * your option · Save more with bundles" and the 1 unit / 2-pack / 3-pack
     * rows with that switch OFF. The switch was read in one place only — the
     * class on a VARIABLE product's `.variants` — so the quantity-bundles
     * branch and the "Choose your option" label never carried it. Reproduced
     * in Chromium through the real admin endpoint before the fix: the block
     * was 202px tall at 881, 890, 900, 1000, 1280, 1440 and 1608px; after it,
     * 0px at all of them and 181px at 390 (the phone is Mobile sections').
     *
     * MUTATION NOTE, RUN: make ProductDesktopSections::wrapperClass() skip the
     * `pd-off-` loop → RED on the wrapper assertion. Delete
     * `.pdp-page.pd-off-bundles .pm-bundles` from the laptop block → RED on
     * the stylesheet assertion.
     */
    $p = rgProduct();
    $html = rgPage($p);
    expect($html)->toContain('<div class="pm-sec pm-bundles">');
    expect($html)->toContain(__('store.product.bundles_note'));

    test()->actingAs(rgAdmin(), 'admin');
    $rows = array_map(static fn (array $s) => ['key' => $s['key'], 'desktop' => $s['key'] === 'options' ? false : $s['desktop'], 'mobile' => $s['mobile']],
        array_values(app(ProductSections::class)->all()));
    test()->postJson('/admin-api/product-page', ['sections' => $rows])->assertOk();
    rgFlush();

    $wrapper = rgWrapper(rgPage($p));
    expect($wrapper)->toContain(' pd-off-bundles');

    $laptop = rgMedia('(min-width:881px)');
    expect($laptop)->toContain('.pdp-page.pd-off-bundles .pm-bundles');
    // and never below 881px: the phone's bundles are Mobile sections' switch.
    expect(rgMedia('(max-width:880px)'))->not->toContain('pd-off-');
    expect(rgOutsideMedia())->not->toContain('.pd-off-');
});

it('hides a module switched off for laptops from 881px, where this page turns, not from kbb.css’s 901px', function () {
    /*
     * THE DEFECT: `.d-off` is kbb.css's homepage rule, `min-width:901px`. This
     * page is already its laptop layout at 881px, so at 881–900px every module
     * switched off for laptops was still drawn. And `.m-off` (max-width:900px)
     * hid a phone-only-off module on that same laptop strip.
     *
     * MUTATION NOTE, RUN: change the new rule to `min-width:901px` → RED.
     * Make classFor() emit `m-off` again for a desktop-on module → RED on the
     * `pdp-m-off` assertion.
     */
    expect(rgMedia('(min-width:881px)'))->toContain('.pdp-page .d-off{display:none !important}');
    expect(rgMedia('(max-width:880px)'))->toContain('.pdp-page .pdp-m-off{display:none !important}');

    $modules = app(ProductSections::class);
    $modules->save(['capsule' => ['desktop' => true, 'mobile' => false], 'stockline' => ['desktop' => false, 'mobile' => false], 'cutoff' => ['desktop' => false, 'mobile' => true]]);
    rgFlush();
    $modules = app(ProductSections::class);

    expect($modules->classFor('capsule'))->toBe('pdp-m-off');
    // Off on both: byte for byte what it always printed.
    expect($modules->classFor('stockline'))->toBe('d-off m-off');
    expect($modules->classFor('cutoff'))->toBe('d-off');
    expect($modules->classFor('rating'))->toBe('');
});

/* ═══════════════ 2. ONE LAPTOP SWITCH PER SECTION, ONE STORED VALUE ═════ */

it('reads and writes a module-owned section’s laptop switch in the Sections row itself', function () {
    /*
     * THE DEFECT THIS REPO KEEPS PAYING FOR: two controls writing two values.
     * Desktop sections' "Bundle section" switch must BE Sections → "Options /
     * bundles" → Desktop: the same row of `product_sections`, so switching
     * either shows on both and the page obeys one value.
     *
     * MUTATION NOTE, RUN: make saveLaptop() write every key into `pdpds_off`
     * → RED on the ProductSections assertion (and the stored-row one).
     */
    test()->actingAs(rgAdmin(), 'admin');
    test()->postJson('/admin-api/product-page', ['dsections' => ['laptop' => ['bundles' => false, 'title' => false, 'details' => false]]])
        ->assertOk()
        ->assertJsonPath('sections.'.array_search('options', array_keys(ProductSections::REGISTRY), true).'.desktop', false);
    rgFlush();

    $all = app(ProductSections::class)->all();
    expect($all['options']['desktop'])->toBeFalse();
    expect($all['options']['mobile'])->toBeTrue();   // untouched
    expect($all['tabs']['desktop'])->toBeFalse();

    $stored = json_decode((string) DB::table('settings')->where('key', 'product_sections')->value('value'), true);
    expect($stored['options'])->toBe(['desktop' => false, 'mobile' => true]);
    expect(json_decode((string) DB::table('settings')->where('key', ProductDesktopSections::OFF_KEY)->value('value'), true))->toBe(['title']);

    // And the other way round: the Sections tab's save is what this tab reads.
    $rows = array_map(static fn (array $s) => ['key' => $s['key'], 'desktop' => $s['key'] === 'options' ? true : $s['desktop'], 'mobile' => $s['mobile']], array_values($all));
    $body = test()->postJson('/admin-api/product-page', ['sections' => $rows])->assertOk()->json();
    $buy = collect($body['dsections']['buy'])->keyBy('key');
    expect($buy['bundles']['desktop'])->toBeTrue();
    expect($buy['bundles']['module'])->toBe('options');
    expect($buy['title']['desktop'])->toBeFalse();
    expect($buy['title']['module'])->toBeNull();
});

it('refuses an unknown section, a non-boolean switch and a bad buy order, and saves nothing on a refusal', function () {
    /*
     * THE DEFECT: a typo reporting "Saved" while writing nothing, or a switch
     * stored as a string that reads as on.
     *
     * MUTATION NOTE, RUN: drop the `in_array($key, $all)` check in
     * validateLaptop() → RED on 'Unknown desktop section: gallery.'.
     */
    test()->actingAs(rgAdmin(), 'admin');
    test()->postJson('/admin-api/product-page', ['dsections' => ['laptop' => ['gallery' => false]]])->assertStatus(422)->assertJson(['error' => 'Unknown desktop section: gallery.']);
    test()->postJson('/admin-api/product-page', ['dsections' => ['laptop' => ['title' => 'off']]])->assertStatus(422);
    test()->postJson('/admin-api/product-page', ['dsections' => ['laptop' => [false]]])->assertStatus(422);
    test()->postJson('/admin-api/product-page', ['dsections' => ['buy' => ['title', 'title']]])->assertStatus(422)->assertJson(['error' => 'Buy column block listed twice: title.']);
    test()->postJson('/admin-api/product-page', ['dsections' => ['buy' => ['reviews']]])->assertStatus(422)->assertJson(['error' => 'Unknown buy column block: reviews.']);
    test()->postJson('/admin-api/product-page', ['dsections' => ['laptop' => ['title' => false], 'buy' => ['nope']]])->assertStatus(422);

    expect(DB::table('settings')->whereIn('key', [ProductDesktopSections::OFF_KEY, ProductDesktopSections::BUY_ORDER_KEY])->count())->toBe(0);
});

it('has a laptop-only hide rule for every switchable section, and the photo is not switchable', function () {
    /*
     * THE DEFECT: a switch in the admin with no rule behind it — "I turned it
     * off and it still shows", the bundles bug in its general form.
     *
     * MUTATION NOTE, RUN: delete `.pdp-page.pd-off-auth .kbb-cart-form >
     * .pts-stack,` → RED for auth.
     */
    $laptop = rgMedia('(min-width:881px)');

    foreach (ProductDesktopSections::switchable() as $key) {
        expect((bool) preg_match('/\.pdp-page\.pd-off-'.$key.'[ >][^{]*[,{]/', $laptop))->toBeTrue("no laptop off rule for {$key}");
    }

    expect(ProductDesktopSections::switchable())->not->toContain('gallery');
    expect(ProductDesktopSections::switchable())->toHaveCount(15);
});

it('prints nothing on the wrapper while every laptop switch is on and both orders are the default', function () {
    /*
     * THE DEFECT: a package that moves the page before he touches anything.
     * The shipped page's wrapper is byte-identical (RF's pin, kept).
     */
    $wrapper = rgWrapper(rgPage(rgProduct()));
    expect($wrapper)->not->toContain('pd-off-');
    expect($wrapper)->not->toContain('pdsb-');
    expect($wrapper)->not->toContain('pds-on');
    expect(app(ProductDesktopSections::class)->wrapperClass())->toBe('');
    expect(app(ProductDesktopSections::class)->wrapperStyle())->toBe('');
});

/* ═══════════════ 3. TABBY & TAMARA ON A LAPTOP ═══════════════════════════ */

it('draws Tabby & Tamara on a laptop by default, with its own gap and its own laptop switch', function () {
    /*
     * THE OWNER ASKED: "Also i want tabby tamara section in desktop also with
     * controls." It used to be hidden from 881px outright. A DEFAULT THAT
     * MOVES, because he asked.
     *
     * MUTATION NOTE, RUN: put back `@media (min-width:881px){.pdp
     * .pm-paylater{display:none}}` → RED on the first assertion.
     */
    expect(rgCss())->not->toContain('.pdp .pm-paylater{display:none}');
    expect(rgMedia('(min-width:881px)'))->toContain('.pdp .pm-paylater{margin-block-start:var(--pl-paylater-gap-d,16px)}');
    expect(ProductLayout::SCHEMA['paylater_gap_d'][2])->toBe(16);

    $html = rgPage(rgProduct());
    expect(substr_count($html, 'pm-sec pm-paylater'))->toBe(1);
    expect(app(ProductDesktopSections::class)->laptop()['paylater'])->toBeTrue();
});

/* ═══════════════ 4. THE BUY COLUMN'S LAPTOP ORDER ════════════════════════ */

it('prints the buy column order only once moved, laptop-only, with the first drawn block named', function () {
    /*
     * THE DEFECT: a reorder that also reorders the PHONE, or a first block
     * that keeps a gap above it at the top of the column.
     *
     * MUTATION NOTE, RUN: make firstBuy() ignore `$buyDrawn` → RED on the
     * `pdsb-f-price` assertion (the blurb is not drawn there, so price is
     * first). Move the `.pdsb-on` block outside its media query → RED on the
     * outside-media assertion.
     */
    test()->actingAs(rgAdmin(), 'admin');
    test()->postJson('/admin-api/product-page', ['dsections' => ['buy' => ['paylater', 'title', 'price', 'short', 'ready', 'delivery', 'cart', 'bundles']]])->assertOk();
    rgFlush();

    $svc = app(ProductDesktopSections::class);
    expect($svc->buyOrder())->toBe(['paylater', 'title', 'price', 'short', 'ready', 'delivery', 'cart', 'bundles', 'auth', 'trust', 'paychips']);
    expect($svc->wrapperStyle())->toBe(';--pdsb-o-paylater:1;--pdsb-o-title:2;--pdsb-o-price:3;--pdsb-o-short:4;--pdsb-o-ready:5;--pdsb-o-delivery:6;--pdsb-o-cart:7;--pdsb-o-bundles:8;--pdsb-o-auth:9;--pdsb-o-trust:10;--pdsb-o-paychips:11');
    expect($svc->wrapperClass())->toBe(' pdsb-on pdsb-f-paylater');
    expect($svc->wrapperClass([], ['paylater' => false]))->toBe(' pdsb-on pdsb-f-title');

    $svc->saveLaptop(['title' => false]);
    expect($svc->wrapperClass([], ['paylater' => false, 'short' => false]))->toBe(' pd-off-title pdsb-on pdsb-f-price');

    $wrapper = rgWrapper(rgPage(rgProduct()));
    expect($wrapper)->toContain(' pd-off-title pdsb-on pdsb-f-paylater');
    expect($wrapper)->toContain(';--pdsb-o-paylater:1;');

    expect(rgOutsideMedia())->not->toContain('pdsb-');
    expect(rgMedia('(max-width:880px)'))->not->toContain('pdsb-');
    foreach (ProductDesktopSections::defaultBuyOrder() as $key) {
        expect(rgMedia('(min-width:881px)'))->toContain('var(--pdsb-o-'.$key.',');
    }
});

it('spaces a reordered buy column with the very sliders that space it today', function () {
    /*
     * THE DEFECT: flex items do not collapse margins, so a reordered column
     * drawn with its normal-flow margins doubles some gaps and loses others.
     * Measured in Chromium (tools/rg-reorder-check.cjs): with `pdsb-on` forced
     * in the DEFAULT order every visible element in the column sits on the same
     * pixel as in normal flow, on four products at 1280 and 1440.
     *
     * MUTATION NOTE, RUN: change the bundles gap to `var(--pl-opt-gap-m,20px)`
     * (the PHONE slider) → RED.
     */
    $laptop = rgMedia('(min-width:881px)');

    foreach ([
        'price' => 'var(--pl-rate-gap-d,10px)',
        'short' => 'var(--pl-desc-gap-d,20px)',
        'paylater' => 'var(--pl-paylater-gap-d,16px)',
        'bundles' => 'var(--pl-opt-gap-d,20px)',
        'ready' => 'var(--pl-rule-gap,20px)',
        'delivery' => 'var(--pts-above-d,16px)',
        'cart' => 'var(--pts-below-d,16px)',
        'trust' => 'var(--pl-trust-gap,22px)',
        'paychips' => 'var(--pl-chips-gap,12px)',
    ] as $key => $gap) {
        expect($laptop)->toContain('order:var(--pdsb-o-'.$key.',');
        expect((bool) preg_match('/order:var\(--pdsb-o-'.$key.',\d+\);margin-block-start:'.preg_quote($gap, '/').'\}/', $laptop))->toBeTrue("{$key} is not spaced by {$gap}");
    }
});

/* ═══════════════ 5. THE DETAILS BLOCK ════════════════════════════════════ */

it('drops a tab body’s leading blank lines and nothing else', function () {
    /*
     * THE DEFECT, AS HE SAW IT: Description opened about 75px under the tab
     * row and Major Ingredients about 10px under it, with one setting behind
     * both. Reproduced in Chromium on a description opening with two
     * `<p>&nbsp;</p>`: 86px vs 20px at 390 and at 1280 before, 20px and 20px
     * after.
     *
     * MUTATION NOTE, RUN: make trimLeadingBlank() trim the trailing edge too
     * (trimBlank($html, true)) → RED on the trailing assertion.
     */
    expect(RichText::trimLeadingBlank("<p>&nbsp;</p>\n<p>&nbsp;</p>\n<p><strong>Benefits:</strong></p>\n<p>Copy.</p>"))
        ->toBe("<p><strong>Benefits:</strong></p>\n<p>Copy.</p>");
    expect(RichText::trimLeadingBlank('<br><br /><p> </p><div></div><p><br>&nbsp;Benefits</p>'))->toBe('<p>Benefits</p>');
    // The middle is the author's.
    expect(RichText::trimLeadingBlank("<p>One</p>\n<p>&nbsp;</p>\n<p>Two</p>"))->toBe("<p>One</p>\n<p>&nbsp;</p>\n<p>Two</p>");
    // Only the top edge. (A body that HAD something trimmed is re-serialised,
    // as forShortDescription()'s is: a kept no-break space comes back as the
    // character itself rather than the entity — the same space on the page.)
    expect(RichText::trimLeadingBlank("<p>&nbsp;</p><p>One</p><p>&nbsp;</p>"))->toBe("<p>One</p><p>\u{00A0}</p>");
    // Nothing to trim: the same string, byte for byte (no re-serialising).
    $same = "<p>Plain <b>copy</b> with a <br /> break.</p>\n";
    expect(RichText::trimLeadingBlank($same))->toBe($same);
    // A picture is something to look at.
    expect(RichText::trimLeadingBlank('<p><img src="/a.jpg" alt=""></p><p>x</p>'))->toBe('<p><img src="/a.jpg" alt=""></p><p>x</p>');
});

it('renders every tab body without its leading blank lines, in both prints, and stores nothing', function () {
    /*
     * MUTATION NOTE, RUN: remove the trimLeadingBlank() loop from
     * Store\ProductController::tabs() → RED on both counts.
     */
    $raw = "<p>&nbsp;</p>\n<p>&nbsp;</p>\n<p><strong>Benefits:</strong></p>\n<p>Lightweight.</p>";
    $p = rgProduct(['description' => $raw]);
    $html = rgPage($p);

    expect(substr_count($html, '<div class="dcontent clamp"><p><strong>Benefits:</strong></p>'))->toBe(1);
    expect(substr_count($html, '<div class="dcontent open"><p><strong>Benefits:</strong></p>'))->toBe(1);
    expect($html)->not->toContain('<div class="dcontent clamp"><p>&nbsp;</p>');
    expect(Product::find($p->id)->description)->toBe($raw);
});

it('gives the tab row a phone gap and a laptop gap, both shipped at 18px, and lets the first line sit on it', function () {
    /*
     * THE DEFECT: one value for both widths; and a body wrapped in a div
     * (`<div><h3>Benefits`) kept the heading's 1em top margin under it.
     *
     * MUTATION NOTE, RUN: delete the laptop rule → RED on the first assertion.
     */
    expect(rgMedia('(min-width:881px)'))->toContain('.details .dtabbar{margin-block-end:var(--pl-tab-body-gap-d,18px)}');
    expect(rgCss())->toContain('.details .dtabbar{gap:var(--pl-tab-gap,22px);border-block-end:1px solid var(--line-2);margin-block-end:var(--pl-tab-body-gap,18px);');
    expect(rgOutsideMedia())->toContain('.details .dcontent > :first-child > :first-child,');

    expect(ProductLayout::SCHEMA['tab_body_gap'][1])->toBe('Space under the detail tab row · phone');
    expect(ProductLayout::SCHEMA['tab_body_gap'][2])->toBe(18);
    expect(ProductLayout::SCHEMA['tab_body_gap_d'][1])->toBe('Space under the detail tab row · laptop');
    expect(ProductLayout::SCHEMA['tab_body_gap_d'][2])->toBe(18);
    expect(ProductLayout::TABS['sp_page'][2])->toContain('tab_body_gap_d');
    expect(ProductLayout::vars(ProductLayout::defaults() + ['tab_body_gap_d' => 18])['--pl-tab-body-gap-d'])->toBe('18px');
});

it('lifts a saved tab-row gap under 18px to 18 on both devices, keeps a larger one, and writes nothing on a fresh shop', function () {
    /*
     * THE OWNER ASKED FOR ROOM ON BOTH: "need spacing beteen the tab heading
     * and content in mobile, and desktop both." A saved value under the
     * shipped 18 is what drew his ~0px phone gap.
     *
     * MUTATION NOTE, RUN: drop the max() in the migration → RED on '18'.
     */
    $migration = require database_path('migrations/2027_07_21_000100_product_tab_body_gap_per_device.php');
    $settings = app(SettingsService::class);

    $migration->up();
    expect(DB::table('settings')->where('key', 'like', 'pdplay_tab_body_gap%')->count())->toBe(0);

    $settings->set('pdplay_tab_body_gap', 4);
    $migration->up();
    expect((string) DB::table('settings')->where('key', 'pdplay_tab_body_gap')->value('value'))->toBe('18');
    expect((string) DB::table('settings')->where('key', 'pdplay_tab_body_gap_d')->value('value'))->toBe('18');

    DB::table('settings')->where('key', 'like', 'pdplay_tab_body_gap%')->delete();
    $settings->set('pdplay_tab_body_gap', 26);
    $migration->up();
    $migration->up();
    expect((string) DB::table('settings')->where('key', 'pdplay_tab_body_gap')->value('value'))->toBe('26');
    expect((string) DB::table('settings')->where('key', 'pdplay_tab_body_gap_d')->value('value'))->toBe('26');
})->skip(fn () => ! is_file(database_path('migrations/2027_07_21_000100_product_tab_body_gap_per_device.php')), 'migration not present');

it('hides “The details” and the “Product details” heading on both devices by default, each with its own switch', function () {
    /*
     * THE OWNER ASKED: "keep hide by default." A DEFAULT THAT MOVES on the
     * laptop (the eyebrow and the heading) and on the phone (the heading; the
     * eyebrow already was).
     *
     * MUTATION NOTE, RUN: drop `pd-dh2` from wrapperClass()'s map → RED on
     * the switched-on wrapper.
     */
    expect(ProductMobileSections::SCHEMA['details_head_d'][2])->toBeFalse();
    expect(ProductMobileSections::SCHEMA['details_h2'][2])->toBeFalse();
    expect(ProductMobileSections::SCHEMA['details_h2_d'][2])->toBeFalse();
    expect(ProductMobileSections::TABS['msections'][2])->toContain('details_head_d', 'details_h2', 'details_h2_d');

    expect(rgMedia('(max-width:880px)'))->toContain('.pdp-page:not(.pm-dh2) > .pm-details > h2{display:none}');
    expect(rgMedia('(min-width:881px)'))->toContain('.pdp-page:not(.pd-dhead) > .pm-details > .eyebrow,.pdp-page:not(.pd-dh2) > .pm-details > h2{display:none}');

    $html = rgPage(rgProduct());
    $wrapper = rgWrapper($html);
    expect($wrapper)->not->toContain('pd-dhead')->not->toContain('pm-dh2')->not->toContain('pd-dh2');
    // Still in the HTML: hidden by the stylesheet, so a cached page serves both widths.
    expect($html)->toContain('<h2>'.__('store.product.details_heading').'</h2>');

    app(ProductMobileSections::class)->saveOptions(['details_head_d' => true, 'details_h2' => true, 'details_h2_d' => true]);
    rgFlush();
    expect(rgWrapper(rgPage(rgProduct())))->toContain(' pd-dhead pm-dh2 pd-dh2');
});

/* ═══════════════ 6. THE ADMIN SCREEN ═════════════════════════════════════ */

it('draws a laptop switch on every Desktop sections row and posts the buy order and the switches', function () {
    /*
     * MUTATION NOTE, RUN: drop `laptop: laptop` from the screen's POST body →
     * RED. (Its Chromium proof is docs/rg-shots/after-admin-*.png.)
     */
    $screen = (string) file_get_contents(resource_path('views/admin/partials/product-desktop-sections-screen.blade.php'));
    expect($screen)->toContain('data-pds-sw="');
    expect($screen)->toContain("JSON.stringify({ dsections: { order: LISTS.under.slice(), buy: LISTS.buy.slice(), laptop: laptop } })");
    expect($screen)->toContain("ppMarkDirty('sections')");
    expect($screen)->not->toContain('getBoundingClientRect');
    expect(substr_count((string) file_get_contents(resource_path('views/admin/partials/product-mobile-sections-screen.blade.php')), "@include('admin.partials.product-desktop-sections-screen')"))->toBe(1);
});
