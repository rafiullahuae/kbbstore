<?php
/*
 * Lane SP (speed): render one storefront URL N times inside ONE PHP process
 * against a tools/spd-preview.sh preview, for profiling.
 *
 *   php tools/spd-prof.php LABEL URL [N] [sample]
 *
 * Prints the median ms per render after the first. With "sample", a poor
 * man's sampling profiler: SIGUSR1 (sent every ~1 ms by tools/spd-sampler.py)
 * records the innermost App\ / resources frames, and the top 40 are printed.
 */
$label = $argv[1];
$url = $argv[2];
$n = (int) ($argv[3] ?? 15);
$sample = ($argv[4] ?? '') === 'sample';
$dir = dirname(__DIR__).'/storage/framework/testing/lane-spd-'.$label;
$port = trim((string) file_get_contents($dir.'/port'));
foreach ([
    'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://127.0.0.1:'.$port,
    'APP_KEY' => 'base64:bGFuZXBlcmZsYW5lcGVyZmxhbmVwZXJmbGFuZXBlcmY=', 'KBB_PUBLIC_PATH' => $dir.'/webroot',
    'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_DATABASE' => 'kbb_spd_'.$label, 'DB_USERNAME' => 'kbb', 'DB_PASSWORD' => 'kbb',
    'SESSION_DRIVER' => 'file', 'CACHE_STORE' => 'file', 'APP_CONFIG_CACHE' => $dir.'/compiled/config.php',
    'APP_ROUTES_CACHE' => $dir.'/compiled/routes.php', 'APP_EVENTS_CACHE' => $dir.'/compiled/events.php',
    'APP_SERVICES_CACHE' => $dir.'/compiled/services.php', 'APP_PACKAGES_CACHE' => $dir.'/compiled/packages.php',
] as $k => $v) {
    putenv("$k=$v");
    $_ENV[$k] = $_SERVER[$k] = $v;
}
$base = $dir.'/app';
require $base.'/vendor/autoload.php';

$samples = [];
if ($sample) {
    pcntl_async_signals(true);
    pcntl_signal(SIGUSR1, static function () use (&$samples): void {
        $stack = [];
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 60) as $f) {
            $file = $f['file'] ?? '';
            if (str_contains($file, '/app/app/') || str_contains($file, 'storage/framework/views')) {
                $stack[] = basename($file).':'.($f['line'] ?? 0).' '.($f['class'] ?? '').($f['type'] ?? '').$f['function'];
            }
        }
        if ($stack !== []) {
            $samples['TOP '.$stack[0]] = ($samples['TOP '.$stack[0]] ?? 0) + 1;
            foreach (array_unique($stack) as $s) {
                $samples['INC '.$s] = ($samples['INC '.$s] ?? 0) + 1;
            }
        }
        $samples['__total'] = ($samples['__total'] ?? 0) + 1;
    });
    file_put_contents($dir.'/prof.pid', (string) getmypid());
}

$callers = [];
$wantCallers = ($argv[4] ?? '') === 'callers';
$times = [];
for ($i = 0; $i < $n; $i++) {
    $app = require $base.'/bootstrap/app.php';
    if ($wantCallers && $i === 0) {
        $app['events']->listen(\Illuminate\Cache\Events\CacheHit::class, static function ($e) use (&$callers): void {
            if ($e->key !== 'kbb.settings') {
                return;
            }
            $chain = [];
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $f) {
                $file = $f['file'] ?? '';
                if ((str_contains($file, '/app/app/') || str_contains($file, 'framework/views')) && ! str_contains($file, 'SettingsService.php')) {
                    $chain[] = basename($file).':'.($f['line'] ?? 0);
                    if (count($chain) === 3) {
                        break;
                    }
                }
            }
            $k = implode(' < ', $chain);
            $callers[$k] = ($callers[$k] ?? 0) + 1;
        });
    }
    $kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
    $req = \Illuminate\Http\Request::create('http://127.0.0.1:'.$port.$url, 'GET');
    $t = microtime(true);
    $res = $kernel->handle($req);
    $kernel->terminate($req, $res);
    $times[] = (microtime(true) - $t) * 1000;
    if ($i === 0) {
        fwrite(STDERR, 'status '.$res->getStatusCode().' bytes '.strlen((string) $res->getContent())."\n");
    }
    if (is_file($base.'/tests/Support/StaticMemos.php')) {
        require_once $base.'/tests/Support/StaticMemos.php';
        try { \Tests\Support\StaticMemos::forgetAll(); } catch (\Throwable) {}
    }
    \Illuminate\Support\Facades\Facade::clearResolvedInstances();
    $app->flush();
}
@unlink($dir.'/prof.pid');
$rest = array_slice($times, 1);
sort($rest);
printf("median %.1f ms (n=%d, first %.1f)\n", $rest[intdiv(count($rest), 2)], count($rest), $times[0]);
if ($wantCallers) {
    arsort($callers);
    foreach (array_slice($callers, 0, 40, true) as $k => $v) {
        printf("%6d  %s\n", $v, $k);
    }
}
if ($sample) {
    arsort($samples);
    foreach (array_slice($samples, 0, 45, true) as $k => $v) {
        printf("%6d  %s\n", $v, $k);
    }
}
