<?php
/* Set one screenshot scenario on the Lane HB preview, from the environment:
     HB_TB    JSON merged over the set's text box (style, glow, button, show_*, size_*)
     HB_BOX   0 switches every picture's words off (the shop as it ships), 1 on
   Run as: . <preview>/env.sh && php artisan tinker --execute="require 'tools/hb-set.php';" */

use App\Models\BannerCard;
use App\Models\BannerSet;
use App\Support\BannerTextBox;

$set = BannerSet::query()->where('slug', 'homepage-slider')->firstOrFail();
$tb = json_decode((string) getenv('HB_TB'), true) ?: [];
$set->text_box = json_encode(BannerTextBox::normalize($tb));
$set->save();

if (getenv('HB_BOX') !== false && getenv('HB_BOX') !== '') {
    BannerCard::query()->where('banner_set_id', $set->id)->update(['box_on' => (bool) getenv('HB_BOX')]);
}

\Illuminate\Support\Facades\Cache::flush();
echo "hb set: ".$set->text_box."\n";
