<?php

declare(strict_types=1);

/*
 * Lane AR round 3: what every search answers, before and after -- the header
 * box (/api/search) and the results page (/shop/?s=), on English and on /ar,
 * for a fixed list of queries. Run with tools/arr-preview.sh's environment:
 *
 *   KBB_PROBE_TAG=before php artisan tinker --execute="require 'tools/arr-search-probe.php';"
 *
 * Writes storage/ar-logs/search-<tag>.json: per query and language, the box's
 * groups (label + each row's label and url, plus `corrected`) and the page's
 * ordered product URLs, heading and result count. Compared by
 * tools/arr-search-diff.py.
 */

use Illuminate\Http\Request;

$queries = ['Anua', 'anua', 'sunscreen', 'serum', 'cream', 'toner', 'Medicube serum', 'medicob', '50%', 'x_y', '', 'zzqq',
    'أنوا', 'سيروم', 'واقي شمس', 'كريم', 'ميديكيوب', 'تونر أنوا', 'مرطب', 'الشمس'];

$kernel = app(\Illuminate\Contracts\Http\Kernel::class);
$get = function (string $uri, string $accept) use ($kernel) {
    \App\Services\SettingsService::forgetMemo();
    \App\Services\Translation\TranslationStore::forgetMemo();
    app()->forgetScopedInstances();
    $req = Request::create('http://127.0.0.1' . $uri, 'GET', [], [], [], [
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
        'HTTP_ACCEPT' => $accept, 'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9',
    ]);
    $res = $kernel->handle($req);
    $kernel->terminate($req, $res);

    return $res;
};

$out = [];
foreach (['en' => '', 'ar' => '/ar'] as $lang => $pre) {
    foreach ($queries as $q) {
        $r = $get($pre . '/api/search?q=' . rawurlencode($q), 'application/json');
        $j = json_decode((string) $r->getContent(), true) ?: [];
        $box = ['status' => $r->getStatusCode(), 'total' => $j['total'] ?? null, 'corrected' => $j['corrected'] ?? null, 'groups' => []];
        foreach ($j['groups'] ?? [] as $g) {
            $box['groups'][] = ['key' => $g['key'] ?? '', 'label' => $g['label'] ?? '', 'rows' => array_map(fn ($it) => [$it['label'] ?? '', $it['meta'] ?? '', $it['url'] ?? ''], $g['items'] ?? [])];
        }
        $r2 = $get($pre . '/shop/?s=' . rawurlencode($q), 'text/html');
        $html = (string) $r2->getContent();
        preg_match_all('#<a class="kbb-card-shot" href="([^"]+)"#', $html, $m);
        preg_match('#<h1[^>]*>(.*?)</h1>#s', $html, $h1);
        preg_match('#<title>([^<]*)</title>#', $html, $t);
        $out[$lang][$q] = [
            'box' => $box,
            'page' => ['status' => $r2->getStatusCode(), 'urls' => $m[1], 'h1' => trim(strip_tags($h1[1] ?? '')), 'title' => html_entity_decode($t[1] ?? '')],
        ];
    }
}

$file = base_path('storage/ar-logs/search-' . (getenv('KBB_PROBE_TAG') ?: 'run') . '.json');
file_put_contents($file, json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo 'wrote ', $file, "\n";
