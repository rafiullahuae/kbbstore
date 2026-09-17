<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Product;

/**
 * Lane FB — the skin quiz recommended seventeen products this shop does not
 * sell, at prices nobody set, and posted them into the leads table.
 *
 * ── WHAT WAS THERE ──────────────────────────────────────────────────────────
 *
 * resources/views/store/skin-quiz.blade.php built its whole interface in one
 * inline <script>, and in the middle of it:
 *
 *     const POOL={
 *       cleanseOil:{b:'Anua',n:'Heartleaf Cleansing Oil',p:79},
 *       ...
 *       eye:{b:'Medicube',n:'Age-R Eye Cream',p:99}
 *     };
 *
 * Seventeen entries. THIRTEEN OF THE NAMES APPEAR NOWHERE ELSE IN THIS
 * REPOSITORY — not in `products`, not in the demo seeder, not in the importer;
 * and two of the brands (Numbuzin, MEDIHEAL) do not exist here at all. The
 * prices, 49 to 149, are not in any column. On top of them the results panel
 * computed a bundle — Math.round(total*0.85/5)*5 — and printed "SAVE 15%"
 * against a discount no coupon and no price rule has ever offered.
 *
 * ── AND IT WAS NOT CONTAINED BY THE PREVIEW BANNER ──────────────────────────
 *
 * The page carries a "PREVIEW · front-end only" flag, which is the argument for
 * having left it alone. It does not hold: buildPayload() POSTed the invented
 * names and the invented bundle to /api/quiz, where they were written to
 * `quiz_submissions.recommended_routines` and read back on the admin's leads
 * screen. A fiction that reaches the database is not a preview.
 *
 * This is the same defect, in the same shop, that 2.60.190 shipped to remove
 * from the front page and PublicPagesQuoteRealPricesTest pins on /app. The
 * quiz is the third instance and the only one a logged-out shopper can reach.
 *
 * ── WHAT IS PINNED HERE ─────────────────────────────────────────────────────
 *
 * Not "POOL is gone" — a test naming seventeen products passes the moment
 * somebody types eighteen different ones. Two properties written so the NEXT
 * instance fails too: the page quotes no money at all, and every product name
 * it does print is one the catalogue actually carries.
 */
function fbQuiz(): string
{
    return test()->get('/skin-quiz')->assertOk()->getContent();
}

it('quotes no price on a page whose products are not the catalogue\'s', function () {
    /*
     * The quiz recommends a routine, not a basket. It has no product row to
     * read a price off — which is exactly why every figure on it was invented —
     * so the correct number of prices on this page is none.
     *
     * Matched on the rendered page rather than the source so that a figure
     * built by string concatenation ('AED ' + p.p) is caught the same as a
     * literal.
     */
    $html = fbQuiz();

    /*
     * BOTH SPELLINGS, and the second is the one that matters. This quiz builds
     * its markup in JavaScript, so a price reaches the shopper as
     * `AED ${p.p}` — a template literal that a scan for "AED" followed by a
     * DIGIT does not match. Written that way first, this case passed against
     * the unfixed page while seventeen prices were on it.
     */
    preg_match_all('/\b(?:AED|د\.إ)\s*(?:\d|\$\{|\'\s*\+|" *\+)/iu', $html, $m);

    expect($m[0])->toBe([], sprintf(
        'The skin quiz quotes or builds %d price(s): %s. This page cannot read a price — '
        . 'it recommends a routine, and every figure it has ever printed was typed '
        . 'into the template.',
        count($m[0]),
        implode(', ', array_unique($m[0]))
    ));
});

it('carries no hard-coded product catalogue in its script', function () {
    /*
     * The shape, not the names. A product in this application is a row with a
     * brand, a name and a price; an object literal carrying all three is a
     * catalogue whatever it is called, and POOL was only the one that happened
     * to be there.
     */
    $source = file_get_contents(resource_path('views/store/skin-quiz.blade.php'));

    // {b:'Brand',n:'Name',p:79} and any respelling of it.
    preg_match_all('/\{\s*b\s*:\s*[\'"][^\'"]+[\'"]\s*,\s*n\s*:\s*[\'"][^\'"]+[\'"]\s*,\s*p\s*:\s*\d+/u', $source, $m);

    expect($m[0])->toBe([], sprintf(
        'The skin quiz declares %d product(s) inline. Products are rows in `products`, '
        . 'not entries in a template.',
        count($m[0])
    ));
});

it('promises no discount the shop has not got', function () {
    $html = fbQuiz();

    expect($html)->not->toContain('SAVE ')
        ->and($html)->not->toContain('save-pill');

    // And no bundle arithmetic left behind to rebuild one.
    $source = file_get_contents(resource_path('views/store/skin-quiz.blade.php'));

    expect($source)->not->toContain('*0.85')
        ->and($source)->not->toContain('bundle_aed');
});

it('names only products the catalogue actually carries', function () {
    /*
     * THE CASE THAT GENERALISES. Whatever the quiz prints as a product name has
     * to be a name `products` holds. With the routine presented as steps rather
     * than products it prints none, and this passes vacuously — but it stops
     * passing the day somebody types a name back in, which is the point.
     */
    Product::create([
        'slug' => 'fb-real-serum',
        'name' => 'Real Catalogue Serum',
        'status' => 'publish',
        'is_visible' => true,
        'price' => 13000,
        'stock_status' => 'instock',
    ]);

    $html = fbQuiz();

    $known = Product::query()->pluck('name')->all();

    // The brands the old POOL invented, as the concrete regression: each is
    // either a real catalogue row now or must not be on the page.
    $invented = [
        'Heartleaf Cleansing Oil', 'Low pH Gel Cleanser', 'Heartleaf 77 Toner',
        'Hyaluronic Acid Toner', 'Dive-In HA Serum', 'AHA-BHA-PHA Serum',
        'Snail 96 Mucin Essence', 'PDRN Pink Collagen Serum', 'Dive-In Cream',
        'Oil-Free Moisturizer', 'PDRN Capsule Cream', 'Glow Mask', 'Age-R Eye Cream',
        'Numbuzin', 'MEDIHEAL',
    ];

    $stillThere = array_values(array_filter(
        $invented,
        fn (string $name): bool => str_contains($html, $name) && ! in_array($name, $known, true)
    ));

    expect($stillThere)->toBe([], 'The quiz still names products the catalogue does not carry: '
        . implode(', ', $stillThere));
});

it('sends the leads table a routine rather than a list of invented products', function () {
    /*
     * The payload is what made this more than a display bug. `products` and
     * `bundle_aed` were written into quiz_submissions.recommended_routines and
     * kept.
     *
     * The admin leads screen (AdminController::quizLeads) reads only
     * $r['name'] out of each routine, so dropping the other two keys is
     * backward compatible: rows already stored keep rendering exactly as they
     * did, and no migration is needed to display them.
     */
    $source = file_get_contents(resource_path('views/store/skin-quiz.blade.php'));

    expect($source)->not->toContain('products:r.items')
        ->and($source)->not->toContain('bundle_aed');

    // The routine's NAME is still sent — that is what the leads screen shows.
    expect($source)->toContain('recommendedRoutines');
});

it('still shows the owner a lead\'s routine names on the leads screen', function () {
    /*
     * The half that proves the payload change did not break the screen it feeds.
     * A row written in the OLD shape (with the invented keys) and one written in
     * the new shape both have to render.
     */
    $admin = AdminUser::create([
        'name' => 'FB Owner',
        'email' => 'fb-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);

    \App\Models\QuizSubmission::create([
        'name' => 'Old Row', 'email' => 'old@example.com', 'phone' => '+971500000001',
        'skin_type' => 'Dry', 'concerns' => json_encode(['Hydration']),
        'recommended_routines' => [
            ['name' => 'Everyday Essentials', 'products' => ['Anua Heartleaf Cleansing Oil'], 'bundle_aed' => 250],
        ],
        'status' => 'new',
    ]);

    \App\Models\QuizSubmission::create([
        'name' => 'New Row', 'email' => 'new@example.com', 'phone' => '+971500000002',
        'skin_type' => 'Oily', 'concerns' => json_encode(['Pores & oil']),
        'recommended_routines' => [
            ['name' => 'Everyday Essentials', 'steps' => ['Cleanse', 'Treat', 'Moisturise', 'Protect']],
        ],
        'status' => 'new',
    ]);

    $json = $this->actingAs($admin, 'admin')
        ->getJson('/admin-api/quiz-leads')
        ->assertOk()
        ->json();

    $names = collect($json['leads'])->pluck('recommended')->flatten()->all();

    expect($names)->toContain('Everyday Essentials');
    expect(collect($json['leads'])->pluck('name')->all())
        ->toContain('Old Row')
        ->toContain('New Row');
});

/* ═══════════ what the capture endpoint actually keeps, measured ═══════════ */

it('still keeps an invented product out of the routines column now that the column is written', function () {
    /*
     * THE CORRECTION THIS LANE OWES ITS OWN EARLIER NOTES, kept, and its
     * condition now met.
     *
     * The first pass at this lane recorded that buildPayload() posted the
     * seventeen invented products to /api/quiz and that they were kept in
     * quiz_submissions.recommended_routines "against a real customer's phone
     * number". That was read off the page rather than measured, and it is
     * wrong in the direction that matters: it implies stored rows to clean up.
     * Nothing was ever stored, because store() did not write that column at
     * all.
     *
     * It writes it now (Lane FJ), and the note this case carried said exactly
     * what that costs: "if somebody later adds recommended_routines to
     * store()'s create() WITHOUT ALSO DECIDING WHAT MAY GO IN IT, this fails
     * and says why". The decision is Api\QuizController::routinesFrom() — a
     * routine contributes a name and a list of step names and the rest of the
     * object is discarded — and this case is what holds it to that. A product
     * name or a bundle total reaching the column turns it red exactly as
     * before.
     */
    $this->postJson('/api/quiz', [
        'skin_type' => 'Oily',
        'concerns' => ['Hydration'],
        'name' => 'Probe', 'phone' => '+971500000000', 'consent' => true,
        // The two keys the old page sent and the new one does not.
        'products' => [['name' => 'Invented Serum', 'brand' => 'Numbuzin', 'price' => 129]],
        'bundle_aed' => 411,
        // And the same two smuggled inside a routine, which is the shape the
        // column now accepts.
        'recommendedRoutines' => [[
            'name' => 'Everyday Essentials',
            'steps' => ['Cleanse', 'Protect'],
            'products' => [['name' => 'Invented Serum', 'brand' => 'Numbuzin', 'price' => 129]],
            'bundle_aed' => 411,
        ]],
    ])->assertCreated();

    $row = \App\Models\QuizSubmission::query()->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->recommended_routines)->toBe([
            ['name' => 'Everyday Essentials', 'steps' => ['Cleanse', 'Protect']],
        ]);

    $stored = implode(' ', array_map('strval', $row->getAttributes()));

    expect(str_contains($stored, 'Invented Serum'))->toBeFalse('a product name reached the routines column');
    expect(str_contains($stored, 'Numbuzin'))->toBeFalse('a brand name reached the routines column');
    expect(str_contains($stored, '411'))->toBeFalse('a bundle total reached the routines column');
});

it('keeps the seven contact and answer fields the quiz page has always sent', function () {
    /*
     * THE GAP, CLOSED — this case is the inversion the note it replaces asked
     * for by name.
     *
     * What it said: the endpoint accepted NINE fields and the page sent TWO of
     * them, because skin-quiz.blade.php has always posted camelCase under
     * nested objects — `skinType`, `contact.name`, `contact.phone`,
     * `contact.email`, `answers.age`, `answers.routineDepth`, `answers.budget`
     * — while store() validated flat snake_case, and Laravel's validate()
     * drops every key it was not asked about. A shopper typed their name,
     * WhatsApp number and email into a gated form and the columns were written
     * NULL.
     *
     * The nested spelling is canonical now, because the page is the published
     * contract and a shopper part-way through the quiz is posting that body;
     * the flat spelling stays accepted as an alias. Both halves are pinned in
     * tests/Feature/ApiSecurityTest.php, along with what this endpoint may NOT
     * keep and the leak rules that a lead carrying a phone number brings with
     * it. This case holds the page's own body to it.
     */
    $this->postJson('/api/quiz', [
        // Exactly what resources/views/store/skin-quiz.blade.php posts.
        'skinType' => 'Oily',
        'concerns' => ['Hydration', 'Pores & texture'],
        'answers' => ['age' => '25-34', 'routineDepth' => 'Full', 'budget' => 'AED 200-400'],
        'contact' => ['name' => 'Aisha', 'phone' => '+971500000000', 'email' => 'a@example.com'],
        'recommendedRoutines' => [['name' => 'Balanced glow', 'steps' => ['Cleanse', 'Tone']]],
        'consent' => true,
    ])->assertCreated();

    $row = \App\Models\QuizSubmission::query()->latest('id')->first();

    expect($row->concerns)->toBe('Hydration,Pores & texture')
        ->and((int) $row->consent)->toBe(1);

    expect([
        'skin_type' => $row->skin_type,
        'age' => $row->age,
        'routine_depth' => $row->routine_depth,
        'budget' => $row->budget,
        'name' => $row->name,
        'phone' => $row->phone,
        'email' => $row->email,
    ])->toBe([
        'skin_type' => 'Oily',
        'age' => '25-34',
        'routine_depth' => 'Full',
        'budget' => 'AED 200-400',
        'name' => 'Aisha',
        'phone' => '+971500000000',
        'email' => 'a@example.com',
    ]);
});
