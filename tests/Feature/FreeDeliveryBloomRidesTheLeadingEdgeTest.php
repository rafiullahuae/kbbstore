<?php

declare(strict_types=1);

use Tests\Support\CssDirection;

/**
 * THE FREE-DELIVERY BLOOM RIDES THE FILL'S LEADING EDGE IN BOTH DIRECTIONS.
 * (Lane CX, task 2 — handed over by Lane AR2.)
 *
 * ── WHAT THE DEFECT LOOKED LIKE ON THE SHOP ─────────────────────────────────
 *
 * The squeeze cart page draws a free-delivery progress bar whose fill carries a
 * glowing bloom and a turning petal on the edge it grows towards. Both were
 * `position:absolute; right:0` on the fill, plus `transform:translate(50%,-50%)`
 * to centre them on that edge.
 *
 * `right` is the LEADING edge only while the page reads left to right. Under
 * `dir="rtl"` the fill grows the other way, so `right:0` is its TRAILING edge —
 * the far end of the track, which the fill has not reached — and the +50%
 * translate then pushed the shape a further half-width outwards, away from the
 * bar, over the summary rows.
 *
 * Measured in Chromium on the real page, Arabic, 45% full:
 *
 *              fill spans           bloom sat at      should sit at
 *   390px      211.61 → 361.00      x = 361  (+13)    x = 211.61
 *   1280px     271.31 → 427.00      x = 427  (+13)    x = 271.31
 *
 * — a glowing dot 149px / 156px away from the end of the bar it is supposed to
 * be riding, at the one end of the track that means "you are done". At 0% the
 * `cpg-flat` guard hid it, so the fault appeared the moment a shopper put
 * anything in their basket and grew worse as they spent more.
 *
 * ── WHY THIS IS NOT A ONE-PROPERTY EDIT, WHICH IS THE POINT OF THE TEST ─────
 *
 * Three things carry the direction and they have to move together:
 *
 *   1. the INSET, which has a logical form (`inset-inline-end`);
 *   2. the static TRANSFORM, which does not — translateX is measured along the
 *      physical x axis, so its sign is flipped under `[dir="rtl"]` the way
 *      kbb.css:648 and kbb-shop.css:268 flip the off-canvas drawers';
 *   3. the KEYFRAMES, because a running animation replaces the static transform
 *      outright. Converting 1 and 2 and leaving 3 gives a bloom that is in the
 *      right place for one frame and then animates back to the wrong one — the
 *      worst of the three failures, because it looks like a glitch rather than
 *      a bug.
 *
 * So each case below asserts a PAIR. None of them can be satisfied by the
 * half-edit that looks finished.
 *
 * ── MUTATION NOTE (run, not asserted) ───────────────────────────────────────
 *
 * Put `right:0` back on the shared rule: case 1 is red naming the physical
 * property. Delete the `[dir="rtl"] … ::before` rule: case 2 is red saying the
 * bloom's inset was mirrored and its transform was not. Point `animation-name`
 * at `cpgbloom` instead of `cpgbloomrtl`: case 3 is red, because the RTL rule
 * would then be naming keyframes that translate the other way. Change one
 * `-50%` inside `@keyframes cpgbloomrtl` back to `50%`: case 3 is red on that
 * keyframe alone.
 */
const CX_BLOOM_FILE = 'resources/views/store/cart-squeeze.blade.php';

/** The parsed <style> block, read once. */
function bloomDeclarations(): array
{
    static $rows = null;

    return $rows ??= CssDirection::declarationsInFile(base_path(CX_BLOOM_FILE));
}

/** Every declaration of one exact rule. @return array<string, string> property => value */
function bloomRule(string $selector): array
{
    $out = [];

    foreach (bloomDeclarations() as $d) {
        if ($d['selector'] === $selector) {
            $out[$d['property']] = $d['value'];
        }
    }

    return $out;
}

/** Every `transform` value inside one @keyframes block. @return list<string> */
function bloomKeyframeTransforms(string $name): array
{
    $out = [];

    foreach (bloomDeclarations() as $d) {
        if ($d['property'] === 'transform' && str_starts_with($d['selector'], '@keyframes '.$name.' ')) {
            $out[] = $d['value'];
        }
    }

    return $out;
}

const CX_BLOOM_BASE = '.kbb-cartpage.cpg-squeeze .sum .ship .fill';

it('reads the stylesheet it claims to read', function () {
    /*
     * The guard that stops every case below being vacuously green. This repo has
     * been bitten four times by an assertion that asserted nothing; if the
     * <style> block is restructured, or the Blade moves, the reader returns an
     * empty list and "no physical property found" becomes trivially true.
     */
    expect(count(bloomDeclarations()))->toBeGreaterThan(400);
    expect(bloomRule(CX_BLOOM_BASE.'::before'))->not->toBe([]);
    expect(bloomRule(CX_BLOOM_BASE.'::after'))->not->toBe([]);
});

it('anchors both shapes to the fill\'s leading edge logically, not to its right', function () {
    $anchor = bloomRule(CX_BLOOM_BASE.'::before, '.CX_BLOOM_BASE.'::after');

    expect($anchor)->not->toBe([], 'the shared anchor rule for the bloom and the petal has moved or been split');

    // THE ONE-PROPERTY HALF OF THE FIX.
    expect($anchor['inset-inline-end'] ?? null)->toBe('0');
    /*
     * array_key_exists()->toBeFalse($message) rather than not->toHaveKey(),
     * which is the form ExpectationsThatCannotFailTest asks for: toHaveKey's
     * second argument is an expected VALUE and not a message, so the negated
     * form cannot carry one without silently becoming unfailable.
     */
    expect(array_key_exists('right', $anchor))->toBeFalse(
        'the bloom is anchored to the fill\'s RIGHT edge again — the trailing edge under [dir="rtl"]'
    );
    expect(array_key_exists('left', $anchor))->toBeFalse(
        'the bloom is anchored to a physical side; the leading edge is inset-inline-end in both directions'
    );

    // And nothing physical anywhere else in the file, which is the same guard
    // RtlReadinessTest applies to the storefront stylesheets and which this file
    // now sits inside (Tests\Support\CssDirection::SCOPE).
    $physical = CssDirection::physicalIn(base_path(), CX_BLOOM_FILE);

    expect(array_keys($physical))->toBe([]);
});

it('flips the sign of the transform that centres each shape on that edge', function () {
    foreach (['::before', '::after'] as $pseudo) {
        $ltr = bloomRule(CX_BLOOM_BASE.$pseudo);
        $rtl = bloomRule('[dir="rtl"] '.CX_BLOOM_BASE.$pseudo);

        expect($ltr['transform'] ?? '')->toStartWith(
            'translate(50%,',
            $pseudo.' no longer centres itself on the inline-end edge in the default direction'
        );

        /*
         * THE HALF THAT `inset-inline-end` CANNOT DO. translateX has no logical
         * form: +50% is half a width to the RIGHT whatever the document's
         * direction, so under RTL it pushes the shape off the end of the bar
         * instead of onto it.
         */
        expect($rtl)->not->toBe([], $pseudo.' has no [dir="rtl"] rule, so its inset was mirrored and its transform was not — the shape lands half its width outside the track');
        expect($rtl['transform'] ?? '')->toStartWith('translate(-50%,');

        // Same shape, same everything else: the mirror flips a sign and nothing
        // more. rotate(0deg) is carried on the petal in both, so the two rules
        // differ in the sign alone.
        expect(preg_replace('/^translate\(-50%,/', 'translate(50%,', $rtl['transform']))->toBe($ltr['transform']);
    }
});

it('gives each animation an RTL twin, because a running animation replaces the static transform', function () {
    $pairs = [
        '::before' => 'cpgbloom',
        '::after' => 'cpgpetal',
    ];

    foreach ($pairs as $pseudo => $ltrName) {
        $ltr = bloomRule(CX_BLOOM_BASE.$pseudo);
        $rtl = bloomRule('[dir="rtl"] '.CX_BLOOM_BASE.$pseudo);

        expect($ltr['animation'] ?? '')->toStartWith($ltrName.' ');

        $rtlName = $rtl['animation-name'] ?? '';

        expect($rtlName)->not->toBe('', $pseudo.' keeps the default-direction animation under [dir="rtl"], so its keyframes translate the shape back to the wrong edge the moment the animation starts');
        expect($rtlName)->not->toBe($ltrName);

        $ltrFrames = bloomKeyframeTransforms($ltrName);
        $rtlFrames = bloomKeyframeTransforms($rtlName);

        expect($ltrFrames)->not->toBe([], '@keyframes '.$ltrName.' is missing');
        expect($rtlFrames)->toHaveCount(
            count($ltrFrames),
            '@keyframes '.$rtlName.' does not have a frame for every frame of '.$ltrName
        );

        foreach ($ltrFrames as $i => $frame) {
            expect($frame)->toStartWith('translate(50%,');
            expect($rtlFrames[$i])->toStartWith('translate(-50%,');

            // Identical but for the sign — so an edit to one that is not made to
            // the other is caught here rather than on an Arabic shopper's phone.
            expect(preg_replace('/^translate\(-50%,/', 'translate(50%,', $rtlFrames[$i]))->toBe($frame);
        }
    }
});

it('still hides both shapes on an empty bar, in both directions', function () {
    /*
     * At 0% there is no edge to ride and the shape would sit outside the track
     * looking like a stray mark. The guard is a class the Blade puts on the fill
     * and it carries no direction of its own — which is worth pinning, because
     * the obvious way to write the [dir="rtl"] overrides above is to give them
     * higher specificity than this rule and turn the bloom back on at zero.
     */
    $flat = bloomRule(CX_BLOOM_BASE.'.cpg-flat::before, '.CX_BLOOM_BASE.'.cpg-flat::after');

    expect($flat['display'] ?? null)->toBe('none');

    // And the class still reaches a zero fill from the Blade. Measured in
    // Chromium at 390 and 1280, English and Arabic: computed `display` is
    // `none` on both pseudo-elements at 0% and `block` at 45% and 100%.
    $source = (string) file_get_contents(base_path('resources/views/store/cart-inner.blade.php'));

    expect(substr_count($source, "' cpg-flat'"))->toBe(1);
});
