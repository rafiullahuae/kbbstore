<?php

declare(strict_types=1);

use App\Services\Mail\MailSettings;

/**
 * The Mail screen's layout and palette, inside resources/views/admin/app.blade.php.
 *
 * MailAdminScreenTest next door pins what the screen must SAY (the password box
 * stays empty, the transport error is printed verbatim, the rate limit is a
 * message). This file pins what it must not silently LOSE, and what colour it
 * is allowed to be.
 *
 * The screen was rebuilt from one flat list of fourteen boxes into four titled
 * bands with paired fields. The danger in any such rewrite is not that it looks
 * wrong -- that is visible -- but that a field falls out of the markup. A field
 * that is not rendered is not in collect(), a Save then writes nothing for that
 * key, and the stored value is blanked. Nothing throws and nothing logs. So the
 * first test below walks MailSettings::SCHEMA itself rather than a list written
 * out here, which means a key added to the schema tomorrow is checked tomorrow.
 */
$blade = fn (): string => (string) file_get_contents(resource_path('views/admin/app.blade.php'));

$mailBlock = function () use ($blade): string {
    $source = $blade();
    $start = strpos($source, 'LANE J · Store · Mail — BEGIN');
    $end = strpos($source, 'LANE J · Store · Mail — END');

    expect($start)->not->toBeFalse();
    expect($end)->not->toBeFalse();

    return substr($source, $start, $end - $start);
};

/*
 * The decisive one, and the reason it runs the real code rather than matching
 * strings. Every other assertion in this file greps the blade, and a grep
 * cannot tell whether a field is actually DRAWN -- mailField() is generic, so
 * the template that renders data-mail is present whether or not any particular
 * key reaches it. What decides that is the grouping table plus the catch-all,
 * and the only honest way to check them is to execute them. So the screen's
 * own MAIL_SECTIONS / mailControl / mailField / mailSections are lifted out of
 * the blade verbatim, handed one field per key in MailSettings::SCHEMA, and the
 * HTML they return is read back.
 */
it('draws every key the mail schema declares, proven by running the screen\'s own code', function () use ($mailBlock) {
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));

    if ($node === '') {
        test()->markTestSkipped('node is not available to run the screen code with');
    }

    $block = $mailBlock();

    $from = strpos($block, 'const MAIL_SECTIONS=[');
    $to = strpos($block, '/* The outcome of the last test');
    expect($from)->not->toBeFalse();
    expect($to)->not->toBeFalse();

    $screenCode = substr($block, $from, $to - $from);

    $keys = array_keys(MailSettings::SCHEMA);

    // One field per schema key, shaped the way MailApiController::show() sends
    // them, so the grouping code sees exactly what the browser gives it.
    $fields = array_map(fn (string $key): array => [
        'key' => $key,
        'type' => MailSettings::SCHEMA[$key][0],
        'label' => MailSettings::SCHEMA[$key][1],
        'help' => MailSettings::SCHEMA[$key][2] ?? '',
        'value' => '',
        'has_value' => false,
        'options' => MailSettings::SCHEMA[$key][0] === 'choice' ? ['a', 'b'] : null,
    ], $keys);

    $driver = <<<'JS'
const escHtml = s => String(s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const escAttr = escHtml;
JS;

    $driver .= "\n".$screenCode."\n";
    $driver .= 'const FIELDS = '.json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).";\n";
    $driver .= <<<'JS'
const html = mailSections(FIELDS);
const drawn = Array.from(html.matchAll(/data-mail="([a-z_]+)"/g)).map(m => m[1]);
process.stdout.write(JSON.stringify(drawn));
JS;

    $tmp = tempnam(sys_get_temp_dir(), 'mailsec').'.js';
    file_put_contents($tmp, $driver);

    $out = (string) shell_exec(escapeshellcmd($node).' '.escapeshellarg($tmp).' 2>&1');
    @unlink($tmp);

    $drawn = json_decode($out, true);

    expect(is_array($drawn))->toBeTrue(
        'The Mail screen\'s grouping code did not run. node said: '.substr($out, 0, 400)
    );

    foreach ($keys as $key) {
        expect(in_array($key, $drawn, true))->toBeTrue(
            "MailSettings::SCHEMA declares [{$key}] but mailSections() draws no control for it. "
            .'A field missing from the form is absent from collect(), so the next Save writes nothing '
            .'for that key and silently blanks the stored value.'
        );
    }

    // And nothing is drawn twice -- a key named in two bands would render two
    // inputs sharing one data-mail, and collect() would keep whichever came last.
    expect(count($drawn))->toBe(
        count(array_unique($drawn)),
        'A mail field is rendered more than once: '.implode(', ', array_diff_assoc($drawn, array_unique($drawn)))
    );
});

it('groups fields by name only into keys the schema actually has', function () use ($mailBlock) {
    $block = $mailBlock();

    $start = strpos($block, 'const MAIL_SECTIONS=[');
    expect($start)->not->toBeFalse();
    $end = strpos($block, '];', $start);
    $table = substr($block, $start, $end - $start);

    preg_match_all("/'(mail_[a-z_]+)'/", $table, $m);
    expect($m[1])->not->toBeEmpty();

    foreach (array_unique($m[1]) as $key) {
        expect(array_key_exists($key, MailSettings::SCHEMA))->toBeTrue(
            "MAIL_SECTIONS places [{$key}], which MailSettings::SCHEMA does not declare. "
            .'A mistyped key here drops the real field into the catch-all band instead of its own.'
        );
    }
});

it('keeps a catch-all band so a new schema key can never go unrendered', function () use ($mailBlock) {
    $block = $mailBlock();

    // mailSections() renders whatever no group claimed. This is the safety net
    // that makes the grouping table a display order rather than an allowlist.
    $hasFilter = str_contains($block, 'const rest=fields.filter(f=>!used[f.key]);');
    expect($hasFilter)->toBeTrue(
        'mailSections() no longer renders the fields that no section names. '
        .'Without that, adding a key to MailSettings::SCHEMA drops it off the screen silently.'
    );

    expect(str_contains($block, 'Other settings'))->toBeTrue(
        'The catch-all band lost its heading.'
    );
});

it('uses design tokens rather than hard-coded colours', function () use ($mailBlock) {
    $block = $mailBlock();

    // The console ships five themes, each redefining --accent and friends on
    // :root[data-theme]. A hex literal does not follow them, which is exactly
    // how this screen stopped matching the rest of the admin. #7b8697 appeared
    // nine times, #1f9d55 and #d64545 five more between them.
    preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $block, $m);

    expect($m[0])->toBe(
        [],
        'The Mail screen has picked up hard-coded colours again: '.implode(', ', array_unique($m[0]))
    );
});

it('styles the screen through its own prefix so no other screen moves', function () use ($blade, $mailBlock) {
    $source = $blade();

    // .mmrow / .mmlbl belong to Modules, Mega Menu, Ecommerce, Payment Rules
    // and Delivery too -- 80-odd call sites. Mail borrowing them is why it
    // could not be relaid out without moving five screens that did not ask to.
    $block = $mailBlock();
    expect(str_contains($block, 'class="mmrow"'))->toBeFalse(
        'The Mail screen is using .mmrow again, which five other screens also render.'
    );
    expect(str_contains($block, 'class="mmlbl"'))->toBeFalse(
        'The Mail screen is using .mmlbl again, which five other screens also render.'
    );

    expect(str_contains($source, '.mlf-sec{'))->toBeTrue('The mlf- stylesheet block is missing.');
});

it('still pairs fields into shared-width rows that can stack on a phone', function () use ($blade) {
    $source = $blade();

    // repeat(auto-fit, minmax(240px,1fr)) cannot go below 240px per track, so
    // two tracks plus the gap need more than a 390px phone has and the row
    // overflows rather than stacking. The min() floor is what makes the same
    // markup two columns on a laptop and two stacked boxes on a phone.
    $grid = str_contains(
        $source,
        '.mlf-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(240px,100%),1fr));'
    );

    expect($grid)->toBeTrue(
        'The Mail field grid lost its min() floor, so a paired row can no longer stack at 390px.'
    );
});
