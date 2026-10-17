<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\GridSection;
use App\Models\Product;
use App\Services\GridSections;
use App\Support\ArabicWording;
use App\Support\GridTabs;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArabicShop;

/**
 * An ordinary Grid section's heading on /ar.                          (Lane GAR)
 *
 * The owner, with a screenshot of the Arabic homepage: a Grid section headed
 * "Recommended for you" in ENGLISH, its sub-heading and "عرض الكل" in Arabic —
 * "this heading on the homepage is not translated, please do".
 *
 * What the defect looked like on the shop: grid-section.blade.php printed
 * $section->heading as typed on every page. The Arabic twins of a section's
 * words (heading_ar, sub_ar, view_all_ar, eyebrow_ar) existed only in TABS
 * mode, and the editor showed no Arabic box with tabs off, so an ordinary grid
 * had no way to be Arabic at all — not even for a phrase the shop already
 * translates (wording.cpage_00).
 *
 * The rule now, the same in both modes (GridTabs::say): English page, as
 * typed; /ar, the owner's Arabic twin, else the shop's own Arabic for a
 * shipped English phrase, else his English.
 */
function garAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'GAR', 'email' => 'gar-'.uniqid().'@example.test',
        'password' => 'secret-secret', 'role' => 'owner',
    ]);
}

function garSection(array $extra = []): GridSection
{
    $brand = Brand::firstOrCreate(['slug' => 'gar-brand'], ['name' => 'GAR Brand']);

    if (Product::query()->where('slug', 'like', 'gar-p%')->count() === 0) {
        foreach (range(1, 6) as $i) {
            Product::create([
                'slug' => 'gar-p'.$i, 'name' => 'GAR Product '.$i, 'status' => 'publish', 'is_visible' => true,
                'brand_id' => $brand->id, 'price' => 4000 + $i, 'stock_status' => 'instock', 'type' => 'simple',
                'rating' => 0.0, 'review_count' => 0, 'total_sales' => 900000 - $i,
            ]);
        }
    }

    $n = GridSection::query()->count() + 1;

    return GridSection::create(array_merge([
        'name' => 'GAR '.$n, 'slug' => 'gar-'.$n.'-'.uniqid(), 'status' => 'publish', 'position' => $n,
        'show_heading' => true, 'heading' => 'Recommended for you', 'subheading' => 'Picked for your skin',
        'source' => 'bestsellers', 'include_children' => false, 'count' => 4, 'mobile_count' => 4,
        'desktop_layout' => 'grid', 'desktop_cols' => 4, 'mobile_layout' => 'grid', 'mobile_cols' => 2,
        'skin' => '', 'card_label' => '', 'show_rank' => false,
        'show_view_all' => true, 'view_all_label' => '', 'view_all_url' => '/shop',
    ], $extra));
}

function garPage(string $path = '/'): string
{
    GridSections::flush();

    return test()->get($path)->assertOk()->getContent();
}

/** The ordinary (non-tab) grid section, cut out of a page. */
function garCut(string $html): string
{
    $at = strpos($html, '<section class="sec kbb-gsec ');
    expect($at)->not->toBeFalse('no grid section on the page');

    return substr($html, $at, strpos($html, '</div></section>', $at) - $at);
}

/** The head and the foot of a section: what the words reach. */
function garWords(string $cut): string
{
    $head = preg_match('#<div class="gs-head">.*?</div></div>#s', $cut, $m) ? $m[0] : '';
    $foot = preg_match('#<div class="gs-foot">.*?</div>#s', $cut, $f) ? $f[0] : '';

    return $head.'|'.$foot;
}

beforeEach(function () {
    GridSections::flush();
});

it('turns the owner\'s live "Recommended for you" heading Arabic on /ar with no data change, and leaves / as it was', function () {
    /*
     * The live section's data cannot be seen, and nothing here changes it:
     * the shop's own Arabic for the shipped phrase (wording.cpage_00) answers.
     * MUTATION: print `{{ $gsHeading }}` again in grid-section.blade.php and the
     * /ar heading is English — red.
     */
    garSection();
    $arabic = (string) ArabicWording::ARABIC['wording.cpage_00'];
    expect($arabic)->toBe('مقترحة لكِ');

    $en = garCut(garPage());
    expect($en)->toContain('<h2>Recommended for you</h2>')
        ->and($en)->toContain('<p>Picked for your skin</p>')
        ->and($en)->not->toContain($arabic);

    ArabicShop::on();
    $ar = garCut(garPage('/ar/'));

    expect($ar)->toContain('<h2>'.$arabic.'</h2>')
        // A sub-heading the shop has no Arabic for stays the owner's English.
        ->and($ar)->toContain('<p>Picked for your skin</p>')
        ->and($ar)->toContain('>'.__('store.product_grid.view_all').'</a>');
});

it('prints the owner\'s Arabic twins on an ordinary grid on /ar, and the English byte-identical on /', function () {
    /*
     * MUTATION: in GridTabs::say() return $english unconditionally and the /ar
     * words are English — red. Return $arabic on every page and the English
     * page carries Arabic — red (and the byte comparison with the twin-less
     * section is red).
     */
    $section = garSection(['heading' => 'Our picks', 'subheading' => 'Fresh this week', 'view_all_label' => 'See everything']);
    $before = garWords(garCut(garPage()));

    test()->actingAs(garAdmin(), 'admin')->putJson('/admin-api/grid-sections/'.$section->id, [
        'product_tabs' => ['heading_ar' => 'اختياراتنا', 'sub_ar' => 'جديد هذا الأسبوع', 'view_all_ar' => 'شاهدي الكل', 'eyebrow_ar' => '', 'eyebrow' => '', 'list' => []],
    ])->assertOk();

    $after = garWords(garCut(garPage()));

    // English: byte-identical with and without the Arabic twins stored.
    expect($after)->toBe($before)
        // The bytes this section printed before Lane GAR (each `@endif` eats its newline).
        ->and($before)->toBe('<div class="gs-head"><div>'."\n"
            .'    <h2>Our picks</h2>    <p>Fresh this week</p>  </div></div>|<div class="gs-foot"><a class="gs-all" href="/shop">See everything</a></div>');

    ArabicShop::on();
    $ar = garCut(garPage('/ar/'));

    expect($ar)->toContain('<h2>اختياراتنا</h2>')
        ->and($ar)->toContain('<p>جديد هذا الأسبوع</p>')
        ->and($ar)->toContain('>شاهدي الكل</a>')
        ->and($ar)->not->toContain('Our picks');
});

it('keeps a section with only Arabic words an ordinary grid, and a values-only save keeps the words', function () {
    /*
     * MUTATION: in GridSectionApiController::applyTabs() go back to
     * GridTabs::clean() and the words are stored NULL — red. Let of() accept
     * an empty list and the section turns into a tab row — red.
     */
    $section = garSection(['tab_style' => 'b']);
    $admin = garAdmin();

    test()->actingAs($admin, 'admin')->putJson('/admin-api/grid-sections/'.$section->id, [
        'product_tabs' => ['heading_ar' => '<b>مختارة</b>', 'list' => []],
    ])->assertOk()->assertJsonPath('product_tabs', null)->assertJsonPath('section_words.heading_ar', 'مختارة');

    $fresh = $section->fresh();
    expect(GridTabs::of($fresh))->toBeNull()
        ->and($fresh->tabs['list'])->toBe([])
        ->and($fresh->tabs['heading_ar'])->toBe('مختارة')
        ->and($fresh->tab_style)->toBe('b');

    // A save that names only the grid's own values leaves the words alone.
    test()->actingAs($admin, 'admin')->putJson('/admin-api/grid-sections/'.$section->id, [
        'values' => ['heading' => 'Recommended for you'],
    ])->assertOk();
    expect($section->fresh()->tabs['heading_ar'])->toBe('مختارة');

    $page = garPage();
    expect($page)->not->toContain('gs-tabs')->and($page)->not->toContain('role="tablist"');

    // Clearing every word stores nothing at all, as before.
    test()->actingAs($admin, 'admin')->putJson('/admin-api/grid-sections/'.$section->id, [
        'product_tabs' => ['heading_ar' => '', 'list' => []],
    ])->assertOk();
    expect($section->fresh()->tabs)->toBeNull();
});

it('keeps an empty English heading hidden on /ar whatever its Arabic twin says', function () {
    /*
     * MUTATION: compute $gsHeadingSay without the `$gsHeading !== ''` guard
     * and print it under `@if ($gsHeadingSay !== '')` — an Arabic heading
     * appears on /ar over a grid the owner left headless — red.
     */
    garSection(['heading' => '', 'subheading' => 'Only a line', 'tabs' => ['heading_ar' => 'عنوان', 'align' => 'center', 'list' => []]]);

    ArabicShop::on();
    $ar = garCut(garPage('/ar/'));

    expect($ar)->not->toContain('<h2>')
        ->and($ar)->not->toContain('عنوان');
});

it('falls back to the shop\'s Arabic in tabs mode too, so both modes agree', function () {
    /*
     * MUTATION: put GridTabs::pick() back for $gtHeading in grid-tabs.blade.php
     * and the tabs section's heading is English on /ar — red.
     */
    garSection(['tabs' => [
        'eyebrow' => '', 'eyebrow_ar' => '', 'heading_ar' => '', 'sub_ar' => '', 'view_all_ar' => '', 'align' => 'center',
        'list' => [['title' => 'Best Sellers', 'source' => 'bestsellers'], ['title' => 'New', 'source' => 'newest']],
    ]]);

    ArabicShop::on();
    $html = garPage('/ar/');
    $at = strpos($html, '<section class="sec kbb-gsec gs-tabs');
    expect($at)->not->toBeFalse();
    $tabs = substr($html, $at, 3000);

    expect($tabs)->toContain('>مقترحة لكِ</h2>');
});

it('costs no query for the Arabic words: /ar with and without twins stored is the same count', function () {
    /*
     * The twins live in the `tabs` column of the row the page already loaded.
     * MUTATION: read them with GridSection::query()->find() in the partial
     * and the counts differ — red.
     */
    $section = garSection();
    ArabicShop::on();

    $count = function (): int {
        test()->get('/ar/');
        GridSections::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->get('/ar/')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $without = $count();
    $section->forceFill(['tabs' => ['heading_ar' => 'اختياراتنا', 'sub_ar' => 'سطر', 'view_all_ar' => 'الكل', 'eyebrow' => 'Edit', 'eyebrow_ar' => 'مختارات', 'align' => 'center', 'list' => []]])->save();
    $with = $count();

    expect($with)->toBe($without);
    $ar = garCut(garPage('/ar/'));
    expect($ar)->toContain('<div class="gs-head"><div><span class="hs-eyebrow">مختارات</span>')
        ->and($ar)->toContain('<h2>اختياراتنا</h2>');
});
