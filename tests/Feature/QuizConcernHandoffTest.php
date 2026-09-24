<?php

declare(strict_types=1);

use App\Mail\QuizPlanEmail;
use App\Models\Product;
use App\Services\SettingsService;
use App\Support\ConcernCollections;
use App\Support\QuizRoutineLink;
use App\Support\RoutineConcerns;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Lane Q — the half of the quiz hand-off that the shipped shop can reach.
 *
 * ── WHAT ACTUALLY HAPPENS AT THE END OF THE QUIZ TODAY, MEASURED ───────────
 *
 * Lane FT built App\Support\QuizRoutineLink and wired it into the quiz, so the
 * plan's "the quiz -> routine hand-off" line is stale as written. But every URL
 * it can produce is a /routines page, and those live behind `build_my_routine`,
 * which SHIPS OFF. On the shop as it is applied:
 *
 *   - /skin-quiz renders and contains no routine table at all;
 *   - the routine pages 404;
 *   - the only address any button on the results screen carries is /shop/.
 *
 * So a shopper names up to three concerns, and the shop answers with three
 * chips, three hardcoded routine SHAPES with no products in them, and four
 * buttons to the whole catalogue. The concern is used for a chip and a row in
 * quiz_leads. Nothing else. The first case below fetches all of that rather
 * than asserting it.
 *
 * ── AND THE DESTINATION THAT DOES NOT NEED THE MODULE ──────────────────────
 *
 * /concern/{slug}/ (App\Support\ConcernCollections) is the other page per
 * concern, and it is gated on copy plus MIN_PRODUCTS live tagged products
 * rather than on the module switch. docs/SEO-FEATURE-MATRIX.md §2 ranks those
 * pages as the largest gap against the competitor and §3 item 1 calls the
 * tagging behind them "the single biggest blocker in the whole plan". The day
 * the owner finishes tagging acne, /concern/acne/ answers 200 whether or not
 * the routine module was ever switched on — and until this lane, the quiz still
 * would not have mentioned it.
 *
 * ── THE PROPERTY THAT MATTERS MOST IS STILL THE ABSENCE ────────────────────
 *
 * Nothing in this repository is tagged for any concern, so ConcernCollections
 * ::live() is [], the quiz emits no second table, and /skin-quiz is
 * byte-identical outside its inline script — 15,349 bytes of it either way,
 * measured. A link into a 404 is worse than no link, which is the rule Lane FT
 * set for the routine half and this keeps for the collection half.
 */
beforeEach(function () {
    /*
     * The migration set seeds a demo catalogue. None of it is tagged, but it is
     * cleared for the same reason QuizFollowThroughTest clears it: each case
     * below states its own catalogue in full, so a count can be asserted
     * exactly rather than "at least".
     */
    Product::query()->forceDelete();
});

/** A live, in-stock product tagged for some concerns. */
function qcProduct(array $concerns = [], array $overrides = []): Product
{
    return Product::create(array_replace([
        'slug' => 'qc-' . Str::random(8),
        'name' => 'QC ' . Str::random(4),
        'status' => 'publish',
        'is_visible' => true,
        'price' => 10000,
        'stock_status' => 'instock',
        'routine_concerns' => $concerns === [] ? null : json_encode($concerns),
    ], $overrides));
}

/** Enough tagged products for `acne` to have a page. */
function qcAcnePageLive(): void
{
    foreach (range(1, ConcernCollections::MIN_PRODUCTS) as $ignored) {
        qcProduct(['acne']);
    }
}

/** The concern-page table the quiz emits for its own script, decoded, or null. */
function qcConcernTable(string $html): ?array
{
    if (preg_match('/window\.KBB_CONCERN_PAGES = (\{.*?\});<\/script>/s', $html, $m) !== 1) {
        return null;
    }

    return json_decode($m[1], true);
}

/* ─────────────── 0. what the shipped shop does, by fetching it ───────────── */

it('offers a shopper nothing that uses their concern on the shop as it ships', function () {
    /*
     * THE MEASUREMENT THE REST OF THIS FILE IS THE ANSWER TO. Not a claim about
     * the code: both destinations are fetched and both 404, and the quiz page is
     * read for every way it could name either of them.
     *
     * MUTATION NOTE: set ConcernCollections::ENABLED to ['acne'] (it already is)
     * and seed MIN_PRODUCTS acne-tagged products before this case — the last two
     * expectations go red, because the concern table appears. That is the whole
     * behaviour, in one edit.
     */
    $this->get('/routines')->assertNotFound();
    $this->get('/concern/acne/')->assertNotFound();

    $html = $this->get('/skin-quiz')->assertOk()->getContent();

    expect(QuizRoutineLink::map())->toBeNull()
        ->and(QuizRoutineLink::concernPages())->toBeNull()
        ->and(ConcernCollections::live())->toBe([]);

    /*
     * THE ASSIGNMENTS, not the identifiers. Both names are READ by the page's
     * script on every render — that is how each feature switches itself off in
     * the browser — so a test looking for the bare word would fail against a
     * page behaving perfectly. Lane FT makes the same distinction and for the
     * same reason.
     */
    expect(str_contains($html, 'window.KBB_ROUTINES = '))->toBeFalse()
        ->and(str_contains($html, 'window.KBB_CONCERN_PAGES = '))->toBeFalse();

    // And no address of either kind reaches the document.
    expect(str_contains($html, '/concern/'))
        ->toBeFalse('The quiz named a concern page that answers 404.');
});

/* ─────────────────── 1. the concern collection hand-off ──────────────────── */

it('offers the concern page once the owner has tagged enough products for it', function () {
    /*
     * THE DEFECT THIS WOULD HAVE CAUGHT: the quiz never mentioned
     * /concern/{slug}/ at all. Every link it could draw needed
     * `build_my_routine`, which ships off, so the shop's answer to "I have acne"
     * was /shop/ — on the exact day the owner had finished the tagging job the
     * whole SEO plan is waiting on.
     *
     * The module stays OFF in this case, deliberately: that is the shipped
     * configuration, and the point is that this destination does not need it.
     */
    qcAcnePageLive();

    $this->get('/routines')->assertNotFound();
    $this->get('/concern/acne/')->assertOk();

    $html = $this->get('/skin-quiz')->assertOk()->getContent();
    $table = qcConcernTable($html);

    expect($table)->toBeArray();

    /*
     * KEYED BY THE QUIZ'S OWN ENGLISH, ampersand and all. The lookup in the
     * page is `table[state.concerns[i]]`, and state.concerns holds the strings
     * the shopper clicked — which are RoutineConcerns' right-hand column,
     * character for character. A key spelled any other way is a link that never
     * appears, silently.
     */
    expect(array_keys($table))->toBe(['Acne & blemishes'])
        ->and($table['Acne & blemishes'])->toContain('/concern/acne/');

    // Every URL in the table is a page that answers.
    $this->get($table['Acne & blemishes'])->assertOk();
});

it('never offers a concern page that is one product short of existing', function () {
    /*
     * THE DEFECT: a link into a 404. ConcernCollections::MIN_PRODUCTS is the
     * thin-page floor, and a quiz that linked at MIN_PRODUCTS - 1 would send a
     * shopper to the router's 404 page.
     *
     * MUTATION NOTE: change concernPages() to iterate
     * ConcernCollections::slugs() instead of ::live() and this goes red on the
     * first expectation.
     */
    foreach (range(1, ConcernCollections::MIN_PRODUCTS - 1) as $ignored) {
        qcProduct(['acne']);
    }

    $this->get('/concern/acne/')->assertNotFound();

    expect(QuizRoutineLink::concernPages())->toBeNull();

    $html = $this->get('/skin-quiz')->assertOk()->getContent();

    expect(str_contains($html, 'window.KBB_CONCERN_PAGES = '))->toBeFalse();
});

it('counts only products a shopper could actually be shown', function () {
    /*
     * THE DEFECT: tagging a draft, a hidden row or a sold-out product would
     * have moved a countdown and opened a link to a page that still 404s,
     * because ConcernCollections::query() filters on visible() and in-stock and
     * the link would not have.
     */
    qcProduct(['acne']);
    qcProduct(['acne'], ['status' => 'draft']);
    qcProduct(['acne'], ['is_visible' => false]);
    qcProduct(['acne'], ['stock_status' => 'outofstock']);

    expect(ConcernCollections::counts(['acne'])['acne'])->toBe(1)
        ->and(QuizRoutineLink::concernPages())->toBeNull();
});

it('sends the shopper to the first of their own concerns that has a page', function () {
    /*
     * THE DEFECT A FALLBACK WOULD BE: a shopper who picked "Sun protection"
     * being handed the acne page because it is the only one that exists. The
     * order the shopper clicked is the only statement of priority the quiz
     * collects, and a page for a concern they did not name is somebody else's
     * answer.
     *
     * MUTATION NOTE: delete the isset() guard in concernUrlForConcerns() and
     * return the first value of the map instead — the second expectation goes
     * red.
     */
    qcAcnePageLive();

    expect(QuizRoutineLink::concernUrlForConcerns(['Hydration', 'Acne & blemishes']))
        ->toContain('/concern/acne/');
    expect(QuizRoutineLink::concernUrlForConcerns(['Sun protection', 'Hydration']))
        ->toBeNull();
    expect(QuizRoutineLink::concernUrlForConcerns(['not a concern anybody offers']))
        ->toBeNull();
});

it('prefers the routine to the collection when the shop has both', function () {
    /*
     * Both destinations exist here. The routine wins, because it is five named
     * steps filled from this shop's stock and the collection is a shelf — and
     * the two tables stay SEPARATE so each keeps its own wording. A merged
     * table would print "Build my acne routine" over a collection URL.
     */
    app(SettingsService::class)->setModule('build_my_routine', true);
    qcAcnePageLive();
    // A product with a role, so the acne routine can fill a step.
    qcProduct(['acne'], ['routine_role' => 'cleanse']);

    $html = $this->get('/skin-quiz')->assertOk()->getContent();

    expect(qcConcernTable($html))->toBeArray()
        ->and(QuizRoutineLink::map())->toBeArray();

    // The page holds both tables, and the script takes the routine first.
    expect(str_contains($html, 'window.KBB_ROUTINES = '))->toBeTrue()
        ->and(str_contains($html, 'window.KBB_CONCERN_PAGES = '))->toBeTrue();

    /*
     * The precedence itself, read from the source rather than asserted about
     * it: routineLinkHTML() returns the routine block and only then reaches
     * concernPick(). A later lane that reordered those two would change which
     * page a shopper lands on with no test noticing.
     */
    $script = (string) file_get_contents(resource_path('views/store/skin-quiz.blade.php'));

    expect(strpos($script, 'const pick=routinePick();'))
        ->toBeLessThan((int) strpos($script, 'const cpick=concernPick();'));
});

/* ─────────────────────────── 2. the plan email ───────────────────────────── */

it('sends the plan email to the concern page when there is no routine to offer', function () {
    /*
     * THE DEFECT: the email fell straight from "routine" to "the whole shop".
     * `$routineUrl ?? $shopUrl` meant a shopper who had just named a concern
     * got a button to /shop/ even on a shop whose concern page was live.
     *
     * MUTATION NOTE: put `$kbbCtaUrl = $routineUrl ?? $shopUrl;` back in
     * resources/views/emails/quiz-plan.blade.php and this goes red on the
     * /concern/acne/ expectation.
     */
    Mail::fake();
    qcAcnePageLive();

    $this->postJson('/api/quiz', [
        'skinType' => 'Oily',
        'concerns' => ['Acne & blemishes'],
        'contact' => ['name' => 'Aisha', 'phone' => '+971500000000', 'email' => 'aisha@example.test'],
        'recommendedRoutines' => [['name' => 'Everyday Essentials', 'steps' => ['Cleanse', 'Treat']]],
        'consent' => true,
    ])->assertSuccessful();

    Mail::assertSent(QuizPlanEmail::class, function (QuizPlanEmail $mail) {
        $html = (string) $mail->render();

        expect($html)->toContain('/concern/acne/')
            ->and($html)->toContain('Shop for your concern');

        return true;
    });
});

it('keeps the plan email pointed at the shop when no concern page exists', function () {
    /*
     * The shipped state, which must not have moved. Nothing is tagged, so the
     * message is exactly the message it was.
     */
    Mail::fake();

    $this->postJson('/api/quiz', [
        'skinType' => 'Oily',
        'concerns' => ['Acne & blemishes'],
        'contact' => ['name' => 'Aisha', 'phone' => '+971500000000', 'email' => 'aisha@example.test'],
        'recommendedRoutines' => [['name' => 'Everyday Essentials', 'steps' => ['Cleanse', 'Treat']]],
        'consent' => true,
    ])->assertSuccessful();

    Mail::assertSent(QuizPlanEmail::class, function (QuizPlanEmail $mail) {
        $html = (string) $mail->render();

        expect($html)->not->toContain('/concern/')
            ->and($html)->toContain('Browse the shop');

        return true;
    });
});

/* ──────────────────── 3. one vocabulary, not two ─────────────────────────── */

it('keys the concern table with the same eight strings the quiz posts', function () {
    /*
     * RoutineConcerns' header spends four paragraphs on why a second concern
     * vocabulary is the failure to avoid, and Lane S2 withdrew three proposed
     * slugs over it. This pins that concernPages() invents nothing: every key it
     * can ever produce is one of the eight, spelled the way the quiz spells it.
     */
    foreach (RoutineConcerns::slugs() as $slug) {
        expect(RoutineConcerns::adminLabel($slug))->toBeString();
    }

    qcAcnePageLive();

    foreach (array_keys(QuizRoutineLink::concernPages() ?? []) as $key) {
        expect(in_array($key, array_values(RoutineConcerns::LIST), true))->toBeTrue(
            'The quiz hand-off invented the concern label "' . $key . '".'
        );
    }
});

it('asks ConcernCollections rather than counting for itself', function () {
    /*
     * THE DEFECT A SECOND IMPLEMENTATION WOULD BE: the router 404s on
     * ConcernCollections::isLive() and the quiz would have linked on its own
     * arithmetic. One class decides, so the two cannot disagree.
     */
    qcAcnePageLive();

    $map = QuizRoutineLink::concernPages() ?? [];

    foreach (RoutineConcerns::slugs() as $slug) {
        $inMap = array_key_exists(RoutineConcerns::adminLabel($slug), $map);

        expect($inMap)->toBe(ConcernCollections::isLive($slug));

        $this->get(ConcernCollections::path($slug))
            ->assertStatus($inMap ? 200 : 404);
    }
});
