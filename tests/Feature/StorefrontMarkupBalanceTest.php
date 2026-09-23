<?php

declare(strict_types=1);

use App\Models\PaymentProvider;
use App\Models\Product;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;

/**
 * =============================================================================
 * EVERY DIV A STOREFRONT PAGE OPENS, IT CLOSES
 * =============================================================================
 *
 * This exists because one stray `</div>` shipped, and nothing in five thousand
 * tests noticed.
 *
 * Removing a duplicated row from the checkout's Contact section left its
 * closing tag behind. One orphan. The browser did what browsers do — closed
 * the nearest open element to make sense of it — and that element was the
 * checkout's two-column grid. So the summary fell OUT of the grid: on desktop
 * the two columns became one, and on mobile the summary dropped from the top
 * of the page to the bottom, because the order it sits in is a property of
 * being inside that grid.
 *
 * Neither symptom looks like a markup bug. Both look like CSS. The owner
 * reported them as a layout regression, and every test stayed green: the page
 * still returned 200, every field was still present, every string was still
 * correct. Nothing asserted that the page was well formed.
 *
 * ── WHY COUNTING IS ENOUGH ──────────────────────────────────────────────────
 *
 * A full parse would be stricter and would also be a second HTML parser to
 * maintain. Counting opens against closes catches the whole class this came
 * from — a tag added or removed by hand — and it cannot be argued with.
 *
 * SCRIPT AND STYLE ARE STRIPPED FIRST, and that is not a detail. The address
 * sheet builds its rows in JavaScript, so `</div>` appears dozens of times
 * inside string literals that are not markup at all. Counting without
 * stripping them gave a wrong answer on the first attempt at this very
 * diagnosis, and sent me looking in the wrong file.
 *
 * MUTATION: delete one `</div>` from any page in the walk. Red, naming it.
 */
function balanceMarkup(string $html): string
{
    $html = preg_replace('#<script\b.*?</script>#si', '', $html) ?? '';

    return preg_replace('#<style\b.*?</style>#si', '', $html) ?? '';
}

/** @return array{0:int,1:int} opens and closes of one tag. */
function balanceCount(string $markup, string $tag): array
{
    return [
        preg_match_all('#<' . $tag . '\b[^>]*>#i', $markup),
        preg_match_all('#</' . $tag . '>#i', $markup),
    ];
}

it('closes every element it opens, on every page a shopper walks through', function () {
    $zone = ShippingZone::create(['name' => 'UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $zone->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $zone->id, 'type' => 'flat_rate', 'title' => 'Standard delivery',
        'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);
    PaymentProvider::query()->delete();
    PaymentProvider::create(['id' => 'cod', 'title' => 'Cash on delivery', 'enabled' => true, 'mode' => 'test', 'position' => 0]);

    $product = Product::create([
        'slug' => 'balance-' . uniqid(), 'name' => 'Balance Serum', 'status' => 'publish',
        'is_visible' => true, 'price' => 200, 'stock_status' => 'instock',
    ]);

    test()->postJson('/api/cart/add', ['product_id' => $product->id, 'quantity' => 1])->assertOk();

    /*
     * The pages a shopper actually walks to buy something, which is where a
     * broken box costs money rather than looks untidy. The cart page is walked
     * on BOTH layouts because they are different templates.
     */
    $walk = ['/', '/shop/', '/cart/', '/checkout/'];
    $wrong = [];

    foreach (['classic', 'squeeze'] as $layout) {
        app(\App\Services\SettingsService::class)->set('cartpage_layout', $layout);
        \App\Services\SettingsService::forgetMemo();

        foreach ($walk as $uri) {
            $response = test()->get($uri);

            if ($response->getStatusCode() !== 200) {
                continue;
            }

            $markup = balanceMarkup($response->getContent());

            foreach (['div', 'aside', 'section', 'form'] as $tag) {
                [$open, $close] = balanceCount($markup, $tag);

                if ($open !== $close) {
                    $wrong[] = sprintf(
                        '%s (cart=%s) opens %d <%s> and closes %d',
                        $uri, $layout, $open, $tag, $close
                    );
                }
            }
        }
    }

    expect($wrong)->toBe([], implode("\n", array_merge(
        ['A page is not well formed. The browser will close the nearest open element to '
            . 'recover, which moves whatever was inside it — this shipped once as "the two '
            . 'columns are gone" and "the summary is at the bottom":'],
        $wrong
    )));
});
