<?php
/**
 * Runs Composer under CLI PHP (via a cron job) to install the framework.
 * Handles Composer's security-advisory block automatically, in two ticks:
 *   tick 1 -> allow advisory-flagged versions ; tick 2 -> install.
 * Cron:  php /ABSOLUTE/PATH/run-composer.php   (schedule * * * * *)
 * Progress -> storage/logs/composer-run.log. Creates vendor/ when done.
 */
@set_time_limit(0);
@ini_set('memory_limit', '1024M');

$BASE   = __DIR__;
@mkdir($BASE . '/storage/logs', 0775, true);
$lock   = $BASE . '/storage/.composer-running';
$marker = $BASE . '/storage/.policy-set';
$phar   = $BASE . '/composer.phar';

function lg($BASE, $m) { @file_put_contents($BASE . '/storage/logs/composer-run.log', gmdate('c') . ' ' . $m . "\n", FILE_APPEND); }

if (is_dir($BASE . '/vendor')) { lg($BASE, 'vendor already present - nothing to do'); exit(0); }
if (!is_file($phar))          { lg($BASE, 'ERROR: composer.phar missing'); exit(1); }

// avoid overlapping runs (install can take longer than one minute)
if (is_file($lock) && (time() - @filemtime($lock) < 900)) { lg($BASE, 'another run in progress - skip'); exit(0); }
@touch($lock);
register_shutdown_function(function () use ($BASE, $lock) {
    @unlink($lock);
    lg($BASE, is_dir($BASE . '/vendor') ? 'DONE: vendor/ created' : 'run ended (vendor not created yet)');
});

putenv('COMPOSER_HOME=' . $BASE . '/.composer');
putenv('COMPOSER_ALLOW_SUPERUSER=1');
chdir($BASE);

// tick 1: let Composer install the advisory-flagged Laravel versions
if (!is_file($marker)) {
    @file_put_contents($marker, '1');
    lg($BASE, 'STEP 1 (new script): disabling advisory block');
    // (a) safety net: write the setting straight into composer.json
    $cjPath = $BASE . '/composer.json';
    $cj = json_decode((string) @file_get_contents($cjPath), true);
    if (is_array($cj)) {
        $cj['config']['policy']['advisories']['block'] = false;
        @file_put_contents($cjPath, json_encode($cj, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    // (b) authoritative: let Composer set it in its own preferred place too
    $_SERVER['argv'] = ['composer', 'config', 'policy.advisories.block', 'false', '--working-dir=' . $BASE];
    $_SERVER['argc'] = count($_SERVER['argv']);
    require 'phar://' . $phar . '/bin/composer';
    exit;
}

// tick 2+: install the framework
lg($BASE, 'STEP 2 (new script): composer update --no-dev');
$_SERVER['argv'] = ['composer', 'update', '--no-dev', '--optimize-autoloader', '--no-interaction', '--working-dir=' . $BASE];
$_SERVER['argc'] = count($_SERVER['argv']);
require 'phar://' . $phar . '/bin/composer';
