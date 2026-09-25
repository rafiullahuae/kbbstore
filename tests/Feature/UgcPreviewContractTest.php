<?php

/**
 * The shoppable-video previews are a docs artefact, so most of what is in them
 * can only be shown in a picture. A handful of things in them are not taste at
 * all — they are the difference between the feature working and the feature
 * silently doing nothing on half the traffic — and those are pinned here.
 *
 * WHY A TEST FOR A FILE IN docs/. `docs/` is on BuildPackage::NEVER_SHIP, so
 * nothing below reaches the shop. But this file is the specification the round
 * that builds the storefront rail will copy from, and every line pinned here
 * is a line whose absence is invisible: an autoplay that silently never starts,
 * a clip that never gives its decoder back, a rating bar drawn on a product
 * that has no reviews. A preview that has quietly lost one of them is worse
 * than no preview, because it is the thing the next lane will trust.
 *
 * Each assertion names the defect it catches and how to make it red.
 */

/** The previews, read once. */
function ugcPreview(): string
{
    static $html = null;

    return $html ??= file_get_contents(base_path('docs/UGC-VIDEO-PREVIEWS.html'));
}

it('creates every video muted and inline, which is the whole of whether autoplay happens', function () {
    $html = ugcPreview();

    // Every browser refuses an unmuted autoplay, and iOS Safari additionally
    // refuses one without playsinline -- it takes the clip full screen instead,
    // which is worse than not playing at all. Lose any one of these four lines
    // and the rail looks identical in a desktop screenshot and does nothing on
    // a phone, which is the failure mode that would survive a review.
    //
    // MUTATION: delete `v.muted = true;` from mountVideo() and this is red.
    expect($html)
        ->toContain('v.muted = true;')
        ->toContain('v.playsInline = true;')
        ->toContain("v.setAttribute('playsinline','')")
        ->toContain("v.setAttribute('webkit-playsinline','')");

    // An autoplay refusal is a normal outcome, not an error. Uncaught, the
    // rejected promise is an unhandled rejection in the console per tile.
    expect($html)->toContain('if (t && t.catch) t.catch(function(){});');
});

it('fetches nothing until a clip is wanted, and hands the decoder back when it is not', function () {
    $html = ugcPreview();

    // preload="none" is what makes a rail of twelve cost twelve posters rather
    // than twelve clips. MUTATION: change it to 'metadata' and this is red.
    expect($html)->toContain("v.preload = 'none';");

    // Pausing alone leaves the decoded stream resident, so a long page
    // accumulates them; dropping the src and calling load() is what actually
    // frees the decoder. The measured run pins maxMounted == maxPlaying, which
    // is only true because unmount() does both.
    expect($html)
        ->toContain("v.removeAttribute('src'); v.load();")
        ->toContain('function unmount(v){');
});

it('plays only what is on screen, and never more than the cap', function () {
    $html = ugcPreview();

    // IntersectionObserver at 0.6, and a hard cap. Without the cap the
    // twelve-tile wall at 1280px decodes twelve clips at once; measured at
    // 390px the viewport never lets more than two past the threshold, so the
    // cap is invisible on a phone and load-bearing on a desktop.
    //
    // MUTATION: raise MAX_TEASERS to 99 and the measured run in
    // docs/ugc-preview-shots/measurements-round3.json stops being reproducible.
    expect($html)
        ->toContain('var MAX_TEASERS = 4;')
        ->toContain('new IntersectionObserver(')
        ->toContain('e.intersectionRatio > 0.6');

    // No scroll handler, and none of the element-measuring APIs this project
    // forbids by name in CheckoutFloatingBarGateTest and CartPageSqueezeTest.
    // The parenthesis matters: the page NAMES these in its own prose to explain
    // why it does not use them, and a bare substring match would fail on the
    // explanation rather than on a call.
    foreach (['getBoundingClientRect(', '.offsetTop', '.offsetHeight', '.clientHeight', 'window.scrollY', 'requestAnimationFrame('] as $api) {
        expect($html)->not->toContain($api);
    }
});

it('does not autoplay when the browser asks it not to', function () {
    $html = ugcPreview();

    // prefers-reduced-motion is an accessibility requirement, not a nicety, and
    // it is read live through matchMedia so changing the OS setting with the
    // page open takes effect without a reload.
    // MUTATION: delete the rmQuery.matches term and the reduced-motion shot in
    // docs/ugc-preview-shots/ stops showing poster frames.
    expect($html)
        ->toContain("window.matchMedia('(prefers-reduced-motion: reduce)')")
        ->toContain('return RM_SIM || rmQuery.matches;');

    // Save-Data, and STRICTLY === true. navigator.connection does not exist at
    // all in Safari or Firefox, so a loose truthiness check reads `undefined`
    // on a large share of the traffic -- treating unknown as "save data" would
    // turn the feature off for most of the people who can afford it.
    // MUTATION: change `=== true` to a bare truthiness test and this is red.
    // The whole expression, not the fragment: the page NAMES `=== true` in its
    // own prose to explain the decision, so a substring match would pass on the
    // explanation after the code beneath it had been loosened.
    expect($html)->toContain('return SD_SIM || !!(navigator.connection && navigator.connection.saveData === true);');
});

it('draws no rating bar at all for a product with no reviews', function () {
    $html = ugcPreview();

    // An empty five-star row reads as "rated badly" and a zero reads as "rated
    // zero"; neither is what nought reviews means. The shop already draws this
    // line -- partials/home/grid.blade.php gates its New badge on
    // `! $p->review_count` -- and the bar follows it by not existing.
    //
    // MUTATION: drop the `!p.rc` term and the SPF tile in every rail grows a
    // "0.0 (0)" bar, which v3-no-reviews-390.png would show.
    expect($html)->toContain("if (!p || !p.rc) return '';");

    // Exactly one demo product carries 0/0, so every rail on the page shows the
    // case. Lose it and the previews stop covering it.
    expect($html)->toMatch('/watery:\s*\{[^}]*r:\s*0,\s*rc:\s*0\s*\}/');

    // The star count rounds the way the shop's own card rounds -- grid.blade
    // does `(int) round((float) $p->rating)` -- so a tile and a shop card can
    // never disagree about how many stars a 4.6 gets.
    expect($html)->toContain('var on = Math.round(p.r)');
});

it('keeps the inlined media unable to look like a signing key', function () {
    $html = ugcPreview();

    // PackageSigningTest walks docs/ for /[A-Za-z0-9+\/]{86}==/, the shape of a
    // base64 Ed25519 secret key, and an unwrapped video data URI ends in
    // exactly that. Capping the longest unbroken base64 run at 76 makes the
    // collision impossible however the clips are regenerated -- so this asserts
    // the invariant rather than the one shape that happened to collide.
    preg_match_all('/[A-Za-z0-9+\/]{77,}/', $html, $runs);

    expect($runs[0])->toBe([]);
});
