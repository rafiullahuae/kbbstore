<?php

/*
 * Lane MB — put the preview shop into the state an import leaves it in, so the
 * Media Library can be photographed with imported files in it.
 *
 * Run through tinker so the app is booted and usePublicPath() is honoured:
 *
 *   KBB_PUBLIC_PATH=$PWD/public php artisan tinker --env=mbpreview \
 *     --execute="require 'tools/mb-seed-preview.php';"
 *
 * KBB_PUBLIC_PATH is not optional — without it usePublicPath() names a
 * directory on one server in the world (CLAUDE.md: leave that line alone) and
 * the pictures are written somewhere that does not exist.
 *
 * ── WHAT THIS DOES AND WHAT IT DOES NOT FAKE ────────────────────────────────
 *
 * EGRESS IS BLOCKED IN THIS CONTAINER, so `MediaSideloader` cannot actually
 * reach the old WordPress host and no screenshot in this repo can be taken from
 * a real fetch. What is faked is exactly one thing: the HTTP response. The
 * bytes are written to the path `MediaSideloader::targetPath()` computes for the
 * fixture's own URLs, and then the two REAL registration paths run over them:
 *
 *   REGISTER ON FETCH — `MediaRegistrar::record($path, …, $sniffedMime)`, the
 *   identical call MediaSideloader::batch() now makes the moment a fetch lands,
 *   with the identical arguments. This is the half that needs nobody to press
 *   anything.
 *
 *   THE WALK — `MediaBackfill::runReport()`, unmodified, over the same tree
 *   plus a few files it was NOT told about, which stand for the bulk copy
 *   MediaRewrite's own ABSENT message tells the owner to make ("Copy
 *   wp-content/uploads across from the old host first").
 *
 * The products, categories, brands and posts are the real fixture export run
 * through the real ImportRunner, so the URLs and the year/month tree are the
 * export's and not this file's invention.
 */

use App\Models\Media;
use App\Services\Import\ImportOptions;
use App\Services\Import\ImportRunner;
use App\Services\Import\MediaSideloader;
use App\Support\MediaBackfill;
use App\Support\MediaRegistrar;

/* ------------------------------------------------------- a real JPEG / PNG */

$jpeg = static function (int $w, int $h): string {
    $im = imagecreatetruecolor($w, $h);

    // Something to look at in a thumbnail, so the grid is not 40 black squares.
    for ($i = 0; $i < 90; $i++) {
        imagefilledellipse(
            $im,
            random_int(0, $w),
            random_int(0, $h),
            random_int(20, 160),
            random_int(20, 160),
            imagecolorallocate($im, random_int(120, 255), random_int(90, 210), random_int(120, 230))
        );
    }

    ob_start();
    imagejpeg($im, null, 82);
    $bytes = (string) ob_get_clean();
    imagedestroy($im);

    return $bytes;
};

$png = static function (int $w, int $h) use ($jpeg): string {
    $im = imagecreatefromstring($jpeg($w, $h));
    ob_start();
    imagepng($im);
    $bytes = (string) ob_get_clean();
    imagedestroy($im);

    return $bytes;
};

/* --------------------------------------------- the real fixture, imported */

echo "Importing tests/Fixtures/kbb-export through the real ImportRunner...\n";

$report = (new ImportRunner)->run(new ImportOptions(directory: base_path('tests/Fixtures/kbb-export')));

echo '  products: '.\App\Models\Product::query()->count()
    .'  categories: '.\App\Models\Category::query()->count()
    .'  brands: '.\App\Models\Brand::query()->count()."\n";

/* ------------------------------- the fetch, with only the response faked */

$sideloader = new MediaSideloader(new \App\Services\Import\MediaAudit);
$onFetch = 0;

echo "Landing the fixture's own media URLs and registering each on 'fetch'...\n";

foreach (array_unique(array_column(
    array_map(
        static fn (array $row): array => ['url' => $row[0]],
        array_slice(array_map('str_getcsv', file(base_path('tests/Fixtures/kbb-export/media.csv'))), 1)
    ),
    'url'
)) as $url) {
    ['path' => $path, 'refusal' => $refusal] = $sideloader->targetPath($url);

    if ($path === null) {
        echo "  refused: $url — $refusal\n";

        continue;
    }

    $absolute = $sideloader->absolute($path);
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $bytes = $extension === 'png' ? $png(900, 900) : $jpeg(1000, 1000);
    $mime = $extension === 'png' ? 'image/png' : 'image/jpeg';

    if (! is_dir(dirname($absolute))) {
        mkdir(dirname($absolute), 0o755, true);
    }

    file_put_contents($absolute, $bytes);

    // THE EXACT CALL MediaSideloader::batch() NOW MAKES, arguments and all.
    if (MediaRegistrar::record($path, basename($path), $mime) !== null) {
        $onFetch++;
    }
}

echo "  registered on fetch: $onFetch\n";

/* ------------------------------ and the re-point the same batch performs */

/*
 * MediaSideloader::batch() RE-POINTS THE ROWS IT JUST LANDED, in the same
 * request, and that is not incidental to this picture: until the rows name the
 * local path, MediaUsage's index matches nothing and every imported tile in the
 * library reads "unused" — which is the state that invites an operator to
 * delete a photograph that is on a live product page. Faking the fetch without
 * this step would have produced a screenshot that libels the fix.
 *
 * Verified after this runs: an imported row carries usages ≥ 1.
 */
$repointed = 0;

foreach ([[\App\Models\Product::class, 'image'], [\App\Models\Brand::class, 'logo'], [\App\Models\Category::class, 'image'], [\App\Models\Post::class, 'cover']] as [$model, $column]) {
    foreach ($model::query()->where($column, 'like', '%/wp-content/uploads/%')->get() as $row) {
        $row->{$column} = (string) preg_replace('#^https?://[^/]+/#', '/', (string) $row->{$column});
        $row->save();
        $repointed++;
    }
}

echo "  re-pointed rows: $repointed\n";

/* ---------------- and a bulk copy the importer was never told about ------ */

echo "Adding a tree that arrived by FTP, which only the walk can find...\n";

$ftp = [
    'wp-content/uploads/2018/11/snail-mucin-essence.jpg',
    'wp-content/uploads/2018/11/snail-mucin-essence-300x300.jpg',
    'wp-content/uploads/2019/07/rice-toner-set.jpg',
    'wp-content/uploads/2019/07/rice-toner-set-150x150.jpg',
    'wp-content/uploads/2020/02/centella-ampoule.png',
    'wp-content/uploads/2020/02/centella-ampoule-768x768.png',
    'wp-content/uploads/2021/09/cleansing-oil-duo.jpg',
    'wp-content/uploads/2022/04/sunscreen-spf50.jpg',
    'wp-content/uploads/2022/04/sunscreen-spf50-1024x1024.jpg',
    'wp-content/uploads/2023/01/sheet-mask-box.png',
];

foreach ($ftp as $path) {
    $absolute = public_path($path);

    if (! is_dir(dirname($absolute))) {
        mkdir(dirname($absolute), 0o755, true);
    }

    file_put_contents(
        $absolute,
        str_ends_with($path, '.png') ? $png(700, 700) : $jpeg(800, 800)
    );
}

// And one admin upload under the OTHER root, so the picture shows both trees in
// one library — rule 1, the tree that already worked still works.
$own = 'uploads/products/mb-admin-upload.png';

if (! is_dir(public_path('uploads/products'))) {
    mkdir(public_path('uploads/products'), 0o755, true);
}

file_put_contents(public_path($own), $png(600, 600));

$walk = MediaBackfill::runReport();

// The walk's bulk insert fires no Eloquent event, so it syncs usages itself;
// the rows recorded on 'fetch' above were created BEFORE the re-point, so their
// index entries are rebuilt here. Both paths, one call.
\App\Support\MediaUsageWriter::syncMedia(
    Media::query()->select(['id', 'filename', 'path'])->get()->all()
);

echo '  walk added: '.$walk['added'].'  truncated: '.(json_encode($walk['truncated']) ?: '[]')."\n";

/* ------------------------------------------------- what the library holds */

$imported = Media::query()->where('path', 'like', 'wp-content/uploads/%')->count();
$mine = Media::query()->where('path', 'like', 'uploads/%')->count();

echo "\nMedia Library now holds:\n";
echo "  imported (wp-content/uploads/): $imported\n";
echo "  this shop's own (uploads/):     $mine\n";
echo '  total:                         '.Media::query()->count()."\n";

\App\Services\SettingsService::forgetMemo();
\App\Models\Setting::flushMap();
\Illuminate\Support\Facades\Cache::flush();
