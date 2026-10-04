<?php
// Lane KW preview seed. The catalogue is tools/rf-seed.php's. The keyword bank
// rows below are SAMPLE signals for the screenshots only — this sandbox has no
// internet, so Autocomplete is switched off (seed_cap 0) and the sync runs on
// these plus the shop's own data.
require '/home/user/lane-kw/tools/rf-seed.php';
\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], ['name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner']);

use App\Services\Seo\Keywords\KeywordBank;
use App\Services\Seo\Keywords\KeywordConfig;
use App\Services\Seo\Keywords\KeywordSync;

KeywordConfig::saveOptions(['seed_cap' => 0]);
$names = \App\Models\Product::query()->with('brand:id,name')->limit(12)->get();
$rows = [];
foreach ($names as $i => $p) {
    $core = \App\Services\Seo\Keywords\EntityCatalog::core((string) $p->name, (string) $p->brand?->name);
    $words = implode(' ', array_slice(explode(' ', $core), 0, 3));
    $b = strtolower((string) $p->brand?->name);
    $rows[] = ['term' => $b.' '.$words, 'source' => 'gsc', 'score' => KeywordBank::scoreGsc(900 - $i * 60, 40 - $i * 3, 3.5 + $i), 'metrics' => ['impressions' => 900 - $i * 60, 'clicks' => 40 - $i * 3, 'position' => 3.5 + $i, 'pages' => ['/product/'.$p->slug.'/']]];
    $rows[] = ['term' => $b.' '.$words.' uae', 'source' => 'autocomplete', 'score' => KeywordBank::scoreAutocomplete(1), 'metrics' => ['seed' => $b, 'rank' => 2]];
    $rows[] = ['term' => $b.' '.$words.' price', 'source' => 'autocomplete', 'score' => KeywordBank::scoreAutocomplete(3), 'metrics' => ['seed' => $b, 'rank' => 4]];
}
foreach (['korean sunscreen uae', 'korean toner for sensitive skin', 'korean serum for dark spots', 'k beauty dubai', 'korean skincare abu dhabi'] as $i => $t) {
    $rows[] = ['term' => $t, 'source' => 'autocomplete', 'score' => KeywordBank::scoreAutocomplete($i), 'metrics' => ['seed' => 'korean skincare', 'rank' => $i + 1]];
}
$rows[] = ['term' => 'snail mucin', 'source' => 'site', 'score' => KeywordBank::scoreSite(37), 'metrics' => ['hits' => 37]];
$rows[] = ['term' => 'sunscreen', 'source' => 'site', 'score' => KeywordBank::scoreSite(29), 'metrics' => ['hits' => 29]];
KeywordBank::put('en', $rows);

$run = KeywordSync::start(['product', 'category', 'brand', 'collection', 'page', 'post'], false, null);
while ($run->status === 'running') {
    $run = KeywordSync::step($run->id);
}
echo "kw seed done: run {$run->id} {$run->status} ".json_encode($run->counts)."\n";
