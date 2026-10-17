<?php
/*
 * Lane HT -- php -S router for the Product tabs PREVIEWS. Not a shop file and
 * never shipped: routes/web.php is untouched. It serves the ARF preview webroot
 * exactly as tools/m1-router.php does, and adds ONE route group at boot time:
 *
 *   /ht-preview/{a..e}      (and /ar/ht-preview/{a..e}, via SetLocaleFromPath)
 *
 * rendered through the real layouts.store and the real <x-product-card>, on the
 * preview database. Start with tools/ht-preview.sh. The preview has no orders
 * or page views, so its Trending tab stands in with the daily-random sort.
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (! preg_match('#^/(ar/)?ht-preview/[a-e]/?$#', $path)) {
    return require __DIR__.'/m1-router.php';
}

$base = dirname(__DIR__);
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';

$app->booted(function () use ($base) {
    \Illuminate\Support\Facades\View::addNamespace('ht', $base.'/tools/ht-preview');
    \Illuminate\Support\Facades\Route::middleware('web')->get('/ht-preview/{v}', function (string $v) {
        $grid = app(\App\Services\GridSections::class);
        $sun = (int) \App\Models\Category::query()->where('slug', 'sunscreens')->value('id');
        $ar = app()->getLocale() === 'ar';
        $tabs = [
            ['key' => 'best', 'title' => $ar ? 'الأكثر مبيعًا' : 'Best Sellers', 'href' => '/shop/?orderby=popularity', 'items' => $grid->pool('bestsellers', 0, 0, [], 8)],
            ['key' => 'trend', 'title' => $ar ? 'الرائج في الجمال الكوري' : 'Trending K-Beauty', 'href' => '/shop/', 'items' => $grid->pool('query', 0, 0, [], 8, null, false, ['sort' => 'random'])],
            ['key' => 'new', 'title' => $ar ? 'وصل حديثًا' : 'New Arrivals', 'href' => '/shop/?orderby=date', 'items' => $grid->pool('newest', 0, 0, [], 8)],
            ['key' => 'sun', 'title' => $ar ? 'واقيات الشمس' : 'Sunscreens', 'href' => '/product-category/sunscreens/', 'items' => $grid->pool('category', 0, $sun, [], 8)],
            ['key' => 'sale', 'title' => $ar ? 'عروض' : 'On Sale', 'href' => '/shop/', 'items' => $grid->pool('onsale', 0, 0, [], 8)],
        ];

        return view('ht::preview', ['v' => $v, 'tabs' => $tabs, 'ar' => $ar]);
    })->where('v', '[a-e]');
});

$app->handleRequest(\Illuminate\Http\Request::capture());
