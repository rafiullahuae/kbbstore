<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Product;
use App\Models\UgcSection;
use App\Models\UgcVideo;
use App\Services\SettingsService;
use App\Services\UgcTranscoder;
use App\Support\Shortcodes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * THE POPUP IS THE SHAPE OF THE CLIP — Lane UG.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * The owner, about the opened player: *"the products boxes should come over the
 * video at the bottom, not outside the video frame! and there should not top and
 * bottom black weird space!!! only the video will popup, along with products
 * box(es), and other credit etc will also come on the video frame."*
 *
 * ── WHAT THE DEFECT LOOKED LIKE ON THE SHOP, MEASURED ──────────────────────
 *
 * Two lines in resources/views/ugc/assets.blade.php did it:
 *
 *     .ugcp-box{width:100%;height:100%;max-width:520px}
 *     .ugcp-v  {object-fit:contain}
 *
 * The frame was sized to the VIEWPORT and the picture was fitted inside it, so
 * the two were different rectangles — and `.ugcp-credit`, `.ugcp-rail` and
 * `.ugcp-x` are all positioned against the FRAME. Driven in Chromium against
 * this repo, on the 9:16 clips this feature is for
 * (docs/lane-ug-shots/measurements.json carries the run):
 *
 *   390 x 844   frame 390x844, picture 390x693 -> 75px of black above AND below.
 *               The credit sat at y=14, on the top band. The picture's bottom
 *               edge was y=768 and the product rail ran y=681..844, so 76 of its
 *               163px hung below the video.
 *   1280 x 800  frame 520x800, picture 450x800 -> 35px of black each side, and
 *               the rail spanned the full 520 and overhung the picture at both
 *               ends.
 *
 * After: at 390 the frame is 390x693 and the picture is 390x693, at 1280 the
 * frame is 450x800 and the picture is 450x800 — band 0 on every edge at both
 * widths, and the rail's box is the picture's box to the pixel.
 *
 * ── WHAT THIS FILE CAN AND CANNOT PIN ──────────────────────────────────────
 *
 * A rendered rectangle is a browser measurement and lives in
 * docs/lane-ug-shots/. What lives here is the MECHANISM those numbers come
 * out of, because each half of it can be undone on its own and the page still
 * looks plausible in a screenshot taken at the one width somebody checked:
 *
 *   1. the server prints the clip's ratio onto the tile (`data-ugcr-ar`),
 *   2. the frame's width and height are min() expressions over that ratio,
 *   3. the picture fills the frame rather than being fitted inside it,
 *   4. every overlay is inside the frame element,
 *   5. the script hands the frame the ratio, and measures nothing to do it.
 */
function ugFrameShop(): void
{
    app(SettingsService::class)->setModule('shoppable_video', true);
    SettingsService::forgetMemo();
    Cache::flush();
}

function ugFrameRail(int $width = 720, int $height = 1280, int $products = 2): string
{
    $brand = Brand::query()->firstOrCreate(['slug' => 'ug-frame-brand'], ['name' => 'COSRX']);

    $row = UgcVideo::query()->create([
        'slug' => 'ugframe-'.Str::random(8),
        'title' => 'Glass skin in 6 steps',
        'caption' => 'Glass skin in 6 steps',
        'status' => 'publish',
        'rights_status' => 'granted',
        'file_path' => '/uploads/ugc/clip-ugframe.mp4',
        'poster_path' => '/uploads/ugc/poster-ugframe.jpg',
        'width' => $width,
        'height' => $height,
        'creator_handle' => '@layla.skin',
        'source_platform' => 'upload',
    ]);

    for ($i = 0; $i < $products; $i++) {
        $p = Product::query()->create([
            'slug' => 'ugframe-p'.$i.'-'.Str::random(5),
            'name' => 'Advanced Snail 96 Mucin Power Essence',
            'brand_id' => $brand->id,
            'price' => 11000,
            'status' => 'publish',
            'stock_status' => 'instock',
            'type' => 'simple',
        ]);
        $row->products()->attach($p->id, ['position' => $i]);
    }

    $handle = 'ugframe-'.substr(md5((string) $row->id), 0, 8);

    $sec = UgcSection::query()->create(['handle' => $handle, 'title' => 'UG', 'status' => 'publish']);
    $sec->videos()->attach($row->id, ['position' => 0]);

    return Shortcodes::render('[kbb_videos section="'.$handle.'"]');
}

/** The rendered <section>, with the stylesheet and the script cut off the front. */
function ugFrameMarkup(string $html): string
{
    $at = strrpos($html, '<section class="kbb-ugc');

    return $at === false ? '' : substr($html, $at);
}

it('prints the clip’s own ratio onto the tile, so the popup never has to measure one', function () {
    /*
     * MUTATION NOTE. Delete the `data-ugcr-ar` attribute from the tile in
     * resources/views/ugc/rail.blade.php and this goes red on the first
     * expectation. RUN: red — "Failed asserting that false is true (a tile
     * carries the clip's ratio for the popup's frame)".
     *
     * WHY IT MATTERS RATHER THAN BEING TIDY. Without it the only other place the
     * ratio exists at run time is the video element's videoWidth/videoHeight,
     * which is known only after metadata has loaded — so a frame built from it
     * would draw one rectangle and jump to another, on a modal, on the slowest
     * connection. The server has the number before the page is sent.
     */
    ugFrameShop();

    $markup = ugFrameMarkup(ugFrameRail(720, 1280));

    expect(str_contains($markup, 'data-ugcr-ar="0.5625"'))->toBeTrue(
        'a tile carries the clip\'s ratio for the popup\'s frame'
    );

    // A clip that is NOT 9:16 carries its own number rather than the fallback.
    $wide = ugFrameMarkup(ugFrameRail(1280, 720));

    expect(str_contains($wide, 'data-ugcr-ar="1.7778"'))->toBeTrue(
        'a landscape clip frames at its own ratio, not at 9:16'
    );
});

it('falls back to 9:16 rather than printing a ratio that divides by zero', function () {
    /*
     * `width` and `height` are nullable columns and a clip imported before the
     * transcoder filled them in has 0 in both. `calc(100dvh / 0)` is an invalid
     * declaration, which drops the whole height rule and gives the frame the
     * viewport's height again — the exact geometry this round removed, restored
     * silently by one bad row.
     *
     * MUTATION NOTE. Remove the `($w > 0 && $h > 0)` guard in
     * rail.blade.php so the expression is a bare `round($w / $h, 4)` and this is
     * a DivisionByZeroError rather than a failure. RUN: red.
     */
    ugFrameShop();

    $markup = ugFrameMarkup(ugFrameRail(0, 0));

    expect(str_contains($markup, 'data-ugcr-ar="0.5625"'))->toBeTrue(
        'a clip with no stored dimensions frames at the shipped 9:16'
    );
});

it('sizes the popup frame from that ratio and fills it, instead of letterboxing inside the viewport', function () {
    /*
     * THE THREE HALVES OF THE OWNER'S COMPLAINT, one expectation each.
     *
     * MUTATION NOTE, and it is the defect verbatim. Put the two old lines back
     * in resources/views/ugc/assets.blade.php:
     *
     *     .ugcp-box{position:relative;width:100%;height:100%;max-width:520px;...}
     *     .ugcp-v{...object-fit:contain...}
     *
     * RUN: red on all three expectations — the frame no longer reads the ratio,
     * the picture is fitted rather than filled, and `max-width:520px` is back.
     * In Chromium that same edit restores a measured 75px black band above and
     * below the picture at 390 and 35px each side at 1280.
     */
    $css = file_get_contents(resource_path('views/ugc/assets.blade.php'));

    expect(str_contains($css, 'width:min(var(--ugcp-w),calc(var(--ugcp-h) * var(--ugcp-ar)))'))->toBeTrue(
        'the frame\'s width comes from the clip\'s ratio, not from the viewport'
    );

    expect(str_contains($css, 'height:min(var(--ugcp-h),calc(var(--ugcp-w) / var(--ugcp-ar)))'))->toBeTrue(
        'the frame\'s height comes from the clip\'s ratio, not from the viewport'
    );

    /*
     * `contain` is the line that drew the bands. It must not come back on the
     * player's video — and the search is deliberately for the whole declaration
     * on `.ugcp-v`, because `object-fit:contain` is a legitimate thing to write
     * about some other element one day.
     */
    $player = substr($css, strpos($css, '.ugcp-v{'));
    $player = substr($player, 0, (int) strpos($player, '}') + 1);

    expect(str_contains($player, 'object-fit:cover'))->toBeTrue(
        'the picture fills the frame; the frame already carries its ratio'
    );
    expect(str_contains($player, 'object-fit:contain'))->toBeFalse(
        'object-fit:contain on the player is what produced the black bands'
    );
});

it('keeps the credit, the product boxes and the close button inside the video frame', function () {
    /*
     * The frame being the picture is only half of it: if an overlay were a
     * sibling of `.ugcp-box` rather than a child, it would be positioned against
     * the viewport again and land off the video exactly as before.
     *
     * MUTATION NOTE. In ensureShell(), move `<div class="ugcp-rail"></div>`
     * outside the `<div class="ugcp-box">` (before its closing tag becomes after
     * it) and this is red: the rail is no longer between the box's opening tag
     * and its close. RUN: red.
     */
    $js = file_get_contents(resource_path('views/ugc/assets.blade.php'));

    $open = strpos($js, "shell.innerHTML = '<div class=\"ugcp-box\">'");
    expect($open)->not->toBeFalse();

    $shell = substr($js, (int) $open, 900);
    $end = strpos($shell, "+ '</div>';");
    expect($end)->not->toBeFalse();

    $inside = substr($shell, 0, (int) $end);

    foreach (['ugcp-v', 'ugcp-credit', 'ugcp-rail', 'ugcp-x'] as $part) {
        expect(str_contains($inside, $part))->toBeTrue(
            $part.' is built inside the video frame, so it sits over the video'
        );
    }
});

it('holds the keyboard while the popup is open and hands it back on close', function () {
    /*
     * An overlay with aria-modal="true" that does not hold focus is lying: the
     * rail behind it stays in the tab order, so Tab walks out of the dialog onto
     * tiles that are covered and still clickable.
     *
     * MUTATION NOTE. Delete the `if (e.key === 'Tab') trapTab(e);` line from
     * ensureShell()'s keydown listener and this is red on the first expectation.
     * Delete the `returnFocusTo.focus()` block from close() and it is red on the
     * third. RUN: red on each, separately.
     */
    $js = file_get_contents(resource_path('views/ugc/assets.blade.php'));

    expect(str_contains($js, "if (e.key === 'Tab') trapTab(e);"))->toBeTrue(
        'Tab is trapped inside the dialog'
    );
    expect(str_contains($js, 'closer.focus()'))->toBeTrue(
        'focus moves into the dialog when it opens'
    );
    expect(str_contains($js, 'returnFocusTo.focus()'))->toBeTrue(
        'focus goes back to the tile that opened it'
    );
    expect(str_contains($js, "e.key === 'Escape'"))->toBeTrue(
        'Escape closes it'
    );
});

it('measures nothing in the browser to lay the popup out', function () {
    /*
     * Rule 4, applied to the code this round added. The frame is arithmetic over
     * a server-printed number; if anybody ever "improves" it by reading the
     * video's own box, this is the test that says no.
     *
     * MUTATION NOTE. Add `var b = frame.getBoundingClientRect();` inside open()
     * and this is red. RUN: red.
     */
    $js = file_get_contents(resource_path('views/ugc/assets.blade.php'));

    // The comments in this file discuss these APIs by name, so they are stripped
    // before the scan — a source scan finds its own prose otherwise.
    $code = preg_replace('#/\*.*?\*/#s', '', $js);
    $code = preg_replace('#^\s*//.*$#m', '', (string) $code);
    $code = preg_replace('#\{\{--.*?--\}\}#s', '', (string) $code);

    foreach (['getBoundingClientRect', 'offsetHeight', 'offsetWidth', 'clientHeight',
        'getComputedStyle', 'requestAnimationFrame', 'window.innerHeight'] as $api) {
        expect(str_contains((string) $code, $api))->toBeFalse(
            $api.' lays the popup out in JavaScript; this project sizes with calc()'
        );
    }
});
