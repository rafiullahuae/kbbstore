<?php

/*
 * Lane RK — render today's order confirmation from the preview shop, after the
 * contact details were saved on Emails → Design & branding by tools/rk-shots.cjs.
 * Run inside the preview environment (tools/rk-preview.sh writes env.sh):
 *
 *   . storage/framework/testing/lane-rk-preview/env.sh
 *   php artisan tinker --execute="require 'tools/rk-render-footer.php';"
 *
 * Writes docs/rk-emails/footer-after.html. The "before" is the same order
 * rendered by the code before this package: docs/email-previews/order-
 * confirmation.html at commit 2b11208, copied to footer-before.html.
 */

use App\Mail\OrderConfirmation;
use App\Models\Order;

$order = Order::query()->where('order_number', 'KBB-10427')->firstOrFail()->fresh('items');
$html = (string) (new OrderConfirmation($order))->render();

$out = base_path('docs/rk-emails');
@mkdir($out, 0775, true);
file_put_contents($out . '/footer-after.html', "<!doctype html><meta charset=\"utf-8\"><body style=\"margin:0\">" . $html);

echo 'footer-after.html ' . strlen($html) . " bytes\n";
