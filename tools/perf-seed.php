<?php
/* Lane PERF — the fixture the Lighthouse numbers are taken on.
 *
 * It reproduces the homepage PageSpeed Insights measured on extrabeauty.ae on
 * 29 September 2026, in the three things that report blamed:
 *
 *   1. A CARDS BANNER whose pictures are the originals. The live set's three
 *      files are 810x1440 (134.3 KiB), 810x1440 (134.3 KiB) and 720x1280
 *      (72.3 KiB); "Improve image delivery" wanted 276.0 KiB of that back,
 *      and the first one is the page's LCP element.
 *   2. A SHOPPABLE-VIDEO RAIL, which is where the 7.2 KiB inline <style> and
 *      the 10.6 KiB inline <script> come from.
 *   3. ONE UGC POSTER WHOSE FILE IS NOT ON DISK, which is the single console
 *      404 that costs Best Practices its 4 points. The live row is
 *      /uploads/ugc/poster-20260928-081820-fCoQkEvJ5G.jpg and the server
 *      answers 404 for it; here one clip is given a poster_path that was
 *      never written, the same way.
 *
 * The pictures are DRAWN, not committed: a photograph in the repository to
 * prove a byte count is a binary somebody has to review, and GD makes a JPEG
 * whose weight is in the same class as the owner's.
 */

use App\Models\BannerCard;
use App\Models\BannerSet;
use App\Models\UgcSection;
use App\Models\UgcVideo;

\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

/* ── 1. the cards banner ─────────────────────────────────────────────────── */

$dir = public_path('uploads/posters');
@mkdir($dir, 0775, true);

/* Portrait, at the two sizes the owner's own files are. Noise on top of a
   gradient, because a flat fill compresses to nothing and the point of the
   fixture is the weight. */
$sizes = [[810, 1440], [810, 1440], [720, 1280], [810, 1440], [720, 1280], [810, 1440]];
$palette = [
    [0xE8, 0x91, 0x9F], [0xF4, 0xC4, 0xB8], [0xC9, 0xA7, 0xD6],
    [0xA8, 0xD5, 0xCB], [0xF2, 0xD7, 0x8E], [0xB9, 0xC9, 0xEE],
];
$copy = [
    ['Glass skin set', 'Cleanse, essence, serum', 'Shop set'],
    ['Barrier repair', 'For skin that stings', 'Shop now'],
    ['SPF every day', 'Weightless, no white cast', 'See all'],
    ['Overnight masks', 'Wake up plump', 'Shop masks'],
    ['Gentle actives', 'Retinal, slowly', 'Explore'],
    ['Under AED 99', 'Small sizes, real formulas', 'Browse'],
];

$set = BannerSet::create([
    'name' => 'Autumn edit', 'slug' => 'autumn-edit', 'status' => 'publish', 'position' => 0,
]);

foreach ($copy as $i => [$heading, $body, $label]) {
    [$w, $h] = $sizes[$i];
    $file = $dir.'/perf-poster-'.($i + 1).'.jpg';

    $img = imagecreatetruecolor($w, $h);
    [$r, $g, $b] = $palette[$i];
    for ($y = 0; $y < $h; $y += 4) {
        $t = $y / $h;
        imagefilledrectangle($img, 0, $y, $w, $y + 4, imagecolorallocate(
            $img, (int) ($r * (1 - $t) + 250 * $t), (int) ($g * (1 - $t) + 240 * $t), (int) ($b * (1 - $t) + 245 * $t)
        ));
    }
    imagefilledellipse($img, (int) ($w * .35), (int) ($h * .3), (int) ($w * .7), (int) ($w * .7),
        imagecolorallocatealpha($img, 255, 255, 255, 96));
    /* Grain. Without it a gradient JPEG is 12 KB and the fixture no longer
       reproduces the thing being measured. */
    mt_srand(4242 + $i);
    for ($n = 0; $n < ($w * $h) / 34; $n++) {
        imagesetpixel($img, mt_rand(0, $w - 1), mt_rand(0, $h - 1),
            imagecolorallocate($img, mt_rand(150, 255), mt_rand(150, 255), mt_rand(150, 255)));
    }
    imagejpeg($img, $file, 82);
    imagedestroy($img);

    $path = 'uploads/posters/'.basename($file);
    \App\Support\MediaRegistrar::record($path);

    /* The phone-sized copies, exactly as Content -> Media Library -> "Make
       phone-sized copies" writes them. They ARE on the live box -- the owner's
       report shows the product tile beside this carousel serving
       `img-cache/400/uploads/posters/20260928-081819-AHt4kiCq.jpg` -- so a
       fixture without them would be measuring a different shop. */
    \App\Support\ImageVariants::generate('/'.$path);

    BannerCard::create([
        'banner_set_id' => $set->id,
        'image' => $path,
        'image_w' => $w,
        'image_h' => $h,
        'alt' => $heading,
        'heading' => $heading,
        'body' => $body,
        'button_label' => $label,
        'button_url' => '/shop/',
        'position' => $i,
    ]);
}

app(\App\Services\SettingsService::class)->setModule(\App\Services\Banners::MODULE, true);
app(\App\Services\SettingsService::class)->setModuleSetting(\App\Services\Banners::MODULE, 'set', (string) $set->id);

/* ── 2 and 3. the shoppable-video rail ───────────────────────────────────── */

app(\App\Services\SettingsService::class)->setModule('shoppable_video', true);

$ugcDir = public_path('uploads/ugc');
@mkdir($ugcDir, 0775, true);

$section = UgcSection::updateOrCreate(['handle' => 'home-reels'], [
    'title' => 'Real people, real routines', 'status' => 'publish',
]);

foreach (range(1, 4) as $i) {
    /* Clip 3 is the owner's broken row: a poster_path that names a file which
       was never written. Everything else about the row is valid, which is why
       nothing upstream of the browser notices. */
    $missing = $i === 3;
    $poster = 'uploads/ugc/perf-poster-clip-'.$i.'.jpg';

    if (! $missing) {
        $img = imagecreatetruecolor(360, 640);
        imagefilledrectangle($img, 0, 0, 360, 640, imagecolorallocate($img, 40 + $i * 30, 30 + $i * 20, 60 + $i * 25));
        mt_srand(99 + $i);
        for ($n = 0; $n < 8000; $n++) {
            imagesetpixel($img, mt_rand(0, 359), mt_rand(0, 639),
                imagecolorallocate($img, mt_rand(60, 220), mt_rand(60, 220), mt_rand(60, 220)));
        }
        imagejpeg($img, public_path($poster), 82);
        imagedestroy($img);
        \App\Support\MediaRegistrar::record($poster);
        \App\Support\ImageVariants::generate('/'.$poster);
    }

    /* A REAL mp4, copied from the one this shop already carries.
       App\Support\UgcDemoMedia holds a genuine H.264 clip as base64 for the
       Content -> Demo content screen, and materialise() writes it into
       /uploads/ugc/. Without a playable file every tile's <video> is a 404 of
       its own, and a 404 hunt that turns up the video rather than the cover is
       a 404 hunt that proves nothing about the cover. (The container's ffmpeg
       has no `lavfi`, so there is nothing to synthesise one with.) */
    $clip = 'uploads/ugc/perf-clip-'.$i.'.mp4';
    $demo = \App\Support\UgcDemoMedia::materialise();

    if ($demo !== null && ! is_file(public_path($clip))) {
        @copy(public_path(ltrim($demo['clip'], '/')), public_path($clip));
    }

    $video = UgcVideo::updateOrCreate(['slug' => 'perf-clip-'.$i], [
        'title' => 'Routine '.$i,
        'status' => 'publish',
        'rights_status' => 'granted',
        'file_path' => '/uploads/ugc/perf-clip-'.$i.'.mp4',
        'poster_path' => '/'.$poster,
        'width' => 360,
        'height' => 640,
        'creator_handle' => '@routine'.$i,
        'published_at' => now()->subDay(),
    ]);

    $section->videos()->syncWithoutDetaching([$video->id => ['position' => $i - 1]]);
}

app(\App\Services\SettingsService::class)->setModuleSetting('shoppable_video', 'home_section', 'home-reels');
\App\Services\SettingsService::forgetMemo();

/* ── the hero is OFF, because it is off on the shop being measured ────────
 *
 * The owner's report names the LCP element as
 * `div.kbbn-tr > div#kbbn-1-0 > a.kbbn-im > img` and its network list carries
 * four poster files and no hero picture at all. With the hero slider left on,
 * this fixture's LCP is the gradient slide above the carousel and every
 * measurement below it is of a page the owner does not have. */
$rows = app(\App\Services\HomepageSections::class)->all();
$rows['hero']['desktop'] = false;
$rows['hero']['mobile'] = false;
app(\App\Services\HomepageSections::class)->save($rows);
\App\Services\SettingsService::forgetMemo();

echo "seeded: banner set {$set->id}, ugc section {$section->id}\n";
