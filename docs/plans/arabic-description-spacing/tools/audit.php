<?php
// usage: php audit.php <worktree> <tabs.json> <recent50.txt> <out.json>
require $argv[1].'/vendor/autoload.php';
use App\Support\BlockStructure;

$d = json_decode(file_get_contents($argv[2]), true);
$recent = array_map(fn ($l) => basename(trim($l)), array_filter(file($argv[3]), 'trim'));
$recentSet = array_flip($recent);

$classes = [];
$bump = function (string $c, string $slug) use (&$classes) {
    $classes[$c]['tabs'] = ($classes[$c]['tabs'] ?? 0) + 1;
    $classes[$c]['products'][$slug] = true;
};
$products = [];
// 2.60.665 (render-time blank after every Arabic list) went live at ~10:13 on 14 Nov, during this fetch:
// products from index $cut on were fetched after it. For them, the same strip is applied to BOTH sides
// (a genuine stored blank right after a list is lost on both, so it still matches), which takes the
// render-time insertion back out of the Arabic and leaves the STORED structure to compare.
$cut = (int) ($argv[5] ?? PHP_INT_MAX);
$order = array_map(fn ($l) => basename(trim($l)), array_values(array_filter(file(dirname($argv[2]).'/all-products.txt'), 'trim')));
$pos = array_flip($order);
$strip = fn (string $h): string => (string) preg_replace('#(</(?:ul|ol)>(?:\s*</div>)*)<div class="kbb-bl"></div>#i', '$1', $h);
$effect = ['products_after' => 0, 'bodies_added_blank_english_lacks' => 0, 'products' => []];
$tabsChecked = 0; $tabsTranslated = 0;

foreach ($d as $slug => $r) {
    $p = ['slug' => $slug, 'recent' => isset($recentSet[$slug]), 'score' => 0, 'tabs' => [], 'auto' => 0, 'human' => 0];
    if (count($r['en']) !== count($r['ar'])) {
        $bump('tab count differs (EN '.count($r['en']).' / AR '.count($r['ar']).')', $slug);
        $p['score'] += 3;
    }
    if (($r['en_short'] ?? null) !== null && ($r['ar_short'] ?? null) !== null) {
        $r['en'][] = ['title' => 'Short description', 'body' => $r['en_short']];
        $r['ar'][] = ['title' => 'Short description', 'body' => $r['ar_short']];
    }
    foreach ($r['en'] as $i => $t) {
        $en = $t['body'];
        $ar = $r['ar'][$i]['body'] ?? null;
        if ($ar === null) continue;
        $tabsChecked++;
        if (@filemtime(dirname($argv[2]).'/pages/ar-'.$slug.'.html') >= $cut && $en !== $ar) {
            $rawAr = $ar;
            $en = $strip($en);
            $ar = $strip($ar);
            if (substr_count(BlockStructure::shape($rawAr), 'B') > substr_count(BlockStructure::shape($r['en'][$i]['body']), 'B')
                && BlockStructure::shape($ar) === BlockStructure::shape($en)) {
                $effect['bodies_added_blank_english_lacks']++;
                $effect['products'][$slug] = true;
            }
        }
        if ($en === $ar || ! preg_match('/\p{Arabic}/u', strip_tags($ar))) {
            $bump('tab not translated (English shown on /ar)', $slug);
            continue;
        }
        if (! preg_match('/\p{Latin}{3,}/u', strip_tags($en)) && BlockStructure::words($en) === BlockStructure::words($ar)) continue;
        $tabsTranslated++;
        $se = BlockStructure::shape($en);
        $sa = BlockStructure::shape($ar);
        $tab = ['i' => $i, 'title' => $t['title'], 'en' => $se, 'ar' => $sa];

        // stray nbsp
        $nb = fn ($h) => preg_match_all('/&nbsp;|&#160;|\x{00A0}/u', $h);
        if ($nb($ar) > $nb($en)) { $bump('stray non-breaking spaces (more in AR than EN)', $slug); }

        if ($se === $sa) { $bump('same block structure', $slug); $p['tabs'][] = $tab; continue; }

        $a = BlockStructure::align($en, $ar);
        $tab['changes'] = $a['changes'];
        $tab['problem'] = $a['problem'];
        // per-gap analysis on the aligned outcome
        $enL = explode(' ', $se); $arL = explode(' ', $sa);
        $cnt = fn ($arr, $k) => count(array_filter($arr, fn ($x) => $x === $k));
        $blEn = $cnt($enL, 'B'); $blAr = $cnt($arL, 'B');
        if ($blAr < $blEn) $bump('fewer blank lines in AR than EN', $slug);
        if ($blAr > $blEn) $bump('more blank lines in AR than EN', $slug);
        $lists = fn ($arr) => count(array_filter($arr, fn ($x) => str_starts_with($x, 'L')));
        $pl = fn ($arr) => $cnt($arr, 'P');
        if ($lists($arL) < $lists($enL)) $bump('fewer lists in AR (merged or lost list)', $slug);
        if ($lists($arL) > $lists($enL)) $bump('more lists in AR (split or extra list)', $slug);
        if ($pl($arL) < $pl($enL)) $bump('fewer text lines in AR (heading glued to text / paragraphs merged / <br> lost)', $slug);
        if ($pl($arL) > $pl($enL)) $bump('more text lines in AR (paragraph split / extra <br>)', $slug);
        if (str_contains($se, 'X') !== str_contains($sa, 'X')) $bump('table lost/added', $slug);
        $ie = preg_match_all('/<img\b/i', $en); $ia = preg_match_all('/<img\b/i', $ar);
        if ($ie !== $ia) $bump('picture count differs', $slug);
        // list item counts (content drift) where lists align
        $le = array_values(array_filter($enL, fn ($x) => str_starts_with($x, 'L')));
        $la = array_values(array_filter($arL, fn ($x) => str_starts_with($x, 'L')));
        if (count($le) === count($la) && $le !== $la) $bump('list item counts differ (Arabic made from an older English)', $slug);

        $gapScore = abs($blEn - $blAr) + 2 * abs($lists($enL) - $lists($arL)) + 2 * abs($pl($enL) - $pl($arL));
        $p['score'] += $gapScore;
        if ($a['changed']) { $p['auto']++; $bump('AUTO: the command lines it up', $slug); }
        elseif (! $a['ok']) { $p['human']++; $bump('HUMAN: cannot be lined up with certainty', $slug); }
        else { $bump('differs only in list sizes (nothing to fix)', $slug); }
        $p['tabs'][] = $tab;
    }
    $products[$slug] = $p;
}

$effect['products_after'] = count(array_filter($order, fn ($sl) => @filemtime(dirname($argv[2]).'/pages/ar-'.$sl.'.html') >= $cut));
$effect['products_with_added_blank'] = count($effect['products']);
$effect['examples'] = array_slice(array_keys($effect['products']), 0, 8);
unset($effect['products']);
foreach ($classes as $c => $v) { $classes[$c]['products'] = count($v['products']); }
ksort($classes);
$worst = array_values($products);
usort($worst, fn ($a, $b) => $b['score'] <=> $a['score']);
$recentOut = array_values(array_filter($products, fn ($p) => $p['recent']));
file_put_contents($argv[4], json_encode(['products' => count($d), 'tabs_checked' => $tabsChecked, 'tabs_translated' => $tabsTranslated, 'classes' => $classes, 'worst' => array_slice($worst, 0, 25), 'recent' => $recentOut, 'effect_2_60_665' => $effect, 'all' => $products], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo json_encode(['effect_2_60_665' => $effect, 'products' => count($d), 'tabs_checked' => $tabsChecked, 'tabs_translated' => $tabsTranslated, 'classes' => $classes], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
