<?php
/*
 * Lane GBX2 preview helper, run through tools/go-tinker.sh against the preview
 * database only. GBX_DO selects:
 *   design  GBX_V = A..E (or "off": the switch off)  -- Gift offers -> Settings
 *   left    GBX_V = seconds left on every claim (a 20-minute hold; negative = expired)
 */
use App\Services\GiftOffers\GiftOffers;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

$do = getenv('GBX_DO') ?: '';
$v = (string) getenv('GBX_V');

if ($do === 'design') {
    $s = app(SettingsService::class);
    $c = GiftOffers::normalise($s->get(GiftOffers::KEY));
    $c['box'] = $v === 'off' ? ['merge' => false, 'design' => $c['box']['design']] : ['merge' => true, 'design' => $v];
    $s->set(GiftOffers::KEY, $c);
    echo 'BOX=' . json_encode(GiftOffers::normalise($s->get(GiftOffers::KEY))['box']) . "\n";
}

if ($do === 'ar') {
    $s = app(SettingsService::class);
    $s->set('language_ar_enabled', '1');
    $s->set(\App\Support\Locale::SETTING_RTL, '1');
    echo "AR=on\n";
}

if ($do === 'left') {
    $n = (int) $v;
    DB::table('gift_offer_claims')->update(['expires_at' => now()->addSeconds($n), 'created_at' => now()->addSeconds($n - 1200)]);
    echo "LEFT=$n\n";
}
