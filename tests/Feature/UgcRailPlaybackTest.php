<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\UgcAppearanceController;
use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\ModuleSetting;
use App\Models\Product;
use App\Models\UgcSection;
use App\Models\UgcVideo;
use App\Services\SettingsService;
use App\Services\Ugc\RailPlayback;
use App\Support\UgcDemoMedia;
use Illuminate\Support\Facades\Cache;

/**
 * "on front-end it still not auto play" — round three, and the round that found
 * why.
 *
 * ── THE DEFECT, IN ONE SENTENCE ─────────────────────────────────────────────
 *
 * `rebalance()` in ugc/assets.blade.php promoted tiles IN DOCUMENT ORDER until
 * `max_playing` was reached. The owner's homepage rail is four `(Demo)` clips
 * that Content → Demo content wrote, followed by the two clips he actually
 * uploaded, and `max_playing` is 4 — so the cap was spent before his own clips
 * were looked at, and the two he cares about were the two showing a play disc.
 * docs/lane-ug2-shots/measurements-before.json is the run: at 1600x1000 tiles 5
 * and 6 report `data-ugcr-vis="1"`, are 100% on screen, and mount no <video>.
 *
 * ── WHAT THIS FILE PINS ─────────────────────────────────────────────────────
 *
 * The browser half is measured by tools/ug2-shots.cjs, because a decoder either
 * advances or it does not and no PHP test can say. What is pinned HERE is the
 * half that made the defect silent for three rounds: the server can now tell a
 * placeholder from a real clip, and the admin screen says out loud what the
 * storefront is about to do.
 */
function playbackShop(): void
{
    app(SettingsService::class)->setModule('shoppable_video', true);
    SettingsService::forgetMemo();
    Cache::flush();
}

function playbackProduct(string $slug): Product
{
    $brand = Brand::query()->firstOrCreate(['slug' => 'pb-brand'], ['name' => 'Beauty of Joseon']);

    return Product::query()->create([
        'slug' => $slug, 'name' => 'Relief Sun '.$slug, 'brand_id' => $brand->id,
        'price' => 8900, 'status' => 'publish', 'stock_status' => 'instock', 'type' => 'simple',
    ]);
}

/**
 * Four demo clips then two real ones — HIS rail, and the mix is the whole point.
 * A tidy rail of six identical clips is what the last two rounds measured.
 */
function playbackSection(int $demo = 4, int $real = 2, bool $realOnDisk = true): UgcSection
{
    $section = UgcSection::query()->create(['handle' => 'shop-the-look', 'title' => 'Shop the look', 'status' => 'publish']);
    $product = playbackProduct('pb-1');
    $pos = 0;

    for ($i = 1; $i <= $demo; $i++) {
        $v = UgcVideo::query()->create([
            'slug' => 'demo-'.$i, 'title' => 'Demo clip '.$i, 'status' => 'publish',
            'rights_status' => 'granted',
            // The real thing: DemoContentController::seedVideos() points every
            // demo row at this one file, and that is what makes them
            // recognisable without a query.
            'file_path' => UgcDemoMedia::CLIP_PATH,
            'teaser_path' => UgcDemoMedia::CLIP_PATH,
            'poster_path' => UgcDemoMedia::POSTER_PATH,
            'width' => 270, 'height' => 480, 'duration_ms' => 2700,
        ]);
        $v->products()->sync([$product->id]);
        $section->videos()->syncWithoutDetaching([$v->id => ['position' => $pos++]]);
    }

    for ($i = 1; $i <= $real; $i++) {
        $v = UgcVideo::query()->create([
            'slug' => 'real-'.$i, 'title' => 'His own clip '.$i, 'status' => 'publish',
            'rights_status' => 'granted',
            'file_path' => '/uploads/ugc/clip-'.$i.'.webm',
            'poster_path' => '/uploads/ugc/cover-'.$i.'.jpg',
            'width' => 720, 'height' => 1280, 'duration_ms' => 9000,
        ]);
        $v->products()->sync([$product->id]);
        $section->videos()->syncWithoutDetaching([$v->id => ['position' => $pos++]]);

        if ($realOnDisk) {
            playbackTouch('/uploads/ugc/clip-'.$i.'.webm');
        }
    }

    playbackTouch(UgcDemoMedia::CLIP_PATH);

    return $section;
}

/** RailPlayback asks whether the file is really there, so the file has to be. */
function playbackTouch(string $path): void
{
    $full = public_path(ltrim($path, '/'));
    @mkdir(dirname($full), 0755, true);
    @file_put_contents($full, 'x');
}

afterEach(function () {
    foreach (glob(public_path('uploads/ugc/*')) ?: [] as $f) {
        @unlink($f);
    }
});

it('gives the owner’s own clips the playback slots before the demo ones', function () {
    playbackShop();
    playbackSection();

    $out = app(RailPlayback::class)->describe();

    expect($out['total'])->toBe(6)
        ->and($out['max'])->toBe(4)
        ->and($out['moving'])->toBe(4);

    $by = collect($out['tiles'])->keyBy('n');

    /*
     * THE ASSERTION THE DEFECT FAILS. Tiles 5 and 6 are his own clips and they
     * are LAST in the section, which is exactly the position that lost under
     * document order.
     *
     * MUTATION NOTE — RUN. Deleting the `demo` term from RailPlayback's usort
     * (so the comparator is `$a <=> $b`, plain document order) turns tiles 5 and
     * 6 into 'cap' and tiles 3 and 4 into 'plays': this expectation goes red on
     * "Failed asserting that 'cap' is identical to 'plays'", which is the
     * owner's screenshot stated as a test.
     */
    expect($by[5]['why'])->toBe(RailPlayback::WHY_PLAYS)
        ->and($by[6]['why'])->toBe(RailPlayback::WHY_PLAYS)
        ->and($by[5]['demo'])->toBeFalse()
        ->and($by[6]['demo'])->toBeFalse();

    // The two demo tiles that lose are the ones at the BACK of the demo run, so
    // the order the owner arranged still decides between equals.
    expect($by[1]['why'])->toBe(RailPlayback::WHY_PLAYS)
        ->and($by[2]['why'])->toBe(RailPlayback::WHY_PLAYS)
        ->and($by[3]['why'])->toBe(RailPlayback::WHY_CAP)
        ->and($by[4]['why'])->toBe(RailPlayback::WHY_CAP)
        ->and($out['demo_moving'])->toBe(2);
});

it('recognises a demo clip by its file and nothing else', function () {
    playbackShop();
    playbackSection(demo: 1, real: 1);

    $tiles = app(RailPlayback::class)->describe()['tiles'];

    /*
     * Not by its title, not by a flag on the row, not by the demo_seed_log —
     * by the one fixed path App\Support\UgcDemoMedia writes. A row an owner
     * renamed is still demo footage; a row he uploaded that happens to be
     * called "Demo" is not.
     *
     * MUTATION NOTE — RUN. Pointing the demo row's file_path at
     * '/uploads/ugc/anything-else.webm' makes `demo` false on tile 1 and this
     * goes red.
     */
    expect($tiles[0]['demo'])->toBeTrue()
        ->and($tiles[1]['demo'])->toBeFalse();
});

it('says no tile will move when the loop is switched off, and that somebody switched it', function () {
    playbackShop();
    playbackSection();

    app(SettingsService::class)->setModuleSetting('shoppable_video', 'teaser', '');
    SettingsService::forgetMemo();
    Cache::flush();

    $out = app(RailPlayback::class)->describe();

    expect($out['teaser_on'])->toBeFalse()
        ->and($out['moving'])->toBe(0)
        ->and(collect($out['tiles'])->pluck('why')->unique()->all())->toBe([RailPlayback::WHY_TEASER_OFF]);

    /*
     * ── "NEVER SET" IS NOT "SWITCHED OFF" ────────────────────────────────────
     *
     * A bool that stores '1'/'' cannot tell them apart from its VALUE, which is
     * the trap 2027_03_21_000000_tamara_auto_capture_on_by_default was written
     * about. The row's existence answers it, and the screen says a different
     * sentence for each.
     *
     * MUTATION NOTE — RUN. Deriving `teaser_chosen` from the VALUE instead —
     * `($conf['teaser'] ?? true) === false` — is the version that looks
     * equivalent and is not. It agrees on the two lines above and it is wrong
     * on the third: a shop where somebody switched the loop ON is a shop that
     * has touched the control, and the value-derived version calls that
     * untouched. The last expectation below goes red on it; the first two do
     * not, which is exactly why it is worth writing down.
     */
    expect($out['teaser_chosen'])->toBeTrue();

    ModuleSetting::query()->where('module', 'shoppable_video')->where('key', 'teaser')->delete();
    SettingsService::forgetMemo();
    Cache::flush();

    // Never set: the value is the shipped default and nobody chose it.
    $untouched = app(RailPlayback::class)->describe();
    expect($untouched['teaser_on'])->toBeTrue()
        ->and($untouched['teaser_chosen'])->toBeFalse();

    // Set, and set ON. Same value as the default, and a different fact about it.
    app(SettingsService::class)->setModuleSetting('shoppable_video', 'teaser', '1');
    SettingsService::forgetMemo();
    Cache::flush();

    $chosen = app(RailPlayback::class)->describe();
    expect($chosen['teaser_on'])->toBeTrue()
        ->and($chosen['teaser_chosen'])->toBeTrue();
});

it('names a clip whose file is not on this server instead of counting it', function () {
    playbackShop();
    playbackSection(demo: 0, real: 2, realOnDisk: false);

    $out = app(RailPlayback::class)->describe();

    /*
     * The storefront used to mount a video for one of these, take a playback
     * slot and KEEP IT for the life of the page — nothing listened for `error`.
     * So a rail of duds never moved and every tile looked like it was playing.
     *
     * MUTATION NOTE — RUN. Making onDisk() `return true` unconditionally turns
     * both tiles into 'plays' and moving into 2, and this goes red.
     */
    expect($out['moving'])->toBe(0)
        ->and(collect($out['tiles'])->pluck('why')->all())
        ->toBe([RailPlayback::WHY_NO_FILE, RailPlayback::WHY_NO_FILE]);
});

it('hands the verdict to the appearance screen', function () {
    playbackShop();
    playbackSection();

    $owner = AdminUser::query()->create([
        'name' => 'Owner', 'email' => 'owner@pb.test', 'password' => 'secret-pb-1', 'role' => 'owner',
    ]);

    $response = $this->actingAs($owner, 'admin')->getJson('/admin-api/ugc-appearance');

    $response->assertOk();

    /*
     * MUTATION NOTE — RUN. Removing the 'playback' key from
     * UgcAppearanceController::show() makes this 404-free response arrive
     * without it and the test goes red — which is the shape the screen was in
     * for three rounds: every control correct, and no way to find out that the
     * rail was refusing his clips.
     */
    expect($response->json('playback.total'))->toBe(6)
        ->and($response->json('playback.moving'))->toBe(4)
        ->and($response->json('playback.section'))->toBe('shop-the-look');
});
