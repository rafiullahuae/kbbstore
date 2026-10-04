<?php

declare(strict_types=1);

/**
 * BUTTONS ON TWO HOMEPAGE SECTIONS THAT HAD NONE.                   (2.60.385)
 *
 * THE OWNER, of the #KBEAUTYBLISS Spotted grid on a laptop: "i need the button
 * also on this section", arrowing the space right of the heading; and of
 * K-Beauty Under AED 54: "on this section too on homepage".
 *
 * WHAT THE SHOP LOOKED LIKE. Spotted's button lived only in the carousel
 * layout, and the shop ships the grid, so it had no way to the Spotted page but
 * the pictures. Under AED 54's button text defaulted to empty, which means "no
 * button", so the section ended at its last card.
 *
 * NOW, both shipped ON (he asked), both with the control to take it back:
 *   · Appearance → #KBeautyBliss Spotted → Button: "Button to the Spotted page"
 *     and "Button place · laptop" (right of the heading / under the pictures).
 *   · Appearance → Homepage content → Under AED 54: "Button text" (empty: no
 *     button) and "Button link".
 * Both keep the heading CENTRED with the button out to the right, the Big
 * savings bundles way; on a phone the button sits under the section.
 *
 * MUTATIONS, each run and each red here:
 *   · the grid's $sptBtn dropped from spotted.blade.php   → "Spotted grid"
 *   · home_u54_btn default back to ''                     → "Under AED 54"
 *   · 'center' => false for under54                      → "Under AED 54"
 *   · the migration's trim(...) === '' guard removed      → "keeps his own text"
 */

use App\Services\SettingsService;
use App\Services\SpottedSettings;
use App\Support\HomeSections;
use Illuminate\Support\Facades\DB;

function hsbSpotted(): string
{
    SpottedSettings::flush();
    SettingsService::forgetMemo();

    return view('partials.home.spotted')->render();
}

it('puts the Spotted page button on the grid, right of the heading on a laptop and under the pictures on a phone', function () {
    $html = hsbSpotted();

    expect($html)->toContain('spt-sg spt-btn-d-top')
        ->and(substr_count($html, '<a class="bndl-all spt-all" href="/kbeautybliss-spotted/">See every #KBeautyBliss look<i>'))->toBe(2)
        ->and(substr_count($html, '<span class="spt-hbtn">'))->toBe(1)
        ->and(substr_count($html, '<div class="spt-foot spt-sg-foot">'))->toBe(1);

    // "Under the pictures" on a laptop: no top class, so the CSS shows the foot.
    app(SpottedSettings::class)->save(['btn_pos_d' => 'bottom']);
    expect(hsbSpotted())->not->toContain('spt-btn-d-top')->toContain('spt-sg-foot');

    // A value the select never offered falls back to the default, not into a class.
    app(SpottedSettings::class)->save(['btn_pos_d' => '" onmouseover="x']);
    expect(hsbSpotted())->toContain('spt-sg spt-btn-d-top')->not->toContain('onmouseover');

    // Off is off: neither copy.
    app(SpottedSettings::class)->save(['btn_on' => false]);
    expect(hsbSpotted())->not->toContain('spt-all')->not->toContain('spt-btn-d-top');
});

it('gives Under AED 54 a button beside its centred heading, linking to the budget collection', function () {
    $r = HomeSections::rail([], 'under54');
    expect($r['btn'])->toBe('Shop all under AED 54')
        ->and($r['url'])->toEndWith('/everything-under-54-aed')
        ->and($r['center'])->toBeTrue();

    $head = view('partials.home.hs-head', ['hid' => 'x', 'h' => $r])->render();
    expect($head)->toContain('class="hs-head hs-split hs-split-c"')
        ->and(substr_count($head, 'class="bndl-all hs-btn hs-btn-top"'))->toBe(1);

    // Best Sellers keeps its left heading: no centred split there.
    expect(HomeSections::rail([], 'bestselling')['center'])->toBeFalse();

    // Empty text is still "no button", so the control takes it back.
    $off = HomeSections::rail(['home_u54_btn' => ''], 'under54');
    expect(view('partials.home.hs-head', ['hid' => 'x', 'h' => $off])->render())->not->toContain('hs-btn');
});

it('centres both headings with room reserved on both sides, and drops the dot', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));
    expect($css)->toContain('.kbb-home .hs-head.hs-split.hs-split-c{display:block;position:relative;text-align:center;padding-inline:220px}')
        ->and($css)->toContain('.kbb-home .spt-sg.spt-btn-d-top .spt-head{position:relative;padding-inline:220px}')
        ->and($css)->toContain('.kbb-home .spt-hbtn{display:none}')
        // The first cut named the wrapper `.spt-top`, which is ALREADY the
        // carousel card's @handle overlay (absolute, display:flex): on a phone
        // the button sat on top of the heading. One owner per class name.
        ->and(substr_count($css, '.spt-hbtn'))->toBe(2);
});

it('turns the button on for a shop that saved the old empty default, and keeps his own text', function () {
    $m = require database_path('migrations/2027_08_12_100000_home_buttons_and_centred_headings.php');
    $s = app(SettingsService::class);

    $s->set('home_u54_btn', '');
    $s->set('home_u54_url', '/my-own-page');
    ob_start();
    $m->up();
    ob_end_clean();
    SettingsService::forgetMemo();
    expect($s->get('home_u54_btn'))->toBe(HomeSections::U54_BTN)
        ->and($s->get('home_u54_url'))->toBe('/my-own-page');

    // A shop that never saved the tab gets no row at all: the default answers.
    DB::table('settings')->whereIn('key', ['home_u54_btn', 'home_u54_url'])->delete();
    ob_start();
    $m->up();
    ob_end_clean();
    expect(DB::table('settings')->where('key', 'home_u54_btn')->exists())->toBeFalse();
});
