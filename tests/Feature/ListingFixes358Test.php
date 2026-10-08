<?php

declare(strict_types=1);

/**
 * Three fixes the owner reported on 2 October, one file.   (Integrator, 2.60.358)
 *
 *   1. "the selection of low to high etc, is absolutely not working."
 *      The sort select's inline handler said `new URL(location.href)`. Inside
 *      an inline handler the scope chain runs through the document before the
 *      window, so `URL` was `document.URL` -- a string -- and every choice
 *      threw "URL is not a constructor" (measured in Chromium at 390 and 1280:
 *      the page URL never changed). The server's sorting was always correct.
 *
 *   2. "turn hide by default the products count beside the filter button."
 *
 *   3. "the background banner image in mobile should display full, not any cut
 *      from left or right." Measured at 390 on a 2400x600 banner: 46% of its
 *      width on screen before (object-fit:cover in a 1.8:1 box), 100% after.
 *
 * MUTATIONS, RUN:
 *   - put `new URL(` back in shop.blade.php -- the first case is red;
 *   - flip `show_count`'s default to true -- the second case is red;
 *   - delete `$class .= ' kbb-th--pw';` from TitleHeader -- the third is red;
 *   - delete the `.kbb-th--pw > .kbb-th__img{...object-fit:contain}` rule --
 *     the fourth is red.
 */

use App\Models\Category;
use App\Services\SettingsService;

function lf358Category(array $overrides = []): Category
{
    $category = Category::query()->create(array_merge([
        'name' => 'Sunscreens', 'slug' => 'lf-sun', 'parent_id' => null,
    ], $overrides));
    $category->forceFill(['path' => $category->slug, 'depth' => 0])->save();

    return $category;
}

function lf358Set(string $key, string $value): void
{
    app(SettingsService::class)->set($key, $value);
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();
}

it('makes the sort select call the URL constructor, not document.URL', function () {
    $blade = (string) file_get_contents(resource_path('views/store/shop.blade.php'));

    expect($blade)->toContain('<select id="sort" onchange="var u=new window.URL(location.href);');

    // No inline handler anywhere in the storefront's views names a bare URL.
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'))) as $file) {
        if (! str_ends_with((string) $file, '.blade.php')) {
            continue;
        }
        $src = (string) file_get_contents((string) $file);
        expect(preg_match('#\son[a-z]+="[^"]*(?<![\w.])URL\(#', $src))->toBe(0, (string) $file);
    }
});

it('hides the product count beside Filters by default, and shows it when switched on', function () {
    lf358Category();

    expect((string) $this->get('/collections/lf-sun/')->getContent())->not->toContain('class="gcount"');

    lf358Set('layout_show_count', '1');
    expect((string) $this->get('/collections/lf-sun/')->getContent())->toContain('<span class="gcount">');
});

it('shows the whole picture on a phone when switched on, with the blurred copy behind it', function () {
    // 2.60.393: off by default now (the owner: "cover the whole area by middle
    // and center"); this is the switch that puts the whole picture back.
    // Lane CB2: the old category header, pinned where it still is -- "Old
    // category header"; every category draws the brand design by default now.
    app(\App\Services\SiteLayout::class)->save(['catb_hero' => 'header']);
    lf358Set('layout_cat_header_phone_whole', '1');
    lf358Category(['header_image' => '/uploads/lf/banner.jpg']);

    $html = (string) $this->get('/collections/lf-sun/')->getContent();

    expect($html)->toMatch('#<section class="kbb-th kbb-th--img[^"]* kbb-th--pw"#')
        ->and($html)->toContain('<img class="kbb-th__fill" src="/uploads/lf/banner.jpg" alt="" aria-hidden="true" decoding="async">')
        ->and(strpos($html, 'kbb-th__fill'))->toBeLessThan(strpos($html, 'class="kbb-th__img"'));

    // Switched off: the cropped frame, no copy.
    lf358Set('layout_cat_header_phone_whole', '0');
    $off = (string) $this->get('/collections/lf-sun/')->getContent();
    expect($off)->not->toContain('kbb-th--pw')->and($off)->not->toContain('kbb-th__fill');

    // A light box has no picture to show whole.
    lf358Set('layout_cat_header_phone_whole', '1');
    lf358Category(['slug' => 'lf-box', 'name' => 'Toners']);
    expect((string) $this->get('/collections/lf-box/')->getContent())->not->toContain('kbb-th--pw');
});

it('sizes a phone header by the picture, and wraps the words inside it', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-title-header.css'));

    expect($css)->toContain('.kbb-th__fill{display:none}')
        ->and($css)->toContain('.kbb-th--pw{display:grid;min-height:0;background:#f6f0f2}')
        ->and($css)->toContain('.kbb-th--pw > .kbb-th__img{position:relative;inset:auto;align-self:stretch;width:100%;height:100%;object-fit:contain}')
        ->and($css)->toContain('.kbb-th__text{max-width:min(100%, var(--kbb-th-mw), calc(100vw - 2 * var(--site-gutter, 22px) - 2 * var(--kbb-th-px-now)))}');

    // Both under the phone query only.
    $phone = substr($css, (int) strpos($css, '.kbb-th__fill{display:none}'));
    expect($phone)->toMatch('#^\.kbb-th__fill\{display:none\}\n@media not all and \(min-width:900px\)\{\n  \.kbb-th--pw\{#');

    // The shipped bundle carries it.
    $manifest = json_decode((string) file_get_contents(base_path('public/build/manifest.json')), true);
    $file = null;
    foreach ($manifest as $src => $entry) {
        if (str_contains($src, 'kbb-title-header.css')) {
            $file = $entry['file'];
        }
    }
    $bundle = $file ? (string) file_get_contents(base_path('public/build/'.$file)) : '';
    if ($bundle === '') {
        $bundle = (string) file_get_contents(base_path('public/build/'.$manifest['resources/css/kbb/kbb.css']['file']));
    }
    expect($bundle)->toContain('kbb-th--pw');
});
