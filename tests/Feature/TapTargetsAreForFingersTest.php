<?php

declare(strict_types=1);

/**
 * A DESKTOP WINDOW NARROWER THAN 900px IS NOT A PHONE.
 *
 * Reported by the owner from a browser window at 845px: "the +- quantity button
 * on cart, cart panel, and on checkout page, coming too giant and style is also
 * coming different."
 *
 * All three were true, and none of it was a regression — the rules had been
 * live for a while and are byte-identical in the bundle the shop served before
 * and after this cycle's rebuild, which was checked before anything was
 * changed. The defect is the CONDITION: three `@media (max-width: 900px)`
 * blocks raise the steppers to 44px finger targets, and on checkout also swap
 * the pill for a bordered 10px box. 900px is a LAYOUT breakpoint being used as
 * an INPUT-DEVICE one, so a laptop at half screen width gets phone chrome
 * driven by a mouse.
 *
 * `pointer: fine` is the question that was meant. Measured in Chromium after
 * the fix:
 *
 *   845px, mouse   cart 28x28   checkout 23x23 (no border)   panel 22x22
 *   390px, finger  cart 44x44   checkout 44x44               panel 44x44
 *   1280px, mouse  unchanged
 *
 * The counter-queries are appended AFTER each block rather than narrowing it,
 * because the rest of those blocks is real layout work — wrapping, spacing, the
 * remove control — that a narrow desktop window genuinely wants. Only the
 * finger-sized hit areas are handed back.
 */

it('hands the desktop stepper back to a mouse below the mobile breakpoint', function () {
    $files = [
        'resources/css/kbb/kbb.css' => '.kc-qty button',
        'resources/css/kbb/kbb-cart.css' => '.kbb-cartpage .qty button',
        'resources/css/kbb/kbb-checkout.css' => '.kbb-checkout .qty .co-q',
    ];

    foreach ($files as $path => $selector) {
        $css = file_get_contents(base_path($path));

        expect(str_contains($css, '@media (max-width: 900px) and (pointer: fine)'))->toBeTrue(
            $path.' lost its pointer:fine counter-query, so a desktop window under 900px gets finger-sized controls again'
        );

        // The counter-query has to actually carry the control it is for,
        // otherwise it is an empty block that reads like a fix.
        $tail = substr($css, (int) strpos($css, '@media (max-width: 900px) and (pointer: fine)'));

        expect(str_contains($tail, $selector))->toBeTrue(
            $path.': the counter-query does not mention '.$selector.', so it restores nothing'
        );
    }
});

it('keeps the finger-sized targets for an actual touch device', function () {
    // The mobile blocks must still be there. A "fix" that deleted them would
    // pass the case above and take the 44px targets away from every phone.
    foreach ([
        'resources/css/kbb/kbb-cart.css' => 'width:44px;height:44px;font-size:18px',
        'resources/css/kbb/kbb-checkout.css' => 'width:44px;height:44px;min-width:44px',
    ] as $path => $needle) {
        expect(str_contains(file_get_contents(base_path($path)), $needle))->toBeTrue(
            $path.' no longer raises its stepper to 44px at all, so phones lost the touch target'
        );
    }
});
