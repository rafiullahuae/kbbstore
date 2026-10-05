<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Services\OwnerApp\OwnerAppUi;
use App\Services\SettingsService;
use App\Services\SiteApp;
use Tests\Support\OwnerAppRoutes as OA;
use Tests\Support\SiteAppRoutes;

/*
 * The top of the screen in both installed apps (Lane IC).
 *
 * The owner, 5 October: "in owner app, nowadays the phones comes with curved
 * screen little bit from the top ... our app is not covering the notch. fix
 * this and also fix for the main site app too ... don't take the header up,
 * just extend the background color to the top end." His screenshot: Android,
 * the owner app installed, a solid BLACK band where the clock and camera are.
 *
 * That band was the manifest's display_override ['fullscreen', ...]: Android
 * full screen hides the status bar and letterboxes the cutout in black. Now:
 * standalone, the bar in the app's own top colour (theme_color + theme-color),
 * and on iPhone black-translucent, so the app's background runs under the
 * clock while .app's padding-top: env(safe-area-inset-top) keeps the header
 * where it was. The old behaviour is one choice away under Customise app.
 */

beforeEach(function () {
    SiteAppRoutes::wire($this->app);
    OA::wire($this->app);
    OwnerAppUi::forget();
});

afterEach(fn () => OwnerAppUi::forget());

function icSbSet(string $key, mixed $value): void
{
    Setting::query()->updateOrCreate(['key' => $key], ['value' => is_array($value) ? json_encode($value) : $value, 'autoload' => true]);
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
}

function icSbUi(array $over): void
{
    expect(OwnerAppUi::put(array_replace_recursive(OwnerAppUi::defaults(), $over)))->toBeNull();
    OwnerAppUi::forget();
}

/* ============================================================ owner app */

it('runs the owner app\'s own colour up to the top edge by default: no full screen, the bar in #FBE3EA, iPhone translucent', function () {
    /* DEFECT: a black band over the clock and camera on his Android phone.
       MUTATION: put 'display_override' => ['fullscreen', 'standalone'] back in
       AppController::manifest() -> the not->toHaveKey line is red.
       MUTATION: make the shell's status-bar style 'default' always -> the
       black-translucent line is red (a separate white bar on iPhone). */
    $m = $this->get(OA::base().'/manifest.webmanifest')->assertOk()->json();
    expect($m['display'])->toBe('standalone')
        ->and($m)->not->toHaveKey('display_override')
        ->and($m['theme_color'])->toBe('#FBE3EA')
        ->and($m['short_name'])->toBe('KBB Owner');

    $shell = (string) $this->get(OA::base())->assertOk()->getContent();
    expect($shell)->toContain('<meta name="theme-color" content="#FBE3EA">')
        ->and($shell)->toContain('<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">')
        ->and($shell)->toContain('viewport-fit=cover');

    // The header does not move up: the app box is padded by the safe-area
    // inset, which is what black-translucent hands the page.
    $css = (string) file_get_contents(resource_path('css/owner-app/owner-app.css'));
    expect($css)->toContain('--sat: env(safe-area-inset-top, 0px)')
        ->and($css)->toMatch('/\.app \{[^}]*padding-top: var\(--sat\)/')
        // And the colour at the top of that padding is the colour the bar gets.
        ->and($css)->toContain('--appbg: linear-gradient(180deg, #FBE3EA 0,');
});

it('follows a custom accent: the bar is the same 14% mix the app paints at its top', function () {
    /* MUTATION: return TOP_DEFAULT always from OwnerAppUi::topColour() -> red
       (a pink bar over a blue app). The mix is computed here independently. */
    $acc = '#2F5D8A';
    icSbUi(['accent' => $acc]);
    $mix = sprintf('#%02X%02X%02X', ...array_map(fn ($c) => (int) round($c * 0.14 + 255 * 0.86), sscanf($acc, '#%02x%02x%02x')));
    expect($mix)->toBe('#E2E8EF');

    expect($this->get(OA::base().'/manifest.webmanifest')->json('theme_color'))->toBe($mix);
    expect((string) $this->get(OA::base())->getContent())->toContain('<meta name="theme-color" content="'.$mix.'">');

    $css = (string) file_get_contents(resource_path('css/owner-app/owner-app.css'));
    expect($css)->toContain('--appbg: linear-gradient(180deg, color-mix(in srgb, var(--acc) 14%, #fff) 0,');
});

it('keeps the old full screen one choice away, and stores only one of its own options', function () {
    /* MUTATION: drop 'fullscreen' from CHOICES['statusbar'] -> the choice
       cannot be saved and the display_override line is red. */
    icSbUi(['statusbar' => 'fullscreen']);
    expect(OwnerAppUi::all()['statusbar'])->toBe('fullscreen');

    $m = $this->get(OA::base().'/manifest.webmanifest')->json();
    expect($m['display_override'])->toBe(['fullscreen', 'standalone'])
        ->and(array_keys($m))->toBe(['name', 'short_name', 'id', 'start_url', 'scope', 'display', 'display_override', 'orientation', 'background_color', 'theme_color', 'icons']);
    expect((string) $this->get(OA::base())->getContent())->toContain('<meta name="apple-mobile-web-app-status-bar-style" content="default">');

    icSbUi(['statusbar' => 'hidden"><script>']);
    expect(OwnerAppUi::all()['statusbar'])->toBe('color');

    // The admin card offers both, labelled, under Layout.
    $admin = OA::admin();
    $this->actingAs($admin, 'admin')->getJson('/admin-api/owner-app/ui')->assertOk()
        ->assertJsonPath('options.choices.statusbar', ['color', 'fullscreen']);
    $js = (string) file_get_contents(resource_path('views/admin/partials/owner-app-customise.blade.php'));
    expect($js)->toContain("statusbar: { color: 'Colour to the top (default)', fullscreen: 'Full screen, no clock' }")
        ->and(substr_count($js, "sel('statusbar', 'Top of the screen')"))->toBe(1);
});

/* ============================================================ the shop's app */

it('gives the installed shop app the header\'s colour at the top, and a browser visitor nothing new', function () {
    /* MUTATION: return SiteApp::THEME from topColour() -> the #1D1D1F lines are red.
       MUTATION: take fb_bg when the flag strip is on for phones (as first proposed) -> the
       flag-strip line is red: the strip draws BELOW the header, so it must not colour the bar. */
    expect($this->get('/manifest.webmanifest')->json('theme_color'))->toBe('#FFFFFF');

    icSbSet('header_settings', ['bar_bg' => '#1D1D1F']);
    expect($this->get('/manifest.webmanifest')->json('theme_color'))->toBe('#1D1D1F');
    expect((string) $this->get('/')->getContent())->toContain('<meta name="theme-color" media="(display-mode: standalone)" content="#1D1D1F">');

    // The flag strip, even on for phones, is drawn under the header: the bar stays the header's.
    icSbSet('header_settings', ['bar_bg' => '#1D1D1F', 'fb_mobile' => true, 'fb_bg' => '#FDEFF4']);
    expect(SiteApp::topColour())->toBe('#1D1D1F');
    $html = (string) $this->get('/')->getContent();
    expect(strpos($html, '<header'))->toBeLessThan(strpos($html, 'class="kfb'));

    // App off: no theme-color at all, the page as it was.
    icSbSet(SiteApp::SETTING, ['on' => false, 'name' => 'K-Beauty Bliss']);
    expect((string) $this->get('/')->getContent())->not->toContain('theme-color');
});
