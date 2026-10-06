<?php

declare(strict_types=1);

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\SettingsService;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

/*
 * THE SETTINGS MAP IS READ FROM THE CACHE ONCE PER PAGE.             (Lane SP)
 *
 * The owner, 6 October: "i'm noticing the inner pages are loading slightly
 * slow, before it was super fast." What it looked like, measured on a
 * live-scale MySQL preview (1,200 products, file cache as on Cloudways):
 *
 *   category page   3,162 reads of `kbb.settings` at 2.60.403, 3,824 at HEAD,
 *                   each a file open + read + unserialize of the whole map;
 *                   100 ms -> 114 ms of PHP with 5 SQL queries either way.
 *   with the memo   19 cache reads in total, 29 ms.
 *
 * SiteLayout::all() asks for its 134 keys one get() at a time and is called
 * from everywhere, so every new SiteLayout->get() (sold-out mode, filters,
 * shop links ...) added 134 cache reads to the page without one extra query.
 * StorefrontQueryBudgetTest could not see it; this file can.
 *
 * MUTATION: delete the `prependMiddleware(SettingsRequestMemo::class)` line in
 * AppServiceProvider::boot() (or make SettingsService::remembered() always go to the
 * cache) and the first test reads thousands, not one.
 */

function srmReads(string $path): int
{
    $n = 0;
    $listener = static function ($e) use (&$n): void {
        if ($e->key === 'kbb.settings') {
            $n++;
        }
    };
    Event::listen([CacheHit::class, CacheMissed::class], $listener);
    SettingsService::forgetMemo();
    app()->forgetScopedInstances();

    test()->get($path)->assertOk();

    // Listeners accumulate, but each call counts into its own $n, so an
    // earlier page's listener never adds to a later page's number.
    return $n;
}

it('reads the settings map once per storefront page, not once per setting', function () {
    test()->seed(\Database\Seeders\DatabaseSeeder::class);
    $product = Product::query()->visible()->firstOrFail();
    $category = Category::query()->whereHas('products')->firstOrFail();
    $brand = Brand::query()->whereHas('products')->firstOrFail();

    $pages = [
        'home' => '/',
        'shop' => '/shop/',
        'search' => '/shop/?s=a',
        'category' => '/collections/'.$category->slug.'/',
        'brand' => '/brands/'.$brand->slug.'/',
        'product' => '/product/'.$product->slug.'/',
    ];

    $reads = [];
    foreach ($pages as $label => $path) {
        srmReads($path);                      // warm: the map is in the cache
        $reads[$label] = srmReads($path);
    }

    foreach ($reads as $label => $n) {
        expect($n)->toBeLessThanOrEqual(1, "{$label} read kbb.settings {$n} times in one request");
    }
});

it('reads a setting saved during the request back as the new value', function () {
    $settings = app(SettingsService::class);
    $settings->set('srm_probe', 'before');
    $settings->set('srm_direct', 'before');

    Route::get('/__srm-probe', function () {
        $s = app(SettingsService::class);
        $first = $s->get('srm_probe');

        // Through the service.
        $s->set('srm_probe', 'after');
        $second = $s->get('srm_probe');

        // Around it, the way BrandRename / AdminPathService / OwnerAppPath
        // do: write the table, forget the key.
        $before = $s->get('srm_direct');
        DB::table('settings')->where('key', 'srm_direct')->update(['value' => 'after']);
        Cache::forget('kbb.settings');
        $direct = $s->get('srm_direct');

        return response()->json([$first, $second, $before, $direct, SettingsService::requestMemoActive()]);
    });

    expect(test()->get('/__srm-probe')->assertOk()->json())->toBe(['before', 'after', 'before', 'after', true])
        ->and(SettingsService::requestMemoActive())->toBeFalse();
});

it('memoises nothing outside a web request, and ends the memo when a page throws', function () {
    $settings = app(SettingsService::class);
    $settings->set('srm_cli', 'one');
    expect($settings->get('srm_cli'))->toBe('one');

    // A second process (the admin) rewrites the cached map: artisan, queue
    // jobs and a test's own setup see it at once, as they always have.
    Cache::forever('kbb.settings', ['srm_cli' => 'two'] + $settings->all());
    expect(SettingsService::requestMemoActive())->toBeFalse()
        ->and($settings->get('srm_cli'))->toBe('two');

    Route::get('/__srm-throws', function () {
        app(SettingsService::class)->get('srm_cli');
        throw new RuntimeException('page fell over');
    });

    test()->get('/__srm-throws')->assertStatus(500);
    expect(SettingsService::requestMemoActive())->toBeFalse();
});

it('wraps every request: the memo middleware is registered exactly once, outermost', function () {
    $global = app(\Illuminate\Contracts\Http\Kernel::class)->getGlobalMiddleware();

    // Outermost, so every other middleware and the page itself read the memo.
    expect(array_count_values($global)[\App\Http\Middleware\SettingsRequestMemo::class] ?? 0)->toBe(1)
        ->and($global[0])->toBe(\App\Http\Middleware\SettingsRequestMemo::class);

    // Registered from a file a package can carry: UpdateGuard refuses
    // bootstrap/, so a line there would never reach the shop.
    expect((string) file_get_contents(base_path('bootstrap/app.php')))->not->toContain('SettingsRequestMemo');
});
