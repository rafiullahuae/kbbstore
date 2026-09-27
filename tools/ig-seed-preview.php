<?php
// Seeds the Instagram Profile preview: nine posts with REAL jpegs on disk, a
// profile blob, the module on, and an owner to log in as. Lane IG.
use App\Models\AdminUser;
use App\Models\InstagramPost;
use App\Services\Instagram\IgPath;
use App\Services\InstagramSettings;
use App\Services\SettingsService;

$dir = IgPath::directory();
@mkdir($dir, 0775, true);

// Nine tiles that look like a real K-beauty grid: a gradient per tile so the
// layouts are visually distinguishable from one another in a screenshot.
$captions = [
  'Glass skin in three steps — the toner pad everyone asks about 🫧',
  'Rice water cleanser, day 14. The texture on my cheeks is gone.',
  'Restocked: the snail mucin everyone waited for',
  'Our founder on why we only stock what we use ourselves',
  'Sunscreen that does not pill under makeup. Finally.',
  'Before / after, 6 weeks, no filter',
  'The 5-minute morning routine we actually do',
  'Unboxing the September set 📦',
  'Ampoule vs serum — what is the difference, really?',
];
$types = ['IMAGE','VIDEO','IMAGE','VIDEO','IMAGE','CAROUSEL_ALBUM','IMAGE','VIDEO','CAROUSEL_ALBUM'];
$likes = [14238, 2841, 9120, 512, 0, 33104, 671, 4890, null];
$comments = [312, 88, 240, 19, 0, 1204, 26, 143, null];

InstagramPost::query()->delete();

foreach ($captions as $i => $caption) {
    $remote = 'demo-'.($i + 1);
    $name = IgPath::fileName($remote, 'jpg');

    $img = imagecreatetruecolor(640, 640);
    // A soft two-tone wash per tile, so nine tiles are nine distinguishable
    // pictures rather than nine identical grey squares.
    for ($y = 0; $y < 640; $y++) {
        $t = $y / 640;
        $r = (int) (238 - $t * (60 + $i * 9) % 90);
        $g = (int) (226 - $t * (30 + $i * 13) % 70);
        $b = (int) (232 - $t * (20 + $i * 7) % 60);
        imagefilledrectangle($img, 0, $y, 640, $y + 1, imagecolorallocate($img, $r, $g, $b));
    }
    $ink = imagecolorallocate($img, 120, 96, 110);
    imagefilledellipse($img, 200 + $i * 24, 300, 260, 260, imagecolorallocate($img, 255, 255, 255));
    imagestring($img, 5, 40, 40, 'KBB '.($i + 1), $ink);
    imagejpeg($img, $dir.'/'.$name, 88);
    imagedestroy($img);

    InstagramPost::query()->create([
        'remote_id' => $remote,
        'media_type' => $types[$i],
        'permalink' => 'https://www.instagram.com/p/DEMO'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT).'/',
        'shortcode' => 'DEMO'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
        'caption' => $caption,
        'local_path' => '/'.IgPath::ROOT.$name,
        'width' => 640,
        'height' => 640,
        'like_count' => $likes[$i],
        'comments_count' => $comments[$i],
        'posted_at' => now()->subDays($i),
        'seen_at' => now(),
    ]);
}

// The avatar, same treatment.
$avatarName = IgPath::fileName('profile-demo', 'jpg');
$av = imagecreatetruecolor(320, 320);
imagefilledrectangle($av, 0, 0, 320, 320, imagecolorallocate($av, 245, 232, 236));
imagefilledellipse($av, 160, 160, 240, 240, imagecolorallocate($av, 21, 168, 90));
imagestring($av, 5, 130, 150, 'KBB', imagecolorallocate($av, 255, 255, 255));
imagejpeg($av, $dir.'/'.$avatarName, 90);
imagedestroy($av);

app(InstagramSettings::class)->saveProfile([
    'username' => 'kbeauty.bliss',
    'name' => 'K-Beauty Bliss',
    'avatar' => '/'.IgPath::ROOT.$avatarName,
    'followers' => 48219,
    'posts' => 1483,
    'account_type' => 'BUSINESS',
    'fetched_at' => time(),
]);

$settings = app(SettingsService::class);
$settings->setModule('instagram_profile', true);

AdminUser::query()->where('email', 'owner@preview.test')->delete();
AdminUser::create([
    'name' => 'Preview Owner',
    'email' => 'owner@preview.test',
    'password' => 'preview-secret-1',
    'role' => 'owner',
]);

SettingsService::forgetMemo();
\App\Models\Setting::flushMap();
\Illuminate\Support\Facades\Cache::flush();

echo "seeded 9 posts, avatar, module on, owner@preview.test\n";
