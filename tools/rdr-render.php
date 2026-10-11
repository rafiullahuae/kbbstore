<?php

declare(strict_types=1);

/*
 * Lane RD: render every review-showcase design through Blade, from the preview
 * database, into static fragments the shot script injects into the real
 * homepage. Run by tools/rdr-preview.sh (tinker, preview env only).
 *
 *   webroot/rdr/<letter>.<locale>.html   one design, its own <style> inside
 *   webroot/rdr/_base.html, _js.html     printed once per page when wired
 *   docs/lane-rd-shots/costs.json        bytes per design, render ms, queries
 *
 * QUERIES ARE MEASURED FLAT: ReviewShowcase::data() is counted against the
 * seeded shop, then again after 40 more approved reviews (with customers and
 * products) are added inside a transaction that is rolled back.
 */

use App\Models\Review;
use App\Support\ReviewShowcase;
use Illuminate\Support\Facades\DB;

$root = public_path('rdr');
@mkdir($root, 0755, true);
$docs = base_path('docs/lane-rd-shots');
@mkdir($docs, 0755, true);

$count = static function (callable $fn): array {
    DB::flushQueryLog();
    DB::enableQueryLog();
    $t = hrtime(true);
    $out = $fn();
    $ms = (hrtime(true) - $t) / 1e6;
    $n = count(DB::getQueryLog());
    DB::disableQueryLog();

    return [$out, $n, $ms];
};

$costs = ['designs' => [], 'queries' => []];
$cssOf = static function (string $html): string {
    preg_match_all('#<style>(.*?)</style>#s', $html, $m);

    return implode('', $m[1]);
};
$jsOf = static function (string $html): string {
    preg_match_all('#<script>(.*?)</script>#s', $html, $m);

    return implode('', $m[1]);
};
$min = static fn (string $css) => trim(preg_replace(['#/\*.*?\*/#s', '#\s+#', '#\s*([{};:,>])\s*#'], ['', ' ', '$1'], $css));

foreach (['en', 'ar'] as $loc) {
    app()->setLocale($loc);
    \App\Services\Translation\TranslationStore::forgetMemo();
    // Warm the translation map so the count below is the showcase's own.
    __('store.home.reviews_heading');
    ReviewShowcase::data(); // and the once-per-process demo_seed_log check
    [$rs, $q, $ms] = $count(fn () => ReviewShowcase::data());
    $costs['queries'][$loc] = ['data_queries' => $q, 'data_ms' => round($ms, 1), 'approved_real' => $rs['summary']['total'], 'cards' => count($rs['items']), 'photo_cards' => count($rs['photos'])];

    foreach (['_base', '_js'] as $part) {
        $html = view('partials.review-showcase.'.$part)->render();
        file_put_contents("$root/$part.html", $html);
        if ($loc === 'en') {
            $code = $part === '_base' ? $min($cssOf($html)) : $jsOf($html);
            $costs[$part] = ['bytes' => strlen($code), 'gzip' => strlen(gzencode($code, 9))];
        }
    }

    foreach (array_keys(ReviewShowcase::DESIGNS) as $l) {
        [$html, $vq, $vms] = $count(fn () => view('partials.review-showcase.design-'.$l, ['rs' => $rs])->render());
        file_put_contents("$root/$l.$loc.html", $html);
        if ($loc === 'en') {
            $css = $min($cssOf($html));
            $markup = trim(preg_replace(['#<style>.*?</style>#s', '#\{\{--.*?--\}\}#s'], '', $html));
            $costs['designs'][$l] = [
                'name' => ReviewShowcase::DESIGNS[$l][0], 'carousel' => ReviewShowcase::DESIGNS[$l][1],
                'css_bytes' => strlen($css), 'css_gzip' => strlen(gzencode($css, 9)),
                'html_bytes' => strlen($markup), 'html_gzip' => strlen(gzencode($markup, 9)),
                'render_ms' => round($vms, 1), 'render_queries' => $vq,
            ];
        }
        $leak = array_filter(['PENDING-SHOULD-NOT-SHOW', 'SPAM-SHOULD-NOT-SHOW', '@preview.test', '127.0.0.1', '1.2.3.4'], fn ($s) => str_contains($html, $s));
        if ($leak) {
            throw new RuntimeException("design $l ($loc) printed ".implode(', ', $leak));
        }
    }
}

// Flat as the shop grows: the same count with 40 more approved reviews.
app()->setLocale('en');
DB::beginTransaction();
try {
    $pids = DB::table('products')->where('slug', 'like', 'rd-%')->pluck('id')->all();
    for ($n = 0; $n < 40; $n++) {
        $cid = DB::table('customers')->insertGetId(['name' => 'Flat '.$n, 'email' => 'flat'.$n.'@x.test', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('addresses')->insert(['customer_id' => $cid, 'is_default' => true, 'city' => 'Dubai', 'created_at' => now(), 'updated_at' => now()]);
        Review::create(['source' => 'sorina', 'product_id' => $pids[$n % count($pids)], 'customer_id' => $cid, 'author_name' => 'Flat Person', 'author_email' => 'f@x.test', 'rating' => 5, 'content' => 'Flat '.$n, 'status' => 'approved', 'verified' => true, 'images' => ['/uploads/rd/ugc-boj-sun.webp']]);
    }
    [$rs2, $q2] = $count(fn () => ReviewShowcase::data());
    $costs['queries']['flat'] = ['before_total' => $costs['queries']['en']['approved_real'], 'before_queries' => $costs['queries']['en']['data_queries'], 'after_total' => $rs2['summary']['total'], 'after_queries' => $q2];
} finally {
    DB::rollBack();
}

file_put_contents("$docs/costs.json", json_encode($costs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
echo json_encode($costs['queries'])."\n";
foreach ($costs['designs'] as $l => $d) {
    echo strtoupper($l)." {$d['name']}: css {$d['css_bytes']} B ({$d['css_gzip']} gz), html {$d['html_bytes']} B, {$d['render_ms']} ms, {$d['render_queries']} q\n";
}
echo "base css {$costs['_base']['bytes']} B ({$costs['_base']['gzip']} gz), js {$costs['_js']['bytes']} B ({$costs['_js']['gzip']} gz)\n";
