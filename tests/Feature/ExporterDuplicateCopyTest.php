<?php

/**
 * Two copies of the exporter installed: the new one switches the older one
 * off instead of dying.
 *
 * THE DEFECT, ON THE OWNER'S WORDPRESS (1 October 2026). An older build was
 * active in wp-content/plugins/kbb-exporter-3/. The 1.9.0 zip unpacked into
 * wp-content/plugins/kbb-exporter/. Both show on Plugins as "KBB Store
 * Exporter". Activating the new one failed, twice, with a fresh upload in
 * between:
 *
 *   Warning: Constant KBB_EXPORTER_VERSION already defined ... kbb-exporter.php on line 66
 *   Fatal error: Cannot redeclare class KBB_Export_Csv (previously declared in
 *   .../kbb-exporter-3/includes/class-kbb-export-csv.php:28)
 *
 * WordPress's activation (plugin_sandbox_scrape) includes the new main file
 * while every active plugin, the old copy included, is already loaded. That is
 * what these cases do: a PHP process with WordPress's functions stubbed loads
 * the "old copy" first, then the real plugin file from this repository.
 *
 * The old copy is this plugin with its version set back to 1.8.0 and its
 * duplicate check cut out, which is exactly what any build before 1.9.1 was.
 *
 * MUTATION, RUN: delete the `if ( defined( 'KBB_EXPORTER_DIR' ) ) { ... }`
 * block from kbb-exporter.php and the first case goes red with
 * "Cannot redeclare class KBB_Export_Csv", the owner's error word for word.
 */

/** Lay out wp-content/plugins with an old copy in kbb-exporter-3 and this build in kbb-exporter. */
function dupPlugins(string $oldVersion = '1.8.0'): string
{
    $root = sys_get_temp_dir().'/kbb-dup-'.bin2hex(random_bytes(4)).'/plugins';
    $src = base_path('wordpress-plugin/kbb-exporter');

    foreach (['kbb-exporter', 'kbb-exporter-3'] as $folder) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $to = $root.'/'.$folder.'/'.substr($file->getPathname(), strlen($src) + 1);
            @mkdir(dirname($to), 0777, true);
            copy($file->getPathname(), $to);
        }
    }

    // The old copy: no duplicate check, an older version number.
    $main = $root.'/kbb-exporter-3/kbb-exporter.php';
    $code = (string) file_get_contents($main);
    $code = (string) preg_replace("/\nif \( defined\( 'KBB_EXPORTER_DIR' \) \) \{.*?\n\treturn;\n\}\n/s", "\n", $code, 1, $cut);
    expect($cut)->toBe(1, 'could not find the duplicate check to cut out of the old copy');
    $code = (string) preg_replace("/define\( 'KBB_EXPORTER_VERSION', '[0-9.]+' \)/", "define( 'KBB_EXPORTER_VERSION', '{$oldVersion}' )", $code);
    file_put_contents($main, $code);

    return $root;
}

/**
 * Run WordPress's load order with stubs and report what happened.
 *
 * @param  list<string>  $load  plugin folders, in the order WordPress includes them
 * @return array{exit:int, out:string, state:array<string,mixed>}
 */
function dupRun(string $plugins, array $load, bool $adminInitDone): array
{
    $script = dirname($plugins).'/run.php';
    $state = dirname($plugins).'/state.json';

    file_put_contents($script, '<?php
define("ABSPATH", __DIR__."/");
define("WP_PLUGIN_DIR", '.var_export($plugins, true).');
$GLOBALS["kbb_hooks"] = [];
$GLOBALS["kbb_off"] = [];
function add_action($h, $cb, $p = 10, $a = 1) { $GLOBALS["kbb_hooks"][$h][] = $cb; return true; }
function did_action($h) { return '.($adminInitDone ? '1' : '0').' && $h === "admin_init" ? 1 : 0; }
function current_user_can($c) { return true; }
function deactivate_plugins($p) { $GLOBALS["kbb_off"][] = $p; }
function plugin_basename($f) { return ltrim(substr($f, strlen(WP_PLUGIN_DIR)), "/"); }
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES); }
register_shutdown_function(function () {
    foreach ($GLOBALS["kbb_hooks"]["admin_init"] ?? [] as $cb) { $cb(); }
    ob_start();
    foreach ($GLOBALS["kbb_hooks"]["admin_notices"] ?? [] as $cb) { $cb(); }
    $notices = ob_get_clean();
    file_put_contents('.var_export($state, true).', json_encode([
        "off" => $GLOBALS["kbb_off"],
        "version" => defined("KBB_EXPORTER_VERSION") ? KBB_EXPORTER_VERSION : null,
        "dir" => defined("KBB_EXPORTER_DIR") ? basename(KBB_EXPORTER_DIR) : null,
        "csv_from" => class_exists("KBB_Export_Csv", false) ? basename(dirname((new ReflectionClass("KBB_Export_Csv"))->getFileName(), 2)) : null,
        "notices" => $notices,
    ]));
});
foreach ('.var_export($load, true).' as $folder) {
    include_once WP_PLUGIN_DIR."/".$folder."/kbb-exporter.php";
}
');

    @unlink($state);
    exec(escapeshellcmd(PHP_BINARY).' -d display_errors=1 -d error_reporting=-1 '.escapeshellarg($script).' 2>&1', $out, $exit);

    return [
        'exit' => $exit,
        'out' => implode("\n", $out),
        'state' => is_file($state) ? json_decode((string) file_get_contents($state), true) : [],
    ];
}

it('activates beside an older active copy without the fatal, and switches the older copy off', function () {
    $plugins = dupPlugins();

    // Activation: admin_init has already fired when WordPress includes the new file.
    $run = dupRun($plugins, ['kbb-exporter-3', 'kbb-exporter'], adminInitDone: true);

    expect($run['out'])->not->toContain('Cannot redeclare')
        ->and($run['out'])->not->toContain('already defined')
        ->and($run['exit'])->toBe(0, $run['out']);

    // The older copy is the one switched off, by its WordPress basename ...
    expect($run['state']['off'])->toBe(['kbb-exporter-3/kbb-exporter.php']);

    // ... and the owner is told, in words, which folder it was.
    expect($run['state']['notices'])->toContain('kbb-exporter-3 (version 1.8.0)')
        ->and($run['state']['notices'])->toContain('has been deactivated');
});

it('loads alone, on the next request, as itself', function () {
    $plugins = dupPlugins();

    $run = dupRun($plugins, ['kbb-exporter'], adminInitDone: false);

    $header = [];
    preg_match('/^\s*\*\s*Version:\s*([0-9.]+)/m', (string) file_get_contents(base_path('wordpress-plugin/kbb-exporter/kbb-exporter.php')), $header);

    expect($run['exit'])->toBe(0, $run['out'])
        ->and($run['state']['off'])->toBe([])
        ->and($run['state']['version'])->toBe($header[1])
        ->and($run['state']['dir'])->toBe('kbb-exporter')
        ->and($run['state']['csv_from'])->toBe('kbb-exporter');
});

it('waits for admin_init when it is loaded before the admin is', function () {
    $plugins = dupPlugins();

    // An ordinary request with both still active: plugins load before admin_init.
    $run = dupRun($plugins, ['kbb-exporter-3', 'kbb-exporter'], adminInitDone: false);

    expect($run['exit'])->toBe(0, $run['out'])
        ->and($run['state']['off'])->toBe(['kbb-exporter-3/kbb-exporter.php']);
});

it('switches ITSELF off when the copy already running is newer', function () {
    $plugins = dupPlugins('9.9.9');

    $run = dupRun($plugins, ['kbb-exporter-3', 'kbb-exporter'], adminInitDone: true);

    expect($run['exit'])->toBe(0, $run['out'])
        ->and($run['state']['off'])->toBe(['kbb-exporter/kbb-exporter.php'])
        ->and($run['state']['version'])->toBe('9.9.9');
});

it('keeps the duplicate check\'s own version number equal to the header', function () {
    $plugin = (string) file_get_contents(base_path('wordpress-plugin/kbb-exporter/kbb-exporter.php'));

    preg_match('/^\s*\*\s*Version:\s*([0-9.]+)/m', $plugin, $header);
    preg_match("/\\\$kbb_exporter_mine\s*=\s*'([0-9.]+)'/", $plugin, $mine);

    expect($mine[1] ?? null)->toBe($header[1], 'the duplicate check compares versions with its own copy of the number');
});
