<?php
/* Renders the exit popup (design A, words 3) in English and Arabic into HTML files beside this script,
   from the GO preview database (GO_LANE=... sh tools/go-tinker.sh docs/lane-gow-shots/render.php). */
use App\Services\GiftOffers\GiftOffers;
use App\Services\SettingsService;

$s = app(SettingsService::class);
$c = GiftOffers::normalise($s->get(GiftOffers::KEY));
$c['places']['popup'] = true;
$c['popup']['design'] = 'A';
$c['popup']['style'] = 'S3';
$c['popup']['words'] = '3';
$s->set(GiftOffers::KEY, $c);
foreach (['en', 'ar'] as $lang) {
    app()->setLocale($lang);
    $go = app(GiftOffers::class);
    $go->freshConfig();
    $offer = $go->offerFor(40400);
    $p = $go->popupView($offer);
    $html = '<!doctype html><html lang="' . $lang . '"' . ($lang === 'ar' ? ' dir="rtl"' : '') . '><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;font-family:Outfit,system-ui,sans-serif;background:#888}</style>'
        . view('partials.gift-offers.popup-css')->render() . '</head><body>' . view('partials.gift-offers.popup-card', ['p' => $p])->render() . '</body></html>';
    file_put_contents(__DIR__ . "/popup-$lang.html", $html);
    echo "$lang ok\n";
}
