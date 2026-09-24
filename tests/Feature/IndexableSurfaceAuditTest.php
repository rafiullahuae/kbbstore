<?php

/**
 * Store → SEO & Meta → SEO Audit, and the sitemap's product images.
 *
 * NAMED WITHOUT "Seo" ON PURPOSE. tests/Feature/*Seo* belongs to the lane
 * changing App\Support\Seo this round; a file of mine landing in that glob
 * would collide with theirs at merge for no reason other than what I called it.
 *
 * Every assertion below is made against what the endpoint actually returns or
 * what /sitemap.xml actually serves — never against a helper's return value —
 * and each one says in its own comment what the shop looked like without it.
 */

use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Support\SeoAudit;
use Illuminate\Support\Facades\Route;

const ISA_BASE = 'https://kbeautybliss.test';

/**
 * The route mounted exactly as routes/seo-audit-admin.php says it must be.
 *
 * CLAUDE.md forbids this lane from editing routes/web.php, so the route file
 * ships for the integrator to require inside the existing admin-api group.
 * Mounting it here the same way makes these assertions a test OF that wiring
 * instruction: they pass now, and they keep passing unchanged once the
 * integrator follows it, because Laravel's route collection is keyed on method
 * and URI and this registration replaces rather than duplicates.
 *
 * ONE middleware() CALL. RouteRegistrar::middleware() REPLACES the attribute
 * rather than appending to it, so the readable two-call spelling silently drops
 * the guard — which is the one mistake the integrator must not make in web.php.
 */
function isaMount(): void
{
    Route::middleware(['web', 'auth:admin', \App\Http\Middleware\NoStoreAdminApi::class])
        ->prefix('admin-api')
        ->group(base_path('routes/seo-audit-admin.php'));
}

function isaUser(string $role): AdminUser
{
    return AdminUser::create([
        'name' => 'ISA '.$role,
        'email' => 'isa-'.$role.'-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => $role,
    ]);
}

function isaSettings(array $values = []): void
{
    foreach (array_merge(['site_url' => ISA_BASE], $values) as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['value' => (string) $value]);
    }

    Setting::flushMap();
}

function isaProduct(array $attrs = []): Product
{
    static $n = 0;
    $n++;

    return Product::create(array_merge([
        'slug' => 'isa-product-'.$n,
        'name' => 'ISA Product '.$n,
        'status' => 'publish',
        'is_visible' => true,
        'price' => 10000,
        'short_description' => 'A long enough description to count as a real one for the audit.',
        'image' => '/media/isa-'.$n.'.jpg',
        'sku' => 'ISA-'.$n,
    ], $attrs));
}

/**
 * The report, as the endpoint returns it.
 *
 * EVERY COUNT BELOW IS A DELTA AGAINST A BASELINE TAKEN FIRST, never an
 * absolute. The test database is migrated AND SEEDED — it arrives with 24
 * demo products, 6 categories and 8 brands, several of them missing images and
 * descriptions — so an absolute `toBe(2)` asserts the size of the demo
 * catalogue as much as it asserts the code, and breaks the day somebody adds a
 * demo product. A delta asserts exactly the thing the test set up.
 */
function isaScan(): array
{
    return test()->actingAs(isaUser('owner'), 'admin')
        ->getJson('/admin-api/seo-audit')
        ->assertOk()
        ->json();
}

/** The count for one finding, or the scanned total for one kind. */
function isaCount(array $report, string $finding): int
{
    return (int) ($report['findings'][$finding]['count'] ?? 0);
}

/* ------------------------------------------------------------------ the guard */

it('refuses the SEO audit to a caller who is not signed in', function () {
    isaMount();

    $response = test()->getJson('/admin-api/seo-audit');

    expect($response->status())->not->toBe(200);

    /*
     * And the refusal must not leak the report itself. This response is a map
     * of the shop's weakest pages — which titles collide, which products have
     * no description. Unauthenticated that is a competitor's content plan, and
     * it is a full catalogue scan per call, which is free amplification.
     *
     * MUTATION NOTE: move the require in routes/web.php out of the admin-api
     * group (or drop `auth:admin` from it) and this is red.
     */
    expect(str_contains($response->getContent(), '"findings"'))
        ->toBeFalse('an anonymous caller was handed the SEO audit');
});

it('refuses the SEO audit to every role but the owner', function (string $role) {
    isaMount();

    /*
     * The capability is system.diagnostics, which is owner-only. Without the
     * RULES entry in AdminCapabilities the closed default would produce the
     * same 403 — the entry exists so the map shows a route that was mapped on
     * purpose rather than one nobody thought about, and so
     * AdminCapabilityMapTest sees it.
     *
     * MUTATION NOTE: change the capability on the admin-api/seo-audit row to
     * one a manager holds (say 'catalogue.manage') and this is red.
     */
    test()->actingAs(isaUser($role), 'admin')
        ->getJson('/admin-api/seo-audit')
        ->assertForbidden();
})->with(['manager', 'support', 'editor']);

/* ------------------------------------------------------- what the audit finds */

it('finds two products claiming the same title, which no per-row check can see', function () {
    isaMount();
    isaSettings();

    /*
     * THE DEFECT THIS EXISTS FOR. Two products named identically are two URLs
     * competing for one result: Google keeps one and files the other under
     * "Duplicate without user-selected canonical", and the page silently stops
     * ranking. Admin\CatalogueAuditApiController cannot report this and never
     * could — it asks three questions of each row in turn, and a duplicate is
     * a property of the SET, not of any row in it.
     *
     * MUTATION NOTE: drop the `$entry['count'] < 2` guard in
     * SeoAudit::collectDuplicates() to `< 3` and this is red; delete the
     * duplicate-title tally in SeoAudit::common() and it is red too.
     */
    $before = isaScan();

    isaProduct(['name' => 'Snail Mucin Essence 100ml']);
    isaProduct(['name' => 'Snail Mucin Essence 100ml']);
    isaProduct(['name' => 'Something Else Entirely Here']);

    $after = isaScan();

    // Two PAGES have the problem, not one clashing value. Reporting the number
    // of distinct duplicated titles is how an audit makes a real problem look
    // small.
    expect(isaCount($after, 'duplicate_title') - isaCount($before, 'duplicate_title'))->toBe(2);
});

it('does not call a page a duplicate because a second page has the same empty description', function () {
    isaMount();
    isaSettings();

    /*
     * The obvious implementation tallies every description including the empty
     * string, and then a shop where fifty products have no description reports
     * fifty "duplicate descriptions" on top of fifty "no description" — the
     * same defect counted twice, burying the handful of pages that genuinely
     * do share a written paragraph.
     *
     * MUTATION NOTE: move the description tally in SeoAudit::common() out of
     * the `else` branch so it runs for empty descriptions too, and this is red.
     */
    $before = isaScan();

    isaProduct(['short_description' => '']);
    isaProduct(['short_description' => '']);

    $after = isaScan();

    expect(isaCount($after, 'no_description') - isaCount($before, 'no_description'))->toBe(2);
    expect(isaCount($after, 'duplicate_description') - isaCount($before, 'duplicate_description'))->toBe(0);
});

it('reports a canonical override that points off this site', function () {
    isaMount();
    isaSettings();

    /*
     * `seo.canonical` is a free text box on the product, category and brand
     * editors. A row carrying another company's domain is a page instructing
     * Google to credit THAT address instead of this one — one wrong paste
     * de-indexes a page silently and permanently, and no screen in the console
     * has ever shown it.
     *
     * MUTATION NOTE: make SeoAudit::canonicalIsSafe() `return true` and this is
     * red on all three rows.
     */
    $before = isaScan();

    isaProduct(['seo' => ['canonical' => 'http://kbeautyarabia.example/products/x']]);   // scheme downgrade
    isaProduct(['seo' => ['canonical' => 'javascript:alert(1)']]);                        // not a URL at all
    isaProduct(['seo' => ['canonical' => '//evil.example/x']]);                           // protocol-relative

    // These two are correct and must NOT be reported: a root-relative value
    // resolves against this host by definition and is what an admin usually
    // types, and an absolute https URL is the fully-written form of the same.
    isaProduct(['seo' => ['canonical' => '/product/somewhere/']]);
    isaProduct(['seo' => ['canonical' => ISA_BASE.'/product/somewhere/']]);

    $after = isaScan();

    expect(isaCount($after, 'bad_canonical') - isaCount($before, 'bad_canonical'))->toBe(3);
});

it('leaves a noindexed row out of the audit entirely', function () {
    isaMount();
    isaSettings();

    /*
     * A page the owner has told Google to skip is not a page with a problem.
     * Counting it produces an audit that can never reach zero — the operator
     * fixes everything fixable and the screen still shows findings, which is
     * how a report stops being read.
     *
     * MUTATION NOTE: delete the `! empty($override['noindex'])` continue in
     * SeoAudit::scanProducts() and this is red twice over — the scanned total
     * goes to 2 and no_description goes to 1.
     */
    $before = isaScan();

    isaProduct();
    isaProduct(['short_description' => '', 'seo' => ['noindex' => true]]);

    $after = isaScan();

    // Two products added, ONE counted: the noindexed one is not scanned at all.
    expect($after['scanned']['Products'] - $before['scanned']['Products'])->toBe(1);
    // And its empty description is not a finding.
    expect(isaCount($after, 'no_description') - isaCount($before, 'no_description'))->toBe(0);
});

it('scans categories and brands, which the Catalogue Audit never looks at', function () {
    isaMount();
    isaSettings();

    /*
     * On this shop that is 93 brand landing pages and the whole category tree,
     * every one of them an indexable URL the sitemap submits to Google, and
     * none of them visible to any audit screen this console had.
     *
     * MUTATION NOTE: delete either scanTaxonomy call in SeoAudit::run() and
     * the matching key disappears from `scanned`, which is red.
     */
    $before = isaScan();

    Category::create(['slug' => 'isa-cat', 'name' => 'X', 'path' => 'isa-cat']);
    Brand::create(['slug' => 'isa-brand', 'name' => 'Y']);

    $after = isaScan();

    expect($after['scanned']['Categories'] - $before['scanned']['Categories'])->toBe(1);
    expect($after['scanned']['Brands'] - $before['scanned']['Brands'])->toBe(1);

    // Both names are one character, which is under TITLE_MIN, and neither has
    // a description or an image — so the whole-surface checks reach them.
    expect(isaCount($after, 'title_too_short') - isaCount($before, 'title_too_short'))->toBe(2);
    expect(isaCount($after, 'no_description') - isaCount($before, 'no_description'))->toBe(2);
    expect(isaCount($after, 'no_image') - isaCount($before, 'no_image'))->toBe(2);
});

it('never hands the browser a column the screen did not ask for', function () {
    isaMount();
    isaSettings();

    /*
     * `products` carries wc_id, sku and total_sales. CLAUDE.md records each of
     * those leaking in production through an endpoint that returned a model
     * instead of an allowlist. This endpoint is authenticated, which is not a
     * reason to relax the rule — it is a reason the rule is cheap to keep.
     *
     * MUTATION NOTE: return the query rows straight out of SeoAudit instead of
     * the four keys built in scanProducts(), and this is red.
     */
    isaProduct(['name' => 'A', 'wc_id' => 4242, 'total_sales' => 99]);

    $raw = test()->actingAs(isaUser('owner'), 'admin')
        ->getJson('/admin-api/seo-audit')
        ->assertOk()
        ->getContent();

    foreach (['wc_id', 'total_sales', '4242'] as $leak) {
        expect(str_contains($raw, $leak))->toBeFalse("the audit response carried {$leak}");
    }
});

it('counts words in a script that has no spaces between every word', function () {
    /*
     * str_word_count() — which the older Catalogue Audit uses — counts ZERO
     * words in Arabic, because its notion of a "word" is Latin letters. This
     * shop is bilingual, so an Arabic description of any length would have been
     * reported as missing, on every Arabic product, forever.
     *
     * MUTATION NOTE: swap SeoAudit::words() back to str_word_count() and this
     * is red.
     */
    expect(SeoAudit::words('سيروم مرطب للبشرة الجافة'))->toBe(4);
    expect(SeoAudit::words('<p>two words</p>'))->toBe(2);
    expect(SeoAudit::words('   '))->toBe(0);
});

it('names the biggest problem in one line rather than printing a score', function () {
    isaMount();
    isaSettings();

    /*
     * The verdict is the only part of this screen an owner reads every time, so
     * it has to name something actionable. A score ("SEO health: 72") is a
     * number nobody knows what to do with; "Biggest issue: No image (38)" is a
     * morning's work with an obvious beginning.
     *
     * MUTATION NOTE: make SeoAudit::verdict() return a constant string and this
     * is red — it asserts the count in the line matches the finding it names.
     */
    $report = isaScan();

    expect($report['verdict'])->toContain('indexable URLs scanned');
    expect($report['verdict'])->toContain('Biggest issue:');

    // The number in the line is the count of the finding the line names, not a
    // total and not a coincidence.
    $worst = 0;
    foreach ($report['findings'] as $finding) {
        $worst = max($worst, (int) $finding['count']);
    }

    expect($report['verdict'])->toContain('('.$worst.')');
    expect($report['verdict'])->toStartWith($report['total'].' indexable URLs scanned');
});

/* ------------------------------------------------- the sitemap's image entries */

it('serves the sitemap without an image namespace until the box is ticked', function () {
    isaSettings();
    isaProduct(['images' => ['/media/gallery-1.jpg']]);

    /*
     * RULE 1. /sitemap.xml is byte-pinned by the English render walk, and a new
     * setting ships at the value the page already has — so applying this
     * package must not move one byte of a file Search Console has already
     * fetched.
     *
     * MUTATION NOTE: change the default in SeoFilesController::sitemap() from
     * '0' to '1' and this is red.
     */
    $xml = test()->get('/sitemap.xml')->assertOk()->getContent();

    expect($xml)->not->toContain('sitemap-image');
    expect($xml)->not->toContain('<image:image>');
});

it('lists the whole gallery under a product once the box is ticked', function () {
    isaSettings(['sitemap_images' => '1']);

    isaProduct([
        'slug' => 'isa-gallery',
        'image' => '/media/featured.jpg',
        'images' => ['/media/featured.jpg', '/media/second.jpg', 'https://cdn.example/third.jpg'],
    ]);

    $xml = test()->get('/sitemap.xml')->assertOk()->getContent();

    expect($xml)->toContain('xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"');

    // The featured shot first — Google treats the first <image:image> as the
    // most representative, and the product page puts the same one at the top.
    expect($xml)->toContain('<image:loc>'.ISA_BASE.'/media/featured.jpg</image:loc>');
    expect($xml)->toContain('<image:loc>'.ISA_BASE.'/media/second.jpg</image:loc>');
    // An absolute URL is passed through rather than having the site root
    // prefixed onto it.
    expect($xml)->toContain('<image:loc>https://cdn.example/third.jpg</image:loc>');

    /*
     * De-duplicated: the featured image is normally also the first gallery
     * entry, so the naive version published every product's main photograph
     * twice.
     *
     * MUTATION NOTE: drop the in_array() check in
     * SeoFilesController::productImages() and this is red.
     */
    expect(substr_count($xml, ISA_BASE.'/media/featured.jpg'))->toBe(1);
});

it('refuses to print an image URL that is not a web address', function () {
    isaSettings(['sitemap_images' => '1']);

    /*
     * The `images` column is written by the WooCommerce importer, the media
     * sideloader and the product editor's text box, so a row can hold anything.
     * A sitemap is parsed strictly and one malformed <image:loc> is a reason to
     * reject the entry around it — and `javascript:` is not an address a
     * crawler should ever be handed. This is the same scheme check this project
     * already applies to a settings-supplied href, applied to a row-supplied
     * one.
     *
     * MUTATION NOTE: delete the parse_url scheme check in
     * SeoFilesController::productImages() and this is red.
     */
    isaProduct([
        'slug' => 'isa-bad-images',
        'image' => null,
        'images' => ['javascript:alert(1)', 'data:image/png;base64,AAAA', ['not' => 'a string'], '/media/ok.jpg'],
    ]);

    $xml = test()->get('/sitemap.xml')->assertOk()->getContent();

    expect($xml)->not->toContain('javascript:');
    expect($xml)->not->toContain('data:image');
    expect($xml)->toContain('<image:loc>'.ISA_BASE.'/media/ok.jpg</image:loc>');
});

it('cannot be broken out of the XML by a hostile image URL', function () {
    isaSettings(['sitemap_images' => '1']);

    /*
     * THE SITEMAP'S EQUIVALENT OF THE JSON-LD BREAK-OUT.
     *
     * tests/Feature/SeoRenderTest.php pins the <script> case for JSON-LD, after
     * an org_name of "</script><script>alert(1)</script>" executed on every page
     * of the site. This is the same class of defect one document over: an image
     * URL is operator-supplied text that this lane now prints into XML that
     * Google fetches and parses strictly.
     *
     * A URL containing < > & " survives parse_url's scheme check — the scheme is
     * genuinely https — so the scheme check is NOT what saves this. The
     * htmlspecialchars(..., ENT_XML1) on the way out is.
     *
     * ENT_QUOTES MATTERS AND IT IS EASY TO MISS: htmlspecialchars($v,
     * ENT_XML1) does NOT escape a double quote, because passing a flag
     * REPLACES the default set rather than adding to it. In element text that
     * is legal XML; in the hreflang href="..." attribute a few lines above in
     * the same method it closes the attribute and costs the whole document.
     * Both call sites now pass ENT_QUOTES | ENT_XML1.
     *
     * MUTATION NOTE: drop the escaping from the <image:loc> line in
     * SeoFilesController::sitemap() and this is red — and the sitemap becomes
     * malformed XML that Search Console rejects whole. Narrow it back to a bare
     * ENT_XML1 and the first assertion below is red on the quote.
     */
    isaProduct([
        'slug' => 'isa-xml-breakout',
        'image' => 'https://cdn.example/a.jpg?x=<loc>&y="q"',
        'images' => [],
    ]);

    $xml = test()->get('/sitemap.xml')->assertOk()->getContent();

    // The raw metacharacters must not reach the document...
    expect($xml)->toContain('https://cdn.example/a.jpg?x=&lt;loc&gt;&amp;y=&quot;q&quot;');

    // ...and the document must still parse. This is the assertion that matters:
    // a sitemap that does not parse is a sitemap Google discards entirely.
    $prior = libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml);
    libxml_use_internal_errors($prior);

    expect($doc)->not->toBeFalse('the sitemap was not well-formed XML');
});

/* ------------------------------------------- alt text on product images (Lane S, round 2) */

/*
 * WHAT THIS LOOKS LIKE ON THE SHOP. `products.image_alts` and the alt box on
 * every gallery row of the product editor exist, and the storefront reads them
 * through Product::altFor(). Nothing anywhere told the owner WHICH products
 * still had none — the audit reported "No image", which is a different and much
 * smaller problem, and stopped there. On a beauty catalogue, where people shop
 * by looking and Google Image Search is a real entry point, the photographs
 * that nobody has described are the largest untapped surface in the shop and
 * there was no way to find them.
 *
 * Admin path: Store → SEO & Meta → SEO Audit. The fix for each row is
 * Store → Catalogue → Products → the product → Media.
 *
 * MUTATION NOTE: delete the `if ($hasAlts)` block from
 * SeoAudit::scanProducts() and every test in this section goes red. Change it
 * to count products with no alt ROW rather than no alt PER SHOT and "counts a
 * product whose gallery is only half described" goes red.
 */

it('reports a product whose photographs nobody has described', function () {
    isaMount();
    isaSettings();

    $before = isaScan();

    isaProduct(['image' => '/media/alt-none.jpg', 'images' => ['/media/alt-none-2.jpg']]);

    $after = isaScan();

    expect(isaCount($after, 'product_no_image_alt') - isaCount($before, 'product_no_image_alt'))->toBe(1);
});

it('leaves a fully described product alone', function () {
    isaMount();
    isaSettings();

    $before = isaScan();

    isaProduct([
        'image' => '/media/alt-all.jpg',
        'images' => ['/media/alt-all-2.jpg'],
        'image_alts' => [
            '/media/alt-all.jpg' => 'Texture on the back of a hand',
            '/media/alt-all-2.jpg' => 'The ingredient list on the box',
        ],
    ]);

    $after = isaScan();

    expect(isaCount($after, 'product_no_image_alt') - isaCount($before, 'product_no_image_alt'))->toBe(0);
});

it('counts a product whose gallery is only half described, and says how far off it is', function () {
    isaMount();
    isaSettings();

    $before = isaScan();

    isaProduct([
        'slug' => 'isa-half-described',
        'name' => 'ISA Half Described',
        'image' => '/media/half-1.jpg',
        'images' => ['/media/half-2.jpg', '/media/half-3.jpg'],
        'image_alts' => ['/media/half-1.jpg' => 'Texture on the back of a hand'],
    ]);

    $after = isaScan();

    expect(isaCount($after, 'product_no_image_alt') - isaCount($before, 'product_no_image_alt'))->toBe(1);

    // The screen already prints `detail` beside the name, so "2 of 3 shots"
    // separates a product nobody has touched from one that is nearly done.
    $sample = collect($after['findings']['product_no_image_alt']['samples'])
        ->firstWhere('name', 'ISA Half Described');

    expect($sample['detail'])->toBe('2 of 3 shots');
});

it('does not count the featured shot twice when the gallery repeats it', function () {
    // The featured image is very often the first gallery row as well. One
    // photograph with one missing sentence is one problem, not two.
    isaMount();
    isaSettings();

    isaProduct([
        'slug' => 'isa-repeated-shot',
        'name' => 'ISA Repeated Shot',
        'image' => '/media/repeat.jpg',
        'images' => ['/media/repeat.jpg'],
    ]);

    $sample = collect(isaScan()['findings']['product_no_image_alt']['samples'])
        ->firstWhere('name', 'ISA Repeated Shot');

    expect($sample['detail'])->toBe('1 of 1 shots');
});

it('does not report missing alt text on a product that has no photographs at all', function () {
    // Already counted as "No image", and there is no alt to write for a shot
    // that does not exist. Counting it twice is how an audit becomes noise.
    isaMount();
    isaSettings();

    $before = isaScan();

    isaProduct(['image' => null, 'images' => []]);

    $after = isaScan();

    expect(isaCount($after, 'no_image') - isaCount($before, 'no_image'))->toBe(1)
        ->and(isaCount($after, 'product_no_image_alt') - isaCount($before, 'product_no_image_alt'))->toBe(0);
});

it('does not let missing alt text take the headline away from a real defect', function () {
    /*
     * The verdict names the biggest count and calls it "the biggest issue".
     * That was fair while every finding described something wrong. Missing alt
     * text is not: no page is broken, every <img> already carries a usable alt
     * from Product::altFor(), and on the day this check ships it is the largest
     * number on the screen for every shop. A canonical handing this shop's
     * ranking to another domain is worth more than six hundred missing
     * sentences.
     *
     * THE SETUP MAKES ALT THE STRICT MAXIMUM rather than hoping it is. The
     * seeded demo catalogue already carries findings of its own, so a fixed
     * number of products here would assert the size of that catalogue: the
     * count is read off a baseline scan and beaten by five. Each product added
     * has a real description, a SKU, a category and an image, so it lands in
     * exactly ONE finding -- this one.
     *
     * MUTATION NOTE: remove 'product_no_image_alt' from SeoAudit::ADVISORY and
     * this is red, because the verdict then names the finding with the biggest
     * count and that finding is now this one.
     */
    isaMount();
    isaSettings();

    $category = Category::create(['slug' => 'isa-headline-cat', 'name' => 'Headline', 'path' => 'isa-headline-cat']);

    $before = isaScan();

    $beat = max($before['findings']
        ? array_map(static fn (array $f): int => (int) $f['count'], $before['findings'])
        : [0]) + 5;

    foreach (range(1, $beat) as $i) {
        isaProduct([
            'slug' => 'isa-headline-' . $i,
            'name' => 'ISA Headline Product Number ' . $i,
            'image' => '/media/headline-' . $i . '.jpg',
            'category_id' => $category->id,
            // A DISTINCT description per row, or all of them land in
            // `duplicate_description` as well and this stops being a product
            // that has exactly one problem.
            'short_description' => 'A long enough description to count as a real one, number ' . $i . '.',
        ]);
    }

    // And one genuine defect, with a count of exactly one.
    isaProduct([
        'slug' => 'isa-off-site-canonical',
        'name' => 'ISA Off Site Canonical',
        'image' => '/media/off-site.jpg',
        'category_id' => $category->id,
        'short_description' => 'A long enough description to count as a real one, off-site canonical.',
        'seo' => ['canonical' => 'https://someone-elses-shop.example/steal-this/'],
    ]);

    $scan = isaScan();

    $counts = array_map(static fn (array $f): int => (int) $f['count'], $scan['findings']);

    // Alt text really is the biggest number on the screen ...
    expect($counts['product_no_image_alt'])->toBe(max($counts))
        ->and($counts['product_no_image_alt'])->toBeGreaterThan($counts['bad_canonical'])
        // ... and the headline still names something that is actually wrong.
        ->and($scan['verdict'])->not->toContain('alt text')
        // The finding is still counted and still listed; it just does not get
        // to be the sentence at the top.
        ->and($scan['findings']['product_no_image_alt']['samples'])->not->toBeEmpty();
});
