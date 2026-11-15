<?php
// Lane LN15 audit: every heading line in the live English tab bodies and what sits under it.
// A heading = a line whose words are all bold / in h2-h6, at most 80 characters.
$W = '/home/user/kbbstore/.claude/worktrees/agent-aac70f6e28b98a975';
require $W . '/vendor/autoload.php';
use App\Support\BlockStructure;

$tabs = json_decode(file_get_contents($W . '/storage/ln15-logs/tabs.json'), true);
$loc = $argv[1] ?? 'en';
$under = [];       // blank count under a heading => n
$byNext = [];
$bodies = ['with headings' => 0, 'mixed (some headings with a blank, some without)' => 0, 'no headings' => 0];
$products = [];
$mixedSlugs = [];
$tagShapes = [];
foreach ($tabs as $slug => $r) {
    foreach ($r[$loc] as $ti => $t) {
        $html = $t['body'];
        $lines = BlockStructure::lines($html);
        $hs = [];
        foreach ($lines as $i => $l) {
            if ($l['kind'] !== 'P' || empty($l['bold'])) continue;
            $text = trim(html_entity_decode(strip_tags(substr($html, $l['start'], $l['end'] - $l['start'])), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($text === '' || mb_strlen($text) > 80) continue;
            $j = $i + 1; $b = 0;
            while (isset($lines[$j]) && $lines[$j]['kind'] === 'B') { $b++; $j++; }
            if (! isset($lines[$j])) continue; // a heading at the very end
            $hs[] = $b;
            $under[$b] = ($under[$b] ?? 0) + 1;
            $byNext[$lines[$j]['kind'] . ':' . min($b, 2)] = ($byNext[$lines[$j]['kind'] . ':' . min($b, 2)] ?? 0) + 1;
            // the heading's own markup shape, text removed
            $open = substr($html, max(0, $l['start'] - 40), $l['start'] - max(0, $l['start'] - 40));
            preg_match('/(<(?:p|div|h[2-6])\b[^>]*>(?:<[^>]+>)*)$/i', $open, $mm);
            $tagShapes[$mm[1] ?? '?'] = ($tagShapes[$mm[1] ?? '?'] ?? 0) + 1;
        }
        if (! $hs) { $bodies['no headings']++; continue; }
        $bodies['with headings']++;
        if (count(array_unique($hs)) > 1) {
            $bodies['mixed (some headings with a blank, some without)']++;
            $mixedSlugs[$slug] = true;
        }
        $products[$slug] = true;
    }
}
ksort($under);
echo "tab bodies: ", json_encode($bodies), "\n";
echo "blank lines under a heading => headings: ", json_encode($under), "\n";
echo "next block:blanks => n: ", json_encode($byNext), "\n";
arsort($tagShapes);
echo "heading markup: ", json_encode(array_slice($tagShapes, 0, 12, true), JSON_UNESCAPED_UNICODE), "\n";
echo "products with headings: ", count($products), "; products with uneven heading gaps (by blank lines): ", count($mixedSlugs), "\n";
echo implode(' ', array_slice(array_keys($mixedSlugs), 0, 40)), "\n";
