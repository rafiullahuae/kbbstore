<?php

declare(strict_types=1);

/*
 * Lane TS (type sizes preview): tools/fg5-seed.php's shop -- realistic
 * products, UAE delivery, free gifts in TESTING for 127.0.0.1 -- plus the four
 * payment methods the live till offers (card, Tabby, Tamara, cash), each with
 * a placeholder credential so the checkout draws the same list the owner sees.
 * The credentials are dummies: nothing here can reach a gateway.
 *   FG_SEED=tsz-seed.php sh tools/fg-preview.sh 11240 -- never against a real database.
 */

require __DIR__.'/fg5-seed.php';

$creds = [
    'stripe' => ['secret_key_test' => 'sk_test_preview', 'publishable_key_test' => 'pk_test_preview'],
    'tabby'  => ['public_key' => 'pk_preview', 'secret_key' => 'sk_preview'],
    'tamara' => ['api_token' => 'preview', 'notification_token' => 'preview'],
];
foreach ($creds as $id => $config) {
    $row = \App\Models\PaymentProvider::find($id);
    if ($row) {
        $row->enabled = true;
        $row->mode = 'test';
        $row->config = $config;
        $row->save();
    }
}
/*
 * THE LIVE CART PANEL. kbeautybliss.com prints the panel's settings on every
 * page as the drawer's own style/class attributes, so they can be read from
 * the public homepage (22 October 2026) without a basket:
 *   class="drawer cp-noship" style="--cp-w:380px;--cp-w-m:68vw;--cp-pad:12px;
 *   --cp-pad-m:11px;--cp-rowpad:5px;--cp-rowpad-m:6px;--cp-thumb:42px;
 *   --cp-thumb-m:56px;--cp-name:11px;--cp-name-m:11px;--cp-lines:2;...;
 *   --cp-price:1;--cp-price-m:0.95;--cp-step:20px;--cp-step-m:24px;--cp-rm:13px;
 *   --cp-rm-m:16px;--cp-rmbox-m:32px;--cp-x:28px;--cp-x-m:37px;--cp-xg:13px;
 *   --cp-xg-m:13px;--cp-tabh-m:44px;--cp-btngap:8px;--cp-btngap-m:6px;
 *   --cp-btnpad:12px;--cp-btnpad-m:10px;--cp-btnh-m:42px;--cp-btnr:99px;
 *   --cp-btnr-m:26px;--cp-btnf:1;--cp-btnf-m:1;--cp-accent:#C13E63;...;
 *   --cp-footgap-m:50px"
 * and the brand accent is #e35f82. The cart page and the checkout answer this
 * connection with 403 ("We can't take orders from this connection"), so their
 * own settings could not be read and stay at this repository's defaults.
 */
app(\App\Services\CartPanel::class)->save([
    'panel_width' => 380, 'panel_width_m' => 68, 'list_pad' => 12, 'list_pad_m' => 11,
    'row_pad' => 5, 'row_pad_m' => 6, 'thumb_size' => 42, 'thumb_size_m' => 56,
    'name_size' => 11, 'name_size_m' => 11, 'name_lines' => 2, 'name_lines_m' => 2,
    'price_size' => 100, 'price_size_m' => 95, 'stepper_size' => 20, 'stepper_size_m' => 24,
    'rm_size' => 13, 'rm_size_m' => 16, 'rm_tap_m' => 32, 'x_size' => 28, 'x_size_m' => 37,
    'x_glyph' => 13, 'x_glyph_m' => 13, 'tab_h_m' => 44, 'btn_gap' => 8, 'btn_gap_m' => 6,
    'btn_pad' => 12, 'btn_pad_m' => 10, 'btn_h_m' => 42, 'btn_radius' => 99, 'btn_radius_m' => 26,
    'btn_size' => 100, 'btn_size_m' => 100, 'accent' => '#C13E63', 'checkout_bg' => '#C13E63',
    'checkout_fg' => '#FFFFFF', 'foot_gap_m' => 50, 'show_ship_bar' => false,
]);
app(\App\Services\SettingsService::class)->set('brand_accent', '#e35f82');
\Illuminate\Support\Facades\Cache::flush();
echo 'tsz: panel '.app(\App\Services\CartPanel::class)->cssVariables()."\n";
echo 'tsz: payments '.json_encode(\App\Models\PaymentProvider::query()->where('enabled', true)->pluck('id'))."\n";
