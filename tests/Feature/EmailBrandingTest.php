<?php

/**
 * The three things the owner asked for in the emails themselves.
 *
 *   1. QUANTITY AS ITS OWN COLUMN. The people who pack these orders read this
 *      table, and quantity used to be the middle number of "2 × AED 199.00" in
 *      grey 12.5px under the product name. Asserted on content, in both the
 *      customer's receipt and the merchant's alert, with a two-line order whose
 *      quantities differ — a template that printed the wrong cell would show the
 *      same number twice and a single-line order would not notice.
 *
 *   2. A SUPPORT BLOCK BUILT FROM REAL SETTINGS. "Focus more on support by
 *      showing our WhatsApp, email, Instagram etc, so the user will feel more
 *      trust and comfort." A support block is a promise, so there is a test here
 *      that greps the shipped templates and services for a hardcoded number or
 *      handle, and one that proves a channel the store has not configured is
 *      simply not printed rather than printed empty.
 *
 *   3. A LOGO SWITCH AND AN EDITABLE SIGNATURE, both from the admin. The
 *      signature is operator input rendered into an HTML body, which is the same
 *      shape as the two XSS holes this repo has already fixed, so it is pinned
 *      with a script tag.
 *
 * Money is asserted in exact fils as well as in the rendered string, the way
 * OrderEmailsTest does: an email that says AED 216 for a 21550-fils order is
 * wrong in a way no screenshot review would catch.
 */

use App\Mail\NewOrderAlert;
use App\Mail\OrderConfirmation;
use App\Models\Order;
use App\Models\Setting;
use App\Services\Mail\EmailBranding;
use App\Services\Mail\MailCredentials;
use App\Services\Mail\MailSettings;
use App\Services\Mail\OrderEmailPresenter;
use App\Services\ModuleRegistry;
use App\Services\SettingsService;

beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
    app(MailCredentials::class)->forget();
});

/**
 * Two lines with DIFFERENT quantities and different unit prices.
 *
 * 3 × 19900 = 59700, plus 1 × 6500 = 6500. Subtotal 66200, delivery 2000,
 * total 68200. Deliberately not a round number and deliberately not equal to
 * any single line, so a total that was copied rather than computed shows.
 */
function brandingOrder(): Order
{
    $order = Order::create([
        'order_number' => 'KBB-BRAND-1',
        'email' => 'buyer@example.com',
        'status' => 'processing',
        'currency' => 'AED',
        'billing_address' => ['first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => '12 Marina Walk', 'city' => 'Dubai', 'country' => 'AE'],
        'shipping_address' => ['first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => '12 Marina Walk', 'city' => 'Dubai', 'country' => 'AE'],
        'subtotal' => 66200,
        'discount_total' => 0,
        'shipping_total' => 2000,
        'fee_total' => 0,
        'tax_total' => 0,
        'total' => 68200,
        'shipping_method' => 'Standard delivery',
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
    ]);

    $order->items()->create([
        'name' => 'Rice Toner', 'brand' => 'Haruharu', 'sku' => 'HH-RT-150',
        'quantity' => 3, 'unit_price' => 19900, 'subtotal' => 59700, 'total' => 59700,
    ]);

    $order->items()->create([
        'name' => 'Centella Ampoule', 'brand' => 'SKIN1004', 'sku' => 'SK-CA-030',
        'quantity' => 1, 'unit_price' => 6500, 'subtotal' => 6500, 'total' => 6500,
    ]);

    return $order->fresh('items');
}

/** The plain-text twin of a mailable, rendered the way the previews render it. */
function brandingText(App\Mail\OrderMail $mailable): string
{
    $content = $mailable->content();

    return (string) view($content->text, array_merge($mailable->buildViewData(), $content->with))->render();
}

/** Fill in the support channels the way the owner would on Store → Mail. */
function brandingSupport(array $overrides = []): void
{
    app(SettingsService::class)->set('store_name', 'K Beauty Bliss');

    app(MailSettings::class)->save(array_merge([
        'mail_from_address' => 'hello@kbeautybliss.com',
        'mail_support_whatsapp' => '+971 58 505 2611',
        'mail_support_instagram' => '@kbeauty.bliss',
    ], $overrides));

    Setting::flushMap();
    SettingsService::forgetMemo();
}

/* ------------------------------------------------ 1. the quantity column -- */

it('prints quantity as its own column in the customer receipt', function () {
    $order = brandingOrder();

    // The integers first. Everything below is a rendering of these.
    expect($order->items[0]->quantity)->toBe(3)
        ->and($order->items[0]->unit_price)->toBe(19900)
        ->and($order->items[0]->total)->toBe(59700)
        ->and($order->items[1]->quantity)->toBe(1)
        ->and($order->total)->toBe(68200);

    $html = (string) (new OrderConfirmation($order))->render();

    // A Qty heading, so the column is labelled and not merely present.
    expect($html)->toContain('>Qty<')
        ->and($html)->toContain('>Item<')
        ->and($html)->toContain('>Total<');

    // Both quantities, in their own cells. Matched with the styling that makes
    // the cell a cell, so a "3" that happened to appear inside a price would
    // not satisfy this.
    expect($html)->toMatch('/font-weight:700;color:#C13E63;line-height:1\.35;">3</')
        ->and($html)->toMatch('/font-weight:700;color:#C13E63;line-height:1\.35;">1</');

    // Nothing was lost on the way: unit price, line total and order total, at
    // the currency's real precision rather than the storefront's rounding.
    expect($html)->toContain(OrderEmailPresenter::html(19900))
        ->and($html)->toContain(OrderEmailPresenter::html(59700))
        ->and($html)->toContain(OrderEmailPresenter::html(6500))
        ->and($html)->toContain(OrderEmailPresenter::html(68200))
        // And the unit price is labelled, so it cannot be read as a line total.
        ->and($html)->toContain('each')
        // The old shape is gone.
        ->and($html)->not->toContain('3 &times;');
});

it('prints quantity as its own column in the merchant alert', function () {
    // This is the one the packing team reads, and the reason the column exists.
    $html = (string) (new NewOrderAlert(brandingOrder()))->render();

    expect($html)->toContain('>Qty<')
        ->and($html)->toMatch('/font-weight:700;color:#C13E63;line-height:1\.35;">3</')
        ->and($html)->toContain(OrderEmailPresenter::html(19900))
        ->and($html)->toContain(OrderEmailPresenter::html(59700));
});

it('names all three figures in the plain-text part too', function () {
    $text = brandingText(new OrderConfirmation(brandingOrder()));

    expect($text)->toContain('QTY 3')
        ->and($text)->toContain('QTY 1')
        ->and($text)->toContain(OrderEmailPresenter::plain(19900) . ' each')
        ->and($text)->toContain('line total ' . OrderEmailPresenter::plain(59700))
        // No markup leaked into text/plain.
        ->and($text)->not->toContain('<td')
        ->and($text)->not->toContain('<div');
});

/* ------------------------------------------------- 2. the support block -- */

it('builds the support block from settings', function () {
    brandingSupport();

    $html = (string) (new OrderConfirmation(brandingOrder()))->render();

    expect($html)->toContain('We are here if you need us')
        // The number as typed, and a wa.me link built from its digits.
        ->and($html)->toContain('+971 58 505 2611')
        ->and($html)->toContain('https://wa.me/971585052611')
        ->and($html)->toContain('mailto:hello@kbeautybliss.com')
        ->and($html)->toContain('https://www.instagram.com/kbeauty.bliss/')
        ->and($html)->toContain('@kbeauty.bliss');
});

it('falls back to the values the storefront already uses', function () {
    // brand_whatsapp is what the footer and the phone menu read; social_instagram
    // is what Store → Business Details saves. An owner who has filled those in
    // should not have to type them a second time.
    $settings = app(SettingsService::class);
    $settings->set('brand_whatsapp', '+971500000001');
    $settings->set('social_instagram', 'https://www.instagram.com/kbeauty.bliss/?hl=en');

    app(MailSettings::class)->save(['mail_from_address' => 'hello@kbeautybliss.com']);
    Setting::flushMap();
    SettingsService::forgetMemo();

    $support = collect(app(EmailBranding::class)->support())->keyBy('kind');

    expect($support['whatsapp']['url'])->toBe('https://wa.me/971500000001')
        // The handle is pulled out of a pasted URL, so a tracking parameter does
        // not end up printed in a receipt.
        ->and($support['instagram']['value'])->toBe('@kbeauty.bliss')
        ->and($support['instagram']['url'])->toBe('https://www.instagram.com/kbeauty.bliss/')
        ->and($support['email']['value'])->toBe('hello@kbeautybliss.com');
});

it('prints nothing for a channel the store has not configured', function () {
    // WhatsApp only. A support block with an empty Instagram row is worse than a
    // shorter block: it advertises a channel nobody is watching.
    app(MailSettings::class)->save([
        'mail_from_address' => 'hello@kbeautybliss.com',
        'mail_support_whatsapp' => '+971585052611',
    ]);
    Setting::flushMap();
    SettingsService::forgetMemo();

    $html = (string) (new OrderConfirmation(brandingOrder()))->render();

    expect($html)->toContain('wa.me/971585052611')
        ->and($html)->not->toContain('instagram.com');
});

it('shows no support block at all when nothing is configured anywhere', function () {
    // APP_URL is http://localhost in the suite, which yields no usable From
    // address, so this really is a store with no channel of any kind.
    $html = (string) (new OrderConfirmation(brandingOrder()))->render();

    expect($html)->not->toContain('We are here if you need us')
        // ...and the rest of the receipt is untouched.
        ->and($html)->toContain('KBB-BRAND-1')
        ->and($html)->toContain(OrderEmailPresenter::html(68200));
});

it('keeps the support block out of the merchant alert', function () {
    brandingSupport();

    $html = (string) (new NewOrderAlert(brandingOrder()))->render();

    // The store does not need to be told how to contact itself, and the bottom
    // of this email is where something the packing team needs will one day go.
    expect($html)->not->toContain('We are here if you need us')
        ->and($html)->not->toContain('wa.me/')
        // Nor a sign-off addressed to the person who wrote it.
        ->and($html)->not->toContain('With love');
});

it('hardcodes no phone number or handle anywhere in the shipped emails', function () {
    /*
     * The guard that survives a rewrite of any single template.
     *
     * The storefront footer carries the store's number as a literal default and
     * that is how it drifts: one screen is updated, another is not, and a
     * receipt goes out for a year with a number the store no longer answers.
     * Every value in an email's support block comes from settings, and this
     * greps for the shapes that would mean it did not.
     */
    $haystack = '';

    foreach (['resources/views/emails', 'app/Mail', 'app/Services/Mail'] as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir)));

        foreach ($files as $file) {
            if ($file->isFile()) {
                $haystack .= file_get_contents($file->getPathname());
            }
        }
    }

    /*
     * Comments stripped first -- Blade comments, block comments and line
     * comments -- because the docblocks explain the fallback order using a real
     * profile URL as the example, and a guard that cannot tell an example from a
     * hardcoded value gets deleted the first time it cries wolf. What is left is
     * only code and markup.
     */
    $haystack = (string) preg_replace(
        ['/\{\{--.*?--\}\}/s', '~/\*.*?\*/~s', '~^\s*//.*$~m'],
        '',
        $haystack,
    );

    // A UAE mobile number in any of the shapes the rest of this repo writes it.
    expect($haystack)->not->toMatch('/\+?971[\s\d]{6,}/')
        // A built wa.me or instagram.com link with something already in it.
        ->and($haystack)->not->toMatch('~wa\.me/\d~')
        ->and($haystack)->not->toMatch('~instagram\.com/[a-z]~i');
});

/* --------------------------------------------- 3. the logo and signature -- */

it('offers the logo switch on a screen the owner already uses', function () {
    // Store → Modules → Order emails, beside the five switches for the emails
    // themselves. ModuleRegistry's `live` status means something reads the key,
    // and Phase3ModuleSwitchesTest greps the source to prove it.
    expect(ModuleRegistry::REGISTRY)->toHaveKey(EmailBranding::LOGO_MODULE);

    $row = ModuleRegistry::REGISTRY[EmailBranding::LOGO_MODULE];

    expect($row[0])->toBe('email')
        ->and($row[3])->toBeTrue()          // on by default
        ->and($row[9])->toBe('live');
});

it('uses the store logo the site already keeps, and no second upload', function () {
    // org_logo: the one logo image this store has, the same file the schema.org
    // Organization block publishes.
    app(SettingsService::class)->set('org_logo', 'https://cdn.kbeautybliss.com/logo.png');
    Setting::flushMap();
    SettingsService::forgetMemo();

    $html = (string) (new OrderConfirmation(brandingOrder()))->render();

    expect($html)->toContain('<img src="https://cdn.kbeautybliss.com/logo.png"');
});

it('makes a site-relative logo absolute, because a mail client has no origin', function () {
    config(['app.url' => 'https://www.kbeautybliss.com']);

    app(SettingsService::class)->set('org_logo', '/media/logo.png');
    Setting::flushMap();
    SettingsService::forgetMemo();

    expect(app(EmailBranding::class)->logoUrl())->toBe('https://www.kbeautybliss.com/media/logo.png');
});

it('refuses a logo src that is not an http url or a site path', function () {
    // An attribute is not a text node: Blade's {{ }} escapes the quotes but
    // would happily print a javascript: or data: src.
    foreach (['javascript:alert(1)', 'data:text/html;base64,PHN2Zz4=', 'not a url'] as $bad) {
        app(SettingsService::class)->set('org_logo', $bad);
        Setting::flushMap();
        SettingsService::forgetMemo();

        expect(app(EmailBranding::class)->logoUrl())->toBeNull();
    }
});

it('falls back to the site wordmark when the switch is off', function () {
    app(SettingsService::class)->set('org_logo', 'https://cdn.kbeautybliss.com/logo.png');
    app(SettingsService::class)->setModule(EmailBranding::LOGO_MODULE, false);
    Setting::flushMap();
    SettingsService::forgetMemo();

    $html = (string) (new OrderConfirmation(brandingOrder()))->render();

    expect($html)->not->toContain('cdn.kbeautybliss.com/logo.png')
        // The header wordmark, in the site's own two halves.
        ->and($html)->toContain('K-Beauty')
        ->and($html)->toContain('Bliss');
});

it('renders the signature the owner typed, on both parts', function () {
    app(MailSettings::class)->save(['mail_signature' => 'Warmly, Sara|Founder, K Beauty Bliss']);
    Setting::flushMap();
    SettingsService::forgetMemo();

    $mailable = new OrderConfirmation(brandingOrder());
    $html = (string) $mailable->render();

    // The pipe is a line break, because Store → Mail's fields are single-line
    // inputs and the owner cannot type a newline into one.
    expect($html)->toContain('Warmly, Sara')
        ->and($html)->toContain('Founder, K Beauty Bliss')
        ->and($html)->toContain('<br>');

    expect(brandingText($mailable))->toContain("Warmly, Sara\nFounder, K Beauty Bliss");
});

it('escapes a signature, because it is operator input in an HTML body', function () {
    app(MailSettings::class)->save([
        'mail_signature' => '<script>alert(1)</script>|<img src=x onerror=alert(2)>',
    ]);
    Setting::flushMap();
    SettingsService::forgetMemo();

    $html = (string) (new OrderConfirmation(brandingOrder()))->render();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->not->toContain('<img src=x onerror=alert(2)>')
        // Present, but as text.
        ->and($html)->toContain('&lt;script&gt;');
});

it('escapes a support value too', function () {
    app(MailSettings::class)->save([
        'mail_from_address' => 'hello@kbeautybliss.com',
        'mail_support_whatsapp' => '+971 58 505 2611" onmouseover="alert(1)',
    ]);
    Setting::flushMap();
    SettingsService::forgetMemo();

    $html = (string) (new OrderConfirmation(brandingOrder()))->render();

    expect($html)->not->toContain('onmouseover="alert(1)"')
        ->and($html)->toContain('&quot;');
});

it('signs off with the store name when no signature is set', function () {
    app(SettingsService::class)->set('store_name', 'K Beauty Bliss');
    Setting::flushMap();
    SettingsService::forgetMemo();

    expect(app(EmailBranding::class)->signature())->toBe(['With love,', 'the K Beauty Bliss team']);
});

it('bounds the signature, since nothing else does', function () {
    // MailApiController's rule list is another lane's file and has no entry for
    // this key, so the cap lives in MailSettings::save().
    app(MailSettings::class)->save(['mail_signature' => str_repeat('a', 5000)]);
    Setting::flushMap();
    SettingsService::forgetMemo();

    expect(mb_strlen(app(MailSettings::class)->get('mail_signature')))
        ->toBe(MailSettings::MAX_LENGTHS['mail_signature']);
});

/* ----------------------------------------------------------- the colours -- */

it('takes its colours from the storefront stylesheet rather than inventing them', function () {
    $css = (string) file_get_contents(resource_path('css/kbb/kbb.css'));

    foreach (['pink' => '--pink:', 'pinkDeep' => '--pink-deep:', 'ink' => '--ink:', 'cream' => '--cream:'] as $key => $var) {
        expect(strtoupper($css))->toContain(strtoupper($var . EmailBranding::PALETTE[$key]));
    }
});

it('puts no stylesheet, media query or flexbox in a message body', function () {
    brandingSupport();

    $html = (string) (new OrderConfirmation(brandingOrder()))->render();

    /*
     * Gmail's web client strips <style> out of a message body, Outlook renders
     * with Word and supports neither flex nor grid, and without a <style> block
     * there is nowhere for a media query to live. Everything here has to work at
     * one width with inline styles, so these are the things that must not appear.
     */
    expect($html)->not->toContain('<style')
        ->and($html)->not->toContain('@media')
        ->and($html)->not->toContain('display:flex')
        ->and($html)->not->toContain('display:grid')
        ->and($html)->not->toContain('<html')
        ->and($html)->not->toContain('<body')
        // And the layout really is tables, with the attributes Word reads.
        ->and($html)->toContain('cellpadding="0"')
        ->and($html)->toContain('bgcolor=');
});

it('carries no credential in a branded email', function () {
    app(MailSettings::class)->save([
        'mail_from_address' => 'hello@kbeautybliss.com',
        'mail_password' => 'KBBBRANDING-pw-0013',
    ]);
    app(SettingsService::class)->set('indexnow_key', 'INDEXNOW-CANARY-0013');
    app(SettingsService::class)->set('admin_path', 'kbb-secret-console');
    Setting::flushMap();
    SettingsService::forgetMemo();

    $order = brandingOrder();

    foreach ([new OrderConfirmation($order), new NewOrderAlert($order)] as $mailable) {
        $html = (string) $mailable->render();
        $text = brandingText($mailable);
        $subject = (string) $mailable->envelope()->subject;

        foreach ([$html, $text, $subject] as $body) {
            expect($body)->not->toContain('KBBBRANDING-pw-0013')
                ->and($body)->not->toContain('INDEXNOW-CANARY-0013')
                ->and($body)->not->toContain('kbb-secret-console');
        }
    }
});
