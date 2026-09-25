<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Product;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use App\Support\ConcernCollections;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\BuildMyRoutineRoutes;

/**
 * Lane Q4 — bulk tagging on Catalog → Build my routine.
 *
 * ── WHY THIS EXISTS ────────────────────────────────────────────────────────
 *
 * docs/SEO-FEATURE-MATRIX.md §2 ranks concern-led landing pages as the largest
 * gap against the competitor and §3 item 1 calls the tagging behind them "the
 * single biggest blocker in the whole plan". docs/SEO-CONCERN-MAPPING.md §1
 * then recorded, in as many words, the thing that made the job long:
 *
 *     "There is no bulk-tagging endpoint. Tagging is per product, which is why
 *      §3 is organised to make each search return a group that all gets the
 *      same chip."
 *
 * The worksheet is organised BY CONCERN. `centella` returns a cleanser, two
 * toners and a cream — one concern, four steps — so the operation that
 * collapses a search into one press is the CONCERN, and a bulk control that
 * only set steps would have missed the job it was built for. Both are here.
 *
 * ── WHAT EACH SECTION BELOW PINS ───────────────────────────────────────────
 *
 *   1. The write itself, and that it reaches only the ids it was handed.
 *   2. UNDO, which is the reason the endpoint takes per-row values rather than
 *      a verb. A bulk action that mis-tags twenty rows and cannot be reversed
 *      is worse than twenty single presses.
 *   3. SELECTION SCOPE — the page, never the search and never the catalogue.
 *   4. The cost: a bulk write of forty rows is not forty round trips.
 *   5. The capability, which fails closed.
 *   6. Rule 1: applying this changes nothing on the shop.
 */
beforeEach(function () {
    BuildMyRoutineRoutes::wire(app());
    app(SettingsService::class)->setModule('build_my_routine', true);

    // Each case states its own catalogue in full — BuildMyRoutineTest's rule,
    // for its reason: otherwise every count measures the seeder.
    Product::query()->forceDelete();
});

function rtbAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'Q4 '.$role,
        'email' => 'q4-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => $role,
    ]);
}

function rtbProduct(?string $role = null, array $concerns = [], array $attributes = []): Product
{
    return Product::create(array_replace([
        'slug' => 'rtb-'.Str::random(8),
        'name' => 'RTB '.Str::random(5),
        'status' => 'publish',
        'is_visible' => true,
        'price' => 10000,
        'stock_status' => 'instock',
        'routine_role' => $role,
        'routine_concerns' => $concerns === [] ? null : json_encode($concerns),
    ], $attributes));
}

/** POST the bulk endpoint as an owner, and hand back the decoded body. */
function rtbBulk(array $rows, ?AdminUser $as = null): array
{
    return test()->actingAs($as ?: rtbAdmin(), 'admin')
        ->postJson('/admin-api/routine-products-bulk', ['rows' => $rows])
        ->assertOk()
        ->json();
}

function rtbScreen(): string
{
    return (string) file_get_contents(
        resource_path('views/admin/partials/routines-screen.blade.php')
    );
}

/* ═══════════════ 1. the write, and what it is allowed to touch ════════════ */

it('tags a whole search result for one concern in a single request', function () {
    /*
     * THE DEFECT, and it is the friction docs/SEO-CONCERN-MAPPING.md §1 wrote
     * down rather than a crash: `routes/build-my-routine-admin.php` registered
     * `POST /admin-api/routine-products/{id}` and nothing else, so the ONLY way
     * to tag the nine products a worksheet term returns was nine presses and
     * nine round trips. A 40-product session was ~90 presses.
     *
     * MUTATION NOTE: delete the Route::post('/routine-products-bulk', …) line
     * and this is a 404 on the first call.
     */
    $centella = collect(range(1, 9))->map(fn () => rtbProduct());
    $other = rtbProduct();

    $body = rtbBulk($centella->map(fn (Product $p) => [
        'id' => $p->id,
        'concerns' => ['sensitivity'],
    ])->all());

    expect($body['ok'])->toBeTrue()
        ->and($body['changed'])->toBe(9)
        ->and($body['sent'])->toBe(9);

    foreach ($centella as $p) {
        expect(json_decode((string) $p->fresh()->routine_concerns, true))->toBe(['sensitivity']);
    }

    // The row that was not in the payload is untouched. The endpoint takes ids
    // and no filter of any kind, so there is no query it could have widened to.
    expect($other->fresh()->routine_concerns)->toBeNull();
});

it('puts a whole selection into one step without touching their concerns', function () {
    /*
     * The other half of the bar: a step-shaped search (`cleansing foam`,
     * `sunscreen`) where the whole result set belongs to one step.
     *
     * AND IT LEAVES THE CONCERNS ALONE. The two are different fields and the
     * payload carries `role` only, so `concerns` is absent — the same
     * `sometimes` semantics the single-row tag() has always used. A bulk writer
     * that sent both fields every time would blank the concern list of every
     * row it stepped, which is the exact shape of silent data loss this
     * codebase has paid for elsewhere.
     *
     * MUTATION NOTE: make bulk() read `$ask['concerns'] ?? []` instead of
     * testing array_key_exists, and the concerns assertion below goes red with
     * an emptied column.
     */
    $a = rtbProduct(null, ['acne']);
    $b = rtbProduct('tone', ['acne', 'pores']);

    $body = rtbBulk([
        ['id' => $a->id, 'role' => 'cleanse'],
        ['id' => $b->id, 'role' => 'cleanse'],
    ]);

    expect($body['changed'])->toBe(2)
        ->and($a->fresh()->routine_role)->toBe('cleanse')
        ->and($b->fresh()->routine_role)->toBe('cleanse')
        ->and(json_decode((string) $a->fresh()->routine_concerns, true))->toBe(['acne'])
        ->and(json_decode((string) $b->fresh()->routine_concerns, true))->toBe(['acne', 'pores']);
});

it('refuses a concern slug that is not in the vocabulary', function () {
    /*
     * The allowlist, and it is the same one the single-row endpoint enforces.
     * CLAUDE.md rule 5: a select stores one of its own options or the default.
     * A bulk writer is the worst place to relax it — one bad payload would
     * write a slug that is in no vocabulary onto twenty-five rows, and
     * RoutineConcerns::clean() would then silently read every one of them as
     * untagged.
     *
     * MUTATION NOTE: drop the `in:` rule from rows.*.concerns.* and this is a
     * 200.
     */
    $p = rtbProduct();

    test()->actingAs(rtbAdmin(), 'admin')
        ->postJson('/admin-api/routine-products-bulk', [
            'rows' => [['id' => $p->id, 'concerns' => ['hyperpigmentation']]],
        ])
        ->assertStatus(422);

    expect($p->fresh()->routine_concerns)->toBeNull();
});

it('refuses the same product twice rather than guessing which instruction won', function () {
    /*
     * THE DEFECT: two rows for one id is an ambiguous instruction, and both
     * ways of resolving it (first wins, last wins) are a guess at what was
     * meant. Guessing is how a bulk control writes something nobody asked for.
     * The screen cannot produce this — a selection is a set — so refusing costs
     * the operator nothing and closes the door on any other caller.
     *
     * MUTATION NOTE: delete the duplicate check and this returns 200 with the
     * last instruction silently applied.
     */
    $p = rtbProduct();

    test()->actingAs(rtbAdmin(), 'admin')
        ->postJson('/admin-api/routine-products-bulk', [
            'rows' => [
                ['id' => $p->id, 'role' => 'cleanse'],
                ['id' => $p->id, 'role' => 'protect'],
            ],
        ])
        ->assertStatus(422);

    expect($p->fresh()->routine_role)->toBeNull();
});

it('names an id that no longer exists and still writes the rest', function () {
    /*
     * The ids come off a page he is looking at. One of them missing means the
     * product was deleted under him, which he should be told about — while the
     * other rows are exactly what he asked for, and refusing all of them would
     * cost him the selection as well.
     *
     * MUTATION NOTE: drop `missing` from the response and the screen loses the
     * only sentence that distinguishes "39 of 40 saved" from "40 saved".
     */
    $live = rtbProduct();

    $body = rtbBulk([
        ['id' => $live->id, 'role' => 'treat'],
        ['id' => 987654, 'role' => 'treat'],
    ]);

    expect($body['missing'])->toBe([987654])
        ->and($body['changed'])->toBe(1)
        ->and($live->fresh()->routine_role)->toBe('treat');
});

it('does not count a row it did not have to move', function () {
    /*
     * "Tag twelve for sensitivity" when three already carry it moves nine. A
     * screen that reported twelve would be inventing a number, and — much worse
     * — an undo built from twelve rows would include three whose `before` and
     * `after` are identical, making the undo count wrong too.
     *
     * MUTATION NOTE: always push to $roleGroups/$concernGroups instead of
     * testing `$role !== $wasRole`, and `changed` reads 3 here instead of 1.
     */
    $already = rtbProduct('treat', ['acne']);
    $already2 = rtbProduct('treat', ['acne']);
    $fresh = rtbProduct('treat');

    $body = rtbBulk([
        ['id' => $already->id, 'concerns' => ['acne']],
        ['id' => $already2->id, 'concerns' => ['acne']],
        ['id' => $fresh->id, 'concerns' => ['acne']],
    ]);

    expect($body['changed'])->toBe(1)
        ->and($body['sent'])->toBe(3);
});

it('reports how many rows got a concern without having a step', function () {
    /*
     * A CONCERN DOES NOT NEED A STEP, and that is not an oversight.
     * App\Support\ConcernCollections::query() selects on `routine_concerns` and
     * NEVER READS `routine_role`; BuildMyRoutine::coverage() counts the
     * concern-page countdown from the same explicit tags. So a concern tag on a
     * stepless product is exactly what /concern/{slug}/ needs, and refusing one
     * would make the bulk bar useless for the worksheet it exists to serve —
     * on a fresh shop EVERY row is stepless.
     *
     * It is not silent either: the count comes back so the bar can say so.
     *
     * MUTATION NOTE: drop `without_step` and the screen's note about stepless
     * rows has nothing behind it.
     */
    $stepless = rtbProduct();
    $stepped = rtbProduct('tone');

    $body = rtbBulk([
        ['id' => $stepless->id, 'concerns' => ['sensitivity']],
        ['id' => $stepped->id, 'concerns' => ['sensitivity']],
    ]);

    expect($body['without_step'])->toBe(1)
        ->and($body['changed'])->toBe(2)
        ->and(json_decode((string) $stepless->fresh()->routine_concerns, true))->toBe(['sensitivity']);
});

/* ═══════════════════════════════ 2. UNDO ══════════════════════════════════ */

it('hands back what every row held before the write, so undo has something exact to post', function () {
    /*
     * The shape the whole design turns on. Every response row carries `before`,
     * read off the column a moment before the write — not echoed from what the
     * caller sent, and not what this screen believed the row held. The list is
     * a snapshot and another admin, or the product editor in a second tab, may
     * have moved a row since it was drawn.
     *
     * MUTATION NOTE: build `before` from $ask instead of from $product and this
     * reads back the value that was WRITTEN, which makes undo a no-op.
     */
    $p = rtbProduct('tone', ['acne']);

    $body = rtbBulk([['id' => $p->id, 'role' => 'treat', 'concerns' => ['acne', 'dullness']]]);

    expect($body['rows'][0]['before'])->toBe(['role' => 'tone', 'concerns' => ['acne']])
        ->and($body['rows'][0]['role'])->toBe('treat')
        ->and($body['rows'][0]['concerns'])->toBe(['acne', 'dullness']);
});

it('undoes a bulk concern without stripping a tag the product already had', function () {
    /*
     * ── THE DEFECT THIS TEST IS FOR, AND IT IS THE REASON THE ENDPOINT TAKES
     *    PER-ROW VALUES RATHER THAN A VERB ──────────────────────────────────
     *
     * The obvious bulk API is `{ids: […], add_concern: 'sensitivity'}`, and the
     * obvious undo for it is `remove_concern: 'sensitivity'` over the same ids.
     * That is WRONG, and it destroys work rather than merely failing: of the
     * three products below, `$had` already carried `sensitivity` before this
     * session ever started. Undo-by-subtraction takes it off — a tag the owner
     * set on another day, silently removed by pressing Undo on something else.
     *
     * The only honest inverse of a write is the state that was there before it,
     * row by row. So undo replays the `before` values through the SAME
     * endpoint: one writer, one validation path, and an undo that is right by
     * construction instead of by a second implementation agreeing with the
     * first.
     *
     * MUTATION NOTE: change the undo payload below from `before` to a list that
     * strips the concern from every row — `['concerns' => []]` — and $had comes
     * back with nothing, which is exactly the bug.
     */
    $had = rtbProduct('treat', ['sensitivity']);
    $fresh = rtbProduct('treat', ['acne']);
    $bare = rtbProduct('treat');

    $all = [$had, $fresh, $bare];

    // The write the bar makes: every row ends up carrying `sensitivity`, and
    // the rows that already did are not sent at all.
    $applied = rtbBulk([
        ['id' => $fresh->id, 'concerns' => ['acne', 'sensitivity']],
        ['id' => $bare->id, 'concerns' => ['sensitivity']],
    ]);

    expect($applied['changed'])->toBe(2);

    foreach ($all as $p) {
        expect(App\Support\RoutineConcerns::clean($p->fresh()->routine_concerns))
            ->toContain('sensitivity');
    }

    // Undo: post each changed row's own `before` back through the same endpoint.
    $undone = rtbBulk(array_map(static fn (array $r) => [
        'id' => $r['id'],
        'role' => $r['before']['role'],
        'concerns' => $r['before']['concerns'],
    ], $applied['rows']));

    expect($undone['changed'])->toBe(2)
        // The row that already had it KEEPS it. This is the assertion the
        // subtract-the-chip design fails.
        ->and(App\Support\RoutineConcerns::clean($had->fresh()->routine_concerns))->toBe(['sensitivity'])
        ->and(App\Support\RoutineConcerns::clean($fresh->fresh()->routine_concerns))->toBe(['acne'])
        ->and($bare->fresh()->routine_concerns)->toBeNull();
});

it('undoes a bulk step back to no step at all, not to some other step', function () {
    /*
     * THE DEFECT: an undo that restores "" or the first role rather than NULL.
     * NULL is a real answer on this column — RoutineRoles' header says so at
     * length, "nobody has said" rather than "none of the above" — and the
     * screen's headline figure is the count of rows in exactly that state. An
     * undo that filled it in would quietly move the untagged count and put
     * products into a routine nobody placed them in.
     *
     * MUTATION NOTE: make bulk() coerce a null role to '' and this reads '' —
     * and the `Still untagged` tile stops counting the row.
     */
    $p = rtbProduct();

    $applied = rtbBulk([['id' => $p->id, 'role' => 'protect']]);
    expect($p->fresh()->routine_role)->toBe('protect');

    rtbBulk([['id' => $p->id, 'role' => $applied['rows'][0]['before']['role']]]);

    expect($p->fresh()->routine_role)->toBeNull();
});

it('keeps the undo bar on the screen rather than putting undo in a toast', function () {
    /*
     * THE DEFECT: shipping undo as a toast. The console's toast() is a
     * three-second window, and three seconds is not long enough to read
     * twenty-five rows and decide one of them is wrong. The undo bar is drawn
     * from state, so it survives every repaint, and it is drawn OUTSIDE the
     * list's empty/loading chain so it is still reachable after a search that
     * returns nothing.
     *
     * MUTATION NOTE: move `body += undoView();` inside the final else branch
     * and the last assertion here goes red — the bar disappears the moment a
     * search finds nothing, which is exactly when he wants it.
     */
    $src = rtbScreen();

    expect($src)->toContain('function undoView()')
        ->and($src)->toContain("id=\"rtn-undo\"")
        // The stack, not a single slot: several bulk writes undo in turn.
        ->and($src)->toContain('undo.push(')
        ->and($src)->toContain('undo.pop()')
        // It says how long it lasts, on the bar, in words.
        ->and($src)->toContain('Lasts until you reload this screen.');

    // Drawn before the "nothing loaded / searching / no rows" chain, not inside
    // its final branch.
    $step = Str::betweenFirst($src, 'function stepView(){', 'function productRow(');

    expect(Str::betweenFirst($step, 'body += undoView();', 'if (!products && !searching)'))
        ->not->toContain('body +=');
});

it('says "Remove" in words when the concern chip will take a tag off', function () {
    /*
     * ── THE DEFECT, AND THIS LANE SHIPPED IT BEFORE CATCHING IT ───────────
     *
     * The bulk concern chip flips to a REMOVE when every picked row already
     * carries the concern — otherwise the press would be a no-op and bulk
     * removal would need a control of its own. The first build drew that state
     * as "✓ Redness & sensitivity", which reads as a STATUS and is a BUTTON
     * that untags.
     *
     * Driving a real four-term worksheet session in Chromium walked into it.
     * `centella` matched twenty rows — ten of them also the `heartleaf` group,
     * because heartleaf products carry centella in their ingredient list — so
     * by the time `heartleaf` was typed all ten already carried `sensitivity`,
     * the chip had silently become a remove, and one press UNTAGGED ten
     * products that had just been tagged. The session ended with 10 sensitivity
     * products where it should have had 20, and nothing on the screen said so.
     * The count on the Concern pages tab would have been the only evidence.
     *
     * The state is still shown, because it is worth showing. The VERB is now
     * written out, and the title attribute spells out the consequence.
     *
     * MUTATION NOTE: change the chip back to `(off ? '\u2713 ' : '+ ')` and
     * this is red — and a worksheet session with two overlapping terms silently
     * loses the tags from the first one.
     */
    $src = rtbScreen();
    $bar = Str::betweenFirst($src, 'function bulkBarView(){', 'function productRow(');

    expect($bar)->toContain("'\\u2713 Remove '")
        // The consequence is spelled out on hover as well as in the label.
        ->and($bar)->toContain('press to take it off them');

    // And the handler really does subtract in that state rather than add.
    $handler = Str::betweenFirst($src, "data-rtn-bulkconcern']", 'var undoBtn');

    expect($handler)->toContain('have.splice(at, 1);')
        ->and($handler)->toContain("var off = btn.dataset.rtnBulkoff === '1';");
});

/* ════════════════════ 3. what "select all" selects ════════════════════════ */

it('selects the page and never the search or the catalogue', function () {
    /*
     * ── THE DECISION, AND WHY THE OTHER TWO ARE DANGEROUS ─────────────────
     *
     * "Select all" on a list with a pager has three defensible meanings: the
     * rows drawn, every row matching the search, or the whole catalogue. The
     * second and third are the dangerous ones, and in the same way: he can see
     * twenty-five rows, and a control that writes to three hundred he has never
     * seen is one press from a mis-tag he cannot even inspect. On this shop
     * "every product" is ~671 rows, and one press would concern-tag all of them.
     *
     * So select-all ticks THE ROWS ON THIS PAGE. The checkbox label carries the
     * count, so the scope is read rather than assumed, and when the search runs
     * to more pages the line beside it says so and names the next step.
     *
     * MUTATION NOTE: change the #rtn-pickall handler to iterate anything but
     * `products.products` — there is nothing else on this screen holding more
     * rows, which is itself the point — or delete the row count from the label,
     * and the first two assertions go red.
     */
    $src = rtbScreen();
    $handler = Str::betweenFirst($src, "if (pickAll) pickAll.onchange", 'var pickClear');

    // It ticks the drawn rows, one by one, and nothing else.
    expect($handler)->toContain('(products && products.products || []).forEach')
        ->and($handler)->not->toContain('total')
        ->and($handler)->not->toContain('loadProducts');

    // The label counts what it will tick.
    expect($src)->toContain("'Select all ' + rows.length + ' on this page")
        ->and($src)->toContain('A selection is this page only, never the other ');
});

it('cannot write to a row that is no longer on the page', function () {
    /*
     * THE DEFECT: `picked` is an id set that survives a repaint — it has to, or
     * a keystroke in the search box would clear the selection. But it must NOT
     * survive the list CHANGING under it: tick five rows, type a new term, and
     * a bar still reading "5 selected" would write to rows he can no longer
     * see.
     *
     * Two guards, and they are deliberately redundant. loadProducts() empties
     * the set on every fetch, and pickedIds() derives from the DRAWN rows — so
     * even an id left behind by some future code path is not in the payload
     * unless its row is on screen.
     *
     * MUTATION NOTE: remove `picked = {};` from loadProducts() and the first
     * assertion is red; make pickedIds() return Object.keys(picked) and the
     * second is.
     */
    $src = rtbScreen();

    expect(Str::betweenFirst($src, 'products = body;', 'catch (e)'))
        ->toContain('picked = {};');

    $derive = Str::betweenFirst($src, 'function pickedIds(){', 'function pickedRows(');

    expect($derive)->toContain('(products && products.products || []).forEach')
        ->and($derive)->not->toContain('Object.keys');
});

it('keeps the selection across a bulk write so a step and a concern are two presses', function () {
    /*
     * The other direction, and the reason the rule is "cleared on a FETCH"
     * rather than "cleared on any change": applying a step to twenty-five rows
     * and then a concern to the same twenty-five is the common shape, and a
     * selection that evaporated after the first press would double the work
     * this control exists to halve. bulkApply() repaints from the response and
     * never refetches.
     *
     * MUTATION NOTE: call loadProducts() at the end of bulkApply() instead of
     * render() and this is red — and the press count in the report doubles.
     */
    $apply = Str::betweenFirst(rtbScreen(), 'async function bulkApply(', 'function bulkUndo()');

    expect($apply)->toContain('render();')
        ->and($apply)->not->toContain('loadProducts()')
        ->and($apply)->not->toContain('picked = {}');
});

/* ══════════════════════════ 4. what it costs ══════════════════════════════ */

it('writes forty rows without making forty round trips', function () {
    /*
     * CLAUDE.md rule 4, and the brief's own instruction: a bulk write of forty
     * rows must not be forty round trips. The naive build is a loop of
     * $product->save(), which is 40 UPDATEs — plus 40 SELECTs if it also loads
     * each row — inside one request on a shared host.
     *
     * This groups by the VALUE being written. Forty products into one step is
     * ONE statement; forty products given one concern is one statement per
     * distinct resulting list, which on a real search is one or two — the rows
     * that had nothing become ["sensitivity"] together.
     *
     * The numbers below are measured, not guessed, and they are a BUDGET: a
     * later change that needs one more raises it deliberately.
     *
     * MUTATION NOTE: replace the grouped UPDATEs with a foreach of
     * $product->save() and `$stepQueries` jumps from single figures to 40+.
     */
    $rows = collect(range(1, 40))->map(fn () => rtbProduct());
    $admin = rtbAdmin();

    $count = static function (array $payload) use ($admin): int {
        $log = [];
        DB::listen(function ($q) use (&$log) { $log[] = $q->sql; });
        test()->actingAs($admin, 'admin')
            ->postJson('/admin-api/routine-products-bulk', ['rows' => $payload])
            ->assertOk();
        DB::getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);

        return count($log);
    };

    // Warm first: the first request of a process also fills the settings cache,
    // which belongs to the console's boot rather than to this endpoint. Same
    // reason RoutineTaggingJobTest warms before measuring.
    $count([['id' => $rows[0]->id, 'role' => 'cleanse']]);

    $stepQueries = $count($rows->map(fn (Product $p) => ['id' => $p->id, 'role' => 'tone'])->all());

    /*
     * One SELECT for the rows, ONE UPDATE for all forty, and then the coverage
     * recount the screen's header reads — which the single-row endpoint already
     * pays on every one of its forty presses. Ten is the ceiling; measured it
     * sits well under, and the point of the assertion is that it does not scale
     * with the number of rows.
     */
    expect($stepQueries)->toBeLessThan(10);

    // And the concern shape, where the resulting list can differ per row. These
    // forty all end up with the same list, so it is still one statement.
    $concernQueries = $count($rows->map(fn (Product $p) => [
        'id' => $p->id, 'concerns' => ['sensitivity'],
    ])->all());

    expect($concernQueries)->toBeLessThan(10)
        ->and($rows->every(fn (Product $p) => $p->fresh()->routine_role === 'tone'))->toBeTrue();
});

it('refuses a payload larger than four pages rather than accepting an unbounded write', function () {
    /*
     * THE DEFECT: no cap. This endpoint's cost is linear in the ids it is
     * handed, and an unbounded admin write is how a request gets killed halfway
     * through on a shared host — which on a bulk tagger means half the
     * selection written and no record of which half.
     *
     * The screen can only ever select one page (25). The cap is four pages, so
     * it is headroom for a caller that pages through a selection, not for one
     * that means the catalogue.
     *
     * MUTATION NOTE: remove the `max:` rule from `rows` and this returns 200.
     */
    $p = rtbProduct();

    $rows = [];
    for ($i = 1; $i <= 101; $i++) {
        $rows[] = ['id' => $p->id + $i, 'role' => 'tone'];
    }

    test()->actingAs(rtbAdmin(), 'admin')
        ->postJson('/admin-api/routine-products-bulk', ['rows' => $rows])
        ->assertStatus(422);
});

/* ════════════════════════ 5. the capability ═══════════════════════════════ */

it('gives the bulk write its own rule, and it is a write rule not a read one', function () {
    /*
     * CLAUDE.md rule 5: every new admin endpoint gets its own capability and
     * fails closed. The hazard this ordering answers is the one the
     * Build-my-routine block in AdminCapabilities was already written for —
     * a read rule reached first would let an `editor` on catalog.view alone
     * retag the catalogue twenty-five rows at a time.
     *
     * MUTATION NOTE: move the rule below the GET rows in RULES, or delete it
     * and let a wildcard catch the path, and this is red.
     */
    expect(AdminCapabilities::forPath('POST', 'admin-api/routine-products-bulk'))
        ->toBe('catalog.manage');

    $paths = array_map(static fn (array $r) => $r[0].' '.$r[1], AdminCapabilities::RULES);

    $bulkAt = array_search('POST admin-api/routine-products-bulk', $paths, true);
    $readAt = array_search('GET admin-api/routine-products', $paths, true);

    expect($bulkAt)->not->toBeFalse()
        ->and($readAt)->not->toBeFalse()
        ->and($bulkAt)->toBeLessThan($readAt);
});

it('refuses a role that may look at the catalogue but not change it', function () {
    /*
     * `support` carries catalog.view and not catalog.manage. It can open this
     * screen and read the list; it cannot retag one product through
     * /routine-products/{id}, and it must not be able to retag twenty-five
     * through this.
     *
     * MUTATION NOTE: change the rule's capability to catalog.view and this is a
     * 200.
     */
    $p = rtbProduct();

    test()->actingAs(rtbAdmin('support'), 'admin')
        ->postJson('/admin-api/routine-products-bulk', [
            'rows' => [['id' => $p->id, 'role' => 'cleanse']],
        ])
        ->assertForbidden();

    expect($p->fresh()->routine_role)->toBeNull();
});

it('sits behind auth:admin like every other endpoint in this file', function () {
    /*
     * CLAUDE.md: "/api/* is unauthenticated". This endpoint lists nothing, but
     * it WRITES to the catalogue, and it is registered in the same guarded
     * group as its siblings rather than beside the public API.
     *
     * MUTATION NOTE: move the route into routes/api.php and this is a 200 for
     * an anonymous caller.
     */
    $p = rtbProduct();

    test()->postJson('/admin-api/routine-products-bulk', [
        'rows' => [['id' => $p->id, 'role' => 'cleanse']],
    ])->assertUnauthorized();

    $route = collect(BuildMyRoutineRoutes::registered())
        ->first(fn ($r) => $r->uri() === 'admin-api/routine-products-bulk');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('auth:admin');
});

/* ═══════════ 6. rule 1 — applying this changes nothing on the shop ════════ */

it('leaves the shop exactly as it found it: nothing tagged, no page published', function () {
    /*
     * CLAUDE.md rule 1. This lane ships a CONTROL, not a default. Applying the
     * package tags no product, publishes no concern page, and leaves
     * ConcernCollections::live() empty on a fresh shop — nothing moves until
     * somebody ticks a box and presses a button.
     *
     * MUTATION NOTE: have the migration write a single routine_concerns value,
     * or add a slug to ConcernCollections::ENABLED, and this is red.
     */
    rtbProduct('cleanse', ['acne']);
    rtbProduct('tone', ['acne']);

    // Two tagged products is below MIN_PRODUCTS, which is the state a fresh
    // shop is in and the state this package leaves it in.
    expect(ConcernCollections::live())->toBe([]);

    test()->get('/concern/acne/')->assertNotFound();

    // And the migration this round ships is a cache clear and nothing else.
    $migration = (string) file_get_contents(
        database_path('migrations/2027_01_02_000000_clear_caches_routine_bulk_tagging.php')
    );

    expect($migration)->not->toContain('Schema::')
        ->and($migration)->not->toContain('DB::table')
        ->and($migration)->toContain('opcache_reset');
});

it('changes nothing about how one product is tagged on its own', function () {
    /*
     * The single-row endpoint is the control the owner already knows, and this
     * round must not have moved it. Same path, same payload, same response
     * shape, same behaviour on an emptied concern list.
     *
     * MUTATION NOTE: route tag() through bulk() "to share the writer" and the
     * response shape changes — `product` becomes `rows` — and this is red.
     */
    $p = rtbProduct('tone', ['acne']);

    $body = test()->actingAs(rtbAdmin(), 'admin')
        ->postJson('/admin-api/routine-products/'.$p->id, ['concerns' => []])
        ->assertOk()
        ->json();

    expect($body['product']['concerns'])->toBe([])
        ->and($body['product']['role'])->toBe('tone')
        ->and($p->fresh()->routine_concerns)->toBeNull()
        ->and($body)->toHaveKey('coverage');
});
