<?php

/*
 * NO ANIMATION THAT RUNS FOR EVER MAY ANIMATE A PROPERTY THE COMPOSITOR CANNOT
 * RUN.                                                              (Lane AN)
 *
 * THE DEFECT, measured on the live shop and on the seeded preview: in the 10
 * seconds after a page had loaded, with nobody touching it, a phone (390px,
 * DPR 3, 4x CPU) spent 3.2-4.0 s of main-thread task time and ran ~600 style
 * recalculations -- one every frame -- on home, product and super-sale, with
 * zero script. Three always-running animations did all of it, each one ALONE
 * enough for the full 600 recalcs:
 *
 *   btdrift    the buy-together wash      background-position   1.9 s / 10 s
 *   kft-drift  the footer strip and name  background-position   2.1 s / 10 s
 *   kbwK       the WhatsApp online dot    box-shadow            2.0 s / 10 s
 *
 * while every transform/opacity one (the page wash, the logo shine, the
 * WhatsApp rings and faces) cost ~0: the compositor runs those off the main
 * thread. So every keyframes an `infinite` animation names, in kbb.css,
 * kbb-product.css and the WhatsApp button's CSS, may touch transform and
 * opacity only. Put background-position back in btdrift, or box-shadow in
 * kbwK, and this is red.
 *
 * The exceptions are named, each with why, and none is on the live shop's
 * pages: options the owner has not switched on, a loading placeholder that
 * lives for a moment, and the old drift kept for a browser without
 * plus-lighter. The list may shrink; it must not grow without a reason here.
 */

use App\Services\WhatsAppButton;

const ANM_COMPOSITOR_EXCEPTIONS = [
    // Loading skeletons: on screen only while a list is being fetched.
    'kbbshim',
    // Menu-icon options (Appearance -> Header); the default is the three lines.
    'miTiles', 'miCycle', 'miGlow',
    // Home section-divider options; their fade mask is fixed to the element,
    // so moving the layer would move the fade too -- a visible change.
    'kbbStitch', 'kbbDrift', 'kbbSlide',
    // The footer name's old drift: only where mix-blend-mode:plus-lighter is
    // missing or reduced motion is asked for (it is then not running).
    'kft-drift',
    // The shine across the big name: an option, off on the live shop. A white
    // band clipped to the letters; no transform can move it inside them.
    'kft-sheen-text', 'kft-sheen-text-rtl',
    // The glossy logo option: the same, a gradient clipped to the letters.
    'lgx-dr',
];

function anmShopAnimationCss(): array
{
    $strip = static fn (string $css): string => (string) preg_replace('#/\*.*?\*/#s', '', $css);
    $wa = '';

    foreach ((new ReflectionClass(WhatsAppButton::class))->getConstants() as $value) {
        foreach ((array) $value as $v) {
            if (is_string($v) && str_contains($v, '{')) {
                $wa .= $v;
            }
        }
    }

    return [
        'kbb.css' => $strip((string) file_get_contents(resource_path('css/kbb/kbb.css'))),
        'kbb-product.css' => $strip((string) file_get_contents(resource_path('css/kbb/kbb-product.css'))),
        'WhatsAppButton' => $strip($wa),
    ];
}

/** @return array<string, list<string>> keyframes name => the properties its frames set */
function anmKeyframeProperties(string $css): array
{
    $out = [];
    preg_match_all('/@keyframes\s+([\w-]+)\s*\{/', $css, $m, PREG_OFFSET_CAPTURE);

    foreach ($m[1] as $i => [$name]) {
        $at = $m[0][$i][1] + strlen($m[0][$i][0]);
        $depth = 1;
        $j = $at;

        while ($depth > 0 && $j < strlen($css)) {
            $depth += ($css[$j] === '{') - ($css[$j] === '}');
            $j++;
        }

        preg_match_all('/([a-z-]+)\s*:/i', substr($css, $at, $j - $at - 1), $p);
        // A keyframe's own timing function is not an animated property (the
        // page wash walks its curve in steps that way).
        $props = array_diff(array_map('strtolower', $p[1]), ['animation-timing-function']);
        $out[$name] = array_values(array_unique(array_merge($out[$name] ?? [], $props)));
    }

    return $out;
}

/** @return list<string> keyframes names used by an animation that never ends */
function anmInfiniteAnimationNames(string $css, array $known): array
{
    $names = [];
    preg_match_all('/animation\s*:\s*([^;}]+)/i', $css, $m);

    foreach ($m[1] as $decl) {
        // Commas inside var()/calc() are not list separators.
        foreach (preg_split('/,(?![^(]*\))/', $decl) as $one) {
            if (! preg_match('/\binfinite\b/', $one)) {
                continue;
            }

            foreach (preg_split('/[\s()]+/', $one) as $token) {
                if (isset($known[$token])) {
                    $names[] = $token;
                }
            }
        }
    }

    return array_values(array_unique($names));
}

it('runs every never-ending shop animation on the compositor: transform and opacity only', function () {
    $offenders = [];
    $seen = [];

    foreach (anmShopAnimationCss() as $file => $css) {
        $frames = anmKeyframeProperties($css);

        foreach (anmInfiniteAnimationNames($css, $frames) as $name) {
            $seen[] = $name;
            $bad = array_diff($frames[$name], ['transform', 'opacity']);

            if ($bad !== [] && ! in_array($name, ANM_COMPOSITOR_EXCEPTIONS, true)) {
                $offenders[] = "{$file}: @keyframes {$name} animates ".implode(', ', $bad);
            }
        }
    }

    expect($offenders)->toBe([])
        // The parser really is reading the files: the ones that cost the shop
        // its idle frames are found, and now pass.
        ->and($seen)->toContain('btdrift', 'kft-slide', 'kbwK', 'kbb-page-b', 'lgx-sh', 'kbwS');
});

it('keeps the exception list honest: every entry still exists and still needs it', function () {
    $all = [];

    foreach (anmShopAnimationCss() as $css) {
        $all += anmKeyframeProperties($css);
    }

    foreach (ANM_COMPOSITOR_EXCEPTIONS as $name) {
        expect($all)->toHaveKey($name)
            ->and(array_diff($all[$name], ['transform', 'opacity']))->not->toBe([], "{$name} is compositor-only now; drop it from the list");
    }

    // The page wash redraws the screen only when a step lands, not each frame.
    $css = anmShopAnimationCss()['kbb.css'];
    expect(substr_count($css, 'animation-timing-function:steps('))->toBeGreaterThanOrEqual(24);

    // The three that were measured costing every idle frame are not excused.
    expect(ANM_COMPOSITOR_EXCEPTIONS)->not->toContain('btdrift')->not->toContain('kbwK')->not->toContain('kft-slide');
});

it('moves the footer name drift on a layer, and keeps the old drift only behind plus-lighter', function () {
    $css = anmShopAnimationCss()['kbb.css'];

    // The name's own background-position drift is switched off wherever the
    // compositor copy is drawn; the copy is printed only with the drift on.
    expect($css)->toContain('@supports (mix-blend-mode:plus-lighter) and selector(:has(*))')
        ->and($css)->toContain('.kft-motion .kft-name:has(>.kft-nm){position:relative;animation:none;background:none;color:#000}')
        ->and($css)->toContain('mix-blend-mode:plus-lighter')
        ->and($css)->toContain('mix-blend-mode:multiply');
});
