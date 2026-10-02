<?php
/*
 * Rebuild the category header's phone-and-laptop rules (Lane QC).
 *
 *   php tools/qc-split-css.php           rewrite the generated block
 *   php tools/qc-split-css.php --check   exit 1 if it is out of date
 *
 * Reads resources/css/kbb/kbb-title-header.css, keeps everything above the
 * generated block, and writes the block again from it with
 * App\Support\TitleHeaderSplitCss -- the same code QcCategoryHeaderDevicesTest
 * checks the file against. Run it after editing any rule in that file, then
 * `npx vite build`.
 */

require __DIR__.'/../vendor/autoload.php';

$path = __DIR__.'/../resources/css/kbb/kbb-title-header.css';
$css = (string) file_get_contents($path);
$next = \App\Support\TitleHeaderSplitCss::apply($css);

if (in_array('--check', $argv, true)) {
    if ($next !== $css) {
        fwrite(STDERR, "kbb-title-header.css: the generated phone-and-laptop block is out of date. Run php tools/qc-split-css.php\n");
        exit(1);
    }

    echo "up to date\n";
    exit(0);
}

file_put_contents($path, $next);
echo 'wrote '.substr_count(\App\Support\TitleHeaderSplitCss::generated($next) ?? '', "\n")." lines\n";
