<?php

declare(strict_types=1);

use App\Models\BannerCard;
use App\Models\BannerSet;
use App\Services\Banners;
use App\Support\ImageVariants;

/**
 * A SECOND PICTURE PER SLIDE, FOR PHONES.                           Lane SEC
 *
 * The owner: "for desktop the size should be 1920 x 550 and in mobile 500 x
 * 600". Two sizes is two pictures. He described the phone image as a thing that
 * exists; a slide having one image field was our gap, not his ambiguity.
 *
 * ── WHAT THE GAP LOOKED LIKE, MEASURED BEFORE THIS ROUND ────────────────────
 *
 * One 1920 x 550 picture serving both frames. In the shipped 500 x 600 phone
 * frame `object-fit: cover` showed about a quarter of its width, and
 * `sizes="min(100vw, 2400px)"` declared 390 CSS pixels for a box whose COVERED
 * width is 1533 — so Chromium chose the 400w copy and painted it across 1533
 * pixels. Cropped and soft. storage/sec-logs/shots/many-390.png is the picture
 * of it; wrong-390.png is the control that proves the frame was never at fault,
 * a portrait source in the same portrait frame being sharp.
 *
 * ── THE THREE CLAIMS THIS FILE HOLDS ────────────────────────────────────────
 *
 * 1. A slide WITH a phone picture draws it below 768px and the desktop one
 *    above, from one <picture>, with `sizes` correct on both because each
 *    shape now matches its frame.
 * 2. A slide WITHOUT one is UNCHANGED IN WHAT IT DRAWS — same file, same crop,
 *    no <picture> wrapper at all — and changed only in what it ASKS FOR: the
 *    width covering the phone frame actually needs.
 * 3. The breakpoint is one constant. The <source>'s media, the preload's media
 *    and the frame's own `min-width:768px` rule must agree, or a width exists
 *    at which the portrait picture is drawn in the landscape frame.
 */

/** A slider set with one slide, optionally carrying a phone picture. */
function bppSet(array $card = [], array $setAttributes = []): array
{
    BannerCard::query()->delete();
    BannerSet::query()->delete();

    $set = BannerSet::create($setAttributes + [
        'name' => 'Phone', 'slug' => 'bpp-'.uniqid(), 'status' => 'publish', 'position' => 0,
    ]);

    BannerCard::create($card + [
        'banner_set_id' => $set->id,
        'image' => 'uploads/banners/bpp-desktop.jpg',
        'image_w' => 1920, 'image_h' => 550,
        'alt' => 'A banner', 'position' => 1, 'status' => 'publish',
    ]);

    $loaded = app(Banners::class)->forPreview($set->id);

    expect($loaded)->not->toBeNull();

    return $loaded;
}

/**
 * Put a real file on disk so detailSrcsetFor() has candidates to offer.
 *
 * ── WITHOUT THIS EVERY `sizes` ASSERTION BELOW IS VACUOUS ───────────────────
 *
 * The partial prints `srcset` and `sizes` together or not at all — a `sizes`
 * beside no `srcset` says nothing to a browser — so on a database with no
 * generated copies the attribute this whole file is about is simply absent, and
 * `expect($img)->toContain('sizes=...')` would be red for the right reason while
 * `->not->toContain` would be green for the wrong one. Found the first way, at
 * the cost of one run.
 */
function bppMakeImage(string $rel, int $w, int $h): void
{
    $path = public_path($rel);

    @mkdir(dirname($path), 0775, true);

    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 210, 160, 180));
    imagejpeg($im, $path, 70);
    imagedestroy($im);

    ImageVariants::generate('/'.ltrim($rel, '/'));
}

/** The partial's markup with its pushed <head> in front, stylesheet stripped. */
function bppRender(array $card = [], array $setAttributes = []): string
{
    bppMakeImage('uploads/banners/bpp-desktop.jpg', 1920, 550);

    if (($card['image_m'] ?? '') !== '') {
        bppMakeImage('uploads/banners/bpp-phone.jpg', (int) ($card['image_m_w'] ?? 500), (int) ($card['image_m_h'] ?? 600));
    }

    [$set, $cards] = bppSet($card, $setAttributes);

    $factory = app('view');
    $factory->flushState();
    $factory->incrementRender();

    try {
        $html = view($set->homePartial(), [
            'set' => $set, 'cards' => $cards,
            'sections' => app(\App\Services\HomepageSections::class),
        ])->render();

        $head = $factory->yieldPushContent('head');
    } finally {
        $factory->decrementRender();
        $factory->flushState();
    }

    // The stylesheet and the script NAME every class and attribute this file
    // asks about, so an absence assertion over the raw render is false for
    // every page the partial has ever drawn.
    $body = (string) preg_replace('#<script>.*?</script>#s', '',
        (string) preg_replace('#<style>.*?</style>#s', '', $head.$html));

    return $body;
}

/** One method's source, from its signature to the closing brace at its indent. */
function bppMethodBody(string $source, string $method): string
{
    $at = (int) strpos($source, ' function '.$method.'(');

    expect($at)->toBeGreaterThan(0, $method.'() is gone from the controller');

    $end = (int) strpos($source, "\n    }", $at);

    return substr($source, $at, $end - $at);
}

/* ══════════════ 1. the column reaches the shop, which is the trap ═════════ */

it('hydrates the phone picture through forHome, which a new column usually is not', function () {
    /*
     * THE DEFECT THIS CATCHES, and it is the one this table has shipped before.
     * Banners::load() selects a NAMED LIST of columns. A column added to
     * `banner_cards` and not added to that list reaches the admin screen and
     * the preview — which read the model — and silently does nothing on the
     * shop. `kind` shipped exactly that way once: a slider that drew as cards
     * on the storefront and as a slider in the console.
     *
     * A phone picture with that fault would be worse, because the owner could
     * only find it by looking at his own live site on his own handset.
     *
     * MUTATION, run: remove 'image_m' from the foreach in Banners::load() and
     * this is red — `image_m` arrives as null and hasPhonePicture() is false on
     * a row that has one.
     */
    [, $cards] = bppSet(['image_m' => 'uploads/banners/bpp-phone.jpg', 'image_m_w' => 500, 'image_m_h' => 600]);

    expect($cards[0]->image_m)->toBe('uploads/banners/bpp-phone.jpg')
        ->and($cards[0]->image_m_w)->toBe(500)
        ->and($cards[0]->image_m_h)->toBe(600)
        ->and($cards[0]->hasPhonePicture())->toBeTrue();
});

/* ══════════════════════════ 2. with a phone picture ══════════════════════ */

it('draws the phone picture below 768px and the desktop one above, from one picture element', function () {
    $html = bppRender(['image_m' => 'uploads/banners/bpp-phone.jpg', 'image_m_w' => 500, 'image_m_h' => 600]);

    expect(substr_count($html, '<picture>'))->toBe(1, 'the phone picture is not wrapped, or is wrapped twice')
        ->and(substr_count($html, '</picture>'))->toBe(1)
        ->and(substr_count($html, '<source media='))->toBe(1);

    // The source names the PHONE file and the img names the DESKTOP one. Either
    // way round is a banner that is wrong on every screen rather than one.
    $source = (string) (preg_match('#<source media=.*?>#s', $html, $m) ? $m[0] : '');

    expect($source)->toContain('bpp-phone.jpg')
        ->and($source)->not->toContain('bpp-desktop.jpg')
        ->and($source)->toContain('media="(max-width: 767.98px)"');

    $img = (string) (preg_match('#<img [^>]*>#s', $html, $m) ? $m[0] : '');

    expect($img)->toContain('bpp-desktop.jpg')
        ->and($img)->not->toContain('bpp-phone.jpg');

    /*
     * AND BOTH `sizes` ARE THE FLAT EXPRESSION, which is the whole point of
     * uploading a second picture: a 500 x 600 source in a 500 / 600 frame is
     * WIDTH-BOUND, so the frame's own width is the right answer and nothing has
     * to be over-asked for. The blur is gone because the shapes agree, not
     * because a number was inflated.
     *
     * MUTATION: give the phone card image_m_w 1920 / image_m_h 550 and the
     * source's sizes becomes `min(100vw * 4.2, 2400px)` — the fallback's
     * answer, correctly, because that is a landscape picture in a portrait
     * frame however it got there.
     */
    expect($source)->toContain('sizes="min(100vw, 2400px)"')
        // The <img> carries the DESKTOP term alone and no phone condition: with
        // a source element winning below 768px, a phone term here would
        // describe a frame this element is never measured against.
        ->and($img)->toContain('sizes="min(100vw, 2400px)"')
        ->and($img)->not->toContain('max-width');
});

it('preloads one file per breakpoint, each scoped so only one is ever fetched', function () {
    /*
     * TWO TAGS, ONE FETCH. The LCP element is a different FILE depending on the
     * viewport, so a single unconditioned preload would fetch the desktop
     * 1920px file on the phone — the connection least able to afford it — and
     * then fetch the phone one as well when the parser reached the <source>.
     * `media` on each is what makes the browser act on exactly one.
     *
     * MUTATION, run: drop the `media` attribute from either link and this is
     * red on the count of scoped tags, at 1 rather than 2.
     */
    $html = bppRender(['image_m' => 'uploads/banners/bpp-phone.jpg', 'image_m_w' => 500, 'image_m_h' => 600]);

    $head = (string) (preg_match_all('#<link rel="preload" as="image"[^>]*>#', $html, $m) ? implode("\n", $m[0]) : '');

    expect(substr_count($head, 'rel="preload" as="image"'))->toBe(2, 'a phone slide does not preload one file per breakpoint');
    expect(substr_count($head, ' media='))->toBe(2, 'a preload is unscoped, so both files are fetched on one screen');

    expect($head)->toContain('media="(max-width: 767.98px)"')
        ->and($head)->toContain('media="(min-width: 768px)"');

    // And each names the file its own breakpoint will paint.
    $lines = explode("\n", $head);
    $phone = $lines[0];
    $desktop = $lines[1];

    expect($phone)->toContain('bpp-phone.jpg')->and($phone)->toContain('max-width');
    expect($desktop)->toContain('bpp-desktop.jpg')->and($desktop)->toContain('min-width');
});

it('uses one breakpoint for the source, the preload and the frame', function () {
    /*
     * THE DEFECT THIS EXISTS FOR, and it shows on exactly one width so nobody
     * finds it by looking. The frame's ratio switches at `min-width:768px`. If
     * the <source> said `max-width:767px` a viewport of 767.5 — a zoomed
     * desktop, and several Android handsets, which report fractional CSS pixel
     * widths — would match NEITHER: it would get the desktop picture in the
     * portrait frame, which is the cropped, soft banner this whole round is
     * about, on the one width nobody tests at.
     *
     * MUTATION, run: change `$bsPhoneQuery` to '(max-width: 767px)' and this is
     * red on the first expectation.
     */
    $partial = (string) file_get_contents(resource_path('views/partials/home/slider-banner.blade.php'));

    expect(substr_count($partial, "\$bsPhoneQuery = '(max-width: 767.98px)';"))->toBe(1, 'the phone breakpoint is not declared once');

    // The frame's own rule, which is the thing the query has to be the
    // complement of.
    expect(str_contains($partial, '@media (min-width:768px){.kbbs-vp{aspect-ratio:var(--kbbs-ar,'))->toBeTrue(
        'the frame no longer switches shape at 768px, so the phone query may be the wrong complement'
    );

    // And nothing spells a second breakpoint by hand.
    expect(substr_count($partial, 'max-width: 767px'))->toBe(0, 'a second, different phone breakpoint is spelled somewhere');
});

/* ═══════════════════ 3. without one: the fallback, measured ══════════════ */

it('leaves a slide with no phone picture drawing exactly what it drew', function () {
    /*
     * RULE 1. Every slide on every shop the day this applies has no phone
     * picture, so this is the case that covers the whole catalogue: no
     * <picture>, no <source>, one preload with no media, and the same file.
     *
     * MUTATION, run: emit the <picture> wrapper unconditionally and this is red
     * on the first count — which matters because a wrapper on every slide is a
     * changed page for a shop that has configured nothing.
     */
    $html = bppRender();

    expect(substr_count($html, '<picture>'))->toBe(0, 'a slide with no phone picture gained a wrapper')
        ->and(substr_count($html, '<source'))->toBe(0)
        ->and(substr_count($html, 'rel="preload" as="image"'))->toBe(1, 'a slide with one file preloads more than one')
        ->and(substr_count($html, ' media='))->toBe(0, 'an unnecessary media scope on a single-file slide');

    $img = (string) (preg_match('#<img [^>]*>#s', $html, $m) ? $m[0] : '');

    expect($img)->toContain('bpp-desktop.jpg');
});

it('asks for the width that covering the phone frame actually needs, not the frame width', function () {
    /*
     * ── THE ONE DECISION IN THIS ROUND, AND IT IS A TRADE ───────────────────
     *
     * A slide with no phone picture shows its DESKTOP picture in the portrait
     * phone frame. `object-fit: cover` crops it to about a quarter of its
     * width, and that is not changed here — it cannot be, with one file.
     *
     * What IS changed is what the browser is asked for. The covered width is
     *
     *     frameWidth x max(1, sourceAspect / frameAspect)
     *
     * which for 1920 x 550 in a 500 / 600 frame is 4.2 x the frame — 1533 CSS
     * pixels against the 390 the flat expression declared. So the phone was
     * being handed the 400w copy for a 1533-pixel box: soft on top of cropped.
     *
     * HEAVY BEATS SOFT HERE, and that is the judgement. The alternative is to
     * leave the flat expression and serve a blurry banner, and a shop that has
     * not uploaded a phone picture is being shown a crop it did not choose
     * EITHER WAY — so the version that at least looks right is the better of
     * two wrong answers. It is also self-limiting: the moment a phone picture
     * exists the factor collapses to 1 and the phone downloads a 500 x 600
     * file, which is far smaller than anything the old code ever served it.
     *
     * MUTATION, run: make bannerSliderCoverSizes() return the flat expression
     * unconditionally and this is red on the first expectation.
     */
    $html = bppRender();
    $img = (string) (preg_match('#<img [^>]*>#s', $html, $m) ? $m[0] : '');

    /*
     * ONE `sizes`, TWO FRAMES. With no phone picture there is no source element
     * for a phone term to live on, so the <img>'s own attribute carries a media
     * condition — which is what `sizes` is for — and the condition is the same
     * constant everything else in this section uses.
     */
    expect($img)->toContain('sizes="(max-width: 767.98px) min(100vw * 4.2, 2400px), min(100vw, 2400px)"');

    // The DESKTOP frame is unaffected: 1920 x 550 in a 1920 / 550 frame is
    // width-bound, the factor is 1, and a landscape banner on a laptop asks for
    // exactly what it always asked for.
    expect(ImageVariants::bannerSliderCoverSizes(1920, 550, 1920 / 550))->toBe('min(100vw, 2400px)');
});

it('collapses to the flat expression for every shape this shop had before', function () {
    /*
     * WHY THIS IS SAFE TO APPLY EVERYWHERE. A source no wider than its frame is
     * width-bound and `max(1, ...)` collapses, so the attribute is the string
     * that was returned before, byte for byte. PerfDeliveryTest's 810 x 1440
     * fixture is such a case in BOTH frames, which is why that file did not
     * move — asserted here rather than left as a happy accident.
     *
     * The unknown-dimensions row is the one that would otherwise be a guess:
     * NULL is what `image_w` holds for a picture whose header could not be
     * read, and multiplying by a guessed aspect would put a number in the
     * attribute that nothing measured.
     */
    $flat = ImageVariants::bannerSliderSizesAttribute();

    foreach ([
        'a portrait source in the phone frame' => [810, 1440, 500 / 600],
        'a portrait source in the desktop frame' => [810, 1440, 1920 / 550],
        'the phone picture in its own frame' => [500, 600, 500 / 600],
        'unknown width' => [null, 600, 500 / 600],
        'unknown height' => [500, null, 500 / 600],
        'a nonsense frame' => [1920, 550, 0.0],
    ] as $why => [$w, $h, $frame]) {
        expect(ImageVariants::bannerSliderCoverSizes($w, $h, $frame))->toBe($flat, $why.' stopped using the flat expression');
    }

    /*
     * AND THE CASES THAT MUST NOT COLLAPSE, or the loop above is a tautology.
     *
     * The SQUARE is the one worth having and it was a mistake in the first
     * draft of this case, listed above as collapsing. It does not: 1.0 is wider
     * than 5 : 6, so a square picture is HEIGHT-bound in the phone frame too
     * and needs 1.2x the frame's width. The boundary is the frame's own ratio,
     * not "landscape or portrait", and getting that wrong is exactly the error
     * the flat expression made in the first place.
     */
    expect(ImageVariants::bannerSliderCoverSizes(1920, 550, 500 / 600))->toBe('min(100vw * 4.2, 2400px)')
        ->and(ImageVariants::bannerSliderCoverSizes(1000, 1000, 500 / 600))->toBe('min(100vw * 1.2, 2400px)');
});

/* ═══════════════════════ 4. the shapes come from the set ═════════════════ */

it('follows the set own frame shapes rather than the shipped numbers', function () {
    /*
     * The arithmetic reads BannerSet::sliderRatioMobileValue(), which parses the
     * CSS the frame is laid out with — so an owner who picks a different phone
     * shape gets a `sizes` computed for the shape he picked. Parsed from the
     * VALUE and not the key, so a preset whose label ever disagreed with its
     * pixels would follow the pixels.
     *
     * MUTATION: make sliderRatioMobileValue() return the shipped 500/600
     * regardless and this is red — the 4 / 5 frame keeps the 500/600 answer.
     */
    $set = new BannerSet;
    $set->forceFill(['slider_ratio' => '1920/550', 'slider_ratio_m' => '4/5']);

    expect(round($set->sliderRatioMobileValue(), 4))->toBe(0.8)
        ->and(round($set->sliderRatioValue(), 4))->toBe(round(1920 / 550, 4));

    // 1920/550 in a 4:5 frame: 3.4909 / 0.8 = 4.4.
    expect(ImageVariants::bannerSliderCoverSizes(1920, 550, $set->sliderRatioMobileValue()))
        ->toBe('min(100vw * 4.4, 2400px)');

    // And a set with no valid ratio at all cannot divide by zero on the front
    // page: ratioValue() answers 0.0 and the arithmetic declines to compute.
    $broken = new BannerSet;
    $broken->forceFill(['slider_ratio_m' => 'not-a-ratio']);

    expect($broken->sliderRatioMobileValue())->toBeGreaterThan(0.0);
});

/* ═════════════════════ 5. the write path, both pictures ═════════════════ */

it('writes and clears the phone picture through the same gate as the desktop one', function () {
    /*
     * ONE LOOP FOR TWO PICTURES in BannerApiController::fillCard(), so the
     * phone one cannot take a URL, an absolute path or a scheme this shop has
     * not checked — and clearing it clears its measured size with it, or the
     * markup prints a width and height for a picture that is no longer there.
     *
     * MUTATION, run: drop `image_m` from the loop's array and the first block
     * is red; leave the size columns out of the clearing branch and the second
     * is.
     */
    $card = new BannerCard;
    $card->forceFill(['image_m' => 'uploads/banners/x.jpg', 'image_m_w' => 500, 'image_m_h' => 600]);

    expect($card->hasPhonePicture())->toBeTrue();

    $card->forceFill(['image_m' => '']);

    expect($card->hasPhonePicture())->toBeFalse();

    $controller = (string) file_get_contents(
        (new ReflectionClass(\App\Http\Controllers\Admin\BannerApiController::class))->getFileName()
    );

    expect($controller)->toContain("'image_m' => ['image_m_w', 'image_m_h'],")
        ->and($controller)->toContain("'image_m' => ['sometimes', 'nullable', 'string', 'max:400'],")
        // Written ONCE inside fillCard(), through the loop — a second
        // hand-rolled block is the copy that drifts, and it is the phone half
        // that would drift because nobody looks at it. Scoped to the method
        // rather than to the file: MediaRegistrar::record() is called from four
        // other places in this controller and counting them all reads 5 for a
        // correct file.
        // The needle is the ASSIGNMENT and not the bare call: the method's own
        // docblock names MediaRegistrar::record() in prose, so the short needle
        // reads 2 for a correct file — the ambiguous-needle class again, and
        // the third time this lane has paid for it.
        ->and(substr_count(bppMethodBody($controller, 'fillCard'), '$media = MediaRegistrar::record($path);'))->toBe(1);

    /*
     * A PHONE PICTURE ALONE IS NOT DRAWABLE. The storefront's filter is
     * `image <> ''`, so a slide carrying only a phone picture draws nothing at
     * all rather than half of itself on one breakpoint.
     */
    $phoneOnly = new BannerCard;
    $phoneOnly->forceFill(['image' => '', 'image_m' => 'uploads/banners/x.jpg']);

    expect($phoneOnly->drawable())->toBeFalse();
});
