<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\BannerCard;
use App\Models\BannerSet;
use App\Services\Banners;
use App\Services\SettingsService;
use App\Support\ImageVariants;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\BannersAdminRoutes;

/**
 * Lane RC, item 2 -- the slider's height control, and pictures that are never
 * cut.
 *
 * The owner: "for slider images, there should be height control of the
 * overall banner. and image should adjust auto with the screen without
 * cutting etc."
 *
 * WHAT IT LOOKED LIKE ON THE SHOP, measured in Chromium before this lane: the
 * frame was a fixed preset (1920 : 550 / 500 : 600) and every picture was
 * `object-fit: cover`. A slide with no phone picture drew its 1920 x 550
 * desktop art in the 390 x 468 phone frame -- the middle 458 x 550 of it, the
 * words and both ends gone -- and art only roughly the preset's shape lost its
 * edges at every width. There was no height control at all.
 *
 * WHAT SHIPS (rule 1, reversed: he asked, so it ships on): the frame is `auto`
 * -- the first picture's own shape -- every picture is fitted whole
 * (`contain`), and a height CAP per device stops the banner growing past a
 * number without cutting anything. `cover` and the presets remain one select
 * away.
 */

/* ───────────────────────────────── helpers ──────────────────────────────── */

function rchSet(array $setAttributes = [], array $cards = []): BannerSet
{
    BannerCard::query()->delete();
    BannerSet::query()->delete();

    $set = BannerSet::create($setAttributes + [
        'name' => 'Slider', 'slug' => 'rch-'.uniqid(), 'status' => 'publish', 'position' => 0,
        'kind' => 'slider',
    ]);

    foreach ($cards ?: [[], []] as $i => $over) {
        BannerCard::create($over + [
            'banner_set_id' => $set->id,
            'image' => 'uploads/banners/rch-'.($i + 1).'.jpg',
            'image_w' => 1920, 'image_h' => 550,
            'alt' => 'Slide '.($i + 1), 'button_url' => '/shop/',
            'position' => $i + 1, 'status' => 'publish',
        ]);
    }

    return $set;
}

/** The slider's own root element, as the HOMEPAGE draws it. */
function rchRoot(BannerSet $set): string
{
    $settings = app(SettingsService::class);
    $settings->setModule('cards_banner', true);
    $settings->setModuleSetting(Banners::MODULE, 'set', (string) $set->id);

    $html = (string) test()->get('/')->assertOk()->getContent();

    return (string) (preg_match('#<div class="kbbs [^"]*"\s+id="kbbs-\d+"\s+style="[^"]*"#', $html, $m) ? $m[0] : '');
}

function rchOwner(): void
{
    test()->actingAs(AdminUser::create([
        'name' => 'RC owner', 'email' => 'rch-'.uniqid().'@example.com',
        'password' => Hash::make('secret-secret'), 'role' => 'owner',
    ]), 'admin');
}

/* ═══════════════════════════ the shipped state ════════════════════════════ */

it('ships whole pictures at their own shape, with no height cap', function () {
    /*
     * MUTATION, run: put `'slider_fit' => 'cover'` back in $attributes (or
     * delete the key, so the column's null is read) and `is-whole` is gone
     * from the root; put `slider_ratio` back to '1920/550' and a 1920 x 600
     * picture is drawn in a 1920 : 550 frame, i.e. cut.
     */
    $fresh = new BannerSet;

    expect($fresh->slider_fit)->toBe('contain')
        ->and($fresh->slider_ratio)->toBe('auto')
        ->and($fresh->slider_ratio_m)->toBe('auto')
        ->and($fresh->sliderHeight())->toBe(0)
        ->and($fresh->sliderHeight(true))->toBe(0);

    // "1920 x 550-ish" art: a 1920 x 600 picture is drawn in a 1920 : 600
    // frame, its own, and a 520 x 640 phone picture in a 520 : 640 one.
    $root = rchRoot(rchSet([], [
        ['image_w' => 1920, 'image_h' => 600, 'image_m' => 'uploads/banners/rch-m.jpg', 'image_m_w' => 520, 'image_m_h' => 640],
        [],
    ]));

    expect($root)->toContain(' is-whole')
        ->and($root)->toContain('--kbbs-ar:1920 / 600')
        ->and($root)->toContain('--kbbs-arm:520 / 640')
        ->and($root)->not->toContain('--kbbs-hd')
        ->and($root)->not->toContain('--kbbs-hm');
});

it('fits every picture whole under contain, and only crops under cover', function () {
    /*
     * The stylesheet half of "without cutting": `is-whole` turns the picture to
     * `contain` and takes the frame's placeholder tint away, so any spare room
     * is the set's own background.
     *
     * MUTATION, run: delete `.kbbs.is-whole .kbbs-a img{object-fit:contain}`
     * and the CSS expectation is red; print ' is-whole' unconditionally and
     * the cover case is.
     */
    $html = (string) view('partials.home.slider-banner', ['set' => new BannerSet(['kind' => 'slider']), 'cards' => [new BannerCard([
        'image' => 'uploads/banners/x.jpg', 'image_w' => 1920, 'image_h' => 550,
    ])]])->render();

    expect($html)->toContain('.kbbs.is-whole .kbbs-a img{object-fit:contain}')
        ->and($html)->toContain('.kbbs.is-whole .kbbs-vp{background:transparent}');

    expect(rchRoot(rchSet(['slider_fit' => 'cover'])))->not->toContain('is-whole');
});

it('does not hand the phone a server-made crop unless the set crops', function () {
    /*
     * THE DEFECT, on the shop: a slide with no phone picture was given a crop
     * of its desktop art -- the middle quarter, 458 x 550 of 1920 x 550 -- by
     * the server, on every phone. Under `contain` or an `auto` phone frame that
     * crop is exactly the cutting he asked to be rid of.
     *
     * MUTATION, run: make `$crop` ignore `$bsCrops` in the partial and the
     * first expectation is red (the crop file exists, so it is offered).
     */
    $rel = 'uploads/banners/rch-crop-'.uniqid().'.jpg';
    @mkdir(dirname(public_path($rel)), 0775, true);
    $im = imagecreatetruecolor(1920, 550);
    imagejpeg($im, public_path($rel), 70);
    imagedestroy($im);
    ImageVariants::generate('/'.$rel);
    ImageVariants::generateCrop('/'.$rel, '500x600', 500 / 600);

    try {
        $cards = [['image' => $rel]];

        rchRoot(rchSet([], $cards));
        $page = (string) test()->get('/')->getContent();

        expect($page)->not->toContain('/img-cache/c500x600/')
            ->and(substr_count($page, '<source media="(max-width: 767.98px)"'))->toBe(0);

        // `cover` at a fixed phone shape still crops, exactly as before.
        rchRoot(rchSet(['slider_fit' => 'cover', 'slider_ratio_m' => '500/600'], $cards));

        expect((string) test()->get('/')->getContent())->toContain('/img-cache/c500x600/');

        expect((new BannerSet)->sliderCropsPhone())->toBeFalse()
            ->and((new BannerSet(['slider_fit' => 'cover', 'slider_ratio_m' => '500/600']))->sliderCropsPhone())->toBeTrue()
            ->and((new BannerSet(['slider_fit' => 'cover', 'slider_ratio_m' => 'auto']))->sliderCropsPhone())->toBeFalse()
            ->and((new BannerSet(['slider_fit' => 'contain', 'slider_ratio_m' => '500/600']))->sliderCropsPhone())->toBeFalse();
    } finally {
        @unlink(public_path($rel));
        ImageVariants::forget('/'.$rel);
    }
});

/* ═════════════════════════ the height control ═════════════════════════════ */

it('carries a stored height from the column to the page, per device', function () {
    /*
     * THE WHOLE PATH, because a control that does not reach the page is the
     * failure this repository keeps finding: stored column -> forHome()'s
     * explicit column list -> sliderVariables() -> the root's style -> the
     * stylesheet's `max-height`.
     *
     * MUTATION, run: drop 'slider_h', 'slider_h_m' from Banners::load()'s
     * $setColumns and both `--kbbs-h*` expectations are red while the admin
     * (which reads the model) still shows the values.
     */
    $root = rchRoot(rchSet(['slider_h' => 320, 'slider_h_m' => 260]));

    expect($root)->toContain('--kbbs-hd:320px')
        ->and($root)->toContain('--kbbs-hm:260px');

    $css = (string) view('partials.home.slider-banner', ['set' => new BannerSet, 'cards' => [new BannerCard(['image' => 'x.jpg'])]])->render();

    expect($css)->toContain('.kbbs-vp{width:100%;aspect-ratio:var(--kbbs-arm,4 / 3);max-height:var(--kbbs-hm,none);')
        ->and($css)->toContain('@media (min-width:768px){.kbbs-vp{aspect-ratio:var(--kbbs-ar,16 / 9);max-height:var(--kbbs-hd,none)}}');
});

it('clamps a height on the way in and again on the way out', function () {
    /*
     * Rule 5: any CSS value is a constant or a clamped int. The controller
     * clamps to LIMITS (and lifts 1..79 to 80); sliderHeight() does it again
     * for a row edited by hand.
     *
     * MUTATION, run: print `$set->slider_h` raw in sliderVariables() and the
     * 99999 row reaches the page as `99999px`.
     */
    BannersAdminRoutes::wire(app());
    rchOwner();

    $set = rchSet();

    test()->putJson('/admin-api/banners/sets/'.$set->id, ['slider_h' => 99999, 'slider_h_m' => 5])->assertOk();

    $fresh = $set->fresh();

    expect($fresh->slider_h)->toBe(BannerSet::LIMITS['slider_h'][1])
        ->and($fresh->slider_h_m)->toBe(80);

    test()->putJson('/admin-api/banners/sets/'.$set->id, ['slider_h' => '300px; }'])->assertStatus(422);

    DB::table('banner_sets')->where('id', $set->id)->update(['slider_h' => 65000, 'slider_h_m' => 3]);

    $root = rchRoot($set);

    expect($root)->toContain('--kbbs-hd:1000px')
        ->and($root)->toContain('--kbbs-hm:80px');
});

it('stores one of its own fits, and draws contain for anything else', function () {
    /*
     * MUTATION, run: replace the slider_fit rule with ['string'] and `stretch`
     * is stored (first expectation red); make sliderFit() return the raw
     * column and the hand-edited row draws without `is-whole`.
     */
    BannersAdminRoutes::wire(app());
    rchOwner();

    $set = rchSet();

    test()->putJson('/admin-api/banners/sets/'.$set->id, ['slider_fit' => 'stretch'])->assertStatus(422);
    test()->putJson('/admin-api/banners/sets/'.$set->id, ['slider_fit' => 'cover'])->assertOk();

    expect($set->fresh()->slider_fit)->toBe('cover')
        ->and(test()->getJson('/admin-api/banners/sets/'.$set->id)->json('set.slider_fit'))->toBe('cover')
        ->and(test()->getJson('/admin-api/banners')->json('enums.slider_fits'))->toHaveKeys(['contain', 'cover'])
        ->and(test()->getJson('/admin-api/banners')->json('enums.slider_ratios'))->toHaveKey('auto');

    DB::table('banner_sets')->where('id', $set->id)->update(['slider_fit' => '} body{x:y}']);

    expect(rchRoot($set))->toContain(' is-whole');
});

it('sends the fit and both heights from the screen on Save and in the preview', function () {
    /*
     * The admin screen buffers edits and sends exactly SET_KEYS. A control
     * whose key is missing there is drawn, moved, previewed -- and lost on
     * Save. MUTATION, run: take 'slider_h' out of SET_KEYS and this is red.
     */
    $screen = (string) file_get_contents(resource_path('views/admin/partials/banners-screen.blade.php'));

    expect($screen)->toContain("'slider_fit', 'slider_h', 'slider_h_m'];")
        ->and($screen)->toContain("heightField('slider_h', 'Banner height on a computer'")
        ->and($screen)->toContain("heightField('slider_h_m', 'Banner height on a phone'")
        ->and($screen)->toContain("pick('slider_fit', 'How the pictures fit'")
        ->and($screen)->toContain('<div class="bns-sec">Size &amp; fit</div>');

    // And the buffered preview honours them, through the same fillSet().
    BannersAdminRoutes::wire(app());
    rchOwner();

    $set = rchSet();

    $html = (string) test()->postJson('/admin-api/banners/sets/'.$set->id.'/preview', [
        'set' => ['slider_h' => 300, 'slider_fit' => 'cover'],
    ])->assertOk()->json('html');

    expect($html)->toContain('--kbbs-hd:300px')->and($html)->not->toContain(' is-whole');
});

/* ═════════════════════════════ the migration ══════════════════════════════ */

it('moves the shipped shapes to auto and every set to whole pictures, and leaves a chosen shape alone', function () {
    /*
     * MUTATION, run: drop the `where('slider_ratio', '1920/550')` from the
     * migration and change it to a blanket update -- the 21/9 row is red.
     */
    $migration = require database_path('migrations/2027_07_18_000300_banner_single_image_and_slider_height.php');

    BannerCard::query()->delete();
    BannerSet::query()->delete();

    $ids = [];

    foreach ([['1920/550', '500/600'], ['21/9', '1/1']] as $i => [$d, $m]) {
        $ids[] = DB::table('banner_sets')->insertGetId([
            'name' => 'M'.$i, 'slug' => 'rcm-'.$i.'-'.uniqid(), 'status' => 'publish', 'position' => $i,
            'kind' => 'slider', 'slider_ratio' => $d, 'slider_ratio_m' => $m, 'slider_fit' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    ob_start();
    $migration->up();
    ob_end_clean();

    $rows = DB::table('banner_sets')->whereIn('id', $ids)->orderBy('id')->get();

    expect([$rows[0]->slider_ratio, $rows[0]->slider_ratio_m, $rows[0]->slider_fit])->toBe(['auto', 'auto', 'contain'])
        ->and([$rows[1]->slider_ratio, $rows[1]->slider_ratio_m, $rows[1]->slider_fit])->toBe(['21/9', '1/1', 'contain']);
});
