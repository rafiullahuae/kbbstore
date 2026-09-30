<?php

declare(strict_types=1);

/**
 * THE HOMEPAGE'S PICTURE BANNER RUNS EDGE TO EDGE, WITH NO CORNERS AND NO
 * SHADOW.                                                           (Lane BG)
 *
 * The owner: *"and by default, make the main images banner full width, and
 * remove the corner radius etc."*
 *
 * ── WHAT IT LOOKED LIKE ON THE SHOP BEFORE ──────────────────────────────────
 *
 * Measured in Chromium on the real page (docs/home-width-shots/
 * before-measurements.json): the banner's frame was **1203px wide inside a
 * 1280 viewport**, **1622 inside 1920**, inset 39 and 149 pixels from the left,
 * with `border-radius: 18px` and a drop shadow. It was a rounded card sitting
 * on the page rather than the top of it. After: **1280 at 1280, 1920 at 1920,
 * left 0, radius 0px, shadow none**, and capped at 1920 — at a 2200 viewport it
 * is 1920 wide and centred.
 *
 * ── THREE PLACES MOVE THE DEFAULT, AND ONLY TOGETHER ────────────────────────
 *
 * This is the lesson `banner_ships_as_image_slider` wrote down and this file
 * asserts one release later:
 *
 *   BannerSet::$attributes          rows made from here on
 *   the migration                   rows already on the shop — `card_radius`
 *                                   and `shadow` have DATABASE defaults, so
 *                                   every existing row carries 18 and 'soft'
 *                                   in the column and the model's default is
 *                                   never consulted for them
 *   BannerSet::shadowCss()          a row holding a token the enum does not
 *                                   carry
 *
 * A change in any one alone looks applied and is not, which is exactly how
 * this project has shipped an inert default before.
 *
 * ── AND THE WIDTH NEEDS NO ROW WRITTEN, WHICH IS WORTH ASSERTING ────────────
 *
 * `homepage_sections.cards_banner.width` ships as `bleed` from
 * HomepageSections::WIDTH_DEFAULTS, and castRow() reads
 * `$row[$name] ?? $field['default']` — so a shop that has SAVED a homepage
 * arrangement gets it too, because no payload ever written carries the key.
 * The case below saves an arrangement first and then asserts the class, which
 * is the half a test written on a fresh shop would miss.
 */

use App\Models\BannerCard;
use App\Models\BannerSet;
use App\Services\Banners;
use App\Services\HomepageSections;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

function bgbMigration(): object
{
    return require database_path('migrations/2027_06_15_000000_banner_runs_edge_to_edge.php');
}

/** A published slider with one published picture, so the homepage draws it. */
function bgbSet(array $attrs = []): BannerSet
{
    $set = BannerSet::create([
        'name' => 'Homepage banner', 'slug' => 'bgb-' . uniqid(),
        'status' => 'publish', 'position' => 0,
    ] + $attrs);

    BannerCard::create([
        'banner_set_id' => $set->id, 'image' => 'uploads/banners/bgb.png',
        'alt' => 'A picture', 'heading' => '', 'body' => '',
        'button_label' => '', 'button_url' => '/shop/',
        'position' => 1, 'status' => 'publish',
    ]);

    return $set;
}

beforeEach(function () {
    SettingsService::forgetMemo();
});

it('makes a new set square and flat', function () {
    /*
     * MUTATION NOTE: take `'card_radius' => 0` and `'shadow' => 'none'` out of
     * BannerSet::$attributes and this goes red at 18 and 'soft' — the column
     * defaults, which is what a changed model default alone would have left in
     * place on every row.
     */
    $set = bgbSet();

    expect($set->card_radius)->toBe(0)
        ->and($set->shadow)->toBe('none');

    $css = Banners::sliderVariables($set, [], false);

    // The stylesheet reads `--kbbs-r` into `border-radius` and `--kbbs-sh` into
    // `box-shadow` on `.kbbs-vp`. Asserting the variables rather than the
    // column is what ties the moved default to the pixel.
    expect($css)->toContain('--kbbs-r:0px')
        ->and($css)->toContain('--kbbs-sh:none');
});

it('falls back to no shadow for a token the enum does not carry', function () {
    /*
     * The third door the BannerSet class header describes. A door that
     * disagreed with the other two would be the one place on the shop still
     * drawing the old shadow.
     *
     * MUTATION NOTE: put `?? self::SHADOWS['soft']` back in shadowCss() and
     * this goes red with the soft shadow's box-shadow value.
     */
    $set = bgbSet();
    $set->shadow = 'not-a-shadow';

    expect($set->shadowCss())->toBe('none');
});

it('moves the rows already on the shop, and only those it should', function () {
    /*
     * FOUR ROWS, AND THE MIGRATION IS RIGHT ABOUT ALL FOUR. The two scopes are
     * the whole of what it does, so they are the whole of what is tested:
     *
     *   a shipped slider   18 / soft   -> 0 / none      moved
     *   a chosen slider    24 / lift   -> 24 / lift     the owner chose it
     *   a cards set        18 / soft   -> 18 / soft     a card is not a banner
     *   a set already 0    0 / none    -> 0 / none      idempotent
     *
     * MUTATION NOTE: delete `->where('kind', 'slider')` from either update and
     * the cards row goes to 0 / none and this is red; delete
     * `->where('card_radius', 18)` and the chosen slider loses its 24.
     */
    $shipped = bgbSet(['kind' => 'slider', 'card_radius' => 18, 'shadow' => 'soft']);
    $chosen = bgbSet(['kind' => 'slider', 'card_radius' => 24, 'shadow' => 'lift']);
    $cards = bgbSet(['kind' => 'cards', 'card_radius' => 18, 'shadow' => 'soft']);
    $already = bgbSet(['kind' => 'slider', 'card_radius' => 0, 'shadow' => 'none']);

    // The rows are written under the model, because `$attributes` would other-
    // wise have made the "shipped" row already correct and the case vacuous.
    DB::table('banner_sets')->where('id', $shipped->id)->update(['card_radius' => 18, 'shadow' => 'soft']);
    DB::table('banner_sets')->where('id', $cards->id)->update(['card_radius' => 18, 'shadow' => 'soft']);

    expect(DB::table('banner_sets')->where('id', $shipped->id)->value('card_radius'))->toBe(18);

    bgbMigration()->up();

    $read = fn (BannerSet $s) => (array) DB::table('banner_sets')
        ->where('id', $s->id)->select('card_radius', 'shadow')->first();

    expect($read($shipped))->toMatchArray(['card_radius' => 0, 'shadow' => 'none'])
        ->and($read($chosen))->toMatchArray(['card_radius' => 24, 'shadow' => 'lift'])
        ->and($read($cards))->toMatchArray(['card_radius' => 18, 'shadow' => 'soft'])
        ->and($read($already))->toMatchArray(['card_radius' => 0, 'shadow' => 'none']);

    // Running it twice moves nothing further, which is what makes it safe to
    // re-apply a package.
    bgbMigration()->up();

    expect($read($shipped))->toMatchArray(['card_radius' => 0, 'shadow' => 'none'])
        ->and($read($chosen))->toMatchArray(['card_radius' => 24, 'shadow' => 'lift']);
});

it('ships the banner section at full bleed, on a shop that has saved a homepage', function () {
    $set = bgbSet();

    app(SettingsService::class)->set('module_settings', null);
    DB::table('module_toggles')->updateOrInsert(['module' => 'cards_banner'], ['enabled' => true]);
    DB::table('module_settings')->updateOrInsert(
        ['module' => 'cards_banner', 'key' => 'set'],
        ['value' => (string) $set->id],
    );

    /*
     * SAVE AN ARRANGEMENT FIRST. This is the half of the default move that
     * looks like it needs a data migration and does not: no payload ever
     * written carries a `width` key, and castRow() falls back to the field
     * default for an absent one — so the banner is bleed on a configured shop
     * as well as a fresh one.
     *
     * MUTATION NOTE: take `cards_banner` out of
     * HomepageSections::WIDTH_DEFAULTS and this goes red — the section renders
     * as `class="sec "` with no width class, which is the 1203-inside-1280
     * card the before measurements photographed.
     */
    $sections = app(HomepageSections::class);
    $payload = [];

    foreach ($sections->all() as $key => $row) {
        $payload[$key] = ['desktop' => true, 'mobile' => true, 'skin' => $row['skin'], 'order' => $row['order']];
    }

    $sections->save($payload);
    SettingsService::forgetMemo();

    expect(app(HomepageSections::class)->all()['cards_banner']['width'])->toBe('bleed');

    $html = test()->get('/')->getContent();

    /*
     * AND THE SECTION IS REALLY THERE WITH A PICTURE IN IT — the false green
     * this round was warned about. `kbbs-vp` is the slider's frame and
     * `kbbs-s` is one slide; a width class on an empty section would satisfy
     * a class-only assertion and photograph as nothing at all.
     */
    expect($html)->toContain('<section class="sec kbb-secw-bleed"')
        ->and($html)->toContain('kbbs-vp')
        ->and(substr_count($html, 'kbbs-s"'))->toBeGreaterThan(0)
        ->and(substr_count($html, 'kbb-secw-bleed'))->toBe(1);
});
