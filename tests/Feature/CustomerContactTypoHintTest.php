<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

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
    $cases = [
        // ---- true fixes (the domain only; the local part is kept exactly)
        'jovana8b@yahoo.co' => 'jovana8b@yahoo.com',
        'Jovana8B@Yahoo.Co' => 'Jovana8B@yahoo.com',
        'a@gmial.com' => 'a@gmail.com',
        'a@gmal.com' => 'a@gmail.com',
        'a@gamil.com' => 'a@gmail.com',
        'a@gmail.con' => 'a@gmail.com',
        'a@gmail.cmo' => 'a@gmail.com',
        'a@gmail.comm' => 'a@gmail.com',
        'a@gmail.om' => 'a@gmail.com',
        'a@gmail.co' => 'a@gmail.com',
        'a@hotmial.com' => 'a@hotmail.com',
        'a@hotmal.com' => 'a@hotmail.com',
        'a@hotmail.con' => 'a@hotmail.com',
        'a@outlok.com' => 'a@outlook.com',
        'a@outlook.co' => 'a@outlook.com',
        'a@icloud.co' => 'a@icloud.com',
        'a@iclod.com' => 'a@icloud.com',
        'a@yaho.com' => 'a@yahoo.com',
        'a@yahooo.com' => 'a@yahoo.com',
        'a@yahoo.con' => 'a@yahoo.com',
        'a@hotmial.co.uk' => 'a@hotmail.co.uk',
        'a@rocketmial.com' => 'a@rocketmail.com',
        'a@protonmal.com' => 'a@protonmail.com',
        '  a@gmial.com  ' => 'a@gmail.com',

        // ---- must not touch: real providers and their country variants
        'a@yahoo.co.uk' => null,
        'a@yahoo.co.in' => null,
        'a@yahoo.co.jp' => null,
        'a@hotmail.fr' => null,
        'a@hotmail.co.uk' => null,
        'a@live.co.uk' => null,
        'a@outlook.sa' => null,
        'a@gmail.com' => null,
        'a@yahoo.com' => null,
        'a@live.com' => null,
        'a@me.com' => null,
        'a@emirates.net.ae' => null,
        'a@eim.ae' => null,
        'a@ymail.com' => null,
        'a@mail.com' => null,
        'a@email.com' => null,
        'a@googlemail.com' => null,
        'a@gmx.de' => null,
        'a@wahoo.com' => null,

        // ---- must not touch: company / custom / anything not near the list
        'a@company.co' => null,
        'a@company.con' => null,
        'a@kbeautybliss.com' => null,
        'a@extrabeauty.ae' => null,
        'a@adnoc.ae' => null,
        'a@emiratesnbd.com' => null,
        'a@du.ae' => null,

        // ---- short names: too many real neighbours for one edit to mean a typo
        'a@liv.com' => null,
        'a@msm.com' => null,
        'a@aoll.com' => null,

        // ---- ambiguous: two providers equally close -> nothing
        'a@xmail.com' => null,

        // ---- not an address we can judge
        'a@بريد.امارات' => null,
        'a@' => null,
        'a' => null,
        '' => null,
        '@gmial.com' => null,
        'a@gmial' => null,
        'a@gmial..com' => null,
    ];

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
        // No request per keystroke: the input handler only redraws.
        ->and($src)->toContain("b.oninput = function (ev) {\n      if (ev.target.id !== 'kxeEmail' && ev.target.id !== 'kxePhone') return;\n      grab(); refresh();\n    };")
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

