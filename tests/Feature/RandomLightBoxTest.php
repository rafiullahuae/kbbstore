<?php

declare(strict_types=1);

/**
 * A category with no banner gets a different light box on every visit.
 *
 * THE OWNER'S REQUEST (2 October 2026): "can we have options to use random
 * layout on random categories, where we didin't upload the background image
 * yet. so on each page load, it will give random colored background as we have
 * multiple designs."
 *
 * Appearance → Site layout → Category header → "A different light box on every
 * visit" + "In the mix · A…E". One pick per page view stands in for the SHOP's
 * box style on both devices, so a category's own style still wins and a
 * category with a banner is untouched. SiteLayout's default is OFF (every test
 * and a fresh install draw the same box); migration 2027_07_16_000100 switches
 * it ON for his shop, unless he already stored a value.
 *
 * MUTATIONS, RUN:
 *   - ignore the mix (always the whole RANDOM_POOL): case 2 red;
 *   - put `$random` BEFORE the category's own box in TitleHeader::device(): case 4 red;
 *   - drop the `$kind === 'box'` guard: case 5 red;
 *   - make the migration overwrite a stored value: case 6 red;
 *   - drop the `runningUnitTests()` guard: case 1 red (the test database
 *     itself gets random on).
 */

use App\Models\Category;
use App\Services\SettingsService;

function rlbSet(array $v): void
{
    $s = app(SettingsService::class);
    foreach ($v as $k => $val) {
        $s->set('layout_'.$k, $val);
    }
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();
}

function rlbBox(string $slug): ?string
{
    $html = test()->get('/collections/'.$slug.'/')->assertOk()->getContent();
    preg_match('/kbb-th--box-([a-z]+)/', $html, $m);

    return $m[1] ?? null;
}

it('draws the chosen box every time while random is off', function () {
    Category::create(['name' => 'Rlb Toners', 'slug' => 'rlb-toners']);

    expect(collect(range(1, 6))->map(fn () => rlbBox('rlb-toners'))->unique()->values()->all())->toBe(['blush']);
});

it('picks only from the styles in the mix', function () {
    Category::create(['name' => 'Rlb Serums', 'slug' => 'rlb-serums']);
    rlbSet(['cat_header_box_random' => '1', 'cat_header_rand_blush' => '0', 'cat_header_rand_cream' => '1',
        'cat_header_rand_mint' => '0', 'cat_header_rand_lilac' => '1', 'cat_header_rand_plain' => '0']);

    $seen = collect(range(1, 40))->map(fn () => rlbBox('rlb-serums'))->unique()->sort()->values()->all();

    // Two styles, forty draws: both appear (the odds of missing one are 2 in 2^40).
    expect($seen)->toBe(['cream', 'lilac']);
});

it('falls back to the chosen box when the mix is empty', function () {
    Category::create(['name' => 'Rlb Masks', 'slug' => 'rlb-masks']);
    rlbSet(['cat_header_box_random' => '1', 'cat_header_box_style' => 'mint', 'cat_header_rand_blush' => '0',
        'cat_header_rand_cream' => '0', 'cat_header_rand_mint' => '0', 'cat_header_rand_lilac' => '0', 'cat_header_rand_plain' => '0']);

    expect(rlbBox('rlb-masks'))->toBe('mint');
});

it('lets a category keep its own box style', function () {
    Category::create(['name' => 'Rlb Lips', 'slug' => 'rlb-lips', 'header_style' => ['box' => 'lilac']]);
    rlbSet(['cat_header_box_random' => '1']);

    expect(collect(range(1, 12))->map(fn () => rlbBox('rlb-lips'))->unique()->values()->all())->toBe(['lilac']);
});

it('never touches a category that has a banner', function () {
    Category::create(['name' => 'Rlb Suns', 'slug' => 'rlb-suns', 'header_image' => '/uploads/rlb/banner.jpg']);
    rlbSet(['cat_header_box_random' => '1']);

    $html = $this->get('/collections/rlb-suns/')->assertOk()->getContent();

    expect($html)->toContain('kbb-th--img')->and($html)->not->toMatch('/kbb-th--box-[a-z]+/');
});

it('is switched on by its migration, without overriding a stored choice', function () {
    $migration = require base_path('database/migrations/2027_07_16_000100_random_light_box_on.php');

    // The test suite's own database ran it: still off.
    expect(\App\Models\Setting::query()->where('key', 'layout_cat_header_box_random')->exists())->toBeFalse();

    // As on his shop: not under the test runner, with categories.
    $asShop = function () use ($migration): void {
        $env = app()['env'];
        app()['env'] = 'production';
        try {
            $migration->up();
        } finally {
            app()['env'] = $env;
        }
    };

    $asShop();
    expect(\App\Models\Setting::query()->where('key', 'layout_cat_header_box_random')->value('value'))->toBe('1');

    // He turned it off; applying the package again must not turn it back on.
    app(SettingsService::class)->set('layout_cat_header_box_random', '0');
    $asShop();
    expect(\App\Models\Setting::query()->where('key', 'layout_cat_header_box_random')->value('value'))->toBe('0');
});
