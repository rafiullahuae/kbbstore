<?php

declare(strict_types=1);

/*
 * ════════════════════════════════════════════════════════════════════════════
 * LANE A, ROUND 2 — Phase 13: "three-bucket classification: migrate / discard /
 * ask — Rafi approves any discard list"
 * ════════════════════════════════════════════════════════════════════════════
 *
 * WHAT THE SHOP LOOKED LIKE WITH THIS DEFECT IN IT, which is not a stack trace
 * and is why it stayed open for as long as it did:
 *
 * `RedirectMap` built three buckets correctly and the screen drew them
 * correctly. THE ASK BUCKET WAS A TILE WITH A NUMBER ON IT AND NOTHING TO
 * PRESS. Every load re-derived the same rows, so the list came back identical
 * for ever, and the only way to act on one was to copy the address onto
 * Store → Redirects and retype the destination. The owner could read the
 * question and could not answer it.
 *
 * Then it got bigger. `CheckRedirects` was registered in the global pipeline
 * (docs/GP-ADDRESSES-LAND.md §13.7), so three branches of `reachable()` that
 * rested on "the table only fires on a 404" changed their minds — one of them
 * had been DISCARDING its proposals silently, and on a real export that branch
 * is most of the category rule. A list nobody could act on became a longer list
 * nobody could act on.
 *
 * THE FOUR PROPERTIES ASSERTED HERE, in the order they would hurt:
 *
 *  1. AN ANSWER IS TO A QUESTION, NOT TO AN ADDRESS. Approving
 *     "/x/ → /a/" must never become approval of "/x/ → /b/" because somebody
 *     re-parented a category afterwards. It goes STALE and is asked again.
 *
 *  2. THE ENDPOINT NEVER TAKES A DESTINATION FROM THE REQUEST. Otherwise
 *     "approve" is a button that points this shop's indexed addresses anywhere.
 *
 *  3. A QUESTION WITH NOWHERE TO SEND ANYBODY CANNOT BE ACCEPTED. Three of them
 *     have no destination at all, and accepting one would write
 *     `redirects.target = ''`.
 *
 *  4. IT ANSWERS IN BULK IN A BOUNDED NUMBER OF QUERIES. A question is asked
 *     hundreds of times and the answer is one answer; a loop of
 *     updateOrCreate() would be two queries per row.
 *
 * `expect(...)->not->toContain($x, $message)` IS NOT USED ANYWHERE HERE.
 * `toContain` is variadic, so the message is read as a second needle and the
 * assertion passes vacuously — CLAUDE.md records it.
 */

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Redirect;
use App\Models\RedirectDecision;
use App\Services\Import\RedirectDecisions;
use App\Services\Import\RedirectMap;
use Illuminate\Support\Facades\DB;
use Tests\Support\UrlsMediaAdminRoutes;

/**
 * A category tree that makes the map ask three different questions, through the
 * real router and the real reachability check rather than a stub.
 *
 *   /shop/                      SERVED  — the shop answers this address today
 *   /product-category/qa-toners/ MOVED  — the archive controller 301s it itself
 *   /product-category/qa-orphan/ no target — stranded with no computed path
 */
function qaTree(): array
{
    $parent = Category::query()->create(['name' => 'QA Skincare', 'slug' => 'qa-skincare', 'parent_id' => null]);
    $parent->forceFill(['path' => 'qa-skincare', 'depth' => 0])->save();

    $child = Category::query()->create(['name' => 'QA Toners', 'slug' => 'qa-toners', 'parent_id' => $parent->id]);
    // `source_term_id` so a permalinks row can name this category by the id the
    // old site knew it by, which is what makes the disagreement below possible.
    $child->forceFill(['path' => 'qa-skincare/qa-toners', 'depth' => 1, 'source_term_id' => 77001])->save();

    /*
     * Slugged `shop`, so its flat root address is one this storefront really
     * answers. That is not a contrivance: `LegacyCategoryUrls` records that
     * kbeautybliss.com served its categories flat at the root, and a flat
     * category address colliding with a route this shop serves is exactly the
     * collision the `still-answers` question exists for.
     */
    $collides = Category::query()->create(['name' => 'QA Shop', 'slug' => 'shop', 'parent_id' => $parent->id]);
    $collides->forceFill(['path' => 'qa-skincare/shop', 'depth' => 1])->save();

    // No computed path: the parent cycle CategoryImporter reports. Its real
    // address is unknown, so it is a question that cannot be answered yes.
    $orphan = Category::query()->create(['name' => 'QA Orphan', 'slug' => 'qa-orphan', 'parent_id' => null]);
    $orphan->forceFill(['path' => null, 'depth' => 0])->save();

    /*
     * ▲ A MERGED CATEGORY, ADDED SO `already-redirects` IS STILL REACHABLE.
     *
     * That question used to be produced by the nesting rule alone:
     * /product-category/qa-toners/ is 301'd by the shop on its own, so every
     * nesting proposal was one. Since Lane SEO round 2 the map compares the
     * shop's destination with its own and discards when they AGREE — and a
     * nesting proposal always agrees, because both are the category's canonical
     * path. That is the noise this round removed, and it took the fixture's
     * only source of this question with it.
     *
     * So the fixture now produces the disagreement the question is actually
     * for: `qa-merged` was merged into QA Skincare, which is a real edit that
     * writes a category_redirects row, while the permalink export in the test
     * below claims the same address for QA Toners. Two destinations, one
     * address, and only the owner can say which wins.
     */
    DB::table('category_redirects')->insert([
        'from_path' => 'qa-merged',
        'category_id' => $parent->id,
        'reason' => 'merge',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$parent, $child, $collides, $orphan];
}

function qaAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'QA Owner',
        'email' => 'qa-owner-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

function qaProposal(string $source, array $proposals): ?array
{
    foreach ($proposals as $proposal) {
        if ($proposal['source'] === $source) {
            return $proposal;
        }
    }

    return null;
}

/* ========================================================================== */
/*  THE QUESTIONS ARE NAMED, AND THE NAMES MEAN SOMETHING                      */
/* ========================================================================== */

it('gives every question it asks a code, and never asks one it has no heading for', function () {
    /*
     * The ask bucket used to be free text and nothing else, which is why it
     * could not be grouped and therefore could not be answered in bulk. Every
     * ASK row carries a code now, and a code with no heading would render as a
     * blank block on the screen with buttons under it.
     *
     * MUTATION: delete the `'question' => self::Q_STILL_ANSWERS` line from
     * RedirectMap::reachable() and the first expectation is red, naming the
     * address that lost its code.
     */
    qaTree();

    /*
     * The permalink row is what makes the merged address a DISAGREEMENT: the
     * shop sends /product-category/qa-merged/ to QA Skincare, and the export
     * says the address belonged to QA Toners.
     */
    $proposals = (new RedirectMap)->propose([
        ['type' => 'category', 'wc_id' => 77001, 'permalink' => 'https://old.test/product-category/qa-merged/'],
    ]);
    $asking = array_values(array_filter($proposals, fn ($p) => $p['decision'] === RedirectMap::ASK));

    expect(count($asking))->toBeGreaterThan(0, 'the fixture asks nothing, so the loop below asserts nothing');

    foreach ($asking as $proposal) {
        expect($proposal['question'])->toBeIn(array_keys(RedirectMap::QUESTIONS), $proposal['source'])
            /*
             * decidable() and the target must agree. A question offered as
             * answerable with nothing to send anybody to would write
             * `redirects.target = ''`, and one refused despite having a
             * destination is a row nobody can ever clear.
             */
            ->and(RedirectMap::decidable($proposal))
            ->toBe(RedirectMap::QUESTIONS[$proposal['question']]['decidable'] && $proposal['target'] !== '');
    }

    // Every row, not only the questions, carries the key — so nothing reading
    // the map has to tell "no question" from "an older shape".
    foreach ($proposals as $proposal) {
        expect(array_key_exists('question', $proposal))->toBeTrue()
            ->and(array_key_exists('answered', $proposal))->toBeTrue();
    }

    // And the three shapes the fixture was built to produce are all there.
    $codes = array_unique(array_column($asking, 'question'));

    expect($codes)->toContain(RedirectMap::Q_STILL_ANSWERS)
        ->and($codes)->toContain(RedirectMap::Q_ALREADY_REDIRECTS)
        ->and($codes)->toContain(RedirectMap::Q_NO_TARGET);
});

/* ========================================================================== */
/*  AN ANSWER MOVES THE ROW, AND THE WRITE HONOURS IT                          */
/* ========================================================================== */

it('turns an approved question into a redirect the write actually creates', function () {
    /*
     * THE WHOLE ITEM IN ONE TEST. Before this the row below was a question for
     * ever and the only way to act on it was to retype it on Store → Redirects.
     *
     * MUTATION: drop the `answered()` call from RedirectMap::propose() and the
     * approval is recorded and then ignored — the row is still ASK, the write
     * creates nothing, and this is red on the second expectation.
     */
    qaTree();
    UrlsMediaAdminRoutes::wire($this->app);

    $before = qaProposal('/shop/', (new RedirectMap)->propose());

    expect($before['decision'])->toBe(RedirectMap::ASK)
        ->and($before['question'])->toBe(RedirectMap::Q_STILL_ANSWERS)
        ->and($before['answered'])->toBe(RedirectDecisions::UNANSWERED);

    $this->actingAs(qaAdmin(), 'admin')
        ->postJson('/admin-api/urls-media/decisions', ['action' => 'accept', 'sources' => ['/shop/']])
        ->assertOk()
        ->assertJson(['ok' => true, 'recorded' => 1]);

    $after = qaProposal('/shop/', (new RedirectMap)->propose());

    expect($after['decision'])->toBe(RedirectMap::MIGRATE)
        ->and($after['answered'])->toBe(RedirectDecisions::ACCEPT)
        // The row still says what it was asking, because an approval that
        // erases the question is an approval nobody can review later.
        ->and(str_contains($after['reason'], 'still answers on this shop'))->toBeTrue()
        ->and($after['target'])->toBe($before['target']);

    $this->actingAs(qaAdmin(), 'admin')
        ->postJson('/admin-api/urls-media/redirects', ['action' => 'write'])
        ->assertOk();

    expect(Redirect::query()->where('source', '/shop/')->value('target'))->toBe($before['target']);
});

it('keeps a declined question out of everything the write creates', function () {
    /*
     * The other half, and the half that cannot be done by writing a row: an
     * address he has looked at and said no to has to STOP BEING ASKED, or the
     * list never shrinks and the hundredth read is as long as the first.
     *
     * MUTATION: make RedirectDecisions::apply() ignore REJECT and the row is
     * back in the ask bucket on the next load — the "no" does nothing at all.
     */
    qaTree();
    UrlsMediaAdminRoutes::wire($this->app);

    $this->actingAs(qaAdmin(), 'admin')
        ->postJson('/admin-api/urls-media/decisions', ['action' => 'reject', 'sources' => ['/shop/']])
        ->assertOk()
        ->assertJson(['recorded' => 1]);

    $after = qaProposal('/shop/', (new RedirectMap)->propose());

    expect($after['decision'])->toBe(RedirectMap::DISCARD)
        ->and($after['answered'])->toBe(RedirectDecisions::REJECT)
        ->and(str_contains($after['reason'], 'you declined this on'))->toBeTrue();

    $this->actingAs(qaAdmin(), 'admin')
        ->postJson('/admin-api/urls-media/redirects', ['action' => 'write'])
        ->assertOk();

    expect(Redirect::query()->where('source', '/shop/')->exists())->toBeFalse();
});

it('asks again when what an approval pointed at has moved', function () {
    /*
     * THE PROPERTY THE WHOLE CLASS IS BUILT AROUND. An answer is to a QUESTION,
     * and the question names a destination. Re-parent the category afterwards
     * and the map proposes a different sentence — one he has never seen.
     *
     * Keying the answer on the address alone would write a redirect he never
     * agreed to, silently, and the only way to notice would be to follow the
     * link.
     *
     * MUTATION: delete the `$stored !== $offered` branch in
     * RedirectDecisions::apply() and the row comes back MIGRATE pointing at the
     * NEW destination, with "you approved this" on it. This is red on the
     * decision and again on the word "stale".
     */
    [$parent, , $collides] = qaTree();
    UrlsMediaAdminRoutes::wire($this->app);

    $admin = qaAdmin();

    $this->actingAs($admin, 'admin')
        ->postJson('/admin-api/urls-media/decisions', ['action' => 'accept', 'sources' => ['/shop/']])
        ->assertOk();

    $approved = qaProposal('/shop/', (new RedirectMap)->propose());

    expect($approved['decision'])->toBe(RedirectMap::MIGRATE);

    // Somebody moves the category. Its address changes; his answer does not.
    $second = Category::query()->create(['name' => 'QA Bath', 'slug' => 'qa-bath', 'parent_id' => null]);
    $second->forceFill(['path' => 'qa-bath', 'depth' => 0])->save();
    $collides->forceFill(['parent_id' => $second->id, 'path' => 'qa-bath/shop'])->save();

    $stale = qaProposal('/shop/', (new RedirectMap)->propose());

    expect($stale['decision'])->toBe(RedirectMap::ASK)
        ->and($stale['answered'])->toBe(RedirectDecisions::STALE)
        ->and($stale['target'])->toBe('/product-category/qa-bath/shop/')
        ->and(str_contains($stale['reason'], $approved['target']))->toBeTrue()
        ->and(str_contains($stale['reason'], 'something moved after you answered'))->toBeTrue();

    // And the write does not create it, which is the consequence that matters.
    $this->actingAs($admin, 'admin')
        ->postJson('/admin-api/urls-media/redirects', ['action' => 'write'])
        ->assertOk();

    expect(Redirect::query()->where('source', '/shop/')->exists())->toBeFalse();

    // Unused, but named so the fixture reads as what it is.
    expect($parent->slug)->toBe('qa-skincare');
});

/* ========================================================================== */
/*  WHAT IT REFUSES                                                            */
/* ========================================================================== */

it('refuses to accept a question with nowhere on this shop to send anybody', function () {
    /*
     * A category stranded in a parent cycle has no computed path, so the map
     * has no address to offer. Accepting it would write `redirects.target = ''`
     * — a 301 to nowhere, which is worse than the 404 it replaces.
     *
     * The screen does not draw a Yes button for these. This asserts the SERVER
     * refuses anyway, because a screen is not a guard.
     *
     * MUTATION: delete the `RedirectMap::decidable()` check in
     * RedirectDecisions::record() and the answer is stored, the row goes
     * MIGRATE, and the write creates a redirect whose target is the empty
     * string.
     */
    qaTree();
    UrlsMediaAdminRoutes::wire($this->app);

    $orphan = qaProposal('/product-category/qa-orphan/', (new RedirectMap)->propose());

    expect($orphan['decision'])->toBe(RedirectMap::ASK)
        ->and($orphan['question'])->toBe(RedirectMap::Q_NO_TARGET)
        ->and($orphan['target'])->toBe('')
        ->and(RedirectMap::decidable($orphan))->toBeFalse();

    $body = $this->actingAs(qaAdmin(), 'admin')
        ->postJson('/admin-api/urls-media/decisions', [
            'action' => 'accept',
            'sources' => ['/product-category/qa-orphan/'],
        ])
        ->assertOk()
        ->json();

    expect($body['recorded'])->toBe(0)
        ->and(RedirectDecision::query()->count())->toBe(0)
        ->and(str_contains($body['refused'][0] ?? '', 'no address on this shop to send it to'))->toBeTrue();

    // Declining it IS allowed — "this address should 404 on purpose" is a real
    // answer, and the only one available here.
    $this->actingAs(qaAdmin(), 'admin')
        ->postJson('/admin-api/urls-media/decisions', [
            'action' => 'reject',
            'sources' => ['/product-category/qa-orphan/'],
        ])
        ->assertOk()
        ->assertJson(['recorded' => 1]);
});

it('takes the destination from the map and never from the request', function () {
    /*
     * WITHOUT THIS, "approve" IS A BUTTON THAT POINTS THIS SHOP'S INDEXED
     * ADDRESSES ANYWHERE. `/admin-api/*` is behind auth:admin and the
     * `data.import` capability, which is not a reason to accept a destination
     * from a body: the endpoint's own contract is "approve what this map
     * proposes", and an endpoint that will write whatever it is handed is a
     * different endpoint wearing that label.
     *
     * MUTATION: read `target` from the request in
     * RedirectDecisions::record() and the stored answer — and then the written
     * redirect — points at /HIJACKED/.
     */
    qaTree();
    UrlsMediaAdminRoutes::wire($this->app);

    $proposed = qaProposal('/shop/', (new RedirectMap)->propose())['target'];

    $this->actingAs(qaAdmin(), 'admin')
        ->postJson('/admin-api/urls-media/decisions', [
            'action' => 'accept',
            'sources' => ['/shop/'],
            'target' => '/HIJACKED/',
            'targets' => ['/shop/' => 'https://evil.test/'],
        ])
        ->assertOk()
        ->assertJson(['recorded' => 1]);

    expect(RedirectDecision::query()->where('source', '/shop/')->value('target'))->toBe($proposed);

    $this->actingAs(qaAdmin(), 'admin')
        ->postJson('/admin-api/urls-media/redirects', ['action' => 'write'])
        ->assertOk();

    expect(Redirect::query()->where('source', '/shop/')->value('target'))->toBe($proposed);
});

it('refuses an address this map proposes nothing for, by name', function () {
    // Not a security hole on its own — nothing is written for it — but a silent
    // no-op would let the screen report "answered" for a row that was never
    // touched, which is the worst kind of confirmation.
    qaTree();
    UrlsMediaAdminRoutes::wire($this->app);

    $body = $this->actingAs(qaAdmin(), 'admin')
        ->postJson('/admin-api/urls-media/decisions', [
            'action' => 'accept',
            'sources' => ['/not-a-thing-this-map-knows/'],
        ])
        ->assertOk()
        ->json();

    expect($body['recorded'])->toBe(0)
        ->and(RedirectDecision::query()->count())->toBe(0)
        ->and(str_contains($body['refused'][0] ?? '', 'does not propose anything for that address'))->toBeTrue();
});

it('will not answer without being told what to answer', function () {
    // "Accept everything" is not offered, because the questions are not all the
    // same question and one button over all of them is the opposite of asking.
    qaTree();
    UrlsMediaAdminRoutes::wire($this->app);

    $this->actingAs(qaAdmin(), 'admin')
        ->postJson('/admin-api/urls-media/decisions', ['action' => 'accept'])
        ->assertStatus(422);

    // And an invented question code is refused by validation rather than
    // silently matching nothing — the failure mode CLAUDE.md records for the
    // product `status` filter.
    $this->actingAs(qaAdmin(), 'admin')
        ->postJson('/admin-api/urls-media/decisions', ['action' => 'accept', 'question' => 'made-up'])
        ->assertStatus(422);

    expect(RedirectDecision::query()->count())->toBe(0);
});

it('refuses an anonymous caller', function () {
    UrlsMediaAdminRoutes::wire($this->app);

    // json, because auth:admin answers a browser navigation with a 302 to the
    // login form and only an API-shaped request with a 401.
    $this->postJson('/admin-api/urls-media/decisions', ['action' => 'accept', 'sources' => ['/shop/']])
        ->assertStatus(401);

    expect(RedirectDecision::query()->count())->toBe(0);
});

/* ========================================================================== */
/*  IN BULK, AND UNDOABLE                                                      */
/* ========================================================================== */

it('answers a whole question at once, in a bounded number of queries', function () {
    /*
     * The point of grouping. A question is asked hundreds of times on a real
     * export and the answer is ONE answer — so "yes to all" has to be one
     * request and a bounded number of queries, not two per row inside a
     * transaction on a shared host.
     *
     * Counted against `redirect_decisions` ALONE, not against the request. The
     * map derives itself on the way in and that walk is a different cost with a
     * different owner; mixing them would give a number that moves whenever
     * somebody else touches the map and says nothing about this write.
     *
     * TWO statements is the whole budget: one `select` for the upsert's
     * conflict handling and one `insert … on conflict`, both from
     * RedirectDecisions::record()'s chunked upsert.
     *
     * MUTATION: replace the chunked upsert() with updateOrCreate() in a loop
     * and this is 26 statements against that one table.
     */
    qaTree();

    // More rows under one question, so a per-row write would be visible.
    foreach (range(1, 12) as $i) {
        $c = Category::query()->create([
            'name' => 'QA Bulk '.$i, 'slug' => 'qa-bulk-'.$i, 'parent_id' => null,
        ]);
        $c->forceFill(['path' => null, 'depth' => 0])->save();
    }

    UrlsMediaAdminRoutes::wire($this->app);

    $asking = array_values(array_filter(
        (new RedirectMap)->propose(),
        fn ($p) => $p['decision'] === RedirectMap::ASK && $p['question'] === RedirectMap::Q_NO_TARGET,
    ));

    expect(count($asking))->toBe(13);

    $queries = [];
    DB::listen(function ($q) use (&$queries): void {
        if (str_contains((string) $q->sql, 'redirect_decisions')) {
            $queries[] = (string) $q->sql;
        }
    });

    $body = $this->actingAs(qaAdmin(), 'admin')
        ->postJson('/admin-api/urls-media/decisions', [
            'action' => 'reject',
            'question' => RedirectMap::Q_NO_TARGET,
        ])
        ->assertOk()
        ->json();

    expect($body['recorded'])->toBe(13)
        ->and(RedirectDecision::query()->count())->toBe(13)
        // 13 answers written. A loop of updateOrCreate() would be 26 statements
        // against this table; the chunked upsert is a handful.
        ->and(count($queries))->toBeLessThanOrEqual(
            4,
            'writing 13 answers cost '.count($queries).' statements against redirect_decisions: '
                .implode(' | ', $queries)
        );

    foreach ((new RedirectMap)->propose() as $proposal) {
        if (($proposal['question'] ?? '') === RedirectMap::Q_NO_TARGET) {
            expect($proposal['decision'])->toBe(RedirectMap::DISCARD)
                ->and($proposal['answered'])->toBe(RedirectDecisions::REJECT);
        }
    }
});

it('undoes a question\'s answers and puts the rows back', function () {
    /*
     * Why an approval is STORED rather than written straight into `redirects`
     * and forgotten: an approval that left no trace could only be undone by
     * guessing which rows in `redirects` this screen put there.
     */
    qaTree();
    UrlsMediaAdminRoutes::wire($this->app);

    $admin = qaAdmin();

    $this->actingAs($admin, 'admin')
        ->postJson('/admin-api/urls-media/decisions', [
            'action' => 'accept',
            'question' => RedirectMap::Q_STILL_ANSWERS,
        ])
        ->assertOk();

    expect(RedirectDecision::query()->where('question', RedirectMap::Q_STILL_ANSWERS)->count())
        ->toBeGreaterThan(0)
        ->and(qaProposal('/shop/', (new RedirectMap)->propose())['decision'])->toBe(RedirectMap::MIGRATE);

    $this->actingAs($admin, 'admin')
        ->postJson('/admin-api/urls-media/decisions', [
            'action' => 'clear',
            'question' => RedirectMap::Q_STILL_ANSWERS,
        ])
        ->assertOk()
        ->assertJson(['ok' => true]);

    $back = qaProposal('/shop/', (new RedirectMap)->propose());

    expect($back['decision'])->toBe(RedirectMap::ASK)
        ->and($back['answered'])->toBe(RedirectDecisions::UNANSWERED)
        ->and(RedirectDecision::query()->count())->toBe(0);
});

/* ========================================================================== */
/*  THE SCREEN                                                                 */
/* ========================================================================== */

it('groups the ask bucket into the questions it is actually asking', function () {
    /*
     * Store → Import → Addresses & pictures → Old addresses · What this needs
     * you to decide.
     *
     * A few hundred rows are a handful of questions asked a few hundred times.
     * Grouped, each one gets a heading and two buttons; ungrouped it is a list
     * nobody finishes, which docs/FV-IMPORT-AT-VOLUME.md §10 says is the same
     * failure as not asking at all.
     *
     * MUTATION: have questions() key on `reason` instead of `question` and
     * every row becomes its own group, so `asking` is 1 everywhere and the
     * count assertion below is red.
     */
    qaTree();
    UrlsMediaAdminRoutes::wire($this->app);

    $admin = qaAdmin();

    $body = $this->actingAs($admin, 'admin')
        ->getJson('/admin-api/urls-media/status')
        ->assertOk()
        ->json();

    $groups = collect($body['urls']['questions'])->keyBy('question');

    expect($groups->has(RedirectMap::Q_STILL_ANSWERS))->toBeTrue()
        ->and($groups->has(RedirectMap::Q_NO_TARGET))->toBeTrue()
        ->and($groups[RedirectMap::Q_STILL_ANSWERS]['decidable'])->toBeTrue()
        ->and($groups[RedirectMap::Q_NO_TARGET]['decidable'])->toBeFalse()
        // `/shop/` and `/shop` — U-01's slash and the one somebody pasted
        // without it — are one question asked twice.
        ->and($groups[RedirectMap::Q_STILL_ANSWERS]['asking'])->toBe(2)
        ->and($groups[RedirectMap::Q_STILL_ANSWERS]['rows'][0]['source'])->toStartWith('/shop');

    // The counts add up to the bucket, so the screen cannot show a question
    // list that is quietly shorter than the number on the tile.
    $asking = collect($body['urls']['questions'])->sum('asking');

    expect($asking)->toBe($body['urls']['buckets'][RedirectMap::ASK]['count'])
        ->and($body['urls']['answers'])->toBe(['accepted' => 0, 'rejected' => 0, 'stale' => 0, 'asking' => $asking]);

    // And an answer is visible on the next read, per question.
    $this->actingAs($admin, 'admin')
        ->postJson('/admin-api/urls-media/decisions', ['action' => 'accept', 'sources' => ['/shop/']])
        ->assertOk();

    $after = collect(
        $this->actingAs($admin, 'admin')->getJson('/admin-api/urls-media/status')->json('urls.questions')
    )->keyBy('question');

    expect($after[RedirectMap::Q_STILL_ANSWERS]['accepted'])->toBe(1)
        ->and($after[RedirectMap::Q_STILL_ANSWERS]['asking'])->toBe(1);
});

it('puts the question and the answer in the spreadsheet the owner approves from', function () {
    /*
     * The CSV is how approval actually happens away from the screen — Phase 13
     * says the buckets are approved before anything is applied, and a
     * spreadsheet he can open beside the export is the form that takes.
     *
     * The two columns are APPENDED, so a sheet somebody already has open
     * against the old shape still reads every column it knew about at the
     * position it knew it at.
     */
    qaTree();
    UrlsMediaAdminRoutes::wire($this->app);

    $admin = qaAdmin();

    $this->actingAs($admin, 'admin')
        ->postJson('/admin-api/urls-media/decisions', ['action' => 'reject', 'sources' => ['/shop/']])
        ->assertOk();

    $csv = $this->actingAs($admin, 'admin')
        ->get('/admin-api/urls-media/map.csv')
        ->assertOk()
        ->getContent();

    $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
    $header = array_shift($rows);

    expect($header)->toBe([
        'decision', 'subject', 'old address', 'new address', 'rule', 'why', 'question', 'your answer',
    ]);

    $answered = array_values(array_filter($rows, fn ($r) => $r[2] === '/shop/'));

    expect($answered)->toHaveCount(1)
        ->and($answered[0][0])->toBe(RedirectMap::DISCARD)
        ->and($answered[0][6])->toBe(RedirectMap::Q_STILL_ANSWERS)
        ->and($answered[0][7])->toBe(RedirectDecisions::REJECT);
});

/* ========================================================================== */
/*  THE DESTINATION IS CHECKED ON EVERY PROPOSAL, NOT ONLY THE MIGRATING ONES  */
/* ========================================================================== */

it('asks about a dead destination even when the shop already answers the old address', function () {
    /*
     * WHAT THE DEFECT LOOKED LIKE, and it is one this lane's own round-2 work
     * created the exposure for.
     *
     * `RedirectMap::reachable()` checked the TARGET only after the MOVED,
     * SERVED and UNKNOWN branches had fallen through — so a proposal routed to
     * ASK by one of those three never had its destination checked at all. That
     * was survivable while an ASK row was a dead end: nobody could act on it.
     *
     * It stopped being survivable the moment `RedirectDecisions` let the owner
     * APPROVE one. "Yes to all 312" over the `still-answers` question would
     * write a row pointing at an address that 404s — a 301 to a 404, which is
     * worse than the 404 it replaces because it tells a search engine the
     * address was replaced by nothing — with NOTHING on the screen saying so,
     * because the row was filed under a heading about something else entirely.
     *
     * So the destination is checked FIRST and a dead one wins the question. It
     * has to be the heading he reads, not a sentence buried under a different
     * one, because it is the reason not to approve.
     *
     * MUTATION: move the target check back below the three verdict branches in
     * reachable() and this row comes back as Q_STILL_ANSWERS — approvable, in
     * the same bulk block as the sound ones, with its dead destination
     * mentioned nowhere.
     */
    [$parent, , $collides] = qaTree();
    UrlsMediaAdminRoutes::wire($this->app);

    /*
     * `/shop/` is an address this storefront answers — that is what makes it a
     * `still-answers` question. Its destination is the nested archive of the
     * category slugged `shop`; delete that category's computed path out from
     * under it and the destination stops existing while the source goes on
     * answering, which is exactly the combination the old order could not see.
     */
    $before = qaProposal('/shop/', (new RedirectMap)->propose());

    expect($before['question'])->toBe(RedirectMap::Q_STILL_ANSWERS)
        ->and($before['target'])->toBe('/product-category/qa-skincare/shop/');

    $collides->forceFill(['path' => 'qa-skincare/gone-away'])->save();

    $after = qaProposal('/shop/', (new RedirectMap)->propose());

    expect($after['decision'])->toBe(RedirectMap::ASK)
        ->and($after['question'])->toBe(RedirectMap::Q_TARGET_MISSING)
        ->and(str_contains($after['reason'], 'does not exist on this shop'))->toBeTrue()
        ->and(str_contains($after['reason'], 'A 301 to a 404 is worse than the 404 it replaces'))->toBeTrue();

    /*
     * AND THE SCREEN FILES IT UNDER THAT HEADING, which is the half that
     * actually protects him: a bulk "yes" on `still-answers` cannot reach it,
     * because it is no longer in that group.
     */
    $groups = collect(
        $this->actingAs(qaAdmin(), 'admin')->getJson('/admin-api/urls-media/status')->json('urls.questions')
    )->keyBy('question');

    $stillAnswers = $groups[RedirectMap::Q_STILL_ANSWERS] ?? ['rows' => []];

    expect(array_column($stillAnswers['rows'], 'source'))->toBe([])
        ->and(array_column($groups[RedirectMap::Q_TARGET_MISSING]['rows'], 'source'))->toContain('/shop/');

    expect($parent->slug)->toBe('qa-skincare');
});

it('does not mistake a question with no destination for one whose destination is gone', function () {
    /*
     * `SourceReachability::verdict('')` answers UNKNOWN — "this is not a
     * root-relative path" — and not NOT_FOUND, so an empty target would not be
     * caught by the check above by accident. The guard is explicit anyway,
     * because relying on that would be relying on another class's answer to a
     * question nobody asked it.
     *
     * The three questions that carry no destination keep their own headings;
     * they are "fix something else first", not "your destination died".
     *
     * MUTATION: drop the `trim($proposal['target']) !== ''` guard in
     * reachable() and this still passes today — which is the point of saying so
     * here rather than trusting it.
     */
    qaTree();

    $orphan = qaProposal('/product-category/qa-orphan/', (new RedirectMap)->propose());

    expect($orphan['target'])->toBe('')
        ->and($orphan['question'])->toBe(RedirectMap::Q_NO_TARGET)
        ->and(RedirectMap::decidable($orphan))->toBeFalse();
});
