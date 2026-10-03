<?php

declare(strict_types=1);

/**
 * The bar after the rating: hidden on both devices by default, one switch each.
 *                                                        (Integrator, 2.60.364)
 *
 * THE OWNER, 3 October 2026, arrow on the pink hairline after "★★★★★ 5.0" in
 * the price row: "also give option to hide unhide the rating bar line, keep off
 * by default in desktop and mobile both."
 *
 * THE DEFECT THIS PINS: the hairline (.bb-ratebar, in the capsule row AND the
 * inline row) was drawn at every width with no way to remove it. Now
 * Appearance → Product page → Mobile sections → Price row → "Show the bar
 * after the rating · phone" / "· laptop", both OFF. The markup stays; a class
 * on .pdp-page, printed only when a switch is ON, lets the stylesheet show it.
 *
 * MUTATION NOTES, RUN:
 *   · delete the `(max-width:880px)` rule from kbb-product.css → RED (phone).
 *   · drop `'rate_bar_d' => 'pd-rbar'` from wrapperClass()'s map → RED on the
 *     switched-on wrapper.
 */

use App\Models\Product;
use App\Models\Review;
use App\Services\ProductMobileSections;
use App\Services\SettingsService;
use Illuminate\Support\Str;

function rbsPage(): string
{
    $p = Product::create([
        'slug' => 'rbs-'.Str::lower(Str::random(8)), 'name' => 'Fino Premium Touch Hair Mask',
        'type' => 'simple', 'status' => 'publish', 'is_visible' => true, 'price' => 4500,
        'stock_status' => 'instock', 'short_description' => '<p>Mask.</p>', 'description' => '<p>Mask.</p>',
    ]);
    Review::create(['product_id' => $p->id, 'author_name' => 'R', 'author_email' => 'r@example.test',
        'rating' => 5, 'content' => 'Lovely.', 'status' => 'approved']);

    return (string) test()->get('/product/'.$p->slug.'/')->assertOk()->getContent();
}

function rbsWrapper(string $html): string
{
    expect(preg_match('#<div class="wrap pdp-page[^"]*"#', $html, $m))->toBe(1);

    return $m[0];
}

function rbsRules(): string
{
    return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents(resource_path('css/kbb/kbb-product.css')));
}

it('hides the bar after the rating on phone and laptop by default, each with its own switch', function () {
    expect(ProductMobileSections::SCHEMA['rate_bar'][2])->toBeFalse();
    expect(ProductMobileSections::SCHEMA['rate_bar_d'][2])->toBeFalse();
    expect(ProductMobileSections::TABS['msections'][2])->toContain('rate_bar', 'rate_bar_d');

    $css = rbsRules();
    expect($css)->toContain('@media (max-width:880px){.pdp-page:not(.pm-rbar) .bb-ratebar{display:none}}');
    expect($css)->toContain('@media (min-width:881px){.pdp-page:not(.pd-rbar) .bb-ratebar{display:none}}');

    $html = rbsPage();
    expect($html)->toContain('class="bb-ratebar"');          // still drawn, hidden by the sheet
    expect(rbsWrapper($html))->not->toContain('pm-rbar')->not->toContain('pd-rbar');

    app(SettingsService::class)->set('pdpms_rate_bar_d', true);
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();
    $w = rbsWrapper(rbsPage());
    expect($w)->toContain(' pd-rbar')->not->toContain('pm-rbar');

    app(SettingsService::class)->set('pdpms_rate_bar', true);
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();
    expect(rbsWrapper(rbsPage()))->toContain(' pm-rbar pd-rbar');
});

it('draws both switches under the price row in the admin and previews them live', function () {
    $screen = (string) file_get_contents(resource_path('views/admin/partials/product-mobile-sections-screen.blade.php'));
    expect($screen)->toContain("price: ['rate_m', 'rate_count', 'rate_bar', 'rate_bar_d']");
    expect($screen)->toContain("out.push('pm-rbar')")->toContain("out.push('pd-rbar')");
    expect($screen)->toContain('pm-rbar$|pd-rbar$');
});
