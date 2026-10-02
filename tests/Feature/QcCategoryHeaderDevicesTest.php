<?php

declare(strict_types=1);

/*
 * ════════════════════════════════════════════════════════════════════════════
 * LANE QC — the category header's designs as tiles, phone and laptop apart,
 * a live preview, and fine-tuning for every design
 * ════════════════════════════════════════════════════════════════════════════
 *
 * THE OWNER, IN HIS WORDS:
 *
 *   "i will upload the background images manually. forget it. i need the
 *    same designs on backend to choose the category banner designs, text
 *    style etc and for mobile also. i will set and upload the banners
 *    manually"
 *
 *   "along with previews of designs to choose, and also along with LIVE
 *    preview."
 *
 *   "make sure that i should have these designs to chooose from and make
 *    edits as per need." -- pointing at docs/py-options/overview.png.
 *
 * WHAT THE SHOP DID BEFORE (2.60.349): one value per choice for every width,
 * picked from dropdowns; only F had colours of its own; nothing about a
 * treatment could be tuned; a category could not choose per device.
 *
 * MUTATIONS, RUN (each red, then restored; storage/qc-logs/qc-mutate.py, not
 * committed). Measured, this file alone: Q1 1 red, Q2 5, Q3 1, Q4 1, Q5 1,
 * Q6 4, Q7 1, Q8 1, Q9 1, Q10 3 -- no mutation left it green:
 *   Q1  SiteLayout::all(): the DEVICE_PAIRS inheritance loop removed: red,
 *       `a value saved before the split is both devices' value`.
 *   Q2  TitleHeader::forModel(): `$split = $phone !== $laptop` -> `false`:
 *       red, `phone and laptop render their own classes`.
 *   Q3  TitleHeaderSplitCss::build(): PHONE_QUERY and LAPTOP_QUERY swapped:
 *       red, `the stylesheet picks by width`.
 *   Q4  kbb-title-header.css: one generated rule edited by hand: red,
 *       `the generated block is the rules above it`.
 *   Q5  TitleHeader::device(): the per-device key ignored (only `align`):
 *       red, `a category's per-device choice beats the shop's and PY's`.
 *   Q6  TitleHeader::style(): the `=== default` skip removed from the
 *       TWEAK_VARS loop: red, `untouched, every design is the sheet's` and
 *       the byte-identical default pin.
 *   Q7  kbb-title-header.css: the icon opacity fallback .6 -> .5: red,
 *       `every tweak falls back to the number as drawn`.
 *   Q8  sanitizeStyle(): the pick() on DEVICE_CHOICES replaced by the raw
 *       value: red, `refuses a word off its list`.
 *   Q9  the kit's tiles: role="radio" -> role="option": red, `radio-group
 *       semantics`.
 *   Q10 TitleHeader::tone(): luminance for presets dropped (always dark on a
 *       box): red, `a tuned dark box gets white words`.
 */

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Setting;
use App\Services\ModuleSchema;
use App\Services\SettingsService;
use App\Services\SiteLayout;
use App\Support\TitleHeader;
use App\Support\TitleHeaderSplitCss;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogAdminRoutes;

function qcCategory(array $overrides = []): Category
{
    $category = Category::query()->create(array_merge([
        'name' => 'Sunscreens',
        'slug' => 'qc-sun',
        'parent_id' => null,
        'description' => 'Light Korean sunscreens.',
    ], $overrides));

    $category->forceFill(['path' => $category->slug, 'depth' => 0])->save();

    return $category;
}

function qcOpen(string $path): string
{
    $html = (string) test()->get($path)->assertOk()->getContent();

    return preg_match('#<section class="kbb-th[^"]*"[^>]*data-kbb-title-header[^>]*>#', $html, $m) === 1 ? $m[0] : '';
}

/** @return array{0: string, 1: string} the class and style attributes */
function qcAttrs(string $open): array
{
    preg_match('#class="([^"]*)"#', $open, $c);
    preg_match('#style="([^"]*)"#', $open, $s);

    return [$c[1] ?? '', $s[1] ?? ''];
}

function qcSave(array $values): void
{
    $result = app(SiteLayout::class)->save($values);
    expect($result['rejected'])->toBe([]);
    SettingsService::forgetMemo();
    ModuleSchema::forgetNormalised();
}

/** A raw settings row, as an older release (or a hand) left it. */
function qcStore(string $key, string $value): void
{
    Setting::query()->updateOrCreate(['key' => 'layout_'.$key], ['value' => $value, 'autoload' => true]);
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    ModuleSchema::forgetNormalised();
}

function qcCss(): string
{
    return (string) file_get_contents(resource_path('css/kbb/kbb-title-header.css'));
}

function qcKit(): string
{
    return (string) file_get_contents(resource_path('views/admin/partials/title-header-kit.blade.php'));
}

/** 2.60.349's style attribute at the shipped values, byte for byte. */
const QC_DEFAULT_STYLE = '--kbb-th-h:190px;--kbb-th-hd:300px;--kbb-th-ts:26px;--kbb-th-tsd:40px;--kbb-th-ds:13px;'
    .'--kbb-th-dsd:15px;--kbb-th-py:28px;--kbb-th-pyd:36px;--kbb-th-px:20px;--kbb-th-pxd:48px;--kbb-th-r:18px;'
    .'--kbb-th-mt:6px;--kbb-th-mtd:6px;--kbb-th-mb:22px;--kbb-th-mbd:26px;--kbb-th-mw:760px;--kbb-th-ov:0.4;'
    .'--kbb-th-lines:2;--kbb-th-tw:700';

/* ══════════════════════════════════════════ nothing moves by applying it ═══ */

it('ships the laptop at the phone\'s defaults, and a default category page byte-identical to 2.60.349', function () {
    /*
     * "Defaults unchanged from 2.60.349 (A, 1, Start, Auto) on both devices."
     * The light box and the picture header at the shipped values carry the
     * exact class and style 2.60.349 wrote -- no split class, no tweak.
     */
    foreach (SiteLayout::DEVICE_PAIRS as $phone => $laptop) {
        expect(SiteLayout::SCHEMA[$laptop][2])->toBe(SiteLayout::SCHEMA[$phone][2], $laptop);
    }

    qcCategory();
    qcCategory(['slug' => 'qc-pic', 'header_image' => '/uploads/qc/banner.jpg']);

    expect(qcOpen('/collections/qc-sun/'))->toBe('<section class="kbb-th kbb-th--box kbb-th--dark kbb-th--a-start kbb-th--v-bottom kbb-th--t-none kbb-th--box-blush" style="'.QC_DEFAULT_STYLE.'" data-kbb-title-header aria-labelledby="kbb-th-title">')
        // 2.60.358: picture headers end in kbb-th--pw (the whole picture on a phone).
        ->and(qcOpen('/collections/qc-pic/'))->toBe('<section class="kbb-th kbb-th--img kbb-th--light kbb-th--a-start kbb-th--v-bottom kbb-th--t-shadow kbb-th--pw" style="'.QC_DEFAULT_STYLE.'" data-kbb-title-header aria-labelledby="kbb-th-title">');
});

it('gives a value saved before the split to both devices, until the laptop gets its own', function () {
    /*
     * "No shop that saved a choice may change appearance by applying this."
     * 2.60.349 stored ONE row per choice; there is no laptop row on such a
     * shop. SiteLayout::all() hands the laptop the phone's value.
     */
    qcStore('cat_header_align', 'center');
    qcStore('cat_header_box_style', 'lilac');
    qcStore('cat_header_box_treatment', 'frost');
    qcStore('cat_header_text', 'light');

    $all = app(SiteLayout::class)->all();

    expect($all['cat_header_align_desktop'])->toBe('center')
        ->and($all['cat_header_box_style_desktop'])->toBe('lilac')
        ->and($all['cat_header_box_treatment_desktop'])->toBe('frost')
        ->and($all['cat_header_text_desktop'])->toBe('light')
        ->and($all['cat_header_treatment_desktop'])->toBe('shadow');

    qcCategory();
    [$class] = qcAttrs(qcOpen('/collections/qc-sun/'));
    expect($class)->toBe('kbb-th kbb-th--box kbb-th--light kbb-th--a-center kbb-th--v-bottom kbb-th--t-frost kbb-th--box-lilac');

    // A laptop row of its own, and only then do they part.
    qcStore('cat_header_align_desktop', 'end');
    expect(app(SiteLayout::class)->all()['cat_header_align'])->toBe('center')
        ->and(app(SiteLayout::class)->all()['cat_header_align_desktop'])->toBe('end');
});

/* ══════════════════════════════════════════════ phone and laptop apart ═══ */

it('phone and laptop render their own classes: laptop B + 3 centred, phone A + 2 at the start', function () {
    qcSave([
        'cat_header_box_style' => 'blush', 'cat_header_box_treatment' => 'fade', 'cat_header_treatment' => 'fade', 'cat_header_align' => 'start',
        'cat_header_box_style_desktop' => 'cream', 'cat_header_box_treatment_desktop' => 'frost', 'cat_header_treatment_desktop' => 'frost', 'cat_header_align_desktop' => 'center',
    ]);

    qcCategory();
    qcCategory(['slug' => 'qc-pic', 'header_image' => '/uploads/qc/banner.jpg']);

    [$box] = qcAttrs(qcOpen('/collections/qc-sun/'));
    [$pic] = qcAttrs(qcOpen('/collections/qc-pic/'));

    expect($box)->toBe('kbb-th kbb-th--box kbb-th--split kbb-th--p-dark kbb-th--p-a-start kbb-th--p-v-bottom kbb-th--p-t-fade kbb-th--p-box-blush kbb-th--l-dark kbb-th--l-a-center kbb-th--l-v-bottom kbb-th--l-t-frost kbb-th--l-box-cream')
        ->and($pic)->toBe('kbb-th kbb-th--img kbb-th--split kbb-th--p-light kbb-th--p-a-start kbb-th--p-v-bottom kbb-th--p-t-fade kbb-th--l-light kbb-th--l-a-center kbb-th--l-v-bottom kbb-th--l-t-frost kbb-th--pw')
        // The icon layer is there for the device that has icons.
        ->and((string) $this->get('/collections/qc-sun/')->getContent())->toContain('<div class="kbb-th__icons" aria-hidden="true"></div>');
});

it('where the words sit is per device too, for the shop and for a category', function () {
    /*
     * 2.60.350's "the content will come left bottom with spacing controls, as
     * give control of the overal box for desktop and mobile also" -- bottom
     * ships on both; each device may move on its own.
     */
    expect(SiteLayout::DEVICE_PAIRS['cat_header_valign'])->toBe('cat_header_valign_desktop')
        ->and(SiteLayout::SCHEMA['cat_header_valign_desktop'][2])->toBe('bottom');

    qcSave(['cat_header_valign_desktop' => 'top']);
    qcCategory();
    qcCategory(['slug' => 'qc-own', 'header_style' => ['valign_phone' => 'center']]);

    [$shop] = qcAttrs(qcOpen('/collections/qc-sun/'));
    [$own] = qcAttrs(qcOpen('/collections/qc-own/'));

    expect($shop)->toContain('kbb-th--p-v-bottom')->and($shop)->toContain('kbb-th--l-v-top')
        ->and($own)->toContain('kbb-th--p-v-center')->and($own)->toContain('kbb-th--l-v-top');

    $generated = (string) TitleHeaderSplitCss::generated(qcCss());
    expect($generated)->toContain('.kbb-th--p-v-bottom{align-items:flex-end}')
        ->and($generated)->toContain('.kbb-th--l-v-top{align-items:flex-start}');
});

it('the stylesheet picks by width: every choice rule twice, phone under 900px and laptop from 900px', function () {
    /*
     * No script and nothing measured: the two copies sit in exactly
     * complementary media queries, so one applies at any width.
     */
    $css = qcCss();
    $generated = TitleHeaderSplitCss::generated($css);

    expect($generated)->not->toBeNull();

    [$phone, $laptop] = explode(TitleHeaderSplitCss::LAPTOP_QUERY.'{', $generated, 2);

    expect($phone)->toStartWith(TitleHeaderSplitCss::PHONE_QUERY.'{')
        ->and($phone)->toContain('.kbb-th--p-t-frost .kbb-th__text{padding:16px 20px;')
        ->and($phone)->toContain('.kbb-th--p-a-center .kbb-th__inner{align-items:center;text-align:center}')
        ->and($phone)->toContain('[dir="rtl"] .kbb-th--p-a-start{--kbb-th-side:to left}')
        ->and($phone)->not->toContain('kbb-th--l-')
        ->and($laptop)->toContain('.kbb-th--l-t-frost .kbb-th__text{padding:16px 20px;')
        ->and($laptop)->toContain('.kbb-th--l-box-cream{--kbb-th-bg:#FBF4EA;--kbb-th-ic:#CDA57B}')
        ->and($laptop)->not->toContain('kbb-th--p-');

    // Every rule above the block that names a choice has a twin in each half.
    $source = (string) preg_replace('#/\*.*?\*/#s', '', TitleHeaderSplitCss::source($css));
    preg_match_all('/([^{}]+)\{[^{}]*\}/', $source, $rules);
    $choice = array_filter($rules[1], fn ($sel) => preg_match(TitleHeaderSplitCss::CHOICE_WORD, $sel) === 1);
    expect(count($choice))->toBeGreaterThan(30)
        ->and(substr_count($phone, '.kbb-th--p-'))->toBeGreaterThan(count($choice))
        ->and(PHP_EOL.$laptop)->toContain('.kbb-th--split.kbb-th--l-own{--kbb-th-bg:var(--kbb-th-l-bg);--kbb-th-ic:var(--kbb-th-l-ic)}');

    // And the built copy the shop serves carries both halves.
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
    $built = (string) file_get_contents(public_path('build/'.$manifest['resources/css/kbb/kbb-title-header.css']['file']));
    expect($built)->toContain('@media not all and (min-width:900px){')
        ->and($built)->toContain('.kbb-th--l-a-center .kbb-th__inner{align-items:center;text-align:center}')
        ->and($built)->toContain('.kbb-th--p-t-fade');
});

it('the generated block is the rules above it, byte for byte', function () {
    /* Edit a rule above without running tools/qc-split-css.php and this is red. */
    $css = qcCss();

    expect(TitleHeaderSplitCss::apply($css))->toBe($css)
        ->and(TitleHeaderSplitCss::generated($css))->toBe(TitleHeaderSplitCss::build(TitleHeaderSplitCss::source($css)));
});

it('writes a split box\'s own colours per device, never as the shared property', function () {
    qcSave(['cat_header_box_style' => 'plain', 'cat_header_box_style_desktop' => 'blush', 'cat_header_box_bg' => '#2A1F3D']);
    qcCategory();

    [$class, $style] = qcAttrs(qcOpen('/collections/qc-sun/'));

    expect($class)->toContain('kbb-th--p-light kbb-th--p-a-start kbb-th--p-v-bottom kbb-th--p-t-none kbb-th--p-box-plain kbb-th--p-own')
        ->and($class)->toContain('kbb-th--l-dark kbb-th--l-a-start kbb-th--l-v-bottom kbb-th--l-t-none kbb-th--l-box-blush')
        ->and($class)->not->toContain('kbb-th--l-own')
        ->and($style)->toContain('--kbb-th-p-bg:#2A1F3D;--kbb-th-p-ic:#EFA889')
        ->and($style)->not->toContain('--kbb-th-bg:');
});

/* ═══════════════════════════════════════════════════════════ per category ═══ */

it('a category\'s per-device choice beats the shop\'s, and PY\'s both-devices choice is both devices\'', function () {
    qcSave(['cat_header_box_style' => 'blush', 'cat_header_box_style_desktop' => 'cream']);

    qcCategory(['header_style' => ['box_desktop' => 'mint']]);
    qcCategory(['slug' => 'qc-py', 'header_style' => ['align' => 'end']]);
    qcCategory(['slug' => 'qc-both', 'header_style' => ['align' => 'end', 'align_phone' => 'center']]);

    [$own] = qcAttrs(qcOpen('/collections/qc-sun/'));
    [$py] = qcAttrs(qcOpen('/collections/qc-py/'));
    [$both] = qcAttrs(qcOpen('/collections/qc-both/'));

    expect($own)->toContain('kbb-th--p-box-blush')->and($own)->toContain('kbb-th--l-box-mint')
        // PY's `align` -- saved before this lane -- still both devices'.
        ->and($py)->toContain('kbb-th--p-a-end')->and($py)->toContain('kbb-th--l-a-end')
        ->and($both)->toContain('kbb-th--p-a-center')->and($both)->toContain('kbb-th--l-a-end');
});

it('a category\'s own colours repaint its box on both devices, and its phone crop is a class', function () {
    qcCategory(['header_style' => ['bg' => '#123456', 'ic' => '#ABCDEF']]);
    qcCategory(['slug' => 'qc-pic', 'header_image' => '/uploads/qc/b.jpg', 'header_style' => ['focus' => 'right']]);
    qcCategory(['slug' => 'qc-mid', 'header_image' => '/uploads/qc/b.jpg', 'header_style' => ['focus' => 'center']]);
    qcCategory(['slug' => 'qc-box-fx', 'header_style' => ['focus' => 'left']]);

    [$class, $style] = qcAttrs(qcOpen('/collections/qc-sun/'));

    expect($class)->toBe('kbb-th kbb-th--box kbb-th--light kbb-th--a-start kbb-th--v-bottom kbb-th--t-none kbb-th--box-blush')
        ->and($style)->toEndWith(';--kbb-th-bg:#123456;--kbb-th-ic:#ABCDEF')
        ->and(qcAttrs(qcOpen('/collections/qc-pic/'))[0])->toEndWith(' kbb-th--fx-right kbb-th--pw')
        ->and(qcAttrs(qcOpen('/collections/qc-mid/'))[0])->not->toContain('fx-')
        // No picture, nothing to crop.
        ->and(qcAttrs(qcOpen('/collections/qc-box-fx/'))[0])->not->toContain('fx-');

    expect(qcCss())->toContain("@media not all and (min-width:900px){\n  .kbb-th--fx-left .kbb-th__img{object-position:0% 50%}\n  .kbb-th--fx-right .kbb-th__img{object-position:100% 50%}\n}");
});

/* ═══════════════════════════════════════════════ "make edits as per need" ═══ */

it('untouched, every design is the sheet\'s: no tweak property on any of A-F or 1-5', function () {
    /*
     * "its default equals the design as drawn on the sheet (so choosing a
     * design and touching nothing looks exactly like the sheet)."
     */
    foreach (array_keys(SiteLayout::BOX_STYLES) as $box) {
        foreach (array_keys(SiteLayout::TREATMENTS) as $t) {
            $slug = 'qc-'.$box.'-'.$t;
            qcCategory(['slug' => $slug, 'header_style' => ['box' => $box, 'treatment' => $t]]);
            [$class, $style] = qcAttrs(qcOpen('/collections/'.$slug.'/'));

            expect($class)->toBe('kbb-th kbb-th--box kbb-th--dark kbb-th--a-start kbb-th--v-bottom kbb-th--t-'.$t.' kbb-th--box-'.$box);

            $extra = match ($box) {
                'plain' => ';--kbb-th-bg:#FFF4EE',
                'custom' => ';--kbb-th-bg:#FFF4EE;--kbb-th-ic:#EFA889',
                default => '',
            };
            expect($style)->toBe(QC_DEFAULT_STYLE.$extra, $slug);
        }
    }
});

it('every tweak falls back to the number as drawn, in the stylesheet itself', function () {
    $css = qcCss();

    foreach ([
        'opacity:var(--kbb-th-io, .6)',
        '--kbb-th-tile:calc(220px * var(--kbb-th-isz, 1))',
        '--kbb-th-tile:calc(280px * var(--kbb-th-isz, 1))',
        'letter-spacing:var(--kbb-th-ls, -.02em)',
        'rgba(0,0,0,calc(.5 * var(--kbb-th-shs, 1)))',
        '0 2px calc(16px * var(--kbb-th-shb, 1))',
        'rgba(18,12,16,calc(.78 * var(--kbb-th-fdd, 1))) 0',
        'calc(80% * var(--kbb-th-fdr, 1))',
        'rgba(24,16,20,calc(.42 * var(--kbb-th-fro, 1)))',
        'blur(var(--kbb-th-frb, 12px))',
        'border-radius:var(--kbb-th-frr, max(6px, calc(var(--kbb-th-r) - 4px)))',
        'background:var(--kbb-th-lbl-bg, rgba(168,47,83,.9));color:var(--kbb-th-lbl-fg, #fff)',
        'background:var(--kbb-th-lbl-bg, rgba(255,255,255,.92));color:var(--kbb-th-lbl-fg, var(--ink, #2a2228))',
        'color:var(--kbb-th-dc, var(--ink-2, #4a3f45))',
    ] as $needle) {
        expect($css)->toContain($needle);
    }

    // The four presets' colours are the settings' defaults.
    foreach (SiteLayout::BOX_PRESETS as $box => [$bg, $ic]) {
        expect($css)->toContain('.kbb-th--box-'.$box.'{--kbb-th-bg:'.$bg.';--kbb-th-ic:'.$ic.'}')
            ->and(SiteLayout::SCHEMA['cat_header_'.$box.'_bg'][2])->toBe($bg)
            ->and(SiteLayout::SCHEMA['cat_header_'.$box.'_ic'][2])->toBe($ic);
    }
});

it('each tweak moves only its own custom property', function () {
    qcCategory();
    [, $before] = qcAttrs(qcOpen('/collections/qc-sun/'));
    expect($before)->toBe(QC_DEFAULT_STYLE);

    $cases = [
        ['cat_header_icon_strength', 35, '--kbb-th-io:0.35'],
        ['cat_header_icon_size', 150, '--kbb-th-isz:1.5'],
        ['cat_header_shadow_strength', 160, '--kbb-th-shs:1.6'],
        ['cat_header_shadow_blur', 50, '--kbb-th-shb:0.5'],
        ['cat_header_fade_dark', 120, '--kbb-th-fdd:1.2'],
        ['cat_header_fade_reach', 70, '--kbb-th-fdr:0.7'],
        ['cat_header_frost_opacity', 140, '--kbb-th-fro:1.4'],
        ['cat_header_frost_blur', 20, '--kbb-th-frb:20px'],
        ['cat_header_frost_radius', 4, '--kbb-th-frr:4px'],
        ['cat_header_letter', 5, '--kbb-th-ls:0.05em'],
        ['cat_header_label_bg', '#123abc', '--kbb-th-lbl-bg:#123ABC'],
        ['cat_header_label_fg', '#fff', '--kbb-th-lbl-fg:#FFFFFF'],
        ['cat_header_desc_colour', '#333333', '--kbb-th-dc:#333333'],
        ['cat_header_blush_bg', '#FFE0EA', '--kbb-th-bg:#FFE0EA'],
        ['cat_header_blush_ic', '#C0607E', '--kbb-th-ic:#C0607E'],
    ];

    foreach ($cases as [$key, $value, $declaration]) {
        qcSave([$key => $value]);
        [$class, $style] = qcAttrs(qcOpen('/collections/qc-sun/'));

        expect($style)->toBe(QC_DEFAULT_STYLE.';'.$declaration, $key)
            ->and($class)->toBe('kbb-th kbb-th--box kbb-th--dark kbb-th--a-start kbb-th--v-bottom kbb-th--t-none kbb-th--box-blush', $key);

        qcSave([$key => SiteLayout::SCHEMA[$key][2]]);
    }

    // Every tweak in the map was exercised.
    expect(array_column(array_values(TitleHeader::TWEAK_VARS), 0))->each->toBeIn(array_column($cases, 0));
});

it('a tuned dark box gets white words when the text colour is Automatic', function () {
    qcSave(['cat_header_cream_bg' => '#2B1D14']);
    qcCategory(['header_style' => ['box' => 'cream']]);

    expect(qcAttrs(qcOpen('/collections/qc-sun/'))[0])->toBe('kbb-th kbb-th--box kbb-th--light kbb-th--a-start kbb-th--v-bottom kbb-th--t-none kbb-th--box-cream');
});

/* ═════════════════════════════════════════════════════════════════ guards ═══ */

it('refuses a word off its list, a colour that is not one, and clamps a number', function () {
    $layout = app(SiteLayout::class);

    foreach (['cat_header_box_style_desktop' => 'neon', 'cat_header_align_desktop' => 'left',
        'cat_header_text_desktop' => 'red', 'cat_header_treatment_desktop' => 'glow',
        'cat_header_label_bg' => 'url(x)', 'cat_header_desc_colour' => 'red;x:y', 'cat_header_cream_bg' => 'fef',
        'cat_header_label_fg' => '#12345'] as $key => $bad) {
        expect($layout->save([$key => $bad])['rejected'])->toHaveKey($key);
    }

    qcSave(['cat_header_label_bg' => '#fef', 'cat_header_desc_colour' => '', 'cat_header_icon_strength' => 900, 'cat_header_letter' => -50]);
    expect($layout->all()['cat_header_label_bg'])->toBe('#FFEEFF')
        ->and($layout->all()['cat_header_desc_colour'])->toBe('')
        ->and($layout->all()['cat_header_icon_strength'])->toBe(100)
        ->and($layout->all()['cat_header_letter'])->toBe(-5);

    expect(TitleHeader::sanitizeStyle([
        'align_phone' => 'center', 'align_desktop' => 'left;x', 'box_desktop' => 'url(javascript:x)',
        'treatment_phone' => 'frost', 'text_desktop' => 'dark', 'text_phone' => 'red', 'focus' => 'top',
        'bg' => '#abc', 'ic' => 'red', 'evil' => 'x',
    ]))->toBe(['align_phone' => 'center', 'treatment_phone' => 'frost', 'text_desktop' => 'dark', 'bg' => '#AABBCC']);

    // A row written behind the screen's back still prints only listed words and checked colours.
    $c = qcCategory();
    DB::table('categories')->where('id', $c->id)->update(['header_style' => json_encode([
        'align_desktop' => '"><script>', 'bg' => '#fff;background:url(x)', 'box_phone' => 'mint',
    ])]);
    $open = qcOpen('/collections/qc-sun/');
    expect($open)->not->toContain('<script')->and($open)->not->toContain('url(')
        ->and($open)->toContain('kbb-th--p-box-mint');
});

it('costs no query: a split header with every tweak runs exactly as many as the default one', function () {
    qcCategory(['header_style' => ['box_desktop' => 'mint', 'focus' => 'left', 'bg' => '#EEEEEE']]);

    $count = function (): int {
        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });
        test()->get('/collections/qc-sun/')->assertOk();

        return $n;
    };

    test()->get('/collections/qc-sun/');
    $split = $count();

    DB::table('categories')->update(['header_style' => null]);
    test()->get('/collections/qc-sun/');
    $plain = $count();

    expect($split)->toBe($plain);
});

/* ══════════════════════════════════════════════════════════════ the admin ═══ */

describe('Catalog -> Categories -> Edit -> Category header, per device', function () {
    beforeEach(function () {
        CatalogAdminRoutes::wire($this->app);
        $this->actingAs(AdminUser::firstOrCreate(
            ['email' => 'qc-admin@example.test'],
            ['name' => 'QC Admin', 'password' => 'password-long-enough', 'role' => 'owner']
        ), 'admin');
    });

    it('saves per-device choices, the crop and the colours, and retires PY\'s both-devices keys', function () {
        $c = qcCategory(['header_style' => ['align' => 'end', 'box' => 'lilac']]);

        $this->putJson('/admin-api/categories/'.$c->id, [
            'name' => 'Sunscreens', 'slug' => 'qc-sun',
            'header_style' => [
                'align' => null, 'treatment' => null, 'box' => null,
                'align_phone' => 'end', 'align_desktop' => 'center',
                'box_phone' => 'lilac', 'box_desktop' => 'lilac',
                'treatment_phone' => null, 'treatment_desktop' => 'frost',
                'text_phone' => 'dark', 'text_desktop' => null,
                'focus' => 'left', 'bg' => '#fef', 'ic' => null, 'title_phone' => 30,
            ],
        ])->assertOk();

        expect($c->fresh()->header_style)->toBe([
            'align_phone' => 'end', 'align_desktop' => 'center', 'treatment_desktop' => 'frost',
            'box_phone' => 'lilac', 'box_desktop' => 'lilac', 'text_phone' => 'dark', 'focus' => 'left',
            'bg' => '#FFEEFF', 'title_phone' => 30,
        ]);

        foreach (['align_desktop' => 'left', 'box_phone' => 'neon', 'text_desktop' => 'red', 'focus' => 'top', 'bg' => 'red', 'ic' => '#12345'] as $key => $bad) {
            $this->putJson('/admin-api/categories/'.$c->id, ['name' => 'Sunscreens', 'slug' => 'qc-sun', 'header_style' => [$key => $bad]])
                ->assertStatus(422)->assertJsonValidationErrors('header_style.'.$key);
        }
    });
});

it('draws the designs as a radio group: role, checked state, one tab stop, arrows, Home/End, Enter and Space', function () {
    $kit = qcKit();

    expect($kit)->toContain('role="radiogroup"')
        ->and($kit)->toContain('class="thk-tile" role="radio" tabindex="\' + (on ? \'0\' : \'-1\') + \'" aria-checked="\' + (on ? \'true\' : \'false\') + \'"')
        ->and($kit)->toContain('aria-labelledby="\' + esc(id) + \'"');

    foreach (["'ArrowRight'", "'ArrowDown'", "'ArrowLeft'", "'ArrowUp'", "'Home'", "'End'", "'Enter'", "' '"] as $key) {
        expect($kit)->toContain('e.key === '.$key);
    }

    // The kit is included once, by the editor, which the console includes
    // BEFORE the Site layout screen -- so window.kbbTH exists for both.
    $tree = (string) file_get_contents(resource_path('views/admin/partials/category-tree-screen.blade.php'));
    $layout = (string) file_get_contents(resource_path('views/admin/partials/site-layout-screen.blade.php'));
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    expect(substr_count($tree, "@include('admin.partials.title-header-kit')"))->toBe(1)
        ->and($layout)->not->toContain("@include('admin.partials.title-header-kit')")
        ->and(strpos($app, "@include('admin.partials.category-tree-screen')"))
        ->toBeLessThan(strpos($app, "@include('admin.partials.site-layout-screen')"));

    $html = view('admin.partials.title-header-kit')->render();
    expect($html)->toContain('window.kbbTH = {')
        ->and($html)->toContain('kbb-title-header-')
        ->and($html)->not->toContain('@verbatim')
        ->and($html)->not->toContain('@once');
});

it('names the sheet\'s letters, numbers and names, and agrees with the server about every list and default', function () {
    $kit = qcKit();

    foreach (['blush' => "['A', 'Blush icons']", 'cream' => "['B', 'Cream icons']", 'mint' => "['C', 'Mint icons']",
        'lilac' => "['D', 'Lilac icons']", 'plain' => "['E', 'Plain soft colour']", 'custom' => "['F', 'My own colours']",
        'shadow' => "['1', 'Soft shadow']", 'fade' => "['2', 'Dark fade on the text side']", 'frost' => "['3', 'Frosted panel']",
        'label' => "['4', 'Solid label']", 'none' => "['5', 'None']"] as $key => $name) {
        expect($kit)->toContain($key.': '.$name);
    }

    expect($kit)->toContain("var PV_TEXTS = ['".implode("', '", TitleHeader::TEXTS)."'];")
        ->and($kit)->toContain("var PV_FOCUSES = ['".implode("', '", TitleHeader::FOCUSES)."'];")
        ->and($kit)->toContain("var PV_VALIGNS = ['".implode("', '", TitleHeader::VALIGNS)."'];")
        // The preview shows his generic line for a category with no description.
        ->and($kit)->toContain("split('{category}').join(title)");

    foreach (SiteLayout::BOX_PRESETS as $box => [$bg, $ic]) {
        expect($kit)->toContain($box.": ['".$bg."', '".$ic."']");
    }

    foreach (TitleHeader::TWEAK_VARS as $var => [$key, $as]) {
        expect($kit)->toContain("['".$var."', '".$key."', '".$as."']");
    }
    foreach (TitleHeader::TWEAK_COLOURS as $var => $key) {
        expect($kit)->toContain("['".$var."', '".$key."']");
    }

    // The kit's stand-in defaults are the schema's.
    preg_match('/var DEFAULTS = \{(.*?)\};/s', $kit, $m);
    preg_match_all("/(cat_header\w*): ('[^']*'|-?\d+|true|false)/", $m[1], $pairs, PREG_SET_ORDER);
    expect(count($pairs))->toBeGreaterThan(50);
    foreach ($pairs as [, $key, $raw]) {
        $value = match (true) {
            $raw === 'true' => true,
            $raw === 'false' => false,
            str_starts_with($raw, "'") => trim($raw, "'"),
            default => (int) $raw,
        };
        expect(SiteLayout::SCHEMA[$key][2])->toBe($value, $key);
    }
});

it('makes uploading his own banner obvious, says what size to make it, and how a phone crops it', function () {
    $editor = (string) file_get_contents(resource_path('views/admin/partials/category-tree-screen.blade.php'));

    // The banner comes first among the panel's controls, after the live preview.
    $panel = substr($editor, (int) strpos($editor, "return '<details class=\"ct-hdr\" id=\"ct-hdr\" open>'"));
    expect(strpos($panel, 'data-ct-hdrlive'))->toBeLessThan(strpos($panel, 'Header picture (banner)'))
        ->and(strpos($panel, 'Header picture (banner)'))->toBeLessThan(strpos($panel, 'id="ct-hdrtitle"'));

    expect($editor)->toContain('>Upload banner</button>')
        // Through the shared Media Library, never a picker or an endpoint of its own.
        ->and($editor)->toContain("upload: true,\n        pickUploaded: true,")
        ->and($editor)->not->toContain('/media/upload')
        ->and($editor)->not->toContain('type="file"')
        ->and($editor)->toContain('<b>Make it 2400 × 600 px</b> (4 : 1)')
        ->and($editor)->toContain('1236 × 300 on a 1280px laptop')
        ->and($editor)->toContain('346 × 190')
        ->and($editor)->toContain('middle 45% of its width')
        ->and($editor)->toContain("label: 'Phone crop");

    // The design's own reset is one the reset guard asks about first.
    $guard = (string) file_get_contents(resource_path('views/admin/partials/reset-guard.blade.php'));
    preg_match('#var RESET_LABEL = (/.+?/i);#', $guard, $g);
    expect(preg_match($g[1].'u', 'Back to defaults for B · Cream icons'))->toBe(1)
        ->and((string) file_get_contents(resource_path('views/admin/partials/site-layout-screen.blade.php')))
        ->toContain("'\">Back to defaults for ' + esc(n[0] + ' · ' + n[1]) + '</button>");
});
