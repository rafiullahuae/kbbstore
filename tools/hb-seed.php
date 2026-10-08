<?php
/* Seed the Lane HB preview: an owner, the Arabic shop on (mirrored), and one
   published slider set of three pictures carrying words in English and Arabic.
   Run inside `artisan tinker` by tools/hb-preview.sh. Works against the BASE
   tree too (HB_APP): a column that does not exist there is simply not written. */

use App\Models\BannerCard;
use App\Models\BannerSet;
use App\Models\Setting;
use App\Support\Locale;
use Illuminate\Support\Facades\Schema;

\App\Models\AdminUser::create([
    'name' => 'Preview Owner', 'email' => 'owner@preview.test',
    'password' => 'preview-secret-1', 'role' => 'owner',
]);

$settings = app(\App\Services\SettingsService::class);
$settings->set('site_url', getenv('HB_SITE_URL') ?: 'http://127.0.0.1:10460');

foreach ([Locale::SETTING_ENABLED, Locale::SETTING_RTL] as $key) {
    Setting::query()->updateOrCreate(['key' => $key], ['value' => '1', 'autoload' => true]);
}

$set = BannerSet::create([
    'name' => 'Homepage slider', 'slug' => 'homepage-slider', 'status' => 'publish', 'position' => 0,
    'kind' => 'slider', 'show_arrows' => true, 'show_dots' => true, 'autoplay' => false,
    // Lane HB3: the owner's phone height (HB_PHONE_H, e.g. 600). 0 is Auto.
    'slider_h_m' => (int) (getenv('HB_PHONE_H') ?: 0),
]);

$words = [
    ['blush', 'New in · Glass skin', 'Glass skin starts here', 'Toners, essences & serums from Seoul’s cult labels.', 'Shop the Glow Edit', 'NEW', 'JUST LANDED',
        'جديد · بشرة زجاجية', 'بشرة زجاجية تبدأ من هنا', 'تونر وإسنس وسيروم من أشهر علامات سيول.', 'تسوّقي مجموعة التوهّج', 'جديد', 'وصل حديثاً'],
    ['lilac', 'Lip tints · cushions · blush', 'Hello, *soft glow*', 'Pastel-pretty colour from Seoul just landed.', 'Shop new arrivals', 'NEW', 'JUST LANDED',
        'أحمر شفاه · كوشن · بلاشر', 'مرحباً، *توهّج ناعم*', 'ألوان ناعمة من سيول وصلت للتو.', 'تسوّقي الجديد', 'جديد', 'وصل حديثاً'],
    ['peach', 'SPF season', 'Sun care that feels like nothing', 'Weightless Korean sunscreens for the UAE sun.', 'Shop sunscreens', 'SPF', 'EVERY DAY',
        'موسم الحماية', 'حماية من الشمس لا تشعرين بها', 'واقيات شمس كورية خفيفة لشمس الإمارات.', 'تسوّقي واقيات الشمس', 'SPF', 'كل يوم'],
];

$hasBox = Schema::hasColumn('banner_cards', 'box_on');

foreach ($words as $i => [$pic, $eb, $h, $t, $btn, $stk, $ring, $ebAr, $hAr, $tAr, $btnAr, $stkAr, $ringAr]) {
    $d = 'uploads/banners/hb-'.$pic.'-d.jpg';
    $m = 'uploads/banners/hb-'.$pic.'-m.jpg';
    \App\Support\MediaRegistrar::record($d);
    \App\Support\MediaRegistrar::record($m);

    $row = [
        'banner_set_id' => $set->id, 'image' => $d,
        'image_w' => 1920, 'image_h' => 550,
        'alt' => str_replace('*', '', $h), 'heading' => $h, 'body' => $t, 'button_label' => $btn,
        'button_url' => '/shop/', 'position' => $i + 1, 'status' => 'publish',
    ];

    // Lane HB3: HB_NO_PHONE=1 seeds pictures WITHOUT phone pictures (his case).
    if (! getenv('HB_NO_PHONE')) {
        $row += ['image_m' => $m, 'image_m_w' => 500, 'image_m_h' => 600];
    }

    if ($hasBox) {
        $row += ['box_on' => true, 'eyebrow' => $eb, 'sticker' => $stk, 'sticker_ring' => $ring,
            'eyebrow_ar' => $ebAr, 'heading_ar' => $hAr, 'body_ar' => $tAr, 'button_label_ar' => $btnAr,
            'sticker_ar' => $stkAr, 'sticker_ring_ar' => $ringAr];
    }

    BannerCard::create($row);
}

$settings->setModule('cards_banner', true);
$settings->setModuleSetting(\App\Services\Banners::MODULE, 'set', (string) $set->id);

echo "hb seed: set {$set->id}, 3 pictures, words ".($hasBox ? 'on' : 'n/a (base tree)')."\n";
