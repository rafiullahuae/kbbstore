<?php
/*
 * Lane SG (IG2) preview seed: the shared catalogue, the preview owner, and a
 * realistic synced Instagram account — 30 posts of @kbeauty.bliss (photos,
 * reels with 9:16 covers, carousels; long and short captions; counts in the
 * thousands; a few with no share figure, as Meta returns for some), 14 of them
 * ticked for /kbeautybliss-spotted/, plus three MANUAL posts so the "before"
 * shot shows today's page. Pictures are drawn with GD into the preview's web
 * root. Written into the PREVIEW only; nothing here reaches a package.
 *
 * Env: IG2_CARD=a|b|c|d (card style), IG2_SELECT=0 (tick nothing).
 */
use App\Models\InstagramPost;
use App\Models\SpottedPost;
use App\Services\Instagram\IgPath;
use App\Services\InstagramSettings;
use App\Services\SettingsService;
use App\Services\SpottedSettings;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/rf-seed.php';
\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], ['name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner']);

function ig2Pic(string $abs, int $w, int $h, array $c1, array $c2, string $kind, int $seed): void
{
    @mkdir(dirname($abs), 0775, true);
    $im = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y++) {
        $t = $y / $h;
        $col = imagecolorallocate($im, (int) ($c1[0] + ($c2[0] - $c1[0]) * $t), (int) ($c1[1] + ($c2[1] - $c1[1]) * $t), (int) ($c1[2] + ($c2[2] - $c1[2]) * $t));
        imageline($im, 0, $y, $w, $y, $col);
    }
    mt_srand($seed);
    // soft bokeh
    for ($i = 0; $i < 9; $i++) {
        $a = imagecolorallocatealpha($im, 255, 255, 255, 100 + mt_rand(0, 20));
        $r = mt_rand((int) ($w * .08), (int) ($w * .22));
        imagefilledellipse($im, mt_rand(0, $w), mt_rand(0, $h), $r, $r, $a);
    }
    // a product: bottle / jar / tube
    $cx = (int) ($w * (0.38 + mt_rand(0, 24) / 100));
    $base = (int) ($h * 0.78);
    $body = imagecolorallocate($im, 255, 255, 255);
    $cap = imagecolorallocate($im, max(0, $c2[0] - 70), max(0, $c2[1] - 70), max(0, $c2[2] - 60));
    $label = imagecolorallocate($im, $c2[0], $c2[1], $c2[2]);
    $shadow = imagecolorallocatealpha($im, 40, 10, 30, 95);
    imagefilledellipse($im, $cx, $base + 8, (int) ($w * .34), (int) ($w * .06), $shadow);
    if ($seed % 3 === 0) { // jar
        $bw = (int) ($w * .3); $bh = (int) ($w * .2);
        imagefilledrectangle($im, $cx - $bw / 2, $base - $bh, $cx + $bw / 2, $base, $body);
        imagefilledrectangle($im, $cx - $bw / 2 - 6, $base - $bh - (int) ($w * .07), $cx + $bw / 2 + 6, $base - $bh, $cap);
        imagefilledrectangle($im, $cx - $bw / 2 + 20, $base - $bh + 25, $cx + $bw / 2 - 20, $base - 25, $label);
    } elseif ($seed % 3 === 1) { // bottle
        $bw = (int) ($w * .18); $bh = (int) ($h * .34);
        imagefilledrectangle($im, $cx - $bw / 2, $base - $bh, $cx + $bw / 2, $base, $body);
        imagefilledrectangle($im, $cx - $bw / 5, $base - $bh - (int) ($h * .09), $cx + $bw / 5, $base - $bh, $cap);
        imagefilledrectangle($im, $cx - $bw / 2 + 14, $base - (int) ($bh * .7), $cx + $bw / 2 - 14, $base - (int) ($bh * .3), $label);
    } else { // tube
        $bw = (int) ($w * .14); $bh = (int) ($h * .4);
        imagefilledpolygon($im, [$cx - $bw / 2, $base - $bh, $cx + $bw / 2, $base - $bh, $cx + $bw / 2 - 10, $base, $cx - $bw / 2 + 10, $base], $body);
        imagefilledrectangle($im, $cx - $bw / 2 + 4, $base, $cx + $bw / 2 - 4, $base + (int) ($h * .05), $cap);
        imagefilledrectangle($im, $cx - $bw / 2 + 12, $base - (int) ($bh * .75), $cx + $bw / 2 - 12, $base - (int) ($bh * .35), $label);
    }
    imagejpeg($im, $abs, 84);
    imagedestroy($im);
}

$palettes = [
    [[255, 226, 234], [233, 143, 172]], [[253, 239, 226], [242, 176, 140]], [[232, 244, 236], [140, 196, 165]],
    [[236, 232, 252], [168, 150, 226]], [[255, 247, 222], [236, 196, 110]], [[226, 240, 252], [128, 172, 222]],
];
$captions = [
    'Glass skin in 3 steps ✨ Double cleanse, essence, and our best-selling Torriden Dive-In serum. Which step do you never skip? #kbeautybliss #glassskin',
    'New in: Beauty of Joseon Relief Sun 🌞',
    'POV: your skin after 2 weeks of COSRX Snail Mucin. Swipe for the before and after — no filter, just hydration. Shop the routine at the link in bio 💕',
    'Restock alert! The Anua Heartleaf toner is back.',
    'Morning routine for oily, acne-prone skin in the UAE heat ☀️ Cleanser → toner pads → light gel cream → SPF50. Save this for later! #skincareroutine #dubai',
    'Unboxing our July bestsellers 🎀',
    'Centella vs. Heartleaf — which calming toner is right for you? We break down the ingredients, textures and who each one is for.',
    'Weekend reset 🧖‍♀️ Sheet mask Sunday with Mediheal.',
    'Your questions answered: can you use retinol and vitamin C together? Watch till the end for our dermatologist-approved routine 👀',
    'Lip sleeping mask season 💋',
    'Customer favourite: the Round Lab Birch Juice moisturiser. 4.9★ from 300+ reviews across the UAE. Light, bouncy, and perfect under makeup.',
    'Free same-day delivery in Dubai on orders over AED 150 🚚',
    'How to layer serums — thinnest to thickest. Tag a friend who needs this!',
    'Behind the scenes at our Dubai warehouse 📦',
    'K-beauty for beginners: the only 4 products you need to start. Simple, gentle and effective — swipe through ➡️',
];
$selected = (getenv('IG2_SELECT') ?: '1') !== '0';
$dir = IgPath::directory();
@mkdir($dir, 0775, true);
$now = now();
$hasNew = Schema::hasColumn('instagram_posts', 'spotted_sort');
for ($i = 0; $i < 30; $i++) {
    $type = $i % 3 === 1 ? 'VIDEO' : ($i % 5 === 3 ? 'CAROUSEL_ALBUM' : 'IMAGE');
    [$w, $h] = $type === 'VIDEO' ? [720, 1280] : ($i % 4 === 0 ? [1080, 1080] : [1080, 1350]);
    $p = $palettes[$i % count($palettes)];
    $rid = '1789'.str_pad((string) (450000 + $i), 11, '0', STR_PAD_LEFT);
    $name = IgPath::fileName($rid, 'jpg');
    ig2Pic($dir.'/'.$name, $w, $h, $p[0], $p[1], $type, $i);
    $code = 'C'.strtoupper(substr(sha1((string) $i), 0, 9)).'x';
    $row = [
        'media_type' => $type,
        'permalink' => 'https://www.instagram.com/'.($type === 'VIDEO' ? 'reel' : 'p').'/'.$code.'/',
        'shortcode' => $code,
        'caption' => $captions[$i % count($captions)],
        'local_path' => '/'.IgPath::ROOT.$name,
        'like_count' => [1243, 87, 15620, 432, 3890, 129, 2210, 64, 48700, 980, 5310, 211, 760, 3402, 18][$i % 15] + $i,
        'comments_count' => [48, 3, 612, 19, 145, 7, 88, 2, 1320, 41, 230, 9, 33, 97, 1][$i % 15],
        'posted_at' => $now->copy()->subDays($i * 4 + 1),
        'seen_at' => $now,
    ];
    if ($hasNew) {
        $row['share_count'] = $i % 6 === 5 ? null : [210, 4, 3870, 12, 640, 1, 95, 0, 12400, 33, 512, 6, 28, 201, 2][$i % 15];
        $row['view_count'] = $type === 'VIDEO' ? [24100, 1203400, 8920, 45600, 3100][$i % 5] : null;
        $row['insights_at'] = $now;
        $row['spotted_sort'] = $selected && $i < 14 ? $i + 1 : null;
    }
    InstagramPost::query()->updateOrCreate(['remote_id' => $rid], $row);
}
ig2Pic($dir.'/avatar-kbb.jpg', 160, 160, [255, 214, 226], [198, 57, 95], 'IMAGE', 2);
app(InstagramSettings::class)->saveProfile([
    'username' => 'kbeauty.bliss', 'name' => 'K-Beauty Bliss', 'avatar' => '/'.IgPath::ROOT.'avatar-kbb.jpg',
    'followers' => 18400, 'posts' => 30, 'account_type' => 'BUSINESS', 'fetched_at' => time() - 3600,
]);

// Three manual posts, the way the page is filled today.
foreach ([1, 2, 3] as $n) {
    $rel = 'uploads/appearance/ig2-manual-'.$n.'.jpg';
    ig2Pic(public_path($rel), 800, 1000, $palettes[$n][0], $palettes[$n][1], 'IMAGE', $n + 10);
    SpottedPost::query()->updateOrCreate(['ig_url' => 'https://www.instagram.com/p/Manual'.$n.'/'], [
        'image' => '/'.$rel, 'handle' => ['sara.glows', 'noura.skin', 'kbeauty.bliss'][$n - 1],
        'caption' => ['Torriden serum', 'My night routine', 'Restock day'][$n - 1], 'on_home' => true, 'on_page' => true, 'sort' => $n, 'link_to' => 'instagram',
    ]);
}
$card = getenv('IG2_CARD') ?: 'a';
if (isset(SpottedSettings::SCHEMA['page_card'])) {
    app(SpottedSettings::class)->save(['page_card' => $card]);
}
SpottedSettings::flush();
if (class_exists(\App\Services\SpottedInstagram::class)) {
    \App\Services\SpottedInstagram::flush();
}
echo "ig2 seed done (card {$card})\n";
// The Arabic shop is a setting; on, so the Arabic shot has a page to take.
app(SettingsService::class)->set(\App\Support\Locale::SETTING_ENABLED, '1');
echo "arabic on\n";
app(SettingsService::class)->set(\App\Support\Locale::SETTING_RTL, '1');
