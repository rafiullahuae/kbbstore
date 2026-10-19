<?php

declare(strict_types=1);

/*
 * Lane AEM — the owner, on a real Arabic order confirmation (order 56262) as it
 * reached him: "in the arabic emails to customers, use our arabic logo, and
 * also make it RTL properly. also any further notifications like shipped,
 * completed etc must be in arabic, if order was placed through arabic version."
 *
 * What he received: the English "K-BeautyBliss" masthead; "Halima ،شكرًا لك" and
 * ".لديك سؤال؟" with their punctuation on the wrong side; the tracker starting
 * on the LEFT; the order chip, the totals (الإجمالي on the left, the amount on
 * the right) and the WhatsApp/email/Instagram row in English column order.
 * Gmail drops <html dir="rtl">, the one place the email said which way it read.
 */

use App\Mail\NewOrderAlert;
use App\Mail\OrderConfirmation;
use App\Mail\OrderStatusChanged;
use App\Models\Order;
use App\Models\Setting;
use App\Services\Mail\Kit\KitRtl;
use App\Services\Mail\OrderMailer;
use App\Services\Mail\OrderReminders;
use App\Services\SettingsService;
use App\Services\Translation\TranslationStore;
use App\Support\Locale;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;

beforeEach(function () {
    Setting::query()->updateOrCreate(['key' => Locale::SETTING_ENABLED], ['value' => '1', 'autoload' => true]);
    $settings = app(SettingsService::class);
    $settings->set('store_name', 'K Beauty Bliss');
    $settings->set('brand_whatsapp', '+971 58 505 2611');
    $settings->set('social_instagram', 'kbeauty.bliss');
    // A public host, so the kit can print an absolute image address at all.
    $settings->set('site_url', 'https://extrabeauty.ae');
    Setting::flushMap();
    SettingsService::forgetMemo();
    $settings->flush();
    TranslationStore::flush();
    \App\Services\Mail\Kit\WebCopy::reset();

    config(['mail.default' => 'array']);
    app('mail.manager');
    config(['mail.mailers.kbb' => ['transport' => 'array']]);
    Mail::purge('kbb');
    app()->setLocale('en');
});

afterEach(function () {
    Carbon::setTestNow();
    app()->setLocale('en');
});

function aemOrder(string $locale, array $overrides = []): Order
{
    $address = [
        'first_name' => 'Halima', 'last_name' => 'Ahmed', 'line1' => 'Apartment 1204, Marina Heights Tower',
        'city' => 'Dubai', 'country' => 'AE', 'phone' => '+971 50 123 4567',
    ];

    $order = Order::create(array_merge([
        'order_number' => 'KBB-' . random_int(10000, 99999), 'email' => 'halima@example.com', 'phone' => '+971 50 123 4567',
        'status' => 'pending', 'currency' => 'AED', 'billing_address' => $address, 'shipping_address' => $address,
        'subtotal' => 40300, 'discount_total' => 0, 'shipping_total' => 0, 'fee_total' => 0, 'tax_total' => 0,
        'total' => 40300, 'shipping_method' => 'Standard delivery', 'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery', 'locale' => $locale,
    ], $overrides));

    $order->items()->create([
        'name' => 'Rice Toner', 'brand' => 'Haruharu', 'quantity' => 1, 'unit_price' => 40300,
        'subtotal' => 40300, 'total' => 40300,
    ]);

    return $order->fresh('items');
}

/** @return list<\Symfony\Component\Mime\Email> */
function aemSent(): array
{
    $transport = Mail::mailer('kbb')->getSymfonyTransport();

    return $transport instanceof ArrayTransport
        ? array_map(static fn ($m) => $m->getOriginalMessage(), $transport->messages()->all())
        : [];
}

function aemAdmin(): \App\Models\AdminUser
{
    return \App\Models\AdminUser::create([
        'name' => 'AEM Owner', 'email' => 'aem-owner-' . uniqid() . '@example.test',
        'password' => 'secret-secret', 'role' => 'owner',
    ]);
}

/** The Arabic render of a mailable, in the order's own language, as OrderMailer sends it. */
function aemRender(Order $order, \Closure $build): string
{
    return \App\Support\OrderLocale::render($order, static fn (): string => (string) $build()->render());
}

it('mastheads an Arabic order email with the Arabic logo, not the English wordmark', function () {
    $html = aemRender(aemOrder('ar'), fn () => new OrderConfirmation(Order::latest('id')->first()));

    /*
     * The defect: "K-Beauty<span …>Bliss</span>" in the masthead and the footer
     * of an Arabic email. MUTATION: return null from KitRtl::masthead() and the
     * first and last expectations are red.
     */
    expect($html)->not->toContain('K-Beauty<span')
        ->and($html)->toContain('<img src="https://extrabeauty.ae/img-cache/mail/kbb-logo-ar-' . KitRtl::VERSION . '.png" width="200" height="48" alt="كي بيوتي بليس"')
        // The footer's brand line, in Arabic text, with its space.
        ->and($html)->toContain('كي بيوتي<span style="color:#C13E63;"> بليس</span>')
        // The copy the web server will serve.
        ->and(is_file(public_path('img-cache/mail/kbb-logo-ar-' . KitRtl::VERSION . '.png')))->toBeTrue();
});

it('ships a small logo whose public name follows its bytes', function () {
    $file = resource_path(KitRtl::SOURCE);

    [$w, $h] = getimagesize($file);

    // A redrawn PNG with an unchanged VERSION would be served from every
    // client's cache as the old one forever.
    expect(substr(sha1_file($file), 0, 8))->toBe(KitRtl::VERSION)
        ->and(filesize($file))->toBeLessThan(15 * 1024)
        ->and([$w, $h])->toBe([KitRtl::WIDTH * 2, KitRtl::HEIGHT * 2]);
});

it('prints the Arabic name as text when no image address can be made, never the English one', function () {
    app(SettingsService::class)->set('site_url', '');
    Setting::flushMap();
    SettingsService::forgetMemo();

    $html = aemRender(aemOrder('ar'), fn () => new OrderConfirmation(Order::latest('id')->first()));

    expect($html)->not->toContain('kbb-logo-ar')
        ->and($html)->not->toContain('K-Beauty<span')
        ->and(substr_count($html, 'كي بيوتي<span style="color:#C13E63;"> بليس</span>'))->toBe(2);
});

it('says right to left on every layout table, where Gmail cannot strip it', function () {
    $order = aemOrder('ar', ['status' => 'shipped']);
    $html = aemRender($order, fn () => new OrderStatusChanged($order, 'shipped'));

    preg_match_all('/<table role="presentation"[^>]*>/', $html, $tables);

    /*
     * The defect: one dir="rtl", on <html>, which Gmail and Outlook.com drop.
     * Every table now carries its own, so its cells run from the right in each
     * client -- the tracker's first step (تم الطلب) is the right-most cell, the
     * chip reads الطلب first from the right. MUTATION: drop the mirror() call
     * in KitSections::close() and this is red with dozens of LTR tables.
     */
    expect(count($tables[0]))->toBeGreaterThan(20);

    foreach ($tables[0] as $tag) {
        expect($tag)->toContain('dir="rtl"');
    }

    // The tracker's own row: its four steps sit in a right-to-left table.
    expect($html)->toMatch('/<table role="presentation" dir="rtl"[^>]*><tr><td width="25%"/');

    // The totals (on the receipt): the amount's cell is aligned to the LEFT
    // edge, opposite its label, which sits on the right.
    $receipt = aemRender($order, fn () => new OrderConfirmation($order));

    expect($receipt)->toMatch('/<td align="left" style="padding:14px 0 4px;[^"]*font-size:20px/')
        ->and($receipt)->not->toContain('align="right"')
        ->and($html)->not->toContain('align="right"')
        // Arabic is joined-up: no tracking pulls its letters apart.
        ->and($html)->not->toMatch('/letter-spacing:(?!0)[^;"]+/');

    // Phone numbers and handles read left to right inside the Arabic text.
    expect($html)->toContain('<span dir="ltr">+971 58 505 2611</span>')
        ->and($html)->toContain('<span dir="auto">+971 50 123 4567</span>');
});

it('leaves an English order email exactly as it was', function () {
    $order = aemOrder('en', ['status' => 'shipped']);
    $html = aemRender($order, fn () => new OrderStatusChanged($order, 'shipped'))
        . aemRender($order, fn () => new OrderConfirmation($order));

    expect($html)->not->toContain('dir="rtl"')
        ->and($html)->not->toContain('<span dir=')
        ->and($html)->not->toContain('kbb-logo-ar')
        ->and($html)->toContain('K-Beauty<span style="color:#C13E63;">Bliss</span>')
        ->and($html)->toContain('<td align="right"')
        ->and($html)->toContain('<html lang="en" dir="ltr"');
});

it('sends the shipped email in Arabic, with /ar links, when the English admin changes an Arabic order', function () {
    $order = aemOrder('ar', ['status' => 'processing', 'paid_at' => now()]);
    Mail::purge('kbb');

    $this->actingAs(aemAdmin(), 'admin')
        ->putJson('/admin-api/orders/' . $order->id . '/status', ['status' => 'shipped', 'notify' => true])
        ->assertOk();

    $sent = aemSent();

    expect($sent)->toHaveCount(1);

    $mail = $sent[0];

    // The defect this guards: English paperwork after an Arabic checkout.
    expect($mail->getSubject())->toContain('في طريقه إليك')
        ->and($mail->getHtmlBody())->toContain('dir="rtl"')
        ->and($mail->getHtmlBody())->toMatch('#href="https?://[^"/]+/ar/track-my-order/"#')
        ->and($mail->getHtmlBody())->not->toMatch('#href="https?://[^"/]+/track-my-order/"#')
        ->and($mail->getHtmlBody())->not->toContain('Your order is on its way')
        ->and((string) $mail->getTextBody())->toContain('طلبك')
        // and the admin's process is English again afterwards.
        ->and(app()->getLocale())->toBe('en');
});

it('sends a bulk status change and the scheduler\'s reminder in Arabic for Arabic orders', function () {
    $a = aemOrder('ar', ['status' => 'processing', 'paid_at' => now()]);
    $e = aemOrder('en', ['status' => 'processing', 'paid_at' => now(), 'email' => 'english@example.com']);
    Mail::purge('kbb');

    $this->actingAs(aemAdmin(), 'admin')
        ->postJson('/admin-api/orders-bulk-status', ['ids' => [$a->id, $e->id], 'status' => 'completed', 'force' => true])
        ->assertOk();
    OrderMailer::flushDeferred();

    $byTo = collect(aemSent())->keyBy(fn ($m) => $m->getTo()[0]->getAddress());

    expect($byTo)->toHaveCount(2)
        ->and($byTo['halima@example.com']->getHtmlBody())->toContain('<html lang="ar" dir="rtl"')
        ->and($byTo['english@example.com']->getHtmlBody())->toContain('<html lang="en" dir="ltr"')
        ->and($byTo['english@example.com']->getHtmlBody())->not->toContain('dir="rtl"');

    // The scheduler: an unpaid Arabic order, 30 minutes on, from an English CLI process.
    Carbon::setTestNow('2026-10-19 10:00:00');
    $unpaid = aemOrder('ar', ['status' => 'pending', 'payment_method' => 'stripe', 'payment_method_title' => 'Card', 'email' => 'unpaid@example.com']);
    Mail::purge('kbb');
    Carbon::setTestNow('2026-10-19 10:30:00');

    expect(app(OrderReminders::class)->sweep(10))->toBe(1);

    $reminder = aemSent()[0];

    expect($reminder->getTo()[0]->getAddress())->toBe('unpaid@example.com')
        ->and($reminder->getHtmlBody())->toContain('<html lang="ar" dir="rtl"')
        ->and($reminder->getHtmlBody())->toContain('kbb-logo-ar-')
        ->and(app()->getLocale())->toBe('en');
});

it('keeps an English order English while the process is in Arabic', function () {
    $order = aemOrder('en', ['status' => 'processing', 'paid_at' => now()]);
    Mail::purge('kbb');
    app()->setLocale('ar');

    $order->update(['status' => 'shipped']);
    OrderMailer::flushDeferred();

    $mail = aemSent()[0];

    expect($mail->getSubject())->toContain('on its way')
        ->and($mail->getHtmlBody())->not->toContain('dir="rtl"')
        ->and($mail->getHtmlBody())->toContain('K-Beauty<span')
        ->and(app()->getLocale())->toBe('ar');
});

it('keeps the owner\'s new-order alert in English for an Arabic order', function () {
    app(\App\Services\Mail\MailSettings::class)->save(['mail_merchant_address' => 'owner@example.com']);
    $order = aemOrder('ar');
    Mail::purge('kbb');

    $html = (string) (new NewOrderAlert($order))->render();
    app(OrderMailer::class)->placed($order);
    OrderMailer::flushDeferred();

    $alert = collect(aemSent())->first(fn ($m) => $m->getTo()[0]->getAddress() === 'owner@example.com');

    expect($alert)->not->toBeNull()
        ->and($alert->getHtmlBody())->toContain('<html lang="en" dir="ltr"')
        ->and($alert->getHtmlBody())->not->toContain('dir="rtl"')
        ->and($alert->getHtmlBody())->not->toContain('kbb-logo-ar')
        ->and($html)->not->toContain('dir="rtl"');
});

it('escapes what its isolates print, in both languages, exactly as {{ }} would in English', function () {
    $typed = '<img src=x onerror=alert(1)> & "Halima"';

    foreach (['ltr', 'auto', 'number'] as $fn) {
        // English: byte for byte what Blade's {{ }} prints.
        expect(KitRtl::$fn($typed))->toBe(e($typed));
    }

    app()->setLocale('ar');

    expect(KitRtl::ltr($typed))->toBe('<span dir="ltr">' . e($typed) . '</span>')
        ->and(KitRtl::auto($typed))->toBe('<span dir="auto">' . e($typed) . '</span>')
        ->and(KitRtl::number($typed))->toBe('<span dir="ltr" style="white-space:nowrap">' . e($typed) . '</span>')
        ->and(KitRtl::number($typed))->not->toContain('<img');
});

it('mirrors left and right in tags only, never in the words', function () {
    $html = '<table role="presentation" width="100%"><tr><td align="right" style="padding:5px 0 5px 12px;padding-left:12px;border-left:4px solid #C13E63;letter-spacing:.12em;text-align:right">turn left at the right door</td></tr></table>';

    expect(KitRtl::mirror($html))->toBe('<table role="presentation" dir="rtl" width="100%"><tr><td align="left" style="padding:5px 12px 5px 0;padding-right:12px;border-right:4px solid #C13E63;letter-spacing:0;text-align:left">turn left at the right door</td></tr></table>');
});

it('still delivers, in English, when the Arabic render throws -- and says so at ERROR', function () {
    $order = aemOrder('ar', ['status' => 'processing', 'paid_at' => now()]);
    Mail::purge('kbb');

    // An Arabic-only failure inside the body (the masthead partial).
    View::composer('emails.kit.header', static function (): void {
        if (app()->getLocale() === 'ar') {
            throw new \RuntimeException('aem: arabic render broke');
        }
    });

    Log::spy();

    $order->update(['status' => 'shipped']);
    OrderMailer::flushDeferred();

    $sent = aemSent();

    /*
     * Before: the ViewException reached send()'s catch, was logged as "order
     * mail failed" and the customer got nothing. MUTATION: remove the catch in
     * OrderMailer::inLocale() and $sent is empty.
     */
    expect($sent)->toHaveCount(1)
        ->and($sent[0]->getTo()[0]->getAddress())->toBe('halima@example.com')
        ->and($sent[0]->getSubject())->toContain('on its way')
        ->and(app()->getLocale())->toBe('en');

    Log::shouldHaveReceived('error')->withArgs(fn (string $msg) => str_contains($msg, 'the ar render failed; sent in English instead'))->once();
});
