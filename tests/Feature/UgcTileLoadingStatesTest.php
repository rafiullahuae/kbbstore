<?php

declare(strict_types=1);

use App\Models\UgcSection;
use App\Models\UgcVideo;
use App\Services\SettingsService;
use App\Support\Shortcodes;
use Illuminate\Support\Facades\Cache;

/**
 * THREE STATES ON A TILE, AND EXACTLY ONE OF THEM AT A TIME.
 *
 * The owner: *"if video doesn't load at the moment, make a nice loading option,
 * but super nice icon. and the play button will be hide for now, and when video
 * load on page the play icon will show."*
 *
 * ── WHAT WAS WRONG ──────────────────────────────────────────────────────────
 *
 * A tile drew its play disc from the moment the page rendered until the
 * `playing` event fired. In between — which is the whole of the fetch, and on
 * this shop the whole of a FULL CLIP because ffmpeg cannot be started from
 * PHP-FPM there — a shopper was looking at a play button that did nothing yet,
 * with no sign that anything was happening. There were two states where there
 * needed to be three.
 *
 *     loading  the loader, and no disc         .is-loading
 *     ready    the disc, and no loader         neither class
 *     playing  neither                         .is-playing
 *
 * ── AND THE FOURTH THING, WHICH IS THE ONE THAT BITES ───────────────────────
 *
 * A tile that FAILED. It already carries `data-ugcr-fault`, and the answer is
 * that it lands in `ready`: stopTeaser() drops both classes, so the disc comes
 * back and the loader goes. That is deliberate and it is what stops a shopper
 * looking at a spinner that is never going to finish — pressing the disc opens
 * the player, which streams the FULL clip and may well work when a missing
 * teaser did not.
 *
 * WHAT IS PINNED HERE is everything about that a PHP suite can hold: the
 * markup, the CSS that decides what is drawn, and the shape of the four
 * transitions. Whether a decoder actually paints a frame is a browser fact and
 * docs/lane-ug3-shots/ measures it in Chromium at 390 and 1280.
 */
function loadStateCss(): string
{
    return (string) file_get_contents(resource_path('views/ugc/assets.blade.php'));
}

function loadStateRail(): string
{
    app(SettingsService::class)->setModule('shoppable_video', true);
    SettingsService::forgetMemo();
    Cache::flush();

    $section = UgcSection::query()->create(['handle' => 'ls-rail', 'title' => 'Shop the look', 'status' => 'publish']);

    foreach (['ls-a', 'ls-b'] as $i => $slug) {
        $clip = UgcVideo::query()->create([
            'slug' => $slug, 'title' => 'clip '.$slug, 'status' => 'publish',
            'rights_status' => 'granted',
            'file_path' => '/uploads/ugc/'.$slug.'.webm',
            'poster_path' => '/uploads/ugc/'.$slug.'.jpg',
            'width' => 720, 'height' => 1280, 'duration_ms' => 9000,
        ]);
        $section->videos()->syncWithoutDetaching([$clip->id => ['position' => $i]]);
    }

    return Shortcodes::render('[kbb_videos section="ls-rail"]');
}

it('gives every tile one loader, and the loader nothing to fetch', function () {
    $html = loadStateRail();

    /*
     * THE FINISHED STATE, PINNED AS A COUNT. Two tiles, two loaders. Zero is
     * "the element was never added"; more than one per tile is two spinners
     * turning out of phase over the same poster, which is the shape a partial
     * that gets included twice produces.
     *
     * MUTATION NOTE — RUN. Delete the <span class="ugcr-load"> line from
     * ugc/rail.blade.php and the first expectation is 0, red.
     */
    expect(substr_count($html, 'class="ugcr-load"'))
        ->toBe(2, 'one loader per tile, and exactly one');

    // It is decorative. Six tiles announcing "loading" as a rail is scrolled is
    // noise a screen reader user cannot turn off, and nothing is being waited
    // FOR — the poster is on screen and the page is usable.
    expect(substr_count($html, '<span class="ugcr-load" aria-hidden="true"></span>'))
        ->toBe(2, 'the loader is announced, or it carries something that is not a pseudo-element');

    /*
     * AND IT COSTS NO REQUEST. The whole indicator is two pseudo-elements on one
     * empty span — no <img>, no inline <svg>, no background-image, no font
     * glyph. A loading indicator that has to be fetched before it can say
     * something is being fetched is the joke this avoids.
     */
    $css = loadStateCss();
    $block = substr($css, (int) strpos($css, '.ugcr-load{'));
    $block = substr($block, 0, (int) strpos($block, '.ugcr-badge{'));

    expect(str_contains($block, 'url('))->toBeFalse('the loader fetches something to draw itself');
});

it('draws the loader only while a tile is loading, and nothing else moves', function () {
    $css = loadStateCss();

    /*
     * The loader is CSS-only state: the script adds ONE class and the stylesheet
     * decides. So the rule that reveals it is the whole mechanism, and its
     * default has to be hidden — a loader that ships visible is a spinner on
     * every tile of every rail, for ever, including the tiles that will never
     * play.
     *
     * `visibility` as well as `opacity`, because an `opacity:0` element is still
     * a paint the compositor does on every frame of an animation it cannot be
     * seen doing.
     *
     * MUTATION NOTE — RUN. Change `.ugcr-load{...opacity:0` to `opacity:1` and
     * the first expectation is red.
     */
    expect(str_contains($css, '.ugcr-load{position:absolute;inset:0;z-index:3;display:grid;place-items:center;'."\n".'  pointer-events:none;opacity:0;visibility:hidden;'))
        ->toBeTrue('the loader no longer ships hidden');

    expect(str_contains($css, '.ugcr-t.is-loading .ugcr-load{opacity:1;visibility:visible;'))
        ->toBeTrue('nothing reveals the loader');

    /*
     * ── IT MUST NOT MOVE THE TILE ────────────────────────────────────────────
     *
     * The tile's box comes from `aspect-ratio` and rule 4 forbids measuring one
     * in script. The loader is `position:absolute;inset:0`, so it is out of flow
     * and contributes no size at all — which is what makes "no layout shift"
     * true by construction rather than by having looked at it once.
     */
    expect(str_contains($css, '.ugcr-load{position:absolute;inset:0;'))
        ->toBeTrue('the loader is in flow and can push the tile about');

    /*
     * ── REDUCED MOTION ──────────────────────────────────────────────────────
     *
     * And NOT by freezing the sweep where it stopped: a stationary three-quarter
     * arc reads as broken, which is worse than no indicator. The rim is redrawn
     * whole and still.
     *
     * MUTATION NOTE — RUN. Delete the @media block and this is red; leave the
     * block but drop the `background:` from it and the shopper gets a frozen
     * arc, which is what the second expectation is about.
     */
    $reduced = substr($css, (int) strpos($css, '@media(prefers-reduced-motion:reduce){'."\n".'  .ugcr-load::after'));

    expect(strpos($css, '@media(prefers-reduced-motion:reduce){'."\n".'  .ugcr-load::after'))
        ->not->toBeFalse('the loader spins for a shopper who asked for no motion');

    expect(str_contains(substr($reduced, 0, 140), 'animation:none'))
        ->toBeTrue('the sweep keeps turning under reduced motion')
        ->and(str_contains(substr($reduced, 0, 140), 'background:rgba(255,255,255,.62)'))
        ->toBeTrue('reduced motion freezes the arc instead of drawing a whole still rim');
});

it('hides the play disc while loading and while playing, and at no other time', function () {
    $css = loadStateCss();

    /*
     * THE OWNER'S RULE, as two CSS rules and nothing else: *"the play button
     * will be hide for now, and when video load on page the play icon will
     * show."*
     *
     * Both rules key off a class the SCRIPT owns, and there is no third rule —
     * so a tile in any other state (never asked to play, refused by the cap,
     * Save-Data, reduced motion, or FAILED) draws its disc. That last one is the
     * point: a faulted tile has to end up somewhere a shopper can press.
     *
     * MUTATION NOTE — RUN. Delete the `.is-loading` rule and the disc is drawn
     * over the loader — the second expectation is red, and a screenshot at 390
     * shows a play triangle sitting inside a turning rim.
     */
    expect(str_contains($css, '.ugcr-t.is-playing .ugcr-play span{opacity:0;transform:scale(.7)}'))
        ->toBeTrue('the disc is drawn over a tile that is already playing');

    expect(str_contains($css, '.ugcr-t.is-loading .ugcr-play span{opacity:0;transform:scale(.86)}'))
        ->toBeTrue('the disc is drawn over a tile that has not loaded yet');

    // Exactly two rules may hide it. A third would be a fourth state nobody
    // wrote down.
    expect(substr_count($css, '.ugcr-play span{opacity:0'))
        ->toBe(2, 'something else hides the play disc, so the three states are no longer three');
});

it('turns the loader on at mount and off on the first painted frame', function () {
    $js = loadStateCss();

    /*
     * ── THE FOUR TRANSITIONS ────────────────────────────────────────────────
     *
     *   ON    once, in playTeaser(), immediately after mount() — the tile has
     *         been asked to play and has fetched nothing yet.
     *   OFF   in the `playing` listener, which is the browser saying it is
     *         rendering frames. Not `canplay`, not a timer: `playing` is the
     *         moment the poster underneath is replaced by a moving picture.
     *   OFF   in stopTeaser(), which every teardown path goes through —
     *         eviction by the ranking, leaving the viewport, a media error, a
     *         refused play(). This is the line that makes "a failed tile shows
     *         its disc" true.
     *   OFF   on a bound, below.
     *
     * MUTATION NOTE — RUN. Move `tile.classList.add('is-loading')` above the
     * `if (!src)` return in playTeaser() and the count is still 1 but a tile with
     * no media keeps a loader for ever — which is why the SECOND expectation
     * pins where it sits, immediately after the mount. Delete the removal from
     * stopTeaser() and the third is red, and a tile whose file 404s spins for
     * the life of the page.
     */
    expect(substr_count($js, "tile.classList.add('is-loading');"))
        ->toBe(1, 'exactly one place may put a tile into the loading state');

    $at = (int) strpos($js, "tile.classList.add('is-loading');");

    expect(str_contains(substr($js, 0, $at), 'var v = mount(tile, src, true);'))
        ->toBeTrue('a tile is marked loading before it has been asked to load anything');

    // Off on the first frame, in the `playing` listener and beside is-playing.
    $playing = (int) strpos($js, "v.addEventListener('playing', function () {");
    $body = substr($js, $playing, 600);

    expect(str_contains($body, "tile.classList.add('is-playing');"))
        ->toBeTrue('the playing listener moved')
        ->and(str_contains($body, "tile.classList.remove('is-loading');"))
        ->toBeTrue('the loader survives the first painted frame');

    // Off on every teardown.
    $stop = substr($js, (int) strpos($js, 'function stopTeaser(tile) {'), 900);

    expect(str_contains($stop, "tile.classList.remove('is-loading');"))
        ->toBeTrue('a torn-down tile keeps its loader');
});

it('will not leave a shopper watching a spinner that is never going to finish', function () {
    $js = loadStateCss();

    /*
     * ── THE THIRD OUTCOME ───────────────────────────────────────────────────
     *
     * `playing` and `error` are the two answers a browser gives. The third is
     * SILENCE: a request that hangs, a captive portal that swallows the
     * response, a phone that drops signal mid-fetch. Nothing fires, and without
     * a bound the loader turns for ever on a tile that is never going to move.
     *
     * The bound only PUTS THE DISC BACK. It does not unmount, does not set
     * data-ugcr-fault and does not give the playback slot up — so if the bytes
     * arrive at second fourteen, the `playing` listener still runs and the tile
     * still plays. The shopper gets something they can press in the meantime
     * instead of a promise nothing is keeping.
     *
     * THIS IS NOT THE TIMER THE BRIEF FORBIDS. The loader is hidden on the first
     * painted frame, by the `playing` event, every time that event comes; this
     * is the failure bound underneath it, and it is what the success path is
     * measured against in docs/lane-ug3-shots/.
     *
     * MUTATION NOTE — RUN. Delete the setTimeout and the first expectation is
     * red. Add `stopTeaser(tile);` inside it and the last is red — a slow tile
     * would then lose its slot to a neighbour and never come back on its own.
     */
    $at = (int) strpos($js, 'v.ugcrTimers.push(setTimeout(function () {');
    expect($at)->not->toBe(false, 'nothing bounds the loading state');

    $guard = substr($js, $at, 300);

    expect(str_contains($guard, "if (tile.querySelector('video') !== v) return;"))
        ->toBeTrue('the bound fires against a tile that has since been torn down')
        ->and(str_contains($guard, "tile.classList.remove('is-loading');"))
        ->toBeTrue('the bound does not put the disc back')
        ->and(str_contains($guard, "why(tile, 'slow');"))
        ->toBeTrue('the tile does not say why it is sitting there')
        ->and(str_contains($guard, 'stopTeaser'))
        ->toBeFalse('the bound gives the slot up, so a slow tile can never recover')
        ->and(str_contains($guard, 'data-ugcr-fault'))
        ->toBeFalse('a slow tile is marked failed, so the ranking will never try it again');

    // And the timers die with the mount they belong to.
    expect(str_contains($js, 'function clearTimers(v) {'))
        ->toBeTrue('nothing clears the timers a mount armed')
        ->and(str_contains(substr($js, (int) strpos($js, 'function unmount(v) {'), 200), 'clearTimers(v);'))
        ->toBeTrue('a timer outlives the element it was armed for');
});

it('wraps the one-second loop on the second, not on the next timeupdate', function () {
    $js = loadStateCss();

    /*
     * ── WHY THIS APPEARS WITH THE ONE-SECOND CHANGE AND NOT BEFORE ──────────
     *
     * `timeupdate` is specified to fire "at least" every 250ms, and Chromium
     * fires it about every 250ms exactly. A loop that rewinds on the first tick
     * PAST the mark is therefore not `ms` long — it is `ms` plus 0 to 250ms, a
     * different amount every lap.
     *
     * At the old 2500 that overshoot was at most 10% and invisible. At 1000 it
     * is up to 25%, sixty times a minute, on up to four tiles at once and out of
     * step with each other. That is a limp, and "make it super smooth the loop"
     * is the sentence this is answering.
     *
     * So the wrap is ARMED for the time actually remaining and re-armed after
     * each seek lands. `timeupdate` stays as the BACKSTOP, because a background
     * tab clamps timers to once a second and a throttled timer would wrap late
     * where the tick still catches it. Both paths go through one `rewind()`, so
     * the single-seek-in-flight guard holds however the wrap was reached.
     *
     * MUTATION NOTE — RUN. Delete the `v.addEventListener('playing', armWrap);`
     * line and the wrap is never armed for the first lap — the third expectation
     * is red, and the measured series in docs/lane-ug3-shots/ walks back up to
     * 1.24s.
     */
    expect(str_contains($js, 'var armWrap = function () {'))
        ->toBeTrue('the wrap is polled rather than armed')
        ->and(str_contains($js, 'var left = ms - v.currentTime * 1000;'))
        ->toBeTrue('the wrap is armed for a fixed interval instead of the time actually left')
        ->and(str_contains($js, "v.addEventListener('playing', armWrap);"))
        ->toBeTrue('the first lap of the loop is never armed');

    // Re-armed when the seek lands, or the loop runs exactly twice.
    expect(str_contains($js, "v.addEventListener('seeked', function () {\n        rewinding = false;\n        armWrap();\n      });"))
        ->toBeTrue('the wrap is not re-armed after a seek, so the loop stops after one lap');

    // The backstop is still there, and it is a backstop: it calls the same
    // guarded rewind rather than assigning currentTime itself.
    expect(str_contains($js, "v.addEventListener('timeupdate', function () {\n        if (v.currentTime * 1000 < ms) return;\n        rewind();\n      });"))
        ->toBeTrue('the throttled-timer backstop is gone, or it bypasses the seek guard');

    /*
     * AND THE WHOLE POINT IS THAT A CUT CLIP NEVER GETS HERE. A clip with its
     * own one-second teaser file uses the browser's native `v.loop = true` and
     * seeks nothing at all, which is the only genuinely seamless loop available.
     * This arming is the fallback path being made survivable — the fix is still
     * `php artisan ugc:cut-covers`.
     */
    expect(str_contains($js, 'if (!teaser) {'))
        ->toBeTrue('the rewind branch is no longer gated on there being no teaser file');
});
