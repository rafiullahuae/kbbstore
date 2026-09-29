<?php
/*
 * What a product page COSTS, measured on the preview's own data. (Lane PT)
 *
 * The suite measures ProductTabs::forProduct() in isolation. This measures the
 * whole product page through the router -- the number the owner actually waits
 * for -- at 0, 1 and 6 authored tabs, with a warm-up pass first for the same
 * reason StorefrontQueryBudgetTest has one: several caches here live for the
 * life of the PROCESS, so a cold-then-warm comparison would report a fall that
 * has nothing to do with the tabs.
 */
$slug = 'lanept-ceramide-moisturiser';

$product = \App\Models\Product::where('slug', $slug)->firstOrFail();

$reset = function () {
    \App\Support\ProductTabs::flush();
    \App\Services\SettingsService::forgetMemo();
    \App\Models\Setting::flushMap();
    \App\Services\Translation\TranslationStore::flush();
};

$measure = function (string $uri) use ($reset): int {
    $reset();

    // Warm-up, discarded.
    app(\Illuminate\Contracts\Http\Kernel::class)
        ->handle(\Illuminate\Http\Request::create($uri, 'GET'));

    $n = 0;
    \Illuminate\Support\Facades\DB::listen(function () use (&$n) { $n++; });

    $response = app(\Illuminate\Contracts\Http\Kernel::class)
        ->handle(\Illuminate\Http\Request::create($uri, 'GET'));

    \Illuminate\Support\Facades\DB::getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);

    return $response->getStatusCode() === 200 ? $n : -1;
};

$uri = '/product/'.$slug.'/';

/* Every authored tab out of the way, which is the shop on the day this package
   applies: the table exists and is empty. */
$kept = \App\Models\ProductTab::all();
\App\Models\ProductTab::query()->delete();
\App\Support\ProductTabs::flush();

$zero = $measure($uri);

// One tab of its own.
$one = \App\Models\ProductTab::create([
    'product_id' => $product->id, 'title' => 'One', 'body' => '<p>One.</p>',
    'position' => 500, 'is_enabled' => true,
]);

$oneTab = $measure($uri);

for ($i = 2; $i <= 6; $i++) {
    \App\Models\ProductTab::create([
        'product_id' => $product->id, 'title' => 'Tab '.$i, 'body' => '<p>Body '.$i.'.</p>',
        'position' => 500 + $i, 'is_enabled' => true,
    ]);
}

$sixTabs = $measure($uri);

// Put the preview back exactly as the seed left it.
\App\Models\ProductTab::query()->delete();

foreach ($kept as $row) {
    $fresh = $row->replicate();
    $fresh->id = $row->id;
    $fresh->exists = false;
    $fresh->save();
}

\App\Support\ProductTabs::flush();

echo json_encode([
    'page' => $uri,
    'queries_with_0_custom_tabs' => $zero,
    'queries_with_1_custom_tab' => $oneTab,
    'queries_with_6_custom_tabs' => $sixTabs,
    'tabs_restored' => \App\Models\ProductTab::query()->count(),
], JSON_PRETTY_PRINT), "\n";
