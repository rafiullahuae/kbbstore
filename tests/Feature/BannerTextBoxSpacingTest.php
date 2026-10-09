<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Support\BannerTextBox;
use Illuminate\Support\Facades\Hash;

/*
 * Lane HB4 -- the text box's spacing.
 *
 * The owner: "for homepage main banners, as we have two designs finalized
 * yesterday. the padding inside box between each element, and the spacing
 * downside the box etc. give me these controls."
 *
 * Measured in Chromium (tools/hb4-rects.cjs): at the defaults, A and D at 390
 * and 1280, English and Arabic, every part of the box sits where 2.60.445 put
 * it and a screenshot of the banner is byte-identical to the old one.
 */
require_once __DIR__.'/../Support/BannerTextBoxHelpers.php';

function hb4Css(): string
{
    return (string) file_get_contents(resource_path('views/partials/home/slider-text-box-css.blade.php'));
}

function hb4Admin(): void
{
    test()->actingAs(AdminUser::create(['name' => 'HB4', 'email' => 'hb4-'.uniqid().'@example.com',
        'password' => Hash::make('secret-secret'), 'role' => 'owner']), 'admin');
}

it('prints nothing for an untouched set, and the stylesheet\'s fallbacks ARE the old values', function () {
    /*
     * Pixel-identical until he moves a slider: no --hb-sp-* is printed at a
     * default, so every var() falls back -- and each fallback must be the
     * number 2.60.445 drew. MUTATION: change `var(--hb-sp-pt-m,18px)` to 20px,
     * or a SPACING default, and this is red.
     */
    $cfg = BannerTextBox::normalize([]);

    expect(BannerTextBox::cssVariables($cfg))->not->toContain('--hb-sp-')
        ->and(BannerTextBox::rootClasses($cfg))->not->toContain('hb-sb-')->not->toContain('hb-st-');

    $was = ['d' => ['pt' => 22, 'pb' => 24, 'px' => 28, 'geh' => 10, 'ght' => 10, 'gtb' => 14, 'su' => 24, 'so' => 28],
        'm' => ['pt' => 18, 'pb' => 20, 'px' => 18, 'geh' => 8, 'ght' => 8, 'gtb' => 11.2, 'su' => 28, 'so' => 8]];

    foreach ($was as $dev => $values) {
        foreach ($values as $k => $v) {
            expect(hb4Css())->toContain('--hb-'.$k.':var(--hb-sp-'.$k.'-'.$dev.','.$v.'px)')
                ->and((float) BannerTextBox::SPACING[$k][$dev === 'd' ? 2 : 3][1])->toBe((float) $v);
        }

        expect(BannerTextBox::SPACING['sb'][$dev === 'd' ? 2 : 3][1])->toBe(0)
            ->and(BannerTextBox::SPACING['st'][$dev === 'd' ? 2 : 3][1])->toBe(0);
    }
});

it('moves only its own gap or padding', function (string $k, string $rule) {
    /*
     * Each control is one variable and each variable is read by one rule.
     * MUTATION: write `.hb-eb + .hb-h{margin-top:var(--hb-ght)}` and the geh
     * and ght rows are red.
     */
    foreach (['d', 'm'] as $dev) {
        $vars = BannerTextBox::cssVariables(['sp_'.$k.'_'.$dev => 33]);

        expect(substr_count($vars, '--hb-sp-'))->toBe(1)
            ->and($vars)->toContain('--hb-sp-'.$k.'-'.$dev.':33px');
    }

    expect(hb4Css())->toContain($rule)
        // Read nowhere else: the padding by the A and the D rule, the sticker's
        // two offsets also by the room kept for it (--hb-o, D's end inset).
        ->and(substr_count(hb4Css(), 'var(--hb-'.$k.')'))->toBe(['geh' => 1, 'ght' => 1, 'gtb' => 1][$k] ?? 2);
})->with([
    'eyebrow to heading' => ['geh', '.hb-eb + .hb-h{margin-top:var(--hb-geh)}'],
    'heading to short text' => ['ght', ':is(.hb-eb,.hb-h) + .hb-t{margin-top:var(--hb-ght)}'],
    'before the button' => ['gtb', '.kbbs :is(.hb-eb,.hb-h,.hb-t) + .hb-btn{margin-top:var(--hb-gtb)}'],
    'padding top' => ['pt', 'padding:var(--hb-pt) var(--hb-px) var(--hb-pb)'],
    'padding bottom' => ['pb', 'padding:var(--hb-pt) var(--hb-px) var(--hb-pb)'],
    'padding sides' => ['px', 'padding:var(--hb-pt) var(--hb-px) var(--hb-pb)'],
    'sticker up' => ['su', 'top:calc(var(--hb-su) * -1)'],
    'sticker out' => ['so', 'inset-inline-end:calc(var(--hb-so) * -1)'],
]);

it('keeps the old button margin when the button is the only thing in the box', function () {
    // The box's own gap is 0 now; a lone button still gets the .4-of-a-gap it had.
    expect(hb4Css())->toContain('margin-top:calc(var(--hb-g) * .4)')
        ->and(hb4Css())->toMatch('/align-items:flex-start;gap:0;pointer-events:none;/');
});

it('moves the box from the bottom or top edge, but never into the slider bars\' strip', function () {
    /*
     * 0 is Auto. A number is the bottom (or top) inset, floored at --hb-room,
     * the strip the inset/corner bars use. MUTATION: drop the max() with
     * --hb-room and a 0-ish space puts the button under the bars -- red.
     */
    $n = BannerTextBox::normalize(['sp_sb_d' => 120, 'sp_st_m' => 30]);

    expect(BannerTextBox::rootClasses($n))->toContain('hb-sb-d')->toContain('hb-st-m')
        ->not->toContain('hb-sb-m')->not->toContain('hb-st-d')
        ->and(hb4Css())->toContain('.kbbs.kbbs.has-hb.hb-sb-d{--hb-bot:max(var(--hb-sp-sb-d),var(--hb-room,0px))}')
        ->and(hb4Css())->toContain('.kbbs.kbbs.has-hb.hb-sb-m{--hb-bot:max(var(--hb-sp-sb-m),var(--hb-room,0px))}')
        // ▲ Lane QK: --hb-room is the bars' own 20px tap strip, not the 66px Auto inset.
        ->and(hb4Css())->toContain('.kbbs.has-hb.is-bars.is-inset{--hb-bot:66px;--hb-bot0:66px;--hb-room:var(--kbbs-hit,20px)}')
        // D's centre stays anchored to the AUTO bottom.
        ->and(hb4Css())->toContain('.hb-d.hb-va-d .hb-pos{inset-block-start:calc(var(--hb-bot0) + 24px)}');
});

it('clamps every spacing value and falls back on junk', function () {
    /*
     * MUTATION: drop the max()/min() in the spacing loop of normalize() and
     * 999 survives -- red.
     */
    $n = BannerTextBox::normalize(['sp_pt_d' => 999, 'sp_pb_m' => -4, 'sp_geh_m' => 11.13, 'sp_gtb_d' => 'x;}',
        'sp_sb_m' => 5000, 'sp_su_d' => '12']);

    expect($n['sp_pt_d'])->toBe(48)->and($n['sp_pb_m'])->toBe(0)->and($n['sp_geh_m'])->toBe(11.2)
        ->and($n['sp_gtb_d'])->toBe(14)->and($n['sp_sb_m'])->toBe(200)->and($n['sp_su_d'])->toBe(12);

    expect(BannerTextBox::cssVariables($n))->toMatch('/^(--hb-[a-z0-9-]+:-?[0-9.]+(px|%)?;?)+$/');

    hb4Admin();
    $set = hbSet([], 1);

    test()->putJson('/admin-api/banners/sets/'.$set->id, ['tb_sp_geh_m' => 'wide'])->assertStatus(422);
    test()->putJson('/admin-api/banners/sets/'.$set->id, ['tb_sp_geh_m' => 999, 'tb_sp_sb_d' => 40])
        ->assertOk()->assertJsonPath('set.tb_sp_geh_m', 40)->assertJsonPath('set.tb_sp_sb_d', 40);
});

it('draws the admin\'s live preview with the very same variables and stylesheet as the shop', function () {
    /*
     * The preview is the shop's own partial fed the unsaved draft; after Save
     * the shop prints the same slider. MUTATION: make previewDraft() skip
     * fillSet() for tb_* keys and the preview loses --hb-sp-geh-d -- red.
     */
    hb4Admin();
    $set = hbSet([], 1, [1 => hbWords()]);
    $draft = ['tb_sp_geh_d' => 20, 'tb_sp_pt_m' => 30, 'tb_sp_sb_d' => 90, 'tb_style' => 'd'];

    $preview = (string) test()->postJson('/admin-api/banners/sets/'.$set->id.'/preview', ['set' => $draft])->assertOk()->json('html');

    test()->putJson('/admin-api/banners/sets/'.$set->id.'/all', ['set' => $draft])->assertOk();
    $shop = hbRender($set->fresh());

    $root = fn (string $h): array => preg_match('/<div class="(kbbs [^"]*)"\s+[^>]*?style="([^"]*)"/s', $h, $m) ? [$m[1], $m[2]] : [];
    $css = fn (string $h): string => preg_match('/<style>(?:(?!<\/style>).)*\.kbbs\.has-hb \.kbbs-s\{.*?<\/style>/s', $h, $m) ? $m[0] : '';

    expect($root($preview))->not->toBe([])
        ->and($root($preview))->toBe($root($shop))
        ->and($root($preview)[1])->toContain('--hb-sp-geh-d:20px')->toContain('--hb-sp-pt-m:30px')->toContain('--hb-sp-sb-d:90px')
        ->and($css($preview))->not->toBe('')
        ->and($css($preview))->toBe($css($shop));
});

it('offers every spacing control in the console and a reset', function () {
    $screen = (string) file_get_contents(resource_path('views/admin/partials/banners-screen.blade.php'));

    foreach (array_keys(BannerTextBox::SPACING) as $k) {
        expect($screen)->toContain("'tb_sp_".$k."_d'")->toContain("'tb_sp_".$k."_m'");
    }

    expect($screen)->toContain('data-bns-tbspreset>Reset spacing to default</button>')
        ->and($screen)->toContain("if (k.indexOf('sp_') === 0) draft.set['tb_' + k] = D[k];");
});
