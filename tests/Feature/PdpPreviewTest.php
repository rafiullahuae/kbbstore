<?php

declare(strict_types=1);

/**
 * Five product-page designs, behind the admin, on the real catalogue. (Lane PDP)
 *
 * ── WHAT THIS FILE IS DEFENDING ─────────────────────────────────────────────
 *
 * Four of the five candidates are going to be deleted. That makes almost every
 * assertion about how one of them LOOKS worthless within the week — so nothing
 * here asserts a pixel. What it asserts is the five things that would still be
 * defects on the day the owner picks one:
 *
 *   §1  the shop did not move. /product/{slug}/ is byte-identical with these
 *       files present, and is byte-identical WITH A QUERY STRING ON IT — the
 *       `?layout=` map Lane PP put on that template and Lane PP2 had to delete
 *       is not coming back, and this is the assertion that says so.
 *   §2  the previews are GATED. Signed out is refused; a role without
 *       `catalog.view` is refused; the capability rule that governs them is the
 *       one the route's prefix claims.
 *   §3  every candidate renders, on an ordinary product, on a SET and on a
 *       sold-out product, and each one contains HIS LIST IN HIS ORDER.
 *   §4  the candidate name and the language are ALLOWLISTED. One names a view
 *       file and the other names a translation loader; neither may be whatever
 *       arrived in the URL.
 *   §5  no JavaScript anywhere in the five, and in particular none of the
 *       element-measuring APIs two other tests in this suite already forbid by
 *       name. The tab strip is a radio group and the read-more is a checkbox.
 *
 * ── THE MUTATIONS, ALL RUN ──────────────────────────────────────────────────
 *
 * Listed against each `it(...)` below. Each one was applied, the file run, and
 * the failure read.
 */

use App\Http\Controllers\Admin\PdpPreviewController;
use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductSetItem;
use App\Support\AdminCapabilities;
use Tests\Support\PdpPreviewRoutes;

beforeEach(function () {
    PdpPreviewRoutes::wire(app());

    $this->admin = AdminUser::create([
        'name' => 'Owner',
        'email' => 'owner@kbeautybliss.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
});

/** A product with a main shot and three gallery shots, so the strip renders. */
function pdpProduct(array $overrides = []): Product
{
    $brand = Brand::firstOrCreate(['slug' => 'pdp-brand'], ['name' => 'Anua']);

    return Product::create(array_merge([
        'name' => 'Heartleaf 77% Soothing Toner 250ml',
        'slug' => 'pdp-test-toner',
        'brand_id' => $brand->id,
        'price' => 9900,
        'status' => 'publish',
        'is_visible' => true,
        'stock_status' => 'instock',
        'type' => 'simple',
        'short_description' => 'A gentle daily toner built around 77% heartleaf extract.',
        'description' => '<p>The description tab.</p>',
        'ingredients' => '<p>Houttuynia Cordata Extract 77%.</p>',
        'how_to_use' => '<p>Morning and night.</p>',
        'image' => 'https://example.test/one.jpg',
        'images' => [
            'https://example.test/two.jpg',
            'https://example.test/three.jpg',
            'https://example.test/four.jpg',
        ],
    ], $overrides));
}

/**
 * An approved, non-demo review, so the thin rating bar has something true to
 * draw.
 *
 * `source` is deliberately NOT 'demo' and nothing is written to `demo_seed_log`
 * — App\Support\DemoReviews asks BOTH questions, and a row that failed either
 * is excluded from the storefront summary, which is the correct behaviour and
 * would leave this fixture with no bar at all.
 */
function pdpReview(Product $product, int $stars = 5): void
{
    \App\Models\Review::create([
        'product_id' => $product->id,
        'author_name' => 'Layla A.',
        'rating' => $stars,
        'title' => 'Calm skin',
        'content' => 'Three weeks in and the redness has gone down.',
        'status' => 'approved',
        'verified' => true,
        'source' => 'import',
    ]);
}

function pdpUrl(string $candidate, string $slug, string $query = ''): string
{
    return '/admin-api/catalog/pdp-preview/'.$candidate.'/'.$slug.$query;
}

/* ═══════════════════════════════════════════════════════════════════════════
   §1 — THE SHOP DID NOT MOVE, AND `?layout=` IS NOT COMING BACK
   ═══════════════════════════════════════════════════════════════════════════ */

it('serves the shipped product page byte for byte whatever query string is on it', function () {
    /*
     * ▲ THIS IS THE ASSERTION THIS WHOLE LANE IS SHAPED AROUND.
     *
     * The previous round put a `?layout=focus|editorial|compact` map into
     * resources/views/store/product.blade.php so the owner could see three
     * drawings on the real catalogue. He chose none of them, and Lane PP2 then
     * had to delete ~240 lines of `.pp-lay*` CSS and the map behind it —
     * docs/PP-PRODUCT-PAGE-PROPOSALS.md carries the SUPERSEDED banner that
     * records it. This lane was told, in as many words, not to resurrect it.
     *
     * So the drawings live at their own addresses and the shipped page reads no
     * query string this lane put there. Compared as BYTES, with the same three
     * candidate names the previews use plus the old `layout` key, because a
     * "looks the same" assertion would have passed for the thing that went
     * wrong last time too.
     *
     * MUTATION NOTE. Add `@if (request('layout') === 'ledger') <i>x</i> @endif`
     * anywhere in resources/views/store/product.blade.php and this goes red on
     * the first pair. RUN.
     */
    $product = pdpProduct();

    $plain = $this->get('/product/'.$product->slug.'/');
    $plain->assertOk();

    foreach (['?layout=ledger', '?layout=focus', '?pv=deck', '?candidate=counter', '?lang=ar'] as $query) {
        $withQuery = $this->get('/product/'.$product->slug.'/'.$query);

        expect($withQuery->getContent())->toBe(
            $plain->getContent(),
            'GET /product/'.$product->slug.'/'.$query.' must be byte-identical to the page with no query '
            .'string. A query parameter that changes this template is the ?layout= map Lane PP2 deleted.'
        );
    }
});

it('leaves the shipped product template with no reference to the previews at all', function () {
    /*
     * The other half of the same promise, read off the file rather than off a
     * response: this lane's five candidates are their own templates and the
     * shipped one does not know they exist.
     *
     * MUTATION NOTE. Add `{{-- pdp-preview --}}` to that template and this goes
     * red. RUN.
     */
    $shipped = (string) file_get_contents(resource_path('views/store/product.blade.php'));

    expect(str_contains($shipped, 'pdp-preview'))->toBeFalse(
        'resources/views/store/product.blade.php must not reference the preview templates.'
    );
    expect(str_contains($shipped, 'pvCandidate'))->toBeFalse(
        'resources/views/store/product.blade.php must not reference the preview payload.'
    );
});

/* ═══════════════════════════════════════════════════════════════════════════
   §2 — GATED, AND BY THE RULE THE PREFIX CLAIMS
   ═══════════════════════════════════════════════════════════════════════════ */

it('refuses a signed-out visitor both the chooser and a drawing', function () {
    /*
     * MUTATION NOTE. Drop `auth:admin` from PdpPreviewRoutes::STACK and both of
     * these answer 200. RUN.
     */
    pdpProduct();

    $this->get('/admin-api/catalog/pdp-preview')->assertRedirect();
    $this->get(pdpUrl('ledger', 'pdp-test-toner'))->assertRedirect();
});

it('is governed by catalog.view, which is the rule its prefix already carries', function () {
    /*
     * The route file says its prefix was chosen FOR the capability. This asks
     * App\Support\AdminCapabilities itself rather than trusting the comment: a
     * prefix of this lane's own would fall through to the closed owner-only
     * default, and a screen that fails closed on a host with no shell is not a
     * thing to discover after a package has shipped.
     *
     * MUTATION NOTE. Change the route prefix to `/pdp-preview` in
     * routes/pdp-preview-admin.php and this reads the owner-only default
     * instead of 'catalog.view'. RUN.
     */
    expect(AdminCapabilities::forPath('GET', 'admin-api/catalog/pdp-preview'))
        ->toBe('catalog.view');
    expect(AdminCapabilities::forPath('GET', 'admin-api/catalog/pdp-preview/ledger/x'))
        ->toBe('catalog.view');
});

/* ═══════════════════════════════════════════════════════════════════════════
   §3 — EVERY CANDIDATE RENDERS, AND CARRIES HIS LIST IN HIS ORDER
   ═══════════════════════════════════════════════════════════════════════════ */

it('renders all five candidates on an ordinary product', function () {
    $product = pdpProduct();

    foreach (array_keys(PdpPreviewController::CANDIDATES) as $candidate) {
        $this->actingAs($this->admin, 'admin')
            ->get(pdpUrl($candidate, $product->slug))
            ->assertOk()
            ->assertSee($product->name);
    }
});

it('puts the page in the order he asked for, in every candidate', function () {
    /*
     * ── HIS ORDER, QUOTED ────────────────────────────────────────────────────
     *
     *   "image, then beautiful gallery, then small brand name with link, then
     *    product name, and right side cut price and actual price ... and then
     *    small thin rating bar, and then 2-3 lines short description with fade
     *    read more. and then bundles purchase bars ... and then quantity + add
     *    to cart button row. and then product tabs ... then Authenticity line,
     *    delivery line , and payment icons."
     *
     * Asserted as the ORDER OF FIRST APPEARANCE in the HTML, which is the order
     * a phone reads and the order a screen reader reads. Candidate C reaches a
     * three-column desktop by GRID PLACEMENT precisely so that this stays true
     * of the document; if it had moved the markup instead, this is the test
     * that would have caught it.
     *
     * ▲ NOT positions on a rendered page. This asserts the document, not the
     *   layout — CLAUDE.md forbids measuring layout in the product and this
     *   suite has no browser. The pictures are the layout evidence; see
     *   docs/lane-pdp-shots/.
     *
     * MUTATION NOTE. Move `@include('store.pdp-preview.parts.buyrow')` above
     * the options include in any one candidate and that candidate fails, naming
     * the pair that is out of order. RUN, on `deck`.
     */
    $product = pdpProduct();
    pdpReview($product);

    $markers = [
        'the gallery frame' => 'class="gmain"',
        'the thumbnail strip' => 'id="gthumbs"',
        'the brand' => 'class="pv-brand"',
        'the product name' => 'class="pv-title"',
        'the price pair' => 'class="pv-money"',
        'the rating bar' => 'class="pv-rate"',
        'the short description' => 'class="pv-blurb"',
        'the bundle bars' => 'class="variants pv-variants"',
        'quantity and add to cart' => 'class="pv-buyrow"',
        'the product tabs' => 'class="pv-tabs ',
        'the assurances' => 'class="pv-assure"',
        'the payment marks' => 'class="pv-pay"',
    ];

    foreach (array_keys(PdpPreviewController::CANDIDATES) as $candidate) {
        $html = $this->actingAs($this->admin, 'admin')
            ->get(pdpUrl($candidate, $product->slug))
            ->assertOk()
            ->getContent();

        $seen = [];

        foreach ($markers as $what => $needle) {
            $at = strpos($html, $needle);

            expect($at)->not->toBeFalse($candidate.' must draw '.$what.' ('.$needle.')');

            $seen[$what] = $at;
        }

        $names = array_keys($markers);

        for ($i = 1; $i < count($names); $i++) {
            expect($seen[$names[$i]])->toBeGreaterThan(
                $seen[$names[$i - 1]],
                $candidate.': '.$names[$i].' must come after '.$names[$i - 1].' in the document'
            );
        }
    }
});

it('draws the price pair and the thin rating bar from the product, not from the columns', function () {
    /*
     * The struck figure is Product::compareAtPrice() and the bar's fill is
     * ($rating / 5) as a percentage written server-side — both facts about the
     * product. The rating bar draws only when there are approved, non-demo
     * reviews, because the shipped page refuses to print a rating it does not
     * have and a drawing that invents one is a drawing of a shop that lies.
     *
     * MUTATION NOTE. Drop the `@if ($rcount)` from parts/rating.blade.php and
     * the unreviewed product below renders a `pv-rate` with "0.0" in it. RUN.
     */
    $product = pdpProduct(['sale_price' => 7425]);

    $html = $this->actingAs($this->admin, 'admin')
        ->get(pdpUrl('ledger', $product->slug))
        ->getContent();

    expect($html)->toContain('<s>');
    expect($html)->toContain('pv-off');
    // No reviews on this fixture, so no bar at all.
    expect(str_contains($html, 'class="pv-rate"'))->toBeFalse(
        'a product with no approved reviews must draw no rating bar'
    );
});

it('shows a set its contents instead of bundle bars, in every candidate', function () {
    /*
     * BundleService::forProduct() answers an empty array for a set and a set is
     * never $isVar, so neither branch of parts/options.blade.php draws anything
     * — and partials/set-contents-panel, which is the "hanging photos"
     * treatment the owner chose, is included in that slot instead. A drawing
     * that lost the set panel would be proposing to delete a feature he asked
     * for.
     *
     * MUTATION NOTE. Remove the @include of partials.set-contents-panel from
     * parts/options.blade.php and this goes red on the first candidate. RUN.
     */
    $brand = Brand::firstOrCreate(['slug' => 'pdp-brand'], ['name' => 'Anua']);

    $member = Product::create([
        'name' => 'Rice Toner 150ml', 'slug' => 'pdp-member', 'brand_id' => $brand->id,
        'price' => 7900, 'status' => 'publish', 'is_visible' => true,
        'stock_status' => 'instock', 'type' => 'simple',
    ]);

    $set = Product::create([
        'name' => 'Glow Ritual Set', 'slug' => 'pdp-set', 'brand_id' => $brand->id,
        'price' => 21500, 'status' => 'publish', 'is_visible' => true,
        'stock_status' => 'instock', 'type' => 'set',
        'image' => 'https://example.test/s1.jpg',
    ]);

    ProductSetItem::create([
        'set_product_id' => $set->id, 'member_product_id' => $member->id,
        'quantity' => 1, 'position' => 0,
    ]);

    foreach (array_keys(PdpPreviewController::CANDIDATES) as $candidate) {
        $html = $this->actingAs($this->admin, 'admin')
            ->get(pdpUrl($candidate, $set->slug))
            ->assertOk()
            ->getContent();

        /* ▲ `ksl-rows`, NOT the member's NAME. The name is in this document
              anyway — Store\ProductController publishes a set's contents in the
              schema.org block in <head>, so `toContain($member->name)` passed
              with the panel's @include DELETED. Measured: mutation M4 in
              docs/PDP-PRODUCT-PAGE-DESIGNS.md was GREEN until this line named
              a class only the panel draws. An assertion that a bug walks
              straight past is worse than no assertion, because it is counted. */
        expect($html)->toContain('ksl-rows');
        expect($html)->toContain($member->name);
        expect(str_contains($html, 'class="variants pv-variants"'))->toBeFalse(
            $candidate.' must not draw bundle bars on a set'
        );
    }
});

it('draws a sold-out product with a dead button and a sold-out line', function () {
    $product = pdpProduct(['slug' => 'pdp-gone', 'stock_status' => 'outofstock']);

    $html = $this->actingAs($this->admin, 'admin')
        ->get(pdpUrl('dossier', $product->slug))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('pv-stock out');
    expect($html)->toContain('disabled');
});

/* ═══════════════════════════════════════════════════════════════════════════
   §4 — THE TWO STRINGS THAT ARRIVE IN THE URL ARE ALLOWLISTED
   ═══════════════════════════════════════════════════════════════════════════ */

it('404s a candidate name that is not one of the five', function () {
    /*
     * The candidate names a VIEW FILE. It is checked against
     * PdpPreviewController::CANDIDATES before it is used for anything, and the
     * answer for a name that is not in that map is 404 rather than a fallback
     * to the first candidate — a typo should say so rather than quietly
     * photograph the wrong design, and a URL naming a candidate that has been
     * deleted must stop working the day it is deleted.
     *
     * MUTATION NOTE. Delete the `array_key_exists` guard in
     * PdpPreviewController::show() and the first case below becomes a 500 from
     * the view finder rather than a 404. RUN.
     */
    pdpProduct();

    foreach (['focus', 'editorial', 'compact', 'x'] as $bogus) {
        $this->actingAs($this->admin, 'admin')
            ->get(pdpUrl($bogus, 'pdp-test-toner'))
            ->assertNotFound();
    }
});

it('ignores a language that this shop does not speak', function () {
    /*
     * `?lang=` reaches app()->setLocale(), which names a translation loader.
     * App\Support\Locale::isSupported() is the allowlist; anything else leaves
     * the request on the default locale rather than on whatever arrived.
     *
     * MUTATION NOTE. Remove the `Locale::isSupported($lang)` half of the
     * condition in PdpPreviewController::show() and the locale below reads
     * '../../etc' instead of 'en'. RUN.
     */
    $product = pdpProduct();

    foreach (['../../etc/passwd', 'zz', 'en-US<script>'] as $bogus) {
        $this->actingAs($this->admin, 'admin')
            ->get(pdpUrl('counter', $product->slug, '?lang='.urlencode($bogus)))
            ->assertOk();

        expect(app()->getLocale())->toBe('en');
    }
});

/* ═══════════════════════════════════════════════════════════════════════════
   §5 — NO JAVASCRIPT, AND NONE OF THE MEASURING APIS
   ═══════════════════════════════════════════════════════════════════════════ */

it('contains no script and no element-measuring API anywhere in the five designs', function () {
    /*
     * CLAUDE.md rule 4: "No JavaScript that measures layout — this project
     * sizes with calc() for a reason, and two tests forbid the element-measuring
     * APIs by name." A scrollable tab row and a fading read-more are the two
     * most tempting places in the whole shop to reach for one of these, which is
     * why they are named here rather than left to the general sweep: the tab
     * switch is a radio group and the read-more is a checkbox, and this is the
     * test that stops either from quietly becoming a script later.
     *
     * MUTATION NOTE. Put `<script>document.querySelector(".pv-tab")
     * .getBoundingClientRect()</script>` in parts/tabs.blade.php and this names
     * both the file and the API. RUN.
     */
    $forbidden = [
        'getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'offsetTop', 'offsetLeft',
        'clientWidth', 'clientHeight', 'scrollWidth', 'scrollHeight',
        'getComputedStyle', 'ResizeObserver', 'IntersectionObserver',
        '<script', 'onclick=', 'addEventListener',
    ];

    $dir = resource_path('views/store/pdp-preview');
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

    foreach ($files as $file) {
        if (! $file->isFile() || ! str_ends_with((string) $file, '.blade.php')) {
            continue;
        }

        $source = (string) file_get_contents((string) $file);

        /* COMMENTS STRIPPED FIRST. Every one of these files argues in a Blade
           comment about why it does NOT measure anything, and several of them
           name the APIs in order to say so. A scanner that reads the prose it
           is told to trust would fail on the explanation rather than on a
           defect. */
        $code = preg_replace('/\{\{--.*?--\}\}/s', '', $source);
        $code = preg_replace('#/\*.*?\*/#s', '', (string) $code);

        foreach ($forbidden as $needle) {
            expect(str_contains((string) $code, $needle))->toBeFalse(
                basename((string) $file).' must not contain '.$needle
                .' — these five designs carry no JavaScript at all'
            );
        }
    }
});

it('renders no script tag inside the preview root', function () {
    /*
     * The file scan above reads the sources; this reads the ANSWER, which is
     * what a browser gets. Belt and braces, and it also covers anything a part
     * might pull in from outside this lane's directory.
     *
     * MUTATION NOTE. Add `<script>1</script>` to _layout.blade.php inside the
     * .pv div and this reads 1. RUN.
     */
    $product = pdpProduct();

    $html = $this->actingAs($this->admin, 'admin')
        ->get(pdpUrl('marquee', $product->slug))
        ->getContent();

    $root = substr($html, (int) strpos($html, '<div class="pv pv-'));

    expect(substr_count(substr($root, 0, (int) strpos($root, '</main>') ?: strlen($root)), '<script'))->toBe(
        0,
        'the five designs must render no <script> of their own'
    );
});

/* ═══════════════════════════════════════════════════════════════════════════
   §6 — WHAT THE INTEGRATOR HAS TO WIRE, PINNED AS THE FINISHED STATE
   ═══════════════════════════════════════════════════════════════════════════ */

it('requires routes/pdp-preview-admin.php exactly once', function () {
    /*
     * ▲ THIS IS EXPECTED TO BE RED UNTIL THE INTEGRATOR WIRES IT, and that is
     *   what it is for. CLAUDE.md names the mistake it is written to avoid and
     *   names the three times it has been made: a lane that may not edit
     *   routes/web.php writes `expect($web)->not->toContain(...)` to prove it
     *   did not quietly wire itself up, and THAT assertion goes red the moment
     *   the integrator does the one thing the lane asked for. This pins the
     *   state that can actually regress instead —
     *
     *       0  is "built, never wired up", which is the shape this repository
     *          keeps finding;
     *       2  mounts every preview route twice.
     *
     *   Nothing is untested in the meantime: every case above drives the real
     *   route file through Tests\Support\PdpPreviewRoutes.
     *
     * toBe($expected, $message) AND NEVER ->toContain($needle, $message).
     * Pest's toContain() is VARIADIC, so a message passed as its second
     * argument becomes a second needle and the assertion can then only fail.
     *
     * MUTATION NOTE. With the require added, this reads 1; paste it twice and
     * it reads 2. RUN (it reads 0 in this lane's own worktree, which is the
     * state this test exists to end).
     */
    $web = (string) file_get_contents(base_path('routes/web.php'));

    expect(substr_count($web, "require __DIR__.'/pdp-preview-admin.php';"))->toBe(
        1,
        'routes/web.php must require routes/pdp-preview-admin.php EXACTLY ONCE, inside the existing '
        ."admin-api group — the one opened by Route::prefix('admin-api')"
        .'->middleware(NoStoreAdminApi::class) — beside the other catalog route files. '
        .'The package that carries it also needs a clear_caches_* migration: routes/web.php is '
        .'compiled on the server and these routes do not exist until bootstrap/cache/routes-*.php is gone.'
    );
});
