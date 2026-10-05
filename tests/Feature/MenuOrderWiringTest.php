<?php

declare(strict_types=1);

/**
 * Lane MO's handover: docs/mo-wiring.json, applied by tools/mo-wire.php —
 * Store → Mega Menu's tree and its thin-line drag become the column board.
 *
 * Pins the FINISHED state, never the absence of it (CLAUDE.md): each edit is
 * applied in memory when the integrator has not applied it yet, and the
 * result must carry each line exactly once. Green in the lane's worktree,
 * green after the integrator runs the tool, red for "built, never wired"
 * (zero) and for a doubled merge (two).
 */
function moWired(): array
{
    $edits = json_decode((string) file_get_contents(base_path('docs/mo-wiring.json')), true, 512, JSON_THROW_ON_ERROR);
    $files = [];
    $problems = [];

    foreach ($edits as $e) {
        $files[$e['file']] ??= (string) file_get_contents(base_path($e['file']));
        $src = $files[$e['file']];

        $applied = $e['replacement'] === ''
            ? substr_count($src, $e['anchor']) === 0
            : substr_count($src, $e['replacement']) >= $e['count'];
        if ($applied) {
            continue;   // the integrator has applied it
        }

        $n = substr_count($src, $e['anchor']);
        if ($n !== $e['count']) {
            $problems[] = "block {$e['n']} ({$e['file']}): anchor found {$n} times, expected {$e['count']}";
            continue;
        }

        $files[$e['file']] = str_replace($e['anchor'], $e['replacement'], $src);
    }

    return ['files' => $files, 'problems' => $problems, 'edits' => $edits];
}

it('can apply the handover, every anchor exactly as often as it says', function () {
    /* MUTATION: change any anchor in docs/mo-wiring.json by one character -> red, naming the block. */
    $w = moWired();

    expect(array_column($w['edits'], 'n'))->toBe([1, 2, 3, 4, 5, 6, 7])
        ->and($w['problems'])->toBe([], implode("\n", $w['problems']));
});

it('loads the board once and mounts it once, in the screen that paints #mgmTree', function () {
    /*
     * Zero is the board built and never drawn; two would bind every listener
     * twice and send two moves per click.
     * MUTATION: delete block 1 or block 4 from the JSON -> a count reads 0.
     */
    $app = moWired()['files']['resources/views/admin/app.blade.php'];

    expect(substr_count($app, "@include('admin.partials.menu-order')"))->toBe(1)
        ->and(substr_count($app, 'KBBMenuOrder.mount('))->toBe(1)
        ->and(substr_count($app, '<div class="mgmtree" id="mgmTree"></div>'))->toBe(1);

    // Mounted inside bindMegaMenu, which paintMegaMenu calls after drawing #mgmTree.
    $bind = substr($app, strpos($app, 'function bindMegaMenu(){'), 4000);
    expect($bind)->toContain("KBBMenuOrder.mount(\$('#mgmTree'), {")
        ->and($bind)->toContain('api: mgmApi,')
        ->and($bind)->toContain('confirmDelete: label => mgmDeleteConfirm(label),');

    // The board is loaded before the screen can paint: its include sits with
    // the other shared admin partials, after the main script defines mgmApi.
    expect(strpos($app, "@include('admin.partials.menu-order')"))->toBeGreaterThan(strpos($app, 'async function mgmApi('));
});

it('leaves exactly one way of reordering: the old thin-line drag and its tree are gone', function () {
    /*
     * Two drag systems on one screen would both answer a drag. These names
     * are the old code's; after the handover each occurs zero times, and the
     * board's own entry point once — asserted together, so this is the
     * finished state, not a lane proving it did not wire itself.
     */
    $app = moWired()['files']['resources/views/admin/app.blade.php'];

    foreach (['function mgmBindDragDrop(', 'function mgmSetDropIndicator(', 'function mgmCanNestInto(', 'function mgmPerformDrop(', 'function mgmRow(', 'let mgmDragId'] as $old) {
        expect(substr_count($app, $old))->toBe(0, "{$old} is still in the console");
    }
    expect(substr_count($app, 'KBBMenuOrder.mount('))->toBe(1)
        ->and($app)->not->toContain('Drag the ⠿ handle');
});

it('keeps every capability the tree had: edit, delete, add top-level, the preview strip', function () {
    $app = moWired()['files']['resources/views/admin/app.blade.php'];

    expect(substr_count($app, 'id="mgmAddTop"'))->toBe(1)
        ->and(substr_count($app, 'function mgmOpenForm('))->toBe(1)
        ->and(substr_count($app, 'function mgmDeleteConfirm('))->toBe(1)
        ->and(substr_count($app, 'function mgmFind('))->toBe(1)
        ->and($app)->toContain("edit: id => { const it = mgmFind(id); if(it) mgmOpenForm(it.parent_id ?? null, it._depth, it); },")
        ->and($app)->toContain("changed: () => { const pv = \$('.mgmpv'); if(pv) pv.outerHTML = mgmPreview(); },");
});

it('applies cleanly through the tool, twice, writing the same file', function () {
    /*
     * The tool and the record cannot drift: run tools/mo-wire.php against a
     * copy and compare with the in-memory result. Run it again: no change.
     */
    $tmp = sys_get_temp_dir() . '/mo-wire-' . uniqid();
    @mkdir($tmp . '/tools', 0777, true);
    @mkdir($tmp . '/docs', 0777, true);
    @mkdir($tmp . '/resources/views/admin', 0777, true);
    copy(base_path('tools/mo-wire.php'), $tmp . '/tools/mo-wire.php');
    copy(base_path('docs/mo-wiring.json'), $tmp . '/docs/mo-wiring.json');
    copy(base_path('resources/views/admin/app.blade.php'), $tmp . '/resources/views/admin/app.blade.php');

    exec('php ' . escapeshellarg($tmp . '/tools/mo-wire.php') . ' 2>&1', $out1, $rc1);
    $once = (string) file_get_contents($tmp . '/resources/views/admin/app.blade.php');
    exec('php ' . escapeshellarg($tmp . '/tools/mo-wire.php') . ' 2>&1', $out2, $rc2);
    $twice = (string) file_get_contents($tmp . '/resources/views/admin/app.blade.php');
    exec('rm -rf ' . escapeshellarg($tmp));

    expect($rc1)->toBe(0, implode("\n", $out1))
        ->and($rc2)->toBe(0)
        ->and($once)->toBe(moWired()['files']['resources/views/admin/app.blade.php'])
        ->and($twice)->toBe($once)
        ->and(implode("\n", $out2))->not->toContain('applied —');
});
