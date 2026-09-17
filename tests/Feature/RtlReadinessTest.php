<?php

declare(strict_types=1);

use Tests\Support\CssDirection;

/**
 * The guard for T6's logical-property rewrite.
 *
 * The rewrite converted 368 physical direction declarations across the
 * storefront stylesheets and the storefront views' inline <style> blocks. Every
 * one of them is a no-op in a left-to-right document, which is what made it safe
 * to land before the locale switch exists — and also what makes it easy to undo
 * by accident. Nothing about `margin-left` looks wrong today, so a later edit
 * that reintroduces one will look completely normal in review.
 *
 * So this pins both halves:
 *
 *   - no physical direction declaration may appear in the converted files
 *     except the 57 that docs/rtl-audit.md documents as deliberately physical;
 *   - each of those 57 must still be there, so the document cannot quietly
 *     describe a file that no longer looks like that.
 *
 * WHY THE FIRST TEST IN THIS FILE EXISTS. This repo has been bitten four times
 * by a guard that asserted something vacuously true, so the reader that these
 * assertions depend on is itself checked first: if it ever silently returns
 * nothing — a regex that stops matching, a file that moves, a Blade view whose
 * <style> block is restructured — then "no physical declarations found" becomes
 * trivially true and every other test here passes while guarding nothing. The
 * declaration counts in docs/rtl-audit.md are the floor that makes that
 * impossible.
 */
function rtlAuditDocument(): string
{
    static $md = null;

    return $md ??= (string) file_get_contents(base_path('docs/rtl-audit.md'));
}

it('reads the stylesheets it claims to read', function () {
    // Floors from docs/rtl-audit.md: file | logical declarations | total parsed.
    $rows = CssDirection::markdownTable(rtlAuditDocument(), 'rtl-audit:floors');

    expect($rows)->not->toBeEmpty('docs/rtl-audit.md has no floors table — the guard below would pass against nothing.');
    expect($rows)->toHaveCount(count(CssDirection::SCOPE), 'The floors table and CssDirection::SCOPE disagree about which files T6 covers.');

    $thin = [];
    $missing = [];

    foreach ($rows as [$file, $logical, $total]) {
        $rel = trim($file, '` ');

        if (! is_file(base_path($rel))) {
            $missing[] = $rel;

            continue;
        }

        $parsed = CssDirection::declarationCount(base_path(), $rel);

        // The reader must still see roughly the whole file. A generous margin:
        // this is here to catch "returns 0 / returns 3", not to police edits.
        if ($parsed < (int) $total * 0.5) {
            $thin[] = sprintf('%s: audit recorded %s declarations, the reader now finds %d', $rel, $total, $parsed);
        }
    }

    expect($missing)->toBe([], 'Files in the T6 scope no longer exist: '.implode(', ', $missing));
    expect($thin)->toBe([], "The CSS reader has stopped seeing most of these files, so every other assertion in this file is vacuous:\n".implode("\n", $thin));
});

it('converted the physical direction declarations it says it converted', function () {
    $rows = CssDirection::markdownTable(rtlAuditDocument(), 'rtl-audit:floors');

    $short = [];

    foreach ($rows as [$file, $logical]) {
        $rel = trim($file, '` ');
        $floor = (int) trim($logical);
        $found = CssDirection::logicalCount(base_path(), $rel);

        if ($found < $floor) {
            $short[] = sprintf('%s: %d logical direction declarations, audit records %d', $rel, $found, $floor);
        }
    }

    // A floor, not an equality: adding new logical CSS is the desired direction
    // of travel and must not fail a test. Losing it is the regression.
    expect($short)->toBe([], "Logical direction declarations have gone missing — something converted back to physical:\n".implode("\n", $short));

    // And the floors must add up to real work, so this test cannot be satisfied
    // by an audit document that records zero everywhere.
    $total = array_sum(array_map(fn (array $r): int => (int) trim($r[1]), $rows));
    expect($total)->toBeGreaterThanOrEqual(360, 'The audit records fewer conversions than T6 made; the document has been trimmed.');
});

it('lets no physical direction declaration back into the storefront stylesheets', function () {
    $documented = [];

    foreach (CssDirection::markdownTable(rtlAuditDocument(), 'rtl-audit:physical') as $row) {
        [$file, $selector, $declaration] = $row;
        $rel = trim($file, '` ');
        [$property, $value] = array_map('trim', explode(':', trim($declaration, '` '), 2));
        $documented[$rel.' | '.trim($selector, '` ').' | '.$property.' | '.$value] = true;
    }

    expect($documented)->not->toBeEmpty('docs/rtl-audit.md documents no physical declarations at all.');

    $found = [];

    foreach (CssDirection::SCOPE as $rel) {
        $found += CssDirection::physicalIn(base_path(), $rel);
    }

    $undocumented = array_diff_key($found, $documented);

    expect(array_keys($undocumented))->toBe([], <<<'TXT'
        A physical direction property is back in a file T6 converted.

        Either convert it (margin-left -> margin-inline-start, left ->
        inset-inline-start, text-align:left -> text-align:start, and so on — the
        full map is in Tests\Support\CssDirection::MAP), or, if it genuinely has
        to stay physical, add it to the table in docs/rtl-audit.md with the
        reason and mark the rule with an RTL-PHYSICAL: comment.

        Offending declarations:
        TXT."\n".implode("\n", array_keys($undocumented)));
});

it('keeps the audit document from describing stylesheets that have moved on', function () {
    $documented = [];

    foreach (CssDirection::markdownTable(rtlAuditDocument(), 'rtl-audit:physical') as $row) {
        [$file, $selector, $declaration] = $row;
        $rel = trim($file, '` ');
        [$property, $value] = array_map('trim', explode(':', trim($declaration, '` '), 2));
        $documented[$rel.' | '.trim($selector, '` ').' | '.$property.' | '.$value] = true;
    }

    $found = [];

    foreach (CssDirection::SCOPE as $rel) {
        $found += CssDirection::physicalIn(base_path(), $rel);
    }

    $stale = array_diff_key($documented, $found);

    expect(array_keys($stale))->toBe([], <<<'TXT'
        docs/rtl-audit.md documents physical declarations that are no longer in
        the stylesheets. If the rule was converted, deleted or edited, take the
        row out of the audit table; the point of this pair of tests is that the
        two cannot drift apart.

        Stale rows:
        TXT."\n".implode("\n", array_keys($stale)));
});

it('marks every file that keeps a physical declaration with the reason in the source', function () {
    // The audit table says why. This checks that someone reading only the
    // stylesheet is told too, so the next person does not "fix" it.
    $unmarked = [];

    foreach (CssDirection::SCOPE as $rel) {
        if (CssDirection::physicalIn(base_path(), $rel) === []) {
            continue;
        }

        if (! str_contains((string) file_get_contents(base_path($rel)), 'RTL-PHYSICAL')) {
            $unmarked[] = $rel;
        }
    }

    expect($unmarked)->toBe([], 'These files keep a physical direction declaration but carry no RTL-PHYSICAL comment saying why: '.implode(', ', $unmarked));
});
