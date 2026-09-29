<?php
/*
 * What the "where it shows" rules cost a product page. (Lane PT, round 2)
 *
 * Measured on the preview, THROUGH THE ROUTER -- the number the shopper waits
 * for -- with a warm-up pass discarded first, for the same reason
 * StorefrontQueryBudgetTest has one: several caches here live for the life of
 * the process, so a cold-then-warm comparison would report a fall that has
 * nothing to do with the tabs.
 *
 * FOUR COLUMNS, and the pair that answers the coordinator's question is the
 * first two: the same six tabs all `global` (what the shop did before this
 * change) against the same six carrying a rule each.
 */
$slugs = [
    'toner (Skincare > Toners, Anua)' => 'lanept-heartleaf-toner',
    'LED mask (Devices, Medicube)' => 'lanept-led-mask',
    'Glow set (type=set)' => 'lanept-glow-set',
    'moisturiser (matches nothing)' => 'lanept-ceramide-moisturiser',
];

$reset = function () {
    \App\Support\ProductTabs::flush();
    \App\Services\SettingsService::forgetMemo();
    \App\Models\Setting::flushMap();
    \App\Services\Translation\TranslationStore::flush();
};

$measure = function (string $uri) use ($reset): int {
    $reset();

    app(\Illuminate\Contracts\Http\Kernel::class)
        ->handle(\Illuminate\Http\Request::create($uri, 'GET'));

    $n = 0;
    \Illuminate\Support\Facades\DB::listen(function () use (&$n) { $n++; });

    $response = app(\Illuminate\Contracts\Http\Kernel::class)
        ->handle(\Illuminate\Http\Request::create($uri, 'GET'));

    \Illuminate\Support\Facades\DB::getEventDispatcher()
        ->forget(\Illuminate\Database\Events\QueryExecuted::class);

    return $response->getStatusCode() === 200 ? $n : -1;
};

/* Snapshot the seeded rules so they can be put back exactly. */
$saved = [];

foreach (\App\Models\ProductTab::query()->whereNull('product_id')->get() as $row) {
    $saved[$row->id] = ['audience' => $row->audience, 'audience_ids' => $row->audience_ids];
}

$out = [];

/* BEFORE: every global tab targeted at everything, which is what every row in
   this table held the moment before this package applied. */
\App\Models\ProductTab::query()->whereNull('product_id')
    ->update(['audience' => 'global', 'audience_ids' => null]);
\App\Support\ProductTabs::flush();

foreach ($slugs as $label => $slug) {
    $out[$label]['before — every tab global'] = $measure('/product/'.$slug.'/');
}

/* AFTER: the rules the seed wrote — one of each of the five. */
foreach ($saved as $id => $row) {
    \App\Models\ProductTab::query()->whereKey($id)->update([
        'audience' => $row['audience'],
        'audience_ids' => $row['audience_ids'] === null ? null : json_encode($row['audience_ids']),
    ]);
}
\App\Support\ProductTabs::flush();

foreach ($slugs as $label => $slug) {
    $out[$label]['after — five rule types'] = $measure('/product/'.$slug.'/');
}

/* AND THE ONE THAT MATTERS MOST: no authored tabs at all, which is the shop on
   the day the package applies. */
$rows = \App\Models\ProductTab::all();
\App\Models\ProductTab::query()->delete();
\App\Support\ProductTabs::flush();

foreach ($slugs as $label => $slug) {
    $out[$label]['no authored tabs at all'] = $measure('/product/'.$slug.'/');
}

foreach ($rows as $row) {
    $fresh = new \App\Models\ProductTab;
    $fresh->forceFill($row->getAttributes());
    $fresh->exists = false;
    $fresh->save();
}

\App\Support\ProductTabs::flush();

$json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

/*
 * WRITTEN INSIDE THIS LANE'S OWN WORKTREE, UNDER A pt- NAME. (Lane PT)
 *
 * Not /tmp and not the session scratchpad: both are shared between every lane
 * on this machine, and a generic filename there has already cost one lane a
 * truncated suite log that a second lane then copied into its own result. The
 * two lanes could not tell whose numbers were in it. A measurement nobody can
 * attribute is not a measurement.
 *
 * storage/framework/testing/* is git-ignored, so this travels nowhere.
 */
$path = storage_path('framework/testing/pt-logs/pt-query-counts.json');
@mkdir(dirname($path), 0775, true);
@file_put_contents($path, $json."\n");

echo $json, "\n";
echo 'tabs restored: ', \App\Models\ProductTab::query()->count(), "\n";
echo 'written to: ', $path, "\n";
