<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductSetItem;
use App\Services\BundleService;
use App\Services\SettingsService;
use App\Support\SetPanelDesign;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FOUR DRAWINGS OF A SET'S CONTENTS, AND NO BULK-QUANTITY STRIP ON A SET.
 * (Lane SF)
 *
 * ── THE TWO DEFECTS THIS FILE IS ABOUT, AS THEY LOOKED ON THE SHOP ─────────
 *
 * 1. A SET WAS OFFERED A QUANTITY BUNDLE. BundleService::forProduct() priced
 *    its tiers for any product carrying a price, so /product/{set-slug}/ drew
 *    "1 unit / 2-pack bundle / 3-pack bundle — Save 10%" under the price of a
 *    curated gift box. The owner: "on desktop set product page, there will be
 *    no bulk quantity purchase strips." It also competed for the exact width
 *    the contents list wants — the two blocks sit one above the other.
 *
 * 2. THERE WAS ONE DRAWING OF THE CONTENTS AND NO WAY TO SEE ANOTHER. The
 *    owner asked to be shown the options and to pick: "present it beautifully.
 *    better to preview me the set product front-end preview. so i can choose
 *    from." Four designs now exist and Appearance → Set contents chooses
 *    between them.
 *
 * ── AND THE RULE THAT GOVERNS BOTH ────────────────────────────────────────
 *
 * CLAUDE.md rule 1. The setting SHIPS AT THE VALUE THE PAGE ALREADY HAS, so a
 * shop that applies this package and opens nothing sees the same block it saw
 * before. Several cases below exist only to hold that line.
 */
function sfBrand(string $name): Brand
{
    return Brand::create(['name' => $name, 'slug' => Str::slug($name).'-'.Str::random(5)]);
}

function sfProduct(string $name, int $fils, array $overrides = []): Product
{
    return Product::create(array_merge([
        'slug' => 'sf-'.Str::slug($name).'-'.Str::random(6),
        'name' => $name,
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => $fils,
        'stock_status' => 'instock',
        'image' => '/img/'.Str::slug($name).'.jpg',
    ], $overrides));
}

/** @param list<array{0: Product, 1: int}> $members */
function sfSet(int $setFils, array $members, array $overrides = []): Product
{
    $set = Product::create(array_merge([
        'slug' => 'sf-set-'.Str::random(8),
        'name' => 'Glow Ritual Set',
        'type' => 'set',
        'status' => 'publish',
        'is_visible' => true,
        'price' => $setFils,
        'stock_status' => 'instock',
        'image' => '/img/glow-ritual-set.jpg',
    ], $overrides));

    $position = 0;

    foreach ($members as [$product, $quantity]) {
        ProductSetItem::create([
            'set_product_id' => $set->id,
            'member_product_id' => $product->id,
            'quantity' => $quantity,
            'position' => $position++,
        ]);
    }

    return $set->fresh();
}

/** Choose a design the way the admin screen does, and clear the memo. */
function sfChoose(string $design): void
{
    app(SettingsService::class)->set(SetPanelDesign::KEY, $design);
    SettingsService::forgetMemo();
}

/* ═════════════════════════════════════ 1. no bulk strip on a set's page ═══ */

it('offers no quantity-bundle tiers for a set', function () {
    /*
     * MUTATION NOTE. Delete the `if ($product->isSet()) { return []; }` guard
     * from BundleService::forProduct() and this is red: the set gets the same
     * three default tiers an ordinary product gets. RUN — and it was.
     */
    $set = sfSet(14000, [[sfProduct('Toner', 9000), 1], [sfProduct('Serum', 7550), 1]]);

    expect(app(BundleService::class)->forProduct($set->fresh()))->toBe(
        [],
        'A set must be offered no quantity bundles at all.'
    );
});

it('draws no bulk-quantity strip on a set page and still draws one on an ordinary product', function () {
    /*
     * THE PAIR IS THE POINT. Half of this lane's job is a visible removal and
     * the other half is CLAUDE.md rule 1 — nothing that already works may
     * change — so the removal and the thing that must survive it are asserted
     * in one case, against one run of the same template.
     *
     * MUTATION NOTE. Remove the guard from BundleService::forProduct() and the
     * FIRST half is red (the set draws "2-pack bundle"). Move the guard up to
     * `enabled()` instead and the SECOND half is red (the ordinary product
     * loses its strip too). RUN, both.
     */
    $set = sfSet(14000, [[sfProduct('Panel toner', 9000), 1], [sfProduct('Panel serum', 7550), 1]]);
    $plain = sfProduct('Ordinary Rice Toner', 8900);

    $setHtml = $this->get('/product/'.$set->slug.'/')->assertOk()->getContent();
    $plainHtml = $this->get('/product/'.$plain->slug.'/')->assertOk()->getContent();

    // 'bundle' appears in BundleService::DEFAULT_TIERS' labels — "2-pack
    // bundle", "3-pack bundle" — which is what the strip prints.
    expect(str_contains($setHtml, '2-pack bundle'))->toBeFalse(
        "A set's product page must not offer the same set at a bulk rate."
    );
    expect(str_contains($setHtml, '3-pack bundle'))->toBeFalse(
        "A set's product page must not offer the same set at a bulk rate."
    );

    expect(str_contains($plainHtml, '2-pack bundle'))->toBeTrue(
        'An ordinary product must keep the quantity-bundle strip it has always had.'
    );
    expect(str_contains($plainHtml, '3-pack bundle'))->toBeTrue(
        'An ordinary product must keep the quantity-bundle strip it has always had.'
    );
});

it('leaves the pricing path alone, so no basket is repriced by this change', function () {
    /*
     * ▲ THE HALF THAT WAS DELIBERATELY NOT CHANGED, PINNED SO A LATER LANE
     *   CANNOT CHANGE IT BY ACCIDENT WHILE "FINISHING" THE ONE ABOVE. ▲
     *
     * forProduct() is the DISPLAY question — which offers does this product
     * carry. unitFor()/totalFor()/discountFor() are the PRICING path, and
     * CartService::add() reprices every basket line through them. Adding a set
     * branch there would change what a basket already holding three of a set
     * is charged, silently, on applying a package. That is exactly what
     * CLAUDE.md rule 1 forbids, so it was left, and this lane's report says so
     * in as many words rather than leaving it to be discovered.
     *
     * MUTATION NOTE. Add `if ($product->isSet())` anywhere on the pricing path
     * — or make discountFor() answer 0 for a set — and this is red. RUN.
     */
    $bundles = app(BundleService::class);

    // BundleService::DEFAULT_TIERS: 3 units at 10% off. 10000 fils -> 9000.
    expect($bundles->unitFor(10000, 3))->toBe(9000)
        ->and($bundles->totalFor(10000, 3))->toBe(27000)
        ->and($bundles->discountFor(3))->toBe(10.0);
});

/* ══════════════════════════════════════════ 2. the setting and its default ═══ */

it('ships at the design the page already drew, so applying the package moves nothing', function () {
    /*
     * CLAUDE.md rule 1, asserted three ways: the constant, what current()
     * answers with no row in `settings` at all, and what the page actually
     * renders.
     *
     * MUTATION NOTE. Change SetPanelDesign::DEFAULT to any other key and all
     * three halves are red. RUN.
     */
    expect(SetPanelDesign::DEFAULT)->toBe(SetPanelDesign::GRID);

    SettingsService::forgetMemo();
    expect(SetPanelDesign::current())->toBe(
        'grid',
        'With nothing stored, the shop must draw the compact grid it drew before this lane.'
    );

    $set = sfSet(14000, [[sfProduct('Default toner', 9000), 1], [sfProduct('Default serum', 7550), 1]]);
    $html = $this->get('/product/'.$set->slug.'/')->assertOk()->getContent();

    expect(str_contains($html, 'class="ksp-grid"'))->toBeTrue(
        'The unconfigured shop must render the compact grid.'
    );
});

it('stores one of its own options or the default, whatever is in the settings table', function () {
    /*
     * CLAUDE.md rule 5: "A select stores one of its own options or the
     * default." The load-bearing half is the READ, because a row can arrive in
     * `settings` from an import or a restored backup without ever passing
     * through the controller.
     *
     * MUTATION NOTE. Make SetPanelDesign::current() return the raw setting and
     * this is red on every junk value below. RUN.
     */
    foreach (['', 'GRID', 'nope', '../../etc/passwd', 'partials.set-contents.grid'] as $junk) {
        sfChoose($junk);

        expect(SetPanelDesign::current())->toBe(
            'grid',
            "A stored value of '{$junk}' must draw the default design, not be believed."
        );
    }

    // And the four real ones are believed.
    foreach (array_keys(SetPanelDesign::DESIGNS) as $key) {
        sfChoose($key);
        expect(SetPanelDesign::current())->toBe($key);
    }
});

it('has a partial for every design and a design for every partial', function () {
    /*
     * The two lists that can drift. A key added to DESIGNS with no template
     * renders an empty block; a template added with no key is dead code the
     * screen never offers.
     *
     * MUTATION NOTE. Add a fifth key to SetPanelDesign::DESIGNS without adding
     * its partial, or delete one of the four files, and this is red by name.
     * RUN.
     */
    foreach (array_keys(SetPanelDesign::DESIGNS) as $key) {
        expect(file_exists(resource_path("views/partials/set-contents/{$key}.blade.php")))->toBeTrue(
            "Design '{$key}' has no resources/views/partials/set-contents/{$key}.blade.php."
        );
    }

    foreach (glob(resource_path('views/partials/set-contents/*.blade.php')) ?: [] as $file) {
        $key = basename($file, '.blade.php');

        // footing.blade.php is the shared tail every design ends with, not a
        // design; it is named here rather than pattern-matched so ADDING a
        // second shared partial has to be a deliberate edit to this line.
        if ($key === 'footing') {
            continue;
        }

        expect(array_key_exists($key, SetPanelDesign::DESIGNS))->toBeTrue(
            "resources/views/partials/set-contents/{$key}.blade.php is not a design in SetPanelDesign::DESIGNS."
        );
    }
});

/* ════════════════════════════════════════ 3. what each design has to do ═══ */

/**
 * Every design, against the same set, asserted on the same four rules.
 *
 * A dataset rather than four copies: the rules below are not per-design
 * opinions, they are the contract a design has to meet to be offered at all,
 * and four copies of them is three places for one to be quietly dropped.
 */
it('names every member, with brand, quantity and its own price, in design: {0}', function (string $design) {
    /*
     * MUTATION NOTE. Delete any one of the four design partials' name, brand,
     * quantity or price interpolations and the case for that design is red
     * while the other three stay green. RUN (checked against `list`, by
     * removing its .ksl-pr span).
     */
    sfChoose($design);

    $brand = sfBrand('Anua');
    $toner = sfProduct('Heartleaf Soothing Toner', 9000, ['brand_id' => $brand->id]);
    $serum = sfProduct('Azelaic Acid Serum', 7550, ['brand_id' => $brand->id]);

    $set = sfSet(14000, [[$toner, 2], [$serum, 1]]);
    $html = $this->get('/product/'.$set->slug.'/')->assertOk()->getContent();

    expect(str_contains($html, 'Heartleaf Soothing Toner'))->toBeTrue("{$design}: must name every member.");
    expect(str_contains($html, 'Azelaic Acid Serum'))->toBeTrue("{$design}: must name every member, not only the first.");
    expect(str_contains($html, 'Anua'))->toBeTrue("{$design}: must print each member's brand.");
    expect(str_contains($html, '2&times;'))->toBeTrue("{$design}: a member whose quantity is 2 must say so.");

    // 2 x 9000 + 1 x 7550 = 25550 separately; the set is 14000; saving 11550.
    expect(str_contains($html, 'You save'))->toBeTrue("{$design}: a set cheaper than its parts must say what it saves.");
})->with(['grid', 'list', 'cards', 'stack']);

it('links a published member and never links an unpublished one, in design: {0}', function (string $design) {
    /*
     * ▲ THE ONE A SHOPPER MEETS AS A 404. ▲
     *
     * A set's members are its own products, and one of them can be a draft, be
     * hidden, or be scheduled for next week. SetContents::memberIsLive() asks
     * the three conditions Product::scopeVisible() asks and FAILS CLOSED; every
     * design has to honour the answer, and a design that linked
     * unconditionally would send a shopper who arrived from Google to a 404.
     *
     * MUTATION NOTE. In any design partial, drop the `$...Link` condition and
     * always emit the <a> — that design's case goes red on the draft's URL
     * appearing in an href. RUN (checked against `cards`).
     */
    sfChoose($design);

    $live = sfProduct('Published Cleanser', 6000);
    $draft = sfProduct('Unpublished Cleanser', 6500, ['status' => 'draft']);

    $set = sfSet(9000, [[$live, 1], [$draft, 1]]);
    $html = $this->get('/product/'.$set->slug.'/')->assertOk()->getContent();

    expect(str_contains($html, 'Unpublished Cleanser'))->toBeTrue(
        "{$design}: an unpublished member is still IN the box and must still be named."
    );
    expect(str_contains($html, 'href="'.$live->url().'"'))->toBeTrue(
        "{$design}: a published member must be a link to its own page."
    );
    expect(str_contains($html, 'href="'.$draft->url().'"'))->toBeFalse(
        "{$design}: an unpublished member must NOT be linked — that href is a 404."
    );
})->with(['grid', 'list', 'cards', 'stack']);

it('never claims a saving on an unpriced set, in design: {0}', function (string $design) {
    /*
     * The owner's first set, half filled in, printed "Bought separately: AED
     * 806.00 / Set price: AED 0.00 / You save AED 806.00" — the arithmetic
     * right and the sentence false. SetContents::fromProduct() floors that to
     * zero, and partials/set-contents/footing.blade.php is the ONE place any
     * design decides whether to print the sentence.
     *
     * MUTATION NOTE. Remove the `$setPrice <= 0 ? 0 :` from SetContents, or
     * the `saving > 0` guard from footing.blade.php, and all four cases are
     * red at once — which is the property having one writer buys. RUN.
     */
    sfChoose($design);

    $set = sfSet(0, [[sfProduct('Unpriced member A', 40000), 1], [sfProduct('Unpriced member B', 40600), 1]]);
    $html = $this->get('/product/'.$set->slug.'/')->assertOk()->getContent();

    expect(str_contains($html, 'You save'))->toBeFalse(
        "{$design}: a set with no price saves nobody anything and must not say it does."
    );
})->with(['grid', 'list', 'cards', 'stack']);

it('draws a member with no picture without a broken image, in design: {0}', function (string $design) {
    /*
     * MUTATION NOTE. Drop the @else branch in any design partial and that
     * design emits `<img src="">` for a pictureless member, which browsers
     * resolve to the current page. RUN (checked against `stack`).
     */
    sfChoose($design);

    $withPhoto = sfProduct('Has A Photograph', 5000);
    $without = sfProduct('Has No Photograph', 5500, ['image' => null]);

    $set = sfSet(9000, [[$withPhoto, 1], [$without, 1]]);
    $html = $this->get('/product/'.$set->slug.'/')->assertOk()->getContent();

    expect(str_contains($html, 'Has No Photograph'))->toBeTrue("{$design}: a pictureless member is still named.");
    expect(str_contains($html, 'src=""'))->toBeFalse("{$design}: must never emit an empty img src.");
    expect(str_contains($html, "src='"."'"))->toBeFalse("{$design}: must never emit an empty img src.");
})->with(['grid', 'list', 'cards', 'stack']);

it('sizes with CSS and measures nothing in script, in design: {0}', function (string $design) {
    /*
     * CLAUDE.md rule 4: "No JavaScript that measures layout — this project
     * sizes with calc() for a reason, and two tests forbid the
     * element-measuring APIs by name."
     *
     * Asserted on the SOURCE, because a design partial that carried a measuring
     * script would ship it inline and no rendered assertion would name it.
     *
     * ▲ THE COMMENTS ARE STRIPPED FIRST, AND THAT IS NOT TIDINESS. ▲
     *
     * A source scan finds its own explanation. The first run of this case was
     * red on `list` for the sentence in that partial's own docblock — "no
     * getBoundingClientRect, no offsetWidth" — which is the file PROMISING not
     * to do the thing, read as the file doing it. Blade comments and CSS
     * comments both go, so what is scanned is only what executes.
     *
     * MUTATION NOTE. Add `el.getBoundingClientRect()` to any design partial and
     * that design's case is red. RUN — and it was, both before and after the
     * stripping was added.
     */
    $src = (string) file_get_contents(resource_path("views/partials/set-contents/{$design}.blade.php"));

    $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $src);
    $src = (string) preg_replace('~/\*.*?\*/~s', '', $src);

    foreach (['getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientWidth',
        'ResizeObserver', 'requestAnimationFrame', '<script'] as $banned) {
        expect(str_contains($src, $banned))->toBeFalse(
            "{$design}: a set design must size with CSS. Found '{$banned}' in its partial."
        );
    }
})->with(['grid', 'list', 'cards', 'stack']);

it('is flat in the number of members, in design: {0}', function (string $design) {
    /*
     * CLAUDE.md rule 4 again, and the one a ceiling cannot catch: a page doing
     * one query per member passes any ceiling on a small fixture. So this
     * measures the SHAPE — three members and twelve, on the same design, and
     * the difference must be ZERO.
     *
     * MUTATION NOTE. Delete the `SetEagerLoad::on([$product])` line from
     * Store\ProductController::show() and every design's case is red by nine.
     * RUN.
     */
    sfChoose($design);

    $small = sfSet(10000, array_map(fn ($i) => [sfProduct('Flat small '.$i, 1000 + $i), 1], range(1, 3)));
    $large = sfSet(10000, array_map(fn ($i) => [sfProduct('Flat large '.$i, 1000 + $i), 1], range(1, 12)));

    $count = function (string $url): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get($url)->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $n;
    };

    // Warm whatever a first request in this process warms, so the two figures
    // compare the page rather than the boot.
    $this->get('/product/'.$small->slug.'/')->assertOk();

    $three = $count('/product/'.$small->slug.'/');
    $twelve = $count('/product/'.$large->slug.'/');

    expect($twelve)->toBe(
        $three,
        "{$design}: twelve members must cost what three cost. Three: {$three}. Twelve: {$twelve}."
    );
})->with(['grid', 'list', 'cards', 'stack']);

it('draws the design that is chosen and none of the others, in design: {0}', function (string $design) {
    /*
     * The switch itself. Each design has one class that appears in no other:
     * .ksp-grid, .ksl-r, .ksc-c, .kss-chain. Choosing one must draw that one
     * and only that one — a chain of @if branches that fell through would draw
     * two.
     *
     * MUTATION NOTE. Change any @elseif in set-contents-panel.blade.php to @if
     * with its own @endif and the excluded designs start rendering alongside
     * the chosen one. RUN.
     */
    $marks = ['grid' => 'ksp-grid', 'list' => 'ksl-r', 'cards' => 'ksc-c', 'stack' => 'kss-chain'];

    sfChoose($design);

    $set = sfSet(14000, [[sfProduct('Switch toner', 9000), 1], [sfProduct('Switch serum', 7550), 1]]);
    $html = $this->get('/product/'.$set->slug.'/')->assertOk()->getContent();

    foreach ($marks as $key => $mark) {
        expect(str_contains($html, 'class="'.$mark))->toBe(
            $key === $design,
            $key === $design
                ? "{$design}: the chosen design must be drawn (looked for class=\"{$mark}\")."
                : "{$design}: design '{$key}' must NOT be drawn alongside it."
        );
    }
})->with(['grid', 'list', 'cards', 'stack']);

/* ═══════════════════════════════════════════════════ 4. the admin screen ═══ */

it('is mounted exactly once by the admin console', function () {
    /*
     * ▲ THE FINISHED STATE, NOT AN ABSENCE. ▲
     *
     * CLAUDE.md at length: a lane that cannot edit admin/app.blade.php wants to
     * prove it did not quietly wire itself up, and the obvious assertion —
     * `not->toContain('set-contents-screen')` — is correct in this worktree and
     * GOES RED THE MOMENT THE INTEGRATOR DOES THE ONE THING THIS LANE ASKED
     * FOR. It happened three times in one day.
     *
     * So this pins ONE, which is the state that can actually regress in both
     * directions: zero is "built, never wired up", and two registers the
     * sidebar entry twice and wraps window.go around its own wrapper.
     *
     * It is SKIPPED until the integrator wires it, so this lane's suite is
     * green today and this case starts asserting the moment the include lands.
     *
     * MUTATION NOTE. Paste the @include line twice into app.blade.php and this
     * is red at 2. RUN.
     */
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    $mounts = substr_count($app, "@include('admin.partials.set-contents-screen')");

    if ($mounts === 0) {
        $this->markTestSkipped(
            'Appearance → Set contents is not wired into admin/app.blade.php yet. '
            ."The integrator adds exactly one line:\n"
            ."    @include('admin.partials.set-contents-screen')"
        );
    }

    expect($mounts)->toBe(1, 'The Set contents screen must be included exactly once.');
});

it('mounts its routes exactly once', function () {
    /*
     * The same shape for routes/web.php, and the same reason. Skipped until the
     * integrator wires it; pinned at ONE once he has. Laravel takes the LAST
     * registration of a path, so a file required twice is not an error that
     * shows — it is three duplicate rows in the route table.
     */
    $web = (string) file_get_contents(base_path('routes/web.php'));

    $mounts = substr_count($web, "require __DIR__.'/set-contents-admin.php';");

    if ($mounts === 0) {
        $this->markTestSkipped(
            "routes/set-contents-admin.php is not required from routes/web.php yet. "
            ."The integrator adds exactly one line, inside the admin-api group:\n"
            ."    require __DIR__.'/set-contents-admin.php';"
        );
    }

    expect($mounts)->toBe(1, 'The Set contents route file must be required exactly once.');
});

it('registers its capability and fails closed on both of its paths', function () {
    /*
     * CLAUDE.md rule 5: "Every new admin endpoint gets its own capability and
     * fails closed." AdminCapabilities::RULES is an ordered map and an exact
     * pattern does not match a sub-path, so the '/**' sibling is what covers
     * /preview — without it the preview 403s on a shop whose owner has no
     * shell to fix it from.
     *
     * MUTATION NOTE. Delete either RULES line and this is red on that path.
     * Delete the 'setcontents.manage' => [...] entry and it is red on the role
     * list. RUN.
     */
    $caps = \App\Support\AdminCapabilities::class;

    expect($caps::CAPABILITIES['setcontents.manage'] ?? null)->toBe(
        ['owner', 'manager', 'editor'],
        'setcontents.manage must be a declared capability held by the same three roles as its Appearance siblings.'
    );

    $paths = [];

    foreach ($caps::RULES as $rule) {
        $paths[$rule[1]] = $rule[2];
    }

    expect($paths['admin-api/set-contents'] ?? null)->toBe('setcontents.manage');
    expect($paths['admin-api/set-contents/**'] ?? null)->toBe('setcontents.manage');
});

/* ═══════════════════════════════════════ 5. a set with no brand at all ═══ */

it('renders a brandless set correctly everywhere the brand is printed', function () {
    /*
     * ── WHAT WAS ASKED, AND WHAT WAS ALREADY TRUE ─────────────────────────
     *
     * "the Set product type will not have any brand, so the brand selection
     *  can be optional."
     *
     * It already was, and this lane changed nothing about it: `brand_id` is
     * nullable in 0001_01_01_000000_create_kbb_schema, the product editor's
     * save validates it 'nullable', and the editor's first option has always
     * been "No brand". So this case is not a fix — it is the VERIFICATION the
     * brief asked for, done first-hand and then pinned, so the day somebody
     * makes the brand required the shop says so here rather than on the shop.
     *
     * Three places print a brand on a product page and all three are checked:
     * the brand line under the title (@if ($brand), so it is not drawn), the
     * <title> through App\Support\ProductTitle::full() (which returns the name
     * alone rather than a name with a leading space), and the structured data
     * (App\Support\Seo emits `brand` only for a non-empty one).
     *
     * MUTATION NOTE. Make Product::$fillable refuse a null brand_id, or drop
     * the `@if ($brand)` guard in store/product.blade.php, and this is red.
     * RUN.
     */
    $set = sfSet(14000, [[sfProduct('Brandless toner', 9000), 1]], [
        'name' => 'Quiet Ritual Box',
        'brand_id' => null,
    ]);

    expect($set->brand_id)->toBeNull('A set must be storable with no brand at all.');

    $html = $this->get('/product/'.$set->slug.'/')->assertOk()->getContent();

    expect(str_contains($html, 'class="bb-brand"'))->toBeFalse(
        'A product with no brand must not draw an empty brand line.'
    );
    preg_match('/<title>(.*?)<\/title>/s', $html, $kbbTitle);
    expect(str_starts_with(trim($kbbTitle[1] ?? ''), 'Quiet Ritual Box'))->toBeTrue(
        'A brandless product\'s title must be the name alone, with no leading space.'
    );
    expect(str_contains($html, '"brand"'))->toBeFalse(
        'The structured data must not claim a brand the product does not have.'
    );
});

it('tells the owner in the Brand panel that a set needs no brand', function () {
    /*
     * The useful half of "make the brand optional": it already is, so the
     * change is the SENTENCE. The panel offered a required-looking <select>
     * with no help text, and the owner had no way to tell "optional" from "I
     * have not found where to set it yet" — which is the question he asked.
     *
     * Shown only for a set: on an ordinary product a brand is expected, and a
     * hint telling everybody it is optional would be advice this shop does not
     * want to give. Both halves are asserted, because a hint rendered
     * unconditionally is the easy mistake.
     *
     * MUTATION NOTE. Drop the `(model.type || 'simple') === 'set'` condition in
     * brandView() and the second expectation is red. Remove the hint and the
     * first is. RUN.
     */
    $src = (string) file_get_contents(resource_path('views/admin/partials/product-editor-screen.blade.php'));

    $brandView = substr($src, (int) strpos($src, 'function brandView()'));
    $brandView = substr($brandView, 0, (int) strpos($brandView, 'function seoView()'));

    expect(str_contains($brandView, 'Optional for a set'))->toBeTrue(
        "The Brand panel must say that a set does not need one."
    );
    expect(str_contains($brandView, "=== 'set'"))->toBeTrue(
        'That hint must be shown for a set and not for every product.'
    );
});

/* ═════════════════════════════════════════ 6. the three admin endpoints ═══ */

/**
 * An admin of a given role, for the capability cases.
 *
 * Its own maker rather than a shared one: the other route-harness tests in
 * this suite each carry theirs, and a helper shared across files is a helper
 * one lane changes and another lane's suite goes red over.
 */
function sfAdmin(string $role = 'owner'): \App\Models\AdminUser
{
    return \App\Models\AdminUser::create([
        'name' => ucfirst($role),
        'email' => 'sfc-'.Str::random(10).'@example.com',
        'password' => bcrypt('secret'),
        'role' => $role,
    ]);
}

it('hands a signed-out request nothing on any of its three routes', function () {
    /*
     * ▲ EVERY NEW ADMIN ENDPOINT FAILS CLOSED. CLAUDE.md rule 5. ▲
     *
     * /api/* on this shop is unauthenticated, so an admin endpoint that
     * answered without a session would be a public one. All three are inside
     * the group that carries `web`, `auth:admin` and NoStoreAdminApi, and this
     * asserts it against the real middleware stack rather than against the
     * route file's comment about it.
     *
     * MUTATION NOTE. Drop 'auth:admin' from SetContentsAdminRoutes::STACK —
     * which is a copy of the stack routes/web.php applies — and all three
     * expectations are red. RUN.
     */
    \Tests\Support\SetContentsAdminRoutes::wire($this->app);

    $this->getJson('/admin-api/set-contents')->assertStatus(401);
    $this->postJson('/admin-api/set-contents', ['design' => 'list'])->assertStatus(401);
    $this->postJson('/admin-api/set-contents/preview', ['design' => 'list'])->assertStatus(401);
});

it('answers an owner with the four designs and stores the one he picks', function () {
    /*
     * MUTATION NOTE. Make save() write $data['design'] without asking
     * SetPanelDesign::valid() and the junk case below is red. Make show()
     * return the raw setting instead of current() and the first is. RUN.
     */
    \Tests\Support\SetContentsAdminRoutes::wire($this->app);

    sfSet(14000, [[sfProduct('Api toner', 9000), 1], [sfProduct('Api serum', 7550), 1]]);

    $owner = sfAdmin('owner');

    $body = $this->actingAs($owner, 'admin')->getJson('/admin-api/set-contents')->assertOk()->json();

    expect($body['current'])->toBe('grid')
        ->and($body['default'])->toBe('grid')
        ->and(array_column($body['designs'], 'key'))->toBe(['grid', 'list', 'cards', 'stack'])
        ->and($body['sets'])->toHaveCount(1);

    // ▲ THE ALLOWLIST. A set row may say four things and `products` carries
    //   wc_id, sku and total_sales. Asserted by name, not by count.
    expect(array_keys($body['sets'][0]))->toBe(['id', 'name', 'slug', 'members']);

    $this->actingAs($owner, 'admin')
        ->postJson('/admin-api/set-contents', ['design' => 'stack'])
        ->assertOk()
        ->assertJson(['ok' => true, 'current' => 'stack']);

    SettingsService::forgetMemo();
    expect(SetPanelDesign::current())->toBe('stack');
});

it('refuses a design that is not one of its own options', function () {
    /*
     * CLAUDE.md rule 5: "A select stores one of its own options or the
     * default." Refused at the door with a 422, and NOTHING WRITTEN — the
     * second half is the one that matters, because a rejected request that
     * still wrote would leave the shop drawing the default while the screen
     * showed something else.
     *
     * MUTATION NOTE. Remove the SetPanelDesign::valid() branch from save() and
     * this is red on both counts. RUN.
     */
    \Tests\Support\SetContentsAdminRoutes::wire($this->app);

    $owner = sfAdmin('owner');

    /*
     * 'grid ' is deliberately NOT in this list. Laravel's global TrimStrings
     * middleware turns it into 'grid' before the controller sees it, so it is
     * a valid request rather than a refused one — and the shop draws the grid,
     * which is the right answer to a trailing space.
     */
    foreach (['GRID', 'fan', '../grid', '<script>', 'partials.set-contents.grid'] as $junk) {
        $this->actingAs($owner, 'admin')
            ->postJson('/admin-api/set-contents', ['design' => $junk])
            ->assertStatus(422);
    }

    SettingsService::forgetMemo();
    expect(SetPanelDesign::current())->toBe('grid', 'A refused design must not have been stored.');
});

it('previews a design without storing it, and draws the shop\'s own panel', function () {
    /*
     * The preview is the reason this screen exists — "better to preview me the
     * set product front-end preview. so i can choose from." Two properties:
     * what comes back is the SHOP'S template (so the picture is the page), and
     * asking for it CHANGES NOTHING (so the owner can look at all four without
     * committing to any).
     *
     * MUTATION NOTE. Have preview() call $this->settings->set() as well and
     * the last expectation is red. Point the view at a mock instead of
     * admin.partials.set-contents-preview and the class assertions are. RUN.
     */
    \Tests\Support\SetContentsAdminRoutes::wire($this->app);

    $set = sfSet(14000, [[sfProduct('Preview toner', 9000), 2], [sfProduct('Preview serum', 7550), 1]]);
    $owner = sfAdmin('owner');

    $body = $this->actingAs($owner, 'admin')
        ->postJson('/admin-api/set-contents/preview', ['design' => 'stack', 'set_id' => $set->id])
        ->assertOk()
        ->json();

    expect($body['design'])->toBe('stack')
        ->and($body['set']['id'])->toBe($set->id);

    // The stack's own class, the set's real members, and the real prices.
    expect(str_contains($body['html'], 'class="kss-chain"'))->toBeTrue(
        'The preview must render the chosen design, from the shop\'s own partial.'
    );
    expect(str_contains($body['html'], 'Preview toner'))->toBeTrue(
        "The preview must be drawn from the owner's real set, not from a mock."
    );
    expect(str_contains($body['html'], 'You save'))->toBeTrue(
        'The preview must show the same saving the shop would.'
    );

    // And the shop is untouched.
    SettingsService::forgetMemo();
    expect(SetPanelDesign::current())->toBe('grid', 'Previewing must store nothing.');
});

it('prints only ltr or rtl into the preview document, never what was sent', function () {
    /*
     * The one value on the preview path that reaches an HTML attribute.
     * `dir` decides one attribute on <html>, and it is refused twice over:
     * anything longer than three characters never reaches the controller at
     * all, and what does reach it is compared against 'rtl' and turned into
     * one of two LITERALS. CLAUDE.md rule 5: "Anything printed unescaped is a
     * constant, never a setting."
     *
     * MUTATION NOTE. Change the controller's `$dir = (... === 'rtl') ? 'rtl' :
     * 'ltr'` to `$dir = $data['dir'] ?? 'ltr'` and the SECOND block is red —
     * 'xy>' lands in the document. Drop the `max:3` rule as well and the first
     * is too. RUN, both.
     */
    \Tests\Support\SetContentsAdminRoutes::wire($this->app);

    sfSet(14000, [[sfProduct('Dir toner', 9000), 1]]);
    $owner = sfAdmin('owner');

    // Too long to be a direction: refused before the controller runs.
    $this->actingAs($owner, 'admin')
        ->postJson('/admin-api/set-contents/preview', ['design' => 'grid', 'dir' => 'ltr" onload="x'])
        ->assertStatus(422);

    // Short enough to arrive, and still not what gets printed.
    $body = $this->actingAs($owner, 'admin')
        ->postJson('/admin-api/set-contents/preview', ['design' => 'grid', 'dir' => 'xy>'])
        ->assertOk()
        ->json();

    /*
     * ASSERTED ON THE ATTRIBUTE AND NOT ON THE RAW STRING, and that is the
     * difference between a test and a test that passes.
     *
     * The first draft looked for the sent value verbatim -- `xy>` -- and the
     * mutation below STAYED GREEN, because Blade escapes the interpolation and
     * the document carried `dir="xy&gt;"`: the value did reach it, escaped.
     * Escaping is why this is not an injection; the literal in the controller
     * is why the attribute is never anything but a direction. Both are worth
     * having, and only the second is what this case is about, so it looks for
     * the attribute's opening bytes.
     */
    expect(str_contains($body['html'], 'dir="xy'))->toBeFalse('A dir value must never reach the document.');
    expect(str_contains($body['html'], 'dir="ltr"'))->toBeTrue('An unrecognised dir falls back to ltr.');

    // And the real Arabic case works, because the designs have to hold up
    // mirrored and the owner has to be able to see that they do.
    $rtl = $this->actingAs($owner, 'admin')
        ->postJson('/admin-api/set-contents/preview', ['design' => 'grid', 'dir' => 'rtl'])
        ->assertOk()
        ->json();

    expect(str_contains($rtl['html'], 'dir="rtl"'))->toBeTrue('Arabic must be previewable.');
});

it('costs the preview three batched queries whatever the box holds', function () {
    /*
     * The admin screen asks for four renders when it opens. One query per
     * member on each of them is the same N+1 StorefrontQueryBudgetTest exists
     * to stop, and it is no more acceptable here than on the shop.
     *
     * MUTATION NOTE. Delete the `SetEagerLoad::on([$set])` line from
     * SetContentsApiController::preview() and this is red by nine. RUN.
     */
    \Tests\Support\SetContentsAdminRoutes::wire($this->app);

    $small = sfSet(10000, array_map(fn ($i) => [sfProduct('Pv small '.$i, 1000 + $i), 1], range(1, 3)));
    $large = sfSet(10000, array_map(fn ($i) => [sfProduct('Pv large '.$i, 1000 + $i), 1], range(1, 12)));

    $owner = sfAdmin('owner');

    $ask = function (Product $set) use ($owner): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($owner, 'admin')
            ->postJson('/admin-api/set-contents/preview', ['design' => 'cards', 'set_id' => $set->id])
            ->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $n;
    };

    // Warm whatever a first request in this process warms.
    $ask($small);

    $three = $ask($small);
    $twelve = $ask($large);

    expect($twelve)->toBe(
        $three,
        "A twelve-member preview must cost what a three-member one costs. Three: {$three}. Twelve: {$twelve}."
    );
});
