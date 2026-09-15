<?php

/**
 * The floating bottom menu on phones, behind a switch.
 *
 * Store → Modules → Store & content → "Floating bottom menu (mobile)",
 * default OFF. Nothing else depends on the bar: the header carries its own
 * cart icon and count, and the mobile menu is a separate nav, so hiding it
 * removes an overlay rather than a route.
 */

use App\Services\SettingsService;

it('hides the floating bottom menu by default', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->not->toContain('class="tabbar"');
});

it('shows it once the module is switched on', function () {
    app(SettingsService::class)->setModule('mobile_tabbar', true);

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('class="tabbar"');
});

it('keeps the header cart reachable while the bar is hidden', function () {
    // The point of "off by default" being safe: nothing that was only
    // reachable from the bar becomes unreachable.
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->not->toContain('class="tabbar"')
        ->and($html)->toContain('data-kbb-open="cart"');
});

it('offers the switch on a group the modules screen actually renders', function () {
    $row = \App\Services\ModuleRegistry::REGISTRY['mobile_tabbar'] ?? null;

    expect($row)->not->toBeNull()
        // A group the admin does not know would render the row nowhere.
        ->and(array_keys(\App\Services\ModuleRegistry::GROUPS))->toContain($row[0])
        // Default off.
        ->and($row[3])->toBeFalse();
});
