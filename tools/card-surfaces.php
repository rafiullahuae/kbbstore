<?php
/*
 * LANE CARD, round three — make the surfaces that are 404 or empty REACHABLE,
 * so "apply this everywhere" can be measured on them instead of assumed.
 *
 *   php artisan tinker tools/card-surfaces.php
 *
 * Round two walked eight URLs and reported one card height on each. That is a
 * claim about the pages the walk happened to name. Five more surfaces of this
 * shop draw a product tile and NONE of them was measured, because every one is
 * behind a gate the fixture did not open:
 *
 *   /concern/<slug>/          404 until MIN_PRODUCTS products are TAGGED for
 *                             the concern (ConcernCollections::isLive)
 *   /routines                 404 until the build_my_routine module is on
 *   /my-wishlist              renders the EMPTY state for a guest with no
 *                             cookie -- which is what round two measured and
 *                             recorded as "none signed out"
 *   the FBT block             off by default (frequently_bought)
 *   the heart on every card   off by default (wishlist)
 *
 * A surface that is 404 measures as "no product grid on this page", and
 * tools/card-measure.cjs prints exactly that -- which reads like a pass and is
 * the false green CLAUDE.md warns about. Opening the gate is what turns those
 * five lines into evidence.
 *
 * NOTHING HERE IS A SHIPPED DEFAULT. Every module this switches on is off in
 * the package and stays off; this is the harness putting the shop into the
 * state a shop that HAS turned them on is in, which is the only state in which
 * those pages can be photographed at all.
 */
$mod = function (string $key, bool $on): void {
    \App\Models\ModuleToggle::query()->updateOrCreate(['module' => $key], ['enabled' => $on]);
    echo 'module '.$key.' = '.($on ? 'on' : 'off')."\n";
};

$mod('build_my_routine', true);
$mod('wishlist', true);
$mod('frequently_bought', true);

/*
 * THE CONCERN TAG IS A JSON ARRAY IN A TEXT COLUMN, matched with LIKE '%"slug"%'
 * -- see ConcernCollections::query(). Writing the array is the same thing
 * Catalog -> Build my routine writes, so the page that comes up is the page the
 * owner's own tagging produces.
 *
 * FOUR products and not three: MIN_PRODUCTS is 3 and a page sitting exactly on
 * its floor cannot tell "the gate opened" from "the gate is one product wide".
 * Four also puts the grid onto a SECOND ROW at 390px, which is the whole
 * question -- `height:100%` equalises a row against itself and says nothing
 * about the row above it.
 */
$tagged = \App\Models\Product::query()->visible()->orderBy('id')->limit(5)->get();

/*
 * THE ROLE IS WHAT FILLS A ROUTINE STEP, AND THE CONCERN IS NOT.
 *
 * /concern/<slug>/ needs only routine_concerns. /routines/<slug> needs BOTH:
 * BuildMyRoutine reads `routine_role` to decide WHICH STEP a product can fill
 * (RoutineRoles: cleanse / tone / treat / moisturise / protect) and
 * `routine_concerns` to decide which routine it belongs to. Tagging the concern
 * alone -- which is what the first version of this script did -- left
 * /routines/hydration answering 200 with every step unfilled and NOT ONE TILE
 * on it, which measures as "no product grid on this page" and reads like a
 * pass. One product per role, so all five steps fill.
 */
$roles = ['cleanse', 'tone', 'treat', 'moisturise', 'protect'];

foreach ($tagged as $i => $p) {
    $p->routine_concerns = json_encode(['hydration']);
    $p->routine_role = $roles[$i] ?? 'treat';
    $p->save();
}

echo 'tagged '.$tagged->count()." products for /concern/hydration/ and /routines/hydration\n";

\App\Models\Setting::flushMap();
\App\Services\SettingsService::forgetMemo();
app(\App\Services\SettingsService::class)->flush();
/* 'kbb.modules' as a literal, because SettingsService::MODULES_KEY is private.
   moduleEnabled() is a rememberForever over the whole ModuleToggle table, so a
   toggle written above is invisible to the SERVER until this line runs -- and
   the server reads the FILE cache, which is why card-evidence.sh's
   preview_artisan() sets CACHE_STORE=file for this script too. */
\Illuminate\Support\Facades\Cache::forget('kbb.modules');
echo "caches flushed\n";
