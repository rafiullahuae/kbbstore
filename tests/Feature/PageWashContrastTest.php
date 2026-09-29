<?php

declare(strict_types=1);

use App\Services\PageWash;

/**
 * Appearance → Page background: the colour invariant, and the cost shape.
 *                                                                    (Lane BG)
 *
 * ── WHY THIS IS A TEST AND NOT A TABLE IN A DOCUMENT ───────────────────────
 *
 * A moving background under fixed text is a contrast trap: a pairing that
 * passes at one moment of the cycle can fail at another, and a screenshot of
 * one moment says nothing about the other two. A table of measurements in a
 * markdown file is a claim about the day it was written.
 *
 * So the whole reachable set is walked instead. `drift` and `intensity` are the
 * only two controls that change a colour, both are sliders with a step, and the
 * palettes are four fixed triples — so "every background this screen can
 * produce" is 21 × 21 × 4 × 9 = 15,876 colours and they can simply be
 * enumerated. The assertion is about all of them.
 *
 * ── THE BAR, AND WHY IT IS THIS ONE ────────────────────────────────────────
 *
 * The shop's background today is NOT white. kbb.css:1599 paints
 * `background-color:#FDEFF3` under a four-stop gradient whose darkest stop is
 * `#FCE7EE` — and on /shop and a product page kbb-shop.css and kbb-product.css
 * then override it back to white, which is a second finding and is reported
 * rather than fixed here.
 *
 * `#FCE7EE` is therefore the darkest flat background any storefront page
 * renders, and the bar is that NO SETTING ON THIS SCREEN MAY GO BELOW IT. Not
 * "meets AA" — two of the five text colours do not meet AA against the shop's
 * own background today, and moving those is a decision about the brand rather
 * than about a wash. What this lane can guarantee, and does, is that the wash
 * never costs a single point of contrast anywhere.
 *
 * MUTATION, run in this lane's worktree: change PALETTES['cream_blush_lilac']'s
 * third colour from '#F1EAFA' back to '#E9DFF6' — the value it had when this
 * lane first drew it — and the first case below fails with
 *
 *     cream_blush_lilac at drift 100, intensity 100 reaches #E9DFF6
 *     (luminance 0.7675), darker than the shop's own #FCE7EE (0.8402)
 *
 * which is exactly the regression it exists to catch: body text drops 13.11 →
 * 12.04, muted 3.14 → 2.88 and the pink accent 3.08 → 2.83, on every page.
 */

/* ═════════════════════════════════════════ the contrast floor ═══ */

it('cannot produce a background darker than the one the shop already renders', function () {
    $floor = PageWash::luminance(pwHex(PageWash::CONTRAST_FLOOR));
    $worst = [];
    $checked = 0;

    foreach (array_keys(PageWash::PALETTES) as $palette) {
        for ($drift = 0; $drift <= 100; $drift += 5) {
            for ($intensity = 0; $intensity <= 100; $intensity += 5) {
                $values = ['palette' => $palette, 'drift' => $drift, 'intensity' => $intensity];
                $stop = PageWash::darkestStop($values);
                $lum = PageWash::luminance($stop);
                $checked++;

                if ($lum + 1.0E-9 < $floor) {
                    $worst[] = sprintf(
                        '  %s at drift %d, intensity %d reaches #%02X%02X%02X (luminance %.4f),'
                        .' darker than the shop\'s own %s (%.4f)',
                        $palette, $drift, $intensity, $stop[0], $stop[1], $stop[2], $lum,
                        PageWash::CONTRAST_FLOOR, $floor
                    );
                }
            }
        }
    }

    // The check is blind if the enumeration ever stops enumerating.
    expect($checked)->toBe(4 * 21 * 21);

    expect($worst)->toBe([], "a slider position darkens the page below today's background:\n"
        .implode("\n", array_slice($worst, 0, 12)));
});

it('reports the worst case for every text colour, and beats today on all five', function () {
    /*
     * The five colours are kbb.css's own :root tokens. They are COPIED into
     * PageWash::TEXT_TOKENS so that the table this produces has one home, and
     * the case below reads the stylesheet to prove the copy has not drifted.
     */
    $today = PageWash::contrastReport([
        'palette' => 'custom',
        'c1' => PageWash::CONTRAST_FLOOR,
        'c2' => PageWash::CONTRAST_FLOOR,
        'c3' => PageWash::CONTRAST_FLOOR,
        'drift' => 0,
        'intensity' => 100,
    ]);

    $lines = [];
    $worse = [];

    foreach (PageWash::TREATMENTS as $key => $treatment) {
        $report = PageWash::contrastReport($treatment);

        foreach ($report as $token => $ratio) {
            $lines[] = sprintf('  %s %-12s %5.2f  (today %5.2f)', $key, $token, $ratio, $today[$token]);

            if ($ratio + 0.005 < $today[$token]) {
                $worse[] = sprintf('  treatment %s: %s is %.2f, worse than today\'s %.2f',
                    $key, $token, $ratio, $today[$token]);
            }
        }
    }

    expect($lines)->toHaveCount(20);
    expect($worse)->toBe([], "a treatment costs contrast:\n".implode("\n", $worse));
});

it('keeps its copy of the five text colours in step with kbb.css', function () {
    /*
     * A contrast table whose inputs can move without anybody noticing is not
     * evidence. If a lane restyles the shop's ink, this fails and the table is
     * recomputed rather than quietly becoming a statement about an old design.
     */
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));
    $missing = [];

    foreach (PageWash::TEXT_TOKENS as $token => $hex) {
        if (! str_contains($css, $token.':'.$hex)) {
            $missing[] = '  '.$token.' is no longer '.$hex.' in kbb.css';
        }
    }

    expect($missing)->toBe([], "the contrast table's inputs have moved:\n".implode("\n", $missing));
});

/* ═════════════════════════════════════ the cost of the animation ═══ */

it('animates opacity and nothing else, on layers the compositor owns', function () {
    /*
     * An animated gradient is the classic way to pin a phone's CPU. The two
     * expensive shapes are animating `background-position` on a full-page
     * gradient and interpolating a gradient's colours through a registered
     * custom property — both repaint the whole viewport on every frame, on the
     * main thread. Neither is expressible in what this emits, and this is where
     * that is held.
     *
     * MUTATION: change the keyframes in PageWash::css() to move
     * `background-position` instead of `opacity` and this fails on the first
     * assertion by name.
     */
    $css = pwCssFor(['on' => true] + PageWash::TREATMENTS['b']);

    preg_match_all('/@keyframes\s+kbb-wash-[ab]\{(.*?)\}\}/s', $css.'}', $frames);

    expect($frames[1])->toHaveCount(2, 'both keyframe sets should be emitted');

    foreach ($frames[1] as $body) {
        preg_match_all('/([a-z-]+)\s*:/', $body, $props);
        expect(array_unique($props[1]))->toBe(['opacity'],
            'a keyframe animates something other than opacity: '.$body);
    }

    foreach (['background-position', 'background-image:linear', 'filter:', 'width:', 'height:100%', 'margin'] as $banned) {
        expect(str_contains($css, $banned))
            ->toBeFalse($banned.' is in the emitted stylesheet');
    }

    /*
     * `background-attachment:fixed` is the OTHER phone killer and kbb.css's own
     * `body` rule already carries two of them. This lane must not add a third:
     * its layers are position:fixed ELEMENTS, which the compositor moves for
     * free, and a fixed attachment repaints the viewport on every scroll frame.
     */
    expect($css)->not->toContain('background-attachment');
    expect(substr_count($css, 'position:fixed'))->toBe(3);
});

it('has no animation at all under prefers-reduced-motion', function () {
    /*
     * Stated as `no-preference` rather than as `reduce`, so that the failure
     * mode of a typo is "it never moves" and not "it moves for somebody who
     * asked it not to". Every `animation` declaration must be inside that one
     * query and nowhere else.
     *
     * MUTATION: move either `animation:` line out of the @media block and this
     * fails with the declaration quoted.
     */
    foreach (PageWash::TREATMENTS as $key => $treatment) {
        $css = pwCssFor(['on' => true] + $treatment);

        expect(substr_count($css, 'animation:'))->toBe(2, $key.' should emit two animations');

        preg_match('/@media\(prefers-reduced-motion:no-preference\)\{(.*?)\}$/s', $css, $m);
        expect($m)->not->toBeEmpty($key.' emits no reduced-motion guard at all');

        $guarded = substr_count($m[1], 'animation:');
        expect($guarded)->toBe(2, $key.': '.(2 - $guarded).' animation declaration(s) sit outside the guard');
    }
});

it('takes the wash off a printed page', function () {
    $css = pwCssFor(['on' => true] + PageWash::TREATMENTS['a']);

    expect($css)->toContain('@media print{html::before,body::before,body::after{display:none}');

    /*
     * The printed DOCUMENTS cannot reach this stylesheet at all — the invoice,
     * the packing slip, the delivery note and the shipping label all extend
     * invoices/document.blade.php, which loads no site stylesheet and includes
     * no partial of this lane's. Asserted rather than assumed, because "it
     * cannot happen" is the sentence this repository keeps paying for.
     */
    foreach (glob(resource_path('views/invoices/*.blade.php')) ?: [] as $file) {
        expect(str_contains((string) file_get_contents($file), 'page-wash-css'))
            ->toBeFalse(basename($file).' includes the wash, so a printed document would carry it');
    }
});

/* ═══════════════════════════════════════════════════════ helpers ═══ */

/** @return array{0:int, 1:int, 2:int} */
function pwHex(string $hex): array
{
    $h = ltrim($hex, '#');

    return [(int) hexdec(substr($h, 0, 2)), (int) hexdec(substr($h, 2, 2)), (int) hexdec(substr($h, 4, 2))];
}

/**
 * The stylesheet a shop with these values would send, without touching storage.
 *
 * `effective()` reads settings and the request; these cases are about the
 * FORMATTER, so the values are handed straight to a fresh instance through a
 * stub settings service rather than being written and read back. The endpoint
 * cases in PageWashTest do the round trip.
 *
 * @param  array<string, mixed>  $values
 */
function pwCssFor(array $values): string
{
    $wash = new class(app(\App\Services\SettingsService::class), $values) extends PageWash
    {
        /** @param array<string, mixed> $forced */
        public function __construct(\App\Services\SettingsService $settings, private array $forced)
        {
            parent::__construct($settings);
        }

        public function effective(?\Illuminate\Http\Request $request = null): array
        {
            return array_merge(parent::all(), $this->forced);
        }
    };

    return $wash->css();
}
