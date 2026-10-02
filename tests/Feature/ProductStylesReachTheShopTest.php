<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\ProductStyles;
use App\Services\SettingsService;

/**
 * =============================================================================
 * EVERY CONTROL ON APPEARANCE → PRODUCT STYLES MOVES THE SHOP           Lane AD
 * =============================================================================
 *
 * ── WHAT WAS FOUND, AND HOW ─────────────────────────────────────────────────
 *
 * Lane W1 recorded that ProductStyles::cssVariables() is called from
 * resources/views/admin/app.blade.php AND NOWHERE ELSE, and named three
 * controls that had therefore never moved a pixel: Columns · tablet, Gap
 * between cards, Card roundness.
 *
 * It was worse than three. The measurement below — move one key off its
 * default, re-render the home page, /shop, a category, a brand page and a
 * product page, and compare the bytes — says TWENTY of the screen's controls
 * moved nothing:
 *
 *   Layout        grid_columns, grid_columns_tablet, grid_columns_mobile,
 *                 grid_gap, card_radius, image_ratio
 *   Card content  show_brand, show_category, show_rating, show_was_price,
 *                 show_discount, show_new, show_cart, cart_label
 *   Colour        sale_colour, new_colour, price_colour, star_colour,
 *                 cart_bg, cart_fg
 *
 * The root cause is one line, not twenty: ::bodyClass() carried the seven
 * toggles and ::cssVariables() carried the rest, and layouts/store.blade.php
 * called NEITHER — only ::cardVariables(), which is why the name clamp was the
 * one thing on that screen that worked.
 *
 * ── WHY THIS IS A TEST AND NOT A NOTE IN A DOC ──────────────────────────────
 *
 * Because it is the only shape that keeps being true. A doc saying "these three
 * are dead" was written, was correct, and did not stop the other seventeen
 * being dead beside them. This walks the SCHEMA, so a control added next
 * release and wired to nothing is red the day it is added rather than found by
 * an owner who moves a slider and watches his shop not change.
 *
 * ── THE GATE, WHICH A NAIVE VERSION OF THIS GETS WRONG ──────────────────────
 *
 * Every sticky_* key but sticky_show is only rendered when sticky_show is on.
 * The first version of this measurement left the gate shut and reported the
 * whole Sticky tab dead, which is false and would have had eleven working
 * controls deleted. The gate is held open below, and that is why.
 *
 * MUTATION NOTES, ALL RUN:
 *
 *  M1  Put `{{ $kbbCards->cardVariables() }}` back in place of
 *      `{{ $kbbCards->cssVariables() }}` in layouts/store.blade.php. RED on
 *      "every control moves the shop", naming fifteen keys.
 *  M2  Drop `$kbbCards->bodyClass()` from the same line. RED on the same test,
 *      naming the seven toggles.
 *  M3  Change SCHEMA's card_radius default from 14 to 20. RED on "ships at the
 *      value the stylesheet was already falling back to".
 *  M4  Delete the whereIn(...)->delete() from the migration. RED on "a stored
 *      value nobody has ever seen does not move the shop".
 */
function psShopSeed(): void
{
    $brand = Brand::firstOrCreate(['slug' => 'ps-brand'], ['name' => 'PS Brand']);
    $cat = Category::firstOrCreate(['slug' => 'ps-care'], ['name' => 'PS Care']);

    for ($i = 1; $i <= 3; $i++) {
        $product = Product::create([
            'slug' => 'ps-p'.$i,
            'name' => 'PS Product '.$i,
            'status' => 'publish',
            'is_visible' => true,
            'brand_id' => $brand->id,
            'price' => 5000,
            // One markdown, so "Discount badge" has a pill to draw (Lane PR:
            // it is a markup switch now, not a <body> class).
            'sale_price' => $i === 2 ? 4000 : null,
            'stock_status' => 'instock',
            'type' => 'simple',
            'rating' => 4.0,
            'review_count' => 3,
        ]);

        $product->categories()->syncWithoutDetaching([$cat->id]);
    }
}

/** The five pages that between them carry all five product grids. */
function psPages(): array
{
    return ['/', '/shop/', '/category/ps-care/', '/brand/ps-brand/', '/product/ps-p1/'];
}

function psRenderAll(): string
{
    SettingsService::forgetMemo();
    app()->forgetScopedInstances();

    $out = '';

    foreach (psPages() as $uri) {
        $out .= (string) test()->get($uri)->getContent();
    }

    return $out;
}

/**
 * A value that is NOT this key's default, for every key in the schema.
 *
 * Written out rather than derived, because "something other than the default"
 * has to be a value the control would really accept — ModuleSchema clamps a
 * range and falls a select back to its default, so a derived value that is out
 * of bounds would be cast straight back to the default and the key would look
 * dead when it is not.
 */
function psOffDefault(): array
{
    return [
        'grid_skin' => 'luxe',
        'card_radius' => 26,
        'image_ratio' => 'landscape',
        /* ▲ true, NOT false, FOR THESE TWO.                        (Lane CARD)
           Their shipped default moved to false — the owner asked for the brand
           line and the category eyebrow hidden in as many words — so `false` is
           now the DEFAULT here and setting it would measure nothing. The
           non-default value is switching them back on. */
        'show_brand' => true,
        'show_category' => true,
        'show_rating' => false,
        'show_was_price' => false,
        /* ▲ AND true FOR THESE TWO, for the same reason.            (Lane PR)
           The owner asked for the NEW and -N% pills off by default on
           2 October 2026, so switching them back ON is the move. */
        'show_discount' => true,
        'show_new' => true,
        'show_cart' => false,
        'name_lines' => 3,
        'sale_colour' => '#123456',
        'new_colour' => '#234567',
        'price_colour' => '#345678',
        'star_colour' => '#456789',
        'cart_bg' => '#56789A',
        'cart_fg' => '#6789AB',
        'sticky_show' => true,
        'sticky_devices' => 'all',
        'sticky_trigger' => 'offset',
        'sticky_offset' => 640,
        'sticky_thumb' => false,
        'sticky_name' => false,
        'sticky_price' => false,
        'sticky_label' => 'GRAB IT',
        'sticky_bg' => '#ABCDEF',
        'sticky_btn_bg' => '#BCDEF0',
        'sticky_btn_fg' => '#CDEF01',
        'sticky_radius' => 3,
        // Lane PR: hover on a phone (a <body> class) and Spacing & type (a
        // <style> element) — each moved off its default reaches the shop.
        'hover_phone' => true,
        'card_pad_m' => 8, 'card_pad_d' => 24,
        'card_gap_img_m' => 6, 'card_gap_img_d' => 24,
        'card_gap_price_m' => 4, 'card_gap_price_d' => 20,
        'card_gap_cart_m' => 4, 'card_gap_cart_d' => 20,
        'card_fs_title_m' => '12px', 'card_fs_title_d' => '16px',
        'card_fs_price_m' => '15px', 'card_fs_price_d' => '18px',
        'card_fs_btn_m' => '12px', 'card_fs_btn_d' => '13px',
        'card_fs_brand_m' => '9px', 'card_fs_brand_d' => '12px',
        'card_fw_title' => '500', 'card_fw_price' => '500', 'card_fw_sale' => '600',
        'card_fw_btn' => '600', 'card_fw_brand' => '700',
    ];
}

/* ═════════════ 1 · the measurement this whole lane rests on ═════════════ */

it('moves the shop for every control the screen offers', function () {
    psShopSeed();

    $settings = app(SettingsService::class);
    $off = psOffDefault();

    /*
     * THE STICKY GATE HELD OPEN. Every sticky_* key but sticky_show renders
     * only when the bar is switched on, so a baseline with it shut reports the
     * whole tab dead — which is how the first draft of this nearly deleted
     * eleven working controls.
     */
    $settings->set('sticky_show', true);
    $baseline = psRenderAll();

    $dead = [];

    foreach (ProductStyles::SCHEMA as $key => $definition) {
        expect(array_key_exists($key, $off))->toBeTrue(
            "{$key} was added to the schema without a non-default value here, so nothing measures it"
        );

        if ($key === 'sticky_show') {
            continue;   // it IS the gate, and it is what opened the baseline
        }

        $settings->set($key, $off[$key]);
        $after = psRenderAll();
        $settings->set($key, $definition[2]);
        $settings->set('sticky_show', true);

        if ($after === $baseline) {
            $dead[] = $key;
        }
    }

    expect($dead)->toBe([], 'these controls on Appearance → Product styles change no byte of the '
        .'storefront, so the owner can move them and nothing happens: '.implode(', ', $dead));
});

/* ═══════════ 2 · and the five that are gone are really gone ═══════════ */

it('no longer offers a second answer to a question another screen owns', function () {
    /*
     * REMOVED, NOT WIRED, and each for its own reason — recorded in full in
     * ProductStyles::SCHEMA. In short: the column count comes from --kbb-track
     * and Appearance → Site layout and reaches all five grids where these
     * reached one or none; the gap is declared per grid ON PURPOSE, because
     * /shop sits beside a filter rail and the related rail is narrower still,
     * and --kbb-gap is besides a term in the track arithmetic so a "gap" slider
     * would silently change the column count too; and the button's words are
     * __('store.product_card.add_to_cart'), which Content → Translations edits.
     */
    foreach (['grid_columns', 'grid_columns_tablet', 'grid_columns_mobile', 'grid_gap', 'cart_label'] as $key) {
        expect(array_key_exists($key, ProductStyles::SCHEMA))->toBeFalse(
            "{$key} is back on Appearance → Product styles, where it answers a question another screen owns"
        );
    }

    /*
     * ▲ AND THE SECOND SCREEN THAT OFFERED IT IS GONE TOO.
     *
     * The migration beside this test recorded the reason `grid_columns` was
     * removed from Appearance → Product styles but NOT deleted from `settings`:
     * "grid_columns is still written by the Ecommerce panel's Catalogue layout
     * and by LayoutApiController, and deleting a row those still save would be a
     * change to screens this lane does not own."
     *
     * It is off the Ecommerce panel now, so one of those two is closed and the
     * argument for keeping the row is one writer weaker. The third — Appearance
     * → Product grid's Columns select, which posts to LayoutApiController and
     * then tells the owner "Saved — live on the storefront now" — needs a change
     * to the console itself and is named in the lane report.
     *
     * Asserted on the field list AND the section list, because a key removed
     * from one and left in the other is a section naming a field that cannot be
     * drawn.
     */
    $owner = \App\Models\AdminUser::create([
        'name' => 'Panel Owner',
        'email' => 'ps-panel-owner@example.com',
        'password' => \Illuminate\Support\Facades\Hash::make('secret-secret'),
        'role' => 'owner',
    ]);

    test()->actingAs($owner, 'admin');

    // Asked of the ENDPOINT rather than the class, because what the screen
    // draws is the payload and a private schema() is not reachable from here.
    $payload = test()->getJson('/admin-api/ecommerce');

    $payload->assertStatus(200);

    expect(str_contains(json_encode($payload->json()), 'grid_columns'))->toBeFalse(
        'grid_columns is back on the Ecommerce panel, where it reaches no storefront pixel');

    // And no tab still lists one, which is the half a schema-only check misses:
    // ModuleSchema::tabs() walks TABS, so a stale name there is a field that
    // cannot be drawn.
    foreach (ProductStyles::TABS as $tab => $spec) {
        foreach ($spec[2] as $key) {
            expect(array_key_exists($key, ProductStyles::SCHEMA))->toBeTrue(
                "the {$tab} tab lists {$key}, which the schema does not define"
            );
        }
    }

    // cssVariables() must not still be writing the properties those controls fed.
    $vars = app(ProductStyles::class)->cssVariables();

    foreach (['--kbb-cols', '--kbb-cols-t', '--kbb-cols-m', '--kbb-gap'] as $property) {
        expect(str_contains($vars, $property))->toBeFalse(
            "cssVariables() still writes {$property}, which would now fight the track arithmetic in kbb.css"
        );
    }
});

/* ═════════ 3 · wiring them up moved nothing that was already drawn ═════════ */

it('ships every wired control at the value the stylesheet was already falling back to', function () {
    /*
     * RULE 1, MEASURED AT ITS SOURCE. kbb-grid-skins.css has declared
     * `var(--kbb-radius,14px)` and the rest for releases while nothing wrote
     * them, so the shop has been rendering the FALLBACKS. If a schema default
     * differed from its fallback, wiring the writer up would move a live page
     * the moment the package landed — which is the one thing this change may
     * not do.
     *
     * The fallbacks are read out of the stylesheet rather than repeated here,
     * so a later lane changing one of them is caught too. Comments are stripped
     * first: this sheet documents its own selectors in prose and a scanner that
     * reads its comments finds declarations that do not exist.
     */
    $css = (string) file_get_contents(base_path('resources/css/kbb/kbb-grid-skins.css'));
    $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

    $expected = [
        '--kbb-radius' => '14px',
        '--kbb-ratio' => '1/1',
        '--kbb-sale' => '#E23B57',
        '--kbb-new' => '#1F9D55',
        '--kbb-price' => '#2A2228',
        '--kbb-star' => '#E8A33D',
        '--kbb-cart-bg' => '#E0567B',
    ];

    foreach ($expected as $property => $fallback) {
        expect(preg_match('/var\('.preg_quote($property, '/').'\s*,\s*'.preg_quote($fallback, '/').'\s*\)/i', $css))
            ->toBe(1, "the stylesheet no longer falls {$property} back to {$fallback} — a shop applying this package would move");
    }

    // And the schema ships the same values, so cssVariables() writes what the
    // sheet was already using.
    $values = app(ProductStyles::class)->all();

    expect($values['card_radius'])->toBe(14)
        ->and($values['image_ratio'])->toBe('square')          // -> 1/1
        ->and($values['sale_colour'])->toBe('#E23B57')
        ->and($values['new_colour'])->toBe('#1F9D55')
        ->and($values['price_colour'])->toBe('#2A2228')
        ->and($values['star_colour'])->toBe('#E8A33D')
        ->and($values['cart_bg'])->toBe('#E0567B');

    /*
     * FIVE OF THE SEVEN TOGGLES SHIP ON, AND TWO SHIP OFF.        (Lane CARD)
     *
     * This read `->toBe('')` — all seven on, so the <body> class attribute was
     * byte-identical after the package. Two of them moved: the owner asked for
     * the brand line and the category eyebrow hidden by default in as many
     * words ("i want to hide the brand name, category name by default"), and
     * CLAUDE.md's rule 1 now says what he asked for ships on rather than
     * waiting behind a switch. So bodyClass() carries those two classes, the
     * <body> attribute moves by exactly that much, and nothing else on this
     * screen changed — which is the claim the rest of this case still makes.
     */
    expect(app(ProductStyles::class)->bodyClass())->toBe('pc-nobrand pc-nocat');
});

/* ═════════ 4 · a value he set years ago does not surface on apply ═════════ */

it('does not let a stored value nobody has ever seen move the shop', function () {
    /*
     * THE QUESTION THE OWNER WOULD ASK: what happens to a shop that HAS a
     * non-default stored against one of these?
     *
     * These controls have never had an effect, so such a value is not a
     * preference — it is a record of a control that lied. Wiring the writer up
     * without clearing it would take a slider position he set once, watched do
     * nothing, and forgot, and apply it to a live shop the moment the package
     * landed. The migration clears exactly the fifteen keys this release wires,
     * and this is the proof that it is enough.
     *
     * The migration is RUN, not re-implemented: a test that repeats the delete
     * would pass against a migration that does not do it.
     */
    psShopSeed();

    $settings = app(SettingsService::class);

    $clean = psRenderAll();

    // The shop as it stood before this package, with five of the dead controls
    // carrying values the owner had moved and never seen take effect.
    $settings->set('card_radius', 26);
    $settings->set('image_ratio', 'tall');
    $settings->set('show_brand', false);
    $settings->set('sale_colour', '#00FF00');
    $settings->set('cart_bg', '#000000');

    expect(psRenderAll())->not->toBe($clean, 'the stored values changed nothing, so this proves nothing');

    $migration = require base_path('database/migrations/2027_04_21_000000_product_styles_reach_the_shop.php');
    $migration->up();

    SettingsService::forgetMemo();
    app()->forgetScopedInstances();

    expect(psRenderAll())->toBe($clean,
        'applying this package moved a shop that had a value stored against a control that never worked');
});
