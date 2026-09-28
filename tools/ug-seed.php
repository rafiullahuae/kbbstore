<?php
/* Lane UG — seed a storefront preview that carries a REAL shoppable-video rail.
 *
 * Six clips in one section, on a published page, with real media on disk. The
 * media is VP9 in WebM and NOT H.264, because Playwright's bundled Chromium is
 * the open-source build and carries no H.264 decoder — CLAUDE.md's instrument
 * warnings and tools/ugcloop-check.cjs both say so, and a lane that seeds an
 * mp4 here "finds" a media error that does not exist on the owner's phone.
 *
 * Two of the six are deliberately extreme: `bright` is a near-white clip and
 * `dark` a near-black one, so the lightbox's legibility scrim is measured over
 * both rather than guessed at.
 */
use App\Models\Brand;
use App\Models\Page;
use App\Models\Product;
use App\Models\UgcSection;
use App\Models\UgcVideo;

\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

app(\App\Services\SettingsService::class)->setModule('shoppable_video', true);

/* Arabic on, so the RTL evidence is the REAL mirrored storefront at /ar/... and
   not a `dir` attribute flipped by the instrument. The rail's whole geometry is
   inset-inline-*, and the thing worth a screenshot is a browser resolving those
   against a page that is genuinely right-to-left. */
\App\Models\Setting::query()->updateOrCreate(
    ['key' => \App\Support\Locale::SETTING_ENABLED], ['value' => '1']
);
\App\Models\Setting::query()->updateOrCreate(
    ['key' => \App\Support\Locale::SETTING_RTL], ['value' => '1']
);
\App\Services\SettingsService::forgetMemo();

$brand = Brand::updateOrCreate(['slug' => 'ug-brand'], ['name' => 'Beauty of Joseon']);
$brand2 = Brand::updateOrCreate(['slug' => 'ug-brand-2'], ['name' => 'COSRX']);

$mk = function (string $slug, string $name, int $price, ?int $sale, $brand, float $rating, int $reviews) {
    return Product::updateOrCreate(['slug' => $slug], [
        'name' => $name, 'price' => $price, 'sale_price' => $sale,
        'brand_id' => $brand->id, 'image' => '/uploads/ugc/prod.jpg',
        'status' => 'publish', 'is_visible' => true, 'stock_status' => 'instock',
        'type' => 'simple', 'rating' => $rating, 'review_count' => $reviews,
    ]);
};

$p1 = $mk('ug-relief-sun', 'Relief Sun Rice + Probiotics SPF50+ 50ml', 8900, 6900, $brand, 4.6, 412);
$p2 = $mk('ug-snail-mucin', 'Advanced Snail 96 Mucin Power Essence 100ml', 7500, null, $brand2, 4.8, 1290);
$p3 = $mk('ug-glow-serum', 'Glow Deep Serum Rice + Alpha Arbutin 30ml', 6400, 5100, $brand, 4.4, 88);
$p4 = $mk('ug-cleansing-balm', 'Radiance Cleansing Balm 100ml', 9900, null, $brand, 4.2, 0);

/* The six clips. `dark` and `bright` carry NO teaser file on purpose — that is
   the owner's server, where ffmpeg cannot be started from PHP-FPM, so the tile
   has to cut its loop out of the full clip at playback. `clip1`..`clip4` DO
   carry one, so both arms of the loop are on one page at once. */
$clips = [
    ['ug-bright', 'Bright clip — no teaser file', '/uploads/ugc/bright.webm', null, '/uploads/ugc/bright.jpg', '@brightcreator', [$p1->id, $p2->id, $p3->id]],
    ['ug-dark',   'Dark clip — no teaser file',   '/uploads/ugc/dark.webm',   null, '/uploads/ugc/dark.jpg',   '@darkcreator',   [$p1->id]],
    ['ug-c1', 'Clip one',   '/uploads/ugc/clip1.webm', '/uploads/ugc/clip1.webm', '/uploads/ugc/clip1.jpg', '@one',   [$p2->id]],
    ['ug-c2', 'Clip two',   '/uploads/ugc/clip2.webm', '/uploads/ugc/clip2.webm', '/uploads/ugc/clip2.jpg', '@two',   [$p3->id, $p4->id]],
    ['ug-c3', 'Clip three', '/uploads/ugc/clip3.webm', '/uploads/ugc/clip3.webm', '/uploads/ugc/clip3.jpg', '@three', [$p1->id, $p2->id, $p3->id, $p4->id]],
    ['ug-c4', 'Clip four',  '/uploads/ugc/clip4.webm', '/uploads/ugc/clip4.webm', '/uploads/ugc/clip4.jpg', '@four',  []],
];

$section = UgcSection::updateOrCreate(['handle' => 'ug-rail'], [
    'title' => 'Seen on TikTok', 'status' => 'publish',
]);

$pos = 0;
foreach ($clips as [$slug, $title, $file, $teaser, $poster, $handle, $products]) {
    $v = UgcVideo::updateOrCreate(['slug' => $slug], [
        'title' => $title,
        'caption' => 'Glass skin in six steps, on camera',
        'status' => 'publish',
        'rights_status' => 'granted',
        'file_path' => $file,
        'teaser_path' => $teaser,
        'poster_path' => $poster,
        'width' => 720, 'height' => 1280,
        'duration_ms' => 8000,
        'creator_handle' => $handle,
        'source_url' => 'https://www.tiktok.com/@'.ltrim($handle, '@'),
        'source_platform' => 'tiktok',
        'published_at' => now()->subDay(),
    ]);
    $v->products()->sync($products);
    $section->videos()->syncWithoutDetaching([$v->id => ['position' => $pos++]]);
}

Page::updateOrCreate(['slug' => 'about'], [
    'title' => 'Shoppable video preview',
    // `published`, not `publish` — Store\PageController::show() filters on the
    // former and a page seeded with the latter 404s.
    'status' => 'published',
    'content' => '<h1>Shoppable video preview</h1><p>The rail below is the real shortcode.</p>'
        .'[kbb_videos section="ug-rail"]',
]);

app(\App\Services\UgcRail::class)->flush();
\App\Support\Shortcodes::flush();

echo "seeded ".UgcVideo::count()." clips, section ug-rail, page /about\n";
