<?php
/*
 * Seed the Lane IGR preview with the shop as it stood BEFORE the old Instagram
 * API module was retired, then replay this lane's migrations over it, so the
 * "after" preview is exactly what applying the package does to such a shop.
 *
 *   - the demo catalogue (rf-seed) and an owner account;
 *   - six pasted Instagram embeds (Content → Instagram embeds), as Lane IGE seeds;
 *   - three manual #KBeautyBliss Spotted posts ticked "Spotted page";
 *   - four synced Instagram posts ticked for the Spotted page (Lane SG's feed);
 *   - the API module switched on with a FAKE token, app secret and user id, and
 *     the homepage `instagram` row switched on — the state the data migrations
 *     exist for.
 *
 * Preview database only; nothing here reaches a package. On a tree that does
 * not carry this lane's migrations the replay finds none and runs nothing.
 */
require __DIR__.'/rf-seed.php';

use App\Models\Setting;

\App\Models\AdminUser::updateOrCreate(['email' => 'owner@preview.test'], ['name' => 'Preview Owner', 'password' => 'preview-secret-1', 'role' => 'owner']);

$root = getenv('KBB_PUBLIC_PATH') ?: public_path();
@mkdir($root.'/uploads/spotted', 0775, true);
@mkdir($root.'/uploads/instagram', 0775, true);

$pic = function (string $file, string $hex) use ($root): string {
    [$r, $g, $b] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    $im = imagecreatetruecolor(640, 800);
    imagefilledrectangle($im, 0, 0, 640, 800, imagecolorallocate($im, $r, $g, $b));
    ob_start();
    imagepng($im);
    file_put_contents($root.'/'.$file, (string) ob_get_clean());

    return '/'.$file;
};

// Lane IGE: six pasted addresses, two of them labelled.
$parsed = \App\Services\InstagramEmbeds::parseMany(implode("\n", [
    'https://www.instagram.com/p/DAbcPost001/',
    'https://www.instagram.com/reel/C9ReelOne01/',
    'https://www.instagram.com/p/DAbcPost002/',
    'https://www.instagram.com/p/DAbcPost003/',
    'https://www.instagram.com/reel/C9ReelTwo02/',
    'https://www.instagram.com/p/DAbcPost004/',
]));
$items = $parsed['items'];
$items[0]['l'] = 'Glass-skin routine';
$items[1]['l'] = 'Unboxing: sunscreen haul';
app(\App\Services\InstagramEmbeds::class)->save([], $items);

// Manual Spotted posts (the owner's uploads).
foreach ([['sara.glows', 'E7A1B8'], ['noor.skin', 'B9A3E3'], ['layla.kbeauty', 'F2C38B']] as $i => [$handle, $hex]) {
    \App\Models\SpottedPost::create([
        'image' => $pic("uploads/spotted/igr-$i.png", $hex), 'image_alt' => "Look by @$handle",
        'ig_url' => 'https://www.instagram.com/p/IgrManual0'.$i.'/', 'handle' => $handle,
        'caption' => 'My morning routine with the shop’s toner.', 'link_to' => 'instagram',
        'likes' => 120 + $i, 'sort' => $i, 'on_home' => true, 'on_page' => true,
    ]);
}

// Lane SG: synced Instagram posts, ticked for the Spotted page. Only on a tree
// that still has the table's spotted columns.
if (\Illuminate\Support\Facades\Schema::hasColumn('instagram_posts', 'spotted_sort')) {
    foreach (['DSynced0001', 'DSynced0002', 'DSynced0003', 'DSynced0004'] as $i => $code) {
        \Illuminate\Support\Facades\DB::table('instagram_posts')->insert([
            'remote_id' => '1789'.$i, 'media_type' => $i === 1 ? 'VIDEO' : 'IMAGE',
            'permalink' => 'https://www.instagram.com/'.($i === 1 ? 'reel' : 'p').'/'.$code.'/',
            'shortcode' => $code, 'caption' => 'Synced post '.$i.' from the API feed',
            'local_path' => $pic("uploads/instagram/igr-$i.png", ['D98BA5', 'A0B4E6', 'E6C9A0', '9FD3C1'][$i]),
            'like_count' => 300 + $i, 'comments_count' => 12, 'share_count' => 3, 'view_count' => 900,
            'posted_at' => now()->subDays($i), 'spotted_sort' => $i + 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

// The API module, "connected", with fake credentials (never real values).
foreach ([
    'instagram_app_id' => '1234567890', 'instagram_app_secret' => \Illuminate\Support\Facades\Crypt::encryptString('fake-app-secret'),
    'instagram_token' => \Illuminate\Support\Facades\Crypt::encryptString('fake-long-lived-token'), 'instagram_token_expires' => (string) (time() + 40 * 86400),
    'instagram_user_id' => '17841400000000000', 'instagram_via' => 'facebook',
    'instagram_fb_app_id' => '9876543210', 'instagram_fb_app_secret' => \Illuminate\Support\Facades\Crypt::encryptString('fake-fb-secret'),
    'instagram_fb_page_id' => '1000000001', 'instagram_fb_page_name' => 'KBeauty Bliss',
] as $key => $value) {
    Setting::query()->updateOrCreate(['key' => $key], ['value' => $value, 'autoload' => false]);
}
\App\Models\ModuleToggle::query()->updateOrCreate(['module' => 'instagram_profile'], ['enabled' => true]);

// The homepage `instagram` row ON on both devices, as an owner who connected it would have it.
$sections = app(\App\Services\SettingsService::class)->get('homepage_sections');
$sections = is_array($sections) ? $sections : [];
$sections['instagram'] = array_merge($sections['instagram'] ?? [], ['desktop' => true, 'mobile' => true]);
app(\App\Services\SettingsService::class)->set('homepage_sections', $sections);

// A page carrying the old shortcode.
$page = \App\Models\Page::query()->where('slug', 'delivery')->first();
if ($page) {
    $page->content = (string) $page->content.'[kbb_instagram layout="rail" limit="6"]';
    $page->save();
}

Setting::flushMap();
\Illuminate\Support\Facades\Cache::flush();

// Replay this lane's migrations over the seeded state (after-preview only).
foreach (glob(base_path('database/migrations/2027_10_15_14*.php')) as $file) {
    $m = require $file;
    $m->up();
    echo 'replayed '.basename($file)."\n";
}

echo 'igr seed: '.count($items)." embeds\n";
