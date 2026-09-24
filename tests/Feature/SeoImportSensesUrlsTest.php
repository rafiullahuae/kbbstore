<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Post;
use App\Models\Product;
use App\Models\Redirect;
use App\Services\Import\RedirectMap;
use App\Services\Import\SourceReachability;
use App\Support\LegacyCategoryUrls;
use Illuminate\Support\Facades\DB;

/**
 * =============================================================================
 * THE IMPORTER PROPOSED THIRTY ROWS THE SHOP ALREADY DID FOR ITSELF
 * =============================================================================
 *
 * Lane SEO round 1 taught `CheckRedirects` to DERIVE a 301 for the fifteen old
 * flat category addresses, from the global pipeline, before the router.
 * `SourceReachability` asks the router and the tables and knew nothing about
 * it, so every one of those addresses still came back:
 *
 *     verdict('/toners/') → notfound, "PageController::post() throws a 404 for
 *                            a slug with no published post, so the redirect is
 *                            reached"
 *
 * Every word of that was true when it was written, and the address it describes
 * answers 301. `reachable()` read `notfound` as "nothing serves this, a row
 * here is pure gain".
 *
 * MEASURED on a tree with all fifteen categories imported, before this branch:
 *
 *     buckets: migrate 38, ask 11, discard 8
 *
 * and thirty of those thirty-eight were rows restating a redirect the
 * application already makes. After:
 *
 *     buckets: migrate 8, ask 0, discard 49
 *
 * ── WHY A RESTATED ROW IS NOT MERELY REDUNDANT ──────────────────────────────
 *
 * This is the part that makes it a defect rather than untidiness, and it has a
 * test of its own below. The shop's own answer is DERIVED, so it follows the
 * category when the owner re-parents or renames it. A written row does not: it
 * goes on pointing at the path the category had on import day, and once the
 * category moves, that path is a 404. So of the two, the row is the only one
 * that can rot — and it would be the one that wins, because the table is
 * consulted before the router.
 *
 * ── AND THE OTHER HALF: THE ASK BUCKET HAD TO STOP LYING ────────────────────
 *
 * `currentPathFor()` answers for products, categories, posts, pages and brands
 * and returns null for everything else — and everything else got "nothing in
 * this shop carries {type} id {id} — either it was never imported, or it is in
 * the discard bucket". For a `product_tag` that is false: the term was very
 * probably imported, and what this shop lacks is a tag ARCHIVE, by design.
 * The exporter writes one permalinks row per term of every `pa_*` taxonomy and
 * every `product_tag`, so on a six-year-old shop that is hundreds of rows each
 * carrying a false reason.
 */

function r2Admin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Round 2 Owner',
        'email' => 'seo-r2-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

function r2Cat(string $slug, string $name, ?int $parentId, string $path, int $depth, ?int $termId = null): Category
{
    $c = Category::query()->firstOrNew(['slug' => $slug]);
    $c->fill(['name' => $name, 'parent_id' => $parentId]);
    $c->forceFill(['path' => $path, 'depth' => $depth] + ($termId === null ? [] : ['source_term_id' => $termId]))->save();

    return $c;
}

/** The real tree the import builds: all fifteen, nested as the live shop has them. */
function r2Tree(): void
{
    $sk = r2Cat('skincare', 'Skincare', null, 'skincare', 0, 9001);
    $fc = r2Cat('face-cleansers', 'Face Cleansers', $sk->id, 'skincare/face-cleansers', 1, 9002);
    r2Cat('cleansing-oils', 'Cleansing Oils', $fc->id, 'skincare/face-cleansers/cleansing-oils', 2, 9003);
    r2Cat('face-washes', 'Face Washes', $fc->id, 'skincare/face-cleansers/face-washes', 2, 9004);

    $n = 9010;
    foreach ([
        ['exfoliators', 'Exfoliators'], ['toners', 'Toners'], ['face-serums', 'Face Serums'],
        ['eye-care', 'Eye Care'], ['face-masks', 'Face Masks'], ['moisturizers', 'Moisturizers'],
        ['lip-care', 'Lip Care'], ['sunscreens', 'Sunscreens'],
    ] as [$s, $name]) {
        r2Cat($s, $name, $sk->id, 'skincare/'.$s, 1, $n++);
    }

    foreach ([['hair-care', 'Hair Care'], ['skincare-sets', 'Skincare Sets'], ['beauty-devices', 'Beauty Devices']] as [$s, $name]) {
        r2Cat($s, $name, null, $s, 0, $n++);
    }
}

/** @return array<string,int> */
function r2Buckets(array $proposals): array
{
    $out = ['migrate' => 0, 'ask' => 0, 'discard' => 0];

    foreach ($proposals as $p) {
        $out[$p['decision']]++;
    }

    return $out;
}

function r2Find(array $proposals, string $source): ?array
{
    foreach ($proposals as $p) {
        if ($p['source'] === $source) {
            return $p;
        }
    }

    return null;
}

// ---------------------------------------------------------------------------
// 1. The verdict stopped lying
// ---------------------------------------------------------------------------

it('knows the shop forwards a legacy flat address by itself', function () {
    /*
     * RED WITHOUT THE FIX: every one of these measured `notfound`, with the
     * reason "PageController::post() throws a 404 for a slug with no published
     * post" — about an address that answers 301.
     *
     * MUTATION NOTE, RUN: deleting the landingPath() branch from
     * SourceReachability::verdict() puts all four back to notfound -- 5 failed
     * across the file.
     */
    r2Tree();

    $sr = new SourceReachability;

    foreach ([
        '/toners/' => '/product-category/skincare/toners/',
        '/cleansing-oils/' => '/product-category/skincare/face-cleansers/cleansing-oils/',
        '/skincare-sets/' => '/product-category/skincare-sets/',
        '/hair-care/' => '/product-category/hair-care/',
    ] as $path => $to) {
        $v = $sr->verdict($path);

        expect($v['status'])->toBe(SourceReachability::MOVED, $path.' is still read as a 404');
        expect($v['to'] ?? null)->toBe($to, $path.' names the wrong destination');
    }
});

it('reads the derived rule and not the redirects table, so two identical imports agree', function () {
    /*
     * WHY THIS MATTERS ENOUGH TO PIN. `CheckRedirects::lookup()` would have
     * been the obvious call and it consults the `redirects` TABLE first — the
     * very table this map exists to decide what to write into. The map's answer
     * would then depend on its own previous run: import once, a row is written,
     * import again and the address is now "moved" for a different reason. Two
     * runs over identical data would print different sentences on the screen
     * the owner approves rows from.
     *
     * MUTATION NOTE, RUN: pointing verdict() at CheckRedirects::lookup()
     * instead of LegacyCategoryUrls::landingPath() makes this red -- the
     * verdict changes once the row exists -- 1 failed.
     */
    r2Tree();

    $sr = new SourceReachability;
    $before = $sr->verdict('/beauty-devices/');

    Redirect::query()->create([
        'source' => '/beauty-devices/', 'target' => '/shop/', 'code' => 301,
        'enabled' => true, 'auto_created' => true,
    ]);

    expect($sr->verdict('/beauty-devices/'))->toBe($before, 'the verdict moved because a row was written');
});

// ---------------------------------------------------------------------------
// 2. reachable(): the GP §13.7 decision, settled by comparing destinations
// ---------------------------------------------------------------------------

it('discards a proposal the shop already satisfies, and keeps the count honest', function () {
    /*
     * docs/GP-ADDRESSES-LAND.md §13.7 left this open: "some of those discards
     * are still right, and some are now wrong." Neither blanket answer is
     * right. MOVED is two situations wearing one word, and the destination
     * tells them apart.
     *
     * MUTATION NOTE, RUN: removing the `$already === $proposal['target']`
     * branch from reachable() takes the buckets back to migrate 38 / ask 11 --
     * 4 failed across the file.
     */
    r2Tree();

    $proposals = (new RedirectMap)->propose();
    $buckets = r2Buckets($proposals);

    // The fifteen legacy root addresses, both spellings, are all gone from the
    // write list -- the shop does them already.
    foreach (LegacyCategoryUrls::PATHS as $path) {
        foreach ([$path, rtrim($path, '/')] as $spelling) {
            $row = r2Find($proposals, $spelling);

            if ($row === null) {
                continue;
            }

            expect($row['decision'])->toBe(
                RedirectMap::DISCARD,
                $spelling.' is still proposed as a row, and the shop already forwards it'
            );
        }
    }

    // And the nesting rule's own rows, which are the same redirect said twice.
    expect(r2Find($proposals, '/product-category/toners/')['decision'])->toBe(RedirectMap::DISCARD);

    expect($buckets['ask'])->toBe(0, 'the owner is being asked questions about addresses that already work');
    expect($buckets['migrate'])->toBeLessThan(15, 'the map is still proposing the rows the shop derives');
});

it('still asks when the shop sends the address somewhere else', function () {
    /*
     * The half that must NOT be discarded. A row here overrides the
     * application's own hop, which is a real decision with a real cost, and
     * Phase 13 says who makes it.
     *
     * ── THE FIXTURE IS A REAL MIGRATION, NOT A STUB ─────────────────────────
     *
     * `SourceReachability` is `final`, so the "injectable only so a test can
     * pin the reachability verdicts" note on RedirectMap's constructor cannot
     * actually be used — it can only be handed another real instance. That is
     * reported rather than worked around, and it turned out for the better,
     * because this disagreement is one a real shop produces:
     *
     *   The old site published Toners at /product-category/old-toners/.
     *   Since the import the owner has MERGED a category called `old-toners`
     *   into Hair Care, which writes a `category_redirects` row.
     *
     * So the shop, asked for /product-category/old-toners/, sends it to Hair
     * Care — CategoryPath::resolve() follows that row. The permalinks file
     * says the address belonged to the term that is now Toners. Two different
     * destinations for one address, from two sources that are both right about
     * what they know, and only the owner can say which wins.
     *
     * MUTATION NOTE, RUN: making the MOVED branch discard unconditionally
     * rather than only on an identical destination makes this red -- the
     * question disappears and the merge silently wins -- 1 failed.
     */
    r2Tree();

    $hairCare = Category::query()->where('slug', 'hair-care')->first();

    DB::table('category_redirects')->insert([
        'from_path' => 'old-toners',
        'category_id' => $hairCare->id,
        'reason' => 'merge',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // The shop's own answer, established before the map is asked.
    $sr = new SourceReachability;
    $v = $sr->verdict('/product-category/old-toners/');

    expect($v['status'])->toBe(SourceReachability::MOVED);
    expect($v['to'])->toBe('/product-category/hair-care/', 'the merge row is not being followed, so this proves nothing');

    // And the map, reading the export, wants to send it to Toners instead.
    $proposals = (new RedirectMap)->propose([
        ['type' => 'category', 'wc_id' => 9011, 'permalink' => 'https://old.test/product-category/old-toners/'],
    ]);

    $row = r2Find($proposals, '/product-category/old-toners/');

    expect($row)->not->toBeNull();
    expect($row['target'])->toBe('/product-category/skincare/toners/');
    expect($row['decision'])->toBe(RedirectMap::ASK, 'a real disagreement was decided for the owner');
    expect($row['question'])->toBe(RedirectMap::Q_ALREADY_REDIRECTS);
    expect($row['reason'])->toContain('NOT to where this rule would send it');
});

it('discards the same shape once the two destinations agree', function () {
    /*
     * The same branch from the other side, so neither test can be satisfied by
     * a rule that ignores the destination altogether. Identical fixture, except
     * that the merge points at the category the export names — which is the
     * ordinary case, and needs no question.
     */
    r2Tree();

    $toners = Category::query()->where('slug', 'toners')->first();

    DB::table('category_redirects')->insert([
        'from_path' => 'old-toners',
        'category_id' => $toners->id,
        'reason' => 'merge',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $proposals = (new RedirectMap)->propose([
        ['type' => 'category', 'wc_id' => 9011, 'permalink' => 'https://old.test/product-category/old-toners/'],
    ]);

    $row = r2Find($proposals, '/product-category/old-toners/');

    expect($row['decision'])->toBe(RedirectMap::DISCARD);
    expect($row['reason'])->toContain('already sends this address to exactly this destination');
});

it('says out loud that a written row would rot where the derived one does not', function () {
    /*
     * THE ARGUMENT FOR THE DISCARD, ASSERTED RATHER THAN CLAIMED, because it is
     * the whole reason this is a defect and not untidiness.
     *
     * A row written on import day points at the path the category had that day.
     * Re-parent the category and the shop's derived answer follows it; the row
     * does not — and the row WINS, because the table is read before the router.
     * The old destination is then a 404, so the row is a 301 onto a not-found
     * page: rule 2, broken by a row the import wrote for the owner.
     *
     * MUTATION NOTE, RUN: this fixture is the proof. Write the row, move the
     * category, and the row's target is left naming a path nothing serves while
     * landingPath() has already moved on.
     */
    r2Tree();

    $wasAt = LegacyCategoryUrls::landingPath('/toners/');
    expect($wasAt)->toBe('/product-category/skincare/toners/');

    // The row the import would have written before this branch.
    $row = Redirect::query()->create([
        'source' => '/toners/', 'target' => $wasAt, 'code' => 301,
        'enabled' => true, 'auto_created' => true,
    ]);

    /*
     * The owner renames the PARENT, which is an ordinary edit on
     * Catalogue → Categories and the commonest one in a migration: the imported
     * tree gets tidied up.
     */
    $skincare = Category::query()->where('slug', 'skincare')->first();
    $skincare->slug = 'skin-care';
    $skincare->forceFill(['path' => 'skin-care'])->save();

    $toners = Category::query()->where('slug', 'toners')->first();
    $toners->forceFill(['path' => 'skin-care/toners'])->save();

    // The shop's own answer moved with it, on its own, with nothing applied.
    expect(LegacyCategoryUrls::landingPath('/toners/'))->toBe('/product-category/skin-care/toners/');

    // The row did not — and the row is the one that wins, because the table is
    // read before the router.
    expect((string) $row->fresh()->target)->toBe('/product-category/skincare/toners/');

    /*
     * And its destination now costs a SECOND hop: CategoryPath::resolve() finds
     * the leaf and 301s it on to the real path. So the row turned a one-hop
     * redirect into a two-hop chain — rule 3 — on every one of the fifteen, and
     * nothing on any screen says so.
     */
    $sr = new SourceReachability;
    $stale = $sr->verdict('/product-category/skincare/toners/');

    expect($stale['status'])->toBe(
        SourceReachability::MOVED,
        'the stale row still points straight at a live page, so this proves nothing'
    );
    expect($stale['to'])->toBe('/product-category/skin-care/toners/');

    /*
     * The worse case, and it is one edit further: the LEAF is renamed too, so
     * nothing answers to the slug at all and the stale row is a 301 onto a 404.
     */
    $toners->slug = 'toner';
    $toners->forceFill(['path' => 'skin-care/toner'])->save();

    expect($sr->verdict('/product-category/skincare/toners/')['status'])
        ->toBe(SourceReachability::NOT_FOUND, 'a row pointing at a renamed leaf still resolves');
});

// ---------------------------------------------------------------------------
// 3. The ask bucket stopped lying about tags and attributes
// ---------------------------------------------------------------------------

it('tells the truth about an archive kind this shop does not have', function () {
    /*
     * THE OLD SENTENCE, ON A TAG THAT WAS IMPORTED: "nothing in this shop
     * carries product_tag id 47 — either it was never imported, or it is in the
     * discard bucket and this address should 404 on purpose." False, and false
     * at volume: one row per term of every pa_* taxonomy and every product_tag.
     *
     * The export settles whether the address existed at all — a taxonomy with
     * no public archive produces an EMPTY permalink and never reaches this
     * branch — so a row arriving here with a real URL is the old site stating
     * that it served the address.
     *
     * MUTATION NOTE, RUN: making hasNoArchiveHere() return false always puts
     * both rows back on Q_NOT_IMPORTED with the false reason -- 2 failed.
     */
    r2Tree();

    $proposals = (new RedirectMap)->propose([
        ['type' => 'product_tag', 'wc_id' => 47, 'permalink' => 'https://old.test/product-tag/hydrating/'],
        ['type' => 'pa_size', 'wc_id' => 48, 'permalink' => 'https://old.test/pa_size/50ml/'],
        ['type' => 'product', 'wc_id' => 999999, 'permalink' => 'https://old.test/product/never-imported/'],
    ]);

    $tag = r2Find($proposals, '/product-tag/hydrating/');
    expect($tag['question'])->toBe(RedirectMap::Q_NO_EQUIVALENT);
    expect($tag['reason'])->toContain('this shop has no product_tag archive at all');
    expect($tag['reason'])->not->toContain('never imported');

    $attr = r2Find($proposals, '/pa_size/50ml/');
    expect($attr['question'])->toBe(RedirectMap::Q_NO_EQUIVALENT);

    // And the question that IS about an import stays exactly as it was.
    $product = r2Find($proposals, '/product/never-imported/');
    expect($product['question'])->toBe(RedirectMap::Q_NOT_IMPORTED);
    expect($product['reason'])->toContain('never imported');
});

it('proposes nothing at all for a taxonomy the old site published no archive for', function () {
    /*
     * The exporter writes a row per brand term with an EMPTY permalink and a
     * note saying WordPress returned no archive URL for that taxonomy. That is
     * the old site answering the question, and the answer is "no address of
     * this kind was ever served" — so there is nothing to land and nothing to
     * ask about. Pinned because the alternative, inventing `/brand/{slug}/`,
     * is 93 rows from an address that may never have existed.
     */
    r2Tree();

    $proposals = (new RedirectMap)->propose([
        ['type' => 'brand', 'wc_id' => 77, 'permalink' => '', 'note' => 'WordPress returned no archive URL'],
    ]);

    foreach ($proposals as $p) {
        expect($p['subject'] ?? '')->not->toContain('brand 77');
    }
});

it('offers no accept button on a question with nowhere to send the address', function () {
    // decidable() is what the screen reads. A question with no destination
    // cannot be accepted: accepting would write target = '' and send a visitor
    // to nowhere.
    expect(RedirectMap::QUESTIONS[RedirectMap::Q_NO_EQUIVALENT]['decidable'])->toBeFalse();
    expect(RedirectMap::decidable(['question' => RedirectMap::Q_NO_EQUIVALENT, 'target' => '']))->toBeFalse();
});

// ---------------------------------------------------------------------------
// 4. Idempotency — the half least likely to be noticed
// ---------------------------------------------------------------------------

it('writes the same rows twice and changes nothing the second time', function () {
    /*
     * THE PROOF THE BRIEF ASKED FOR. `PostImporter` re-presents every row on
     * every run, so the owner importing twice is the normal case, not an edge
     * one. A second pass that doubled rows, doubled a path segment or rewrote a
     * destination to a rewrite would show up on no screen until a shopper hit
     * it.
     *
     * Driven through the REAL endpoint the screen posts to, not through
     * diff() directly, because the endpoint is what the owner actually runs.
     *
     * MUTATION NOTE, RUN: changing the endpoint's updateOrCreate() to create()
     * makes the second write throw on the unique `source` index -- 1 failed.
     */
    r2Tree();
    $this->actingAs(r2Admin(), 'admin');

    $permalinks = [
        ['type' => 'category', 'wc_id' => 9011, 'permalink' => 'https://old.test/toners/'],
        ['type' => 'product', 'wc_id' => 999999, 'permalink' => 'https://old.test/product/gone/'],
    ];

    $first = $this->postJson('/admin-api/urls-media/redirects', ['action' => 'write'])->assertOk()->json();
    $rowsAfterFirst = Redirect::query()->orderBy('source')->get(['source', 'target', 'code', 'enabled'])->toArray();

    $second = $this->postJson('/admin-api/urls-media/redirects', ['action' => 'write'])->assertOk()->json();
    $rowsAfterSecond = Redirect::query()->orderBy('source')->get(['source', 'target', 'code', 'enabled'])->toArray();

    // Not one row added, not one destination altered, not one doubled segment.
    expect($rowsAfterSecond)->toBe($rowsAfterFirst, 'the second import changed the redirects table');

    /*
     * ▲ AND THE SECOND RUN WRITES NOTHING AT ALL, which is a stronger statement
     * than "it wrote the same number". `diff()` sorts every proposal whose
     * target already matches into `unchanged`, so an idempotent second pass
     * does no work rather than doing the same work twice. Asserting equal
     * counts would have passed on a build that rewrote all eight rows to the
     * values they already held — which is idempotent in its result and not in
     * its behaviour, and would hide a destination being recomputed differently.
     */
    expect($first['written'])->toBeGreaterThan(0, 'the first run wrote nothing, so this asserts nothing');
    expect($second['written'])->toBe(0, 'the second run rewrote rows that had not changed');
    expect($second['conflict'])->toBe(0);

    // No destination contains a doubled segment, which is the shape a
    // non-idempotent rewrite produces.
    foreach ($rowsAfterSecond as $row) {
        expect($row['target'])->not->toContain('/product-category/product-category/');
        expect($row['target'])->not->toContain('//product');
    }
});

it('proposes byte-identical buckets on two runs over identical data', function () {
    /*
     * One level below the write: the PROPOSAL itself has to be stable. A map
     * whose reasons or buckets move between two runs over the same data is one
     * the owner cannot review, because the list he approved is not the list he
     * is looking at.
     */
    r2Tree();

    $permalinks = [['type' => 'category', 'wc_id' => 9011, 'permalink' => 'https://old.test/toners/']];

    $a = (new RedirectMap)->propose($permalinks);
    $b = (new RedirectMap)->propose($permalinks);

    expect($b)->toBe($a, 'two runs over identical data proposed different things');
});

it('is still stable after its own rows have been written', function () {
    /*
     * The circular case, and the one a naive "ask CheckRedirects" would have
     * broken: run the map, WRITE what it proposes, run it again. The second
     * proposal must not have been changed by the first one's output.
     *
     * MUTATION NOTE, RUN: pointing SourceReachability at CheckRedirects::lookup()
     * makes this red -- rows written by the first run change the second run's
     * verdicts -- 1 failed.
     */
    r2Tree();
    $this->actingAs(r2Admin(), 'admin');

    $before = (new RedirectMap)->propose();

    $this->postJson('/admin-api/urls-media/redirects', ['action' => 'write'])->assertOk();

    $after = (new RedirectMap)->propose();

    expect(r2Buckets($after))->toBe(r2Buckets($before), 'writing the map changed what the map proposes');
});

// ---------------------------------------------------------------------------
// 5. In-content links — the decision Lane A made, re-examined
// ---------------------------------------------------------------------------

it('lets an article body keep linking to the old address, because the shop lands it', function () {
    /*
     * ── WHY THE BODY IS NOT REWRITTEN, AND ROUND 1 STRENGTHENED THAT ────────
     *
     * DocumentMediaRewrite deliberately leaves an `<a href>` that names a PAGE
     * alone: "it belongs to RedirectMap, whose answer is a row in `redirects`
     * the owner approves, and rewriting it here would silently make that
     * decision for him with no row to show for it and no way to take it back."
     *
     * The brief asked whether that still holds now the rows derive themselves.
     * It holds, and the reason is stronger than it was — it is the same reason
     * the block above discards a restated row:
     *
     *   A DERIVED REDIRECT FOLLOWS THE CATEGORY. A REWRITTEN BODY DOES NOT.
     *
     * Rewriting `/toners/` to `/product-category/skincare/toners/` freezes
     * today's nesting into the owner's own prose. Re-parent the category
     * tomorrow and the body points at an address that is a 404, and a body is
     * the one artefact of this migration that cannot be re-derived from
     * anything. Leaving the link alone costs one 301 that the shop makes for
     * itself, for free, for ever, and that corrects itself when the tree moves.
     *
     * So the answer is not "we can now, so we should". It is that the case FOR
     * rewriting got weaker, not stronger, and the cost — editing content
     * irreversibly — did not move.
     *
     * This test is the measurement behind that argument rather than a pin on a
     * thing this lane built.
     */
    r2Tree();

    $post = Post::query()->create([
        'title' => 'How to layer a toner',
        'slug' => 'how-to-layer-a-toner',
        'status' => 'published',
        'body' => '<p>Start with a <a href="/toners/">toner</a> and then a serum.</p>',
    ]);

    // The body is untouched by anything this migration does.
    expect($post->fresh()->body)->toContain('href="/toners/"');

    // And the link a reader clicks lands on the real archive, in one hop, with
    // no row in the redirects table at all.
    expect(Redirect::query()->where('source', '/toners/')->exists())->toBeFalse();

    $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle(\Illuminate\Http\Request::create('http://localhost/toners/', 'GET'));

    expect($response->getStatusCode())->toBe(301);
    expect($response->headers->get('Location'))->toBe('http://localhost/product-category/skincare/toners/');

    $landed = $kernel->handle(\Illuminate\Http\Request::create((string) $response->headers->get('Location'), 'GET'));
    expect($landed->getStatusCode())->toBe(200, 'the link in the article body does not reach a page');
});

// ---------------------------------------------------------------------------
// 6. Nothing that already works may change
// ---------------------------------------------------------------------------

it('leaves a genuine slug change as a row that must still be written', function () {
    /*
     * The rows this map exists for, and the ones round 2 must not discard along
     * with the noise: an address the shop CANNOT derive. A post whose slug
     * changed on import is the real case (SlugGuard refusing a collision), and
     * no derived rule covers it — only a row does.
     */
    r2Tree();

    $post = Post::query()->create([
        'title' => 'Heartleaf', 'slug' => 'heartleaf-extract-2', 'status' => 'published',
        'body' => 'x', 'source_post_id' => 6001,
    ]);

    $proposals = (new RedirectMap)->propose([
        ['type' => 'post', 'wc_id' => 6001, 'permalink' => 'https://old.test/heartleaf-extract/'],
    ]);

    $row = r2Find($proposals, '/heartleaf-extract/');

    expect($row)->not->toBeNull('the slug change produced no proposal at all');
    expect($row['decision'])->toBe(RedirectMap::MIGRATE);
    expect($row['target'])->toBe('/heartleaf-extract-2/');
});

it('leaves the ten shipped journal redirects exactly as they were', function () {
    $rows = Redirect::query()->orderBy('source')->get();

    expect($rows)->toHaveCount(10);

    foreach ($rows as $row) {
        expect(str_starts_with((string) $row->source, '/blog/'))->toBeTrue();
        expect($row->code)->toBe(301);
    }
});

it('compares destinations as raw paths, so a subfolder mount does not undo the discard', function () {
    /*
     * REPORTED RATHER THAN HIDDEN: this block exists because the guard it
     * covers SURVIVED the first mutation pass. Swapping the raw `to_path` for
     * `to` — which has been through Url::to() — left all fifteen tests green,
     * because the default test environment has an EMPTY base path and English
     * has no locale segment, so the two spellings are the same string. The
     * guard was correct and unreached.
     *
     * WHAT IT WOULD LOOK LIKE ON THE SHOP. `env.staging.txt` sets
     * KBB_BASE_PATH=/kbb-upgrade, and `Url::to()` adds it. `redirects.target`
     * is stored WITHOUT it — RedirectMap's class comment calls this "the prefix
     * trap, which is the thing most likely to be silently wrong here" and says
     * every path in the file is assembled from raw strings for that reason. So
     * on a subfolder mount the comparison would read
     *
     *     '/kbb-upgrade/product-category/skincare/toners/'   (the shop)
     *  vs '/product-category/skincare/toners/'               (the proposal)
     *
     * decide they DISAGREE, and put all fifteen back into the ask bucket — on
     * the one deployment shape where nobody would think to re-check, and with
     * a reason that says the shop sends the address somewhere else when it does
     * not.
     *
     * MUTATION NOTE, RUN: putting `'to' => (string) ($resolved['to'] ?? '')`
     * back into SourceReachability::categoryVerdict() makes this red -- the
     * discard becomes an ask -- 1 failed.
     */
    config()->set('kbb.base_path', 'kbb-upgrade');
    \App\Support\Url::forgetBase();

    r2Tree();

    // The precondition: the prefixed and raw spellings really do differ here,
    // or this test asserts nothing.
    expect(\App\Support\Url::to('/product-category/skincare/toners/'))
        ->toBe('/kbb-upgrade/product-category/skincare/toners/');

    $v = (new SourceReachability)->verdict('/product-category/toners/');

    expect($v['status'])->toBe(SourceReachability::MOVED);
    expect($v['to'])->toBe(
        '/product-category/skincare/toners/',
        'the verdict is carrying the base path, which redirects.target never does'
    );

    $row = r2Find((new RedirectMap)->propose(), '/product-category/toners/');

    expect($row['decision'])->toBe(
        RedirectMap::DISCARD,
        'the subfolder mount turned an agreement into a question'
    );
});

it('never edits an article body while it is writing redirects', function () {
    /*
     * THE TWO PASSES MUST NOT LEAK INTO EACH OTHER, and this is the assertion
     * that says so from the redirect side.
     *
     * `DocumentMediaRewrite` edits article bodies and is idempotent by
     * construction — its own header argues it, and `GbUrlsAndMediaTest` ("A
     * second pass has nothing left to do") measures it, so that half is not
     * re-proved here. What neither of them says is that the REDIRECT pass
     * leaves bodies alone entirely. It must: a body is the one artefact of this
     * migration that cannot be re-derived from anything, and a redirect write
     * that touched one would be an edit to the owner's prose with no screen
     * showing it and no way to take it back.
     *
     * Byte-for-byte, including the link to an old address that this lane
     * deliberately does not rewrite.
     *
     * MUTATION NOTE, RUN: this is a guard against a change nobody has made
     * yet. Adding any body write to UrlsMediaApiController::redirects() makes
     * it red; it is here so that change cannot be made quietly.
     */
    r2Tree();
    $this->actingAs(r2Admin(), 'admin');

    $body = '<p>Start with a <a href="/toners/">toner</a>, then '
        .'<a href="https://old.test/wp-content/uploads/2021/x.jpg">this photo</a>.</p>';

    $post = Post::query()->create([
        'title' => 'Layering', 'slug' => 'layering', 'status' => 'published', 'body' => $body,
    ]);

    $this->postJson('/admin-api/urls-media/redirects', ['action' => 'write'])->assertOk();
    $this->postJson('/admin-api/urls-media/redirects', ['action' => 'write'])->assertOk();

    expect($post->fresh()->body)->toBe($body, 'the redirect write edited an article body');
});
