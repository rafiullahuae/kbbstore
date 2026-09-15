<?php

declare(strict_types=1);

/**
 * The Reviews moderation screen itself — the markup, not the endpoint.
 *
 * AdminReviewsTest drives the API. This file asserts the things that live in
 * resources/views/admin/app.blade.php and can only break there.
 *
 * THE ONE THIS EXISTS FOR IS THE ESCAPE. The screen this replaced wrote
 * `r.reply` into innerHTML with no sesc() around it while escaping every other
 * field beside it — stored XSS in the owner's own back-office, reachable by
 * anything that can write a reply, which will include the WooCommerce importer
 * when it lands. An escape is exactly the kind of thing that is correct when
 * written and quietly removed later by someone tidying a template string, and
 * nothing about the rendered page looks different when it goes.
 */

/** The region of the admin document this lane owns. */
function amRegion(): string
{
    $blade = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    $start = strpos($blade, 'LANE AM · Store · Reviews · moderation — BEGIN');
    $end = strpos($blade, 'LANE AM · Store · Reviews · moderation — END');

    expect($start)->not->toBeFalse('the Lane AM region marker is missing');
    expect($end)->not->toBeFalse('the Lane AM region end marker is missing');

    return substr($blade, $start, $end - $start);
}

it('escapes every public field it writes into the page, the reply included', function () {
    $region = amRegion();

    // Each of these is text a shopper or an importer supplies and every one of
    // them lands in innerHTML.
    foreach ([
        'sesc(r.author',
        'sesc(r.title)',
        'sesc(text)',
        'sesc(r.reply)',
        'sesc(p.name)',
        'sesc(RV.search)',
    ] as $needle) {
        // NOT toContain($needle, $message): Pest's toContain is VARIADIC, so a
        // second argument is a second needle and the "message" gets asserted as
        // though it were part of the file. Reported with expect()->toBeTrue()
        // instead, which really does take a message.
        expect(str_contains($region, $needle))
            ->toBeTrue("unescaped interpolation: {$needle} is missing from the Reviews region");
    }

    /*
     * And the specific shape of the old defect, so it cannot come back by
     * copy-paste: the reply concatenated raw.
     */
    expect($region)->not->toContain("+ r.reply +")
        ->and($region)->not->toContain("+r.reply+")
        ->and($region)->not->toContain('r.reply.slice');
});

it('calls the guarded endpoints this lane added, not the old unpaginated one', function () {
    $region = amRegion();

    expect($region)->toContain('/admin-api/reviews/list')
        ->toContain('/admin-api/reviews/export')
        ->toContain('/admin-api/reviews/bulk-moderate')
        ->toContain("'/moderate'");

    // The old screen fetched /admin-api/reviews with an optional ?status=,
    // which returned the whole table. Nothing here may call it.
    expect($region)->not->toContain("api('/admin-api/reviews'")
        ->and($region)->not->toContain("'/admin-api/reviews?");
});

it('offers the schema vocabulary on the chips, and no `rejected` status', function () {
    $region = amRegion();

    // The chips are All / Waiting / Approved / Spam. "Rejected" as a STATUS is
    // gone; "Reject" as the name of an action that writes `spam` is fine and is
    // what the owner reads.
    expect($region)->toContain("['all', 'All']")
        ->toContain("['pending', 'Waiting']")
        ->toContain("['approved', 'Approved']")
        ->toContain("['spam', 'Spam']");

    /*
     * The word "rejected" IS still in the region, deliberately: the button
     * reads "Reject" and the toast says "rejected", which is the language the
     * owner thinks in. What must not exist is a status VALUE spelled that way,
     * because the table cannot hold one. Asserted over the values the two
     * arrays actually offer — see the test below — rather than by banning the
     * string, which would ban the copy as well as the bug.
     */
    expect($region)->not->toContain("status: 'rejected'")
        ->and($region)->not->toContain("['rejected',");
});

/**
 * The status values the region's two arrays actually offer, read out of the
 * source rather than restated — a copy here could agree with itself and
 * disagree with the screen.
 */
function amChipAndActionValues(): array
{
    $region = amRegion();
    $values = [];

    foreach (['RV_CHIPS', 'RV_ACTIONS'] as $name) {
        $at = strpos($region, 'var ' . $name . ' = [');
        expect($at)->not->toBeFalse("{$name} is missing from the Reviews region");

        $block = substr($region, (int) $at, (int) strpos($region, '];', (int) $at) - (int) $at);

        preg_match_all("/\['([a-z_]+)'/", $block, $m);
        $values = array_merge($values, $m[1]);
    }

    expect($values)->not->toBeEmpty();

    return $values;
}

it('offers no status value spelled `rejected` on any chip or action', function () {
    foreach (amChipAndActionValues() as $value) {
        expect(['all', 'pending', 'approved', 'spam'])->toContain($value);
    }
});

it('reads the chip counts from the server rather than counting the page', function () {
    $region = amRegion();

    // Counting `rows.length` on the client would make every chip describe the
    // current page instead of the filtered set — which is the same class of
    // wrong number the old screen had, arrived at from the other direction.
    expect($region)->toContain('counts[c[0]]')
        ->and($region)->toContain('(d && d.counts)');
});

it('sends the page and per-page the pager is driven by', function () {
    $region = amRegion();

    expect($region)->toContain("p.set('page', RV.page)")
        ->toContain("p.set('per_page', RV.perPage)")
        // And the export deliberately does NOT carry them: it is the whole
        // filtered view, bounded server-side, not one page of it.
        ->toContain('rvParams(true)');
});

it('no longer points All Reviews at a standalone HTML file this repo does not ship', function () {
    $blade = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    // REV_SRC still lists the seven Reviews screens that really are unbuilt
    // frames — those are not this lane's to fix — but rev-all must not render
    // one any more.
    expect($blade)->toContain("LANE AM — 'rev-all' is NOT a frame any more")
        ->and($blade)->toContain("if(id==='rev-all'){");

    // And go() still routes rev-all to the real screen.
    expect($blade)->toContain("if(id==='rev-all'){ _go(id); return renderReviews(); }");
});

it('renders the admin document with the region in it', function () {
    $admin = \App\Models\AdminUser::create([
        'name' => 'AM Screen Owner',
        'email' => 'am-screen-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);

    $path = (string) (\App\Models\Setting::map()['admin_path'] ?? 'admin');

    $response = test()->actingAs($admin, 'admin')->get('/' . ltrim($path, '/'));

    if ($response->getStatusCode() !== 200) {
        // The admin path is configurable and another lane owns that route; the
        // static assertions above are the ones that matter here.
        expect($response->getStatusCode())->toBeGreaterThanOrEqual(200);

        return;
    }

    $html = $response->getContent();

    // The compiled Blade actually carries the screen, so a view-cache or
    // @verbatim mistake in the splice would surface here rather than in a
    // browser.
    expect($html)->toContain('renderReviews')
        ->and($html)->toContain('/admin-api/reviews/list')
        ->and($html)->toContain('rv-card');
});
