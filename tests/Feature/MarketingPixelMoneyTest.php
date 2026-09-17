<?php

declare(strict_types=1);

/**
 * What Growth & Marketing reports to third parties, in dirhams.
 *
 * ── HOW THIS CAME TO BE WRITTEN ────────────────────────────────────────────
 *
 * Phase 16's remaining work was established by RUNNING the module list, not by
 * reading it. Every module in ModuleRegistry's `marketing` group —
 * marketing_pixels, abandoned_cart, back_in_stock — reports status `live`, and
 * all three do render when switched on: the pixel tags fire on the homepage and
 * the product page, the "Tell me when this is back" form appears under a
 * sold-out product's button, and the basket-reminder tick box is gated on a
 * non-empty cart. So nothing in Phase 16 is unbuilt.
 *
 * What none of them had was a single test. `ls tests/Feature | grep -i pixel`
 * returned nothing, and the surface with the most to lose is this one: the
 * pixels are the only place in the shop that reports MONEY to somebody else's
 * system.
 *
 * ── WHY MONEY IS THE THING TO PIN ──────────────────────────────────────────
 *
 * Every price in this database is integer fils, AED × 100. This project has
 * already caught that bug twice on the way out — the Product schema in 2.60.36
 * would have told search engines a 126 AED serum cost 12,600, and the plan
 * records it. The same column reaches Meta, GA4 and TikTok through this class.
 * A pixel reporting 12,600 does not look broken anywhere: the tag fires, the
 * event lands, and the shop's entire return-on-ad-spend figure is out by two
 * orders of magnitude in the direction that makes it look like it is winning.
 *
 * ── FETCHED, NOT CALLED ────────────────────────────────────────────────────
 *
 * Through the full kernel with the rendered page parsed, because the tags are
 * built by string interpolation inside a <script> and the thing that matters is
 * what a browser would run.
 */

use App\Models\Brand;
use App\Models\ModuleToggle;
use App\Models\Product;
use App\Models\Setting;
use App\Services\MarketingPixels;
use App\Services\SettingsService;

/**
 * Pixels on, with IDs written through the module's own save path.
 *
 * NOT by writing `Setting` rows called `ga4_id`. Analytics stores these under
 * the module's own namespace (SettingsService::moduleSetting) and the toggle
 * lives in `module_toggles`, not in `settings` — a bare row of either name is
 * read by nothing, which is how an hour goes missing when this is set up by
 * hand. The real save path is the only one that proves the screen works.
 */
function mpEnable(): void
{
    ModuleToggle::query()->updateOrCreate(['module' => 'marketing_pixels'], ['enabled' => true]);

    app(MarketingPixels::class)->save([
        'meta_id' => '111122223333',
        // Shape-checked by Analytics::validId(); junk here silently disables
        // the network, so a test that used one would prove nothing.
        'ga4_id' => 'G-TESTFX12',
        'tiktok_id' => 'CQ1ABCDEFG',
    ]);

    Setting::query()->updateOrCreate(['key' => 'site_url'], ['value' => 'http://localhost', 'autoload' => true]);
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    \Illuminate\Support\Facades\Cache::flush();
}

function mpProduct(int $fils): Product
{
    $brand = Brand::firstOrCreate(['slug' => 'mp-brand'], ['name' => 'MP Brand']);

    $product = Product::firstOrCreate(
        ['slug' => 'mp-serum'],
        [
            'name' => 'MP Serum',
            'brand_id' => $brand->id,
            'status' => 'publish',
            'is_visible' => true,
            'price' => $fils,
            'stock_status' => 'instock',
            'type' => 'simple',
        ]
    );

    $product->forceFill(['price' => $fils])->save();

    return $product;
}

/**
 * One numeric field out of a pixel payload, as the browser would read it.
 *
 * NOT json_decode(). These payloads are JavaScript OBJECT LITERALS with
 * unquoted keys — `{currency:"AED",value:126,...}` — which is valid JavaScript
 * and is not valid JSON, so decoding the whole thing returns null however
 * correct the tag is. The first draft of this file did exactly that and three
 * cases failed against working code.
 *
 * The field is matched with its own pattern, and the pattern is the assertion:
 * `-?\d+(\.\d+)?` accepts a plain decimal and REFUSES the exponent form
 * (1.0E+9) that PHP's string conversion produces for a large float. So a value
 * interpolated without json_encode() does not merely read oddly here, it fails
 * to match at all — which is the same thing the JavaScript parser does with it.
 */
function mpNumber(string $payload, string $key): string
{
    expect(preg_match('#\b'.preg_quote($key, '#').':(-?\d+(?:\.\d+)?)[,}]#', $payload, $m))
        ->toBe(1, "No plain number under '{$key}' in: {$payload}");

    return $m[1];
}

/** The payload of one pixel call on a fetched page. */
function mpPayload(string $html, string $call): string
{
    expect(preg_match('#'.preg_quote($call, '#').',(\{.*?\})\);#', $html, $m))
        ->toBe(1, "No {$call} call on the page.");

    return $m[1];
}

it('reports the product-view value in dirhams, never the fils column', function () {
    /*
     * 12600 in the column is AED 126.00. A tag carrying 12600 fires perfectly
     * and is wrong by a hundred times.
     *
     * MUTATION: drop the `/ 100` from MarketingPixels::viewContent(). Red on
     * every expectation below.
     */
    mpEnable();
    mpProduct(12600);

    $html = test()->get('/product/mp-serum/')->assertOk()->getContent();

    expect(mpNumber(mpPayload($html, "fbq('track','ViewContent'"), 'value'))->toBe('126');

    $ga = mpPayload($html, "gtag('event','view_item'");
    expect(mpNumber($ga, 'value'))->toBe('126');
    expect(mpNumber($ga, 'price'))->toBe('126');

    // And the currency is stated. A bare number is unitless to every one of
    // these systems and they each assume their own default.
    expect(str_contains($ga, 'currency:"AED"'))->toBeTrue("No AED currency in: {$ga}");
});

it('reports the price the shopper is actually charged', function () {
    /*
     * A product on sale prints the sale price and is sold at the sale price, so
     * that is the number the pixel has to carry — effectivePrice(), not the
     * list column. Reporting the list price inflates every conversion value the
     * shop ever reports, in the direction nobody checks.
     *
     * MUTATION: use `$product->price` in viewContent(). Red.
     */
    mpEnable();
    $product = mpProduct(12600);
    $product->forceFill([
        'sale_price' => 8900,
        'sale_starts_at' => now()->subDay(),
        'sale_ends_at' => now()->addDay(),
    ])->save();

    $html = test()->get('/product/mp-serum/')->assertOk()->getContent();

    expect(mpNumber(mpPayload($html, "gtag('event','view_item'"), 'value'))->toBe('89');
});

it('emits nothing at all while the module is off', function () {
    /*
     * The shipped state. Both the toggle and the IDs are required, and the
     * shipped default is the toggle off — so applying a package must change
     * these pages by exactly nothing. Asserted on the loader as well as the
     * events, because a loader with no events is still a third-party script on
     * every page of a shop that never asked for one.
     *
     * THE IDs ARE WRITTEN AND THE TOGGLE IS LEFT OFF, which is the whole care
     * in this case. An earlier draft simply did not call mpEnable(), so there
     * were no IDs either — and it then proved "no IDs means no tags" while
     * reading as though it proved "module off means no tags". Mutating
     * Analytics::enabled() to `true` left it green, which is how that was
     * found. With the IDs present the mutation goes red, because the toggle is
     * the only thing left holding the tags back.
     *
     * str_contains() rather than expect()->not->toContain(): that method is
     * variadic and a second argument to it is a second NEEDLE, which is the
     * shape ExpectationsThatCannotFailTest sweeps the suite for.
     */
    mpEnable();
    ModuleToggle::query()->updateOrCreate(['module' => 'marketing_pixels'], ['enabled' => false]);
    app(SettingsService::class)->flush();
    \Illuminate\Support\Facades\Cache::flush();

    mpProduct(12600);

    $html = test()->get('/product/mp-serum/')->assertOk()->getContent();

    foreach (['fbq(', 'gtag(', 'ttq.load', 'googletagmanager.com'] as $needle) {
        expect(str_contains($html, $needle))->toBeFalse("The page emits {$needle} with the module off.");
    }
});

it('cannot be broken out of by a product name', function () {
    /*
     * `item_name` is a product name interpolated into the BODY of a <script>,
     * which is the one place htmlspecialchars() is no defence at all: the
     * browser HTML-decodes a script's contents before the JavaScript parser
     * sees them, so an escaped entity is decoded back into the character it was
     * hiding. The catalogue is editable from the admin and was imported from
     * somebody else's database, so a name with an apostrophe in it is ordinary
     * and a name with a tag in it is possible.
     *
     * json_encode() is what holds, and it holds for two separate reasons that
     * are worth naming because only one of them is obvious. It quotes and
     * escapes the string, which handles the apostrophe. And it escapes the
     * forward slash as `\/` by default — PHP does this unless
     * JSON_UNESCAPED_SLASHES is passed — which is what stops a `</script>`
     * inside the name from CLOSING THE TAG during HTML parsing, before any
     * JavaScript rule applies.
     *
     * ── WHAT THIS CASE REPLACED, AND WHY ──────────────────────────────────
     *
     * It was first written about the exponent form: a price large enough that
     * (string) (float) prints 1.0E+15, which is a syntax error in an object
     * literal. Two measurements killed it. PHP's default `precision` is 14, so
     * the 100-billion-fils fixture printed "1000000000" and the mutation stayed
     * GREEN; and a price large enough to actually reach the exponent form —
     * 1.0e17 fils — 500s the product page before the tag is ever built, so the
     * hazard cannot be reached through a fetch at all. The docblock on
     * MarketingPixels::viewContent() states the exponent risk and it is real,
     * but it is not reachable with a price this schema can hold, so asserting
     * it here would have been a test about PHP's ini file. This one is about
     * the shop.
     *
     * MUTATION: interpolate `{$product->name}` without json_encode() in
     * viewContent(). Red — the closing tag lands in the page.
     */
    mpEnable();

    $product = mpProduct(12600);
    $product->forceFill(['name' => "Round' Lab </script><script>window.kbbPwned=1;</script>"])->save();

    $html = test()->get('/product/mp-serum/')->assertOk()->getContent();

    /*
     * The needle is an OPENING SCRIPT TAG followed by the payload, not the
     * payload on its own. The name is printed in the <title>, the <h1> and the
     * breadcrumb as ordinary escaped text, so `window.kbbPwned` appears in this
     * page several times over and appears there harmlessly — an assertion on
     * the bare string is red against perfectly safe output, which is how the
     * first draft of this line failed.
     *
     * Nor is `<script>window.kbbPwned` the needle, which was the second draft
     * and is also wrong: json_encode escapes the SLASH, not the angle bracket,
     * so the payload legitimately reads
     *
     *     item_name:"Round' Lab <\/script><script>window.kbbPwned=1;<\/script>"
     *
     * and an opening tag sitting inside a JavaScript string does nothing at
     * all. Only `</script` ends a script element during HTML parsing, which is
     * precisely why escaping the slash is the whole defence.
     *
     * So the needle is the UNESCAPED closing tag immediately followed by the
     * payload's own opening one — the exact byte sequence that would have to
     * appear for the breakout to have happened.
     */
    expect(str_contains($html, '</script><script>window.kbbPwned'))
        ->toBeFalse('The product name closed the pixel script tag and opened one of its own.');

    // The pixel's own tag is one tag: the name is inside it, with its slash
    // escaped, rather than having ended it.
    $ga = mpPayload($html, "gtag('event','view_item'");
    expect(str_contains($ga, '<\/script>'))->toBeTrue("The name's closing tag was not escaped in: {$ga}");

    // And the tag still works: the payload is intact and still carries the
    // price. An escape that broke the event would be a different bug.
    expect(mpNumber(mpPayload($html, "gtag('event','view_item'"), 'value'))->toBe('126');
});
