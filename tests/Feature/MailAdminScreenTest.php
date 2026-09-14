<?php

/**
 * The Mail screen inside resources/views/admin/app.blade.php.
 *
 * That file is ~600KB of single-file admin and three separate lanes were
 * editing it during this phase, so the parts that make a screen reachable are
 * pinned here rather than left to a visual check. A screen whose render
 * function exists but is missing from the router registry is invisible, and
 * nothing else in the suite would notice.
 *
 * The security assertion is the one that matters: the password input must be
 * rendered EMPTY. The API cannot supply the value -- it is encrypted in
 * mail_credentials and show() sends an empty string -- but a screen that tried
 * to prefill it would silently produce a box the browser then offers to save,
 * autofill and sync. MailSecretsTest guards the API; this guards the page.
 */
$blade = fn () => (string) file_get_contents(resource_path('views/admin/app.blade.php'));

it('registers the mail screen in the nav, the titles and the router', function () use ($blade) {
    $source = $blade();

    // All three, because any one of them missing makes the screen unreachable
    // in a different and individually silent way.
    expect($source)->toContain("['mail','Mail'")                 // sidebar entry
        ->and($source)->toContain("mail:['Store','Mail']")       // breadcrumb + go() gate
        ->and($source)->toContain('mail:renderMail')             // the router
        ->and($source)->toContain('function renderMail(');       // and the function it names
});

it('points the screen at the guarded admin-api, not the public api', function () use ($blade) {
    $source = $blade();

    expect($source)->toContain("'/admin-api/mail'")
        ->and($source)->toContain("mailBase()+'/test'");
});

it('never prefills the password box', function () use ($blade) {
    $source = $blade();

    $start = strpos($source, 'LANE J · Store · Mail — BEGIN');
    $end = strpos($source, 'LANE J · Store · Mail — END');
    $block = substr($source, $start, $end - $start);

    // The secret branch of mailField(). No `value=` on that input at all: not
    // the stored password, not a mask. Only a placeholder saying whether one is
    // stored.
    expect($block)->toContain('if(f.type===\'secret\')')
        ->and($block)->toContain("placeholder=\"\${f.has_value?'Stored — leave blank to keep it':'Not set'}\"")
        // Scoped to this lane's block: another screen elsewhere in this file
        // carries a hard-coded dot-mask in a mock that is not ours to change.
        ->and($block)->not->toContain('type="password" value=');
});

it('does not send a blank password box back as a value', function () use ($blade) {
    // Belt and braces over the service's own "blank means unchanged" rule: the
    // screen drops the field entirely rather than relying on it.
    expect($blade())->toContain("if(el.type==='password' && el.value==='') return;");
});

it('shows the transport error verbatim rather than a tidy summary', function () use ($blade) {
    $source = $blade();

    // "535 Incorrect authentication data" and "Connection could not be
    // established" send the owner to two completely different places. A
    // "Could not send" sends them nowhere, which is the failure this whole
    // package exists to avoid.
    expect($source)->toContain('escHtml(String(d.message||\'\'))')
        ->and($source)->toContain('Send failed');
});

it('tells the owner when mail is only going to the log', function () use ($blade) {
    // The green-tick-that-means-nothing case, called out on the screen itself.
    expect($blade())->toContain('Nothing is being sent.');
});

it('handles the rate limit as a message rather than a broken request', function () use ($blade) {
    expect($blade())->toContain('r.status===429');
});

it('keeps the mail block self-contained so the integrator can merge it', function () use ($blade) {
    $source = $blade();

    // Lane I was editing this same file for the payments screen. The markers
    // are what makes an overlap a two-minute resolution instead of a hunt.
    expect(substr_count($source, 'LANE J · Store · Mail — BEGIN'))->toBe(1)
        ->and(substr_count($source, 'LANE J · Store · Mail — END'))->toBe(1)
        ->and(strpos($source, 'LANE J · Store · Mail — BEGIN'))
        ->toBeLessThan(strpos($source, 'LANE J · Store · Mail — END'));
});
