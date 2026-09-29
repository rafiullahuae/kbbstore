<?php
require __DIR__.'/../../vendor/autoload.php';
$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\Translation;
echo "rows ar: ".Translation::query()->where('locale','ar')->count()."\n";
$r = Translation::query()->where('locale','ar')->where('field','like','%banner_slider_prev%')->first();
var_dump($r?->only(['group','item_id','field','value','status']));
echo "GROUP_UI=".Translation::GROUP_UI."\n";
echo "uiMap count: ".count(\App\Services\Translation\TranslationStore::uiMap('ar'))."\n";
