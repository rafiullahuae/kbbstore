<?php
/* Lane UG3 — the rail the one-second round is measured on.
 *
 * ── WHY NOT tools/ug2-seed.php ──────────────────────────────────────────────
 *
 * UG2's seed reproduces the owner's rail as his screenshot shows it, and it is
 * the right fixture for the question UG2 asked (which tiles get a playback
 * slot). This round asks four different questions, and three of them need a
 * tile UG2's rail does not contain:
 *
 *   tile 1  a clip with its OWN one-second teaser file. The whole point of the
 *           change, and the only tile that loops natively with no seek at all.
 *   tile 2  the SAME clip with NO teaser, so the bytes-per-tile comparison is
 *           between two tiles pointing at the same content and differing in one
 *           thing. This is the tile the owner actually has.
 *   tile 3  a BRIGHT poster (cream) and tile 4 a DARK one, because a loading
 *           indicator that has only been looked at on one of those has not been
 *           looked at.
 *   tile 5  a clip whose file IS NOT THERE. `data-ugcr-fault` is set by the
 *           media error, and what a failed tile shows is a question this round
 *           has to answer in a picture. UG2's lost-file row exists but was
 *           deliberately kept OUT of its section; here it is the point.
 *   tile 6  a second uncut clip, so the cap (4) still binds at 1280 and there is
 *           a genuine "ready, not playing" tile to photograph — the middle of
 *           the three states, which cannot be forced without lying.
 */
use App\Models\Page;
use App\Models\Brand;
use App\Models\Product;
use App\Models\UgcSection;
use App\Models\UgcVideo;

\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], [
    'name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner',
]);

app(\App\Services\SettingsService::class)->setModule('shoppable_video', true);

/* Arabic on, so the RTL evidence is the real mirrored storefront at /ar/ rather
   than a `dir` attribute flipped by the instrument. */
\App\Models\Setting::query()->updateOrCreate(
    ['key' => \App\Support\Locale::SETTING_ENABLED], ['value' => '1']
);
\App\Models\Setting::query()->updateOrCreate(
    ['key' => \App\Support\Locale::SETTING_RTL], ['value' => '1']
);
\App\Services\SettingsService::forgetMemo();

$brand = Brand::updateOrCreate(['slug' => 'ug3-boj'], ['name' => 'Beauty of Joseon']);
$brand2 = Brand::updateOrCreate(['slug' => 'ug3-anua'], ['name' => 'Anua']);

$mk = function (string $slug, string $name, int $price, ?int $sale, $b, float $rating, int $reviews) {
    return Product::updateOrCreate(['slug' => $slug], [
        'name' => $name, 'price' => $price, 'sale_price' => $sale, 'brand_id' => $b->id,
        'image' => '/uploads/ugc/real-a.jpg', 'status' => 'publish', 'is_visible' => true,
        'stock_status' => 'instock', 'type' => 'simple', 'rating' => $rating, 'review_count' => $reviews,
    ]);
};

$p = [
    $mk('ug3-relief-sun', 'Relief Sun Rice + Probiotics SPF50+', 21000, 14700, $brand, 4.6, 412),
    $mk('ug3-snail', 'Advanced Snail 96 Mucin Power Essence', 8000, null, $brand2, 4.8, 1290),
    $mk('ug3-toner', 'Heartleaf 77% Soothing Toner', 10100, 7000, $brand2, 4.5, 301),
    $mk('ug3-device', 'Age-R Booster Pro Device', 20800, null, $brand, 4.3, 44),
    $mk('ug3-mist', 'Vitamin C Brightening Serum', 15200, 10600, $brand2, 4.4, 77),
    $mk('ug3-fresh', 'Fresh That Lasts Deodorant', 20800, null, $brand, 4.1, 12),
];

$TEASER = '/uploads/ugc/teaser-1s-20260929-000000-ug3aaaaaaa.webm';

$rows = [
    /* slug, title, handle, caption, file, teaser, poster, w, h, ms, products */
    ['ug3-cut', 'cut at one second', '@extrabeauty', 'Its own 1s teaser file — the tile loops it natively',
        '/uploads/ugc/long-a.webm', $TEASER, '/uploads/ugc/long-a.jpg', 1080, 1920, 8000, [$p[0]->id]],
    ['ug3-uncut', 'the same clip, never cut', '@extrabeauty', 'No teaser file — the loop comes out of the full clip',
        '/uploads/ugc/long-a.webm', null, '/uploads/ugc/long-a.jpg', 1080, 1920, 8000, [$p[1]->id]],
    ['ug3-bright', 'anua mist spray mini', '@extrabeauty', 'Bright poster — the hard case for a white loader',
        '/uploads/ugc/real-a.webm', null, '/uploads/ugc/real-a.jpg', 270, 480, 6000, [$p[2]->id]],
    ['ug3-dark', 'bright underarms era with Medicube', '@extrabeauty', 'Dark poster',
        '/uploads/ugc/real-b.webm', null, '/uploads/ugc/real-b.jpg', 270, 480, 6000, [$p[3]->id]],
    /* THE FILE IS NOT THERE. The poster is, which is the shape that fools every
       screen: a cover that loads perfectly beside a video that does not exist. */
    ['ug3-gone', 'niacinamide routine, week 3', '@extrabeauty', 'Its file is gone — this tile must not spin for ever',
        '/uploads/ugc/clip-20260921-7hk2mq9wxb.webm', null, '/uploads/ugc/real-b.jpg', 270, 480, 7000, [$p[4]->id]],
    ['ug3-spare', 'glass skin in 6 steps', '@extrabeauty', 'Past the cap at 1280 — ready, and waiting to be tapped',
        '/uploads/ugc/real-a.webm', null, '/uploads/ugc/real-a.jpg', 270, 480, 6000, [$p[5]->id]],
];

$section = UgcSection::updateOrCreate(['handle' => 'shop-the-look'], [
    'title' => 'Shop the look', 'status' => 'publish',
]);

$pos = 0;
foreach ($rows as [$slug, $title, $handle, $caption, $file, $teaser, $poster, $w, $h, $ms, $prods]) {
    $v = UgcVideo::updateOrCreate(['slug' => $slug], [
        'title' => $title, 'caption' => $caption, 'status' => 'publish',
        'rights_status' => 'granted', 'rights_granted_at' => now(),
        'file_path' => $file, 'teaser_path' => $teaser, 'poster_path' => $poster,
        'width' => $w, 'height' => $h, 'duration_ms' => $ms,
        'creator_handle' => $handle, 'source_platform' => 'upload',
        'published_at' => now()->subDay(),
    ]);
    $v->products()->sync($prods);
    $section->videos()->syncWithoutDetaching([$v->id => ['position' => $pos++]]);
}

Page::updateOrCreate(['slug' => 'about'], [
    'status' => 'published', 'title' => 'Shop the look',
    'content' => '<h1>Shop the look</h1>[kbb_videos section="shop-the-look"]',
]);

/* THE HOMEPAGE RAIL IS THE ONE TO MEASURE. A CMS page's content column is 695px
   wide, so tiles 4-6 are clipped by the rail's own overflow and the observer
   refuses them — which reads exactly like the defect still being there.
   CLAUDE.md and tools/ug2-shots.cjs both say so; this seed puts the rail where
   the instrument expects it. */
$sv = app(\App\Services\SettingsService::class);
$sv->setModuleSetting('shoppable_video', 'home_section', 'shop-the-look');
$sv->flush();

app(\App\Services\UgcRail::class)->flush();
\App\Support\Shortcodes::flush();

echo 'seeded '.UgcVideo::count()." clips (1 cut, 4 uncut, 1 whose file is gone), section shop-the-look\n";
