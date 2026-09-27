<?php

declare(strict_types=1);

/**
 * A failure may not arrive wearing a success tick.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * The owner sent a screenshot of a black pill reading "✓ That file was not
 * accepted." The tick was not that call site's mistake: `toast()` rendered
 * I.check for EVERY message it was ever given, and `.toast svg` painted it
 * mint green, so every error in this console had been announcing itself with a
 * green tick since the toast was written.
 *
 * `toast(m, 'bad')` fixed the mechanism and 53 simple catch handlers were
 * converted with it. Lane O1 then audited the order screens and found the
 * REMAINING half: the failures that are not thrown but RETURNED — `if (!r.ok ||
 * j.ok === false) { toast(j.message || 'Could not …'); }` — which no catch-block
 * sweep can reach. Seventeen of them, on the screens where money moves.
 *
 * TWO OF THEM ARE THE WHOLE REASON THIS FILE EXISTS:
 *
 *     toast(j.message||'Could not process that refund.')
 *     toast(j.message||'Could not capture that payment.')
 *
 * A refund that did not happen, and a capture that did not happen, each
 * announced with a green tick and gone in 2.4 seconds. "Could not capture that
 * payment" under a tick is read as done by anyone scanning, and the next thing
 * that happens is the goods ship.
 *
 * ── WHAT THIS SCANS FOR, AND WHY IT IS A PATTERN AND NOT A LIST ────────────
 *
 * A list of line numbers rots on the first edit above it. This looks for the
 * SHAPE instead: a toast whose message plainly reports a failure, that does not
 * pass 'bad'. The vocabulary below is drawn from the messages this console
 * actually uses, and a new failure worded some other way will not be caught —
 * that is an honest limit, stated rather than papered over. What it does
 * guarantee is that the phrasings already in use cannot regress, and that the
 * two money ones are pinned by name below whatever the scanner does.
 */
function ftamConsoleSource(): string
{
    static $src = null;

    return $src ??= (string) file_get_contents(resource_path('views/admin/app.blade.php'));
}

/** Comments stripped: this file's own prose names these phrases repeatedly. */
function ftamCode(): string
{
    $code = (string) preg_replace('#/\*.*?\*/#s', '', ftamConsoleSource());

    return (string) preg_replace('#(?<![:\'"])//[^\n]*#', '', $code);
}

it('reads a console worth scanning', function () {
    // Or every assertion below sweeps an empty string and passes.
    expect(strlen(ftamCode()))->toBeGreaterThan(400_000)
        ->and(ftamCode())->toContain('function toast(');
});

/*
 * MUTATION: drop the `, 'bad'` from either money toast and this is red, naming
 * it. RUN: red.
 */
it('never announces a failed capture or refund with a success tick', function () {
    $code = ftamCode();

    $money = [
        "Could not process that refund.",
        "Could not capture that payment.",
    ];

    $bare = [];

    foreach ($money as $needle) {
        /*
         * The whole toast call, from `toast(` to its `);`, so the check is on
         * what this call actually passes rather than on the bytes near it.
         */
        if (preg_match('/toast\([^;]*'.preg_quote($needle, '/').'[^;]*\);/', $code, $m) !== 1) {
            $bare[] = $needle.' (no toast call found — did the wording change?)';

            continue;
        }

        if (! str_contains($m[0], "'bad'")) {
            $bare[] = $m[0];
        }
    }

    expect($bare)->toBe([], 'a capture or refund that did NOT happen is being reported with the green '
        . 'success tick, and dismissed in 2.4 seconds: ' . implode(' | ', $bare));
});

/*
 * MUTATION: drop the `, 'bad'` from any converted failure toast — say
 * 'Could not remove that item.' — and this is red, quoting the call.
 */
it('marks every toast whose words report a failure', function () {
    $code = ftamCode();

    /*
     * Deliberately NOT including "No changes to save" or "Nothing to do": those
     * are no-ops rather than failures. Nothing went wrong, so a tick is not a
     * lie, and marking them red would train the eye to ignore the red.
     */
    $failureWords = [
        'Could not ', 'could not be completed', 'Update failed', 'Save failed',
        'Not saved', 'is not available for this order',
    ];

    $bare = [];

    // Every toast call in the file, whole.
    preg_match_all('/\btoast\((?:[^()\'"]|\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|\([^()]*\))*\)/', $code, $calls);

    foreach ($calls[0] as $call) {
        if (str_contains($call, "'bad'")) {
            continue;
        }

        foreach ($failureWords as $word) {
            if (str_contains($call, $word)) {
                $bare[] = trim($call);
                break;
            }
        }
    }

    expect($bare)->toBe([], 'these toasts report a failure and would draw the green success tick: '
        . implode(' | ', array_unique($bare)));
});

/*
 * And the mechanism itself, because every assertion above is worthless if
 * 'bad' stops meaning anything.
 *
 * MUTATION: make toast() ignore its second argument and this is red.
 */
it('draws a different toast for a failure than for a success', function () {
    $code = ftamCode();

    expect($code)->toContain("const bad=kind==='bad'")
        ->and($code)->toContain('ic(bad?I.alert:I.check)')
        // A failure stays up longer, because it is the one you have to READ.
        ->and($code)->toMatch('/bad\?4200:2400/');

    // And it is visibly different, not merely a different glyph.
    expect(ftamConsoleSource())->toContain('.toast.bad svg{color:');
});
