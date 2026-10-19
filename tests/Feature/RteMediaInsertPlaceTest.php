<?php

/*
 * Where a picture lands when it is inserted into an admin rich-text box --
 * Lane BIM, 19 October 2026.
 *
 * WHAT THE OWNER SAW. Appearance is Content → Blog posts → (a post) → The
 * article → "Media". "in blog when i insert image. the image change it's
 * position to random ... i added the image at one place, it's added in some
 * different location." The shared gallery script (admin/partials/rte-media)
 * climbed from the cursor to the box's TOP-LEVEL child and inserted after it.
 * An article inside one wrapper (<div>, <section>, or the <b> Google Docs
 * wraps a paste in) has a single top-level child, so the picture went to the
 * END of the article wherever the cursor was. A cursor between blocks (right
 * after an earlier gallery) climbed to nothing and went to the end too; a
 * cursor that had left the box (a click in the title) went to the end; in
 * plain <br> lines the picture split the sentence at the next link; and a
 * cursor at the start of a paragraph put it after that paragraph.
 *
 * Measured in Chromium against the old script: wrapped, docsWrapper,
 * afterGallery, afterTitle and typedAbove all landed after "words of 10.";
 * plainLines between "Paragraph 3 with" and "a link"; startOfP4 after
 * paragraph 4. MUTATION: put the old insert() back (climb to the top-level
 * child, append when none) and this is red on those seven cases.
 */

function bimChrome(): ?string
{
    $chrome = (string) env('KBB_BROWSER_CHROME', '/opt/pw-browsers/chromium-1194/chrome-linux/chrome');
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    $pw = (string) env('KBB_BROWSER_NODE_PATH', '/opt/node22/lib/node_modules');

    return is_file($chrome) && $node !== '' && is_dir($pw . '/playwright') ? $chrome : null;
}

function bimRun(): array
{
    $chrome = bimChrome();
    if ($chrome === null) {
        test()->markTestSkipped('node, playwright or Chromium is not installed here; tests/browser/bim-gallery-insert.mjs needs them.');
    }

    $file = storage_path('framework/testing/bim-rte-media-' . getmypid() . '.html');
    @mkdir(dirname($file), 0o777, true);
    file_put_contents($file, view('admin.partials.rte-media')->render());

    $env = 'KBB_BIM_PARTIAL=' . escapeshellarg($file)
        . ' KBB_BROWSER_CHROME=' . escapeshellarg($chrome)
        . ' NODE_PATH=' . escapeshellarg((string) env('KBB_BROWSER_NODE_PATH', '/opt/node22/lib/node_modules'));
    $raw = (string) shell_exec($env . ' node ' . escapeshellarg(base_path('tests/browser/bim-gallery-insert.mjs')) . ' 2>&1');
    @unlink($file);

    $r = json_decode($raw, true);
    expect($r)->toBeArray('no report: ' . substr($raw, 0, 600))
        ->and($r['ok'] ?? false)->toBeTrue($r['error'] ?? '');

    return $r['cases'];
}

it('puts a picture where the cursor was, in every shape an article comes in -- in Chromium', function () {
    $c = bimRun();

    $at = function (string $case, string $before, string $after, string $parent = 'box') use ($c) {
        expect($c[$case]['found'] ?? false)->toBeTrue("{$case}: no gallery")
            ->and($c[$case]['before'])->toEndWith($before, "{$case}: before")
            ->and($after === '' ? $c[$case]['after'] === '' : str_starts_with($c[$case]['after'], $after))->toBeTrue("{$case}: after is '{$c[$case]['after']}'")
            ->and($c[$case]['parent'])->toBe($parent, "{$case}: parent")
            ->and($c[$case]['insideLi'])->toBeFalse()
            ->and($c[$case]['galleries'])->toBe(1)
            ->and($c[$case]['otherBoxGalleries'])->toBe(0)
            ->and($c[$case]['errors'])->toBe([]);
    };

    // The end of paragraph 3 of 10, one picture and a 2-picture row.
    $at('endOfP3', 'words of 3.', 'Paragraph 4');
    $at('row2EndOfP3', 'words of 3.', 'Paragraph 4');
    expect($c['row2EndOfP3']['cls'])->toBe('kbb-gal kbb-gal-2')->and($c['row2EndOfP3']['imgs'])->toBe(2);
    // Inside a list: after the list, never inside a bullet.
    $at('inList', 'Bullet three', 'Paragraph 6');
    // Right after a heading.
    $at('afterHeading', 'A heading', 'Paragraph 4');
    // The whole article inside one wrapper: inside it, after paragraph 3.
    $at('wrapped', 'words of 3.', 'Paragraph 4', 'div.entry');
    $at('docsWrapper', 'words of 3.', 'Paragraph 4', 'b.docs');
    // Plain lines: at the end of line 3, not through the middle of it.
    $at('plainLines', '3 with a link words.', 'Paragraph 4');
    // At the start of paragraph 4: before it.
    $at('startOfP4', 'words of 3.', 'Paragraph 4');
    // Right after an earlier gallery (cursor between blocks): exactly there.
    $at('afterGallery', 'words of 3.', 'Paragraph 4');
    // A real click in paragraph 3.
    $at('clickedP3', 'words of 3.', 'Paragraph 4');
    // Scrolled away and back up to the toolbar.
    $at('afterScroll', 'words of 7.', 'Paragraph 8');
    // The cursor left the article for the title box: its last place.
    $at('afterTitle', 'words of 5.', 'Paragraph 6');
    $at('typedAbove', 'words of 1.', 'Paragraph 2');
    // Never in the article this visit: the end.
    $at('freshLoad', 'words of 10.', '');
    // The Arabic box's own button: its own box, where its cursor was.
    $at('arabic', 'فقرة أولى', 'فقرة ثانية');
});

it('keeps the rule in the one shared script, and still measures nothing', function () {
    $js = (string) file_get_contents(resource_path('views/admin/partials/rte-media.blade.php'));

    expect($js)->toContain('function spot(area, r)')
        ->toContain("document.addEventListener('selectionchange'")
        ->toContain('var at = spot(area, saved);')
        // The old rule: the top-level child, or the end.
        ->not->toContain('while (after && after.parentNode !== area) after = after.parentNode;')
        ->not->toContain('getBoundingClientRect')->not->toContain('setTimeout')->not->toContain('setInterval');
});
