<?php

declare(strict_types=1);

use App\Models\BannerCard;
use App\Models\BannerSet;
use App\Services\Banners;
use App\Services\SettingsService;

/**
 * Lane RC, item 3 -- no space between the header and the banner.
 *
 * The owner sent the live homepage at ~1280px with a visible strip between
 * the header's bottom edge and the top of the pink "Experience the Skincare
 * Revolution" banner: "also remove any space between header and banner."
 *
 * WHAT THE DEFECT WAS, measured in Chromium on the shipped page before the fix:
 *
 *   width   header bottom   banner picture top   gap
 *   390     y = 127         y = 135               8px
 *   1280    y = 93.5        y = 101.5             8px
 *
 * Every box from <main> down to the picture had zero padding and margin
 * except the banner's <section>, which store/home.blade.php printed with an
 * inline `style="padding-top:8px"`. That 8px of page background WAS the
 * strip. After: 0px at both widths (docs/rc-shots/after-*-home-*.png).
 *
 * And with a background chosen on the set ("Behind the banner"), the band
 * around the picture added an 18px top padding of its own -- the same strip
 * in the set's colour -- so that top padding goes too.
 */

function rcgHome(string $kind, array $set = []): string
{
    BannerCard::query()->delete();
    BannerSet::query()->delete();

    $model = BannerSet::create($set + [
        'name' => 'Flush', 'slug' => 'rcg-'.uniqid(), 'status' => 'publish', 'position' => 0, 'kind' => $kind,
    ]);

    BannerCard::create([
        'banner_set_id' => $model->id, 'image' => 'uploads/banners/rcg.jpg',
        'image_w' => 1920, 'image_h' => 550, 'alt' => 'Banner', 'position' => 1, 'status' => 'publish',
    ]);

    $settings = app(SettingsService::class);
    $settings->setModule('cards_banner', true);
    $settings->setModuleSetting(Banners::MODULE, 'set', (string) $model->id);

    return (string) test()->get('/')->assertOk()->getContent();
}

/** The opening tag of the <section> the banner element sits in. */
function rcgSection(string $html): string
{
    $at = false;

    foreach (['<div class="kbbs ', '<div class="kbbi', '<div class="kbbn'] as $needle) {
        $at = strpos($html, $needle);

        if ($at !== false) {
            break;
        }
    }

    if ($at === false) {
        return '';
    }

    $open = strrpos(substr($html, 0, $at), '<section ');

    return $open === false ? '' : substr($html, $open, (int) strpos($html, '>', $open) + 1 - $open);
}

it('puts a picture banner flush under the header, with no section padding above it', function () {
    /*
     * MUTATION, run: put `'slider' => 'padding-top:8px'` back in
     * BannerSet::SECTION_STYLES (or the literal back in home.blade.php) and the
     * slider line is red -- which is the 8px strip on the owner's screenshot.
     */
    foreach (['slider', 'single'] as $kind) {
        $section = rcgSection(rcgHome($kind));

        expect($section)->not->toBe('', "the {$kind} banner's section was not found on the homepage")
            ->and($section)->toContain('style="padding-top:0"')
            ->and($section)->not->toContain('padding-top:8px');
    }
});

it('leaves the cards row exactly where it was', function () {
    /*
     * Rule 1: the owner asked about the BANNER. A shop drawing the cards row
     * keeps its 8px, byte for byte. MUTATION, run: make SECTION_STYLES['cards']
     * 'padding-top:0' and this is red.
     */
    expect(rcgSection(rcgHome('cards')))->toContain('style="padding-top:8px"');
});

it('drops the background band\'s top padding, and only its top', function () {
    /*
     * With "Behind the banner" set to a colour, `.has-bg` padded the band 18px
     * above the picture: the same strip, painted. MUTATION, run: put either
     * partial's `padding-block:0 18px` back to `padding-block:18px` and its
     * expectation is red.
     */
    $slider = rcgHome('slider', ['bg_mode' => 'color', 'bg_color' => '#fbe7ee']);

    expect($slider)->toContain('class="kbbs is-inset')
        ->and($slider)->toContain(' has-bg"')
        ->and($slider)->toContain('padding-inline:var(--kbbs-gut);padding-block:0 18px}')
        ->and($slider)->not->toContain('padding-block:18px}');

    $single = rcgHome('single', ['bg_mode' => 'color', 'bg_color' => '#fbe7ee']);

    expect($single)->toContain('class="kbbi has-bg"')
        ->and($single)->toContain('padding-inline:var(--kbbi-gut);padding-block:0 18px}');
});
