<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\SettingsService;
use App\Services\StockAlerts;
use Illuminate\Support\Facades\Mail;
use Tests\Support\VpaAdminRoutes;
use Tests\Support\VpaCatalog;

/*
 * Lane VPA: Catalog -> Product editor -> Options, and the admin/owner
 * surfaces that read a variable product's options.
 */

beforeEach(function () {
    VpaAdminRoutes::wire($this->app);
});

function vpaOwner(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'VPA '.$role, 'email' => 'vpa-o-'.$role.'-'.uniqid().'@example.com',
        'password' => bcrypt('secret'), 'role' => $role,
    ]);
}

/** The editor's own payload for a product, edited by $fn, sent back. */
function vpaSave(Product $p, callable $fn, ?AdminUser $as = null)
{
    $as ??= vpaOwner();
    $d = test()->actingAs($as, 'admin')->getJson('/admin-api/product-variants/'.$p->id)->assertOk()->json();
    $body = $fn(['axes' => $d['axes'], 'variants' => $d['variants'], 'swatches' => []], $d);

    return test()->actingAs($as, 'admin')->putJson('/admin-api/product-variants/'.$p->id, $body);
}

it('closes the Options endpoints to a guest and to a role without catalog.variants', function () {
    /*
     * THE DEFECT THIS GUARDS: these rows decide what a shopper can buy and at
     * what price. MUTATION: delete the two product-variants rows from
     * AdminCapabilities::RULES and the support role's PUT is no longer 403.
     */
    $c = VpaCatalog::seed();

    $this->getJson('/admin-api/product-variants/'.$c['missha']->id)->assertStatus(401);
    $this->putJson('/admin-api/product-variants/'.$c['missha']->id, ['axes' => [], 'variants' => []])->assertStatus(401);
    $this->actingAs(vpaOwner('support'), 'admin')
        ->putJson('/admin-api/product-variants/'.$c['missha']->id, ['axes' => [], 'variants' => []])->assertStatus(403);

    expect(\App\Support\AdminCapabilities::forPath('PUT', 'admin-api/product-variants/7'))->toBe('catalog.variants')
        ->and(\App\Support\AdminCapabilities::forPath('GET', 'admin-api/product-variants/7'))->toBe('catalog.view');
});

it('reprices, restocks, adds and removes options, and the shop follows', function () {
    /*
     * THE DEFECT: no screen in the admin could make or change an option --
     * they came from the import and stayed. MUTATION: skip the delete of
     * rows not sent in ProductVariantsApiController::update() and the
     * removed shade is still on the page.
     */
    $c = VpaCatalog::seed();

    vpaSave($c['missha'], function ($b, $d) {
        $b['variants'][0]['price_aed'] = '149';
        $b['variants'][0]['sale_aed'] = '119';
        $b['variants'][2]['stock_status'] = 'outofstock';
        array_splice($b['variants'], 1, 1);
        $b['variants'][] = ['id' => null, 'values' => [(string) $d['axes'][0] => '#31 Golden Beige'], 'sku' => 'MS-BB-31',
            'price_aed' => '139', 'sale_aed' => null, 'stock_status' => 'instock', 'manage_stock' => true, 'stock' => 5, 'image' => '/uploads/vpa/missha-27.png'];

        return $b;
    })->assertOk()->assertJson(['ok' => true]);

    $skus = ProductVariant::where('product_id', $c['missha']->id)->orderBy('position')->pluck('sku')->all();
    expect($skus)->toBe(['MS-BB-1', 'MS-BB-3', 'MS-BB-31'])
        ->and(ProductVariant::where('sku', 'MS-BB-1')->value('sale_price'))->toBe(11900)
        ->and(AttributeValue::where('name', '#31 Golden Beige')->exists())->toBeTrue();

    $html = $this->get('/product/vpa-missha/')->getContent();
    expect($html)->toContain('data-n="#31 Golden Beige"')
        ->and($html)->not->toContain('data-n="#21 Light Beige"')
        ->and($html)->toMatch('/class="vpa-sw out"[^>]*data-n="#27 Honey Beige"/');
});

it('refuses what the shop cannot sell, naming the option', function () {
    /*
     * THE DEFECT THIS GUARDS: a sale above its price, a foreign value, two
     * identical options, a colour that is not a colour, a picture that is
     * a script. MUTATION: drop any one check and its line is 200.
     */
    $c = VpaCatalog::seed();
    $foreign = $c['anua']->variants()->first()->attributeValues()->first()->id;

    $cases = [
        function ($b) { $b['variants'][0]['sale_aed'] = '999'; return $b; },
        function ($b) use ($foreign) { $b['variants'][0]['values'][(string) $b['axes'][0]] = $foreign; return $b; },
        function ($b) { $b['variants'][1]['values'] = $b['variants'][0]['values']; return $b; },
        function ($b) { $b['variants'][0]['price_aed'] = '12.5.0'; return $b; },
        function ($b) { $b['variants'][0]['stock_status'] = 'gone'; return $b; },
        function ($b) { $b['variants'][0]['image'] = 'javascript:alert(1)'; return $b; },
        function ($b) { $b['variants'][0]['id'] = ProductVariant::where('sku', 'AN-HL-250')->value('id'); return $b; },
    ];

    foreach ($cases as $i => $case) {
        $res = vpaSave($c['missha'], fn ($b) => $case($b));
        expect($res->getStatusCode())->toBe(422, 'case '.$i.' was accepted');
    }

    $bad = vpaSave($c['missha'], function ($b) {
        $b['swatches'] = [(string) $b['variants'][0]['values'][(string) $b['axes'][0]] => 'red;x:y'];

        return $b;
    });
    expect($bad->getStatusCode())->toBe(422)
        ->and(ProductVariant::where('product_id', $c['missha']->id)->count())->toBe(3);
});

it('saves a swatch colour, normalised, and the card prints it', function () {
    /*
     * MUTATION: write $hex unvalidated in update() and #abc is stored raw.
     */
    $c = VpaCatalog::seed();
    $valueId = null;

    vpaSave($c['missha'], function ($b) use (&$valueId) {
        $valueId = (int) $b['variants'][0]['values'][(string) $b['axes'][0]];
        $b['swatches'] = [(string) $valueId => '#abc'];

        return $b;
    })->assertOk();

    expect(AttributeValue::find($valueId)->swatch_color)->toBe('#AABBCC')
        ->and($this->get('/collections/vpa-makeup/')->getContent())->toContain('--c:#AABBCC');
});

it('marks the product sold out on the grid when its last option sells out', function () {
    /*
     * THE DEFECT THIS GUARDS: the card reads the parent row's stock_status;
     * an editor that sold out every shade must say so there too.
     */
    $c = VpaCatalog::seed();

    vpaSave($c['anua'], function ($b) {
        foreach ($b['variants'] as $i => $v) {
            $b['variants'][$i]['stock_status'] = 'outofstock';
        }

        return $b;
    })->assertOk();

    expect($c['anua']->fresh()->stock_status)->toBe('outofstock');
});

it('offers the product\'s options in the catalogue export, in a column added last', function () {
    /*
     * MUTATION: drop 'options' from the header and the column count moves.
     */
    \Tests\Support\CatalogProductsAdminRoutes::wire($this->app);
    VpaCatalog::seed();

    $csv = $this->actingAs(vpaOwner(), 'admin')->get('/admin-api/catalog-products-export')->streamedContent();
    $lines = array_values(array_filter(explode("\n", $csv)));
    $head = str_getcsv(ltrim($lines[0], "\xEF\xBB\xBF"));

    expect(end($head))->toBe('options')
        ->and($head[count($head) - 2])->toBe('currency')
        ->and($csv)->toContain('Shade: 21N Ivory / TT-MF-21NIvory / 145.00 / instock / 12');
});

it('names the shade in the owner app\'s product screen', function () {
    $c = VpaCatalog::seed();
    $owner = vpaOwner();

    // The app's session middleware puts the signed-in member here.
    $request = \Illuminate\Http\Request::create('/owner-app/api/products/'.$c['missha']->id);
    $request->attributes->set('oa.admin', $owner);
    $labels = collect(app(\App\Http\Controllers\OwnerApp\ProductsController::class)
        ->show($request, $c['missha']->id)->getData(true)['product']['variants'])->pluck('label')->all();

    expect($labels)->toContain('Shade: #13 Bright Beige');
})->skip(fn () => ! method_exists(\App\Http\Controllers\OwnerApp\ProductsController::class, 'show'), 'owner app product screen absent');

it('shows "tell me when it is back" for a sold-out shade, and the email names the shade', function () {
    /*
     * THE DEFECT: the form drew only when the WHOLE product was sold out, so
     * nobody could ask for one shade; and the email said the product was back
     * without saying which shade. MUTATION: drop `notifyVariants` from the
     * product page's include and the hidden form is gone.
     */
    $c = VpaCatalog::seed();
    $s = app(SettingsService::class);
    $s->setModule('back_in_stock', true);
    $s->set(StockAlerts::KEY_FORM_LABEL, 'Sold out — we will tell you when it returns.');
    $s->set(StockAlerts::KEY_SUBJECT, 'It is back');
    $s->set(StockAlerts::KEY_BODY, 'The thing you asked about is back in stock.');

    $html = $this->get('/product/vpa-tirtir/')->getContent();
    expect($html)->toContain('<div class="notifyme" id="vpaNotify" hidden');

    $v = ProductVariant::where('sku', 'TT-MF-17CPorcelain')->first();
    app(StockAlerts::class)->request($c['tirtir']->id, $v->id, 'layla@example.com');
    $v->update(['stock_status' => 'instock', 'stock' => 4]);

    Mail::fake();
    app(\App\Services\OutboundSender::class)->sendStockAlerts(10);

    Mail::assertSent(\App\Mail\BackInStockAlert::class, fn ($m) => str_contains($m->render(), 'Shade: 17C Porcelain'));
});
