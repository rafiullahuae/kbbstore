<?php
/* 2.60.659 preview seed: the Free gifts slide, English + Arabic. Preview database only.
 *   GO_LANE=lane-fgar GO_SEED=../docs/plans/free-gifts-ad/ar-seed.php sh tools/go-preview.sh 9600 */
use App\Models\BannerCard;
use App\Models\BannerSet;
use App\Services\Banners;
use App\Services\SettingsService;
use App\Support\Locale;

require __DIR__ . '/../../../tools/go-seed.php';
$s = app(SettingsService::class);
$s->set(Locale::SETTING_ENABLED, '1');
$s->set(Locale::SETTING_RTL, '1');
$set = BannerSet::create(['name' => 'Home', 'slug' => 'home-fgar', 'status' => 'publish', 'position' => 0, 'kind' => 'slider']);
$s->setModuleSetting(Banners::MODULE, 'set', (string) $set->id);
(require database_path('migrations/2028_01_17_100000_free_gifts_banner_first_slide.php'))->up();
(require database_path('migrations/2028_01_20_100100_free_gifts_banner_arabic.php'))->up();
echo "fgar: ", BannerCard::query()->where('banner_set_id', $set->id)->value('image_ar'), "\n";
