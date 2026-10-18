<?php
/*
 * Lane CPU -- an INSTRUMENTED front controller for the preview server (php -S
 * router). Each request is a fresh PHP execution, exactly as under PHP-FPM, so
 * statics (settings memo, translation memo) start empty and the file cache is
 * the only thing shared between requests -- the live shape. After the response
 * it appends one JSON line to $KBB_CPU_LOG:
 *   uri, status, ms (handleRequest wall time), cpu_ms (user+sys of this
 *   request), queries, settings-table queries, translations-table queries,
 *   cache hits/misses by key family, peak memory.
 * PREVIEW ONLY. Nothing in app/ is changed to get these numbers.
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$root = $_SERVER['DOCUMENT_ROOT'] ?? __DIR__;
if ($path !== '/' && ! str_contains($path, '..') && is_file($root.$path)) {
    return false;
}
define('LARAVEL_START', microtime(true));
$base = getenv('KBB_CPU_APP');
require $base.'/vendor/autoload.php';
$app = require_once $base.'/bootstrap/app.php';

$st = ['q' => 0, 'q_settings' => 0, 'q_translations' => 0, 'q_ms' => 0.0, 'hit' => [], 'miss' => [], 'write' => []];
$fam = static function (string $k): string {
    if (str_starts_with($k, 'kbb.translations.')) { return $k; }
    if ($k === 'kbb.settings') { return $k; }
    if (str_contains($k, 'throttle') || str_contains($k, 'firewall') || str_contains($k, 'fw')) { return 'firewall/throttle'; }
    return preg_replace('/[0-9a-f]{8,}|\d+/', '#', $k);
};
$app->booted(static function () use (&$st, $fam): void {
    Illuminate\Support\Facades\DB::listen(static function ($e) use (&$st): void {
        $st['q']++;
        $st['q_ms'] += $e->time;
        if (preg_match('/from\s+[`"]?settings[`"]?/i', $e->sql)) { $st['q_settings']++; }
        if (preg_match('/from\s+[`"]?translations[`"]?/i', $e->sql)) { $st['q_translations']++; }
        if (getenv('KBB_CPU_SQL') === '1') { $st['sql'][] = round($e->time, 2).' ms  '.$e->sql; }
    });
    Illuminate\Support\Facades\Event::listen(Illuminate\Cache\Events\CacheHit::class, static function ($e) use (&$st, $fam): void { $f = $fam($e->key); $st['hit'][$f] = ($st['hit'][$f] ?? 0) + 1; });
    Illuminate\Support\Facades\Event::listen(Illuminate\Cache\Events\CacheMissed::class, static function ($e) use (&$st, $fam): void { $f = $fam($e->key); $st['miss'][$f] = ($st['miss'][$f] ?? 0) + 1; });
    Illuminate\Support\Facades\Event::listen(Illuminate\Cache\Events\KeyWritten::class, static function ($e) use (&$st, $fam): void { $f = $fam($e->key); $st['write'][$f] = ($st['write'][$f] ?? 0) + 1; });
});
/*
 * KBB_CPU_PROFILE=1: also time every Blade view (EXCLUSIVE ms, by file) and
 * every translator get() (count and ms), by subclassing the framework's own
 * engine and translator from outside the app. Adds overhead; compare / with
 * /ar/ under the same switch, never with the plain numbers.
 */
$prof = ['views' => [], 'tr_n' => 0, 'tr_ms' => 0.0, 'tr_keys' => []];
if (getenv('KBB_CPU_PROFILE') === '1') {
    final class CpuEngine extends Illuminate\View\Engines\CompilerEngine
    {
        public static array $stack = [];
        public static ?array $prof = null;

        public function get($path, array $data = [])
        {
            $t = hrtime(true);
            self::$stack[] = 0.0;
            try {
                return parent::get($path, $data);
            } finally {
                $inc = (hrtime(true) - $t) / 1e6;
                $child = array_pop(self::$stack);
                if (self::$stack !== []) { self::$stack[count(self::$stack) - 1] += $inc; }
                $k = preg_replace('#^.*/resources/views/#', '', $path);
                self::$prof['views'][$k] = (self::$prof['views'][$k] ?? 0) + ($inc - $child);
            }
        }
    }
    final class CpuTranslator extends Illuminate\Translation\Translator
    {
        public static ?array $prof = null;

        public function get($key, array $replace = [], $locale = null, $fallback = true)
        {
            $t = hrtime(true);
            try {
                return parent::get($key, $replace, $locale, $fallback);
            } finally {
                self::$prof['tr_n']++;
                self::$prof['tr_ms'] += (hrtime(true) - $t) / 1e6;
                $g = explode('.', (string) $key)[0].'.'.(explode('.', (string) $key)[1] ?? '');
                self::$prof['tr_keys'][$g] = (self::$prof['tr_keys'][$g] ?? 0) + 1;
            }
        }
    }
    CpuEngine::$prof = &$prof;
    CpuTranslator::$prof = &$prof;
    $app->extend('translator', static function ($t) {
        $n = new CpuTranslator($t->getLoader(), $t->getLocale());
        $n->setFallback($t->getFallback());

        return $n;
    });
    $app->booted(static function ($app): void {
        $app['view.engine.resolver']->register('blade', static fn () => new CpuEngine($app['blade.compiler'], $app['files']));
    });
}
$ru0 = getrusage();
$t0 = hrtime(true);
$app->handleRequest(Illuminate\Http\Request::capture());
$ms = (hrtime(true) - $t0) / 1e6;
$ru1 = getrusage();
$cpu = (($ru1['ru_utime.tv_sec'] - $ru0['ru_utime.tv_sec']) * 1e6 + ($ru1['ru_utime.tv_usec'] - $ru0['ru_utime.tv_usec'])
    + ($ru1['ru_stime.tv_sec'] - $ru0['ru_stime.tv_sec']) * 1e6 + ($ru1['ru_stime.tv_usec'] - $ru0['ru_stime.tv_usec'])) / 1000;
$line = json_encode(['uri' => $_SERVER['REQUEST_URI'] ?? '', 'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET', 'status' => http_response_code(),
    'ms' => round($ms, 1), 'cpu_ms' => round($cpu, 1), 'mem_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1)] + $st
    + ($prof['views'] !== [] || $prof['tr_n'] > 0 ? ['prof' => $prof] : []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($log = getenv('KBB_CPU_LOG')) {
    file_put_contents($log, $line."\n", FILE_APPEND | LOCK_EX);
}
