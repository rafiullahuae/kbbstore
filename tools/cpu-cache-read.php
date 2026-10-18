<?php
// Lane CPU -- size of each file-cache entry and the cost of ONE read of it the
// way Illuminate\Cache\FileStore::get() does it (file_get_contents + unserialize),
// median of 200. Reads the preview's own storage/framework/cache only.
//   php tools/cpu-cache-read.php <cache data dir> key...
$dir = $argv[1];
foreach (array_slice($argv, 2) as $key) {
    $h = sha1($key);
    $f = $dir.'/'.substr($h, 0, 2).'/'.substr($h, 2, 2).'/'.$h;
    if (! is_file($f)) { printf("%-28s (no entry)\n", $key); continue; }
    $t = [];
    for ($i = 0; $i < 200; $i++) {
        $s = hrtime(true);
        $raw = file_get_contents($f);
        $v = unserialize(substr($raw, 10));
        $t[] = (hrtime(true) - $s) / 1e6;
    }
    sort($t);
    printf("%-28s %8d bytes  %.3f ms/read\n", $key, filesize($f), $t[100]);
}
