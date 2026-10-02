<?php

declare(strict_types=1);

/*
 * ════════════════════════════════════════════════════════════════════════════
 * LANE PT — the old shop's category banner, title and description
 * ════════════════════════════════════════════════════════════════════════════
 *
 * THE ASK, IN THE OWNER'S WORDS: "Category banners import from old site. We
 * have a banner image on each category on the old site. Need to bring that on
 * the category pages as title background, like on
 * https://kbeautybliss.com/sunscreens/ -- and we also need the same title,
 * same description for each category if any have on our old site."
 *
 * WHAT THE SHOP DID BEFORE THIS: every category page on extrabeauty.ae showed a
 * small pink "SHOP" eyebrow, the category name and one grey line, on white.
 * The banner was in no exported file (exporter 1.10.1 carried `description`
 * and the square thumbnail only), so nothing could have drawn it.
 *
 * Exporter 1.11.0 carries it (PtCategoryHeaderExportTest); this file is the
 * shop's half -- import, media pipeline, storefront, settings.
 *
 * MUTATIONS, RUN (each red, then restored):
 *   - `'header_image' => …` dropped from TitleHeader::importColumns(): red,
 *     `it lands the banner, title and subtitle the export carries`;
 *   - the `$banner !== null` early return removed from forModel(): red, the
 *     owner's own banner and the imported header both draw an <h1>
 *     (`it lets the owner's own banner win`);
 *   - RichText::forDisplay() replaced by the raw description: red, the
 *     <script> and the javascript: link reach the page (`it prints imported
 *     description markup only through the allowlist`);
 *   - safeImage() accepting any string: red, `javascript:` and `data:` reach
 *     an <img src>;
 *   - `[Category::class, 'categories', 'header_image', false]` removed from
 *     MediaRewrite::COLUMNS: red, the banner stays on the old host after the
 *     picture is on disk (`it brings the banner across with the pictures`);
 *   - the `cat_header` default flipped to false: red, `it ships switched on`;
 *   - TitleHeader::importDescription() cleaning plain text too: red, "Masks &
 *     Peels" is stored as "Masks &amp; Peels" -- which the plain header, which
 *     escapes, would print as "Masks &amp;amp; Peels" (`it cleans a
 *     description that carries markup, and stores plain text exactly`).
 *
 * All seventeen mutations named in the three Lane PT test files were run with
 * storage/pt-logs/mutate.py (not committed) and every one went red.
 */

use App\Models\Brand;
use App\Models\Category;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportRunner;
use App\Services\Import\MediaAudit;
use App\Services\Import\MediaRewrite;
use App\Services\SiteLayout;
use App\Support\TitleHeader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

function ptImportFixtureForHeader(): void
{
    $dir = base_path('tests/Fixtures/kbb-export');
    $manifest = json_decode((string) file_get_contents($dir.'/manifest.json'), true);

    (new ImportRunner)->run(new ImportOptions(
        directory: $dir,
        sourceTimezone: $manifest['source']['timezone'],
        adoptBySlug: true,
    ));
}

/** A category with an imported header, drawn at /collections/{slug}/. */
function ptHeaderCategory(array $overrides = []): Category
{
    $category = Category::query()->create(array_merge([
        'name' => 'Sunscreens',
        'slug' => 'pt-sunscreens',
        'parent_id' => null,
        'description' => '<p>Lightweight <strong>Korean sunscreens</strong> for every day.</p>',
        'header_image' => 'https://kbeautybliss.com/wp-content/uploads/2023/05/sunscreens-banner.jpg',
        'header_title' => 'Korean Sunscreens',
        'header_subtitle' => 'SPF that feels like nothing',
    ], $overrides));

    $category->forceFill(['path' => $category->slug, 'depth' => 0])->save();

    return $category;
}

function ptPage(string $path): string
{
    return (string) test()->get($path)->assertOk()->getContent();
}

/* ═══════════════════════════════════════════════════════════════ import ═══ */

it('lands the banner, title and subtitle the export carries', function () {
    ptImportFixtureForHeader();

    $skin = Category::query()->where('source_term_id', 15)->firstOrFail();
    $wash = Category::query()->where('source_term_id', 22)->firstOrFail();
    $toner = Category::query()->where('source_term_id', 31)->firstOrFail();
    $brand = Brand::query()->where('source_term_id', 502)->firstOrFail();

    /*
     * ADVANCED in 2.60.350: the title and subtitle no longer cross. On the
     * owner's shop the key the exporter read as a title held the theme's
     * "hide" switch, and every category was headed "hide"; he wants the
     * category's name (CategoryHeaderNameFirstTest).
     */
    expect($skin->only(['header_image', 'header_source', 'header_title', 'header_subtitle']))->toBe([
        'header_image' => 'https://kbeautybliss.com/wp-content/uploads/2020/01/skincare-banner.jpg',
        'header_source' => 'header_banner',
        'header_title' => null,
        'header_subtitle' => null,
    ]);

    expect($wash->header_image)->toBe('https://kbeautybliss.com/wp-content/uploads/2020/02/face-cleansers-cover.jpg')
        ->and($wash->header_source)->toBe('cover_image')
        ->and($toner->header_image)->toBeNull()
        ->and($brand->header_image)->toBe('https://kbeautybliss.com/wp-content/uploads/2020/01/boj-banner.jpg');

    // The description that carries markup is cleaned on the way in.
    expect($wash->description)->toStartWith('<p>Gentle cleansers for every skin.');
});

it('cleans a description that carries markup, and stores plain text exactly as it came', function () {
    expect(TitleHeader::importDescription('Masks & Peels'))->toBe('Masks & Peels')
        ->and(TitleHeader::importDescription('<p onclick="x()">Hi<script>alert(1)</script></p>'))->toBe('<p>Hi</p>')
        ->and(TitleHeader::importDescription(null))->toBeNull();
});

it('leaves the header columns alone when the export predates them', function () {
    $category = ptHeaderCategory(['source_term_id' => 777]);

    $dir = storage_path('pt-logs/old-format-'.bin2hex(random_bytes(3)));
    File::ensureDirectoryExists($dir);
    file_put_contents($dir.'/categories.csv', "\"term_id\",\"name\",\"slug\",\"parent\",\"description\",\"image\",\"position\"\n"
        ."\"777\",\"Sunscreens\",\"pt-sunscreens\",\"0\",\"Old words\",\"\",\"0\"\n");

    (new ImportRunner)->run(new ImportOptions(directory: $dir, sourceTimezone: 'Asia/Dubai', only: ['categories']));

    File::deleteDirectory($dir);

    expect($category->fresh()->header_image)->toBe('https://kbeautybliss.com/wp-content/uploads/2023/05/sunscreens-banner.jpg')
        ->and($category->fresh()->description)->toBe('Old words');
});

it('brings the banner across with the pictures: counted, then re-pointed once the file is here', function () {
    $category = ptHeaderCategory();

    $fields = collect((new MediaAudit)->audit())->pluck('field')->all();

    expect($fields)->toContain('categories.header_image');

    $relative = 'wp-content/uploads/2023/05/sunscreens-banner.jpg';
    File::ensureDirectoryExists(dirname(public_path($relative)));
    file_put_contents(public_path($relative), "\xFF\xD8\xFF\xE0".str_repeat('x', 40)."\xFF\xD9");

    try {
        $rewrite = new MediaRewrite;
        $proposals = array_values(array_filter(
            $rewrite->propose(['kbeautybliss.com']),
            fn ($p) => $p['table'] === 'categories' && $p['field'] === 'header_image',
        ));

        expect($proposals)->toHaveCount(1)
            ->and($proposals[0]['decision'])->toBe(MediaRewrite::REWRITE);

        $rewrite->apply($proposals);

        expect($category->fresh()->header_image)->not->toContain('kbeautybliss.com')
            ->and($category->fresh()->header_image)->toEndWith('/wp-content/uploads/2023/05/sunscreens-banner.jpg');
    } finally {
        File::deleteDirectory(public_path('wp-content/uploads/2023'));
    }
});

/* ═══════════════════════════════════════════════════════════ storefront ═══ */

it('ships switched on: the banner behind the old title, the subtitle and the description', function () {
    expect(SiteLayout::SCHEMA['cat_header'][2])->toBeTrue();

    ptHeaderCategory();

    $html = ptPage('/collections/pt-sunscreens/');

    expect(substr_count($html, 'data-kbb-title-header'))->toBe(1)
        ->and($html)->toContain('<h1 class="kbb-th__title" id="kbb-th-title">Korean Sunscreens</h1>')
        ->and($html)->toContain('<p class="kbb-th__sub">SPF that feels like nothing</p>')
        ->and($html)->toContain('<div class="kbb-th__desc kbb-th__desc--clamp"><p>Lightweight <strong>Korean sunscreens</strong> for every day.</p></div>') // 2.60.350: the description is cut at its line count (kbb-th__desc--clamp), as he asked.
        ->and($html)->toContain('src="https://kbeautybliss.com/wp-content/uploads/2023/05/sunscreens-banner.jpg"')
        ->and($html)->toContain('kbb-title-header');

    // ONE <h1>: the plain heading block is not drawn under the header.
    expect(substr_count($html, '<h1'))->toBe(1)
        ->and(str_contains($html, 'class="ptitle"'))->toBeFalse();
});

it('clamps a long description behind Read more, with no script', function () {
    // "Read more" is a switch since 2.60.350 (off, as the owner asked).
    app(\App\Services\SettingsService::class)->set('layout_cat_header_more', '1');
    \App\Services\SettingsService::forgetMemo();

    ptHeaderCategory(['description' => '<p>'.str_repeat('Broad-spectrum protection that sits light. ', 12).'</p>']);

    $html = ptPage('/collections/pt-sunscreens/');

    expect($html)->toContain('<input type="checkbox" id="kbb-th-more" class="kbb-th__toggle">')
        ->and($html)->toContain('class="kbb-th__desc kbb-th__desc--clamp"')
        ->and($html)->toContain('<label for="kbb-th-more" class="kbb-th__more">');
});

it('prints imported description markup only through the allowlist', function () {
    ptHeaderCategory([
        'description' => '<p>Hi<script>alert(1)</script> <a href="javascript:alert(2)">x</a> <img src=x onerror="alert(3)"></p>',
        'header_title' => '<b>Bold</b> "title"',
    ]);

    $html = ptPage('/collections/pt-sunscreens/');
    $header = substr($html, (int) strpos($html, 'data-kbb-title-header'));
    $header = substr($header, 0, (int) strpos($header, '</section>'));

    expect(str_contains($header, '<script'))->toBeFalse()
        ->and(str_contains($header, 'javascript:'))->toBeFalse()
        ->and(str_contains($header, 'onerror'))->toBeFalse()
        ->and($header)->toContain('>Bold &quot;title&quot;</h1>');
});

it('never puts a script or data address into the picture', function () {
    foreach (['javascript:alert(1)', 'data:image/svg+xml;base64,PHN2Zz4=', '//evil.test/x.jpg', '/a/../../etc/passwd', 'https://x.test/a b.jpg', 'x.jpg'] as $bad) {
        expect(TitleHeader::safeImage($bad))->toBeNull($bad);
    }

    expect(TitleHeader::safeImage('https://kbeautybliss.com/a.jpg'))->toBe('https://kbeautybliss.com/a.jpg')
        ->and(TitleHeader::safeImage('/wp-content/uploads/a.jpg'))->toBe('/wp-content/uploads/a.jpg');
});

it('draws today\'s plain title for a category with nothing imported, once the light box is switched off', function () {
    /*
     * Lane PY: a category with no picture gets the light box now -- the owner
     * asked for it ("i just wanted the background image or light colored box
     * ... if no image"), so it ships on. With that switch OFF, the plain
     * title PT kept is still exactly what is drawn, which is what this case
     * pins. PyCategoryHeaderOptionsTest pins the box itself.
     */
    app(SiteLayout::class)->save(['cat_header_box' => false]);
    \App\Services\SettingsService::forgetMemo();
    \App\Services\ModuleSchema::forgetNormalised();

    ptHeaderCategory(['header_image' => null]);

    $html = ptPage('/collections/pt-sunscreens/');

    expect(str_contains($html, 'data-kbb-title-header'))->toBeFalse()
        ->and(str_contains($html, 'kbb-title-header'))->toBeFalse()
        ->and($html)->toContain('<h1 class="ptitle">Sunscreens</h1>');
});

it('lets the owner\'s own banner win', function () {
    ptHeaderCategory(['banner' => ['enabled' => true, 'style' => 'tint', 'heading' => 'Hand made']]);

    $html = ptPage('/collections/pt-sunscreens/');

    expect(str_contains($html, 'data-kbb-title-header'))->toBeFalse()
        ->and($html)->toContain('data-kbb-banner-style="tint"')
        ->and(substr_count($html, '<h1'))->toBe(1);
});

it('obeys Appearance -> Site layout -> Category header', function () {
    ptHeaderCategory(['image' => 'https://kbeautybliss.com/wp-content/uploads/2020/01/thumb.jpg', 'header_image' => null]);

    $layout = app(SiteLayout::class);

    // Lane PY's light box off, so "no header" is observable as no header.
    $layout->save(['cat_header_box' => false]);
    \App\Services\SettingsService::forgetMemo();
    \App\Services\ModuleSchema::forgetNormalised();

    // The fallback to the category picture is OFF as shipped...
    expect(str_contains(ptPage('/collections/pt-sunscreens/'), 'data-kbb-title-header'))->toBeFalse();

    // ...and on, it draws the category picture.
    $layout->save(['cat_header_fallback' => true, 'cat_header_text' => 'dark', 'cat_header_align' => 'end',
        'cat_header_h_phone' => 260, 'cat_header_h_desktop' => 420, 'cat_header_overlay' => 25, 'cat_header_lines' => 5]);
    \App\Services\SettingsService::forgetMemo();
    \App\Services\ModuleSchema::forgetNormalised();

    $html = ptPage('/collections/pt-sunscreens/');

    expect($html)->toContain('src="https://kbeautybliss.com/wp-content/uploads/2020/01/thumb.jpg"')
        // Lane PY: the classes name the shape, the tone, the LOGICAL
        // alignment and the treatment; the heights ride first in the style.
        // 2.60.358: a picture header also carries kbb-th--pw, the whole picture on a phone.
        ->and($html)->toContain('class="kbb-th kbb-th--img kbb-th--dark kbb-th--a-end kbb-th--v-bottom kbb-th--t-shadow kbb-th--pw"')
        ->and($html)->toContain('style="--kbb-th-h:260px;--kbb-th-hd:420px;')
        ->and($html)->toContain(';--kbb-th-ov:0.25;--kbb-th-lines:5;');

    // The main switch off: the plain title again.
    $layout->save(['cat_header' => false]);
    \App\Services\SettingsService::forgetMemo();
    \App\Services\ModuleSchema::forgetNormalised();

    expect(str_contains(ptPage('/collections/pt-sunscreens/'), 'data-kbb-title-header'))->toBeFalse();

    // A value outside its own list is refused, not stored.
    expect($layout->save(['cat_header_text' => 'red;background:url(x)'])['rejected'])->toHaveKey('cat_header_text');
});

it('draws a brand\'s header on its page when the brand carries one', function () {
    $brand = Brand::create([
        'name' => 'PT Joseon', 'slug' => 'pt-joseon', 'description' => 'Hanbang skincare',
        'header_image' => 'https://kbeautybliss.com/wp-content/uploads/2020/01/boj-banner.jpg',
    ]);

    $html = ptPage('/brands/pt-joseon/');

    expect(substr_count($html, 'data-kbb-title-header'))->toBe(1)
        ->and($html)->toContain('<h1 class="kbb-th__title" id="kbb-th-title">PT Joseon</h1>')
        ->and(str_contains($html, 'class="brw-h1"'))->toBeFalse();

    $brand->forceFill(['header_image' => null])->save();

    expect(str_contains(ptPage('/brands/pt-joseon/'), 'data-kbb-title-header'))->toBeFalse();
});

it('costs no query: a category page with a header runs exactly as many as one without', function () {
    $category = ptHeaderCategory();

    $count = function (): int {
        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });
        ptPage('/collections/pt-sunscreens/');

        return $n;
    };

    ptPage('/collections/pt-sunscreens/'); // warm the caches both runs share
    $with = $count();

    $category->forceFill(['header_image' => null])->save();
    ptPage('/collections/pt-sunscreens/');
    $without = $count();

    expect($with)->toBe($without);
});
