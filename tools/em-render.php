<?php

/*
 * Lane EM — render every real email through the real mailer, for the
 * side-by-side shots against the owner's approved previews.
 *
 *   . storage/em-logs/env.sh   (a throwaway SQLite file, APP_URL=https://extrabeauty.ae)
 *   php artisan migrate --force
 *   php artisan tinker --execute="require 'tools/em-render.php';"
 *
 * PREVIEW DATABASE ONLY. Seeds tools/rj-seed.php's fixture (the order the
 * previews were drawn from: KBB-10427, Anua ×2, Beauty of Joseon, COSRX,
 * GLOW10, Tabby, Dubai Marina), gives its three lines real products, and
 * SENDS each email through the shop's own `kbb` mailer on the array
 * transport. What is written to storage/em-logs/render/NN-name.html is the
 * HTML body of the message the transport received — the bytes a customer
 * would get — not a view rendered on the side.
 *
 * Set EM_ADDRESSES=1 to type placeholder Dubai/Korea addresses first (the
 * "how it looks once you fill them in" render); never shipped.
 */

use App\Mail;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Services\Mail\MailSettings;
use App\Services\Mail\OrderMailer;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

require base_path('tools/rj-seed.php');

$out = base_path('storage/em-logs/render' . (getenv('EM_ADDRESSES') ? '-addr' : ''));
@mkdir($out, 0777, true);

if (getenv('EM_ADDRESSES')) {
    app(MailSettings::class)->save([
        'mail_address_dubai' => '[Dubai address]',
        'mail_address_korea' => '[Korea address — owner to paste]',
    ]);
}

app(MailSettings::class)->save(['mail_support_email' => 'info@kbeautybliss.com']);
\App\Models\Setting::flushMap();
SettingsService::forgetMemo();

// The three products, tagged with their routine step (Catalog → Build my
// routine) for the delivered email's "How to use them together", with pictures at an https address the shot script
// serves from docs/rj-email-previews/assets (the previews' own stand-ins).
$make = function (string $brand, string $name, string $slug, string $pic, int $price, string $role): Product {
    $b = Brand::firstOrCreate(['slug' => \Illuminate\Support\Str::slug($brand)], ['name' => $brand]);

    return Product::updateOrCreate(['slug' => $slug], [
        'name' => $name, 'brand_id' => $b->id, 'status' => 'publish', 'is_visible' => 1,
        'price' => $price, 'stock_status' => 'instock', 'type' => 'simple',
        'image' => 'https://shots.test/' . $pic . '.jpg',
        'routine_role' => $role,
    ]);
};

$products = [
    'AN-HL-250' => $make('Anua', 'Heartleaf 77% Soothing Toner 250ml', 'anua-heartleaf-77-soothing-toner-250ml', 'p-anua', 8900, 'tone'),
    'BOJ-GD-30' => $make('Beauty of Joseon', 'Glow Deep Serum Rice + Alpha-Arbutin 30ml', 'boj-glow-deep-serum', 'p-boj', 11500, 'treat'),
    'CX-LPH-150' => $make('COSRX', 'Low pH Good Morning Gel Cleanser', 'cosrx-low-ph-good-morning-gel-cleanser', 'p-cosrx', 6250, 'cleanse'),
];

foreach ($order->items as $item) {
    $item->update(['product_id' => $products[$item->sku]->id]);
}

$order = Order::with('items')->find($order->id);

// Send through the shop's own mailer, on the array transport.
app('mail.manager');
config(['mail.mailers.kbb' => ['transport' => 'array'], 'mail.default' => 'kbb']);
\Illuminate\Support\Facades\Mail::purge('kbb');
$transport = fn () => \Illuminate\Support\Facades\Mail::mailer('kbb')->getSymfonyTransport();

$save = function (string $name) use ($transport, $out): void {
    $messages = $transport()->messages();
    $sent = $messages->last();

    if ($sent === null) {
        echo "NOT SENT: {$name}\n";

        return;
    }

    $email = $sent->getOriginalMessage();
    file_put_contents("{$out}/{$name}.html", (string) $email->getHtmlBody());
    file_put_contents("{$out}/{$name}.subject.txt", (string) $email->getSubject());
    file_put_contents("{$out}/{$name}.txt", (string) $email->getTextBody());
    echo str_pad($name, 28) . ' ' . $email->getSubject() . "\n";
    $transport()->flush();
};

$send = function (\Illuminate\Mail\Mailable $m, string $name) use ($save): void {
    \Illuminate\Support\Facades\Mail::mailer('kbb')->to('aisha.khan@example.com')->send($m);
    $save($name);
};

// The same order, not yet paid (a clone keeps created_at; replicate() drops it).
$unpaid = (clone $order)->forceFill(['paid_at' => null]);

$send(new Mail\OrderConfirmation($order), '01-order-confirmation');
$send(new Mail\OrderPaymentReminder($unpaid, 1), '02-complete-order-30min');
$send(new Mail\OrderPaymentReminder($unpaid, 2), '03-complete-order-24h');
$send(new Mail\OrderStatusChanged($order, 'onhold', 'Please confirm the building name, so the courier can find you.'), '04-order-on-hold');
$send(new Mail\OrderStatusChanged($order, 'shipped'), '05-order-shipped');
$send(new Mail\OrderStatusChanged($order, 'completed'), '06-order-delivered');
$send(new Mail\OrderStatusChanged($order, 'cancelled'), '07-order-cancelled');

$refund = \App\Models\Refund::create([
    'order_id' => $order->id, 'amount' => 8900, 'reason' => 'preview', 'status' => 'succeeded',
    'provider' => 'tabby', 'provider_ref' => 'tabby_refund_PREVIEW',
]);
$send(new Mail\OrderRefunded($order, $refund), '08-order-refunded');
$send(new Mail\OrderStatusChanged($unpaid, 'failed'), '09-order-payment-failed');
$send(new Mail\NewOrderAlert($order), '10-new-order-alert');
$send(new Mail\OrderInvoice($order), '11-order-invoice');

$send(new Mail\BackInStockAlert(
    'Back in stock: Heartleaf 77% Soothing Toner 250ml',
    'You asked us to tell you when this came back. It is in stock now, while it lasts.',
    'Heartleaf 77% Soothing Toner 250ml',
    \App\Support\Url::external('/product/anua-heartleaf-77-soothing-toner-250ml/'),
    \App\Support\Url::external('/mail-preferences/?t=preview'),
), '12-back-in-stock');

$send(new Mail\CartRecoveryReminder(
    'Your basket is waiting',
    'Everything is saved — pick up where you left off.',
    [
        ['name' => 'Heartleaf 77% Soothing Toner 250ml', 'slug' => 'anua-heartleaf-77-soothing-toner-250ml', 'quantity' => 1, 'unit_price' => 8900],
        ['name' => 'Glow Deep Serum Rice + Alpha-Arbutin 30ml', 'slug' => 'boj-glow-deep-serum', 'quantity' => 2, 'unit_price' => 11500],
    ],
    \App\Support\Url::external('/cart/'),
    \App\Support\Url::external('/mail-preferences/?t=preview'),
), '13-basket-reminder');

$send(new Mail\CustomerAccountInvite(
    'Your K Beauty Bliss account is ready',
    [
        ['text' => "We have moved to a new website and kept your order history. Choose a password to sign in, see past orders and check out faster.\n"],
        ['link' => true],
        ['text' => "\nThe link works for 7 days and only for you."],
    ],
    'text body',
    \App\Support\Url::external('/my-account/set-password/preview/'),
    'K Beauty Bliss',
), '14-account-invite');

$send(new Mail\NewsletterConfirmation(
    \App\Support\Url::external('/newsletter/confirm/preview/'),
    \App\Support\Url::external('/newsletter/unsubscribe/preview/'),
    14,
), '15-newsletter-confirm');

$send(new Mail\QuizPlanEmail(
    'Aisha', 'Combination', ['Dullness', 'Dark spots'],
    [
        ['name' => 'Morning', 'steps' => ['Gentle cleanser', 'Hydrating toner', 'Vitamin C serum', 'Moisturiser', 'Sunscreen']],
        ['name' => 'Evening', 'steps' => ['Oil cleanser', 'Water cleanser', 'Toner', 'Brightening serum', 'Night cream']],
    ],
    \App\Support\Url::external('/shop/'), null, \App\Support\Url::external('/concern/dark-spots/'),
), '16-quiz-plan');

$aisha = Customer::where('email', 'aisha.khan@example.com')->first();
$aisha->notify(new \App\Notifications\CustomerPasswordReset('preview-token', $aisha->id));
$save('17-password-reset');
$aisha->notify(new \App\Notifications\CustomerEmailVerification($aisha));
$save('18-verify-email');

$send(new Mail\OrderFeedbackRequest($order), '19-feedback-request');

// Not in the preview set, rendered for completeness: the two statuses the
// previews do not draw.
$send(new Mail\OrderStatusChanged($order, 'processing'), '20-order-processing');
$send(new Mail\OrderStatusChanged($order, 'refunded'), '21-order-refunded-status');

echo 'web copies stored: ' . DB::table('mail_web_copies')->count() . "\n";
