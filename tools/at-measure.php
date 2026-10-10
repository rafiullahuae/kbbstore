<?php
/*
 * Lane AT: the summary's query count and the engagement figures, against the
 * preview database (tools/at-preview.sh). Run with the preview's env:
 *   DB_CONNECTION=sqlite DB_DATABASE=<preview.sqlite> php artisan tinker --execute="require 'tools/at-measure.php';"
 */
use App\Services\Analytics\Report;
use Illuminate\Support\Facades\DB;

Report::summary(...array_slice(Report::range('today'), 0, 2));   // warm the per-process memos
foreach (['today', '7d', '30d'] as $r) {
    [$f, $t] = Report::range($r);
    $n = 0;
    DB::listen(function () use (&$n) { $n++; });
    $t0 = microtime(true);
    $s = Report::summary($f, $t);
    $ms = round((microtime(true) - $t0) * 1000, 1);
    echo $r.': '.$n.' queries, '.$ms." ms\n";
    if (isset($s['engagement'])) {
        echo json_encode($s['engagement'], JSON_UNESCAPED_SLASHES)."\n";
    }
    DB::flushQueryLog();
    app('events')->forget(\Illuminate\Database\Events\QueryExecuted::class);
}
