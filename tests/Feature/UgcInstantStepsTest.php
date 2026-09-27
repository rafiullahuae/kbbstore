<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Product;
use App\Models\UgcVideo;
use Tests\Support\UgcAdminRoutes;

/**
 * Content → Shoppable video → All clips: moving between the five steps, the
 * quick draft, and step 4's product search.
 *
 * ── WHAT THE OWNER REPORTED, AND WHAT WAS ACTUALLY MEASURED ─────────────────
 *
 * "going back to previous tab or moving to next step, in any case it takes alot
 * of time." Driven in Chromium against a clip carrying his own file (8.5 MB, a
 * cover, no teaser) over a link shaped like his — 300 KB/s, 250 ms round trip —
 * timing each click until the target panel was on screen AND the frame carrying
 * it had been composited:
 *
 *                     BEFORE                AFTER
 *   forward 1->2     1170 ms, 4 calls      57 ms, 2 calls
 *   forward 2->3     1185 ms, 4 calls      31 ms, 2 calls
 *   forward 3->4     1137 ms, 4 calls      32 ms, 3 calls
 *   forward 4->5     1135 ms, 4 calls      24 ms, 2 calls
 *   back    5->4       21 ms, 0 calls      35 ms, 0 calls
 *   back    4->3       14 ms, 0 calls       9 ms, 0 calls
 *   back    3->2       19 ms, 0 calls      15 ms, 0 calls
 *   back    2->1       19 ms, 0 calls       8 ms, 0 calls
 *
 * So HALF his report was not a latency problem at all: going back was already
 * 14-21 ms and made no request. Forward was four SEQUENTIAL round trips —
 * PUT the row, POST the products, GET the library, GET the row — every time
 * anybody moved one step to the right.
 *
 * The repaint was still wrong, just not slow. render() replaced #content
 * outright, which destroyed and rebuilt BOTH <video> elements on every step
 * change in both directions — proved by stamping a property on them and
 * watching it never survive; after the fix the same element survives all eight
 * transitions. That restarted the loop preview from zero every time step 2 came
 * back and re-issued range requests against the 8.5 MB file, up to three per
 * transition.
 *
 * ── AND THE SECOND REPORT: "the search products and selection is not working" ─
 *
 * Measured before anything was touched. Typing "anua" left the results area
 * COMPLETELY EMPTY for 905 ms and then, when nothing published matched, empty
 * for good: resultsHTML() opened with `if (!results.length) return '';`, so
 * "nothing typed yet", "still looking" and "nothing found" all rendered the same
 * nothing. Selection, meanwhile, WORKED — the product really was tagged — but
 * the wholesale repaint replaced the search box, so document.activeElement
 * stopped being it: focused=true before the press, false after. Anybody adding a
 * second product then types into nothing.
 *
 * ── WHY A SOURCE SCAN ───────────────────────────────────────────────────────
 *
 * Same reason UgcAddClipFlowTest gives: the screen is a JavaScript string
 * builder inside a Blade partial that nothing renders server-side. Comments are
 * stripped before any behavioural scan, because this screen explains its own
 * defects in prose and quotes the wrong code while doing it.
 */
function instantStepsCode(): string
{
    $src = (string) file_get_contents(
        resource_path('views/admin/partials/ugc-library-screen.blade.php')
    );

    $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $src);
    $src = (string) preg_replace('#/\*.*?\*/#s', '', $src);

    return (string) preg_replace('#^\s*//.*$#m', '', $src);
}

/**
 * A catalogue with nothing in it but what the case puts there.
 *
 * The migration set seeds a demo catalogue, and several of its names contain the
 * very words these cases search for — "heartleaf" and "rice" among them. A case
 * asserting an exact count against a shared catalogue is a case that goes red
 * the next time somebody adds a demo product, which is a false alarm rather than
 * a defect. Emptied inside the test's own transaction, so nothing outside it
 * notices.
 */
function s1EmptyCatalogue(): void
{
    Product::withTrashed()->get()->each(fn (Product $p) => $p->forceDelete());
}

/* ════════════════════════════════════════════ a step change costs nothing ══ */

it('paints a step change without building a panel or asking the server', function () {
    /*
     * THE DEFECT. goStep() ended `step = next; render();`, and render() rebuilt
     * #content.innerHTML from editorHTML() — all five panels, the <video> among
     * them — for a change that only ever alters WHICH of five sections already
     * in the document is hidden.
     *
     * paintStep() is the whole step change now: toggle five `hidden`s, redraw
     * the rail and the footer, and stop. It must not call render(), must not
     * build any panel, and must not reach for the network.
     *
     * MUTATION NOTE. Change goStep()'s `paintStep()` back to `render()` and the
     * second assertion is red. RUN: red.
     */
    $code = instantStepsCode();

    expect(str_contains($code, 'function paintStep()'))
        ->toBeTrue('there is no step painter');

    // The step change calls the painter, not the rebuilder.
    $go = substr($code, (int) strpos($code, 'async function goStep'));
    $go = substr($go, 0, (int) strpos($go, 'async function remove'));

    expect(str_contains($go, 'paintStep();'))
        ->toBeTrue('a step change no longer paints the step');

    /*
     * The ONE render() left in goStep() is the create — a clip with no id has to
     * exist before step 2 has anywhere to put a file — and it is guarded by
     * `!editing.id`. Any other render() in here is the wholesale repaint coming
     * back.
     */
    expect(substr_count($go, 'render();'))
        ->toBe(1, 'a step change rebuilds the screen again');
    expect(str_contains($go, 'if (forward && editing && !editing.id)'))
        ->toBeTrue('the create no longer waits, so step 2 can open with nowhere to put a file');

    // The painter itself builds no panel and sends nothing.
    $paint = substr($code, (int) strpos($code, 'function paintStep()'));
    $paint = substr($paint, 0, (int) strpos($paint, 'function render()'));

    foreach (['detailsPanel(', 'mediaPanel(', 'creditPanel(', 'productsPanel(', 'publishPanel(', 'api('] as $forbidden) {
        expect(str_contains($paint, $forbidden))
            ->toBeFalse("a step change is building or fetching again: {$forbidden}");
    }
});

it('keeps the video element across a repaint instead of rebuilding it', function () {
    /*
     * THE DEFECT, and the expensive half of the owner's report. `render()`
     * replaced #content wholesale, so the <video> in the media panel and the
     * loop preview mounted by wireLoop() were destroyed and recreated on EVERY
     * repaint — every step change, every tag, every reorder, every search
     * answer. Measured in Chromium by stamping a property on both elements: it
     * survived NONE of the eight transitions before, and ALL eight after.
     *
     * The fix is Lane V5's, applied to a screen that cannot lift the video out
     * to <body> the way it lifted the clip dialog: `mounted` holds mountKey()
     * for the editor on screen, and a repaint whose key matches touches only the
     * named regions.
     *
     * mountKey() MUST carry the three media paths. Keying on the id alone would
     * leave a stale player on screen after an upload — the opposite bug, and the
     * worse one, because the owner would be looking at the file he just replaced.
     *
     * MUTATION NOTE. Drop `|f:` from mountKey() and the third assertion is red.
     * Drop the `mounted === mountKey(editing)` guard from render() and the
     * second is red. RUN: red on both.
     */
    $code = instantStepsCode();

    expect(str_contains($code, 'function mountKey(v)'))
        ->toBeTrue('nothing records what the editor on screen was built for');

    expect(str_contains($code, 'mounted !== null && mounted === mountKey(editing)'))
        ->toBeTrue('render() no longer recognises an editor it has already mounted');

    // The three media paths, each named, so a new file always rebuilds the player.
    foreach (["'|f:' + (v.file_path", "'|p:' + (v.poster_path", "'|t:' + (v.teaser_path"] as $part) {
        expect(str_contains($code, $part))
            ->toBeTrue("mountKey() no longer notices a media change: {$part}");
    }

    // ...and the step is NOT in the key, which is the entire point.
    $key = substr($code, (int) strpos($code, 'function mountKey(v)'));
    $key = substr($key, 0, (int) strpos($key, 'function paintRegion'));
    expect(str_contains($key, 'step'))
        ->toBeFalse('the step is in the mount key, so every step change rebuilds the editor again');

    /*
     * AND THE LOOP IS PAUSED ON THE WAY OUT RATHER THAN RELEASED. releaseLoop()
     * gives the decoder back AND drops the src, so calling it on a step change
     * is what made coming back to step 2 re-fetch the clip — the whole 8.5 MB of
     * it on a clip with no teaser — and restart the loop from zero.
     */
    $paint = substr($code, (int) strpos($code, 'function paintStep()'));
    $paint = substr($paint, 0, (int) strpos($paint, 'function render()'));

    expect(str_contains($paint, 'releaseLoop()'))
        ->toBeFalse('a step change gives the video back, so returning to step 2 re-fetches it');
    expect(str_contains($paint, 'loopEl.pause()'))
        ->toBeTrue('the loop is not paused when its panel is hidden');
});

it('saves on the way forward without making the paint wait for it', function () {
    /*
     * THE GUARANTEE THAT MUST SURVIVE. Moving forward still writes: step 1
     * creates the row the media steps need, and nothing may be left behind
     * between two steps. What changed is the ORDER — paint, then save — and that
     * the background save does two calls instead of four, because the PUT
     * answers with card() and card() carries the blockers, the media state and
     * the three paths.
     *
     * MUTATION NOTE. Delete `if (forward) { bgSave = true; save(); }` from
     * goStep() and the first assertion is red — with a forward step that paints
     * instantly and silently throws the typing away. RUN: red.
     */
    $code = instantStepsCode();

    $go = substr($code, (int) strpos($code, 'async function goStep'));
    $go = substr($go, 0, (int) strpos($go, 'async function remove'));

    expect(str_contains($go, 'if (forward) { bgSave = true; save(); }'))
        ->toBeTrue('a forward step no longer saves at all');

    // Painted BEFORE the save is started, or the wait is still there.
    $painted = strpos($go, 'paintStep();');
    $saved = strpos($go, 'bgSave = true; save();');
    expect($painted !== false && $saved !== false && $painted < $saved)
        ->toBeTrue('the paint still waits on the save');

    /*
     * ONE SAVE OF ONE ROW AT A TIME. Stepping forward twice quickly must mark
     * the row dirty rather than open a second PUT of the same id: two saves
     * racing is how a field gets written back to the value it had before it was
     * edited, and the loser of the race wins the database.
     */
    expect(str_contains($code, 'if (saving) { saveAgain = true; return false; }'))
        ->toBeTrue('two saves of one row can now be in flight together');
});

it('leaves a failed background save on the screen instead of in a toast', function () {
    /*
     * CLAUDE.md's UpdateRunner::recordManifest() landmine, in its natural next
     * habitat: a write whose failure leaves no trace does not contain a failure,
     * it seeds one. A save that happens where nobody is looking cannot report
     * itself through say() alone — the toast appears while the owner is reading
     * step 4 and is gone before he looks up, and the next thing he does is close
     * the tab believing his work is saved.
     *
     * So every branch of save()'s catch writes `saveFail`, the footer prints it
     * until a save succeeds, and the draft is NOT cleared while it stands.
     *
     * MUTATION NOTE. Delete the `saveFail = {...}` line from any one of the
     * three catch branches and the count below drops to 2, which is red. Move
     * the localStorage.removeItem out of the try and into the finally and the
     * last assertion is red. RUN: red on both.
     */
    $code = instantStepsCode();

    $save = substr($code, (int) strpos($code, 'async function save()'));
    $save = substr($save, 0, (int) strpos($save, 'async function goStep'));

    // All three refusal shapes — blockers, 422, and everything else — record it.
    expect(substr_count($save, 'saveFail = {'))
        ->toBe(3, 'a way for a save to fail no longer leaves a mark on the screen');

    // The footer is where it is printed, and it survives a repaint.
    expect(str_contains($code, 'function saveStateHTML()'))
        ->toBeTrue('the footer has nothing to say about a save');
    expect(str_contains($code, 'data-ugs-retrysave'))
        ->toBeTrue('a failed save offers no way to try again');

    /*
     * AND THE DRAFT IS DROPPED ONLY WHERE THE SAVE LANDED. It must be inside the
     * try, after both requests — in the finally it would run on the failure path
     * too, and the failure path is the one moment the draft is the only copy of
     * what was typed.
     */
    $clear = strpos($save, 'window.localStorage.removeItem(wasKey)');
    $catch = strpos($save, '} catch (e) {');
    expect($clear !== false && $catch !== false && $clear < $catch)
        ->toBeTrue('the draft is cleared on a path a failed save also takes');
});

/* ═══════════════════════════════════════════════════════════ the draft ══ */

it('offers a draft rather than applying one, and says it is not saved', function () {
    /*
     * THE RULE, and the only thing that keeps a draft from being worse than the
     * delay it removes: A DRAFT IS NEVER APPLIED WITHOUT BEING ASKED FOR.
     * Opening a clip paints the server's values. If unsaved typing exists for
     * that clip a bar offers it, with when it was typed, and two buttons.
     *
     * Driven in Chromium: type without saving, reload, reopen the clip — the
     * title box reads the SERVER's title and the bar appears; press Restore and
     * the typed title comes back with "Nothing has been saved yet"; press
     * Discard and it is gone and stays gone across a reload.
     *
     * MUTATION NOTE. Make open() call restoreDraft() instead of setting
     * draftFound, and the second assertion is red — with a screen showing values
     * that are in no database. RUN: red.
     */
    $code = instantStepsCode();

    foreach (['function writeDraft()', 'function readDraft(v)', 'function restoreDraft()',
        'function discardDraft()'] as $fn) {
        expect(str_contains($code, $fn))->toBeTrue("the draft has no {$fn}");
    }

    // open() considers a draft and NEVER applies one.
    $open = substr($code, (int) strpos($code, 'async function open(id)'));
    $open = substr($open, 0, (int) strpos($open, 'function forgetUpload()'));

    expect(str_contains($open, 'restoreDraft()'))
        ->toBeFalse('opening a clip applies the draft behind the owner');
    expect(str_contains($open, 'draftFound = { at: d.at'))
        ->toBeTrue('opening a clip no longer offers an unsaved draft at all');

    // The bar says, in as many words, that what is on screen is the server's.
    expect(str_contains($code, 'What is on screen now is what the server has.'))
        ->toBeTrue('the draft bar no longer says which values are on screen');
    expect(str_contains($code, 'Nothing has been saved yet'))
        ->toBeTrue('a restored draft no longer says it is unsaved');

    // Both ways out of the bar exist.
    foreach (['data-ugs-draftrestore', 'data-ugs-draftdiscard'] as $button) {
        expect(str_contains($code, $button))->toBeTrue("the draft bar has no {$button}");
    }
});

it('keys a draft to its own clip and drops it only when the row is safe', function () {
    /*
     * A draft that arrived on the next clip opened would be the same defect as
     * applying one silently, one step removed. Keyed per clip, with the
     * never-saved clip taking the single 'new' key.
     *
     * MUTATION NOTE. Make draftKey() ignore the id and return one constant, and
     * the first assertion is red. RUN: red.
     */
    $code = instantStepsCode();

    expect(str_contains($code, "DRAFT_KEY + (v.id ? String(v.id) : 'new')"))
        ->toBeTrue('every clip now shares one draft');

    // Deleting the row takes its draft with it: restoring into nothing is no offer.
    $remove = substr($code, (int) strpos($code, 'async function remove(id, title)'));
    $remove = substr($remove, 0, (int) strpos($remove, 'function paintProgress()'));
    expect(str_contains($remove, 'window.localStorage.removeItem(DRAFT_KEY + String(id))'))
        ->toBeTrue('a deleted clip leaves a draft nothing can ever save');

    /*
     * AND EVERY READ AND WRITE IS GUARDED. localStorage throws outright in a
     * browser set to block site data, and a screen that cannot store a draft
     * must still let somebody edit a clip.
     */
    foreach (['window.localStorage.setItem', 'window.localStorage.getItem',
        'window.localStorage.removeItem'] as $call) {
        $at = 0;
        while (($at = strpos($code, $call, $at)) !== false) {
            $before = substr($code, max(0, $at - 400), min(400, $at));
            expect(str_contains($before, 'try {'))
                ->toBeTrue("an unguarded {$call} will take the screen down where storage is blocked");
            $at += strlen($call);
        }
    }
});

it('restores a draft through the same boxes a save would have read', function () {
    /*
     * A restore that wrote a different set of fields from the set a save sends
     * would put values on screen that quietly never reach the database — the
     * silent-divergence failure by another route.
     *
     * The English boxes are data-ugs-field, which form() reads. The Arabic boxes
     * are data-kbbar-input, which KBBArabic.collect() reads. Both, and nothing
     * else.
     *
     * MUTATION NOTE. Delete the data-kbbar-input loop from restoreDraft() and
     * the second assertion is red — with an Arabic caption that survives a
     * reload on screen and is dropped by the next save. RUN: red.
     */
    $code = instantStepsCode();

    $restore = substr($code, (int) strpos($code, 'function restoreDraft()'));
    $restore = substr($restore, 0, (int) strpos($restore, 'function discardDraft()'));

    expect(str_contains($restore, "host.querySelector('[data-ugs-field=\"' + k + '\"]')"))
        ->toBeTrue('a restore no longer writes the English boxes a save reads');
    expect(str_contains($restore, "host.querySelector('[data-kbbar-input=\"' + f + '\"]')"))
        ->toBeTrue('a restore no longer writes the Arabic boxes a save reads');

    /*
     * A SELECT TAKES ONE OF ITS OWN OPTIONS OR NOTHING — rule 5. A draft comes
     * out of a store the page cannot vouch for, so it is untrusted input like
     * any other, and `status` deciding whether a clip is published makes it the
     * worst possible field to write unchecked.
     */
    expect(str_contains($restore, "el.tagName === 'SELECT'"))
        ->toBeTrue('a restored draft writes a select without checking its options');
    expect(str_contains($restore, 'Array.prototype.some.call(el.options'))
        ->toBeTrue('a restored select value is no longer one of its own options');
});

/* ═════════════════════════════════════════════ step 4's product search ══ */

it('never answers a search with an empty box', function () {
    /*
     * THE DEFECT, and the whole of the owner's second report. resultsHTML() began
     * `if (!results.length) return '';`. He typed four characters, was shown
     * nothing for 905 ms and then nothing for good, and concluded the search was
     * broken. It was silent, which from outside is the same thing.
     *
     * Four answers now, each naming itself: still looking, N matches, nothing
     * published matched THIS term, and nothing typed yet.
     *
     * MUTATION NOTE. Put `if (!results.length) return '';` back at the top of
     * resultsHTML() and every assertion below is red. RUN: red.
     */
    $code = instantStepsCode();

    $fn = substr($code, (int) strpos($code, 'function resultsHTML()'));
    $fn = substr($fn, 0, (int) strpos($fn, 'function stateLine(v)'));

    expect(str_contains($fn, "if (!results.length) return '';"))
        ->toBeFalse('the search draws an empty box again');

    expect(str_contains($fn, 'Searching&hellip;'))
        ->toBeTrue('the debounce and the round trip are silent again');
    expect(str_contains($fn, 'Nothing published matches'))
        ->toBeTrue('a term that matched nothing says nothing');
    expect(str_contains($fn, 'newest products'))
        ->toBeTrue('step 4 offers nothing before a character is typed');

    /*
     * AND THE "STILL LOOKING" STATE COVERS THE DEBOUNCE, not just the request.
     * The debounce is 250 ms of the ~900 ms wait, and a spinner that starts when
     * the request does leaves the first quarter of it blank.
     */
    $input = substr($code, (int) strpos($code, "if (e.target.hasAttribute('data-ugs-search'))"));
    $input = substr($input, 0, (int) strpos($input, 'searchTimer = setTimeout(search, 250);'));
    expect(str_contains($input, 'searching = true;'))
        ->toBeTrue('the 250ms debounce is a silent quarter-second again');
});

it('tells a term that matched nothing from a term that matched a draft', function () {
    /*
     * THE DEFECT THIS ANSWERS, and the likeliest reason his own search "did not
     * work": the endpoint filters Product::visible(), so a DRAFT or hidden
     * product is CORRECTLY not returned — and the screen had no way to say so.
     * Measured in Chromium against a catalogue holding a draft "Anua Rice 70"
     * row: the term "rice 70" matched a real product he owns and drew a blank.
     *
     * "Nothing matched" and "that product is a draft" need completely different
     * things done about them, and only the server can tell them apart.
     *
     * This half is settled against the database rather than the source, because
     * it is a claim about a query.
     *
     * MUTATION NOTE. Delete the `$unpublished` block from
     * UgcVideoController::products() and the last two assertions are red. RUN:
     * red.
     */
    UgcAdminRoutes::wire(app());
    s1EmptyCatalogue();

    $owner = AdminUser::create([
        'name' => 'S1 owner', 'email' => 's1-'.uniqid().'@example.test',
        'password' => 'secret-secret', 'role' => 'owner',
    ]);

    Product::create(['slug' => 's1-live-'.uniqid(), 'name' => 'Anua Heartleaf Toner',
        'status' => 'publish', 'is_visible' => true, 'price' => 9900, 'stock_status' => 'instock']);
    Product::create(['slug' => 's1-draft-'.uniqid(), 'name' => 'Anua Rice 70 Milky Toner',
        'status' => 'draft', 'is_visible' => true, 'price' => 9900, 'stock_status' => 'instock']);
    Product::create(['slug' => 's1-hidden-'.uniqid(), 'name' => 'Anua Rice 70 Cleansing Oil',
        'status' => 'publish', 'is_visible' => false, 'price' => 9900, 'stock_status' => 'instock']);

    // A term that matches a published row: the count is not paid for at all.
    $hit = $this->actingAs($owner, 'admin')
        ->getJson('/admin-api/ugc-videos/products?q=heartleaf')->assertOk()->json();

    expect($hit['products'])->toHaveCount(1)
        ->and($hit['unpublished'])->toBe(0);

    // A term that matches ONLY rows nobody can tag: named, and counted.
    $draftOnly = $this->actingAs($owner, 'admin')
        ->getJson('/admin-api/ugc-videos/products?q=rice 70')->assertOk()->json();

    expect($draftOnly['products'])->toBe([])
        ->and($draftOnly['unpublished'])->toBe(2);

    // A term that matches nothing at all is still nothing at all.
    $nothing = $this->actingAs($owner, 'admin')
        ->getJson('/admin-api/ugc-videos/products?q=zzzznotathing')->assertOk()->json();

    expect($nothing['products'])->toBe([])
        ->and($nothing['unpublished'])->toBe(0);
});

it('returns a NAME for nothing it would refuse to tag', function () {
    /*
     * The count above must stay a COUNT. An unpublished product's name is not
     * something this endpoint may start handing out because somebody searched
     * for it — /admin-api is behind a capability, but the rule CLAUDE.md states
     * about allowlisting what a model returns does not stop at /api.
     *
     * MUTATION NOTE. Return the unpublished rows instead of counting them and
     * this is red. RUN: red.
     */
    UgcAdminRoutes::wire(app());
    s1EmptyCatalogue();

    $owner = AdminUser::create([
        'name' => 'S1 owner', 'email' => 's1-'.uniqid().'@example.test',
        'password' => 'secret-secret', 'role' => 'owner',
    ]);

    Product::create(['slug' => 's1-secret-'.uniqid(), 'name' => 'Unreleased Christmas Set',
        'status' => 'draft', 'is_visible' => true, 'price' => 9900, 'stock_status' => 'instock']);

    $body = $this->actingAs($owner, 'admin')
        ->getJson('/admin-api/ugc-videos/products?q=Unreleased')->assertOk();

    $body->assertJsonPath('products', [])
        ->assertJsonPath('unpublished', 1);

    expect(str_contains($body->getContent(), 'Unreleased Christmas Set'))
        ->toBeFalse('the endpoint leaked the name of a product it refuses to tag');
});

it('shows the newest products first without reordering the answer it already gave', function () {
    /*
     * The owner asked for "the recent 10 products to be shown always". Recency
     * is an order this endpoint did not have — and reordering the answer it
     * ALREADY gives would be a change to behaviour that works, which rule 1
     * forbids. So `recent` is its own mode and the termless answer every existing
     * caller gets is byte-identical.
     *
     * StableOrderingTest refuses a sliced query whose last ORDER BY key can tie,
     * and created_at ties freely: an import writes a whole catalogue inside one
     * second. So the tie-breaker is id, on both branches.
     *
     * MUTATION NOTE. Drop `->orderByDesc('id')` from the recent branch and
     * StableOrderingTest is red. Make `recent` reorder the termless answer
     * instead of branching, and the last assertion here is red. RUN: red on both.
     */
    UgcAdminRoutes::wire(app());
    s1EmptyCatalogue();

    $owner = AdminUser::create([
        'name' => 'S1 owner', 'email' => 's1-'.uniqid().'@example.test',
        'password' => 'secret-secret', 'role' => 'owner',
    ]);

    // Three products, oldest first, with names that sort the OTHER way.
    foreach ([['Aaa oldest', 3], ['Mmm middle', 2], ['Zzz newest', 1]] as [$name, $daysAgo]) {
        Product::create(['slug' => 's1-r-'.uniqid(), 'name' => $name, 'status' => 'publish',
            'is_visible' => true, 'price' => 9900, 'stock_status' => 'instock',
            'created_at' => now()->subDays($daysAgo)]);
    }

    $recent = $this->actingAs($owner, 'admin')
        ->getJson('/admin-api/ugc-videos/products?recent=10')->assertOk()->json('products');

    expect(array_column($recent, 'name'))
        ->toBe(['Zzz newest', 'Mmm middle', 'Aaa oldest']);

    // Bounded, so the parameter cannot be used to ask for the whole catalogue.
    $two = $this->actingAs($owner, 'admin')
        ->getJson('/admin-api/ugc-videos/products?recent=2')->assertOk()->json('products');
    expect($two)->toHaveCount(2);

    /*
     * AND THE ANSWER THAT ALREADY EXISTED HAS NOT MOVED. No `recent`, no term:
     * still every visible product, still by name.
     */
    $plain = $this->actingAs($owner, 'admin')
        ->getJson('/admin-api/ugc-videos/products')->assertOk()->json('products');

    expect(array_column($plain, 'name'))
        ->toBe(['Aaa oldest', 'Mmm middle', 'Zzz newest']);
});

it('never takes the caret out of the search box', function () {
    /*
     * THE DEFECT, and almost certainly what "selection is not working" really
     * was. Pressing a result DID tag the product — measured: the tagged list went
     * from four rows to five and the product was in it — but render() replaced
     * #content, so the search box became a new element and
     * document.activeElement stopped being it. Measured: focused=true before the
     * press, false after. Anybody adding a second product then types into
     * nothing and watches the screen ignore them.
     *
     * Two workarounds grew out of the same root and both are gone: `term` written
     * back into a live input, and a focus()/setSelectionRange(length, length)
     * after each search. The second could only ever put the caret at the END, so
     * correcting the middle of a term was impossible. Measured after the fix:
     * typing into the middle of "anu" leaves the caret at index 2, and adding a
     * product leaves focused=true with the caret where it was.
     *
     * MUTATION NOTE. Change afterTagChange() to call render() and the second
     * assertion is red. RUN: red.
     */
    $code = instantStepsCode();

    expect(str_contains($code, 'setSelectionRange'))
        ->toBeFalse('the caret is being put back by hand again, which means it is being lost again');

    $after = substr($code, (int) strpos($code, 'function afterTagChange()'));
    $after = substr($after, 0, (int) strpos($after, 'function zoneOf(e)'));

    expect(str_contains($after, 'render();'))
        ->toBeFalse('tagging a product rebuilds the screen, and the search box with it');
    expect(str_contains($after, "paintRegion('tagged')"))
        ->toBeTrue('tagging a product no longer repaints the list it changed');

    /*
     * The results are a region of their own and the input is NOT inside it, which
     * is what makes the caret safe by construction rather than by restoration.
     */
    expect(str_contains($code, '<div class="ugs-results" data-ugs-region="results">'))
        ->toBeTrue('the results are no longer a region, so repainting them takes the box with them');

    $search = substr($code, (int) strpos($code, 'async function search()'));
    $search = substr($search, 0, (int) strpos($search, 'async function loadRecent()'));
    expect(str_contains($search, 'render();'))
        ->toBeFalse('a search answer rebuilds the screen again');
    expect(str_contains($search, "paintRegion('results')"))
        ->toBeTrue('a search answer no longer paints the results');
});

it('lets the last keystroke win rather than the last answer to arrive', function () {
    /*
     * THE DEFECT. search() had no sequence guard: a slow answer for "an" landing
     * after a fast one for "anua" replaced the right results with the wrong ones,
     * under a box reading "anua". load() has had this guard since it was written
     * and the search was simply missing it.
     *
     * MUTATION NOTE. Delete `if (mine !== searchSeq) return;` from the try and
     * this is red. RUN: red.
     */
    $code = instantStepsCode();

    expect(str_contains($code, 'var mine = ++searchSeq;'))
        ->toBeTrue('the search takes no ticket');
    expect(substr_count($code, 'if (mine !== searchSeq) return;'))
        ->toBe(2, 'a stale search answer can overwrite a newer one again');
});

/* ══════════════════════════════════════════ nothing that worked has moved ══ */

it('keeps everything the screen already did', function () {
    /*
     * Rule 1. This lane rebuilt how the screen repaints, which is exactly the
     * kind of change that quietly takes a feature with it. Each of these was
     * working before and is named here so that losing one is a red test rather
     * than a bug report.
     *
     * MUTATION NOTE. Delete the keyboard reorder buttons, or the publish gate's
     * step-2 clause, and the matching assertion is red. RUN: red.
     */
    $code = instantStepsCode();

    // keepTyped(), still folding the form back before a save that may be refused.
    expect(str_contains($code, 'function keepTyped(payload)'))->toBeTrue();

    // The publish gate, still read off the model's own three clauses.
    expect(str_contains($code, "if (n === 2) return (v.file_path && v.poster_path) ? 'done' : 'todo';"))->toBeTrue();
    expect(str_contains($code, "if (n === 3) return v.rights_status === 'granted' ? 'done' : 'todo';"))->toBeTrue();

    // The loop preview, still mounted with the shop's own mechanism.
    expect(str_contains($code, 'function wireLoop(host)'))->toBeTrue();
    expect(str_contains($code, 'data-ugs-loopms'))->toBeTrue();

    // Drag to reorder AND the keyboard arrows beside it.
    expect(str_contains($code, 'draggable="true" data-ugs-drag='))->toBeTrue();
    foreach (['data-ugs-up=', 'data-ugs-down='] as $keyboard) {
        expect(str_contains($code, $keyboard))->toBeTrue("the keyboard reorder lost {$keyboard}");
    }
    expect(substr_count($code, 'if (dragFrom !== null) return;'))->toBe(2);

    // The shared upload kit, and the literal accept AdminMediaPickerEverywhereTest reads.
    expect(str_contains($code, 'window.kbbUpload'))->toBeTrue();
    expect(str_contains($code, 'accept="video/mp4,video/webm"'))->toBeTrue();

    // Writes still carry the cookie's token.
    expect(str_contains($code, "opts.headers['X-XSRF-TOKEN'] = cookie('XSRF-TOKEN');"))->toBeTrue();

    /*
     * AND RULE 4 STILL HOLDS, including for everything this lane added.
     * requestAnimationFrame is on the list UgcAddClipFlowTest names, and a
     * repaint scheduler is exactly where somebody would reach for it.
     */
    foreach ([
        'getBoundingClientRect', 'offsetTop', 'offsetHeight', 'offsetWidth',
        'clientHeight', 'clientWidth', 'window.scrollY', 'requestAnimationFrame',
    ] as $api) {
        expect(str_contains($code, $api))
            ->toBeFalse("this screen measures layout in script: {$api}");
    }
});

/* ═════════════════════ handed over from the lane that fixed the upload 500 ══ */

it('never tells the owner his clip is untouched unless the server said so', function () {
    /*
     * THE DEFECT, and it is the most expensive sentence this screen ever printed.
     * The upload failure panel ended with a STATIC "— nothing on the clip was
     * changed." beside the size, for every failure, without knowing. When the
     * transcode blew up AFTER the file and the row had already committed, the
     * owner was told his 8.4 MB upload had failed when it had succeeded — so he
     * uploaded it again, minutes of a slow uplink at a time, orphaning a file on
     * every pass.
     *
     * POST /admin-api/ugc-videos/{id}/media now answers with `stored`, and the
     * panel prints the server's answer or no answer at all. THREE states, not
     * two: true, false, and undefined — a request that never arrived, or a server
     * that does not send the key — which must say NEITHER. Defaulting undefined
     * to false would put the wrong sentence straight back on the one path that
     * has no evidence for it.
     *
     * MUTATION NOTE. Change the `upDone.stored === false` test to a falsy test
     * (`!upDone.stored`) and the third assertion is red, because an unknown
     * outcome starts claiming the clip is untouched again. RUN: red.
     */
    $code = instantStepsCode();

    // The sentence is no longer unconditional.
    expect(str_contains($code, "esc(bytes(upDone.bytes)) + ' &mdash; nothing on the clip was changed.'"))
        ->toBeFalse('the failure panel asserts the clip is untouched again, without knowing');

    // It is the server's answer that decides, and `false` specifically.
    expect(str_contains($code, "upDone.stored === false ? ' &mdash; nothing on the clip was changed.'"))
        ->toBeTrue('the untouched sentence is no longer gated on the server saying so');

    // An unknown outcome says neither thing.
    $panel = substr($code, (int) strpos($code, "upDone.stored === false"));
    $panel = substr($panel, 0, 400);
    expect(str_contains($panel, 'upDone.stored === true'))
        ->toBeTrue('a committed row is not told apart from an unknown one');

    // ...and a committed row is told, so he does not upload it a second time.
    expect(str_contains($code, 'Do not upload it again.'))
        ->toBeTrue('a failure that DID store the file no longer says so');

    // The flag reaches the panel from both endings that have a server body.
    expect(substr_count($code, 'stored: f.body ? f.body.stored : undefined'))
        ->toBe(1, 'a failed request no longer passes the server\'s answer through');
    expect(substr_count($code, 'stored: payload ? payload.stored : undefined'))
        ->toBe(1, 'a refused file no longer passes the server\'s answer through');
});

it('keeps the reason a cover could not be cut on screen, in the server\'s own words', function () {
    /*
     * THE DEFECT. The endpoint's explanation arrived in `payload.notes` and the
     * screen did `(payload.notes || []).forEach(say)` and nothing else — so the
     * one sentence saying WHY there is no cover flashed for a couple of seconds
     * and was gone, while the thing that stayed on screen was a bare "No cover
     * yet" badge. Looking at a clip that says a file is missing and offers no
     * reason, the owner does the only thing that screen suggests: he uploads the
     * video again.
     *
     * AND THE TWO REASONS MUST NOT BE COLLAPSED. "This server has no ffmpeg" and
     * "ffmpeg is installed but PHP is not allowed to start it" have completely
     * different remedies — install a program, or change a PHP setting — and
     * UgcTranscoder keeps them apart on purpose. The screen must print whichever
     * the server sent rather than composing one of its own, which is what it used
     * to do: it asserted "It answered that it has no ffmpeg" from a bare bool,
     * and on the box this whole episode was about that was the wrong half.
     *
     * MUTATION NOTE. Delete `rememberNotes(payload.notes);` from onDone and the
     * second assertion is red — the explanation goes back to being a toast only.
     * Put the hard-coded "no ffmpeg" sub-line back in cutHTML() and the last is
     * red. RUN: red on both.
     */
    $code = instantStepsCode();

    expect(str_contains($code, 'function rememberNotes(notes)'))
        ->toBeTrue('nothing keeps the server\'s explanation');

    // Kept on every path that carries one: the upload, its failure, and the cut.
    expect(substr_count($code, 'rememberNotes('))
        ->toBe(4, 'a path that carries the reason no longer keeps it');

    // Drawn where the missing file is, not only said.
    expect(str_contains($code, 'data-ugs-cutnote'))
        ->toBeTrue('the reason is a toast again and nothing else');
    expect(str_contains($code, 'Why there is no cover here.'))
        ->toBeTrue('the persistent reason lost its heading');

    // Printed verbatim and escaped — it is the server's sentence, not a constant.
    expect(str_contains($code, "esc(cutNote)"))
        ->toBeTrue('the server\'s sentence is printed unescaped or rewritten');

    // It belongs to one clip, like the rest of the upload state.
    $forget = substr($code, (int) strpos($code, 'function forgetUpload()'));
    $forget = substr($forget, 0, (int) strpos($forget, 'function blank()'));
    expect(str_contains($forget, 'cutNote = null;'))
        ->toBeTrue('one clip\'s cut reason can now sit on another clip');

    // The screen no longer guesses WHICH of the two reasons it is.
    expect(str_contains($code, 'It answered that it has no ffmpeg'))
        ->toBeFalse('the screen guesses the reason again instead of printing the server\'s');
    expect(str_contains($code, 'transcoder.blocker'))
        ->toBeTrue('the screen no longer reads the reason the server sends');
});

it('sends the reason it cannot cut, and keeps the two reasons apart', function () {
    /*
     * The half of the case above that is a claim about the server, settled
     * against the server. blocker() is a pure function of two facts, so both
     * arms are reachable from here — including the one this container cannot
     * otherwise be put into, ffmpeg present and proc_open disabled, which is the
     * owner\'s box.
     *
     * MUTATION NOTE. Drop `blocker` from the transcoder payload and the first
     * assertion is red. Make blocker() answer NOTE_NO_FFMPEG for both arms and
     * the last is red. RUN: red on both.
     */
    UgcAdminRoutes::wire(app());

    $owner = AdminUser::create([
        'name' => 'S1 owner', 'email' => 's1-'.uniqid().'@example.test',
        'password' => 'secret-secret', 'role' => 'owner',
    ]);

    $body = $this->actingAs($owner, 'admin')
        ->getJson('/admin-api/ugc-videos')->assertOk()->json('transcoder');

    expect($body)->toHaveKey('blocker');

    // A box that can cut says nothing; a box that cannot says which kind it is.
    $t = app(\App\Services\UgcTranscoder::class);

    expect($t->blocker(true, '/usr/bin/ffmpeg'))->toBeNull();
    expect($t->blocker(true, null))->toBe(\App\Services\UgcTranscoder::NOTE_NO_FFMPEG);
    expect($t->blocker(false, '/usr/bin/ffmpeg'))->toBe(\App\Services\UgcTranscoder::NOTE_NO_SPAWN);

    // The two are different sentences, and each names its own remedy.
    expect(\App\Services\UgcTranscoder::NOTE_NO_FFMPEG)
        ->not->toBe(\App\Services\UgcTranscoder::NOTE_NO_SPAWN);
    expect(\App\Services\UgcTranscoder::NOTE_NO_SPAWN)->toContain('proc_open');
});
