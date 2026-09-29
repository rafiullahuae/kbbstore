<?php

declare(strict_types=1);

use App\Models\BannerCard;
use App\Models\BannerSet;
use App\Models\UgcVideo;
use App\Services\Banners;
use App\Services\Ugc\Tile;
use App\Support\ImageVariants;
use App\Support\WebFonts;

/**
 * Lane PERF — the delivery of the homepage, audit by audit.
 *
 * Every case here names a line from the PageSpeed Insights report the owner ran
 * against extrabeauty.ae on 29 September 2026, and every one of them goes red
 * with the fix taken out. The mutation that proves it is written above the
 * case, because a test nobody has broken on purpose is a test nobody knows
 * asserts anything.
 *
 * WHAT IS DELIBERATELY NOT HERE. The Lighthouse SCORES. They are a property of
 * a browser on a network, they are measured in tools/perf-run.sh and recorded
 * in docs/PERF-PAGESPEED.md, and an assertion on them in this suite would be a
 * test of how busy this machine is. What is here is the thing the score was a
 * symptom of: which hosts the page asks for, which sizes it asks for, and
 * whether it asks for a file that is not there.
 */

/* ── helpers ─────────────────────────────────────────────────────────────── */

/** A published set of $n cards, rendered exactly as the homepage draws it. */
function perfBannerHtml(int $n = 3, array $setAttributes = []): string
{
    BannerCard::query()->delete();
    BannerSet::query()->delete();

    $set = BannerSet::create($setAttributes + [
        'name' => 'Perf', 'slug' => 'perf-'.uniqid(), 'status' => 'publish', 'position' => 0,
    ]);

    foreach (range(1, $n) as $i) {
        BannerCard::create([
            'banner_set_id' => $set->id,
            'image' => 'uploads/posters/perf-'.$i.'.jpg',
            'alt' => 'Alt '.$i,
            'heading' => 'Heading '.$i,
            'body' => 'Body '.$i,
            'button_label' => 'Shop',
            'button_url' => '/shop/',
            'image_w' => 810,
            'image_h' => 1440,
            'position' => $i,
            'status' => 'publish',
        ]);
    }

    $loaded = app(Banners::class)->forPreview($set->id);
    expect($loaded)->not->toBeNull();

    return view('partials.home.cards-banner', ['set' => $loaded[0], 'cards' => $loaded[1]])->render();
}

/** The rendered partial with its inline <style> removed — see CardsBannerSectionShapeTest. */
function perfBannerMarkup(int $n = 3, array $setAttributes = []): string
{
    return (string) preg_replace('#<style>.*?</style>#s', '', perfBannerHtml($n, $setAttributes));
}

/** Write a real JPEG under the web root and its phone-sized copies. */
function perfMakeImage(string $relative, int $w = 810, int $h = 1440): void
{
    $file = public_path($relative);
    @mkdir(\dirname($file), 0775, true);

    $img = imagecreatetruecolor($w, $h);
    imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, 220, 180, 195));
    imagejpeg($img, $file, 70);
    imagedestroy($img);
}

function perfRemoveImage(string $relative): void
{
    @unlink(public_path($relative));

    foreach (ImageVariants::WIDTHS as $width) {
        @unlink(public_path(ImageVariants::DIR.'/'.$width.'/'.$relative));
    }
}

/** The same set drawn as a SLIDER, which is the other banner kind. */
function perfSliderMarkup(int $n = 3, array $setAttributes = []): string
{
    BannerCard::query()->delete();
    BannerSet::query()->delete();

    $set = BannerSet::create($setAttributes + [
        'name' => 'Perf slider', 'slug' => 'perf-s-'.uniqid(),
        'status' => 'publish', 'position' => 0, 'kind' => 'slider',
    ]);

    foreach (range(1, $n) as $i) {
        BannerCard::create([
            'banner_set_id' => $set->id,
            'image' => 'uploads/posters/perf-'.$i.'.jpg',
            'alt' => 'Alt '.$i,
            'image_w' => 810,
            'image_h' => 1440,
            'position' => $i,
            'status' => 'publish',
        ]);
    }

    $loaded = app(Banners::class)->forPreview($set->id);
    expect($loaded)->not->toBeNull();
    expect($loaded[0]->homePartial())->toBe('partials.home.slider-banner');

    $html = view($loaded[0]->homePartial(), ['set' => $loaded[0], 'cards' => $loaded[1]])->render();

    return (string) preg_replace('#<style>.*?</style>#s', '', $html);
}

/* ── 1. the fonts ────────────────────────────────────────────────────────── */

/*
 * "Network dependency tree — Maximum critical path latency: 4,369 ms", all of
 * it the two Google origins.
 *
 * MUTATION: put the two <link> back in layouts/store.blade.php and this is red
 * on the first assertion.
 */
it('asks no third-party origin for a font on an English page', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->not->toContain('fonts.googleapis.com')
        ->and($html)->not->toContain('fonts.gstatic.com');
});

/*
 * The Arabic page is the other half and it was the one at risk: the preconnect
 * hints that made Cairo's request cheap belonged to the Poppins block, so
 * removing them and leaving Cairo on Google would have made /ar/ SLOWER.
 *
 * MUTATION: drop the WebFonts::CAIRO call from the @if branch in the layout and
 * the Arabic page loses its face table — red on the second assertion.
 */
it('asks no third-party origin for a font on an Arabic page either', function () {
    \App\Models\Setting::query()->updateOrCreate(['key' => \App\Support\Locale::SETTING_ENABLED], ['value' => '1']);
    \App\Services\SettingsService::forgetMemo();

    $html = $this->get('/ar/')->assertOk()->getContent();

    expect($html)->not->toContain('fonts.googleapis.com')
        ->and($html)->not->toContain('fonts.gstatic.com')
        ->and($html)->toContain("font-family:'Cairo'");
});

/*
 * Cairo is on the ARABIC page and nowhere else. Rule 1: the English page pays
 * nothing for a feature it does not use, and it did not before this lane either
 * — the <link> was already inside the same @if.
 *
 * MUTATION: move the Cairo call outside the @if and this is red.
 */
it('does not put the Arabic face on an English page', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->not->toContain("font-family:'Cairo'")
        ->and($html)->toContain("font-family:'Poppins'");
});

/*
 * THE ONE THAT CATCHES A BROKEN SHOP RATHER THAN A SLOW ONE. A @font-face whose
 * file is not in the build is a page with no brand face at all, and nothing
 * about the HTML would look wrong — this is exactly the failure mode that makes
 * self-hosting worse than a CDN when it is done carelessly.
 *
 * It checks the MANIFEST and the DISK, not just the manifest: `npx vite build`
 * is manual here (CLAUDE.md, Known gaps) and public/build is committed, so the
 * two can disagree.
 *
 * MUTATION: rename one file in resources/fonts/poppins/ and rebuild — red.
 * Or delete one from public/build/assets/ without rebuilding — also red.
 */
it('has every declared face in the build manifest and on disk', function () {
    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);

    expect($manifest)->toBeArray();

    foreach (WebFonts::sources() as $source) {
        expect(isset($manifest[$source]['file']))->toBeTrue("no manifest entry for {$source}");
        expect(file_exists(public_path('build/'.$manifest[$source]['file'])))
            ->toBeTrue("built font missing: {$manifest[$source]['file']}");
        expect(file_exists(base_path($source)))->toBeTrue("source font missing: {$source}");
    }
})->skip(fn () => ! file_exists(public_path('build/manifest.json')), 'no built assets in this checkout');

/*
 * The other direction, and the one a later lane will actually trip: a face
 * added to the table but not to vite.config.js is in the CSS and not in the
 * build. Vite::asset() would throw at render time — on every page of the shop.
 *
 * MUTATION: delete one 'resources/fonts/...' line from vite.config.js — red.
 */
it('lists every face file as a vite input', function () {
    $config = (string) file_get_contents(base_path('vite.config.js'));

    foreach (WebFonts::sources() as $source) {
        expect(str_contains($config, "'".$source."'"))
            ->toBeTrue("vite.config.js does not build {$source}");
    }
});

/*
 * Twelve faces, four preloads, three Cairo files behind twelve Cairo rules.
 * The counts are the claim "the same font, byte for byte" made checkable: a
 * subset dropped to save repository weight changes which glyphs a codepoint
 * resolves to, and that is the exception rule 1 does not allow.
 *
 * MUTATION: remove the four devanagari rows from POPPINS_FACES — red.
 */
it('carries every face Google serves, and preloads only the ones the page uses', function () {
    expect(WebFonts::faces(WebFonts::POPPINS))->toHaveCount(12)
        ->and(WebFonts::faces(WebFonts::CAIRO))->toHaveCount(12);

    // Cairo is variable: twelve rules, three files.
    $cairoFiles = array_unique(array_column(WebFonts::faces(WebFonts::CAIRO), 'file'));
    expect($cairoFiles)->toHaveCount(3);

    expect(substr_count(WebFonts::preloadTags(WebFonts::POPPINS), '<link'))->toBe(4)
        ->and(substr_count(WebFonts::preloadTags(WebFonts::CAIRO), '<link'))->toBe(1);

    // crossorigin, on every one of them. A preload whose mode does not match
    // the fetch that follows is downloaded twice, which is slower than no
    // preload at all.
    foreach ([WebFonts::POPPINS, WebFonts::CAIRO] as $family) {
        $tags = WebFonts::preloadTags($family);
        expect(substr_count($tags, 'crossorigin'))->toBe(substr_count($tags, '<link'));
    }
});

/* ── 2. the banner's pictures ────────────────────────────────────────────── */

/*
 * "Improve image delivery — Est savings of 276 KiB", three files of this
 * section's own, each an 810x1440 original in a 298x529 box.
 *
 * MUTATION: delete the srcset/sizes attributes from the <img> in
 * partials/home/cards-banner.blade.php — red.
 */
it('offers the phone-sized copies of a banner picture when they exist', function () {
    perfMakeImage('uploads/posters/perf-1.jpg');
    ImageVariants::generate('/uploads/posters/perf-1.jpg');

    $markup = perfBannerMarkup(1);

    expect($markup)->toContain('/img-cache/400/uploads/posters/perf-1.jpg 400w')
        ->and($markup)->toContain('sizes="(max-width: 519px)')
        // The original is a candidate too, at its real width: this box reaches
        // 669 CSS pixels at per_view 2 and the copies stop at 800w.
        ->and($markup)->toContain('/uploads/posters/perf-1.jpg 810w');

    perfRemoveImage('uploads/posters/perf-1.jpg');
});

/*
 * RULE 1, AND IT IS THE HALF OF THIS CHANGE THAT COULD HAVE GONE WRONG
 * SILENTLY. A shop that has never run Content → Media Library → "Make
 * phone-sized copies" must emit the markup it emitted yesterday, to the byte —
 * no empty srcset, no empty sizes, no stray space where an attribute would be.
 *
 * MUTATION: drop the `$bnSrcset !== ''` guard from the @if in the template and
 * this is red with `srcset=""` in the markup.
 */
it('emits no srcset at all for a picture with no copies on disk', function () {
    $markup = perfBannerMarkup(1);

    expect($markup)->not->toContain('srcset')
        ->and($markup)->not->toContain('sizes=');
});

/*
 * THE DEFECT rootRelative() EXISTS FOR, measured rather than described.
 * `banner_cards.image` is stored bare because MediaRegistrar::normalise() ends
 * with ltrim($path, '/'), and ImageVariants::split() refuses a bare relative
 * path — so the obvious call answers '' and a reader concludes the copies are
 * missing.
 *
 * MUTATION: call detailSrcsetFor($bnCard->image) directly in the template
 * instead of through rootRelative() and the srcset case above goes red.
 */
it('reads a stored image column, which split() refuses without a leading slash', function () {
    perfMakeImage('uploads/posters/perf-bare.jpg');

    $stored = 'uploads/posters/perf-bare.jpg';   // exactly what the column holds

    expect(ImageVariants::generate($stored)['reason'])->toBe('not a local image');
    expect(ImageVariants::generate(ImageVariants::rootRelative($stored))['made'])->toBe(3);
    expect(ImageVariants::srcsetFor($stored))->toBe('');
    expect(ImageVariants::srcsetFor(ImageVariants::rootRelative($stored)))->not->toBe('');

    // A remote address is refused rather than turned into a local-looking path.
    expect(ImageVariants::rootRelative('https://other.test/x.jpg'))->toBe('https://other.test/x.jpg');

    perfRemoveImage('uploads/posters/perf-bare.jpg');
});

/*
 * The `sizes` arithmetic, against the four media queries in the partial's own
 * stylesheet. These are the widths the layout actually draws, checked by hand
 * off `.kbb-home .sec > .wrap` and `.kbbn-c`, not a figure rounded up.
 *
 * MUTATION: change the 1.5 in the first band to 1 — red.
 */
it('states the card width the stylesheet draws', function () {
    $sizes = ImageVariants::bannerCardSizesAttribute(4, 38, 16);

    expect($sizes)->toContain('(max-width: 519px) calc((100vw - 40px) / 1.5)')
        ->and($sizes)->toContain('(max-width: 680px) calc((100vw - 56px) / 2.45)')
        ->and($sizes)->toContain('(max-width: 767px) calc((100vw - 94px) / 2.45)')
        ->and($sizes)->toContain('(max-width: 1023px) calc((min(100vw - 24px, 1680px) - 86px) / 3.4)')
        ->and($sizes)->toContain('calc((min(100vw - 24px, 1680px) - 102px) / 4.38)');

    /*
     * THE ARITHMETIC AGAINST THE BROWSER, not against the stylesheet.
     *
     * Read off the CSS the first time, this method understated the card by
     * 10% at 390px — `@media(max-width:680px)` takes the homepage section's
     * card frame off entirely and the reading missed it. These seventeen rows
     * are `.kbbn-c`'s own rectangle, measured in Chromium by
     * storage/perf-logs/measure-kbbn.cjs, and the rule is that the declared
     * figure is never BELOW the drawn one: understating is what leaves a
     * photograph soft, and the browser never looks at `src` again once it has
     * chosen from a srcset.
     *
     * MUTATION: put 1 back where the 1.5 is in the first band and this is red
     * at 360px.
     */
    $declared = static function (int $vw) use (&$declared): float {
        $gap = 16;

        if ($vw <= 519) {
            return (float) ($vw - 24 - $gap) / 1.5;
        }
        if ($vw <= 680) {
            return (float) ($vw - 24 - 2 * $gap) / 2.45;
        }
        if ($vw <= 767) {
            return (float) ($vw - 62 - 2 * $gap) / 2.45;
        }

        $frame = min($vw - 24, 1680);

        return $vw <= 1023
            ? (float) ($frame - 38 - 3 * $gap) / 3.4
            : (float) ($frame - 38 - 4 * $gap) / 4.38;
    };

    $drawn = [
        360 => 213.33, 390 => 233.33, 412 => 248.00, 519 => 319.33,
        520 => 189.38, 600 => 222.03, 767 => 274.69, 768 => 193.52,
        900 => 232.34, 1023 => 267.08, 1024 => 203.89, 1280 => 260.00,
        1350 => 275.33, 1440 => 295.42, 1680 => 350.22, 1920 => 355.70,
        2560 => 355.70,
    ];

    foreach ($drawn as $vw => $measured) {
        $value = $declared($vw);

        expect($value)->toBeGreaterThanOrEqual($measured - 0.02,
            "sizes understates the card at {$vw}px: says {$value}, draws {$measured}");
        // And not wildly over, which would fetch a candidate nobody needs.
        expect($value)->toBeLessThanOrEqual($measured * 1.02,
            "sizes overstates the card at {$vw}px: says {$value}, draws {$measured}");
    }

    // Two big cards is the case srcsetFor's 800w ceiling could not serve:
    // (1622 - 32) / 2.45 = 649 CSS px, 1298 at device-pixel-ratio 2.
    expect(ImageVariants::bannerCardSizesAttribute(2, 45, 16))
        ->toContain('calc((min(100vw - 24px, 1680px) - 70px) / 2.45)');
});

/*
 * AND THE TEMPLATE'S CLAMP AGREES WITH THE ONE THAT WRITES THE CSS VARIABLE.
 * The partial clamps per_view itself to build `sizes`; Banners::cssVariables()
 * clamps it again to write `--kbbn-per-lg`. Two clamps that disagree would
 * describe a card the browser does not draw, and `sizes` is the one attribute
 * where being wrong in the small direction is visible.
 *
 * MUTATION: change the template's min()/max() bounds to 1..4 and this is red
 * at per_view 8.
 */
it('describes the same column count the stylesheet is given', function () {
    perfMakeImage('uploads/posters/perf-1.jpg');
    ImageVariants::generate('/uploads/posters/perf-1.jpg');

    foreach ([0, 1, 4, 8, 99] as $perView) {
        $html = perfBannerHtml(1, ['per_view' => $perView]);

        expect($html)->toMatch('/--kbbn-per-lg:\d+/');
        preg_match('/--kbbn-per-lg:(\d+)/', $html, $perMatch);
        preg_match('/--kbbn-peek-lg:([\d.]+)/', $html, $peekMatch);

        $declared = (int) $perMatch[1];
        $divisor = number_format($declared + (float) $peekMatch[1], 2, '.', '');

        // The `sizes` attribute's last band has to divide by the SAME number
        // the stylesheet is handed, or it describes a card the browser is not
        // drawing.
        expect($html)->toContain('/ '.$divisor.')');
    }

    perfRemoveImage('uploads/posters/perf-1.jpg');
});

/*
 * THE SAME DEFECT, IN THE OTHER BANNER KIND. The slider shipped with `src`
 * alone while the cards banner was being fixed, and its frame is the WIDER of
 * the two: one picture at the full width of the content column, 1236 CSS
 * pixels at the shipped site width against the cards banner's 669. An 810x1440
 * original was downloaded whole into a 346px box on a phone.
 *
 * The preload is asserted as well as the <img>, and that half matters more
 * than it looks: a `<link rel=preload as=image>` with no `imagesrcset` asks
 * for the ORIGINAL, so a fixed <img> beside an unfixed preload downloads two
 * files instead of one and the section ends up slower than before the fix.
 *
 * MUTATION: delete the srcset/sizes attributes from the <img> in
 * partials/home/slider-banner.blade.php — red on the third expectation; delete
 * imagesrcset/imagesizes from the <link> — red on the first.
 */
it('offers the phone-sized copies of a slider picture, on the preload and on the slide', function () {
    perfMakeImage('uploads/posters/perf-1.jpg');
    ImageVariants::generate('/uploads/posters/perf-1.jpg');

    $markup = perfSliderMarkup(1);

    expect($markup)->toContain('imagesrcset="/img-cache/200/uploads/posters/perf-1.jpg 200w')
        ->and($markup)->toContain('/img-cache/400/uploads/posters/perf-1.jpg 400w')
        ->and($markup)->toContain('/img-cache/800/uploads/posters/perf-1.jpg 800w')
        ->and($markup)->toContain('imagesizes="min(100vw, 2400px)"')
        ->and($markup)->toContain(' srcset="/img-cache/200/uploads/posters/perf-1.jpg 200w')
        ->and($markup)->toContain(' sizes="min(100vw, 2400px)"')
        // The original is a candidate at its real width, the copies stopping at
        // 800w — this frame is wider than any of them on a laptop.
        ->and($markup)->toContain('/uploads/posters/perf-1.jpg 810w');

    perfRemoveImage('uploads/posters/perf-1.jpg');
});

/*
 * RULE 1 for the slider, the same way it is asserted for the cards banner: a
 * shop that has never made phone-sized copies emits the markup it emitted
 * yesterday, to the byte.
 *
 * MUTATION: drop the `$bsSrcset !== ''` guard from the @if in the template and
 * this is red with `srcset=""` in the markup; drop the `$bsLcpSrcset !== ''`
 * guard and it is red with `imagesrcset=""`.
 */
it('emits no srcset at all for a slider picture with no copies on disk', function () {
    $markup = perfSliderMarkup(1);

    expect($markup)->not->toContain('srcset')     // covers imagesrcset too
        ->and($markup)->not->toContain('sizes=');
});

/*
 * THE SLIDER'S `sizes`, AND WHY IT IS ONE FLAT EXPRESSION.
 *
 * The frame is `min(100vw, --site-max) - 2 * --site-gutter`, and BOTH of those
 * are owner settings — SiteLayout's `max` is a range 1040..2400 and `gutter` is
 * 8..48. `var()` is not a length to the HTML parser, so neither can be named in
 * a `sizes` attribute; a dropped entry defaults to 100vw. The expression is
 * therefore written at the ends that can only OVERSTATE: the widest site the
 * screen can be set to, and no gutter at all.
 *
 * Overstating is the safe direction and understating is the one that shows —
 * the browser picks a file too small for the frame and the owner's banner comes
 * out soft. This case pins the direction rather than the string, which is the
 * property that has to hold when somebody edits the number.
 *
 * MUTATION: return 'min(100vw, 1680px)' from bannerSliderSizesAttribute() —
 * red, because 1680 is under the 2400 the screen allows.
 */
it('never states a slider frame narrower than the settings can make it', function () {
    $sizes = ImageVariants::bannerSliderSizesAttribute();

    // No media query and no calc(): one box at every width.
    expect($sizes)->not->toContain('max-width:')
        ->and($sizes)->not->toContain('calc(')
        ->and($sizes)->not->toContain('var(');

    $widestSite = (int) (\App\Services\SiteLayout::SCHEMA['max'][4]['max'] ?? 0);
    $narrowestGutter = (int) (\App\Services\SiteLayout::SCHEMA['gutter'][4]['min'] ?? -1);

    expect($widestSite)->toBe(2400)
        ->and($narrowestGutter)->toBe(8)
        ->and($sizes)->toBe('min(100vw, '.$widestSite.'px)');
});

/* ── 3. the LCP preload ──────────────────────────────────────────────────── */

/*
 * "LCP breakdown — Resource load delay 2,260 ms", with LCP request discovery
 * PASSING. The tag is 34 KB into a 51 KiB document on a 1,638 kb/s link.
 *
 * ONE, not zero and not two: zero is the defect, and two is a second full
 * download of the same photograph.
 *
 * MUTATION: delete the @push('head') block from cards-banner.blade.php — red.
 */
it('names the first banner picture in the head of the homepage', function () {
    perfMakeImage('uploads/posters/perf-lcp.jpg');
    ImageVariants::generate('/uploads/posters/perf-lcp.jpg');

    BannerCard::query()->delete();
    BannerSet::query()->delete();

    $set = BannerSet::create(['name' => 'LCP', 'slug' => 'lcp-'.uniqid(), 'status' => 'publish', 'position' => 0]);
    BannerCard::create([
        'banner_set_id' => $set->id, 'image' => 'uploads/posters/perf-lcp.jpg',
        'alt' => 'First', 'heading' => 'First', 'body' => '', 'button_label' => '', 'button_url' => '',
        'image_w' => 810, 'image_h' => 1440, 'position' => 0, 'status' => 'publish',
    ]);

    app(\App\Services\SettingsService::class)->setModule(Banners::MODULE, true);
    app(\App\Services\SettingsService::class)->setModuleSetting(Banners::MODULE, 'set', (string) $set->id);
    \App\Services\SettingsService::forgetMemo();

    $html = $this->get('/')->assertOk()->getContent();

    expect(substr_count($html, 'rel="preload" as="image"'))->toBe(1);

    // The preload is the same resource the element will want, or the browser
    // fetches the photograph twice.
    preg_match('/<link rel="preload" as="image"[^>]*imagesrcset="([^"]*)"/', $html, $pre);
    preg_match('/<img[^>]*perf-lcp[^>]*srcset="([^"]*)"/', $html, $img);

    expect($pre[1] ?? 'A')->toBe($img[1] ?? 'B');

    // And it is in <head>, which is the whole point: after </head> it would be
    // discovered no earlier than the <img> it duplicates.
    $head = substr($html, 0, (int) strpos($html, '</head>'));
    expect($head)->toContain('rel="preload" as="image"');

    perfRemoveImage('uploads/posters/perf-lcp.jpg');
});

/* ── 4. the console 404 ──────────────────────────────────────────────────── */

/*
 * "Browser errors were logged to the console — …ugc/poster-20….jpg 404", the
 * single audit that cost Best Practices its four points.
 *
 * MUTATION: return $poster unchanged from Tile::posterOnDisk() and this is red
 * on the first assertion.
 */
it('does not point at a cover file that is not on disk', function () {
    $present = UgcVideo::create([
        'slug' => 'perf-present', 'title' => 'Present', 'status' => 'publish',
        'rights_status' => 'granted', 'file_path' => '/uploads/ugc/perf-a.mp4',
        'poster_path' => '/uploads/ugc/perf-a.jpg', 'width' => 360, 'height' => 640,
        'published_at' => now()->subDay(),
    ]);
    $gone = UgcVideo::create([
        'slug' => 'perf-gone', 'title' => 'Gone', 'status' => 'publish',
        'rights_status' => 'granted', 'file_path' => '/uploads/ugc/perf-b.mp4',
        'poster_path' => '/uploads/ugc/perf-b.jpg', 'width' => 360, 'height' => 640,
        'published_at' => now()->subDay(),
    ]);

    perfMakeImage('uploads/ugc/perf-a.jpg', 360, 640);
    @unlink(public_path('uploads/ugc/perf-b.jpg'));

    $goneTile = Tile::fromVideo($gone, 'en');
    $presentTile = Tile::fromVideo($present, 'en');

    expect($goneTile['poster'])->toBeNull();

    // AND THE TILE STILL EXISTS. Dropping the clip instead would have traded a
    // console message for a missing tile, which is worse and is the failure
    // Tile::fromVideo's own header says it already had once.
    expect($goneTile)->not->toBeNull()
        ->and($goneTile['src'])->toBe('/uploads/ugc/perf-b.mp4');

    // A cover that IS there is untouched.
    expect($presentTile['poster'])->toBe('/uploads/ugc/perf-a.jpg');

    @unlink(public_path('uploads/ugc/perf-a.jpg'));
});

/* ── 5. the footer headings ──────────────────────────────────────────────── */

/*
 * "Heading elements are not in a sequentially-descending order — SHOP <h5>".
 *
 * MUTATION: put one <h5> back in partials/footer.blade.php — red.
 */
it('gives the footer columns headings that do not skip a level', function () {
    $html = $this->get('/')->assertOk()->getContent();

    preg_match_all('/<h([1-6])\b/', $html, $m);
    $levels = array_map('intval', $m[1]);

    expect($levels)->not->toBeEmpty();
    expect($levels[0])->toBe(1);

    foreach ($levels as $i => $level) {
        if ($i === 0) {
            continue;
        }

        // A decrease is always allowed; an increase of more than one is not.
        expect($level - $levels[$i - 1])->toBeLessThanOrEqual(1,
            "heading level jumped from h{$levels[$i - 1]} to h{$level}");
    }

    expect(substr_count($html, '<h5'))->toBe(0);
});

/*
 * THE THREE LABELS, BOUGHT BACK.
 *
 * EnglishRenderWalk's approved rules for this change cut
 * `<div class="fcol"><hN>[^<]*</hN>` out of both sides, and `[^<]*` swallows
 * the words with the tag — so that walk no longer sees somebody rewriting
 * "Shop", "Customer Care" or "My Account". A rule that cuts more than its
 * element has to say what it stopped watching, and this is the sharper form of
 * what it stopped watching: the shipped English, by tag, in order.
 *
 * MUTATION: change `store.footer.shop_heading` in InterfaceStrings and this is
 * red.
 */
it('keeps the footer column headings saying what they said', function () {
    $html = $this->get('/')->assertOk()->getContent();

    preg_match_all('#<div class="fcol"><h2>([^<]*)</h2>#', $html, $m);

    expect($m[1])->toBe(['Shop', 'Customer Care', 'My Account']);
});

/*
 * The stylesheet moved with the tag, or the footer headings are 1.5em serif-
 * weight black-on-black. This reads the SOURCE and the BUILT bundle, because
 * `npx vite build` is manual in this repository.
 *
 * MUTATION: revert the selector in resources/css/kbb/kbb.css to `.fcol h5` and
 * this is red.
 */
it('styles the footer heading the tag it now is', function () {
    $source = (string) file_get_contents(base_path('resources/css/kbb/kbb.css'));

    expect($source)->toContain('.fcol h2{font-size:12px')
        ->and($source)->not->toContain('.fcol h5{');

    $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
    $built = (string) file_get_contents(public_path('build/'.$manifest['resources/css/kbb/kbb.css']['file']));

    expect($built)->toContain('.fcol h2')
        ->and($built)->not->toContain('.fcol h5');
})->skip(fn () => ! file_exists(public_path('build/manifest.json')), 'no built assets in this checkout');
