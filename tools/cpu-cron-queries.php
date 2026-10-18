<?php
// Lane CPU -- every query each every-minute scheduled command runs, with its
// time, inside one booted app (so the boot is NOT in these numbers; see
// tools/cpu-cron.sh for the boot). Run with tools/cpu-cron.sh's environment:
//   tools/cpu-cron-queries.sh   -> docs/lane-cpu/cron-queries-<label>.txt
$base = $argv[1];
$label = $argv[2];
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$log = [];
Illuminate\Support\Facades\DB::listen(static function ($e) use (&$log): void { $log[] = sprintf('%7.2f ms  %s', $e->time, preg_replace('/\s+/', ' ', $e->sql)); });
$out = '';
foreach (['kbb:order-reminders', 'kbb:campaigns-step', 'kbb:push-step', 'kbb:analytics-rollup', 'kbb:order-emirates', 'ugc:cut-covers --limit=20 --unattended'] as $cmd) {
    $log = [];
    $t = hrtime(true);
    Illuminate\Support\Facades\Artisan::call($cmd);
    $ms = (hrtime(true) - $t) / 1e6;
    $out .= sprintf("## %s  %.1f ms, %d queries\n%s\n", $cmd, $ms, count($log), implode("\n", $log));
}
@mkdir($base.'/docs/lane-cpu', 0777, true);
file_put_contents($base.'/docs/lane-cpu/cron-queries-'.$label.'.txt', $out);
echo preg_replace('/^ .*\n/m', '', $out);
