<?php

declare(strict_types=1);

/**
 * Every tab of Appearance → Product page is in view.       (Integrator, 2.60.357)
 *
 * THE DEFECT, ON THE LIVE ADMIN (2 October 2026). The owner, after 2.60.356:
 * "i can not see any tab of this name at the top of the Appearance → Product
 * page → Buy these together." The tab was there -- thirteenth of thirteen --
 * in one row that scrolled sideways with its scrollbar hidden. Measured in
 * Chromium at 1280: 996px of a 1,902px strip shown, the tab's right edge at
 * x=2167; Share, the three Trust tabs and Mobile sections were off-screen too.
 * After: the strip wraps, scrollWidth equals its width at 390, 1280 and 1440,
 * and the last tab's right edge is inside it.
 *
 * MUTATION, RUN: delete the `#ppStrip .ectabs{...}` rule -- red.
 */
it('wraps the Product page tab strip instead of hiding tabs off to the side', function () {
    $blade = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect(substr_count($blade, '#ppStrip .ectabs{flex-wrap:wrap;overflow-x:visible}'))->toBe(1)
        // After the shared rule it overrides, or it loses the tie.
        ->and(strpos($blade, '#ppStrip .ectabs{flex-wrap:wrap'))
        ->toBeGreaterThan((int) strpos($blade, '.ectabs{display:flex;gap:2px;overflow-x:auto;'));

    // The other screens' strips keep their one scrolling row.
    expect($blade)->toContain('.ectabs{display:flex;gap:2px;overflow-x:auto;border-bottom:1px solid #e9edf3;margin-top:16px}');
});
