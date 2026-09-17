<?php

declare(strict_types=1);

/**
 * Lane CZ — the last delivery promise that was on EVERY page, and three more on
 * the home page that were not reading the shop at all.
 *
 * WHAT WAS WRONG.
 *
 * 1. THE ANNOUNCEMENT BAR. It carried a free-delivery figure resolved from
 *    `store_country` — the shop's own country — so a shopper in Riyadh was
 *    quoted the Dubai threshold with Dubai named beside it. And when the shop
 *    had NO free-delivery method for that country, the template printed a
 *    figure of its own rather than nothing, so a shop that has never offered
 *    free delivery advertised one.
 *
 *    AND IT IS NOT ON ANY PAGE, which is worth stating plainly because it is
 *    not what anyone expects of site chrome. `partials/announcement.blade.php`
 *    is included by nothing — not the layout, not the header, not one view in
 *    this repository — and `git log -S` finds no commit in which it ever was.
 *    So the bar is a loaded claim rather than a live one: the day the
 *    integrator wires this partial into the layout it is meant for, whatever it
 *    says ships on every page at once. That is precisely the posture Lane CM
 *    found `trust_delivery_text` in on the product page, and it was repaired
 *    for the same reason: a defect one keystroke behind the page is still a
 *    defect.
 *
 *    Being included nowhere, it has no page to be scraped from, so the cases
 *    below render the partial itself against a real request, with the real view
 *    composer attached — see czBar(). What that composer resolves IS live on
 *    every page: `layouts.store` prints the same threshold into
 *    window.KBB.freeShip, and that is asserted against real pages further down.
 *
 * 2. THE HOME PAGE TICKER printed a threshold typed into the Blade. It did not
 *    read the shop's number at all: the owner raises it on Store → Shipping and
 *    the ticker goes on advertising the old one, to UAE visitors as much as to
 *    anybody. No country question attached — a plain bug.
 *
 * 3. THE HOME PAGE DELIVERY BAND read `free_shipping_threshold`, a setting with
 *    no admin writer anywhere in this application and exactly one reader: that
 *    line. The number the owner can actually edit lives on the free-shipping
 *    METHOD, and on Extended Delivery's per-country `free_from`.
 *
 * 4. THE ABOUT STATS AND THE TRUST ROW each restated one country's delivery
 *    terms as a literal, the stats block as a bare number in a row of three
 *    measured ones.
 *
 * NOTHING HERE INVENTS A FIGURE OR A TRANSIT TIME. Every number asserted below
 * is one the fixture wrote into the shop's own shipping configuration, and
 * every sentence is one the fixture typed as the owner would. Where the shop
 * records nothing for a destination, the assertion is that the page says
 * nothing — not that it says something softer.
 *
 * THE CLASS-NAME TRAP, as ShopperPathTruthTest and GeoDeliveryLineTest both
 * record: a bare class-name search of rendered HTML also matches the page's own
 * inlined CSS. Every assertion here either extracts an ELEMENT with an anchored
 * regex or looks for words a shopper would read.
 *
 * AND THERE IS NO SOURCE-SCANNING GUARD IN THIS FILE, deliberately. A regex
 * guard over a template reads comments and quoted strings as code, and four
 * lanes have now been bitten by one failing on its own explanatory prose. Every
 * claim below is asserted against RENDERED OUTPUT, where a comment cannot
 * reach.
 */

use App\Models\Category;
use App\Models\DeliveryCountry;
use App\Models\Product;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\ShippingZoneLocation;
use App\Services\CartService;
use App\Services\ExtendedDelivery;
use App\Services\SettingsService;
use App\Support\DeliveryLine;
use App\Support\Money;
use App\View\Composers\StoreComposer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    // Every cache these settings live behind. SettingsService holds a
    // forever-cache AND a per-process memo, and Setting::map() holds a second
    // static of its own — the trap CLAUDE.md records.
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
    Money::forgetConfig();

    app(SettingsService::class)->set('store_country', 'AE');
});

/** The two live zones, with the two thresholds production actually runs. */
function czZones(bool $uaeFree = true, bool $gulfFree = true): void
{
    $uae = ShippingZone::create(['name' => 'All UAE', 'position' => 0]);
    ShippingZoneLocation::create(['shipping_zone_id' => $uae->id, 'type' => 'country', 'code' => 'AE']);
    ShippingMethod::create([
        'shipping_zone_id' => $uae->id, 'type' => 'flat_rate',
        'title' => 'Delivery Charges', 'cost' => 2000, 'enabled' => true, 'position' => 0,
    ]);

    if ($uaeFree) {
        ShippingMethod::create([
            'shipping_zone_id' => $uae->id, 'type' => 'free_shipping',
            'title' => 'Free delivery', 'cost' => 0, 'min_amount' => 19900, 'enabled' => true, 'position' => 1,
        ]);
    }

    $gulf = ShippingZone::create(['name' => 'Gulf Countries', 'position' => 1]);
    foreach (['SA', 'KW', 'QA', 'BH', 'OM'] as $code) {
        ShippingZoneLocation::create(['shipping_zone_id' => $gulf->id, 'type' => 'country', 'code' => $code]);
    }
    ShippingMethod::create([
        'shipping_zone_id' => $gulf->id, 'type' => 'flat_rate',
        'title' => 'Shipping Charges', 'cost' => 15000, 'enabled' => true, 'position' => 0,
    ]);

    if ($gulfFree) {
        ShippingMethod::create([
            'shipping_zone_id' => $gulf->id, 'type' => 'free_shipping',
            'title' => 'Free delivery', 'cost' => 0, 'min_amount' => 160000, 'enabled' => true, 'position' => 1,
        ]);
    }
}

/** A page as a visitor the shop believes is in $country, or nobody in particular. */
function czPage(string $path = '/', ?string $country = null, ?string $tz = null): string
{
    $test = test();

    if ($tz !== null) {
        // The plaintext time-zone cookie the storefront posts on a first visit —
        // the second geo tier, and on this host possibly the only one that ever
        // fires. Unencrypted, which is how ExtendedDelivery::detect() reads it.
        $test = $test->withCredentials()
            ->withoutMiddleware(Illuminate\Cookie\Middleware\EncryptCookies::class)
            ->withUnencryptedCookie('kbb_tz', $tz);
    }

    if ($country !== null) {
        $test = $test->withHeader('CF-IPCountry', $country);
    }

    return $test->get($path)->assertOk()->getContent();
}

/**
 * The announcement bar as this visitor would read it, or null when it renders
 * nothing at all.
 *
 * RENDERED DIRECTLY, BECAUSE THERE IS NO PAGE TO REQUEST. The partial is
 * included by no view in this repository (see the file header), so nothing can
 * be fetched that contains it. What is exercised here is everything the bar has
 * apart from the layout that would include it: the REAL partial, the REAL view
 * composer that supplies its two variables, and a REAL request carrying the
 * visitor's geo signal — bound into the container first, so the composer and
 * ShopperCountry both answer for this visitor rather than for whoever asked
 * last.
 *
 * A route was tried first and is not available: every single-segment path is
 * already claimed by the page catch-all in routes/web.php, which this lane may
 * not edit, and a test fixture has no business in it either way.
 */
function czBar(?string $country = null, ?string $tz = null): ?string
{
    $request = Illuminate\Http\Request::create(
        '/', 'GET', [], $tz === null ? [] : ['kbb_tz' => $tz], [],
        $country === null ? [] : ['HTTP_CF_IPCOUNTRY' => $country],
    );

    app()->instance('request', $request);
    app()->forgetScopedInstances();

    $view = view('partials.announcement');
    app(StoreComposer::class)->compose($view);

    $html = $view->render();
    $m = [];

    return preg_match('/<div class="anno">(.*?)<\/div>/s', $html, $m) === 1
        ? trim(preg_replace('/\s+/', ' ', strip_tags($m[1])))
        : null;
}

/** window.KBB.freeShip as it reaches the browser on a real page. */
function czJsThreshold(string $path, ?string $country = null, ?string $tz = null): ?int
{
    $m = [];

    if (preg_match('/window\.KBB = (\{.*?\});/s', czPage($path, $country, $tz), $m) !== 1) {
        return null;
    }

    $decoded = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);

    return isset($decoded['freeShip']) ? (int) $decoded['freeShip'] : null;
}

/** The home page ticker's text, flattened. */
function czTicker(string $html): ?string
{
    $m = [];

    return preg_match('/<div class="tick[^"]*"><div>(.*?)<\/div><\/div>/s', $html, $m) === 1
        ? trim(preg_replace('/\s+/', ' ', strip_tags($m[1])))
        : null;
}

/** Every trust card on the page, as [title, line]. */
function czTrustCards(string $html): array
{
    $m = [];
    preg_match_all('/<div class="i"><span class="ic">.*?<\/span>\s*<div><b>(.*?)<\/b><span>(.*?)<\/span><\/div><\/div>/s', $html, $m, PREG_SET_ORDER);

    return array_map(static fn ($set) => [html_entity_decode($set[1]), html_entity_decode($set[2])], $m);
}

/** The About block's stat tiles, as [figure, caption]. */
function czAboutStats(string $html): array
{
    $block = [];

    if (preg_match('/<div class="astats">(.*?)<\/div>\s*<a class="lnk"/s', $html, $block) !== 1) {
        return [];
    }

    $m = [];
    preg_match_all('/<div><b>(.*?)<\/b><span>(.*?)<\/span><\/div>/s', $block[1], $m, PREG_SET_ORDER);

    return array_map(static fn ($set) => [trim($set[1]), trim($set[2])], $m);
}

/*
|------------------------------------------------------------------------------
| 1. The announcement bar — no page today, every page the day it is included
|    (see this file's header: partials/announcement.blade.php is included by
|    nothing, and these cases render the partial directly for that reason)
|------------------------------------------------------------------------------
*/

it('quotes the free-delivery threshold of the country the shopper is in', function () {
    /*
     * The defect, exactly. Both zones offer free delivery and the two figures
     * differ — 199 in the UAE, 1,600 in the Gulf — which is what production
     * runs. The bar resolved the threshold from `store_country`, so the Gulf
     * shopper was quoted Dubai's number on every page of the shop.
     */
    czZones();

    expect(czBar('AE'))->toContain('199');

    $saudi = czBar('SA');

    expect(str_contains((string) $saudi, '1,600'))
        ->toBeTrue('A shopper in Saudi Arabia was not shown their own free-delivery threshold: ' . (string) $saudi);
    expect(str_contains((string) $saudi, '199'))
        ->toBeFalse('A shopper in Saudi Arabia was quoted the UAE threshold: ' . (string) $saudi);
});

it('says nothing about free delivery to a country the shop offers none in', function () {
    // The Gulf zone charges a flat rate and has no free-shipping method: there
    // is no such offer there, so there is nothing true to say about one.
    czZones(uaeFree: true, gulfFree: false);

    $saudi = czBar('SA');

    expect($saudi)->not->toBeNull('The announcement bar vanished entirely.');
    expect(str_contains((string) $saudi, 'Free delivery'))
        ->toBeFalse('The bar advertised free delivery in a country the shop does not offer it in: ' . (string) $saudi);

    // And the rest of the strip survives: dropping the claim is not dropping
    // the bar.
    expect(str_contains((string) $saudi, 'Pay later with Tabby'))->toBeTrue();
    expect(str_contains((string) $saudi, '100% authentic'))->toBeTrue();
});

it('never invents a threshold for a shop that offers no free delivery at all', function () {
    /*
     * The fallback figure, which is the half of this that had nothing to do
     * with any country. With no free-shipping method anywhere, the template
     * printed a number of its own — so a shop that had never offered free
     * delivery advertised one, to everybody, on every page.
     */
    czZones(uaeFree: false, gulfFree: false);

    foreach ([['AE', 'a UAE shopper'], ['SA', 'a Saudi shopper'], [null, 'a visitor with no geo signal']] as [$code, $who]) {
        $bar = czBar($code);

        expect(str_contains((string) $bar, 'Free delivery'))
            ->toBeFalse("The bar advertised free delivery to {$who} in a shop that offers none: " . (string) $bar);
        expect(str_contains((string) $bar, '199'))
            ->toBeFalse("A free-delivery figure appeared for {$who} with nothing behind it: " . (string) $bar);
    }
});

it('makes no country claim in the site-wide bar', function () {
    /*
     * The wording, not the figure. Outside the checkout the country is a GUESS
     * — ShopperCountry::guessed() is true for a header, a time-zone cookie and
     * the store default alike — so naming it states the guess back to the
     * shopper as a fact about their order. The threshold alone is true for
     * whoever is reading it and asserts nothing about where they are.
     */
    czZones();

    foreach (['AE', 'SA', null] as $code) {
        expect(str_contains((string) czBar($code), 'UAE'))
            ->toBeFalse('The site-wide bar still names a country at a visitor who may not be in it.');
    }
});

it('falls back to the store country when nothing knows where the shopper is', function () {
    // NO GEO SOURCE IS A SUPPORTED STATE, and it is the one this host may
    // always be in: whether production receives CF-IPCountry has never been
    // established. Today's behaviour, unchanged.
    czZones();

    expect(czBar())->toContain('199');
});

it('reads the time-zone cookie as well as the header', function () {
    // The second geo tier. On this host it may be the only signal that ever
    // fires, so the bar has to answer for it too.
    czZones();

    $saudi = czBar(null, 'Asia/Riyadh');

    expect(str_contains((string) $saudi, '1,600'))
        ->toBeTrue('A shopper detected by time zone was not shown their own threshold: ' . (string) $saudi);
});

it('hands the visitor\'s own threshold to the browser on every page', function () {
    /*
     * THE ONE PLACE THIS VALUE IS LIVE TODAY. `layouts.store` prints the same
     * composer value into window.KBB.freeShip on every storefront page, so a
     * threshold resolved from `store_country` was being handed to a Saudi
     * shopper's browser on every page of the shop whether or not the bar that
     * was meant to print it had been wired in.
     *
     * Nothing in resources/js or the built bundle reads window.KBB.freeShip
     * today, which is exactly why it is worth pinning: the next thing that
     * reads it inherits whatever is put there, silently and site-wide.
     */
    czZones();

    $category = Category::create(['name' => 'Cleansers', 'slug' => 'cz-cleansers']);
    Product::create([
        'slug' => 'cz-' . Str::random(8), 'name' => 'Rice Toner', 'status' => 'publish',
        'is_visible' => true, 'price' => 13000, 'stock_status' => 'instock', 'category_id' => $category->id,
    ]);

    foreach (['/', '/shop', '/product-category/cz-cleansers'] as $path) {
        expect(czJsThreshold($path, 'SA'))
            ->toBe(160000, "{$path} handed the browser the wrong country's threshold.");
        expect(czJsThreshold($path, 'AE'))
            ->toBe(19900, "{$path} handed a UAE shopper the wrong threshold.");
    }
});

it('follows Extended Delivery\'s per-country free_from when it is switched on', function () {
    /*
     * With Extended Delivery on, the zones are not consulted at all and the
     * country list is authoritative. Its `free_from` is per country and is the
     * whole reason a single site-wide figure cannot be right.
     */
    czZones();
    app(SettingsService::class)->set(ExtendedDelivery::SETTING_ON, true);

    DeliveryCountry::create(['code' => 'AE', 'charge' => 2000, 'free_from' => 25000, 'enabled' => true, 'position' => 0]);
    DeliveryCountry::create(['code' => 'SA', 'charge' => 15000, 'free_from' => 90000, 'enabled' => true, 'position' => 1]);
    DeliveryCountry::create(['code' => 'KW', 'charge' => 15000, 'free_from' => null, 'enabled' => true, 'position' => 2]);

    expect(czBar('AE'))->toContain('250');
    expect(czBar('SA'))->toContain('900');

    // A served country with no threshold of its own gets no claim, rather than
    // the shop's own number standing in for it.
    expect(str_contains((string) czBar('KW'), 'Free delivery'))
        ->toBeFalse('A country whose Extended Delivery row records no free_from was given one anyway.');
});

/*
|------------------------------------------------------------------------------
| 2. A per-visitor value must never come out of a shared cache
|------------------------------------------------------------------------------
*/

it('never serves one visitor the threshold that another visitor warmed', function () {
    /*
     * THE BUG THIS EXISTS TO PREVENT, and it is one keystroke away at all
     * times. The comment over these header extras in StoreComposer used to say
     * they are "cached, since the header is on every page and none of this
     * varies per visitor" — over a block where exactly one of four keys goes
     * through Cache::remember(). Make the threshold vary per visitor while that
     * sentence still invites the next reader to wrap the block in one global
     * key, and the FIRST shopper to warm the cache decides what every shopper
     * afterwards is told.
     *
     * Interleaved deliberately, and each country asked TWICE with the other in
     * between: a cache keyed globally passes a test that asks each country once
     * in a fresh process, and fails this one.
     */
    czZones();

    $order = ['SA' => '1,600', 'AE' => '199', 'SA2' => '1,600', 'AE2' => '199'];

    foreach ($order as $label => $expected) {
        $code = str_starts_with($label, 'SA') ? 'SA' : 'AE';
        $bar = czBar($code);

        expect(str_contains((string) $bar, $expected))->toBeTrue(
            "Request {$label} was shown {$bar} instead of the {$code} threshold {$expected}. "
            . 'A per-visitor value is being served out of a cache shared between visitors.'
        );
    }
});

it('resolves the threshold once per request however many places print it', function () {
    /*
     * The other side of the same coin: the answer is memoised ON THE REQUEST,
     * which is per visitor by construction — nothing it computes can outlive
     * the request that asked — rather than in a process static, the trap
     * CLAUDE.md records against Setting::map(). The home page asks three times
     * (bar, band, ticker and trust row) and must pay once.
     */
    czZones();
    app(SettingsService::class)->set(ExtendedDelivery::SETTING_ON, true);
    DeliveryCountry::create(['code' => 'SA', 'charge' => 15000, 'free_from' => 90000, 'enabled' => true, 'position' => 0]);

    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        if (str_contains($q->sql, 'delivery_countries')) {
            $queries[] = $q->sql;
        }
    });

    czPage('/', 'SA');

    expect(count($queries))->toBeLessThanOrEqual(
        1,
        'The per-country delivery table was read once per place that prints the threshold: ' . count($queries) . ' times.'
    );
});

/*
|------------------------------------------------------------------------------
| 3. The home page's own hard-coded claims
|------------------------------------------------------------------------------
*/

it('reads the shop\'s real threshold in the ticker instead of a number typed into the page', function () {
    /*
     * No country question attached: the ticker's figure was a literal, so the
     * owner raising the threshold on Store → Shipping left this line
     * advertising the old one to EVERY visitor, including the UAE ones it was
     * written for.
     */
    czZones();
    ShippingMethod::where('type', 'free_shipping')->update(['min_amount' => 30000]);

    $ticker = czTicker(czPage('/', 'AE'));

    expect(str_contains((string) $ticker, '300'))
        ->toBeTrue('The ticker ignored the threshold the owner set: ' . (string) $ticker);
    expect(str_contains((string) $ticker, '199'))
        ->toBeFalse('The ticker is still printing a figure of its own: ' . (string) $ticker);
});

it('does not promise one country\'s delivery speed in the ticker to everyone', function () {
    czZones();

    $saudi = czPage('/', 'SA');

    expect(str_contains((string) czTicker($saudi), 'UAE'))
        ->toBeFalse('The ticker still promises UAE delivery to a shopper detected in Saudi Arabia.');

    // The whole page, not only the ticker: the sentence was looped twice and
    // repeated in the trust row and the About block.
    expect(str_contains($saudi, 'day delivery across the UAE'))
        ->toBeFalse('A UAE delivery window is still printed somewhere on the page to a Saudi shopper.');
});

it('shows the owner\'s own delivery sentence in the ticker once he has written one', function () {
    // The mechanism, in the owner's words. Nothing in the shipped code says
    // this, and no Gulf transit time is invented to fill the gap until he does.
    czZones();
    app(SettingsService::class)->set(DeliveryLine::SETTING, [['country' => 'SA', 'text' => 'Delivered across Saudi Arabia']]);

    expect(czTicker(czPage('/', 'SA')))->toContain('Delivered across Saudi Arabia');
});

it('keeps the UAE sentence in the ticker for the country it describes', function () {
    // Dropping the promise everywhere would be its own defect: it is true of
    // the country it names.
    czZones();
    app(SettingsService::class)->set('delivery_default_text', '1–3 days fast delivery all over UAE');

    expect(czTicker(czPage('/', 'AE')))->toContain('1–3 days fast delivery all over UAE');
});

it('states no delivery window as a statistic in the About block', function () {
    /*
     * The other three tiles are counted from the database. This one was two
     * digits typed into the template, wearing the same weight as three measured
     * figures, and it named no country — which made it worse, since the number
     * describes exactly one.
     */
    czZones();

    $stats = czAboutStats(czPage('/', 'AE'));

    expect($stats)->not->toBe([], 'The About stats block was not found — this assertion would pass vacuously.');

    foreach ($stats as [$figure, $caption]) {
        expect(str_contains(strtolower($caption), 'delivery'))
            ->toBeFalse("The About block still states a delivery window as a statistic: {$figure} {$caption}");
    }
});

it('builds the delivery trust card out of what the shop records', function () {
    czZones();
    app(SettingsService::class)->set('delivery_default_text', '1–3 days fast delivery all over UAE');

    $cards = czTrustCards(czPage('/', 'AE'));

    expect($cards)->not->toBe([], 'No trust cards were found — this assertion would pass vacuously.');

    $delivery = collect($cards)->first(fn ($c) => str_contains(strtolower($c[1]), 'delivery'));

    expect($delivery)->not->toBeNull('The delivery trust card disappeared for the country the shop does describe.');
    expect($delivery[1])->toContain('1–3 days fast delivery all over UAE');
    expect($delivery[1])->toContain('199');

    foreach ($cards as [$title, $line]) {
        expect(str_contains($title, 'UAE'))
            ->toBeFalse("A trust card still names one country in its title: {$title}");
    }
});

it('drops the delivery trust card entirely when the shop records nothing for the visitor', function () {
    /*
     * A trust card is a promise. With no delivery sentence for this country and
     * no free-delivery offer in it, there is no promise to make, and the row
     * closes up to the three that are still true rather than carrying a fourth
     * with nothing behind it.
     */
    czZones(uaeFree: true, gulfFree: false);

    $cards = czTrustCards(czPage('/', 'SA'));

    expect(count($cards))->toBe(3, 'The trust row still carries a delivery card with nothing behind it: '
        . json_encode($cards, JSON_UNESCAPED_UNICODE));

    foreach ($cards as [$title, $line]) {
        expect(str_contains(strtolower($line), 'delivery'))
            ->toBeFalse("A delivery claim survived in the trust row: {$title} — {$line}");
    }
});

it('leaves the home delivery band out altogether when it has nothing to say', function () {
    // An icon with no words beside it reads as a broken page rather than as
    // restraint, so the band goes with its two halves.
    czZones(uaeFree: true, gulfFree: false);

    $html = czPage('/', 'SA');

    expect(preg_match('/<div class="delivery[^"]*"/', $html))
        ->toBe(0, 'The home page rendered an empty delivery band.');
});

/*
|------------------------------------------------------------------------------
| 4. It costs nothing to ask where the shopper is
|------------------------------------------------------------------------------
*/

it('runs the same number of queries whoever the visitor is', function () {
    /*
     * The announcement bar is on every page, so anything it newly asks for is
     * charged to every page of the site. ShopperCountry is designed to cost
     * nothing — its one paid tier is handed in by the caller rather than
     * fetched — and the threshold is memoised on the Request, so a detected
     * visitor and an undetected one must cost exactly the same.
     *
     * Measured relatively rather than against a fixed ceiling:
     * StorefrontQueryBudgetTest owns the ceilings, and a second copy of them
     * here would be a second number to move.
     */
    czZones();

    $category = Category::create(['name' => 'Serums', 'slug' => 'cz-serums']);
    Product::create([
        'slug' => 'cz-' . Str::random(8), 'name' => 'Ginseng Serum', 'status' => 'publish',
        'is_visible' => true, 'price' => 13000, 'stock_status' => 'instock', 'category_id' => $category->id,
    ]);

    $count = function (string $path, ?string $country, ?string $tz = null) {
        SettingsService::forgetMemo();
        app()->forgetScopedInstances();

        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });

        czPage($path, $country, $tz);

        return $n;
    };

    foreach (['/', '/product-category/cz-serums'] as $path) {
        // Warm the process-level caches first — see StorefrontQueryBudgetTest's
        // header for why a cold-then-warm comparison measures the wrong thing.
        foreach ([null, 'AE', 'SA'] as $code) {
            $count($path, $code);
        }

        $blind = $count($path, null);

        foreach ([['AE', null], ['SA', null], [null, 'Asia/Riyadh']] as [$code, $tz]) {
            expect($count($path, $code, $tz))->toBe(
                $blind,
                sprintf('%s costs more for a detected visitor (%s) than for an undetected one.', $path, $code ?? $tz)
            );
        }
    }
});

/*
|------------------------------------------------------------------------------
| 5. The listing pages' meta descriptions — the promise search engines saw
|------------------------------------------------------------------------------
*/

/** The page's meta description, as a crawler would read it. */
function czMeta(string $html): ?string
{
    $m = [];

    return preg_match('/<meta name="description" content="(.*?)"/s', $html, $m) === 1
        ? html_entity_decode($m[1], ENT_QUOTES)
        : null;
}

it('promises no delivery window in a listing page\'s meta description', function () {
    /*
     * THE HARDEST PROMISE LEFT ANYWHERE IN THIS APPLICATION, and it was not on
     * the file that lane's brief named — a design mock under store/ that was
     * rendered by no route at all, and that Lane DZ has since deleted. The live
     * one was built in
     * ShopController::seoDescription() and CollectionController::seoCtx(), and
     * it ended every category, search, /shop and collection description with a
     * transit time attached to one country.
     *
     * Two things wrong, and the second is worse. It named a country, like every
     * claim this lane series has removed. And the window it named is not the
     * one the shop records: `delivery_default_text` says one to three days, so
     * a shopper who chose this shop out of a search result promising next-day
     * delivery had been told two different things before clicking.
     *
     * Asserted for a UAE visitor as much as a Saudi one, because a meta
     * description is one string per URL and a crawler is one of its readers —
     * the per-visitor machinery the rest of this lane uses must never reach it.
     */
    czZones();

    $category = Category::create(['name' => 'Toners', 'slug' => 'cz-toners']);

    for ($i = 0; $i < 3; $i++) {
        Product::create([
            'slug' => 'cz-meta-' . $i . '-' . Str::random(6), 'name' => 'Rice Toner ' . $i,
            'status' => 'publish', 'is_visible' => true, 'price' => 13000 + $i,
            'stock_status' => 'instock', 'category_id' => $category->id,
        ]);
    }

    foreach (['/product-category/cz-toners', '/shop', '/shop?s=toner', '/new-in'] as $path) {
        foreach (['AE', 'SA', null] as $code) {
            $desc = czMeta(czPage($path, $code));

            expect($desc)->not->toBeNull("No meta description was emitted on {$path}.");
            expect(stripos((string) $desc, 'deliver'))
                ->toBeFalse("{$path} still promises delivery in its meta description: {$desc}");
        }
    }
});

it('still describes the listing its meta description is on', function () {
    // Removing the promise must not hollow the description out: what is left is
    // still the name of the listing and a count of real rows.
    czZones();

    $category = Category::create(['name' => 'Toners', 'slug' => 'cz-toners']);
    Product::create([
        'slug' => 'cz-meta-' . Str::random(6), 'name' => 'Rice Toner',
        'status' => 'publish', 'is_visible' => true, 'price' => 13000,
        'stock_status' => 'instock', 'category_id' => $category->id,
    ]);

    $desc = (string) czMeta(czPage('/product-category/cz-toners'));

    expect($desc)->toContain('Toners');
    expect($desc)->toContain('K-Beauty Bliss');
});
