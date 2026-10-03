<?php

declare(strict_types=1);

/*
 * Lane EM — the real emails against the owner's approved previews.
 *
 * The owner, 3 October 2026, on docs/rj-email-previews/ at 9d6dea4: "stick to
 * the final previews along with the changes ... i want 100% same stuff as in
 * previews". The previews are tools/rj-build-after.cjs output; the real emails
 * are resources/views/emails/kit/*.blade.php. This file holds the two
 * together, so a later edit to either one cannot drift silently:
 *
 *   1. BLOCK FOR BLOCK. Every row of the card that carries the kit's `px`
 *      class has a padding that names the block (hero 30px 32px 6px, tracker
 *      22px 24px 4px, order chip 18px 32px 0, help box 28px 32px 0 …). The
 *      ordered list of those paddings in each real email must equal the same
 *      list in its approved preview. Two emails differ ON PURPOSE and say why.
 *   2. THE OWNER'S DECISIONS: look A, Outfit, the emoji titles, the footer
 *      (addresses only once typed, terms and privacy and NO returns link,
 *      unsubscribe only where there is a list, no phone or email), "View this
 *      email in your browser" at the very bottom.
 *   3. THE BROWSER COPY: the link opens exactly the HTML that was sent, and a
 *      forged, malformed or unknown token is the same 404.
 *   4. NOTHING TYPED BY A PERSON IS PRINTED RAW.
 */

use App\Mail;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Refund;
use App\Services\Mail\Kit\WebCopy;
use App\Services\Mail\MailConfigurator;
use App\Services\Mail\MailSettings;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Mail as MailFacade;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    WebCopy::reset();
    Route::middleware('web')->group(base_path('routes/mail-kit.php'));
});

/** The previews' order: KBB-10427, Anua ×2, Beauty of Joseon, COSRX, GLOW10, Tabby. */
function emkOrder(): Order
{
    $settings = app(SettingsService::class);
    $settings->set('store_name', 'K Beauty Bliss');
    $settings->set('brand_whatsapp', '+971 58 505 2611');
    $settings->set('social_instagram', 'kbeauty.bliss');
    app(MailSettings::class)->save(['mail_signature' => 'With love,|the K Beauty Bliss team']);
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();

    $address = [
        'first_name' => 'Aisha', 'last_name' => 'Khan',
        'line1' => 'Apartment 1204, Marina Heights Tower', 'line2' => 'Al Marsa Street, Dubai Marina',
        'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE', 'phone' => '+971 50 123 4567',
    ];

    $order = Order::create([
        'order_number' => 'KBB-10427', 'email' => 'aisha.khan@example.com', 'phone' => '+971 50 123 4567',
        'status' => 'processing', 'currency' => 'AED', 'billing_address' => $address, 'shipping_address' => $address,
        'subtotal' => 35550, 'discount_total' => 3555, 'coupon_code' => 'GLOW10', 'shipping_total' => 0,
        'fee_total' => 0, 'tax_total' => 0, 'total' => 31995, 'shipping_method' => 'Free UAE delivery',
        'payment_method' => 'tabby', 'payment_method_title' => 'Tabby', 'paid_at' => now(),
    ]);

    foreach ([
        ['Anua', 'Heartleaf 77% Soothing Toner 250ml', 'anua-toner', 2, 8900, 'tone'],
        ['Beauty of Joseon', 'Glow Deep Serum Rice + Alpha-Arbutin 30ml', 'boj-serum', 1, 11500, 'treat'],
        ['COSRX', 'Low pH Good Morning Gel Cleanser', 'cosrx-cleanser', 1, 6250, 'cleanse'],
    ] as [$brand, $name, $slug, $qty, $unit, $role]) {
        $b = Brand::firstOrCreate(['slug' => \Illuminate\Support\Str::slug($brand)], ['name' => $brand]);
        $p = Product::create([
            'name' => $name, 'slug' => $slug, 'brand_id' => $b->id, 'status' => 'publish', 'is_visible' => 1,
            'price' => $unit, 'stock_status' => 'instock', 'type' => 'simple',
            'image' => 'https://cdn.example.com/' . $slug . '.jpg', 'routine_role' => $role,
        ]);
        $order->items()->create([
            'product_id' => $p->id, 'name' => $name, 'brand' => $brand, 'quantity' => $qty,
            'unit_price' => $unit, 'subtotal' => $qty * $unit, 'total' => $qty * $unit,
        ]);
    }

    return $order->fresh('items');
}

/** name => a closure returning the rendered HTML of the REAL email. */
function emkEmails(Order $order): array
{
    $unpaid = (clone $order)->forceFill(['paid_at' => null]);
    $aisha = Customer::firstOrCreate(['email' => 'aisha.khan@example.com'], ['name' => 'Aisha Khan']);
    $url = fn (string $p) => \App\Support\Url::external($p);
    $notify = function ($notification) use ($aisha): string {
        $mail = $notification->toMail($aisha);

        return (string) view($mail->view[0] ?? $mail->view, $mail->viewData)->render();
    };

    return [
        '01-order-confirmation' => fn () => (new Mail\OrderConfirmation($order))->render(),
        '02-complete-order-30min' => fn () => (new Mail\OrderPaymentReminder($unpaid, 1))->render(),
        '03-complete-order-24h' => fn () => (new Mail\OrderPaymentReminder($unpaid, 2))->render(),
        '04-order-on-hold' => fn () => (new Mail\OrderStatusChanged($order, 'onhold', 'Your building name'))->render(),
        '05-order-shipped' => fn () => (new Mail\OrderStatusChanged($order, 'shipped'))->render(),
        '06-order-delivered' => fn () => (new Mail\OrderStatusChanged($order, 'completed'))->render(),
        '07-order-cancelled' => fn () => (new Mail\OrderStatusChanged($order, 'cancelled'))->render(),
        '08-order-refunded' => fn () => (new Mail\OrderRefunded($order, new Refund(['amount' => 8900, 'status' => 'succeeded', 'provider_ref' => 'r1'])))->render(),
        '09-order-payment-failed' => fn () => (new Mail\OrderStatusChanged($unpaid, 'failed'))->render(),
        '10-new-order-alert' => fn () => (new Mail\NewOrderAlert($order))->render(),
        '11-order-invoice' => fn () => (new Mail\OrderInvoice($order))->render(),
        '12-back-in-stock' => fn () => (new Mail\BackInStockAlert('Back in stock', 'It is back.', 'Toner', $url('/product/anua-toner/'), $url('/mail-preferences/?t=x')))->render(),
        '13-basket-reminder' => fn () => (new Mail\CartRecoveryReminder('Basket', 'Saved.', [['name' => 'Toner', 'slug' => 'anua-toner', 'quantity' => 1, 'unit_price' => 8900]], $url('/cart/'), $url('/mail-preferences/?t=x')))->render(),
        '14-account-invite' => fn () => (new Mail\CustomerAccountInvite('Ready', [['text' => 'We moved.'], ['link' => true], ['text' => '7 days.']], 'text', $url('/set/'), 'K Beauty Bliss'))->render(),
        '15-newsletter-confirm' => fn () => (new Mail\NewsletterConfirmation($url('/newsletter/confirm/x/'), $url('/newsletter/unsubscribe/x/'), 14))->render(),
        '16-quiz-plan' => fn () => (new Mail\QuizPlanEmail('Aisha', 'Combination', ['Dullness'], [['name' => 'Morning', 'steps' => ['Cleanse']], ['name' => 'Evening', 'steps' => ['Cleanse']]], $url('/shop/'), null))->render(),
        '17-password-reset' => fn () => $notify(new \App\Notifications\CustomerPasswordReset('tok', $aisha->id)),
        '18-verify-email' => fn () => $notify(new \App\Notifications\CustomerEmailVerification($aisha)),
        '19-feedback-request' => fn () => (new Mail\OrderFeedbackRequest($order))->render(),
    ];
}

/** The ordered paddings of every `px` row: the email's blocks, in order. */
function emkBlocks(string $html): array
{
    preg_match_all('/<td class="px"[^>]*style="padding:([^;"]*)/', $html, $m);

    return $m[1];
}

it('builds every one of the nineteen emails from the same blocks, in the same order, as its approved preview', function () {
    $order = emkOrder();

    /*
     * THE TWO DELIBERATE DIFFERENCES, each the preview promising something
     * this shop cannot honestly print:
     *
     *   10  the merchant alert's "Open in admin" button (26px 32px 0) is the
     *       sentence saying where to find the order (18px 32px 0): the admin
     *       address is a secret here (admin_path), and a button would put it
     *       in every alert and every mail log it passes through.
     *   11  the invoice: the preview says "attached as a PDF" and has no
     *       billing block. Nothing is attached in this shop -- the invoice IS
     *       the body -- so it also carries Bill to / Deliver to (24px 32px 0)
     *       and the seller with its TRN (22px 32px 0).
     */
    $expectedDifferences = [
        '10-new-order-alert' => static function (array $blocks): array {
            $blocks[1] = '26px 32px 0';

            return $blocks;
        },
        '11-order-invoice' => static fn (array $blocks): array => array_values(array_diff_key($blocks, [5 => 1, 6 => 1])),
    ];

    foreach (emkEmails($order) as $name => $render) {
        $preview = emkBlocks((string) file_get_contents(base_path("docs/rj-email-previews/after/{$name}.html")));
        $real = emkBlocks((string) $render());

        if (isset($expectedDifferences[$name])) {
            $real = $expectedDifferences[$name]($real);
        }

        expect($preview)->not->toBe([], "{$name}: the preview has no kit rows -- is it the approved file?");
        expect($real)->toBe($preview, "{$name} is not built from the approved preview's blocks, in its order");
    }

    /*
     * MUTATION: drop @include('emails.kit.help') from emails/kit/order.blade.php
     * and nine of these go red; drop the promises from the reminder and 02/03
     * do; drop the "How to use them together" line and 06 does.
     */
});

it('carries the owner\'s emoji in the subjects and the headline of each email he named', function () {
    $order = emkOrder();
    $unpaid = (clone $order)->forceFill(['paid_at' => null]);

    $cases = [
        '🎉' => new Mail\OrderConfirmation($order),
        '🛍️' => new Mail\OrderPaymentReminder($unpaid, 1),
        '⏳' => new Mail\OrderPaymentReminder($unpaid, 2),
        '🚚💨' => new Mail\OrderStatusChanged($order, 'shipped'),
        '✨' => new Mail\OrderStatusChanged($order, 'completed'),
        '💌' => new Mail\OrderFeedbackRequest($order),
    ];

    foreach ($cases as $emoji => $mailable) {
        $html = (string) $mailable->render();

        expect($mailable->envelope()->subject)->toContain($emoji)
            // The headline: the kit's h1.
            ->and($html)->toMatch('/class="h1 ink"[^>]*>[^<]*' . preg_quote($emoji, '/') . '/u');
    }

    // Payment failed: 😔 in the headline (the approved subject has none).
    expect((string) (new Mail\OrderStatusChanged($unpaid, 'failed'))->render())
        ->toMatch('/class="h1 ink"[^>]*>[^<]*😔/u');
});

it('draws the footer the owner asked for: addresses only once typed, terms and privacy, no returns, no phone or email', function () {
    $order = emkOrder();
    $html = (string) (new Mail\OrderConfirmation($order))->render();
    $footer = substr($html, (int) strpos($html, __('email.kit.footer_tagline')));

    /*
     * "in very bottom footer, don't include phone email, what is repeated in
     * the questions? box. just keep address, terms pages, un-subscribe option
     * etc." -- and, after approving the previews, no returns link: the shop
     * does not offer returns.
     *
     * MUTATION: put 'returns' back in MailKit::FOOTER_LINKS and this is red.
     */
    expect($footer)->toContain('/terms-and-conditions/')
        ->and($footer)->toContain('/privacy-policy/')
        ->and($footer)->not->toContain('refund_returns')
        ->and($footer)->not->toContain('Returns')
        ->and($footer)->not->toContain('wa.me')
        ->and($footer)->not->toContain('mailto:')
        // No address typed: no pin, no place, no placeholder.
        ->and($footer)->not->toContain('&#128205;')
        ->and($footer)->not->toContain('[Dubai')
        // A receipt has no list to leave.
        ->and($footer)->not->toContain(__('email.common.unsubscribe'))
        // And "View this email in your browser" is the very last line.
        ->and($footer)->toMatch('~View this email in your browser</a></td></tr>\s*</table>\s*<!--\[if mso\]></td></tr></table><!\[endif\]-->~');

    // The Korea address alone, once typed, prints alone.
    app(MailSettings::class)->save(['mail_address_korea' => 'Seoul office']);
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();

    $html = (string) (new Mail\OrderConfirmation($order->fresh('items')))->render();

    expect($html)->toContain('>Korea</div>')
        ->and($html)->toContain('Seoul office')
        ->and($html)->not->toContain('>Dubai</div>');

    // A marketing email carries the unsubscribe in the footer.
    $stock = (string) (new Mail\BackInStockAlert('s', 'b', 'Toner', 'https://shop.test/product/anua-toner/', 'https://shop.test/mail-preferences/?t=abc'))->render();
    expect($stock)->toContain('href="https://shop.test/mail-preferences/?t=abc"');
});

it('opens exactly the sent email at its browser-copy link, and answers every other token with the same 404', function () {
    app('mail.manager');
    config(['mail.mailers.kbb' => ['transport' => 'array']]);
    MailFacade::purge('kbb');

    $order = emkOrder();
    MailFacade::mailer(MailConfigurator::MAILER)->to('aisha.khan@example.com')->send(new Mail\OrderConfirmation($order));

    $sent = MailFacade::mailer('kbb')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
    $html = (string) $sent->getHtmlBody();

    expect(preg_match('~/mail/view/([A-Za-z0-9_-]{43})"~', $html, $m))->toBe(1, 'the sent email carries no browser-copy link');

    $res = $this->get('/mail/view/' . $m[1]);
    $res->assertOk();

    expect($res->getContent())->toBe($html)
        ->and($res->headers->get('Cache-Control'))->toContain('no-store')
        ->and($res->headers->get('X-Robots-Tag'))->toContain('noindex')
        ->and($res->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'")
        // One copy per sent message, keyed by the hash -- the token is not stored.
        ->and(\Illuminate\Support\Facades\DB::table(WebCopy::TABLE)->count())->toBe(1)
        ->and(\Illuminate\Support\Facades\DB::table(WebCopy::TABLE)->where('token_hash', $m[1])->exists())->toBeFalse();

    $forged = substr($m[1], 0, 42) . ($m[1][42] === 'A' ? 'B' : 'A');

    foreach ([$forged, 'short', str_repeat('x', 43), '../../etc/passwd'] as $bad) {
        $this->get('/mail/view/' . rawurlencode($bad))->assertNotFound();
    }

    // A rendered-but-never-sent email leaves nothing behind to open.
    (new Mail\OrderConfirmation($order))->render();
    expect(\Illuminate\Support\Facades\DB::table(WebCopy::TABLE)->count())->toBe(1);
});

it('prints nothing a person typed raw: every {!! !!} in the kit is a constant or a value the kit proved', function () {
    /*
     * CLAUDE.md rule 5: anything printed unescaped is a constant, never a
     * setting. The kit's raw prints are the font stacks and colours MailKit
     * pattern-checks, the icon entities (constants), and helpers whose every
     * argument goes through e() inside them. Anything else here is red.
     */
    $allowed = [
        "\$k['sans']", "\$k['head']", "\$k['fontFace']", "\$k['accent']",
        '$heroIcon', '$glyph', "\$prIcons[\$prIcon] ?? ''", '$ftDot',
        "\$ipBox(\$left[0], \$left[1])", "\$ipBox(\$left[2], \$left[3])",
        "\$ipBox(\$right[0], \$right[1])", "\$ipBox(\$right[2], \$right[3])",
        "implode(\$ftDot, array_map(fn (array \$l) => \$ftA(\$l['url'], \$l['label']), \$k['links']))",
        "\$ftA(\$ftUnsub, __('email.common.unsubscribe'), true)", "\$ftA(\$ftUnsub, __('email.kit.preferences'))",
        "\$ftA(\$ftWebCopy, __('email.kit.view_in_browser'), true)",
    ];

    $found = [];

    foreach (glob(resource_path('views/emails/kit/*.blade.php')) as $file) {
        preg_match_all('/\{!!\s*(.*?)\s*!!\}/s', (string) file_get_contents($file), $m);

        foreach ($m[1] as $expr) {
            if (! in_array($expr, $allowed, true)) {
                $found[] = basename($file) . ': ' . $expr;
            }
        }
    }

    expect($found)->toBe([]);

    // And the values behind them really are checked: a colour or a font stack
    // that could close the attribute is refused, not printed.
    expect(\App\Services\Mail\Kit\MailKit::hex('red;background:url(x)', '#C13E63'))->toBe('#C13E63')
        ->and(\App\Services\Mail\Kit\MailKit::fontStack('Arial";x:"'))->toBe(\App\Services\Mail\Kit\MailKit::SANS);
});

it('escapes the customer, the owner and the product on the way in', function () {
    $order = emkOrder();
    $order->forceFill([
        'billing_address' => ['first_name' => '<script>alert(1)</script>', 'last_name' => 'X', 'country' => 'AE'],
        'customer_note' => '<img src=x onerror=alert(2)>',
        'is_gift' => true,
        'gift_note' => "Happy birthday\n<b>love</b>",
    ])->save();
    $order->items()->first()->update(['name' => '<svg onload=alert(3)>']);

    $html = (string) (new Mail\OrderConfirmation($order->fresh('items')))->render();

    expect($html)->not->toContain('<script>alert(1)')
        ->and($html)->not->toContain('<img src=x')
        ->and($html)->not->toContain('<svg onload')
        ->and($html)->not->toContain('<b>love</b>')
        ->and($html)->toContain('&lt;script&gt;')
        // The gift message is still there, with its line break.
        ->and($html)->toContain('Happy birthday<br />');
});

it('draws every email in the fonts and colours chosen under Emails → Design & branding', function () {
    /*
     * THE DEFECT (Lane EM): the Design & branding screen offered four colour
     * swatches -- Accent, Buttons, Background, Text -- and the kit read only
     * the first two. Background and Text saved, answered "Saved", and changed
     * no email: a control that does nothing, on the owner's own screen.
     *
     * MUTATION: drop 'background' or 'text' from MailKit::for() (or put the
     * literal #2A2228 back in a partial) and this is red.
     */
    $order = emkOrder();

    app(\App\Services\Mail\EmailLook::class)->save([
        'email_text' => '#112233', 'email_background' => '#fafafa',
        'email_accent' => '#aa0011', 'email_button' => '#0011aa',
        'email_font_heading' => 'georgia', 'email_font_body' => 'system',
    ]);
    \App\Models\Setting::flushMap();
    SettingsService::forgetMemo();

    $html = (string) (new Mail\OrderConfirmation($order->fresh('items')))->render();

    expect($html)->toContain('bgcolor="#FAFAFA"')
        ->and($html)->toContain('color:#112233')
        ->and($html)->not->toContain('#2A2228')
        ->and($html)->toContain('background:#AA0011')       // the accent rule under the header
        ->and($html)->toContain('bgcolor="#0011AA"')        // the button
        ->and($html)->toContain("font-family:Georgia,'Times New Roman',Times,serif;font-size:28px")
        // Neither font is Outfit, so no web font is requested.
        ->and($html)->not->toContain('@font-face');
});
