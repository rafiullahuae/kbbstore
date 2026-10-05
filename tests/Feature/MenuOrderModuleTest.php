<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Menu;
use App\Models\MenuItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mega menu ordering without aiming — Lane MO.
 *
 * The owner, of the drag-and-drop editor: "i can write sort number, and it go
 * on that. i don't [like] the line of placement where i need to drop the item,
 * the line is super thin, i can't catch."
 *
 * He chose layout A, compact: every top-level item a 156px column, its
 * sub-menus and links inside, 24px rows. resources/js/kbb/admin/menu-order.js
 * gives every row a sort number and arrows, a ⋯ panel with "Move to column…",
 * live drag that decides placement from the row entered and the direction of
 * travel, inline add and a floating +. The cases below EXECUTE it in node
 * (skipped where node is absent, as StorefrontJsEscapingTest does), and the
 * depth-rule cases drive the real move endpoint with what the module would
 * send, so "offered" and "accepted" are compared rather than reasoned about.
 *
 * The server half: MegaMenuApiController::move and ::reorder validated each id
 * only with `exists`, so a request could renumber another branch or another
 * menu. They now take exactly one sibling group.
 */
function moModule(): string
{
    return resource_path('js/kbb/admin/menu-order.js');
}

/** Runs $body (JS, with M = the module and T = $tree) in node and returns what it prints as JSON. */
function moNode(array $tree, string $body): array
{
    if (trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
        test()->markTestSkipped('node is not on this machine');
    }

    // The module is a classic browser script (it sets self.KBBMenuOrder), so
    // it is run as one — in a vm context with a stand-in `self` — rather than
    // imported.
    $js = "const vm = require('node:vm'), fs = require('node:fs');\n"
        . "const ctx = { self: {} }; vm.runInNewContext(fs.readFileSync(" . json_encode(moModule()) . ", 'utf8'), ctx);\n"
        . "const M = ctx.self.KBBMenuOrder;\n"
        . "const T = " . json_encode($tree) . ";\n"
        . "(async () => {\n" . $body . "\n})().then(o => process.stdout.write(JSON.stringify(o)), e => { process.stdout.write('ERR ' + e.stack); });\n";

    $file = tempnam(sys_get_temp_dir(), 'kbbmo') . '.cjs';
    file_put_contents($file, $js);
    $raw = (string) shell_exec('node ' . escapeshellarg($file) . ' 2>&1');
    @unlink($file);

    $out = json_decode($raw, true);
    expect($out)->toBeArray('node could not run the probe: ' . $raw);

    return $out;
}

/** Five top-level items; "Skincare" holds two columns, the first with three links. */
function moTree(): array
{
    $leaf = fn (int $id, string $label) => ['id' => $id, 'label' => $label, 'children' => []];

    return [
        $leaf(1, 'Blog'),
        $leaf(2, 'Everything Under 54 AED'),
        ['id' => 3, 'label' => 'Skincare', 'children' => [
            ['id' => 31, 'label' => 'Cleansers', 'children' => [$leaf(311, 'Oil'), $leaf(312, 'Foam'), $leaf(313, 'Balm')]],
            ['id' => 32, 'label' => 'Toners', 'children' => [$leaf(321, 'Pads')]],
        ]],
        $leaf(4, 'Hair Care'),
        $leaf(5, 'Brands'),
    ];
}

it('moves an item to the number typed — first, middle, last — and clamps out-of-range', function () {
    /*
     * The owner's own ask: "i can write sort number, and it go on that."
     * MUTATION NOTE: drop the Math.min/Math.max clamp in parsePosition and
     * '99' and '0' come back null (the box would revert instead of moving);
     * make planMove splice at `position` rather than `position - 1` and every
     * order below is off by one.
     */
    $o = moNode(moTree(), <<<'JS'
    const p = (id, typed) => { const r = M.planPosition(T, id, typed); return r ? r.ids : null; };
    return {
      first:  p(4, '1'),
      middle: p(1, '3'),
      last:   p(1, '5'),
      over:   p(2, '99'),
      zero:   p(4, '0'),
      neg:    p(4, '-3'),
      spaced: p(5, ' 2 '),
      word:   p(1, 'abc'),
      empty:  p(1, ''),
      frac:   p(1, '2.5'),
      same:   M.planPosition(T, 3, '3').noop,
      link:   p(313, '1'),
      column: p(32, '1'),
    };
    JS);

    expect($o['first'])->toBe([4, 1, 2, 3, 5])
        ->and($o['middle'])->toBe([2, 3, 1, 4, 5])
        ->and($o['last'])->toBe([2, 3, 4, 5, 1])
        ->and($o['over'])->toBe([1, 3, 4, 5, 2])
        ->and($o['zero'])->toBe([4, 1, 2, 3, 5])
        ->and($o['neg'])->toBe([4, 1, 2, 3, 5])
        ->and($o['spaced'])->toBe([1, 5, 2, 3, 4])
        // Not a number: no move, the box goes back to the position it had.
        ->and($o['word'])->toBeNull()
        ->and($o['empty'])->toBeNull()
        ->and($o['frac'])->toBeNull()
        // Its own number again sends nothing.
        ->and($o['same'])->toBeTrue()
        // Every level, not just the top.
        ->and($o['link'])->toBe([313, 311, 312])
        ->and($o['column'])->toBe([32, 31]);
});

it('steps up and down (left and right for columns), and the arrows are disabled at the ends', function () {
    /*
     * MUTATION NOTE: remove the `to < 0 || to >= length` guard in planStep and
     * `topUp` becomes a plan instead of null; drop `pos === 1` from arrows()
     * and `disFirst` reads 0.
     */
    $o = moNode(moTree(), <<<'JS'
    const s = (id, d) => { const r = M.planStep(T, id, d); return r ? r.ids : null; };
    const row = (id) => M.itemHtml(T, id).split('</div>')[0];
    const dis = (id) => (row(id).match(/ disabled/g) || []).length;
    return {
      topUp: s(1, -1), topDown: s(1, 1), lastDown: s(5, 1), lastUp: s(5, -1), midUp: s(3, -1),
      linkUp: s(311, -1), linkDown: s(311, 1),
      disFirst: dis(1), disMid: dis(3), disLast: dis(5), disOnly: dis(321),
      col: row(1), link: row(311),
    };
    JS);

    expect($o['topUp'])->toBeNull()
        ->and($o['topDown'])->toBe([2, 1, 3, 4, 5])
        ->and($o['lastDown'])->toBeNull()
        ->and($o['lastUp'])->toBe([1, 2, 3, 5, 4])
        ->and($o['midUp'])->toBe([1, 3, 2, 4, 5])
        ->and($o['linkUp'])->toBeNull()
        ->and($o['linkDown'])->toBe([312, 311, 313])
        ->and($o['disFirst'])->toBe(1)
        ->and($o['disMid'])->toBe(0)
        ->and($o['disLast'])->toBe(1)
        ->and($o['disOnly'])->toBe(2)
        // Keyboard and screen-reader users hear which item and which way.
        ->and($o['col'])->toContain('aria-label="Move Blog left"')
        ->and($o['col'])->toContain('aria-label="Move Blog right"')
        ->and($o['col'])->toContain('aria-label="Position of Blog, 1 to 5"')
        ->and($o['link'])->toContain('aria-label="Move Oil up"')
        ->and($o['link'])->toContain('aria-label="Move Oil down"');
});

it('places a drag from the row entered and the direction of travel, and sends the right move', function () {
    /*
     * The thin line is gone: where a dragged row lands is decided by WHICH row
     * the pointer has just entered, never by where inside it.
     * MUTATION NOTE: flip `me.index < t.index` in place() and a row dragged
     * down its own list lands above the row it passed (`swapDown` reads
     * [311, 312, 313]); flip `dir < 0` and the cross-list cases swap.
     */
    $o = moNode(moTree(), <<<'JS'
    const P = (id, over, dir) => { const r = M.place(T, id, over, dir); return r === null ? null : r.ok ? { parent: r.parentId, ids: r.ids } : { refused: true }; };
    const work = M.applyPlan(T, M.place(T, 311, { kind: 'row', id: 321 }, 1));
    return {
      swapDown:   P(311, { kind: 'row', id: 312 }, 1),
      swapUp:     P(313, { kind: 'row', id: 312 }, -1),
      crossDown:  P(321, { kind: 'row', id: 312 }, 1),
      crossUp:    P(321, { kind: 'row', id: 312 }, -1),
      intoZone:   P(321, { kind: 'zone', id: 31 }, 1),
      ownZone:    P(311, { kind: 'zone', id: 31 }, 1),
      colHead:    P(312, { kind: 'row', id: 4 }, 1),
      colBody:    P(312, { kind: 'zone', id: 5 }, 1),
      subToCol:   P(31, { kind: 'zone', id: 1 }, 1),
      subIntoSub: P(31, { kind: 'zone', id: 32 }, 1),
      subOnLink:  P(31, { kind: 'row', id: 321 }, 1),
      // Toners emptied first (its one link moved to Cleansers), then dragged onto a link.
      leafSubOnLink: (() => { const T2 = M.applyPlan(T, M.place(T, 321, { kind: 'zone', id: 31 }, 1)); const r = M.place(T2, 32, { kind: 'row', id: 312 }, 1); return r && r.ok ? { parent: r.parentId, ids: r.ids } : r; })(),
      fullSubOnLink: P(32, { kind: 'row', id: 312 }, 1),
      ownBranch:  P(3, { kind: 'row', id: 311 }, 1),
      colSwapR:   P(1, { kind: 'row', id: 3 }, 1),
      colSwapL:   P(4, { kind: 'zone', id: 2 }, -1),
      self:       P(311, { kind: 'row', id: 311 }, 1),
      sent:       M.planFrom(T, work, 311),
      back:       M.planFrom(T, T, 311).noop,
    };
    JS);

    expect($o['swapDown'])->toBe(['parent' => 31, 'ids' => [312, 311, 313]])
        ->and($o['swapUp'])->toBe(['parent' => 31, 'ids' => [311, 313, 312]])
        // Arriving from another list: above when travelling down, below when travelling up.
        ->and($o['crossDown'])->toBe(['parent' => 31, 'ids' => [311, 321, 312, 313]])
        ->and($o['crossUp'])->toBe(['parent' => 31, 'ids' => [311, 312, 321, 313]])
        ->and($o['intoZone'])->toBe(['parent' => 31, 'ids' => [311, 312, 313, 321]])
        ->and($o['ownZone'])->toBeNull()
        // A column's header: first in that column; its body: last.
        ->and($o['colHead'])->toBe(['parent' => 4, 'ids' => [312]])
        ->and($o['colBody'])->toBe(['parent' => 5, 'ids' => [312]])
        // A sub-menu with its links may move to another column ...
        ->and($o['subToCol'])->toBe(['parent' => 1, 'ids' => [31]])
        // ... but never into a sub-menu: that would push its links to a fourth level. Refused, visibly.
        ->and($o['subIntoSub'])->toBe(['refused' => true])
        ->and($o['subOnLink'])->toBe(['refused' => true])
        // An empty sub-menu may become a link.
        ->and($o['leafSubOnLink'])->toBe(['parent' => 31, 'ids' => [311, 32, 312, 313, 321]])
        ->and($o['fullSubOnLink'])->toBe(['refused' => true])
        ->and($o['ownBranch'])->toBeNull()
        // Columns trade places with columns only.
        ->and($o['colSwapR'])->toBe(['parent' => null, 'ids' => [2, 3, 1, 4, 5]])
        ->and($o['colSwapL'])->toBe(['parent' => null, 'ids' => [1, 4, 2, 3, 5]])
        ->and($o['self'])->toBeNull()
        // Release sends one move: the target group's full new order.
        ->and($o['sent'])->toMatchArray(['ok' => true, 'noop' => false, 'itemId' => 311, 'parentId' => 32, 'ids' => [311, 321]])
        ->and($o['back'])->toBeTrue();
});

it('never turns a link or a sub-menu into a column, nor a column into anything else', function () {
    // MUTATION NOTE: let canMoveUnder return true for a top-level target and
    // `linkTop` becomes a plan — dragging could create a top-level column.
    $o = moNode(moTree(), <<<'JS'
    return {
      linkTop: M.planMove(T, 311, null).ok,
      subTop: M.planMove(T, 31, null).ok,
      colUnder: M.planMove(T, 1, 3).ok,
      colChoices: M.parentChoices(T, 1).length,
      linkChoices: M.parentChoices(T, 311).map(c => c.label),
      subChoices: M.parentChoices(T, 31).map(c => c.label),
    };
    JS);

    expect($o['linkTop'])->toBeFalse()
        ->and($o['subTop'])->toBeFalse()
        ->and($o['colUnder'])->toBeFalse()
        ->and($o['colChoices'])->toBe(0)
        ->and($o['linkChoices'])->toBe(['Blog', 'Everything Under 54 AED', 'Skincare', 'Skincare › Cleansers', 'Skincare › Toners', 'Hair Care', 'Brands'])
        // Cleansers carries links, so only columns can take it.
        ->and($o['subChoices'])->toBe(['Blog', 'Everything Under 54 AED', 'Skincare', 'Hair Care', 'Brands']);
});

it('offers under "Move to column…" and accepts in a drag exactly what the server accepts', function () {
    /*
     * The old editor lit a drop zone and then bounced the drop. Every (item,
     * parent) pair of a real tree is asked of the module AND posted to the
     * real move endpoint, each in its own rolled-back transaction:
     *   - everything the board offers (the select, or a drag placement) is
     *     accepted by the server — nothing bounces;
     *   - everything the server accepts below the top level is offered — the
     *     depth rule is mirrored, not merely approximated;
     *   - the only pairs the board withholds that the server would take are its
     *     own rule: nothing becomes a column, and a column stays one.
     *
     * MUTATION NOTE: change `<= MAX_DEPTH` to `<= MAX_DEPTH + 1` in
     * canMoveUnder and Cleansers is offered under Toners, which the server
     * refuses — red. Drop the own-branch check and Skincare's sub-menus are
     * offered under their own links — red.
     */
    $admin = AdminUser::create(['name' => 'Owner', 'email' => 'mo-' . uniqid() . '@example.com', 'password' => bcrypt('secret-secret'), 'role' => 'owner']);
    $menu = Menu::create(['name' => 'MO', 'slug' => 'mo-' . Str::random(6)]);

    $mk = function (array $nodes, ?int $parent) use (&$mk, $menu): array {
        $out = [];
        foreach ($nodes as $pos => $n) {
            $row = MenuItem::create(['menu_id' => $menu->id, 'parent_id' => $parent, 'label' => $n['label'], 'url' => '/x/', 'position' => $pos]);
            $out[] = ['id' => $row->id, 'label' => $n['label'], 'children' => $mk($n['children'], $row->id)];
        }

        return $out;
    };
    $tree = $mk(moTree(), null);

    $o = moNode($tree, <<<'JS'
    const all = []; (function w(l, d){ l.forEach(n => { all.push([n, d]); w(n.children, d + 1); }); })(T, 0);
    const pairs = [];
    for (const [it, depth] of all) {
      const offered = depth === 0 ? [null] : M.parentChoices(T, it.id).map(c => c.id);
      // What a drag would do over every row and zone, both directions.
      const dragged = new Set();
      for (const [n] of all) for (const kind of ['row', 'zone']) for (const dir of [1, -1]) {
        const p = M.place(T, it.id, { kind, id: n.id }, dir);
        if (p && p.ok) dragged.add(p.parentId === null ? 'top' : p.parentId);
      }
      for (const target of [null].concat(all.map(([n]) => n.id))) {
        if (target === it.id) continue;
        const group = target === null ? T : M.locate(T, target).node.children;
        pairs.push({ item: it.id, label: it.label, depth, target, offered: offered.includes(target),
                     dragged: dragged.has(target === null ? 'top' : target),
                     ids: group.map(n => n.id).filter(x => x !== it.id).concat([it.id]) });
      }
    }
    return pairs;
    JS);

    expect(count($o))->toBe(11 * 11); // 11 items, each against the top level and the 10 others

    $wrong = [];
    foreach ($o as $p) {
        DB::beginTransaction();
        $ok = $this->actingAs($admin, 'admin')
            ->postJson('/admin-api/mega-menu/' . $p['item'] . '/move', ['parent_id' => $p['target'], 'ids' => $p['ids']])
            ->status() === 200;
        DB::rollBack();

        $boardOnly = $p['depth'] === 0 || $p['target'] === null;
        $where = $p['label'] . ' under ' . var_export($p['target'], true);
        if ($p['offered'] && ! $ok) $wrong[] = "{$where}: offered in the select, refused by the server";
        if ($p['dragged'] && ! $ok) $wrong[] = "{$where}: placed by a drag, refused by the server";
        if ($ok && ! $p['offered'] && ! $boardOnly) $wrong[] = "{$where}: the server accepts it, the select hides it";
    }

    expect($wrong)->toBe([]);
});

it('saves once per action, refuses a second while one is in flight, keeps the change without a re-fetch, and reloads on failure', function () {
    /*
     * MUTATION NOTE: delete `if (inflight) return 'busy'` and the double click
     * sends two requests; delete host.reload() in fail() and `bReloads` reads
     * 0 — the screen would keep an order the server never stored. A re-fetch
     * after a good save would show as aReloads 1.
     */
    $o = moNode(moTree(), <<<'JS'
    const mk = (answer) => {
      const log = { sends: [], toasts: [], reloads: 0, patches: [] };
      let tree = T, release;
      const gate = new Promise(r => { release = r; });
      const host = {
        getTree: () => tree, setTree: t => { tree = t; },
        patch: (plan) => { log.patches.push(plan.itemId); }, reload: () => { log.reloads++; },
        toast: (m, k) => log.toasts.push([m, k || 'ok']),
        send: (id, body) => { log.sends.push([id, body]); return gate.then(() => answer); },
      };
      return { ctl: M.controller(host), log, release, tree: () => tree };
    };

    const a = mk({ ok: true, data: { ok: true } });
    const p1 = a.ctl.step(2, -1);
    const busyDuring = a.ctl.busy();
    const second = await a.ctl.step(3, -1);
    a.release();
    const first = await p1;
    const afterIds = a.tree().map(n => n.id);

    const b = mk({ ok: false, data: { errors: ['The menu changed since this page loaded — reload and try again.'] } });
    const p2 = b.ctl.toPosition(5, '1'); b.release(); const failed = await p2;

    const c = mk({ ok: true });
    const noop = await c.ctl.toPosition(1, '1');
    const bad = await c.ctl.toPosition(1, 'x');
    const refused = await c.ctl.under(311, null);

    return { first, second, busyDuring, sends: a.log.sends, aToasts: a.log.toasts, afterIds, busyAfter: a.ctl.busy(),
             aReloads: a.log.reloads, aPatches: a.log.patches,
             failed, bReloads: b.log.reloads, bToasts: b.log.toasts,
             noop, bad, refused, cSends: c.log.sends.length, cToasts: c.log.toasts };
    JS);

    expect($o['busyDuring'])->toBeTrue()
        ->and($o['second'])->toBe('busy')
        ->and($o['first'])->toBe('saved')
        ->and($o['sends'])->toBe([[2, ['parent_id' => null, 'ids' => [2, 1, 3, 4, 5]]]])
        ->and($o['aToasts'])->toBe([['Saved', 'ok']])
        ->and($o['afterIds'])->toBe([2, 1, 3, 4, 5])
        ->and($o['aReloads'])->toBe(0)
        ->and($o['aPatches'])->toBe([2])
        ->and($o['busyAfter'])->toBeFalse()
        ->and($o['failed'])->toBe('failed')
        ->and($o['bReloads'])->toBe(1)
        ->and($o['bToasts'][0][1])->toBe('bad')
        ->and($o['noop'])->toBe('noop')
        ->and($o['bad'])->toBe('invalid')
        ->and($o['refused'])->toBe('refused')
        ->and($o['cSends'])->toBe(0)
        ->and($o['cToasts'][0][1])->toBe('bad');
});

it('escapes item labels in everything it renders', function () {
    // MUTATION NOTE: print n.label without esc() in nameBtn and the raw tag
    // below appears in the output.
    $o = moNode([['id' => 7, 'label' => '<img src=x onerror=alert(1)>"', 'children' => []], ['id' => 8, 'label' => 'B', 'children' => []]], <<<'JS'
    return { html: M.boardHtml(T) + M.moreHtml(T, 7) + M.itemHtml(T, 8) };
    JS);

    expect($o['html'])->not->toContain('<img')
        ->and($o['html'])->toContain('&lt;img src=x onerror=alert(1)&gt;&quot;');
});

it('measures no layout, runs no timer and cannot close the script tag it is inlined in', function () {
    /*
     * CLAUDE.md: this project sizes with CSS, and the old editor's
     * getBoundingClientRect thirds are exactly what made the drop target thin.
     * The owner: "super light" — the touch hold is a CSS animation, not a timer.
     * MUTATION NOTE: add `row.getBoundingClientRect()` anywhere in the module
     * and this is red.
     */
    $src = (string) file_get_contents(moModule());

    foreach (['getBoundingClientRect', 'getClientRects', 'offsetTop', 'offsetLeft', 'offsetWidth', 'offsetHeight', 'offsetParent',
        'clientWidth', 'clientHeight', 'scrollWidth', 'scrollHeight', 'getComputedStyle', 'elementFromPoint', 'elementsFromPoint',
        'ResizeObserver', 'IntersectionObserver', 'setInterval', 'setTimeout', 'requestAnimationFrame'] as $api) {
        expect(str_contains($src, $api))->toBeFalse("menu-order.js uses {$api}");
    }

    expect(stripos($src, '</script'))->toBeFalse();
});

/* ---------- Server: the move and reorder endpoints take one sibling group ---------- */

function moServerTree(): array
{
    $menu = Menu::create(['name' => 'MO srv', 'slug' => 'mo-srv-' . Str::random(6)]);
    $other = Menu::create(['name' => 'MO other', 'slug' => 'mo-oth-' . Str::random(6)]);
    $a = MenuItem::create(['menu_id' => $menu->id, 'label' => 'A', 'url' => '/a/', 'position' => 0]);
    $b = MenuItem::create(['menu_id' => $menu->id, 'label' => 'B', 'url' => '/b/', 'position' => 1]);
    $c = MenuItem::create(['menu_id' => $menu->id, 'label' => 'C', 'url' => '/c/', 'position' => 2]);
    $a1 = MenuItem::create(['menu_id' => $menu->id, 'parent_id' => $a->id, 'label' => 'A1', 'url' => '/a1/', 'position' => 0]);
    $x = MenuItem::create(['menu_id' => $other->id, 'label' => 'X', 'url' => '/x/', 'position' => 7]);
    $admin = AdminUser::create(['name' => 'Owner', 'email' => 'mo-srv-' . uniqid() . '@example.com', 'password' => bcrypt('secret-secret'), 'role' => 'owner']);

    return compact('a', 'b', 'c', 'a1', 'x', 'admin');
}

it('refuses a move whose ids are not exactly the target group', function () {
    /*
     * Before: `ids.*` was only `exists:menu_items,id`, so this request set the
     * position of item X in a DIFFERENT menu to 1, and one leaving B out gave
     * two rows of the top level the same position.
     * MUTATION NOTE: delete the `$given !== $expected` block in move() and the
     * three 422s below are 200s, and X's position reads 1.
     */
    ['a' => $a, 'b' => $b, 'c' => $c, 'x' => $x, 'admin' => $admin] = moServerTree();
    $post = fn (array $ids, ?int $parent = null) => $this->actingAs($admin, 'admin')
        ->postJson("/admin-api/mega-menu/{$c->id}/move", ['parent_id' => $parent, 'ids' => $ids]);

    $post([$c->id, $x->id, $a->id, $b->id])->assertStatus(422);   // another menu's item
    $post([$c->id, $a->id])->assertStatus(422);                    // a sibling left out
    $post([$c->id, $a->id, $a->id, $b->id])->assertStatus(422);    // one twice, B missing
    $post([$a->id, $b->id])->assertStatus(422);                    // the item itself missing

    expect((int) $x->fresh()->position)->toBe(7);

    $post([$c->id, $a->id, $b->id])->assertOk();
    expect([(int) $c->fresh()->position, (int) $a->fresh()->position, (int) $b->fresh()->position])->toBe([0, 1, 2]);

    // Into A, after A1: the group is A's children plus C.
    $this->actingAs($admin, 'admin')
        ->postJson("/admin-api/mega-menu/{$b->id}/move", ['parent_id' => $a->id, 'ids' => [moId($a, 'A1'), $b->id]])
        ->assertOk();
    expect((int) $b->fresh()->parent_id)->toBe($a->id);
});

function moId(MenuItem $parent, string $label): int
{
    return (int) MenuItem::where('parent_id', $parent->id)->where('label', $label)->value('id');
}

it('refuses a reorder that spans two groups or two menus, and keeps one that does not', function () {
    // MUTATION NOTE: delete the one-group check in reorder() and the first
    // two requests are 200 and X's position is rewritten.
    ['a' => $a, 'b' => $b, 'c' => $c, 'a1' => $a1, 'x' => $x, 'admin' => $admin] = moServerTree();
    $post = fn (array $ids) => $this->actingAs($admin, 'admin')->postJson('/admin-api/mega-menu/reorder', ['ids' => $ids]);

    $post([$c->id, $x->id])->assertStatus(422);
    $post([$c->id, $a1->id])->assertStatus(422);
    $post([$c->id, $c->id])->assertStatus(422);
    expect((int) $x->fresh()->position)->toBe(7);

    $post([$c->id, $b->id, $a->id])->assertOk();
    expect((int) $a->fresh()->position)->toBe(2);
});

it('still refuses a signed-out move', function () {
    ['a' => $a, 'b' => $b, 'c' => $c] = moServerTree();
    $r = $this->postJson("/admin-api/mega-menu/{$c->id}/move", ['parent_id' => null, 'ids' => [$c->id, $a->id, $b->id]]);
    expect($r->status())->toBeIn([401, 403, 302, 419]);
    expect((int) $c->fresh()->position)->toBe(2);
});

it('ships the module whole inside its partial, ready for the one include', function () {
    // MUTATION NOTE: drop the file_get_contents line from the partial and the
    // screen would call an undefined KBBMenuOrder — red here.
    $html = view('admin.partials.menu-order')->render();

    expect(substr_count($html, 'root.KBBMenuOrder = factory();'))->toBe(1)
        ->and(substr_count($html, '<script>'))->toBe(1)
        ->and($html)->toContain('.mo-num{');
});

it('refuses to create an item under a parent on another menu', function () {
    // MUTATION NOTE: delete the menu_id check in store() and this is a 200
    // that files a row in one menu under a branch of another.
    ['x' => $x, 'a' => $a, 'admin' => $admin] = moServerTree();

    $this->actingAs($admin, 'admin')
        ->postJson('/admin-api/mega-menu', ['menu_id' => $a->menu_id, 'parent_id' => $x->id, 'label' => 'Stray'])
        ->assertStatus(422);
    expect(MenuItem::where('label', 'Stray')->exists())->toBeFalse();

    $this->actingAs($admin, 'admin')
        ->postJson('/admin-api/mega-menu', ['menu_id' => $a->menu_id, 'parent_id' => $a->id, 'label' => 'Kept'])
        ->assertOk()->assertJsonStructure(['ok', 'id']);
});

it('draws the floating + as an accessible button that opens a three-choice sheet, hidden while dragging', function () {
    /*
     * The owner's third ask: a round + with an outer ring, bottom-right, that
     * offers the three kinds of add. MUTATION NOTE: drop aria-expanded from
     * fabHtml, or the `.mo-dragging .mo-fab` rule from the partial, and this
     * is red.
     */
    $o = moNode(moTree(), <<<'JS'
    return { fab: M.fabHtml(), board: M.boardHtml(T) };
    JS);
    $css = (string) file_get_contents(resource_path('views/admin/partials/menu-order.blade.php'));

    expect($o['fab'])->toContain('<button type="button" class="mo-fab" data-mo-fab aria-label="Add to the menu" aria-haspopup="dialog" aria-expanded="false" aria-controls="moSheet">')
        ->and($o['fab'])->toContain('id="moSheet" role="dialog" aria-label="Add to the menu" hidden')
        ->and(substr_count($o['fab'], 'data-mo-choice='))->toBe(3)
        ->and($o['fab'])->toContain('>Add a parent menu (top-level)</button>')
        ->and($o['fab'])->toContain('>Add a sub-menu to…</button>')
        ->and($o['fab'])->toContain('>Add a link to…</button>')
        ->and(substr_count($o['board'], 'data-mo-fab'))->toBe(1);

    expect($css)->toMatch('/\.mo-fab\{position:fixed;right:22px;bottom:22px;[^}]*width:52px;height:52px;[^}]*border-radius:50%;[^}]*box-shadow:0 0 0 5px /')
        ->and($css)->toContain('.mo-fab:focus-visible{outline:3px solid')
        ->and($css)->toContain('.mo-dragging .mo-fab{opacity:0;');
});

it('keeps the owner\'s compact sizes and animates only transform and opacity', function () {
    /*
     * "thin rows and small stuff, so maximum columns can be visible": 156px
     * columns, 24px rows, a 24x20 number box, 18x20 arrows, an 8px gap.
     * MUTATION NOTE: widen .mo-col to 260px (the first preview) and this is red.
     */
    $css = (string) file_get_contents(resource_path('views/admin/partials/menu-order.blade.php'));

    expect($css)->toContain('.mo-cols{display:flex;align-items:flex-start;gap:8px;')
        ->and($css)->toContain('.mo-col{flex:0 0 156px;width:156px;min-width:0;')
        ->and($css)->toMatch('/\.mo-row\{[^}]*height:24px;/')
        ->and($css)->toMatch('/\.mo-num\{[^}]*width:24px;height:20px;/')
        ->and($css)->toMatch('/\.mo-ib\{[^}]*width:18px;height:20px;/')
        ->and($css)->toMatch('/\.mo-name\{[^}]*text-overflow:ellipsis;white-space:nowrap/')
        ->and($css)->toContain('@media (prefers-reduced-motion:reduce)');

    preg_match_all('/@keyframes [\w-]+\{(.*?)\}\}/s', $css, $m);
    expect(count($m[1]))->toBeGreaterThan(5);
    foreach ($m[1] as $frames) {
        preg_match_all('/([a-z-]+):/', $frames, $props);
        expect(array_diff(array_unique($props[1]), ['transform', 'opacity']))->toBe([], "a keyframe animates something other than transform/opacity: {$frames}");
    }
});
