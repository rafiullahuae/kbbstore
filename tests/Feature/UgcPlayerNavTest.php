<?php

declare(strict_types=1);

use App\Models\UgcSection;
use App\Models\UgcVideo;
use App\Services\SettingsService;
use App\Support\Shortcodes;
use Illuminate\Support\Facades\Cache;

/**
 * The opened player: moving between clips, and the square product thumbnail.
 *
 * Both are the owner's own words on a marked-up screenshot of the lightbox:
 *
 *   "i want a nice arrows to move to next or previous video. and also the boxes
 *    has product thumbnail must be square, not rectangle etc. so we will have
 *    more space for the product name etc."
 *
 * ── THE THUMBNAIL WAS A REAL DEFECT, NOT A PREFERENCE ───────────────────────
 *
 * `.ugcp-th` has said `width:46px;height:46px;flex:0 0 auto` since the player
 * was built, and it drew a 129x46 letterbox anyway. `.ugcp-card > div` — which
 * matched it, because .ugcp-th IS a div and IS a direct child — carried
 * `flex:1` at specificity (0,1,1) against .ugcp-th's (0,1,0), so the shorthand
 * `flex:1 1 0%` overrode the basis and the thumbnail took an equal share of the
 * card. Measured in Chromium: 129x46 at 390px and 138x46 at 1280px, and the
 * product name squeezed into 129px and wrapping onto two lines.
 * docs/lane-ug2-shots/player-after.json carries the before, the after and the
 * same card measured with the defect re-introduced.
 */
function playerJs(): string
{
    return (string) file_get_contents(resource_path('views/ugc/assets.blade.php'));
}

function playerRail(int $clips = 3): string
{
    app(SettingsService::class)->setModule('shoppable_video', true);
    SettingsService::forgetMemo();
    Cache::flush();

    $section = UgcSection::query()->create(['handle' => 'pn-rail', 'title' => 'Shop the look', 'status' => 'publish']);

    for ($i = 1; $i <= $clips; $i++) {
        $v = UgcVideo::query()->create([
            'slug' => 'pn-'.$i, 'title' => 'Clip '.$i, 'status' => 'publish', 'rights_status' => 'granted',
            'file_path' => '/uploads/ugc/pn-'.$i.'.webm',
            'poster_path' => '/uploads/ugc/pn-'.$i.'.jpg',
            'width' => 720, 'height' => 1280, 'duration_ms' => 9000,
        ]);
        $section->videos()->syncWithoutDetaching([$v->id => ['position' => $i]]);
    }

    return Shortcodes::render('[kbb_videos section="pn-rail"]');
}

/* ─────────────────────────── the square thumbnail ────────────────────────── */

it('keeps the player’s product thumbnail square', function () {
    $css = playerJs();

    /*
     * MUTATION NOTE — RUN, IN THE BROWSER AND HERE. Changing the selector back
     * to `.ugcp-card > div` makes this red; in Chromium the same change takes
     * the thumbnail from 46x46 to a full share of the card and the product name
     * "Fresh That Lasts Deodorant" from ONE line to TWO at both 390 and 1280
     * (tools/ug2-player-shots.cjs measures exactly that, on the same card, by
     * re-introducing the rule).
     */
    expect(str_contains($css, '.ugcp-card > div:not(.ugcp-th){min-width:0;flex:1}'))
        ->toBeTrue('the text column may grow; the thumbnail may not')
        ->and(str_contains($css, '.ugcp-card > div{min-width:0;flex:1}'))
        ->toBeFalse('the selector that swallowed the thumbnail must be gone');

    /* Square BY CONSTRUCTION. Two matching pixel numbers are square only until
       somebody edits one of them. */
    expect(str_contains($css, '.ugcp-th{flex:0 0 46px;width:46px;aspect-ratio:1;'))
        ->toBeTrue('the thumbnail holds its own square')
        ->and(str_contains($css, '.ugcp-th img{width:100%;height:100%;object-fit:cover'))
        ->toBeTrue('a tall bottle and a wide carton must give the same square');
});

/* ───────────────────────────── previous / next ───────────────────────────── */

it('carries the previous and next labels on the section, translated', function () {
    $html = playerRail();

    /*
     * The buttons are built in the browser, so their words have to travel with
     * the markup or an Arabic shopper gets English ones. `store.ugc.next` and
     * `store.ugc.previous` are strings this shop already translates.
     *
     * MUTATION NOTE — RUN. Deleting the two attributes from rail.blade.php makes
     * this red, and updateNav() then falls back to its English constants on
     * every page including /ar/.
     */
    expect(substr_count($html, 'data-ugcr-prev="'))->toBe(1)
        ->and(substr_count($html, 'data-ugcr-next="'))->toBe(1)
        ->and(str_contains($html, 'data-ugcr-prev="'.__('store.ugc.previous').'"'))->toBeTrue()
        ->and(str_contains($html, 'data-ugcr-next="'.__('store.ugc.next').'"'))->toBeTrue();
});

it('draws two nav buttons and disables them at the ends rather than wrapping', function () {
    $js = playerJs();

    expect(str_contains($js, 'data-ugcp-nav="-1"'))->toBeTrue()
        ->and(str_contains($js, 'data-ugcp-nav="1"'))->toBeTrue();

    /*
     * DISABLED AT THE ENDS, NOT WRAPPED. A rail is an order the owner arranged,
     * so "have I seen all of these?" has to stay answerable — a silent wrap
     * makes the sixth clip indistinguishable from the first.
     *
     * MUTATION NOTE — RUN. Replacing the two `.disabled =` lines with `false`
     * makes this red; the buttons then stay lit at both ends and pressing one
     * does nothing, which is the failure mode this lane exists to remove.
     */
    expect(str_contains($js, 'prev.disabled = !many || at <= 0;'))->toBeTrue()
        ->and(str_contains($js, 'next.disabled = !many || at < 0 || at >= list.length - 1;'))->toBeTrue()
        // …and hidden altogether when there is nowhere to go.
        ->and(str_contains($js, 'prev.hidden = next.hidden = !many;'))->toBeTrue();
});

it('moves between clips through the one open path and the one teardown', function () {
    $js = playerJs();

    /*
     * `step()` calls open(), and open() hands the old decoder back through the
     * SAME recycleVideo() close() uses. The obvious alternative — assigning a
     * new `.src` over the old one — is a second teardown with its own opinion
     * about what a replaced stream leaves resident.
     *
     * MUTATION NOTE — RUN. Deleting the recycleVideo() call from open() leaves
     * this red; `insertBefore(fresh` then appears once instead of inside one
     * shared helper, and a shopper walking six clips accumulates six decoders.
     */
    expect(substr_count($js, 'function recycleVideo()'))->toBe(1)
        ->and(substr_count($js, 'box.insertBefore(fresh, box.firstChild);'))
        ->toBe(1, 'there may be only one place that puts a fresh <video> back')
        /* TWO CALLERS, and the second one is the point: close() has always had
           to hand the decoder back, and open() now has to as well, because
           stepping re-enters it with a stream already running. */
        ->and(substr_count($js, '    recycleVideo();'))
        ->toBe(2, 'close() and open() must both go through the one teardown')
        ->and(str_contains($js, '    open(to);'))->toBeTrue('stepping must go through open()');
});

it('returns focus to the clip the shopper ended on', function () {
    /*
     * THE THING PREV/NEXT QUIETLY BREAKS. Focus used to return to the tile that
     * opened the player, which after four presses of "next" is a tile four
     * places back — off screen, because the rail has scrolled. open() re-points
     * returnFocusTo on every step, so Escape lands on the clip actually being
     * watched.
     *
     * MUTATION NOTE — RUN, AND RUN IN A BROWSER TOO, which matters because
     * everything this particular test can see is the SOURCE. It reads the
     * JavaScript and can only say that `returnFocusTo = tile;` sits outside the
     * wasOpen guard; whether a browser then puts focus anywhere is a different
     * claim, and it was the one claim in this lane pinned only by reading.
     *
     * tools/ug2-player-shots.cjs now presses Escape after walking to the last
     * clip and asks the document who has focus. Shipped, in all four contexts
     * (en/ar x 390/1280), focus lands on `ug2-medicube` — the clip the shopper
     * ENDED on. Guard `returnFocusTo = tile;` with `if (!wasOpen)` and this
     * test goes red AND the measurement goes with it: focus lands on
     * `glass-skin-in-6-steps-demo`, the tile they opened five presses earlier,
     * which the rail has since scrolled off screen. Both runs are in
     * docs/lane-ug2-shots/player-after.json under the `-closed` keys.
     */
    $js = playerJs();
    $at = (int) strpos($js, 'returnFocusTo = tile;');

    expect($at)->toBeGreaterThan(0)
        ->and(substr_count($js, 'returnFocusTo = tile;'))->toBe(1)
        // …and it is NOT inside the wasOpen guard, which only covers the focus()
        ->and(str_contains(substr($js, $at, 400), 'if (!wasOpen) {'))->toBeTrue();
});

it('maps the arrow keys through the page’s direction and mirrors with logical properties', function () {
    $js = playerJs();

    /*
     * A keyboard event carries a PHYSICAL key, so something has to map
     * ArrowRight onto "the next one" in English and "the previous one" in
     * Arabic. That one mapping is the only place in this file that reads the
     * document's direction — everything drawn uses logical properties, and the
     * arrow glyph is a triangle whose single solid border is `border-inline-*`,
     * so it points the right way in both directions with no transform and no
     * [dir] rule at all.
     *
     * MUTATION NOTE — RUN. Replacing `forward` with a bare
     * `e.key === 'ArrowRight'` makes this red, and on /ar/ the arrow keys then
     * walk the rail backwards.
     */
    expect(str_contains($js, "var forward = rtl ? (e.key === 'ArrowLeft') : (e.key === 'ArrowRight');"))
        ->toBeTrue('the arrow keys must follow the page direction');

    expect(str_contains($js, '.ugcp-nav.is-prev i{border-inline-end:11px solid #fff'))->toBeTrue()
        ->and(str_contains($js, '.ugcp-nav.is-next i{border-inline-start:11px solid #fff'))->toBeTrue()
        ->and(str_contains($js, '[dir="rtl"] .ugcp-nav'))
        ->toBeFalse('the arrows must mirror themselves, not be mirrored by a dir rule');

    // Placed against the frame's own width expression, never against a measured
    // rectangle — and the max() is what puts them ON the frame at phone width,
    // where there is no dimmed area to sit in.
    expect(str_contains($js, '.ugcp-nav.is-prev{inset-inline-start:max(10px,calc((100% - var(--ugcp-fw)) / 2 - 54px))}'))
        ->toBeTrue()
        ->and(str_contains($js, '.ugcp-nav.is-next{inset-inline-end:max(10px,calc((100% - var(--ugcp-fw)) / 2 - 54px))}'))
        ->toBeTrue();
});

it('never walks onto a clip that cannot open', function () {
    /*
     * open() returns immediately for a tile with no `data-ugcr-src`, so a rail
     * carrying one would have an arrow that visibly did nothing — the exact
     * failure this lane is here to stop being possible.
     *
     * MUTATION NOTE — RUN. Dropping the filter from walkable() makes this red
     * and puts a dead step back in the middle of the walk.
     */
    expect(str_contains(playerJs(), "function (t) { return !!t.getAttribute('data-ugcr-src'); }"))
        ->toBeTrue('the walk must skip clips the player would refuse to open');
});
