<?php
/*
 * Lane PR — write settings into the preview database between shots.
 *
 *   PR_SET='grid_skin=spotlight,card_pad_m=8,show_new=-' sh tools/pr-set.sh [port]
 *
 * `key=value` writes the row; `key=-` deletes it, which is "the shipped
 * default" for every key here. Values go through the owning class's own cast
 * where there is one (ProductStyles, SiteLayout), so the harness cannot store
 * anything the admin screen could not.
 */
$pairs = array_filter(array_map('trim', explode(',', (string) getenv('PR_SET'))));

foreach ($pairs as $pair) {
    [$key, $value] = array_map('trim', explode('=', $pair, 2)) + [1 => ''];

    if ($value === '-') {
        \App\Models\Setting::query()->whereIn('key', [$key, 'layout_'.$key])->delete();
        echo "{$key} = shipped default (row deleted)\n";

        continue;
    }

    if (array_key_exists($key, \App\Services\SiteLayout::SCHEMA)) {
        $result = app(\App\Services\SiteLayout::class)->save([$key => $value]);
        echo "{$key} = {$value} (site layout: ".json_encode($result).")\n";

        continue;
    }

    if ($key === 'grid_skin' || array_key_exists($key, \App\Services\ProductStyles::SCHEMA)) {
        app(\App\Services\ProductStyles::class)->save([$key => $value]);
        echo "{$key} = {$value}\n";

        continue;
    }

    throw new RuntimeException('not a key this harness knows: '.$key);
}

\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();
app(\App\Services\SettingsService::class)->flush();
