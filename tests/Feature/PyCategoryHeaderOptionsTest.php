<?php

declare(strict_types=1);

/*
 * ════════════════════════════════════════════════════════════════════════════
 * LANE PY — the category title header on every category, with its options
 * ════════════════════════════════════════════════════════════════════════════
 *
 * THE OWNER, IN HIS WORDS, on Lane PT's preview of "Korean Sunscreens" centred
 * on a dark brown banner:
 *
 *   "The title will be get from the category name itself, if custom title or
 *    description not entered by me. also give optio to center, left and for
 *    arabic ofcourse by default make the title name left side as before, i
 *    just wanted the background image or light colored box containing skincare
 *    makeup etc products icons. if no image. but i can be able to update that
 *    background iamge, title font size etc and description etc for each
 *    category. and will have control of overall section height padding etc.
 *    and some type of shadow behind the name, so it will not merged with the
 *    background. please give me multiple options to chooose from."
 *
 * WHAT THE SHOP DID BEFORE THIS (2.60.346/347): a category with an imported
 * banner drew it with the title CENTRED; every other category drew the plain
 * "SHOP" eyebrow, the name and one grey line. There was no way to give a
 * category a picture, a title or a size from the admin.
 *
 * MUTATIONS, RUN (each red, then restored; storage/py-logs/mutate.py, not
 * committed):
 *   M1  forModel(): `$override !== '' ? $override : …` -> always $title:
 *       red, `the custom title wins in English`.
 *   M2  forModel(): the `header_description` branch dropped: red,
 *       `the custom description wins`.
 *   M3  SiteLayout `cat_header_align` default 'start' -> 'center': red,
 *       `ships at Start` (and the walk's insertion pattern).
 *   M4  kbb-title-header.css `text-align:start` -> `text-align:left`: red,
 *       `aligns logically`.
 *   M5  forModel(): the `cat_header_box` guard removed for brands (box on
 *       every brand): red, `leaves /shop/, the brand filter and a brand page
 *       alone`.
 *   M6  sanitizeStyle(): the `pick()` on the choices replaced by the raw
 *       value: red, `refuses a word that is not on its list`.
 *   M7  clampTo() returns $value unclamped: red, `clamps a number`.
 *   M8  CategoriesApiController::headerFields(): `array_key_exists` guard
 *       dropped (absent key -> cleared): red, `a save that does not send the
 *       header leaves it alone`.
 *   M9  forModel(): the per-category override ignored (`$own = []`): red,
 *       `a category's own choice beats the shop's`.
 *   M10 the colour fields' `hex => expand` override removed: red,
 *       `takes #rgb and #rrggbb colours and nothing else`.
 */

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Setting;
use App\Services\ModuleSchema;
use App\Services\SettingsService;
use App\Services\SiteLayout;
use App\Support\Locale;
use App\Support\TitleHeader;
use Illuminate\Support\Facades\DB;
use Tests\Support\ArabicShop;
use Tests\Support\CatalogAdminRoutes;

function pyCategory(array $overrides = []): Category
{
    $category = Category::query()->create(array_merge([
        'name' => 'Lip Care',
        'slug' => 'py-lip-care',
        'parent_id' => null,
        'description' => 'Balms and masks for soft lips.',
    ], $overrides));

    $category->forceFill(['path' => $category->slug, 'depth' => 0])->save();

    return $category;
}

function pyPage(string $path): string
{
    return (string) test()->get($path)->assertOk()->getContent();
}

/** The header's opening tag, or '' when the page has none. */
function pyOpen(string $html): string
{
    return preg_match('#<section class="kbb-th[^"]*"[^>]*data-kbb-title-header[^>]*>#', $html, $m) === 1 ? $m[0] : '';
}

function pySave(array $values): void
{
    $result = app(SiteLayout::class)->save($values);
    expect($result['rejected'])->toBe([]);
    SettingsService::forgetMemo();
    ModuleSchema::forgetNormalised();
}

function pyAdmin(): AdminUser
{
    return AdminUser::firstOrCreate(
        ['email' => 'py-admin@example.test'],
        ['name' => 'PY Admin', 'password' => 'password-long-enough', 'role' => 'owner']
    );
}

function pyRtl(): void
{
    ArabicShop::on();
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_RTL], ['value' => '1', 'autoload' => true]);
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
}

/* ═════════════════════════════════════════════════════════ the defaults ═══ */

it('ships at Start, the soft shadow on pictures, dark words on Blush icons, and no brand box', function () {
    /*
     * "by default make the title name left side as before" -- Start. The
     * picture's treatment, the box's look and its words were chosen from his
     * words by the integrator ("some type of shadow behind the name"; "light
     * colored box ... products icons"); the brand box is OFF because brands are
     * not in his request.
     */
    $d = fn (string $k) => SiteLayout::SCHEMA[$k][2];

    expect($d('cat_header'))->toBeTrue()
        ->and($d('cat_header_box'))->toBeTrue()
        ->and($d('cat_header_box_brands'))->toBeFalse()
        ->and($d('cat_header_align'))->toBe('start')
        ->and($d('cat_header_treatment'))->toBe('shadow')
        ->and($d('cat_header_box_treatment'))->toBe('none')
        ->and($d('cat_header_box_style'))->toBe('blush')
        ->and($d('cat_header_text'))->toBe('auto');
});

/* ═══════════════════════════════════════════════════════════ the words ═══ */

it('takes the title from the category name when no custom title is set', function () {
    pyCategory();

    $html = pyPage('/collections/py-lip-care/');

    expect($html)->toContain('<h1 class="kbb-th__title" id="kbb-th-title">Lip Care</h1>')
        ->and($html)->toContain('<div class="kbb-th__desc kbb-th__desc--clamp">Balms and masks for soft lips.</div>') // 2.60.350: the description is cut at its line count (kbb-th__desc--clamp), as he asked.
        ->and(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->not->toContain('class="ptitle"');
});

it('the custom title wins in English, and the Arabic page keeps the Arabic name', function () {
    $category = pyCategory(['header_title' => 'Soft Lips, Every Day']);
    $category->saveTranslations(['ar' => ['name' => 'العناية بالشفاه']]);

    expect(pyPage('/collections/py-lip-care/'))->toContain('id="kbb-th-title">Soft Lips, Every Day</h1>');

    pyRtl();

    $ar = pyPage('/ar/collections/py-lip-care/');

    expect($ar)->toContain('id="kbb-th-title">العناية بالشفاه</h1>')
        ->and($ar)->not->toContain('Soft Lips, Every Day')
        ->and($ar)->toContain('dir="rtl"')
        // Logical: the same class serves both directions.
        ->and(pyOpen($ar))->toContain('kbb-th--a-start');
});

it('the custom description wins, and a blank one falls back to the category\'s own', function () {
    $category = pyCategory(['header_description' => "Our lip edit.\n\nTwo paragraphs & an ampersand."]);

    $html = pyPage('/collections/py-lip-care/');

    // 2.60.350: the description is cut at its line count (kbb-th__desc--clamp), as he asked.
    expect($html)->toContain('<div class="kbb-th__desc kbb-th__desc--clamp"><p>Our lip edit.</p>')
        ->and($html)->toContain('Two paragraphs &amp; an ampersand.')
        ->and($html)->not->toContain('Balms and masks for soft lips.');

    $category->forceFill(['header_description' => '   '])->save();

    expect(pyPage('/collections/py-lip-care/'))->toContain('Balms and masks for soft lips.');
});

it('escapes a custom title and allowlists a custom description', function () {
    pyCategory([
        'header_title' => '<img src=x onerror=alert(1)>"Lips"',
        'header_description' => '<p>Hi<script>alert(2)</script> <a href="javascript:alert(3)">x</a></p>',
    ]);

    $html = pyPage('/collections/py-lip-care/');
    $header = substr($html, (int) strpos($html, 'data-kbb-title-header'));
    $header = substr($header, 0, (int) strpos($header, '</section>'));

    expect($header)->toContain('id="kbb-th-title">&quot;Lips&quot;</h1>')
        ->and(str_contains($header, '<script'))->toBeFalse()
        ->and(str_contains($header, 'javascript:'))->toBeFalse()
        ->and(str_contains($header, 'onerror'))->toBeFalse();
});

/* ═══════════════════════════════════════════════════════ the light box ═══ */

it('draws the light box with its icons on a category with no picture, and needs no new stylesheet rule per page', function () {
    pyCategory();

    $html = pyPage('/collections/py-lip-care/');
    $open = pyOpen($html);

    expect($open)->toContain('class="kbb-th kbb-th--box kbb-th--dark kbb-th--a-start kbb-th--v-bottom kbb-th--t-none kbb-th--box-blush"') // 2.60.350: words at the bottom, as he asked
        ->and($html)->toContain('<div class="kbb-th__icons" aria-hidden="true"></div>')
        ->and($html)->not->toContain('class="kbb-th__img"')
        ->and($html)->toContain('kbb-title-header');
});

it('leaves /shop/, a search, the brand filter and a brand page with no picture alone', function () {
    /*
     * He asked for categories. A brand with no picture stays exactly as
     * 2.60.346 left it -- on its own page and on /shop/?filter_brands= -- until
     * "Light box on brand pages with no picture" is switched on.
     */
    Brand::create(['name' => 'Py Brand', 'slug' => 'py-brand', 'description' => 'A brand.']);

    foreach (['/shop/', '/shop/?s=toner', '/shop/?filter_brands=py-brand', '/brands/py-brand/'] as $path) {
        $html = pyPage($path);
        expect(str_contains($html, 'data-kbb-title-header'))->toBeFalse($path)
            ->and(str_contains($html, 'kbb-title-header'))->toBeFalse($path);
    }

    pySave(['cat_header_box_brands' => true]);

    expect(pyOpen(pyPage('/brands/py-brand/')))->toContain('kbb-th--box kbb-th--dark')
        ->and(pyOpen(pyPage('/shop/?filter_brands=py-brand')))->toContain('kbb-th--box');

    // Still nothing on /shop/ or a search, which have no row to head.
    expect(str_contains(pyPage('/shop/'), 'data-kbb-title-header'))->toBeFalse();
});

it('renders every box style A to F with its own class, and only E without icons', function () {
    foreach (array_keys(SiteLayout::BOX_STYLES) as $i => $box) {
        pyCategory(['slug' => 'py-box-'.$box, 'header_style' => ['box' => $box]]);

        $html = pyPage('/collections/py-box-'.$box.'/');
        $open = pyOpen($html);

        expect($open)->toContain('kbb-th--box-'.$box.'"')
            ->and(str_contains($html, 'class="kbb-th__icons"'))->toBe($box !== 'plain', $box)
            // The two that use his own colours carry them; the presets do not.
            ->and(str_contains($open, '--kbb-th-bg:#FFF4EE'))->toBe(in_array($box, ['plain', 'custom'], true), $box)
            ->and(str_contains($open, '--kbb-th-ic:#EFA889'))->toBe($box === 'custom', $box);
    }

    // And the stylesheet has a ground for each preset.
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-title-header.css'));
    foreach (['blush', 'cream', 'mint', 'lilac'] as $preset) {
        expect($css)->toMatch('/\.kbb-th--box-'.$preset.'\{--kbb-th-bg:#[0-9A-F]{6};--kbb-th-ic:#[0-9A-F]{6}\}/');
    }
});

it('uses white words on a dark box colour of his own when the text colour is Automatic', function () {
    pySave(['cat_header_box_style' => 'plain', 'cat_header_box_bg' => '#2A1F3D']);
    pyCategory();

    expect(pyOpen(pyPage('/collections/py-lip-care/')))->toContain('kbb-th--box kbb-th--light')
        ->and(TitleHeader::luminance('#2A1F3D'))->toBeLessThan(0.4)
        ->and(TitleHeader::luminance('#FFF4EE'))->toBeGreaterThan(0.4);
});

it('draws the icons from one constant data: URI that is the SVG beside the stylesheet, byte for byte', function () {
    /*
     * "NEVER a setting printed raw", and no request: the pattern is a constant.
     * The URI is the readable SVG source with its line breaks removed and the
     * five characters a CSS url("…") cannot carry escaped.
     */
    $svg = (string) file_get_contents(resource_path('css/kbb/kbb-title-header-icons.svg'));
    $flat = implode('', array_map('trim', explode("\n", $svg)));
    $uri = 'data:image/svg+xml,'.strtr($flat, ['%' => '%25', '#' => '%23', '<' => '%3C', '>' => '%3E', '"' => '%22']);

    $css = (string) file_get_contents(resource_path('css/kbb/kbb-title-header.css'));

    expect(substr_count($css, 'mask-image:url("'.$uri.'")'))->toBe(2)
        // No other address of any kind in the file.
        ->and(preg_match_all('/url\(/', $css))->toBe(2)
        ->and(preg_match('#https?://(?!www\.w3\.org/2000/svg)#', $css))->toBe(0);

    // And the built copy the shop serves carries it too.
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
    $built = (string) file_get_contents(public_path('build/'.$manifest['resources/css/kbb/kbb-title-header.css']['file']));
    expect($built)->toContain('url("'.$uri.'")');
});

/* ═════════════════════════════════════════════ alignment and treatments ═══ */

it('aligns logically: text-align:start and flex-start, never left or right', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-title-header.css'));

    expect($css)->toContain('text-align:start')
        ->and($css)->toContain('.kbb-th--a-end .kbb-th__inner{align-items:flex-end;text-align:end}')
        ->and(preg_match('/text-align:\s*(left|right)/', $css))->toBe(0)
        // The fade and the box's wash follow the words to the right in Arabic.
        ->and($css)->toContain('[dir="rtl"] .kbb-th--a-start{--kbb-th-side:to left}');
});

it('centres or ends the words from the shop setting, and a category\'s own choice beats it', function () {
    pyCategory();
    pyCategory(['slug' => 'py-own', 'header_style' => ['align' => 'end']]);

    pySave(['cat_header_align' => 'center']);

    expect(pyOpen(pyPage('/collections/py-lip-care/')))->toContain('kbb-th--a-center')
        ->and(pyOpen(pyPage('/collections/py-own/')))->toContain('kbb-th--a-end');
});

it('renders every text treatment 1 to 5 on a picture and on the box, each with a rule in the stylesheet', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/kbb-title-header.css'));

    foreach (array_keys(SiteLayout::TREATMENTS) as $t) {
        pyCategory(['slug' => 'py-img-'.$t, 'header_image' => '/uploads/py/x.jpg', 'header_style' => ['treatment' => $t]]);
        pyCategory(['slug' => 'py-box-'.$t, 'header_style' => ['treatment' => $t]]);

        // 2.60.358: a picture header ends in kbb-th--pw (the whole picture on a phone).
        expect(pyOpen(pyPage('/collections/py-img-'.$t.'/')))->toContain('kbb-th--img kbb-th--light kbb-th--a-start kbb-th--v-bottom kbb-th--t-'.$t.' kbb-th--pw"')
            ->and(pyOpen(pyPage('/collections/py-box-'.$t.'/')))->toContain('kbb-th--t-'.$t.' kbb-th--box-blush"');

        if ($t !== 'none') {
            expect($css)->toContain('.kbb-th--t-'.$t);
        }
    }

    // The frosted panel has a fallback for a browser without backdrop-filter.
    expect($css)->toContain('@supports not ((backdrop-filter:blur(1px)) or (-webkit-backdrop-filter:blur(1px)))');
});

it('a blank override follows the shop, and sizes ride on the header as clamped properties', function () {
    pyCategory(['header_style' => ['title_phone' => 30, 'h_desktop' => 360]]);
    pyCategory(['slug' => 'py-plain', 'header_style' => null]);

    pySave(['cat_header_title_phone' => 22, 'cat_header_title_desktop' => 44, 'cat_header_pad_y_phone' => 12,
        'cat_header_radius' => 0, 'cat_header_maxw' => 600, 'cat_header_weight' => '800']);

    $own = pyOpen(pyPage('/collections/py-lip-care/'));
    $plain = pyOpen(pyPage('/collections/py-plain/'));

    expect($own)->toContain('--kbb-th-ts:30px;--kbb-th-tsd:44px;')
        ->and($own)->toContain('--kbb-th-hd:360px;')
        ->and($plain)->toContain('--kbb-th-ts:22px;--kbb-th-tsd:44px;')
        ->and($plain)->toContain('--kbb-th-hd:300px;')
        ->and($plain)->toContain('--kbb-th-py:12px;')
        ->and($plain)->toContain('--kbb-th-r:0px;')
        ->and($plain)->toContain('--kbb-th-mw:600px;')
        ->and($plain)->toContain('--kbb-th-tw:800');
});

/* ═════════════════════════════════════════════════════════════ guards ═══ */

it('refuses a word that is not on its list, and clamps a number, wherever the row came from', function () {
    expect(TitleHeader::sanitizeStyle([
        'align' => 'left;color:red',
        'treatment' => 'frost',
        'box' => 'url(javascript:x)',
        'title_phone' => 999,
        'title_desktop' => '7',
        'h_phone' => 'abc',
        'evil' => 'x',
    ]))->toBe(['treatment' => 'frost', 'title_phone' => 56, 'title_desktop' => 18]);

    expect(TitleHeader::sanitizeStyle('{"align":"end","h_desktop":"9999"}'))->toBe(['align' => 'end', 'h_desktop' => 640])
        ->and(TitleHeader::sanitizeStyle('not json'))->toBe([])
        ->and(TitleHeader::sanitizeStyle(null))->toBe([]);

    // A row written behind the screen's back still renders only listed words.
    $c = pyCategory();
    DB::table('categories')->where('id', $c->id)->update(['header_style' => json_encode(['align' => '"><script>', 'title_phone' => -5])]);

    $open = pyOpen(pyPage('/collections/py-lip-care/'));
    expect($open)->toContain('kbb-th--a-start')
        ->and($open)->toContain('--kbb-th-ts:16px;')
        ->and($open)->not->toContain('<script');
});

it('refuses a select value, and takes #rgb and #rrggbb colours and nothing else', function () {
    $layout = app(SiteLayout::class);

    foreach (['cat_header_treatment' => 'glow', 'cat_header_box_style' => 'neon', 'cat_header_align' => 'left',
        'cat_header_text' => 'red', 'cat_header_weight' => '900'] as $key => $bad) {
        expect($layout->save([$key => $bad])['rejected'])->toHaveKey($key);
    }

    foreach (['red', 'url(x)', '#12345', '#GGGGGG', '#fff;background:url(x)', 'fef'] as $bad) {
        expect(array_keys($layout->save(['cat_header_box_bg' => $bad])['rejected']))->toBe(['cat_header_box_bg'], $bad);
    }

    pySave(['cat_header_box_bg' => '#fef', 'cat_header_box_icon' => '#a1b2c3']);

    expect($layout->all()['cat_header_box_bg'])->toBe('#FFEEFF')
        ->and($layout->all()['cat_header_box_icon'])->toBe('#A1B2C3');

    // A range is clamped into its slider.
    pySave(['cat_header_h_phone' => 5000, 'cat_header_radius' => -3]);
    expect($layout->all()['cat_header_h_phone'])->toBe(480)
        ->and($layout->all()['cat_header_radius'])->toBe(0);
});

it('costs no query: a category with the light box runs exactly as many as one with the plain title', function () {
    pyCategory(['header_style' => ['box' => 'mint', 'title_phone' => 30]]);

    $count = function (): int {
        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });
        pyPage('/collections/py-lip-care/');

        return $n;
    };

    pyPage('/collections/py-lip-care/');
    $box = $count();

    pySave(['cat_header_box' => false]);
    pyPage('/collections/py-lip-care/');
    $plain = $count();

    expect($box)->toBe($plain);
});

/* ═══════════════════════════════════════════════════════════ the admin ═══ */

describe('Catalog -> Categories -> Edit -> Category header', function () {
    beforeEach(function () {
        CatalogAdminRoutes::wire($this->app);
        $this->actingAs(pyAdmin(), 'admin');
    });

    it('shows the imported title to the editor, and clears it on a blank save', function () {
        $c = pyCategory(['header_title' => 'Korean Lip Care', 'header_source' => 'cover_section (rey 1)']);

        $row = collect($this->getJson('/admin-api/categories')->assertOk()->json('categories'))->firstWhere('id', $c->id);

        expect($row['header_title'])->toBe('Korean Lip Care')
            ->and($row['header_source'])->toBe('cover_section (rey 1)')
            ->and($row)->toHaveKeys(['header_image', 'header_subtitle', 'header_description', 'header_style']);

        $this->putJson('/admin-api/categories/'.$c->id, ['name' => 'Lip Care', 'slug' => 'py-lip-care', 'header_title' => ''])
            ->assertOk();

        expect($c->fresh()->header_title)->toBeNull()
            ->and(pyPage('/collections/py-lip-care/'))->toContain('id="kbb-th-title">Lip Care</h1>');
    });

    it('saves a picture, the words and the look, and a save that does not send the header leaves it alone', function () {
        $c = pyCategory();

        $this->putJson('/admin-api/categories/'.$c->id, [
            'name' => 'Lip Care', 'slug' => 'py-lip-care',
            'header_image' => '/uploads/categories/lips.jpg',
            'header_title' => 'Lips',
            'header_description' => 'Mine.',
            'header_style' => ['align' => 'center', 'treatment' => 'label', 'box' => 'lilac', 'title_phone' => 200, 'h_phone' => null],
        ])->assertOk();

        $fresh = $c->fresh();
        expect($fresh->header_image)->toBe('/uploads/categories/lips.jpg')
            ->and($fresh->header_style)->toBe(['align' => 'center', 'treatment' => 'label', 'box' => 'lilac', 'title_phone' => 56]);

        // Catalog -> Catalog's own Categories tab sends no header keys at all.
        $this->putJson('/admin-api/categories/'.$c->id, ['name' => 'Lip Care', 'slug' => 'py-lip-care', 'description' => 'New.'])
            ->assertOk();

        $again = $c->fresh();
        expect($again->header_image)->toBe('/uploads/categories/lips.jpg')
            ->and($again->header_title)->toBe('Lips')
            ->and($again->header_style['treatment'])->toBe('label');

        // Remove picture + every select back to "Use the shop setting".
        $this->putJson('/admin-api/categories/'.$c->id, [
            'name' => 'Lip Care', 'slug' => 'py-lip-care', 'header_image' => '',
            'header_style' => ['align' => null, 'treatment' => null, 'box' => null],
        ])->assertOk();

        expect($c->fresh()->header_image)->toBeNull()
            ->and($c->fresh()->header_style)->toBeNull();
    });

    it('refuses a script or data address for the picture, a word off its list, and a typed word for a number', function () {
        $c = pyCategory();
        $base = ['name' => 'Lip Care', 'slug' => 'py-lip-care'];

        foreach (['javascript:alert(1)', 'data:image/svg+xml;base64,PHN2Zz4=', '//evil.test/x.jpg', '/a/../b.jpg'] as $bad) {
            $this->putJson('/admin-api/categories/'.$c->id, $base + ['header_image' => $bad])
                ->assertStatus(422)->assertJsonValidationErrors('header_image');
        }

        $this->putJson('/admin-api/categories/'.$c->id, $base + ['header_style' => ['align' => 'left']])
            ->assertStatus(422)->assertJsonValidationErrors('header_style.align');
        $this->putJson('/admin-api/categories/'.$c->id, $base + ['header_style' => ['treatment' => 'glow']])
            ->assertStatus(422);
        $this->putJson('/admin-api/categories/'.$c->id, $base + ['header_style' => ['title_phone' => 'big']])
            ->assertStatus(422);

        expect($c->fresh()->header_image)->toBeNull();
    });
});

it('names every option the same way in the server, the settings screen and the category editor', function () {
    /*
     * The two admin screens carry copies of the lists (their previews and
     * selects are drawn in the browser). This is what stops a fourth box style
     * being added to one and not the others.
     */
    /*
     * Lane QC moved the lists, the preview's header and the design tiles into
     * admin/partials/title-header-kit.blade.php, which both screens include
     * -- one copy instead of two, so the lists are read from there.
     */
    $layout = (string) file_get_contents(resource_path('views/admin/partials/title-header-kit.blade.php'));
    $editor = (string) file_get_contents(resource_path('views/admin/partials/category-tree-screen.blade.php'));

    expect($editor)->toContain("@include('admin.partials.title-header-kit')");

    $quoted = fn (array $words) => implode(', ', array_map(fn ($w) => "'".$w."'", $words));

    expect($layout)->toContain("var PV_ALIGNS = [".$quoted(TitleHeader::ALIGNS).'];')
        ->and($layout)->toContain("var PV_TREATMENTS = [".$quoted(array_keys(SiteLayout::TREATMENTS)).'];')
        ->and($layout)->toContain("var PV_BOXES = [".$quoted(array_keys(SiteLayout::BOX_STYLES)).'];')
        ->and($layout)->toContain("var PV_ICON_BOXES = [".$quoted(TitleHeader::ICON_BOXES).'];');

    // The editor's tiles are the kit's (Lane QC), and it names no list of its own.
    expect($editor)->toContain('window.kbbTH')
        ->and($editor)->not->toContain('var HDR_ALIGNS')
        ->and($editor)->not->toContain('var HDR_BOXES');

    // The editor's number bounds are the sliders'.
    foreach (TitleHeader::STYLE_NUMBERS as $mine => $setting) {
        $b = SiteLayout::SCHEMA[$setting][4];
        expect($editor)->toContain("'".$mine."', ")
            ->and($editor)->toMatch("/'".$mine."', '[^']+', ".$b['min'].', '.$b['max'].'\]/');
    }

    // The preview writes the same property names the server does -- all but
    // the space above and below, which the preview's flush header has none of.
    foreach (TitleHeader::PX_VARS as $var => $setting) {
        if (str_ends_with($setting, '_phone') && ! in_array($var, ['--kbb-th-mt', '--kbb-th-mb'], true)) {
            expect($layout)->toContain("['".$var."', '".$setting."', '".str_replace('_phone', '_desktop', $setting)."']");
        }
    }
});

it('resets the two stored values the new defaults replace, and only those', function () {
    foreach (['layout_cat_header_align' => 'center', 'layout_cat_header_text' => 'light', 'layout_cat_header_overlay' => '55'] as $k => $v) {
        Setting::query()->updateOrCreate(['key' => $k], ['value' => $v, 'autoload' => true]);
    }

    (require database_path('migrations/2027_07_14_000200_clear_caches_category_header_options.php'))->up();

    expect(Setting::query()->where('key', 'layout_cat_header_align')->exists())->toBeFalse()
        ->and(Setting::query()->where('key', 'layout_cat_header_text')->exists())->toBeFalse()
        ->and(Setting::query()->where('key', 'layout_cat_header_overlay')->value('value'))->toBe('55');
});
