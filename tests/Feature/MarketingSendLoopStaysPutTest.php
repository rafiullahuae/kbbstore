<?php

declare(strict_types=1);

/**
 * A SENDING CAMPAIGN NEVER PULLS THE ADMIN BACK TO ITSELF.     (Lane MJ, 2.60.488)
 *
 * The owner, 12 October: "when the email campaign running, and then when i go
 * to any other page, it comes back again on the email campaign page after few
 * seconds. i can not stay on any other page."
 *
 * The defect: driveA() kept stepping the campaign while S.view stayed 'review'
 * (the marketing screen's own state, which leaving does not change), and every
 * step repainted Review & send into #content. Measured in Chromium with the
 * campaign kept "sending": BEFORE, Dashboard, Orders and Payments each turned
 * back into "Marketing Emails · Review & send" within 12 seconds and the page
 * sent 30 more steps after leaving; AFTER, all three stayed put and 0 steps
 * were sent (tools/mj-stay-shots.cjs, docs/lane-mj-shots/).
 *
 * The browser is the proof; this pins the two locks so neither can be taken
 * out quietly. MUTATION: remove `ours() &&` from driveA()'s loop, or the
 * `if (!ours()) return;` from shell(), and this is red.
 */
function mjSource(): string
{
    return (string) file_get_contents(resource_path('views/admin/partials/marketing-emails-screens.blade.php'));
}

it('stops the send loop and every paint once the owner leaves the screen', function () {
    $src = mjSource();

    expect($src)->toContain('var away = false;')
        ->and($src)->toContain("if (id !== SCREEN) { away = true; return previousGo.apply(this, arguments); }")
        ->and($src)->toContain("while (ours() && S.view === 'review'")
        ->and($src)->toContain("if (!ours()) break;")
        ->and($src)->toContain('function paintReviewProgress() { if (!ours()) return;');

    // shell() is the only writer to #content in this file, and it refuses.
    preg_match('/function shell\(inner\) \{(.*?)\n  \}/s', $src, $m);
    expect($m[1] ?? '')->toContain('if (!ours()) return;')
        ->and(substr_count($src, 'h.innerHTML = \'<div class="wrap mke"'))->toBe(1);
});

it('treats #content as ours only while it is empty or holds this screen', function () {
    // The lock no other window.go wrapper can skip: a screen whose wrapper was
    // installed after this file never calls ours, but its markup has no
    // [data-mke], so ours() is false and nothing is painted over it.
    preg_match('/function ours\(\) \{(.*?)\n  \}/s', mjSource(), $m);

    expect($m[1] ?? '')->toContain('if (away) return false;')
        ->toContain("!h.firstElementChild || !!h.querySelector('[data-mke]')");
});
