<?php
/* Lane UG2 — the owner's rail, seeded to the shape his screenshot shows.
 *
 * ── WHY THIS IS NOT tools/ug-seed.php ────────────────────────────────────────
 *
 * Lane UG's seed is six clips that all carry their own file, in a tidy row. It
 * loops beautifully and it measured nothing the owner is looking at: he was
 * told the rail was fine on the strength of that preview and his rail still
 * does not play. THE FAILURE IS IN THE MIX. His rail is
 *
 *   tiles 1-4  DemoContentController::seedVideos() rows — the `(Demo)` clips,
 *              all four pointing at the SAME `demo-clip.webm`, which is a
 *              270x480 plum gradient with a slow sheen (UgcDemoMedia's own
 *              docblock). In a screenshot they are flat purple rectangles.
 *   tiles 5-6  his real uploads, with real cover frames.
 *
 * and the demo rows come FIRST because they were imported first and the section
 * orders by position. So the bytes here are the OWNER'S OWN BYTES for 1-4 —
 * UgcDemoMedia::materialise(), the same call the Demo Content screen makes —
 * rather than a lane's approximation of a placeholder.
 *
 * VP9/WebM for the two real clips (tools/ug2-media.sh): Playwright's Chromium
 * has no H.264, so an mp4 would report a media error the owner does not have.
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

/* Arabic on, so the RTL evidence is the REAL mirrored storefront at /ar/ and not
   a `dir` attribute flipped by the instrument. The player's arrows are placed
   with inset-inline-* and their glyphs are logical-border triangles, and the
   thing worth a screenshot is a browser resolving those on a page that is
   genuinely right-to-left. */
\App\Models\Setting::query()->updateOrCreate(
    ['key' => \App\Support\Locale::SETTING_ENABLED], ['value' => '1']
);
\App\Models\Setting::query()->updateOrCreate(
    ['key' => \App\Support\Locale::SETTING_RTL], ['value' => '1']
);
\App\Services\SettingsService::forgetMemo();

$brand = Brand::updateOrCreate(['slug' => 'ug2-boj'], ['name' => 'Beauty of Joseon']);
$brand2 = Brand::updateOrCreate(['slug' => 'ug2-anua'], ['name' => 'Anua']);

$mk = function (string $slug, string $name, int $price, ?int $sale, $b, float $rating, int $reviews) {
    return Product::updateOrCreate(['slug' => $slug], [
        'name' => $name, 'price' => $price, 'sale_price' => $sale, 'brand_id' => $b->id,
        'image' => '/uploads/ugc/real-a.jpg', 'status' => 'publish', 'is_visible' => true,
        'stock_status' => 'instock', 'type' => 'simple', 'rating' => $rating, 'review_count' => $reviews,
    ]);
};

$p = [
    $mk('ug2-relief-sun', 'Relief Sun Rice + Probiotics SPF50+', 21000, 14700, $brand, 4.6, 412),
    $mk('ug2-snail', 'Advanced Snail 96 Mucin Power Essence', 8000, null, $brand2, 4.8, 1290),
    $mk('ug2-toner', 'Heartleaf 77% Soothing Toner', 10100, 7000, $brand2, 4.5, 301),
    $mk('ug2-device', 'Age-R Booster Pro Device', 20800, null, $brand, 4.3, 44),
    $mk('ug2-mist', 'Vitamin C Brightening Serum', 15200, 10600, $brand2, 4.4, 77),
    $mk('ug2-fresh', 'Fresh That Lasts Deodorant', 20800, null, $brand, 4.1, 12),
];

/* ── 1-4: the demo rows, his bytes, in his order ─────────────────────────── */
$demo = \App\Support\UgcDemoMedia::materialise();
if ($demo === null) { throw new RuntimeException('could not write uploads/ugc'); }

$rows = [];

foreach ([
    ['glass-skin-in-6-steps-demo', 'Glass skin in 6 steps (Demo)', '@layla.skin', 'The order that actually matters, and the two steps you can skip.'],
    ['salon-day-bonding-mask-demo', 'Salon day: bonding mask (Demo)', '@jumeirah.glow', 'Fifteen minutes, once a week. This is the one I keep rebuying.'],
    ['spf-that-never-stings-demo', 'SPF that never stings (Demo)', '@noor.routine', 'Reapplying over makeup without pilling — the trick is the pat, not the rub.'],
    ['double-cleanse-no-drama-demo', 'Double cleanse, no drama (Demo)', '@amira.beauty', 'Oil first, foam second. If it squeaks, it was too much.'],
] as $i => [$slug, $title, $handle, $caption]) {
    $rows[] = [$slug, $title, $handle, $caption, $demo['clip'], $demo['clip'], $demo['poster'], 270, 480, 2700, [$p[$i]->id]];
}

/* ── 5-6: the two clips he actually uploaded ─────────────────────────────── */
$rows[] = ['ug2-anua-mist', 'anua mist spray mini', '@extrabeauty', 'Korean skincare is next level',
    '/uploads/ugc/real-a.webm', null, '/uploads/ugc/real-a.jpg', 270, 480, 6000, [$p[4]->id]];
$rows[] = ['ug2-medicube', 'bright underarms era with Medicube', '@extrabeauty', 'Fresh That Lasts',
    '/uploads/ugc/real-b.webm', null, '/uploads/ugc/real-b.jpg', 270, 480, 6000, [$p[5]->id]];

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

/* ── THE SEVENTH CLIP: A ROW WHOSE FILE IS GONE ──────────────────────────────
 *
 * The owner's own `ugc:cut-covers` run named two clips, #9 and #10, that could
 * not be cut; his listing of uploads/ugc shows six files and neither of those
 * names. The ROWS survived and the FILES did not, and every screen went on
 * describing them as healthy.
 *
 * DELIBERATELY NOT IN THE SECTION. The rail's before/after measurements were
 * taken against six tiles in the shape of his screenshot, and a seventh would
 * invalidate the comparison the whole autoplay finding rests on. What this row
 * is for is the CLIPS screen, which lists every clip whether or not a rail
 * carries it — and that screen badged a row like this one "Loops from full
 * video", about a clip with nothing left to loop.
 *
 * Its poster is a real file, because that is the shape that fooled the screen:
 * a cover that loads perfectly beside a video that is not there. */
UgcVideo::updateOrCreate(['slug' => 'ug2-lost-file'], [
    'title' => 'niacinamide routine, week 3', 'caption' => 'Fresh That Lasts',
    'status' => 'publish', 'rights_status' => 'granted', 'rights_granted_at' => now(),
    'file_path' => '/uploads/ugc/clip-20260921-7hk2mq9wxb.webm',   // never written
    'poster_path' => '/uploads/ugc/real-b.jpg',                     // really there
    'width' => 270, 'height' => 480, 'duration_ms' => 7000,
    'creator_handle' => '@extrabeauty', 'source_platform' => 'upload',
    'published_at' => now()->subDay(),
]);

Page::updateOrCreate(['slug' => 'about'], [
    // `published`, not `publish` — Store\PageController::show() filters on the
    // former and a page seeded with the latter 404s.
    'status' => 'published',
    'title' => 'Shop the look',
    'content' => '<h1>Shop the look</h1>[kbb_videos section="shop-the-look"]',
]);

/* HIS RAIL IS THE HOMEPAGE ONE. The screenshot is the "Shop the look" block in
   store/home.blade.php — a full-bleed white card on the pink homepage — and not
   a rail dropped into a CMS page. THE DIFFERENCE IS THE ONLY REASON THIS ROUND
   EXISTS: a page body's content column is 695px wide here, so tiles 4-6 are
   clipped by the rail's own overflow and the IntersectionObserver refuses them
   at 0.6 before the cap is ever reached. On the homepage every tile is fully on
   screen and the CAP is what refuses tiles 5 and 6 — which is his picture. A
   lane that measures the page rail measures the wrong gate. */
$sv = app(\App\Services\SettingsService::class);
$sv->setModuleSetting('shoppable_video', 'home_section', 'shop-the-look');
$sv->flush();

app(\App\Services\UgcRail::class)->flush();
\App\Support\Shortcodes::flush();

echo "seeded ".UgcVideo::count()." clips (4 demo + 2 real + 1 whose file is gone), section shop-the-look, page /about\n";
