<?php

declare(strict_types=1);

/**
 * A category header says the category's name, two lines of description, or a
 * generic line -- never the old theme's switch word.
 *
 * THE DEFECT, ON THE LIVE SHOP (2 October 2026). /collections/skincare/ and
 * every other category opened on a big pink box reading "hide". The owner:
 * "whats this nonsense? on all banners just coming this thing. i wanted the
 * category name, with 2 lines description, that's it, if no description set
 * from backend, then generic line should come. Find your favorite products in
 * our wide range <category name> category.. the content will come left bottom
 * with spacing controls".
 *
 * Exporter 1.11.0 picks a category's title override by term-meta key NAME; on
 * his shop that key held the theme's "hide the title" switch, so the import
 * wrote "hide" as every category's header_title, and the header printed it.
 *
 * MUTATIONS, RUN:
 *   - drop the isSwitchWord() guard in TitleHeader::forModel(): case 1 red;
 *   - put the title_override branch back in importColumns(): case 4 red;
 *   - drop the generic-line block: case 2 red;
 *   - default `cat_header_valign` to 'center': case 3 red;
 *   - `'clamp' => true` removed (always false): case 3 red.
 */

use App\Models\Category;
use App\Support\TitleHeader;

function chnPage(string $slug): string
{
    return test()->get('/collections/'.$slug.'/')->assertOk()->getContent();
}


/*
 * Lane CB2: every category page draws the brand-page design by default now,
 * because the owner asked ("no more old header style for categories"). This
 * file pins the OLD category header, which is still the page under
 * Appearance -> Site layout -> Category page header -> "Old category header",
 * so it is pinned THERE. CategoryBannerTest pins the new default.
 */
beforeEach(function () {
    app(\App\Services\SiteLayout::class)->save(['catb_hero' => 'header']);
    \App\Models\Setting::flushMap();
    \App\Services\SettingsService::forgetMemo();
});

it('heads a category with its own name when the import left "hide" as its title', function () {
    Category::create(['name' => 'Chn Skincare', 'slug' => 'chn-skincare', 'header_title' => 'hide', 'header_subtitle' => 'show']);

    $html = chnPage('chn-skincare');

    expect($html)->toContain('<h1 class="kbb-th__title" id="kbb-th-title">Chn Skincare</h1>')
        ->and($html)->not->toContain('>hide<')
        ->and($html)->not->toContain('kbb-th__sub');
});

it('says the generic line, with the category name, when there is no description', function () {
    Category::create(['name' => 'Chn <b>Toners</b>', 'slug' => 'chn-toners']);

    $html = chnPage('chn-toners');

    // The name is reduced to its text (tags stripped) and escaped: never markup.
    expect($html)->toContain('<div class="kbb-th__desc kbb-th__desc--clamp">Find your favorite products in our wide range Chn Toners category.</div>')
        ->and($html)->not->toContain('<b>Toners</b>');
});

it('sits the words at the bottom, cut at two lines with no Read more', function () {
    Category::create(['name' => 'Chn Serums', 'slug' => 'chn-serums', 'description' => str_repeat('A long serum description. ', 40)]);

    $html = chnPage('chn-serums');

    expect($html)->toContain('kbb-th--v-bottom')
        ->and($html)->toContain('class="kbb-th__desc kbb-th__desc--clamp"')
        ->and($html)->not->toContain('kbb-th__more')
        ->and($html)->toContain('--kbb-th-lines:2');
});

it('no longer imports the old shop title and subtitle', function () {
    $row = new \App\Services\Import\Row(2, ['title_override' => 'hide', 'subtitle' => 'Big sale', 'banner_image' => 'https://example.test/b.jpg']);

    $cols = TitleHeader::importColumns($row);

    expect($cols)->not->toHaveKey('header_title')
        ->and($cols)->not->toHaveKey('header_subtitle')
        ->and($cols)->toHaveKey('header_image');
});

it('clears the titles the import already wrote', function () {
    $c = Category::create(['name' => 'Chn Masks', 'slug' => 'chn-masks', 'header_title' => 'hide', 'header_subtitle' => 'x']);

    (require base_path('database/migrations/2027_07_15_002000_category_header_name_and_generic_line.php'))->up();

    $c->refresh();
    expect($c->header_title)->toBeNull()->and($c->header_subtitle)->toBeNull();
});

it('takes no padding from the shop-wide section rule', function () {
    /*
     * kbb.css: `section{padding:52px 0}`. The header is a <section>, so every
     * header was 104px taller than its Height setting and its words floated in
     * the middle whatever "Where the words sit" said -- measured in Chromium:
     * 52px above and below the text block at 390. The owner's live screenshot
     * of /collections/skincare/ shows exactly that box.
     *
     * MUTATION, RUN: delete `padding:0;` from the .kbb-th rule -- red.
     */
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-title-header.css'));
    preg_match('/\n\.kbb-th\{(.*?)\n\}/s', $css, $m);

    expect($m[1] ?? '')->toContain("\n  padding:0;")
        ->and((string) file_get_contents(resource_path('css/kbb/kbb.css')))->toContain('section{padding:52px 0}');
});

it('cuts the description at its line count without -webkit-box, so Safari wraps it', function () {
    /*
     * The owner, on 2.60.351 in Safari: "Find your favorite products in our
     * wide range Moisturizers cat" -- the generic line cut off mid-word on one
     * line. The words' column is sized to its content and WebKit measures a
     * -webkit-box line-clamp's intrinsic width short. The cut is now a plain
     * block with a max-height of N lines. Chromium in this suite never showed
     * the defect, so the rule is what is pinned.
     *
     * MUTATIONS, RUN: put `display:-webkit-box` back in .kbb-th__desc--clamp --
     * red; drop the max-height -- red.
     */
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-title-header.css'));
    preg_match('/\n\.kbb-th__desc--clamp\{(.*?)\n\}/s', $css, $m);

    expect($m[1] ?? '')->not->toContain('-webkit-box')
        ->and($m[1] ?? '')->toContain('max-height:calc(var(--kbb-th-lines) * 1.55em);')
        ->and($m[1] ?? '')->toContain('overflow:hidden;');
});
