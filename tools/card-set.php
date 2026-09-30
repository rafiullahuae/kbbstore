<?php
/*
 * Move Appearance → Product styles → Card content in the preview, between shots.
 *
 *   KBB_SHOW_BRAND=0|1  KBB_SHOW_CAT=0|1  KBB_SHOW_RATE=0|1  KBB_SKIN=<skin>
 *   php artisan tinker tools/card-set.php
 *
 * ── WHY A SCRIPT AND NOT A QUERY PARAMETER ──────────────────────────────────
 *
 * Because the thing being photographed is the SETTING. ProductStyles::all()
 * reads these keys off the settings table on every storefront render, and that
 * is the exact path "i want to hide the brand name, category name by default"
 * travels down. A `?brand=0` added for the harness would photograph a code path
 * the shop does not have. tools/pg2-set-skin.php carries the same argument.
 *
 * ── AN UNSET KEY IS NOT THE SAME AS A KEY SET TO THE DEFAULT ────────────────
 *
 * ProductStyles::all() falls back to the SCHEMA default only when the row is
 * absent, and this lane's change is to that default. So "off" here DELETES the
 * row rather than writing 0: writing 0 would photograph a shop whose owner has
 * been to that screen, and the picture that matters is the shop as the package
 * leaves it, with no row at all. `1` writes the row, because that IS a shop
 * whose owner has switched it back on.
 *
 * ── THE THREE MEMOS, AND WHY ALL THREE ──────────────────────────────────────
 *
 * CLAUDE.md records that Setting::map() memoises in a process-level static as
 * well as in the cache. This script is a separate process from the server, so
 * its own static does not matter — the CACHE does, and it is a file store the
 * server reads. Flushing one and not the other leaves the next screenshot
 * showing the previous state, which is a failure that looks exactly like a CSS
 * mistake.
 */
$apply = function (string $key, string $env): void {
    $raw = getenv($env);

    if ($raw === false || $raw === '') {
        return;
    }

    if ((string) $raw === '1') {
        \App\Models\Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => '1', 'autoload' => true]
        );
        echo $key." = 1 (row written)\n";

        return;
    }

    \App\Models\Setting::query()->where('key', $key)->delete();
    echo $key." = shipped default (row deleted)\n";
};

$apply('show_brand', 'KBB_SHOW_BRAND');
$apply('show_category', 'KBB_SHOW_CAT');
$apply('show_rating', 'KBB_SHOW_RATE');

$skin = (string) (getenv('KBB_SKIN') ?: '');

if ($skin !== '') {
    if (! \App\Support\GridSkins::exists($skin)) {
        throw new RuntimeException('not a skin: '.$skin);
    }

    \App\Models\Setting::query()->updateOrCreate(
        ['key' => 'grid_skin'],
        ['value' => $skin, 'autoload' => true]
    );
    echo 'grid_skin = '.$skin."\n";
}

\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();
app(\App\Services\SettingsService::class)->flush();
