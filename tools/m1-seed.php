<?php
/* Seed the Lane M1 preview: a section with clips, and a library holding both
   pictures and videos, so the screenshots show the real screens with real rows. */
$root = getenv('KBB_PUBLIC_PATH');

function png(string $path, int $w, int $h, array $rgb): void {
    @mkdir(dirname($path), 0755, true);
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, ...$rgb));
    imagepng($im, $path);
    imagedestroy($im);
}
function mp4(string $path, int $pad = 4096): void {
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, pack('N', 32).'ftyp'.'isom'.pack('N', 512).'isomiso2avc1mp41'.str_repeat("\x00", $pad));
}

$clips = [
    ['anua-mist-spray.mp4', 'Anua mist spray 2', [214, 116, 140]],
    ['cosrx-snail-essence.mp4', 'COSRX snail essence', [92, 148, 196]],
    ['spf-50-review.mp4', 'Spf 50 review', [120, 180, 130]],
];

$section = \App\Models\UgcSection::create([
    'title' => 'Homepage hero rail', 'handle' => 'homepage-hero-rail',
    'heading' => 'Loved by our customers', 'status' => 'publish', 'max_tiles' => 12,
]);

$sync = [];
foreach ($clips as $i => [$file, $title, $rgb]) {
    $clip = 'clip-20260927-1200'.$i.'-preview'.$i.'.mp4';
    $poster = 'poster-20260927-1200'.$i.'-preview'.$i.'.png';
    mp4($root.'/uploads/ugc/'.$clip);
    png($root.'/uploads/ugc/'.$poster, 360, 640, $rgb);

    $v = \App\Models\UgcVideo::create([
        'slug' => 'preview-'.$i, 'title' => $title,
        'status' => $i === 2 ? 'draft' : 'publish',
        'rights_status' => $i === 2 ? 'pending' : 'granted',
        'source_platform' => 'upload', 'creator_handle' => '@'.['jiyoon','marwa','sara'][$i],
        'file_path' => '/uploads/ugc/'.$clip, 'poster_path' => '/uploads/ugc/'.$poster,
        'bytes' => filesize($root.'/uploads/ugc/'.$clip),
        'poster_bytes' => filesize($root.'/uploads/ugc/'.$poster),
        'width' => 360, 'height' => 640,
    ]);
    \App\Support\MediaRegistrar::record('/uploads/ugc/'.$clip, $file);
    \App\Support\MediaRegistrar::record('/uploads/ugc/'.$poster, null);
    $sync[$v->id] = ['position' => $i];
}
$section->videos()->sync($sync);

// A handful of ordinary pictures, so the library grid shows both kinds.
foreach ([['cosrx-cleanser.png', 800, 800], ['beauty-of-joseon.png', 600, 600],
          ['anua-toner.png', 700, 700], ['skin1004-ampoule.png', 640, 640]] as $i => [$n, $w, $h]) {
    $stored = '20260927-1100'.$i.'-lib'.$i.'.png';
    png($root.'/uploads/products/'.$stored, $w, $h, [230 - $i * 20, 235, 245]);
    \App\Support\MediaRegistrar::record('uploads/products/'.$stored, $n);
}

echo "seeded: section {$section->id}, ".\App\Models\Media::count()." media rows\n";
