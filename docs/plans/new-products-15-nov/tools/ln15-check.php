<?php
// Check the LN15 Arabic against the live English: HtmlStructure::problem() and BlockStructure shapes.
$W = '/home/user/kbbstore/.claude/worktrees/agent-aac70f6e28b98a975';
require $W . '/vendor/autoload.php';
$m = require $W . '/database/migrations/2028_02_15_100000_arabic_new_products_15_november.php';
$data = (new ReflectionClassConstant($m, 'PRODUCTS'))->getValue();
$tabs = json_decode(file_get_contents($W . '/storage/ln15-logs/tabs.json'), true);
foreach ($data as $slug => [$name, $short, $desc]) {
    $t = $tabs[$slug] ?? null;
    if (! $t) { echo "$slug: NOT FETCHED\n"; continue; }
    $en = html_entity_decode($t['en_name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $out = [];
    $out[] = 'name:' . (App\Services\RecentLocalise\HtmlStructure::problem($en, $name) ?? 'ok');
    if ($t['en_short'] === null) {
        $out[] = 'short:' . ($short === null ? 'ok(none)' : 'EXTRA');
    } else {
        $out[] = 'short:' . (App\Services\RecentLocalise\HtmlStructure::problem($t['en_short'], (string) $short) ?? 'ok');
        $out[] = 'shortShape:' . (App\Support\BlockStructure::shape($t['en_short']) === App\Support\BlockStructure::shape((string) $short) ? 'ok' : 'DIFF');
    }
    $ed = $t['en'][0]['body'];
    $out[] = 'desc:' . (App\Services\RecentLocalise\HtmlStructure::problem($ed, $desc) ?? 'ok');
    $out[] = 'shape:' . (App\Support\BlockStructure::shape($ed) === App\Support\BlockStructure::shape($desc) ? 'ok ' . App\Support\BlockStructure::shape($desc) : 'DIFF ' . App\Support\BlockStructure::shape($ed) . ' / ' . App\Support\BlockStructure::shape($desc));
    $a = App\Support\BlockStructure::align($ed, $desc);
    $out[] = 'align:' . ($a['ok'] && ! $a['changed'] ? 'clean' : json_encode([$a['ok'], $a['changed'], $a['problem']]));
    if (preg_match('/\b(the|and|with|your|skin|apply|helps|made|korea|size)\b/i', strip_tags($desc . ' ' . $short))) $out[] = 'ENGLISH-WORD';
    echo $slug, ': ', implode(' | ', $out), "\n";
}
