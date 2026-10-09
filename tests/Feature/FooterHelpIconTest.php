<?php

declare(strict_types=1);

use App\Services\SettingsService;
use App\Services\SiteFooter;

/*
 * The owner, 9 October: "in the main footer strip in start, we have whatsapp
 * icon, we need support icon, give multiple icons controls on backend to choose
 * from". Appearance -> Footer -> Site footer · help strip -> Strip icon.
 *
 * MUTATIONS: print the stored setting instead of the HELP_ICONS constant -> the
 * "never prints the stored text" case is red; change the default back to
 * 'whatsapp' -> the default case is red; drop the @if -> 'none' draws an empty
 * circle and its case is red.
 */
function fhiFooter(?string $icon): string
{
    if ($icon !== null) {
        app(SettingsService::class)->set('sitefooter_site_help_icon', $icon);
    }

    return (string) test()->get('/about/')->getContent();
}

it('draws the support headset by default, as the owner asked', function () {
    $html = fhiFooter(null);

    expect($html)->toContain('<span class="kft-help-ic" aria-hidden="true">'.SiteFooter::HELP_ICONS['support'].'</span>')
        ->and($html)->not->toContain(SiteFooter::HELP_ICONS['whatsapp']);
});

it('puts the old WhatsApp icon back byte for byte', function () {
    expect(fhiFooter('whatsapp'))->toContain('<span class="kft-help-ic" aria-hidden="true">'.SiteFooter::HELP_ICONS['whatsapp'].'</span>');
});

it('draws each choice, and no circle at all for none', function () {
    foreach (['chat', 'phone', 'mail', 'heart', 'sparkle'] as $key) {
        expect(fhiFooter($key))->toContain('<span class="kft-help-ic" aria-hidden="true">'.SiteFooter::HELP_ICONS[$key].'</span>');
    }

    expect(fhiFooter('none'))->not->toContain('kft-help-ic');
});

it('never prints the stored text: anything else is the default', function () {
    $html = fhiFooter('<script>alert(1)</script>');

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain(SiteFooter::HELP_ICONS['support']);
});

it('offers every icon in the admin field, and the field sits in the help strip tab', function () {
    expect(array_keys(SiteFooter::SCHEMA['site_help_icon']['options']))->toBe(array_keys(SiteFooter::HELP_ICONS))
        ->and(SiteFooter::TABS['site_help'][2])->toContain('site_help_icon');
});
