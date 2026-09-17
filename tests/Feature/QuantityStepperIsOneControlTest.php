<?php

declare(strict_types=1);

/**
 * =============================================================================
 * ONE QUANTITY STEPPER, ONE RADIUS, ONE SIZE — PHONE AND DESKTOP
 * =============================================================================
 *
 * The owner's instruction, and it took three rounds to get right because the
 * first two answered a question he had not asked:
 *
 *   "i had before less heighted quantity buttons, almost half less height than
 *    yours ... to get the maximum number of products in the space. and keep the
 *    buttons radius same everywhere, slight rectangle round is fine. not full
 *    circled one."
 *
 * So there is no mobile variant of this control any more. The cart page, the
 * cart drawer and the checkout order summary draw the same compact stepper at
 * every screen width, inside an 8px rounded rectangle. Measured at 424px with a
 * touch pointer:
 *
 *              before          after
 *   cart       46px tall       30px      radius 99px -> 8px
 *   checkout   46px tall       25px      radius 99px -> 8px, inner boxes gone
 *   drawer     46px tall       24px      radius 8px (unchanged)
 *
 * ▲ THIS OVERRIDES THE USUAL 44px TOUCH-TARGET GUIDANCE, DELIBERATELY, AND IT
 * IS THE OWNER'S CALL TO MAKE. A 44px target is the accessibility convention;
 * a basket with thirteen lines in it is unusable on a phone if every row is
 * 46px taller than it needs to be, and he is the one who watches people use
 * this shop. Density won. This file exists so the next person does not
 * "restore" the tap targets as a bug fix — if they are ever restored it should
 * be because he asked, not because a linter did.
 *
 * The other 44px targets in those mobile blocks — the drawer and menu close
 * buttons, the remove control, the tab strip — are untouched. They are not what
 * he asked about and none of them is repeated thirteen times down a list.
 */

it('draws the same stepper at every width, with no mobile enlargement', function () {
    $cases = [
        'resources/css/kbb/kbb-cart.css' => '.kbb-cartpage .qty button',
        'resources/css/kbb/kbb.css' => '.kc-qty button',
        'resources/css/kbb/kbb-checkout.css' => '.kbb-checkout .qty .co-q',
    ];

    foreach ($cases as $path => $selector) {
        $css = file_get_contents(base_path($path));

        // The stepper must still be styled at all — a guard that passes because
        // the rule vanished is the failure mode this project keeps meeting.
        expect(str_contains($css, $selector))->toBeTrue($path.' no longer styles '.$selector.' at all');

        $start = strpos($css, '@media (max-width: 900px)');

        if ($start === false) {
            continue;
        }

        /*
         * COMMENTS OUT FIRST. The first run of this guard went red on a CSS
         * COMMENT — the note that used to explain the 44px floor, left behind
         * when the rule was deleted. A regex over a stylesheet reads its own
         * prose as code; this project has a register entry for exactly that,
         * and the guard walked into it on its first execution.
         */
        $mobile = preg_replace('#/\*.*?\*/#s', ' ', substr($css, (int) $start)) ?? '';

        // Inside the mobile block, this stepper may not be given a finger size.
        foreach (['44px', '46px', '48px'] as $finger) {
            $hit = (bool) preg_match(
                '/'.preg_quote($selector, '/').'[^{}]*\{[^}]*'.preg_quote($finger, '/').'/',
                $mobile
            );

            expect($hit)->toBeFalse(
                $path.': the mobile block sizes '.$selector.' at '.$finger.' again, which is the row height the owner asked to halve'
            );
        }
    }
});

it('gives all three the same slightly-rounded corner, not a pill', function () {
    $radii = [
        'resources/css/kbb/kbb-cart.css' => '.kbb-cartpage .qty{',
        'resources/css/kbb/kbb-checkout.css' => '.kbb-checkout .qty{',
        'resources/css/kbb/kbb.css' => '.kc-qty{',
    ];

    foreach ($radii as $path => $selector) {
        $css = file_get_contents(base_path($path));

        $at = strpos($css, $selector);
        expect($at)->not->toBeFalse($path.' no longer declares '.$selector);

        $rule = substr($css, (int) $at, 260);

        expect(str_contains($rule, 'border-radius:8px'))->toBeTrue(
            $path.': '.$selector.' is not the shared 8px corner, so the three steppers no longer match'
        );

        // 99px is the pill this replaced, and "not full circled one" was the
        // instruction in as many words.
        expect(str_contains($rule, 'border-radius:99px'))->toBeFalse(
            $path.': '.$selector.' is a pill again'
        );
    }
});

it('keeps the checkout buttons free of their own box', function () {
    /*
     * The round before this one: the mobile block gave each checkout button a
     * 1px border and a 10px radius on a white ground INSIDE the bordered
     * wrapper — two boxes drawn inside a box. Desktop never did that, and the
     * phone should be the same control rather than a different one.
     */
    $css = file_get_contents(base_path('resources/css/kbb/kbb-checkout.css'));
    $at = strpos($css, '.kbb-checkout .qty .co-q{');

    expect($at)->not->toBeFalse('the checkout stepper rule is gone');

    $rule = substr($css, (int) $at, 400);

    expect(str_contains($rule, 'border:0'))->toBeTrue('the checkout stepper draws its own border again, inside the wrapper')
        ->and(str_contains($rule, 'background:transparent'))->toBeTrue('the checkout stepper has a filled ground again');
});
