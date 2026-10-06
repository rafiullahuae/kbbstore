<?php

declare(strict_types=1);

/*
 * The owner, 6 Oct: "the top notch is covered in iphones, but not in android
 * devices." The colour-to-the-top change reached iPhones at once (iOS reads
 * the status-bar tag from the page on every launch) but not Android, which
 * keeps the old installed app's full-screen display until Chrome re-mints it
 * or the app is installed again. The shell now says which top the server
 * wants, and the app tells an Android phone still on full screen how to get
 * the new one.
 */

use Tests\Support\OwnerAppRoutes as OA;

it('tells the app which top the server wants: app by default, full when chosen', function () {
    // MUTATION: drop the kbb-top meta from the shell and the notice can never decide.
    OA::wire($this->app);
    $html = (string) $this->get(OA::base().'/')->assertOk()->getContent();
    expect(substr_count($html, '<meta name="kbb-top" content="app">'))->toBe(1);
});

it('shows the notice only when the server wants colour to the top and the app is still full screen', function () {
    // MUTATION: drop either half of the condition and the notice nags phones that are fine.
    $fs = (string) file_get_contents(resource_path('js/owner-app/fs.js'));
    expect($fs)->toContain("if (!meta || meta.content !== 'app' || !window.matchMedia('(display-mode: fullscreen)').matches) return;")
        ->toContain("store.set('oa.tn', Date.now())")
        ->not->toContain('setInterval')->not->toContain('getBoundingClientRect');
    expect((string) file_get_contents(resource_path('js/owner-app/owner-app.js')))->toContain("initFullscreen();\nnudgeReinstall();");
});
