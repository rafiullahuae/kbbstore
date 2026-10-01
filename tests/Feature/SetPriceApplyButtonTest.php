<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Product;
use App\Models\ProductSetItem;
use App\Support\Money;
use App\Support\SetPricing;
use App\Support\Url;
use Illuminate\Support\Str;

/**
 * The set panel's price button, and the editor's Visit button. (Lane PK)
 *
 * ── THE DEFECT, AS THE OWNER SAW IT ────────────────────────────────────────
 *
 *   "when i put the percentage or custom discount on the set price, and when i
 *    press Use this price button, it's not bringing the price to the actual
 *    price and sale price field above. i want if i press the button, the actual
 *    price should set, also the sale price, and also the discount will of
 *    course show on front-end. ... This issue is coming only in set products."
 *
 * His screenshot: "An amount off the total", Discount 131.00, tiles Bought
 * separately AED 1310.00 / Set price AED 1179.00 / Saving AED 131.00 -- and the
 * Price card reading Price 1179.00, Sale price EMPTY, and the shop showing no
 * discount at all.
 *
 * Reproduced on a preview of exactly that set (tools/pk-shots.cjs, PHASE
 * before): the button "Use this total" did not copy anything. It set the rule
 * to "an amount off the total" with the amount ZERO -- wiping his 131 -- so
 * after Save the shop sold the set at AED 1,310 with no discount anywhere. And
 * under either rule the server clears the sale price ("NO SALE PRICE UNDER A
 * RULE"), so nothing could ever be struck through.
 *
 * The button is now "Apply to Price and Sale price": Price = the total, Sale
 * price = the set price, rule switched to "A price I type", whole dirhams. The
 * shop then shows the set like any product on sale.
 *
 * ── AND VISIT ──────────────────────────────────────────────────────────────
 *
 * "Visit" in the editor's bar opens the product on the shop in a new tab, from
 * an address the SERVER built (Product::url(), so KBB_BASE_PATH is respected),
 * and says "Not live yet" -- disabled -- for a product the shop would 404.
 */
function pkAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'PK owner',
        'email' => 'pk-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

function pkProduct(string $name, int $fils, array $overrides = []): Product
{
    return Product::create(array_merge([
        'slug' => 'pk-'.Str::slug($name).'-'.Str::lower(Str::random(6)),
        'name' => $name,
        'type' => 'simple',
        'status' => 'publish',
        'is_visible' => true,
        'price' => $fils,
        'stock_status' => 'instock',
    ], $overrides));
}

/** The owner's set: three products costing AED 1,310 bought separately. */
function pkOwnersSet(): array
{
    $device = pkProduct('LED Booster Device', 75000);
    $cream = pkProduct('Capsule Cream', 36000);
    $serum = pkProduct('Peptide Serum', 20000);

    $set = pkProduct('Glow Trio Set', 0, ['type' => 'set']);

    foreach ([$device, $cream, $serum] as $i => $member) {
        ProductSetItem::create([
            'set_product_id' => $set->id,
            'member_product_id' => $member->id,
            'quantity' => 1,
            'position' => $i,
        ]);
    }

    SetPricing::forget();

    return [$set->fresh(), $device, $cream, $serum];
}

/** The editor's source with every comment stripped, so prose cannot pass a check. */
function pkEditorCode(): string
{
    $src = (string) file_get_contents(resource_path('views/admin/partials/product-editor-screen.blade.php'));
    $src = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $src);
    $src = (string) preg_replace('#/\*.*?\*/#s', '', $src);

    return (string) preg_replace('#^\s*//.*$#m', '', $src);
}

/** One function, signature to closing brace, by brace matching. '' if absent. */
function pkFunction(string $code, string $signature): string
{
    $at = strpos($code, $signature);

    if ($at === false) {
        return '';
    }

    $open = strpos($code, '{', $at);
    $depth = 0;

    for ($i = $open; $i < strlen($code); $i++) {
        if ($code[$i] === '{') {
            $depth++;
        } elseif ($code[$i] === '}' && --$depth === 0) {
            return substr($code, $at, $i - $at + 1);
        }
    }

    return '';
}

/**
 * Run the editor's OWN functions in node and hand back what they returned.
 *
 * Not a re-implementation of the arithmetic in PHP: the functions are cut out
 * of the Blade file the console serves, so a change to them is a change to
 * what this asserts.
 */
function pkNode(array $functions, string $body): mixed
{
    $code = pkEditorCode();
    $src = "'use strict';\nvar model = {};\n";

    foreach ($functions as $name) {
        $fn = pkFunction($code, 'function '.$name.'(');
        expect($fn)->not->toBe('', "function {$name}() is not in the product editor.");
        $src .= $fn."\n";
    }

    $dir = storage_path('framework/testing/pk-node');
    @mkdir($dir, 0775, true);
    $file = $dir.'/run-'.getmypid().'-'.Str::random(6).'.js';
    file_put_contents($file, $src."process.stdout.write(JSON.stringify((function(){ ".$body." })()));\n");

    $out = (string) shell_exec('node '.escapeshellarg($file).' 2>&1');
    @unlink($file);

    $decoded = json_decode($out, true);
    expect($decoded)->not->toBeNull("node said: {$out}");

    return $decoded;
}

function pkNeedsNode(): void
{
    if (trim((string) shell_exec('command -v node 2>/dev/null')) === '') {
        test()->markTestSkipped('node is not on PATH; the CI image has it.');
    }
}

beforeEach(function () {
    SetPricing::forget();
    $this->actingAs(pkAdmin(), 'admin');
});

afterEach(function () {
    Url::forgetBase();
});

/* ══════════════════════════════════════════ the button's arithmetic ═══ */

it('fills Price with the total and Sale price with the set price, in whole dirhams', function () {
    /*
     * THE OWNER'S DEFECT, AT ITS SOURCE. The old handler set
     * `model.discount_amount = '0'` and copied nothing, so Price and Sale price
     * never received "1310 / 1179" and his 131 off was wiped.
     *
     * MUTATION NOTES, each RUN:
     *   - Restore the old handler (no setApplyFigures at all) and this is red:
     *     "function setApplyFigures() is not in the product editor".
     *   - Return `sale_aed: ''` from the rule branch (fill Price only) and the
     *     first two cases are red -- the shop would get no sale price again.
     *   - Round with Math.floor(f / 100) instead of `+ 50` and the 1178.82 case
     *     reads 1178, a dirham under what SetPricing's half-up rounding gives.
     */
    pkNeedsNode();

    $out = pkNode(['setApplyFigures'], 'return ['
        // His screenshot: "An amount off the total", 131 off 1310.
        ."setApplyFigures('discount_amount', 131000, 117900, ''),"
        // "A percentage off the total", 10% of 1310.
        ."setApplyFigures('discount_percent', 131000, 117900, ''),"
        // Fils in the tiles: 10% off 1309.80 is 1178.82 -> 1310 / 1179.
        ."setApplyFigures('discount_percent', 130980, 117882, ''),"
        // Exactly half a dirham rounds UP, as WholeDirhams::nearest() does.
        ."setApplyFigures('discount_amount', 131000, 117850, ''),"
        ."setApplyFigures('discount_amount', 131000, 117849, ''),"
        // A rule of 0 is no sale: the sale box is emptied, not set to the price.
        ."setApplyFigures('discount_amount', 131000, 131000, '1200'),"
        // 100% off: the tiles say "Saving —" and so does this -- no sale of 0.
        ."setApplyFigures('discount_percent', 131000, 0, ''),"
        // Already "A price I type": Price = total, Sale left exactly as typed.
        ."setApplyFigures('fixed', 131000, 140000, '1199'),"
        ."setApplyFigures('fixed', 131000, 140000, '')"
        .'];');

    expect($out)->toBe([
        ['price_aed' => '1310', 'sale_aed' => '1179'],
        ['price_aed' => '1310', 'sale_aed' => '1179'],
        ['price_aed' => '1310', 'sale_aed' => '1179'],
        ['price_aed' => '1310', 'sale_aed' => '1179'],
        ['price_aed' => '1310', 'sale_aed' => '1178'],
        ['price_aed' => '1310', 'sale_aed' => ''],
        ['price_aed' => '1310', 'sale_aed' => ''],
        ['price_aed' => '1310', 'sale_aed' => '1199'],
        ['price_aed' => '1310', 'sale_aed' => ''],
    ]);
});

it('switches the set to "A price I type" and keeps the follow-the-members anchor', function () {
    /*
     * The handler, read as code. What it must do, and the one thing the old one
     * did that it must never do again.
     *
     * MUTATION NOTES, each RUN:
     *   - put back `model.discount_amount = '0'` and the not->toContain is red;
     *   - drop `model.price_mode = 'fixed'` and the set stays on its rule, where
     *     the server derives the price and CLEARS the sale price on save -- the
     *     shop shows no discount, exactly the owner's complaint;
     *   - drop `setReanchor = true` and pressing it on a set already at "A
     *     price I type" with the same figures would not re-anchor on save.
     */
    $code = pkEditorCode();
    $handler = pkFunction($code, 'function bindSetBox(');

    expect($handler)->not->toBe('')
        ->and($handler)->not->toContain("model.discount_amount = '0'")
        ->and($handler)->toContain('setApplyFigures(model.price_mode')
        ->and($handler)->toContain('model.price_aed = figures.price_aed;')
        ->and($handler)->toContain('model.sale_aed = figures.sale_aed;')
        ->and($handler)->toContain("model.price_mode = 'fixed';")
        ->and($handler)->toContain('setReanchor = true;')
        // The dates are not the button's business.
        ->and($handler)->not->toContain('sale_starts_at')
        ->and($handler)->not->toContain('sale_ends_at');

    // Relabelled, same element, drawn once.
    expect(substr_count($code, '>Apply to Price and Sale price</button>'))->toBe(1)
        ->and(substr_count($code, 'id="peo-usetotal"'))->toBe(1)
        ->and($code)->not->toContain('>Use this total</button>');
});

it('shows the shop\'s own price in the Set price tile once a sale price is typed', function () {
    /*
     * After the button, a set reads Price 1310 / Sale 1179 on "A price I type".
     * The tile under "Set price" used to read `price` alone in that mode, so
     * pressing the button would have turned the tiles from 1310 / 1179 / 131
     * into 1310 / 1310 / — on the very screen that had just filled in the sale.
     *
     * MUTATION NOTE. Make setChargedFils() `return typed;` unconditionally and
     * the second and third values are 131000, not 117900. RUN.
     */
    pkNeedsNode();

    $out = pkNode(['setFils', 'setChargedFils'], 'var r = [];'
        ."model = { price_aed: '1310', sale_aed: '' }; r.push(setChargedFils());"
        ."model = { price_aed: '1310', sale_aed: '1179' }; r.push(setChargedFils());"
        ."model = { price_aed: '1310.00', sale_aed: '1179.00', sale_starts_at: '2000-01-01T00:00' }; r.push(setChargedFils());"
        // A sale that starts next century, or ended last one, is not today's price.
        ."model = { price_aed: '1310', sale_aed: '1179', sale_starts_at: '2199-01-01T00:00' }; r.push(setChargedFils());"
        ."model = { price_aed: '1310', sale_aed: '1179', sale_ends_at: '2000-01-01T00:00' }; r.push(setChargedFils());"
        .'return r;');

    expect($out)->toBe([131000, 117900, 117900, 131000, 131000]);
});

/* ═══════════════════════════════ what the save does with those figures ═══ */

it('saves the two figures, and the shop shows the set on sale: struck 1,310, 1,179, -10%', function () {
    /*
     * The round trip the screen makes: the set on its rule (his 131 off), then
     * the exact body the editor posts after the button -- price_mode fixed,
     * Price 1310, Sale 1179, the re-anchor instruction.
     *
     * Before the fix the button posted `price_mode: discount_amount,
     * discount_amount: 0` and the product page printed "AED 1,310" with no <s>,
     * no badge -- the owner's "the shop shows no discount".
     *
     * MUTATION NOTE. Post the old body ('discount_amount' => '0') in the second
     * request and every expectation from `sale_price` down is red. RUN.
     */
    [$set] = pkOwnersSet();

    $this->postJson('/admin-api/product-editor-save/'.$set->id, [
        'price_mode' => 'discount_amount',
        'discount_amount' => '131',
    ])->assertOk();

    SetPricing::forget();
    expect(Product::find($set->id)->effectivePrice())->toBe(117900)
        ->and(Product::find($set->id)->sale_price)->toBeNull();

    $saved = $this->postJson('/admin-api/product-editor-save/'.$set->id, [
        'price_mode' => 'fixed',
        'price_aed' => '1310',
        'sale_aed' => '1179',
        'reanchor' => true,
    ])->assertOk()->json('product');

    expect($saved['price_aed'])->toBe('1310.00')
        ->and($saved['sale_aed'])->toBe('1179.00')
        ->and($saved['price_mode'])->toBe('fixed')
        ->and($saved['set_basis_aed'])->toBe('1310.00');

    SetPricing::forget();
    $fresh = Product::find($set->id);

    expect((int) $fresh->price)->toBe(131000)
        ->and((int) $fresh->sale_price)->toBe(117900)
        ->and($fresh->sale_starts_at)->toBeNull()
        ->and($fresh->sale_ends_at)->toBeNull()
        ->and($fresh->isOnSale())->toBeTrue()
        ->and($fresh->discountPercent())->toBe(10);

    $html = $this->get('/product/'.$fresh->slug.'/')->assertOk()->getContent();

    expect($html)->toContain('<s>'.Money::format(131000).'</s>')
        ->and($html)->toContain('<span class="now">'.Money::format(117900).'</span>')
        ->and($html)->toContain('<span class="off">'.\App\Support\Bidi::number('-10%').'</span>');
});

it('still follows its products down after the button: off the price and off the sale', function () {
    /*
     * "keeps the existing follow-the-members behaviour": a member marked down by
     * AED 50 takes AED 50 off both typed figures, so the discount stays on show.
     *
     * MUTATION NOTE. Drop `reanchor` and the price/sale change from the body --
     * i.e. leave the set on its rule as the old button did -- and the sale price
     * is null, so the second expectation is red. RUN.
     */
    [$set, $device] = pkOwnersSet();

    $this->postJson('/admin-api/product-editor-save/'.$set->id, [
        'price_mode' => 'fixed',
        'price_aed' => '1310',
        'sale_aed' => '1179',
        'reanchor' => true,
    ])->assertOk();

    $device->update(['price' => 70000]);
    SetPricing::forget();
    $fresh = Product::find($set->id);

    expect($fresh->compareAtPrice())->toBe(126000)
        ->and($fresh->effectivePrice())->toBe(112900)
        ->and($fresh->isOnSale())->toBeTrue();
});

/* ══════════════════════════════════════════════════════════════ Visit ═══ */

it('hands the editor the shop address and whether it is live, for every product', function () {
    /*
     * "Visit" on a draft would open a 404, so the payload says which products
     * the shop answers for. ProductVisibility::isLive() is the product page's
     * own rule in memory.
     *
     * MUTATION NOTE. Make `live` answer `true` unconditionally and the draft,
     * private, hidden and scheduled rows are red. RUN.
     */
    $cases = [
        'published' => [pkProduct('Live Toner', 9900), true],
        'draft' => [pkProduct('Draft Toner', 9900, ['status' => 'draft']), false],
        'private' => [pkProduct('Private Toner', 9900, ['status' => 'private']), false],
        'hidden' => [pkProduct('Hidden Toner', 9900, ['is_visible' => false, 'status' => 'private']), false],
        'scheduled' => [pkProduct('Later Toner', 9900, ['published_at' => now()->addWeek()]), false],
    ];

    foreach ($cases as $label => [$product, $live]) {
        $ro = $this->getJson('/admin-api/product-editor-load/'.$product->id)->assertOk()->json('product.readonly');

        expect($ro['live'])->toBe($live, "{$label}: live")
            ->and($ro['url'])->toBe('/product/'.$product->slug.'/', "{$label}: url");

        if ($live) {
            $this->get($ro['url'])->assertOk();
        } else {
            $this->get($ro['url'])->assertNotFound();
        }
    }
});

it('builds the Visit address through the shop\'s own base path', function () {
    /*
     * KBB_BASE_PATH is EMPTY on extrabeauty.ae and /kbb-upgrade on the old
     * staging box. The address comes from Product::url() -> Url::to(), so it is
     * right on both without the screen knowing which it is on.
     *
     * MUTATION NOTE. Build it as '/product/'.$product->slug.'/' in the
     * controller and the /kbb-upgrade case is red. RUN.
     */
    $product = pkProduct('Base Path Toner', 9900);

    config(['kbb.base_path' => 'kbb-upgrade']);
    Url::forgetBase();

    $ro = $this->getJson('/admin-api/product-editor-load/'.$product->id)->assertOk()->json('product.readonly');

    expect($ro['url'])->toBe('/kbb-upgrade/product/'.$product->slug.'/');
});

it('puts Visit in the editor bar once, as a new-tab link or a disabled "Not live yet"', function () {
    /*
     * MUTATION NOTES, each RUN:
     *   - remove `+ visitButton()` from editorView() and the count is 0 -- the
     *     "built, never wired up" shape;
     *   - drop rel="noopener" and the shop tab can reach back through
     *     window.opener into the console;
     *   - link the not-live branch and the owner gets a 404 from his own button.
     */
    $code = pkEditorCode();

    expect(substr_count($code, '+ visitButton()'))->toBe(1);

    $visit = pkFunction($code, 'function visitButton(');

    expect($visit)->toContain('target="_blank" rel="noopener"')
        ->and($visit)->toContain('ro.live')
        ->and($visit)->toContain('url(ro.url)')
        ->and($visit)->toContain(">Visit'")
        ->and($visit)->toContain('Not live yet')
        // The disabled shape is a <button disabled>, never an <a> with no href,
        // which can still be focused and "pressed".
        ->and($visit)->toContain("var tag = href ? 'a' : 'button';")
        ->and($visit)->toContain("' type=\"button\" disabled title=");
});

it('draws Visit as a new-tab link when live and a disabled button when not', function () {
    /*
     * The function above, RUN, for the three states a product can be in on
     * this screen. MUTATION NOTE: swap the ternary's branches and the draft
     * gets a link to a 404 -- red. RUN.
     */
    pkNeedsNode();

    $out = pkNode(['esc', 'url', 'visitButton'], 'var r = [];'
        ."model = { id: 7, readonly: { live: true, url: '/product/glow-trio-set/' } }; r.push(visitButton());"
        ."model = { id: 8, readonly: { live: false, url: '/product/coming-soon/' } }; r.push(visitButton());"
        ."model = { id: null, name: 'New' }; r.push(visitButton());"
        .'return r;');

    expect($out[0])->toBe('<a class="peo-btn" id="peo-visit" href="/product/glow-trio-set/" target="_blank" rel="noopener" title="Open this product on the shop, in a new tab">Visit</a>');

    foreach ([$out[1], $out[2]] as $off) {
        expect($off)->toStartWith('<button class="peo-btn" id="peo-visit" type="button" disabled')
            ->and($off)->toEndWith('>Not live yet</button>')
            ->and($off)->not->toContain('href=');
    }
});

it('gives each Catalog -> Products row its shop address and a Visit beside Edit', function () {
    /*
     * MUTATION NOTE. Drop 'live' from CatalogProductsApiController::rowToApi()
     * and the screen draws "Not live yet" on every row, so the first
     * expectation is red. RUN.
     */
    $live = pkProduct('Row Live', 9900);
    $draft = pkProduct('Row Draft', 9900, ['status' => 'draft']);

    $rows = collect($this->getJson('/admin-api/catalog-products-list?per_page=100')->assertOk()->json('products'))
        ->keyBy('id');

    expect($rows[$live->id]['live'])->toBeTrue()
        ->and($rows[$live->id]['url'])->toBe('/product/'.$live->slug.'/')
        ->and($rows[$draft->id]['live'])->toBeFalse();

    $view = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect(substr_count($view, 'data-cpvisit="'))->toBe(2)
        ->and($view)->toContain('target="_blank" rel="noopener" title="Open on the shop, in a new tab"');
});
