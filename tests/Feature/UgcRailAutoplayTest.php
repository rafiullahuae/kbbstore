<?php

declare(strict_types=1);

use App\Models\UgcSection;
use App\Models\UgcVideo;
use App\Services\SettingsService;
use App\Support\Shortcodes;
use App\Support\UgcDemoMedia;
use Illuminate\Support\Facades\Cache;

/**
 * The rail's autoplay arbiter, pinned where PHP can pin it.
 *
 * ── WHAT WENT WRONG, AND WHY NO TEST CAUGHT IT ──────────────────────────────
 *
 * The owner asked three times for the tiles to loop on their own and they did
 * not. Two lanes measured a seeded rail of six healthy, identical clips, found
 * it looping, and said so. His rail is NOT that: four `(Demo)` rows first, his
 * own two last, and a cap of four — so the cap was spent in document order
 * before his clips were reached, and the one visible signal (the play disc) was
 * hidden by a class the rail set at MOUNT rather than at playback, so his own
 * screenshot could not be read either.
 *
 * Whether a decoder advances is a browser fact and tools/ug2-shots.cjs measures
 * it — docs/lane-ug2-shots/measurements-{before,after}.json, at 390, 1280 and
 * 1600. What is pinned here is everything about that failure a PHP suite CAN
 * hold: the attribute the ranking needs, and the shape of the arbiter itself.
 */
function railJs(): string
{
    return (string) file_get_contents(resource_path('views/ugc/assets.blade.php'));
}

function autoplayRail(): string
{
    app(SettingsService::class)->setModule('shoppable_video', true);
    SettingsService::forgetMemo();
    Cache::flush();

    $section = UgcSection::query()->create(['handle' => 'ap-rail', 'title' => 'Shop the look', 'status' => 'publish']);

    $demo = UgcVideo::query()->create([
        'slug' => 'ap-demo', 'title' => 'Glass skin in 6 steps (Demo)', 'status' => 'publish',
        'rights_status' => 'granted',
        'file_path' => UgcDemoMedia::CLIP_PATH,
        'teaser_path' => UgcDemoMedia::CLIP_PATH,
        'poster_path' => UgcDemoMedia::POSTER_PATH,
        'width' => 270, 'height' => 480, 'duration_ms' => 2700,
    ]);

    $real = UgcVideo::query()->create([
        'slug' => 'ap-real', 'title' => 'anua mist spray mini', 'status' => 'publish',
        'rights_status' => 'granted',
        'file_path' => '/uploads/ugc/clip-real.webm',
        'poster_path' => '/uploads/ugc/cover-real.jpg',
        'width' => 720, 'height' => 1280, 'duration_ms' => 9000,
    ]);

    $section->videos()->syncWithoutDetaching([$demo->id => ['position' => 0], $real->id => ['position' => 1]]);

    return Shortcodes::render('[kbb_videos section="ap-rail"]');
}

it('marks a demo tile and only a demo tile', function () {
    $html = autoplayRail();

    /*
     * The storefront cannot rank a placeholder below a real clip unless the
     * server tells it which is which, and this attribute is the whole of that
     * channel — a comparison against App\Support\UgcDemoMedia's one fixed path,
     * printed by ugc/rail.blade.php.
     *
     * MUTATION NOTE — RUN. Removing the `@if ($tile['demo'] ?? false)` line from
     * rail.blade.php drops the count to 0 and this goes red; the storefront then
     * ranks every tile the same and his rail is back where it started.
     */
    expect(substr_count($html, 'data-ugcr-demo="1"'))->toBe(1);

    // And the real clip does NOT carry it — a tile per attribute, not one for
    // the section.
    $realTile = substr($html, (int) strpos($html, 'data-ugcr-slug="ap-real"'));
    expect(str_contains(substr($realTile, 0, 400), 'data-ugcr-demo'))
        ->toBeFalse('his own clip must not be marked as demo footage');
});

it('spends the cap on a ranking rather than on document order', function () {
    $js = railJs();

    /*
     * THE LINE THE OWNER PAID FOR, three rounds running:
     *
     *     Array.prototype.forEach.call(all, function (t) {
     *       if (t.getAttribute('data-ugcr-vis') === '1' && conf(t, 'teaser', '1') === '1') playTeaser(t);
     *     });
     *
     * Whoever is first in the document takes a slot. It has to be gone, not
     * merely have a ranking added beside it.
     *
     * MUTATION NOTE — RUN. Putting that forEach back in rebalance() makes the
     * first expectation red immediately.
     */
    expect(str_contains($js, "=== '1' && conf(t, 'teaser', '1') === '1') playTeaser(t)"))
        ->toBeFalse('rebalance() must not promote tiles in document order any more');

    // A real clip sorts ahead of a placeholder, and then the most-visible tile.
    expect(str_contains($js, "real: t.getAttribute('data-ugcr-demo') === '1' ? 0 : 1"))
        ->toBeTrue('the ranking must know which tiles are placeholders')
        ->and(str_contains($js, 'if (a.real !== b.real) return b.real - a.real;'))
        ->toBeTrue('a real clip must outrank a placeholder')
        ->and(str_contains($js, 'keep.forEach(function (e) { playTeaser(e.t); });'))
        ->toBeTrue('the cap must be handed to the top of the ranking');
});

it('evicts a tile that is holding a slot a better candidate wants', function () {
    /*
     * Ranking the waiting list is worthless on its own. playTeaser() returns
     * early for a tile that already holds a <video>, so the four placeholders
     * that grabbed the decoders first would simply keep them — which is the
     * owner's screenshot exactly.
     *
     * MUTATION NOTE — RUN. Deleting the eviction loop from rebalance() leaves
     * this red and leaves the rail measurably broken: tools/ug2-shots.cjs then
     * reports tiles 5 and 6 unmounted at 1600 again.
     */
    expect(str_contains(railJs(), 'if (!t.querySelector(\'video\')) return;'))
        ->toBeTrue('a tile outside the top of the ranking must give its decoder back');
});

it('only claims a tile is playing once it is playing', function () {
    $js = railJs();

    /*
     * `.ugcr-t.is-playing .ugcr-play span{opacity:0}` is the ONLY rule that hides
     * the play disc, so `is-playing` is the one thing a shopper — or a
     * screenshot — can read about a tile. It used to be added at MOUNT,
     * unconditionally, before a byte of the clip had arrived: a tile whose file
     * 404s looked exactly like a tile that was looping, and the owner's own
     * picture became unreadable.
     *
     * MUTATION NOTE — RUN. Moving the add back out of the `playing` listener
     * makes the second expectation red — the class is then set somewhere other
     * than in a listener for the browser saying it is rendering frames.
     */
    expect(substr_count($js, "tile.classList.add('is-playing')"))
        ->toBe(1, 'exactly one place may claim a tile is playing');

    $at = (int) strpos($js, "tile.classList.add('is-playing')");
    $before = substr($js, 0, $at);

    expect(str_contains(substr($before, -220), "v.addEventListener('playing'"))
        ->toBeTrue('is-playing must be set by the playing event and nothing else');
});

it('gives the slot back when the file turns out not to play', function () {
    $js = railJs();

    /*
     * There was no error handler at all. A tile whose source 404s mounted,
     * pushed onto `teasers`, took a slot AND KEPT IT for the life of the page —
     * nothing ever removed it. On a shop whose first tiles are duds that is the
     * whole cap held permanently by clips that show nothing.
     *
     * MUTATION NOTE — RUN. Deleting the error listener makes this red, and
     * makes a rail of four broken clips permanently still again.
     */
    expect(str_contains($js, "v.addEventListener('error', function () {"))
        ->toBeTrue('a clip that cannot play must release its slot')
        ->and(str_contains($js, "tile.setAttribute('data-ugcr-fault', 'media');"))
        ->toBeTrue('and must say so on the tile, where a screenshot can see it')
        ->and(str_contains($js, "tile.setAttribute('data-ugcr-fault', 'blocked');"))
        ->toBeTrue('a refused play() is a refusal, not a no-op');
});

it('does not use a 0.6 threshold on a rail that is designed to peek', function () {
    $js = railJs();

    /*
     * The rail scrolls sideways and `cols` offers "2.3 across" and "1.2" BY
     * NAME: the next tile is meant to sit half off the edge. At 0.6 a tile the
     * shopper is plainly looking at was refused — measured at 1280px, tile 6 was
     * 73% on screen, reported vis="0" and mounted nothing.
     *
     * 0.35 still refuses the deliberate 24% peek at 390px, so a phone loads no
     * more clips than it did. tools/ug2-shots.cjs has both numbers.
     *
     * MUTATION NOTE — RUN. Setting VIS_MIN back to 0.6 makes this red, and the
     * browser run then shows tile 6 unmounted at 1280 again.
     */
    expect(str_contains($js, 'var VIS_MIN = 0.35;'))->toBeTrue()
        ->and(str_contains($js, 'r >= VIS_MIN'))->toBeTrue()
        // The ranking sorts on the ratio, so the ratio has to be recorded.
        ->and(str_contains($js, "setAttribute('data-ugcr-vis-ratio', r.toFixed(2))"))->toBeTrue();
});

it('says on every tile why it is not moving', function () {
    $js = railJs();

    /*
     * The other half of "this must never be silent again": every refusal now
     * writes its reason onto the tile in the same vocabulary the admin screen
     * prints in words, so the next person to look at a rail that will not play
     * can read the answer out of the DOM instead of guessing.
     */
    foreach (['cap', 'no-media', 'offscreen', 'teaser-off', 'reduced-motion', 'save-data', 'player-open'] as $reason) {
        expect(str_contains($js, "'".$reason."'"))
            ->toBeTrue('the tile must be able to say “'.$reason.'”');
    }

    expect(str_contains($js, "tile.setAttribute('data-ugcr-why', reason)"))->toBeTrue();
});
