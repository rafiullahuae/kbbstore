<?php
/*
 * Lane RJ — render every email the shop sends TODAY, through the real
 * Mailables / Notifications, and write what would leave the server.
 *
 * Run inside the preview environment (tools/rj-preview.sh writes env.sh):
 *
 *   . storage/framework/testing/lane-rj-preview/env.sh
 *   php artisan tinker --execute="require 'tools/rj-render-emails.php';"
 *
 * Each message is SENT through Laravel's `array` mailer, not merely rendered,
 * so what is written is the finished Symfony message: the real From, Reply-To,
 * Message-ID, List-Unsubscribe and the text part — the things a spam filter
 * reads. Nothing leaves the machine: `array` keeps messages in memory.
 *
 * Output: docs/rj-email-previews/before/<name>.html  (HTML part, wrapped in a
 *         document so a browser can photograph it — the body is untouched)
 *         <name>.txt (text part, or a note that there is none)
 *         <name>.headers.txt (the headers a receiving server sees)
 *         index.json (subject, from, reply-to, list-unsubscribe, has-text)
 *
 * Read-only towards the code under review: it changes no template, no mail
 * class and no setting outside the preview database.
 */

use App\Mail\BackInStockAlert;
use App\Mail\CartRecoveryReminder;
use App\Mail\CustomerAccountInvite;
use App\Mail\NewOrderAlert;
use App\Mail\NewsletterConfirmation;
use App\Mail\OrderConfirmation;
use App\Mail\OrderInvoice;
use App\Mail\OrderRefunded;
use App\Mail\OrderStatusChanged;
use App\Mail\QuizPlanEmail;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Refund;
use App\Notifications\CustomerEmailVerification;
use App\Notifications\CustomerPasswordReset;
use App\Services\CustomerInvites\InviteTemplate;
use Illuminate\Support\Facades\Mail;

$out = base_path('docs/rj-email-previews/before');
@mkdir($out, 0775, true);

config(['mail.default' => 'array']);
Mail::purge('array');

$order = Order::with('items')->where('order_number', 'KBB-10427')->firstOrFail();
$aisha = Customer::where('email', 'aisha.khan@example.com')->firstOrFail();

$refund = new Refund(['amount' => 8900, 'status' => 'succeeded', 'reason' => 'One toner arrived damaged']);
$refund->order_id = $order->id;
$refund->provider_ref = 'tabby_refund_PREVIEW';

$cancelled = $order->replicate();
$cancelled->status = 'cancelled';
$cancelled->setRelation('items', $order->items);

$invite = app(InviteTemplate::class)->current();
$inviteValues = app(InviteTemplate::class)->values($aisha, url('/account/invite/PREVIEW'), now()->addDays(7));

$mailables = [
    '01-order-confirmation' => fn () => new OrderConfirmation($order),
    '02-new-order-alert' => fn () => new NewOrderAlert($order),
    '03-order-shipped' => fn () => new OrderStatusChanged($order, 'shipped'),
    '04-order-cancelled' => fn () => new OrderStatusChanged($cancelled, 'cancelled'),
    '05-order-refunded' => fn () => new OrderRefunded($order, $refund),
    '06-order-invoice' => function () use ($order) {
        app(\App\Services\Invoices\InvoiceNumbers::class)->allocate($order);
        $order->refresh()->load('items');

        return new OrderInvoice($order);
    },
    '07-back-in-stock' => fn () => new BackInStockAlert(
        'Good news — Heartleaf 77% Soothing Toner is back',
        "You asked us to tell you when this came back.\nIt is back in stock now, while it lasts.",
        'Heartleaf 77% Soothing Toner 250ml',
        url('/product/anua-heartleaf-77-soothing-toner/'),
        url('/mail-preferences/stock/1/?expires=0&signature=PREVIEW'),
    ),
    '08-cart-recovery' => fn () => new CartRecoveryReminder(
        'You left something in your basket',
        "Your basket is still waiting for you.\nEverything is saved — pick up where you left off.",
        [
            ['name' => 'Heartleaf 77% Soothing Toner 250ml', 'slug' => 'anua-heartleaf', 'quantity' => 1, 'unit_price' => 8900, 'setContents' => []],
            ['name' => 'Glow Deep Serum Rice + Alpha-Arbutin 30ml', 'slug' => 'boj-glow-deep', 'quantity' => 2, 'unit_price' => 11500, 'setContents' => []],
        ],
        url('/cart/'),
        url('/mail-preferences/cart/1/?expires=0&signature=PREVIEW'),
    ),
    '09-customer-invite' => fn () => new CustomerAccountInvite(
        InviteTemplate::subject((string) $invite['subject'], $inviteValues),
        InviteTemplate::segments((string) $invite['body'], $inviteValues),
        InviteTemplate::text((string) $invite['body'], $inviteValues),
        url('/account/invite/PREVIEW'),
        $inviteValues['shop_name'],
    ),
    '10-newsletter-confirm' => fn () => new NewsletterConfirmation(
        url('/newsletter/confirm/1/?expires=0&signature=PREVIEW'),
        url('/newsletter/unsubscribe/1/?expires=0&signature=PREVIEW'),
        14,
    ),
    '11-quiz-plan' => fn () => new QuizPlanEmail(
        'Aisha', 'Combination', ['Dullness', 'Dark spots'],
        [['name' => 'Morning', 'steps' => ['Gentle cleanser', 'Hydrating toner', 'Vitamin C serum', 'Moisturiser', 'Sunscreen']],
         ['name' => 'Evening', 'steps' => ['Oil cleanser', 'Water cleanser', 'Toner', 'Brightening serum', 'Night cream']]],
        url('/shop/'), null, url('/concern/dark-spots/'),
    ),
];

$index = [];

$write = function (string $name, $email) use ($out, &$index): void {
    $html = (string) $email->getHtmlBody();
    $text = $email->getTextBody();
    $headers = $email->getPreparedHeaders()->toString();

    $doc = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . e($name) . '</title></head><body style="margin:0;padding:0;">' . $html . '</body></html>';
    file_put_contents("$out/$name.html", $doc);
    file_put_contents("$out/$name.txt", $text !== null && $text !== '' ? (string) $text : "(NO TEXT PART — this message is HTML only)\n");
    file_put_contents("$out/$name.headers.txt", $headers);

    $h = $email->getHeaders();
    $index[$name] = [
        'subject' => $email->getSubject(),
        'from' => implode(', ', array_map(fn ($a) => $a->toString(), $email->getFrom())),
        'reply_to' => implode(', ', array_map(fn ($a) => $a->toString(), $email->getReplyTo())),
        'list_unsubscribe' => $h->has('List-Unsubscribe') ? $h->get('List-Unsubscribe')->getBodyAsString() : '',
        'list_unsubscribe_post' => $h->has('List-Unsubscribe-Post') ? $h->get('List-Unsubscribe-Post')->getBodyAsString() : '',
        'has_text_part' => $text !== null && trim((string) $text) !== '',
        'html_bytes' => strlen($html),
        'images' => preg_match_all('/<img\b/i', $html),
        'links' => array_values(array_unique(array_map(
            fn ($u) => parse_url(html_entity_decode($u), PHP_URL_HOST) ?: '(relative)',
            (preg_match_all('/href="([^"]+)"/i', $html, $m) ? $m[1] : [])
        ))),
    ];
};

$transport = fn () => app('mail.manager')->mailer('array')->getSymfonyTransport();

foreach ($mailables as $name => $build) {
    try {
        $transport()->flush();
        Mail::mailer('array')->to('aisha.khan@example.com')->send($build());
        $sent = $transport()->messages()->last();
        $write($name, $sent->getOriginalMessage());
        echo "rendered $name\n";
    } catch (\Throwable $e) {
        $index[$name] = ['error' => class_basename($e) . ': ' . $e->getMessage()];
        echo "FAILED $name: " . $e->getMessage() . "\n";
    }
}

foreach ([
    '12-password-reset' => new CustomerPasswordReset('PREVIEWTOKEN', $aisha->id),
    '13-verify-email' => new CustomerEmailVerification($aisha),
] as $name => $notification) {
    try {
        $transport()->flush();
        $aisha->notifyNow($notification);
        $sent = $transport()->messages()->last();
        $write($name, $sent->getOriginalMessage());
        echo "rendered $name\n";
    } catch (\Throwable $e) {
        $index[$name] = ['error' => class_basename($e) . ': ' . $e->getMessage()];
        echo "FAILED $name: " . $e->getMessage() . "\n";
    }
}

file_put_contents("$out/index.json", json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo 'done: ' . count($index) . " messages\n";
