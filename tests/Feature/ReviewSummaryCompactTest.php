<?php

declare(strict_types=1);

/*
 * THE REVIEW SCORE BOX AT HALF HEIGHT.                               (Lane PX)
 *
 * The owner, with a phone screenshot of the "Customer Reviews" summary card
 * bracketed in red: "The review header box i need less heighted, almost less
 * to half. and keep the same design and elements positions."
 *
 * THE DEFECT, AS IT LOOKED ON THE SHOP: the box was 156px tall at 390 — 20px of
 * padding round a 46px score and five bars on a 22px pitch — for five numbers.
 * Measured after, in Chromium: 78.6px at 390 (50%), 86.6px at 1280 (56%), with
 * the score, stars and count still on the left and the bars on the right
 * (docs/lane-px-shots/after-reviews-*.png).
 *
 * Store → Reviews → Review Settings → On the product page → Compact summary,
 * on because he asked; off draws the taller box.
 */

use App\Models\AdminUser;
use App\Models\Product;
use App\Models\Review;
use App\Models\Setting;
use App\Services\SettingsService;
use App\Support\ReviewSettings;
use Illuminate\Support\Facades\Hash;

function pxRvFresh(): void
{
    SettingsService::forgetMemo();
    Setting::flushMap();
    app()->forgetScopedInstances();
}

function pxRvPage(): string
{
    $p = Product::create([
        'slug' => 'px-rv-'.uniqid(), 'name' => 'PX Reviewed', 'status' => 'publish',
        'is_visible' => true, 'price' => 5000, 'stock_status' => 'instock',
    ]);
    Review::create([
        'product_id' => $p->id, 'source' => 'kbb', 'author_name' => 'Zara M.',
        'author_email' => 'z@example.test', 'rating' => 5, 'content' => 'Lovely.', 'status' => 'approved',
    ]);
    pxRvFresh();

    return (string) test()->get('/product/'.$p->slug)->assertOk()->getContent();
}

it('draws the same box, in the same order, with the compact class on', function () {
    expect(ReviewSettings::SCHEMA['sr_compact']['default'])->toBeTrue()
        ->and(ReviewSettings::TABS['page'][2])->toContain('sr_compact');

    $html = pxRvPage();

    expect($html)->toMatch('#<section class="sr sr-compact" id="sr" style=#');

    // Same elements, same positions: score (avg, stars, count) then the bars.
    $sum = substr($html, strpos($html, '<div class="sr-summary">'), 3000);
    $order = array_map(fn ($c) => strpos($sum, $c), ['class="sr-score"', 'class="sr-avg"', 'class="sr-avg-stars"', 'class="sr-count"', 'class="sr-bars"']);
    expect($order)->not->toContain(false)
        ->and($order)->toBe(array_values(array_unique($order)))
        ->and($order)->toEqual((function ($o) { sort($o); return $o; })($order));
    // MUTATION NOTE — RUN: drop the `sr-compact` echo from the <section> in
    // partials/reviews.blade.php and the toMatch above is red.
});

it('halves the box in the stylesheet the page inlines', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/sorina-reviews.css'));

    // Before: padding 20px, a 46px score, bars on a 22px pitch (4px margins
    // round a 12px label line). These are the numbers that halve it.
    expect($css)->toContain('.sr-compact .sr-summary{gap:16px;padding:8px 16px;border-radius:16px}')
        ->and($css)->toContain('.sr-compact .sr-avg{font-size:30px}')
        ->and($css)->toContain('.sr-compact .sr-bar-row{margin:0;gap:8px}')
        ->and($css)->toContain('.sr-compact .sr-bl,.sr-compact .sr-bc{font-size:11px;line-height:12px}')
        // The untouched rules are still the old box, for the switch's off.
        ->and($css)->toContain('.sr-summary{display:flex;gap:20px;align-items:center;background:var(--w);border:1px solid var(--ln);border-radius:20px;padding:20px}');
});

it('off draws the box that shipped before, and the screen saves it', function () {
    $owner = AdminUser::create([
        'name' => 'PX Rv', 'email' => 'px-rv@example.test',
        'password' => Hash::make('secret-secret'), 'role' => 'owner',
    ]);

    test()->actingAs($owner, 'admin')
        ->putJson('/admin-api/review-settings', ['sr_compact' => false])
        ->assertOk()->assertJsonPath('settings.sr_compact', false);

    $html = pxRvPage();

    expect($html)->toContain('<section class="sr" id="sr" style=')
        // (The inlined sheet still names .sr-compact; the section does not.)
        ->and($html)->not->toContain('class="sr sr-compact"');

    // The control is on the screen, once, and findable from the search.
    $screen = (string) file_get_contents(resource_path('views/admin/partials/review-settings-screen.blade.php'));
    expect(substr_count($screen, "row('sr_compact', 'Compact summary',"))->toBe(1)
        ->and(\App\Support\AdminSearchIndex::CURATED['rev-settings'][''] ?? [])->toContain('Compact summary');
});
