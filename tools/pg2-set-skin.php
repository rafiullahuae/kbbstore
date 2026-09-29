<?php
/*
 * Point the preview's `grid_skin` at one treatment, between shots.
 *
 * ── WHY A SCRIPT AND NOT A QUERY PARAMETER ──────────────────────────────────
 *
 * Because the thing being photographed is the DEFAULT. GridSkins::resolve()
 * reads `grid_skin` off the settings table for every grid that does not name
 * its own, and that is the exact path the owner's "keep this design by default
 * from backend" travels down. A `?skin=` added for the harness would photograph
 * a code path the shop does not have.
 *
 * ── THE THREE MEMOS, AND WHY ALL THREE ──────────────────────────────────────
 *
 * CLAUDE.md records that Setting::map() memoises in a process-level static as
 * well as in the cache. This script is a separate process from the server, so
 * its own static does not matter — the CACHE does, and it is a file store the
 * server reads. Flushing one and not the other leaves the next screenshot
 * showing the previous treatment, which is a failure that looks exactly like a
 * CSS mistake.
 *
 *   php artisan tinker tools/pg2-set-skin.php   with KBB_SKIN in the environment
 */
$skin = (string) (getenv('KBB_SKIN') ?: 'classic');

if (! \App\Support\GridSkins::exists($skin)) {
    throw new RuntimeException('not a skin: '.$skin);
}

\App\Models\Setting::query()->updateOrCreate(
    ['key' => 'grid_skin'],
    ['value' => $skin, 'autoload' => true]
);

\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();
app(\App\Services\SettingsService::class)->flush();

echo 'grid_skin = '.$skin."\n";
