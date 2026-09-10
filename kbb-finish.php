<?php
/**
 * KBB deploy finisher — v2, with fatal-error capture.
 *
 * v1 logged a line, called artisan, and logged the result. That works until PHP
 * dies inside the artisan call: a parse error or an exhausted memory limit is not
 * a Throwable, so nothing is caught and nothing is written. The log simply stops,
 * which is exactly what happened here.
 *
 * This version registers a shutdown handler, so whatever kills the process gets
 * written to the log before PHP exits. It also records which step it was on.
 *
 * Cron (every minute):
 *   php /home/uXXXX/domains/easywebsol.com/kbb-upgrade-app/kbb-finish.php
 */

@set_time_limit(0);
@ini_set('memory_limit', '512M');
@ini_set('display_errors', '0');
error_reporting(E_ALL);

$BASE = __DIR__;
@mkdir($BASE . '/storage/logs', 0775, true);
$log  = $BASE . '/storage/logs/kbb-finish.log';
$done = $BASE . '/storage/.kbb-installed';
$lock = $BASE . '/storage/.kbb-finish-running';

function lg($log, $m) { @file_put_contents($log, gmdate('c') . ' ' . $m . "\n", FILE_APPEND); }

$STEP = 'startup';

// The important addition: catch whatever kills the process.
register_shutdown_function(function () use (&$STEP, $log, $lock) {
    @unlink($lock);
    $e = error_get_last();

    if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        lg($log, "*** FATAL during step [{$STEP}] ***");
        lg($log, "    {$e['message']}");
        lg($log, "    at {$e['file']} line {$e['line']}");
    }
});

if (is_file($done))             { exit(0); }
if (!is_dir($BASE . '/vendor')) { lg($log, 'waiting: vendor/ not present yet'); exit(0); }
if (is_file($lock) && (time() - @filemtime($lock) < 600)) { lg($log, 'another run in progress - skip'); exit(0); }
@touch($lock);

lg($log, '--- run start | PHP ' . PHP_VERSION . ' | memory_limit ' . ini_get('memory_limit'));

putenv('COMPOSER_ALLOW_SUPERUSER=1');
chdir($BASE);

$STEP = 'autoload';
require $BASE . '/vendor/autoload.php';

$STEP = 'bootstrap';
$app = require_once $BASE . '/bootstrap/app.php';

$STEP = 'console kernel';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

lg($log, 'booted ok');

function artisan($kernel, $log, &$STEP, $cmd, array $params = []) {
    $STEP = "artisan {$cmd}";
    try {
        $code = $kernel->call($cmd, $params);
        $out  = trim((string) $kernel->output());
        lg($log, "artisan {$cmd} -> exit {$code}");
        if ($out !== '') {
            foreach (array_slice(explode("\n", $out), 0, 25) as $line) {
                lg($log, "    | " . rtrim($line));
            }
        }
        return $code === 0;
    } catch (\Throwable $e) {
        lg($log, "artisan {$cmd} FAILED: " . $e->getMessage());
        lg($log, "    at " . $e->getFile() . ':' . $e->getLine());
        return false;
    }
}

// 1) APP_KEY
$envPath = $BASE . '/.env';
$env = @file_get_contents($envPath);
if ($env !== false && !preg_match('/^APP_KEY=base64:.+/m', $env)) {
    lg($log, 'APP_KEY empty — generating');
    if (!is_writable($envPath)) {
        lg($log, 'WARNING: .env is not writable. Set its permissions to 644.');
    }
    artisan($kernel, $log, $STEP, 'key:generate', ['--force' => true]);
} else {
    lg($log, 'APP_KEY already set');
}

// 2) schema + data
$ok  = artisan($kernel, $log, $STEP, 'migrate', ['--force' => true]);
$ok  = artisan($kernel, $log, $STEP, 'db:seed', ['--force' => true]) && $ok;

// 3) admin account
$STEP = 'admin account';
try {
    $adminEmail = env('ADMIN_EMAIL');
    $adminPass  = env('ADMIN_PASSWORD');
    if ($adminEmail && $adminPass) {
        if (!\App\Models\AdminUser::where('email', $adminEmail)->first()) {
            \App\Models\AdminUser::create([
                'name'     => env('ADMIN_NAME', 'Store Owner'),
                'email'    => $adminEmail,
                'password' => $adminPass,
                'role'     => 'owner',
            ]);
            lg($log, "created admin account: {$adminEmail}");
        } else {
            lg($log, "admin account already exists: {$adminEmail}");
        }
    } else {
        lg($log, 'ADMIN_EMAIL/ADMIN_PASSWORD not set in .env');
    }
} catch (\Throwable $e) {
    lg($log, 'admin account step failed: ' . $e->getMessage());
    $ok = false;
}

// 4) caches — never fatal to the install
$STEP = 'caches';
artisan($kernel, $log, $STEP, 'config:clear');
artisan($kernel, $log, $STEP, 'route:clear');
artisan($kernel, $log, $STEP, 'view:clear');

$STEP = 'finish';
if ($ok) {
    @file_put_contents($done, gmdate('c') . " kbb storefront installed\n");
    lg($log, 'DONE: installed. You can delete the cron jobs now.');
} else {
    lg($log, 'finished with errors — see above. Will retry next tick.');
}
