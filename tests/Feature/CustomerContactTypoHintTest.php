<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\EmailTypoCases;

/**
 * Store -> Customers -> (a customer) -> Edit email / phone: the "Did you mean
 * ...?" hint. (Lane CE)
 *
 * The owner: "yes hints are fine, but must be accurate." So this is a table of
 * what the hint says, run through THE function the browser runs -- cut out of
 * partials/customer-contact-edit.blade.php between its markers and executed by
 * node, the way SiteAnalyticsFeedLinkTest runs pageLink -- not a PHP copy of it
 * that could agree with itself while the page says something else.
 *
 * What a wrong hint looks like on the shop: the owner, trusting the console,
 * taps "Use it" and turns a real yahoo.co.uk customer into a dead yahoo.com
 * address -- the exact failure this feature exists to repair, caused by it.
 */

function ceHintSource(): string
{
    $src = (string) file_get_contents(resource_path('views/admin/partials/customer-contact-edit.blade.php'));
    $start = strpos($src, '/*kxe-suggest-start*/');
    $end = strpos($src, '/*kxe-suggest-end*/');

    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();

    return substr($src, $start, $end - $start);
}

/** @param array<string, string|null> $cases */
function ceRunHints(array $cases): array
{
    $js = ceHintSource()
        . "\nprocess.stdout.write(JSON.stringify(" . json_encode(array_keys($cases)) . '.map(function (e) { return kxeSuggest(e); })));';

    $node = new Process(['node', '-e', $js]);
    $node->run();

    if (! $node->isSuccessful() && (str_contains($node->getErrorOutput(), 'not found') || $node->getExitCode() === 127)) {
        test()->markTestSkipped('node is not installed here');
    }

    expect($node->isSuccessful())->toBeTrue($node->getErrorOutput());

    return array_combine(array_keys($cases), json_decode($node->getOutput(), true));
}

it('suggests exactly the right provider for a real typo, and leaves every real or unknown domain alone', function () {
    // MUTATION: delete KXE_KEEP's 'mail.com' and mail.com -> gmail.com appears;
    // drop the `pSuffix !== suffix` guard and yahoo.co.in -> yahoo.co.uk; drop
    // the `pName.length < 5` guard and liv.com / mse.com start guessing; let
    // rule 1 fire for any name and company.co -> company.com. Each is a row below.
    // The table lives in Tests\Support\EmailTypoCases since Lane EH, shared
    // with the checkout's copy of this function (CheckoutEmailTypoHintTest).
    $cases = EmailTypoCases::all();

    expect(count($cases))->toBeGreaterThanOrEqual(40);

    $got = ceRunHints($cases);

    foreach ($cases as $typed => $want) {
        expect($got[$typed])->toBe($want, 'typed: ' . var_export($typed, true));
    }
});

it('is only a hint: it never writes the field, and "Keep as typed" hides it for that value', function () {
    // MUTATION: assign S.email = kxeSuggest(...) on input (an auto-correct), or
    // drop the S.keep comparison in hintHTML() -- red.
    $src = (string) file_get_contents(resource_path('views/admin/partials/customer-contact-edit.blade.php'));

    expect(substr_count($src, 'S.email = '))->toBe(4) // open() loads it, grab() reads the field, "Use it", "Put back"
        ->and($src)->toContain("if (!hint || hint === S.keep) return '';")
        ->and($src)->toContain('data-kxe="keep"')
        ->and($src)->toContain("if (act === 'use') { S.email = t.getAttribute('data-v');")
        // Nothing while typing (the owner: "the dialogue box should appear only
        // once user left the email field. during write no any disturbance"):
        // the input handler only takes a showing hint away and never redraws;
        // the hint is armed on focusout. MUTATION: call refresh() from
        // oninput, or arm S.hintReady anywhere but focusout -- red.
        ->and($src)->toContain("if (!S.hintReady) return '';")
        ->and(substr_count($src, 'S.hintReady = true'))->toBe(1)
        ->and($src)->toContain("if (ev.target.id === 'kxeEmail') S.hintReady = true;")
        ->and(preg_match('/b\.oninput = function \(ev\) \{(?:(?!\n    \};).)*refresh\(\)/s', $src))->toBe(0)
        // No layout measuring (CLAUDE.md rule 4).
        ->and($src)->not->toContain('getBoundingClientRect')->not->toContain('offsetHeight')->not->toContain('offsetWidth');
});

it('draws with class names no other console screen uses', function () {
    // The first cut used `kcc-`, which partials/concern-chips-editor.blade.php
    // already owned: its `.kcc-row{display:grid;border:...}` drew every field in
    // this dialog as a bordered two-column box, label left, input squeezed right.
    // MUTATION: rename the prefix back to kcc- -- red.
    foreach (glob(resource_path('views/**/*.blade.php')) ?: [] as $file) {
        if (str_ends_with($file, 'customer-contact-edit.blade.php')) {
            continue;
        }
        expect(str_contains((string) file_get_contents($file), 'kxe-'))->toBeFalse($file);
    }
    foreach (glob(resource_path('views/admin/partials/*.blade.php')) ?: [] as $file) {
        if (str_ends_with($file, 'customer-contact-edit.blade.php')) {
            continue;
        }
        expect(str_contains((string) file_get_contents($file), 'kxe-'))->toBeFalse($file);
    }
    expect((string) file_get_contents(resource_path('views/admin/app.blade.php')))->not->toContain('.kxe-');
});

