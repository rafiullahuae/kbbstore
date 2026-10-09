<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\BannerCard;
use App\Models\BannerSet;
use App\Support\BannerTextBox;
use App\Support\ImageVariants;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/*
 * Lane HB3 -- "Exactly this height".
 *
 * The owner: "for the main site banner, on mobile the overall height of the
 * banner is not working, i set 600px height, but it's showing horizontal type
 * size." His set: Whole picture, Auto shapes, phone height 600 -- and the
 * height was only ever a CAP, so next to a wide picture's own shape (390 x 112
 * at 390) it never applied.
 */
require_once __DIR__.'/../Support/BannerTextBoxHelpers.php';

/** A slider set with one REAL 1920 x 550 picture and no phone picture (his case). */
function hb3Set(array $set = [], array $card = []): BannerSet
{
    $rel = 'uploads/banners/hb3-wide.jpg';
    @mkdir(dirname(public_path($rel)), 0775, true);
    $im = imagecreatetruecolor(1920, 550);
    imagefill($im, 0, 0, imagecolorallocate($im, 240, 200, 210));
    imagejpeg($im, public_path($rel), 70);
    imagedestroy($im);

    BannerCard::query()->delete();
    BannerSet::query()->delete();

    $s = BannerSet::create($set + [
        'name' => 'Hero', 'slug' => 'hb3-'.uniqid(), 'status' => 'publish', 'position' => 0, 'kind' => 'slider',
        'slider_h_m' => 600, 'slider_hmode_m' => 'exact',
    ]);

    BannerCard::create($card + [
        'banner_set_id' => $s->id, 'image' => $rel, 'image_w' => 1920, 'image_h' => 550,
        'alt' => 'Picture', 'button_url' => '/shop/', 'position' => 1, 'status' => 'publish',
    ]);

    return $s;
}

function hb3Crops(): array
{
    return glob(public_path('img-cache/c430x600/*/uploads/banners/hb3-wide.jpg')) ?: [];
}

afterEach(function () {
    foreach (hb3Crops() as $file) {
        @unlink($file);
    }
    @unlink(public_path('uploads/banners/hb3-wide.jpg'));
});

/* ═════════════════════ Exactly is a height, Up to is a cap ════════════════ */

it('makes the phone frame exactly the height when the mode is Exactly', function () {
    /*
     * THE DEFECT: 600 on the phone drew a 390 x 112 strip, because
     * `max-height:600px` beside the picture's own wide shape never bites.
     * Exactly drops the aspect-ratio on a phone and IS the height, from CSS, so
     * nothing shifts. MUTATION: drop the @includeWhen of slider-exact-css, or
     * the ' is-hx-m' class, and this is red.
     */
    $html = hbRender(hb3Set());

    expect($html)->toMatch('/<div class="kbbs [^"]*\bis-hx-m\b/')
        ->and($html)->toContain('--kbbs-hm:600px')
        ->and($html)->toContain('@media (max-width:767.98px){.kbbs.is-hx-m .kbbs-vp{aspect-ratio:auto;height:var(--kbbs-hm)}.kbbs.is-hx-m .kbbs-a img{object-fit:cover;object-position:50% 50%}}')
        // The computer is untouched: Up to there.
        ->and($html)->not->toMatch('/<div class="kbbs [^"]*\bis-hx-d\b/')
        ->and($html)->not->toContain('.kbbs.is-hx-d');
});

it('keeps Up to a cap, and prints nothing new for it', function () {
    /*
     * Every set that is not Exactly draws what it drew in 2.60.438.
     * MUTATION: make sliderExact() ignore the mode and this is red.
     */
    $html = hbRender(hb3Set(['slider_hmode_m' => 'max', 'slider_h' => 500]));

    expect($html)->toContain('--kbbs-hm:600px')->toContain('--kbbs-hd:500px')
        ->and($html)->not->toContain('is-hx-')
        ->and($html)->not->toContain('aspect-ratio:auto;height:var(--kbbs-hm)');
});

it('treats a height of Auto as no height, whatever the mode says', function () {
    $set = hb3Set(['slider_h_m' => 0]);

    expect($set->sliderExact(true))->toBeFalse()
        ->and(hbRender($set))->not->toContain('is-hx-m');
});

/* ═════════════════════ the crop is served, never the whole desktop file ════ */

it('crops a picture without a phone picture to 430 x N and serves that crop on a phone', function () {
    /*
     * The phone must not download the whole desktop picture to cover a 600px
     * frame (in Chromium: 24,162 B before, the 7,016 B crop after). The crop is
     * written by the existing writer on Save. MUTATION: drop sliderExact(true)
     * from sliderCropsPhone() and no crop is written -- red.
     */
    $user = AdminUser::create(['name' => 'HB3', 'email' => 'hb3-'.uniqid().'@example.com', 'password' => Hash::make('secret-secret'), 'role' => 'owner']);
    test()->actingAs($user, 'admin');
    $set = hb3Set();
    $card = $set->cards()->first();

    expect($set->sliderCropsPhone())->toBeTrue()
        ->and($set->sliderRatioMobileToken())->toBe('430x600');

    test()->putJson('/admin-api/banners/sets/'.$set->id.'/all', ['cards' => [['id' => $card->id, 'alt' => 'Picture']]])->assertOk();

    expect(hb3Crops())->not->toBeEmpty();

    $html = hbRender($set->fresh());

    expect($html)->toMatch('#<source media="\(max-width: 767\.98px\)"\s+srcset="[^"]*img-cache/c430x600/#')
        ->and($html)->toMatch('#<link rel="preload" as="image" fetchpriority="high" media="\(max-width: 767\.98px\)"[^>]*img-cache/c430x600/#')
        // The browser is told the crop's real rendered width: 600 x 394/550 = 430px.
        ->and($html)->toContain('sizes="max(100vw, 430px)"');
});

/* ═════════════════════ the text box shows on an exact phone frame ═════════ */

it('shows the words on a phone when the frame is an exact height, even without a phone picture', function () {
    /*
     * Before, a slide with no phone picture hid its box on phones (the wide
     * strip had no room). An exact 600px frame has room. MUTATION: drop the
     * $phoneFrame argument from words() and the box is `no-m` again -- red.
     */
    $html = hbRender(hb3Set([], hbWords()));

    expect($html)->toContain('<div class="hb-pos"><div class="hb-box">')
        ->and($html)->not->toContain('hb-pos no-m')
        ->and(hbRender(hb3Set(['slider_hmode_m' => 'max'], hbWords())))->toContain('hb-pos no-m');
});

it('never lets a box be taller than its frame', function () {
    /*
     * The owner's preview: a phone box spilling over a short, wide frame. The
     * box's column is a size container; the box takes at most its height, the
     * heading and short text give up lines, and under 120px it is not drawn.
     * MUTATION: drop `max-block-size:100%` or the container -- red.
     */
    $css = (string) file_get_contents(resource_path('views/partials/home/slider-text-box-css.blade.php'));

    expect($css)->toContain('container:hbpos / size}')
        ->and($css)->toMatch('/\.hb-box\{position:relative;flex:none;max-block-size:100%;/')
        ->and($css)->toContain('.hb-box > .hb-h,.hb-box > .hb-t{flex-shrink:1;min-block-size:0}')
        // ▲ Lane HB4: and the heading keeps at least one line, giving way after the text.
        ->and($css)->toContain('.hb-box > .hb-h{flex-shrink:.25;min-block-size:1lh}')
        ->and($css)->toContain('@container hbpos (max-height:119px){.hb-box{display:none}}');
});

/* ═════════════════════ invalid values fall back ═══════════════════════════ */

it('stores only its own two modes and reads anything else as Up to', function () {
    /*
     * MUTATION: drop the Rule::in on slider_hmode_m and 'fixed' is stored --
     * the 422 is red.
     */
    $user = AdminUser::create(['name' => 'HB3', 'email' => 'hb3-'.uniqid().'@example.com', 'password' => Hash::make('secret-secret'), 'role' => 'owner']);
    test()->actingAs($user, 'admin');
    $set = hb3Set(['slider_hmode_m' => 'max']);

    test()->putJson('/admin-api/banners/sets/'.$set->id, ['slider_hmode_m' => 'fixed'])->assertStatus(422);
    test()->putJson('/admin-api/banners/sets/'.$set->id, ['slider_hmode' => '1;}'])->assertStatus(422);
    test()->putJson('/admin-api/banners/sets/'.$set->id, ['slider_hmode_m' => 'exact'])->assertOk()->assertJsonPath('set.slider_hmode_m', 'exact');

    DB::table('banner_sets')->where('id', $set->id)->update(['slider_hmode_m' => 'weird', 'slider_hmode' => '']);
    $raw = BannerSet::find($set->id);

    expect($raw->sliderHeightMode(true))->toBe('max')->and($raw->sliderHeightMode(false))->toBe('max')
        ->and($raw->sliderExact(true))->toBeFalse();
});

/* ═════════════════════ the migration moves only his set ═══════════════════ */

it('switches the phone to Exactly only on sets that have a phone height', function () {
    /*
     * "Only HIS live set changes, because he asked." MUTATION: drop the
     * `slider_h_m > 0` scope in the migration and the Auto set moves -- red.
     */
    $his = hb3Set(['slider_hmode_m' => 'max', 'slider_h' => 500]);
    $auto = BannerSet::create(['name' => 'Auto', 'slug' => 'hb3-auto-'.uniqid(), 'status' => 'publish', 'position' => 1,
        'kind' => 'slider', 'slider_h_m' => 0, 'slider_h' => 400]);

    $migration = require database_path('migrations/2027_10_12_090000_banner_height_exact_mode.php');
    ob_start();
    $migration->up();
    ob_end_clean();

    expect(BannerSet::find($his->id)->sliderHeightMode(true))->toBe('exact')
        ->and(BannerSet::find($his->id)->sliderHeightMode(false))->toBe('max')
        ->and(BannerSet::find($auto->id)->sliderHeightMode(true))->toBe('max')
        ->and(BannerSet::find($auto->id)->sliderHeightMode(false))->toBe('max')
        // ...and it wrote his phone crop, so the first phone visit is served it.
        ->and(hb3Crops())->not->toBeEmpty();
});

/* ═════════════════════ the console ═════════════════════════════════════════ */

it('offers both modes beside each height, saves them, and previews Exactly at its true height', function () {
    /*
     * The admin's live preview drew a cap where the shop now draws a height --
     * a preview that disagrees with the shop. MUTATION: take the `exact ?`
     * branch out of the preview's height and this is red.
     */
    $screen = (string) file_get_contents(resource_path('views/admin/partials/banners-screen.blade.php'));

    expect($screen)->toContain("SET_KEYS = SET_KEYS.concat(['slider_hmode', 'slider_hmode_m']);")
        ->and($screen)->toContain("pick('slider_hmode_m', 'How the phone height works'")
        ->and($screen)->toContain("pick('slider_hmode', 'How the computer height works'")
        ->and($screen)->toContain('if (cap > 0) bodyH = exact ? Math.max(80, cap) : Math.min(bodyH, Math.max(80, cap));')
        ->and($screen)->toContain('Up to: the banner is the picture\\u2019s own shape and never taller than this. Exactly: the banner is always this tall; the picture fills it.')
        ->and(BannerSet::SLIDER_HMODES)->toBe(['max' => 'Up to this height', 'exact' => 'Exactly this height']);
});
