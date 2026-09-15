<?php

declare(strict_types=1);

/**
 * The Customer note card on the order detail screen.
 *
 * It rendered with no horizontal padding at all: the gift pill, both labels
 * and the message box sat flush against the card border while the header above
 * them was indented 22px. The cause was not a wrong value -- it was that
 * `.odcardbody` was written into the markup and never given a CSS rule, so it
 * inherited nothing. A class that does not exist is invisible to review and
 * silent at runtime, which is why this test asserts the PAIRING rather than
 * the padding figure: every class the card's markup emits must have a rule.
 */
function adminConsoleSource(): string
{
    static $src = null;

    return $src ??= (string) file_get_contents(resource_path('views/admin/app.blade.php'));
}

it('gives every class the customer-note card emits a CSS rule', function () {
    $src = adminConsoleSource();

    // The classes odCustomerNoteCard() actually writes.
    $emitted = ['odcard', 'odcardhead', 'odcardbody', 'odgiftrow', 'odgiftflag', 'odgiftfee', 'odgiftmsg', 'odcustnote'];

    foreach ($emitted as $class) {
        // A rule means the selector appears at the start of a declaration --
        // `.name{` or `.name,` or `.name ` followed by a brace somewhere after.
        $hasRule = (bool) preg_match('/\.' . preg_quote($class, '/') . '(?=[\s,{:.])/', $src);

        expect($hasRule)->toBeTrue("the markup emits .{$class} but no CSS rule defines it");
    }
});

it('indents the note body by the same gutter as the card header', function () {
    $src = adminConsoleSource();

    preg_match('/\.odcardhead\{[^}]*padding:\s*[\d.]+px\s+([\d.]+)px/', $src, $head);
    preg_match('/\.odcardbody\{[^}]*padding:\s*[\d.]+px\s+([\d.]+)px/', $src, $body);

    expect($head[1] ?? null)->not->toBeNull('.odcardhead lost its padding')
        ->and($body[1] ?? null)->not->toBeNull('.odcardbody lost its padding')
        // The whole visible defect in one number: a body gutter that does not
        // match the header's is the card looking broken.
        ->and($body[1])->toBe($head[1], 'the note body no longer lines up with the card header');
});

it('lays the gift pill and its fee out as a row rather than on a baseline', function () {
    $src = adminConsoleSource();

    preg_match('/\.odgiftrow\{([^}]*)\}/', $src, $m);
    $rule = $m[1] ?? '';

    expect($rule)->toContain('display:flex')
        ->and($rule)->toContain('align-items:center')
        // Wraps on a narrow phone instead of pushing the card sideways.
        ->and($rule)->toContain('flex-wrap:wrap');

    // And the markup uses it -- a rule nothing references is not a fix.
    expect($src)->toContain('<div class="odgiftrow">')
        ->and($src)->not->toContain('<p style="margin:0 0 10px"><span class="odgiftflag">');
});

it('boxes the shopper\'s delivery note the way it boxes the gift message', function () {
    $src = adminConsoleSource();

    // Both notes are things the customer wrote. Only one of them used to get a
    // container, so the other read as a stray paragraph off the card edge.
    expect($src)->toMatch('/\.odcustnote p\{[^}]*background:/')
        ->and($src)->toMatch('/\.odgiftmsg p\{[^}]*background:/');
});

it('lets a long note wrap instead of widening the card', function () {
    // The admin console overflowed horizontally by 164px once already. A gift
    // message with no spaces in it -- a URL, a long reference -- is exactly the
    // content that does it again.
    expect(adminConsoleSource())->toMatch('/\.odgiftmsg p,\.odcustnote p\{[^}]*word-break:break-word/');
});
