<?php
/*
 * Lane HC wiring, idempotent. Run from the repository root:
 *
 *     php tools/hc-wire.php          # wire (no-op if already wired)
 *     php tools/hc-wire.php --undo   # take it out again (local previews only)
 *
 * Two lines, each placed directly after the line it belongs beside:
 *
 *   routes/web.php
 *       require __DIR__.'/homepage-live-admin.php';
 *     + require __DIR__.'/homepage-hub-admin.php';
 *
 *   resources/views/admin/app.blade.php
 *       @include('admin.partials.homepage-content-screen')
 *     + @include('admin.partials.homepage-hub')
 */
$undo = in_array('--undo', $argv, true);
$edits = [
    'routes/web.php' => ["        require __DIR__.'/homepage-live-admin.php';\n", "        require __DIR__.'/homepage-hub-admin.php';\n"],
    'resources/views/admin/app.blade.php' => ["@include('admin.partials.homepage-content-screen')\n", "@include('admin.partials.homepage-hub')\n"],
];
foreach ($edits as $file => [$anchor, $line]) {
    $src = (string) file_get_contents($file);
    $has = substr_count($src, $line);
    if ($undo) {
        $src = str_replace($line, '', $src);
        echo "{$file}: removed {$has}\n";
    } elseif ($has === 0) {
        if (substr_count($src, $anchor) !== 1) {
            fwrite(STDERR, "{$file}: anchor not found exactly once\n");
            exit(1);
        }
        $src = str_replace($anchor, $anchor.$line, $src);
        echo "{$file}: wired\n";
    } else {
        echo "{$file}: already wired ({$has})\n";
    }
    file_put_contents($file, $src);
}
