<?php

/**
 * Store → SEO & Meta → Overview — Lane S7.
 *
 * ── THE TWO QUESTIONS THIS SCREEN EXISTS FOR ────────────────────────────────
 *
 * "What do I still have to do?" — several features in this shop are BUILT AND
 * IDLE waiting on one thing from the owner, and each of them says so in a
 * different document. A concern page 404s until three products are tagged for
 * it; the LocalBusiness node publishes no address until the street and city are
 * filled in, on a shop whose type may already say LocalBusiness. Neither has any
 * symptom at all — they are not broken pages, they are absent ones, so no audit
 * can see them and no screen listed them.
 *
 * "Is my SEO healthy?" — App\Support\SeoAudit answers this and this screen DOES
 * NOT REWRITE IT. It calls SeoAudit::run() and adds a rank and a place to go.
 *
 * ── WHAT THIS FILE ASSERTS AND WHY EACH ONE WOULD HAVE CAUGHT SOMETHING ─────
 *
 * The defects it guards against, in order of how much they would cost:
 *
 *   1. A HARD-CODED TASK. A to-do list with a row that is always there goes on
 *      nagging after the work is done, and a screen that nags about finished
 *      work is a screen nobody reads — which loses the rows that are real. So
 *      every task is asserted to DISAPPEAR when its condition is met.
 *   2. A FINDING THE RANK HAS NEVER HEARD OF. SeoAudit belongs to another lane
 *      and grows checks. An unranked finding must be DRAWN (the audit screen's
 *      own header records the failure of a check that "would scan, count, and
 *      then not be drawn") and somebody must be TOLD. Both are asserted.
 *   3. A RANK THAT CONTRADICTS THE AUDIT. SeoAudit::ADVISORY is PUBLIC now — the
 *      integrator made it so at this lane's request, for exactly this — so the
 *      two judgements are asserted equal rather than described as "must not
 *      drift". The audit's verdict line skips an advisory finding; this screen
 *      ranks it `later`; those are the same sentence said twice, and a case
 *      below now fails if they stop being.
 *   4. THE WHOLE SHOP HIDDEN FROM GOOGLE, reported below everything else. It is
 *      one select with no other symptom, and while it is true every other line
 *      on the screen is a reading of a switched-off machine.
 */

use App\Http\Controllers\Admin\SeoTasksApiController;
use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Product;
use App\Models\Setting;
use App\Support\ConcernCollections;
use App\Support\SeoAudit;
use Illuminate\Support\Facades\Hash;
use Tests\Support\SeoBackOfficeRoutes;

beforeEach(function () {
    SeoBackOfficeRoutes::wire(app());

    $owner = AdminUser::create([
        'name' => 'S7 Overview Owner',
        'email' => 's7-overview-owner@example.com',
        'password' => Hash::make('secret-secret'),
        'role' => 'owner',
    ]);

    test()->actingAs($owner, 'admin');
});

/** The Overview endpoint, as the console asks for it. */
function s7Tasks(): array
{
    $response = test()->getJson('/admin-api/seo-tasks');

    expect($response->status())->toBe(200, 'the overview endpoint refused');

    return $response->json();
}

/** @return list<string> the `key` of every task, in the order returned. */
function s7TaskKeys(array $payload): array
{
    return array_map(static fn (array $t): string => $t['key'], $payload['tasks'] ?? []);
}

it('ranks every finding the audit can report, and says so when it cannot', function () {
    /*
     * THE GUARD ON ANOTHER LANE'S FILE.
     *
     * App\Support\SeoAudit belongs to Lane S6 this round and grows checks. A
     * finding this controller has never heard of is still DRAWN — in the middle
     * band, carrying the audit's own words — because a check that scans, counts
     * and is then not shown is the exact failure the audit screen's own header
     * was written against. But somebody has to be told, or the rank silently
     * becomes "whatever the default was", which is a decision nobody made.
     *
     * This is that telling. It fails with the key named.
     */
    $findings = array_keys(SeoAudit::run()['findings']);

    expect($findings)->not->toBeEmpty();

    $unranked = array_values(array_diff($findings, array_keys(SeoTasksApiController::RANK)));

    expect($unranked)->toBe([], 'SeoAudit reports findings this screen does not rank, so they land in '
        .'the middle band by default: '.implode(', ', $unranked).'. Add each to '
        .'SeoTasksApiController::RANK with a band, what it costs the owner, and where to fix it.');

    // And nothing ranked that the audit cannot report — a rank for a finding
    // that no longer exists is a promise the screen cannot keep.
    $stale = array_values(array_diff(array_keys(SeoTasksApiController::RANK), $findings));

    expect($stale)->toBe([], 'these are ranked and the audit no longer reports them: '.implode(', ', $stale));
});

it('gives every rank a band the screen prints and a cost in the owner’s words', function () {
    foreach (SeoTasksApiController::RANK as $key => $row) {
        expect($row)->toHaveCount(4, "{$key} is not [band, cost, where, go]");

        [$band, $cost, $where, $go] = $row;

        expect(array_key_exists($band, SeoTasksApiController::BANDS))
            ->toBeTrue("{$key} is in band '{$band}', which the screen does not print");

        /*
         * A cost line that is short is a cost line that says "missing
         * description" again — which is the vocabulary, not the consequence, and
         * the audit's own `why` already covers the mechanism underneath it.
         */
        expect(mb_strlen($cost))->toBeGreaterThan(40, "{$key} has no real cost sentence");
        expect($where)->not->toBe('', "{$key} does not say where to fix it");
        expect(is_string($go))->toBeTrue("{$key}'s go id is not a string");
    }
});

it('draws a finding it has never heard of rather than dropping it', function () {
    /*
     * The other half of the guard above, and the half that matters on the day
     * S6 adds a check: the screen keeps working. Driven through the real
     * grouping rather than asserted about it.
     */
    $payload = s7Tasks();

    expect($payload['health']['bands'])->toBeArray()->not->toBeEmpty();

    $bands = array_column($payload['health']['bands'], 'band');

    expect($bands)->toBe(array_keys(SeoTasksApiController::BANDS));

    // Every band carries its printed heading, so the screen never draws a group
    // of cards with no explanation of why they are grouped.
    foreach ($payload['health']['bands'] as $band) {
        expect($band['heading'])->toBe(SeoTasksApiController::BANDS[$band['band']]);
    }
});

it('puts a finding with nothing wrong on one clear line instead of a card', function () {
    /*
     * THE DEFECT: the SEO Audit tab draws TWELVE cards on every shop, and on a
     * healthy one eleven say "None — nothing on the shop has this problem".
     * Twelve identical cards is a page that has to be read to discover it is
     * fine, and a wall of identical cards is read by nobody — which is how the
     * one card that is NOT fine gets missed.
     *
     * Its own note is right that a clean shop must show something rather than an
     * empty page, because an empty audit looks like one that failed to run. Both
     * hold here: every clear check is NAMED on one line, and only a finding with
     * a number on it gets a card.
     */
    $payload = s7Tasks();

    $carded = [];
    foreach ($payload['health']['bands'] as $band) {
        foreach ($band['items'] as $item) {
            $carded[] = $item['label'];
            expect($item['count'])->toBeGreaterThan(0, $item['key'].' got a card with a count of zero');
        }
    }

    $clear = $payload['health']['clear'];
    $all = array_map(
        static fn (array $f): string => $f['label'],
        SeoAudit::run()['findings']
    );

    // Every finding is accounted for exactly once: a card, or a clear line.
    expect(count($carded) + count($clear))->toBe(count($all));
    expect(array_intersect($carded, $clear))->toBe([]);
});

it('reports the shop hidden from Google above everything else', function () {
    /*
     * ONE SELECT, NO OTHER SYMPTOM. `robots_index: noindex` takes the entire
     * shop out of search results and the site renders perfectly — every page,
     * every product, every price. Nothing anywhere in this console said so.
     *
     * FIRST IN THE LIST and in a band of its own, because every other line on
     * this screen is a reading of a switched-off machine while it is true.
     */
    $before = s7TaskKeys(s7Tasks());
    expect($before)->not->toContain('noindex');

    Setting::query()->updateOrCreate(['key' => 'robots_index'], ['value' => 'noindex']);
    Setting::flushMap();

    $payload = s7Tasks();
    $keys = s7TaskKeys($payload);

    expect($keys[0])->toBe('noindex');
    expect($payload['tasks'][0]['urgency'])->toBe('stop');
    expect($payload['tasks'][0]['go'])->toBe('seo');
    expect($payload['tasks'][0]['where'])->toContain('Search engines');
});

it('counts the concern pages that are waiting, and stops the moment they are not', function () {
    /*
     * docs/SEO-BUILD-PLAN.md ranks this first of everything, and
     * App\Support\ConcernCollections is built and live. /concern/acne/ is a 404
     * until MIN_PRODUCTS live in-stock products carry the tag, which is correct
     * — "a concern collection with four products and no copy is worse than no
     * page" — and completely invisible: there is no broken page to find, only an
     * absent one.
     *
     * THE ROW DISAPPEARS WHEN THE WORK IS DONE, which is the property that keeps
     * this screen worth opening. A hard-coded row would still be here.
     */
    $enabled = ConcernCollections::slugs();
    expect($enabled)->not->toBeEmpty('no concern has copy, so there is nothing to wait for');

    $slug = $enabled[0];

    $payload = s7Tasks();
    $task = collect($payload['tasks'])->firstWhere('key', 'concern_pages');

    expect($task)->not->toBeNull('an untagged shop is not being told its concern pages are waiting');
    expect($task['go'])->toBe('routines');
    expect($task['where'])->toContain('Build my routine');
    expect($task['detail'])->toContain('0 of '.ConcernCollections::MIN_PRODUCTS);

    /*
     * Tag exactly enough products for EVERY enabled concern, through the column
     * the routine builder writes.
     *
     * All eight are enabled as of Lane S8, so the row only discharges once all
     * eight are over the floor -- which is the honest reading of "the work is
     * done" and is what the owner's own countdown says. Tagging one concern and
     * expecting the row to vanish would have been the wrong assertion the moment
     * a second concern got copy.
     */
    for ($i = 0; $i < ConcernCollections::MIN_PRODUCTS; $i++) {
        Product::create([
            'name' => 'S7 tagged '.$i,
            'slug' => 's7-tagged-'.$i,
            'status' => 'publish',
            'is_visible' => true,
            'stock_status' => 'instock',
            'price' => 5500,
            // Exactly as Admin\RoutinesApiController::tag() writes it. One
            // product can carry several concerns, which is how a real catalogue
            // is tagged.
            'routine_concerns' => json_encode($enabled),
        ]);
    }

    foreach ($enabled as $each) {
        expect(ConcernCollections::count($each))->toBe(ConcernCollections::MIN_PRODUCTS);
    }

    $after = s7TaskKeys(s7Tasks());

    expect(in_array('concern_pages', $after, true))->toBeFalse();
});

it('asks for the address only when the shop has claimed to have one', function () {
    /*
     * A HALF-FILLED ADDRESS IS WORSE THAN NONE — App\Support\BusinessAddress
     * refuses to publish one, so a shop whose type says LocalBusiness and whose
     * address is blank sends a node with a name and a logo and nothing that
     * places it anywhere. The setting has offered Store and LocalBusiness since
     * the SEO screen was built and choosing either bought nothing.
     *
     * AND ONLY THEN. An online-only shop is not nagged for a street it does not
     * have, which is the difference between a to-do list and a checklist
     * somebody else wrote.
     */
    expect(s7TaskKeys(s7Tasks()))->not->toContain('localbusiness_address');

    Setting::query()->updateOrCreate(['key' => 'org_type'], ['value' => 'LocalBusiness']);
    Setting::flushMap();

    $payload = s7Tasks();
    $task = collect($payload['tasks'])->firstWhere('key', 'localbusiness_address');

    expect($task)->not->toBeNull();
    expect($task['go'])->toBe('store-settings');
    expect($task['where'])->toContain('Where the shop is');
    expect($task['why'])->toContain('LocalBusiness');

    // Fill it in the way the Business tab does, and the row goes.
    Setting::query()->updateOrCreate(['key' => 'store_street'], ['value' => 'Shop 4, Al Wasl Road']);
    Setting::query()->updateOrCreate(['key' => 'store_locality'], ['value' => 'Dubai']);
    Setting::query()->updateOrCreate(['key' => 'store_country'], ['value' => 'AE']);
    Setting::flushMap();

    expect(s7TaskKeys(s7Tasks()))->not->toContain('localbusiness_address');
});

it('tells the owner when his map pin is filled in and not being published', function () {
    /*
     * The opposite mistake, and it is silent in the other direction: `geo` and
     * `openingHoursSpecification` are properties of a schema.org PLACE, and of
     * the four types the setting offers only Store and LocalBusiness are Places.
     * So an owner who types his coordinates and leaves the type at Organization
     * gets a valid document with an address in it and no pin — correct
     * behaviour, and there was nothing anywhere to tell him.
     */
    foreach ([
        'store_street' => 'Shop 4, Al Wasl Road',
        'store_locality' => 'Dubai',
        'store_country' => 'AE',
        'store_latitude' => '25.2048',
        'store_longitude' => '55.2708',
        'org_type' => 'Organization',
    ] as $key => $value) {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
    Setting::flushMap();

    $task = collect(s7Tasks()['tasks'])->firstWhere('key', 'place_type');

    expect($task)->not->toBeNull();
    expect($task['why'])->toContain('Organization');
    expect($task['detail'])->toContain('walk in');
});

it('bands exactly the audit’s own advisory findings as “when you have time”', function () {
    /*
     * THE TWO JUDGEMENTS, PINNED EQUAL. SeoAudit::ADVISORY names the findings
     * the audit deliberately keeps out of its headline verdict — real, but
     * nothing is broken while they wait. This screen says the same thing in its
     * own vocabulary by banding them `later`. Two places, one judgement, and
     * until the constant was made public the only thing holding them together
     * was a comment asking a future reader not to let them drift.
     *
     * BOTH DIRECTIONS, because one alone is half a pin: every advisory finding
     * is banded `later`, AND nothing else is — so promoting an advisory finding
     * to `next`, or quietly demoting a real fault to `later`, is red.
     *
     * MUTATION: move 'legacy_url_no_redirect' to 'next' in RANK, or add
     * 'no_image' to SeoAudit::ADVISORY. Either one reddens this.
     */
    $later = array_keys(array_filter(
        SeoTasksApiController::RANK,
        static fn (array $row): bool => $row[0] === 'later'
    ));

    sort($later);

    $advisory = SeoAudit::ADVISORY;
    sort($advisory);

    expect(array_values(array_intersect($later, $advisory)))->toBe($advisory,
        'SeoAudit calls these advisory and this screen does not band them “when you have time”');

    /*
     * And the other direction is deliberately NOT "later === advisory": three
     * findings are banded `later` without being advisory (duplicate_description,
     * title_too_long, product_no_image_alt is advisory, so: the two duplicates
     * and the long title), because a fault can be real, counted in the verdict,
     * and still not urgent. What must not happen is an ADVISORY finding
     * appearing anywhere BUT `later`.
     */
    $notLater = array_keys(array_filter(
        SeoTasksApiController::RANK,
        static fn (array $row): bool => $row[0] !== 'later'
    ));

    expect(array_values(array_intersect($notLater, $advisory)))->toBe([],
        'an advisory finding is banded above “when you have time”');
});

/* ── the FAQ row, in both of its two directions ──────────────────────────── */

/**
 * A content page with a given number of question headings. `updateOrCreate` on
 * the seeded /faqs/ row, so the shop still has exactly seven pages.
 */
function s7FaqPage(int $questions): void
{
    $body = '';

    for ($i = 1; $i <= $questions; $i++) {
        $body .= '<h2>Question number ' . $i . '?</h2>'
            . '<p>An answer long enough to clear the four-word floor, number ' . $i . '.</p>';
    }

    $body .= '<h2>Not a question</h2><p>A heading that does not end in a question mark.</p>';

    \App\Models\Page::updateOrCreate(['slug' => 'faqs'], [
        'title' => 'Frequently Asked Questions',
        'status' => 'published',
        'content' => $body,
        'seo' => null,
    ]);
}

it('reports questions Google is not being shown, and counts the real ones', function () {
    /*
     * THE ROW HAS TO APPEAR, not merely disappear. Every other FAQ assertion in
     * this file and in SeoIntegratorWiringTest is about the row going away when
     * the work is done — so removing the row entirely left the whole suite GREEN
     * when the mutation was run, which is the honest reason this case exists.
     *
     * Neither state of this switch has any symptom on the shop: markup off with
     * questions on the page publishes nothing and looks identical to markup on.
     * That is the whole reason the Overview screen exists.
     *
     * MUTATION: delete the `! $faqOn && questions > 0` block from
     * SeoTasksApiController::tasks() and this is red.
     */
    Setting::query()->updateOrCreate(['key' => 'faq_schema'], ['value' => '0']);
    Setting::flushMap();

    s7FaqPage(3);

    $task = collect(s7Tasks()['tasks'])->firstWhere('key', 'faq_off_with_questions');

    expect($task)->not->toBeNull();

    // The COUNT is the point: a row that says "some questions" is a row nobody
    // acts on. Three questions on one page, and the heading that is not a
    // question is not among them.
    expect($task['title'])->toContain('3 questions');
    expect($task['detail'])->toContain('3 questions across 1 page');

    // Singular, not "1 pages".
    expect($task['detail'])->not->toContain('1 pages');
});

it('does not count a page carrying a single question', function () {
    /*
     * The census applies the SAME floor the published node does
     * (FaqSchema::MIN_QUESTIONS is 2, because one question is a heading and not
     * an FAQ). A census that counted it would promise a node the graph does not
     * emit — the owner switches the markup on, nothing appears, and the screen
     * that told him to do it was the thing that was wrong.
     *
     * MUTATION: lower the floor inside FaqSchema::census() to 1 and this is red.
     */
    Setting::query()->updateOrCreate(['key' => 'faq_schema'], ['value' => '0']);
    Setting::flushMap();

    s7FaqPage(1);

    expect(\App\Services\Seo\FaqSchema::census())->toBe(['pages' => 0, 'questions' => 0]);

    expect(s7TaskKeys(s7Tasks()))->not->toContain('faq_off_with_questions');
});

it('says so when the markup is on and no page is written as questions', function () {
    /*
     * THE OTHER WRONG STATE, and the quieter one: a switch the owner has ticked
     * that publishes nothing. Nothing is broken, which is precisely why he would
     * never find out — so it is banded `later` and it is SAID.
     */
    Setting::query()->updateOrCreate(['key' => 'faq_schema'], ['value' => '1']);
    Setting::flushMap();

    s7FaqPage(0);

    $keys = s7TaskKeys(s7Tasks());

    expect($keys)->toContain('faq_on_without_questions');
    expect($keys)->not->toContain('faq_off_with_questions');
});

it('says neither thing when the markup is on and the questions are there', function () {
    // Both rows are computed, so the done state is silence — not a green tick
    // that stays on the screen for ever.
    Setting::query()->updateOrCreate(['key' => 'faq_schema'], ['value' => '1']);
    Setting::flushMap();

    s7FaqPage(3);

    $keys = s7TaskKeys(s7Tasks());

    expect($keys)->not->toContain('faq_on_without_questions');
    expect($keys)->not->toContain('faq_off_with_questions');
});

it('counts exactly the questions the published graph carries', function () {
    /*
     * THE TWO NUMBERS, JOINED. The census is a promise about what turning the
     * switch on would publish, and the only way that promise can be kept is if
     * it is computed by the same code the graph is. So: count them, turn the
     * switch on, fetch the real page, and count the node's own mainEntity.
     *
     * A second implementation that merely agreed today is the defect this
     * forbids — it would drift the first time either rule changed.
     */
    s7FaqPage(3);

    Setting::query()->updateOrCreate(['key' => 'faq_schema'], ['value' => '0']);
    Setting::flushMap();

    $census = \App\Services\Seo\FaqSchema::census();

    expect($census)->toBe(['pages' => 1, 'questions' => 3]);

    Setting::query()->updateOrCreate(['key' => 'faq_schema'], ['value' => '1']);
    Setting::flushMap();

    $html = test()->get('/faqs/')->assertOk()->getContent();

    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

    $published = 0;

    foreach ($m[1] as $json) {
        $decoded = json_decode($json, true);

        expect($decoded)->not->toBeNull('a ld+json block on /faqs/ is not valid JSON');

        /*
         * array_is_list, and the first draft of this case did NOT have it: a
         * graph emitted as ONE object rather than a list decodes to an assoc
         * array whose values are its own FIELDS, so iterating it yields strings
         * and finds no node. It failed with "0 is identical to 3" and looked
         * exactly like the feature being broken.
         */
        foreach (array_is_list($decoded) ? $decoded : [$decoded] as $node) {
            if (is_array($node) && ($node['@type'] ?? null) === 'FAQPage') {
                $published += count($node['mainEntity']);
            }
        }
    }

    expect($published)->toBe($census['questions'],
        'the Overview promises a number of questions the page does not actually publish');
});

it('drops every task it can, on a shop that has answered everything', function () {
    /*
     * The strongest statement this file makes: NOT ONE ROW IS HARD-CODED. Fill
     * in the eight settings the list asks about and the list empties. A single
     * row surviving here is a row that nags for ever, which is how this screen
     * stops being read.
     *
     * The concern row is the one that cannot be answered with a setting, so it
     * is answered with products — the same way the owner will answer it.
     */
    foreach ([
        'site_url' => 'https://kbb.test',
        'seo_home_description' => 'Authentic Korean skincare, delivered across the Emirates.',
        'og_default_image' => 'https://kbb.test/share.jpg',
        'google_site_verification' => 'a-real-looking-token',
        'enable_merchant' => '1',
        'org_logo' => 'https://kbb.test/logo.png',
        'social_instagram' => 'https://instagram.com/kbeautybliss',
        'robots_index' => 'index',
        'sitemap_enabled' => '1',
        /*
         * Added by the integrator with the FAQ row, and it STRENGTHENS this case
         * rather than excusing it: the seeded /faqs/ page really is written as
         * questions, so with the markup off the screen correctly says so, and
         * the row is answered the way the owner would answer it -- by switching
         * the markup on, not by the test looking away. The opposite row
         * (markup on, no page written as questions) cannot fire here for the
         * same reason: the questions exist.
         */
        'faq_schema' => '1',
    ] as $key => $value) {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
    Setting::flushMap();

    foreach (ConcernCollections::slugs() as $slug) {
        for ($i = 0; $i < ConcernCollections::MIN_PRODUCTS; $i++) {
            Product::create([
                'name' => 'S7 done '.$slug.' '.$i,
                'slug' => 's7-done-'.$slug.'-'.$i,
                'status' => 'publish',
                'is_visible' => true,
                'stock_status' => 'instock',
                'price' => 5500,
                'routine_concerns' => json_encode([$slug]),
            ]);
        }
    }

    expect(s7TaskKeys(s7Tasks()))->toBe([]);
});

it('hands the browser the audit’s own sample rows and nothing else', function () {
    /*
     * Rule 5. Admin\SeoAuditApiController builds each sample row by hand out of
     * four keys — kind, name, url and an optional detail — because `products`
     * carries `wc_id`, `sku` and `total_sales` and an audit screen has no
     * business handing any of them to the browser. This screen FORWARDS those
     * rows, so the same guarantee has to hold here, and it has to hold by
     * construction rather than because the audit happens to be tidy today.
     */
    Brand::create(['name' => 'X', 'slug' => 's7-thin-brand']);

    $payload = s7Tasks();

    $seen = 0;

    foreach ($payload['health']['bands'] as $band) {
        foreach ($band['items'] as $item) {
            foreach ($item['samples'] as $sample) {
                $seen++;
                expect(array_keys($sample))->each->toBeIn(['kind', 'name', 'url', 'detail']);
            }
        }
    }

    expect($seen)->toBeGreaterThan(0, 'no sample rows were produced, so this asserted nothing');
});

it('passes the audit’s verdict through rather than inventing a second one', function () {
    /*
     * SeoAudit::verdict() already names the biggest finding and its count rather
     * than printing a score, and its own comment says why: "a score is a number
     * nobody knows what to do with and '37 products have no description' is a
     * morning's work with an obvious beginning". Rewriting it on this screen
     * would be a second opinion about one scan, and the two would disagree
     * within a month.
     */
    $payload = s7Tasks();

    expect($payload['health']['verdict'])->toBe(SeoAudit::run()['verdict']);
    expect($payload['health']['total'])->toBe(SeoAudit::run()['total']);
    expect($payload['health']['scanned'])->toBe(SeoAudit::run()['scanned']);
});
