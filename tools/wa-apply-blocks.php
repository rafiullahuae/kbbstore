<?php

/*
 * Apply docs/WA-ADMIN-APP-BLOCKS.md.                                 (Lane WA)
 *
 *     php tools/wa-apply-blocks.php
 *
 * Every anchor's count is checked before anything is written; one that is off
 * stops the run with nothing touched. A block already in place is skipped.
 * In a lane this edits files the lane may not ship: revert them afterwards.
 */
declare(strict_types=1);

$base = dirname(__DIR__);
require $base.'/vendor/autoload.php';

$r = Tests\Support\WhatsAppButtonHandover::finished($base);

if ($r['problems'] !== []) {
    fwrite(STDERR, "REFUSING, nothing written:\n  ".implode("\n  ", $r['problems'])."\n");
    exit(1);
}

foreach ($r['files'] as $file => $src) {
    if ($src !== file_get_contents($base.'/'.$file)) {
        file_put_contents($base.'/'.$file, $src);
    }
}

echo $r['applied'] === [] ? "all blocks already in place\n" : 'applied blocks '.implode(', ', $r['applied'])."\n";
