<?php

declare(strict_types=1);

use App\Models\Brand;
use Tests\Support\Phase9Routes;

/**
 * Lane LH — the owner's mobile Speed Index, and the brand page's one shift.
 *
 * THE OWNER, 7 October, over PageSpeed mobile on https://extrabeauty.ae/:
 * "i'm getting 2.3 indexing in desktop but in mobile 5.4 sec ... i really want
 * to reduce that". FCP 1.4 s and LCP 1.8 s are simulated from a model of the
 * page; Speed Index is mostly measured off a real film of it.
 *
 * Lighthouse's simulated Speed Index is 1.4 x the Speed Index of the run it
 * actually filmed (+0.4 x a layout estimate). His own PageSpeed film (5 Oct)
 * is blank for four of its eight frames: the filmed load is slow to its first
 * paint, which is the document and two render-blocking stylesheets -- see the
 * lane report. What this file pins is the part the SHOP chose: on a phone the
 * banner slider (his 864 x 920 phone picture, half the screen) moved on four
 * seconds after first paint, mid-load. Filmed here the way PSI films (Slow 4G,
 * film cut where PSI's stops, 7 runs each): observed SI 3.37 s -> 2.67 s with
 * the first dwell counted from the load event, the same as autoplay off.
 *
 * And the brand page's one layout shift (Lane SX), with the cause.
 */
function lhSlider(): string
{
    $code = (string) file_get_contents(resource_path('views/partials/home/slider-banner.blade.php'));
    $code = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $code);
    preg_match('#<script>(.*)</script>#s', $code, $m);

    return (string) ($m[1] ?? '');
}

it('starts the slider\'s first dwell at the page\'s load event, not at first paint', function () {
    /*
     * THE DEFECT ON THE SHOP: the timer started where the parser met this
     * script, i.e. at first paint, so on a phone over a slow link the banner —
     * half the screen — slid to the next picture while the page was still
     * loading (often onto a lazy picture that had not arrived), and Lighthouse
     * counted every frame before that as half unfinished. 0.73 s of observed
     * Speed Index on the preview, about 1 s of the owner's mobile score.
     *
     * MUTATION: drop `loaded && ` from running() and the slider moves at first
     * paint + dwell again. Red. Drop the load listener and it never moves.
     * Red. Start `loaded` at `true` and it is the old behaviour. Red.
     */
    $js = lhSlider();

    expect($js)->toContain("var loaded = document.readyState === 'complete';")
        ->and($js)->toContain('return loaded && dwell > 0 && !stopped')
        ->and($js)->toContain("if (!loaded) window.addEventListener('load', function(){ loaded = true; beat(); });")
        // The listener comes AFTER the first beat(), which is what writes
        // `is-paused` (the bar shows the solid highlight while waiting, and
        // the progress fill starts from 0 together with the timer at load).
        ->and(strpos($js, "if (!loaded) window.addEventListener('load'"))->toBeGreaterThan((int) strrpos($js, "go(0, false);\n  beat();"));

    // Still one integer of state and no layout read: readyState is document
    // state. (SliderBannerTest's rule-4 case holds the full API list.)
    foreach (['getBoundingClientRect', 'offsetWidth', 'clientWidth', 'scrollWidth'] as $api) {
        expect($js)->not->toContain($api);
    }
});

it('asks matchMedia before nav-fit reads the menu bar, at the breakpoint kbb.css hides it', function () {
    /*
     * THE DEFECT ON THE SHOP: on every phone page fitNavBar() read
     * `wrap.offsetWidth` of a bar kbb.css has set to display:none — on load and
     * again when the webfont landed — forcing a style-and-layout pass mid-load
     * for nothing (Lighthouse "Forced reflow", app-*.js). Desktop unchanged.
     *
     * MUTATION: delete the matchMedia line and the first layout read in
     * fitNavBar() comes before any media check. Red. Change kbb.css's
     * `@media(max-width:1000px)` and the two disagree. Red.
     */
    $js = (string) file_get_contents(resource_path('js/kbb/nav-fit.js'));
    $code = (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $js);
    $fit = substr($code, (int) strpos($code, 'function fitNavBar(){'));

    expect($code)->toContain("const PHONE_BAR_HIDDEN = '(max-width: 1000px)';")
        ->and($fit)->toContain('if(window.matchMedia && window.matchMedia(PHONE_BAR_HIDDEN).matches) return;')
        ->and(strpos($fit, 'PHONE_BAR_HIDDEN'))->toBeLessThan((int) strpos($fit, 'offsetWidth'));

    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));
    expect((bool) preg_match('/@media\(max-width:1000px\)\{[^@]*?\.mbar\{display:none\}/s', $css))
        ->toBeTrue('kbb.css no longer hides .mbar at max-width:1000px -- move PHONE_BAR_HIDDEN with it');
});

it('puts the brand page\'s own stylesheet in <head>, so the brand header does not drop 22px after first paint', function () {
    /*
     * THE DEFECT ON THE SHOP (Lane SX, laptops): one layout shift of 0.015 at
     * ~150-200 ms — .brw-ph__media, .brw-ph__panel and #brandGrid down 22px.
     * store/brands.blade.php pushed its <style> to 'scripts', the foot of the
     * body, so the page painted with `.brw` at no top padding and moved when
     * the sheet arrived. Reproduced at 0.0154 (y 94 -> 116); 0 after.
     *
     * MUTATION: push the block back to 'scripts'. Red, for the brand page and
     * for the directory.
     */
    Phase9Routes::wire($this->app);
    Brand::create(['slug' => 't-lh-brand', 'name' => 'T LH Brand', 'description' => 'A brand.']);

    foreach (['/brands/t-lh-brand/', '/brands/'] as $url) {
        $html = (string) $this->get($url)->assertOk()->getContent();
        $head = substr($html, 0, (int) strpos($html, '</head>'));
        $body = substr($html, (int) strpos($html, '</head>'));

        expect(substr_count($head, '.brw{max-width:var(--site-max);margin-inline:auto;padding:22px var(--site-gutter) 60px}'))->toBe(1, $url)
            ->and(str_contains($body, '.brw{max-width'))->toBeFalse($url.' still prints the block in <body>');
    }
});
