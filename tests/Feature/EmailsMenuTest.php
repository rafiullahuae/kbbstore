<?php

declare(strict_types=1);

/**
 * The Emails menu, package E1 (Lane RK).
 *
 * The owner's words this file holds the shop to:
 *
 *   "put all these settings etc under a new parent menu "Emails""
 *   "it will be sent through server email. without any external email. we also
 *    use google business email, and via google smtp we can also send. give both
 *    options."
 *   "we have two addresses, one in dubai, one in Korea ... also include our
 *    whatsapp and email info@kbeautybliss.com and allow us to change these
 *    details anytime."
 *
 * And the three audit bugs it fixes (docs/EMAILS-AUDIT.md §6): B2 (account
 * emails had no text part), B3 (account emails ignored the store's mailer),
 * B4 ("Dubai Dubai" and "AE" in every order email's address).
 *
 * Every fix carries a MUTATION note: the one-line revert that turns it red.
 */

use App\Mail\NewOrderAlert;
use App\Mail\OrderConfirmation;
use App\Models\AdminUser;
use App\Models\Customer;
use App\Models\MailCredential;
use App\Models\Order;
use App\Models\Setting;
use App\Notifications\CustomerEmailVerification;
use App\Notifications\CustomerPasswordReset;
use App\Services\Mail\EmailBranding;
use App\Services\Mail\MailConfigurator;
use App\Services\Mail\MailCredentials;
use App\Services\Mail\MailSettings;
use App\Services\Mail\MailTester;
use App\Services\Mail\OrderEmailPresenter;
use App\Services\Mail\ServerMailTransport;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use Illuminate\Support\Facades\DB;
use Tests\Support\EmailsAdminRoutes;

function rkFresh(): void
{
    Setting::flushMap();
    SettingsService::forgetMemo();
    app(SettingsService::class)->flush();
    app(MailCredentials::class)->forget();
}

function rkSave(array $values): array
{
    $rejected = app(MailSettings::class)->save($values);
    rkFresh();

    return $rejected;
}

function rkOrder(array $address = []): Order
{
    $address = $address + ['first_name' => 'Aisha', 'last_name' => 'Khan', 'line1' => 'Marina Heights', 'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE'];

    $order = Order::create([
        'order_number' => 'KBB-RK-1', 'email' => 'buyer@example.com', 'status' => 'processing', 'currency' => 'AED',
        'billing_address' => $address, 'shipping_address' => $address,
        'subtotal' => 19900, 'discount_total' => 0, 'shipping_total' => 0, 'fee_total' => 0, 'tax_total' => 0, 'total' => 19900,
        'shipping_method' => 'Standard delivery', 'payment_method' => 'cod', 'payment_method_title' => 'Cash on delivery',
    ]);

    $order->items()->create(['name' => 'Rice Toner', 'sku' => 'HH-RT', 'quantity' => 1, 'unit_price' => 19900, 'subtotal' => 19900, 'total' => 19900]);

    return $order->fresh('items');
}

function rkText(App\Mail\OrderMail $mailable): string
{
    $content = $mailable->content();

    return (string) view($content->text, array_merge($mailable->buildViewData(), $content->with))->render();
}

function rkAdmin(string $role): AdminUser
{
    return AdminUser::create([
        'name' => 'RK ' . $role, 'email' => 'rk-' . $role . '-' . uniqid() . '@example.test',
        'password' => 'secret-secret', 'role' => $role,
    ]);
}

function rkAs(string $role = 'owner'): void
{
    EmailsAdminRoutes::wire(app());
    test()->actingAs(rkAdmin($role), 'admin');
}

/* ===================================================================== B2 / B3 */

it('B2: sends password reset and email verification with a plain-text part', function () {
    $c = Customer::create(['email' => 'p@example.com', 'name' => 'Pat']);

    foreach ([new CustomerPasswordReset('tok123', $c->id), new CustomerEmailVerification($c)] as $n) {
        $message = $n->toMail($c);

        // [html, text], not a bare HTML view name.
        expect($message->view)->toBeArray()->toHaveCount(2);

        [$html, $text] = $message->view;
        $rendered = (string) view($text, $message->viewData)->render();

        // The link is in the text part, whole, and nothing in it is markup.
        expect($rendered)->toContain($message->viewData['url'])
            ->and($rendered)->not->toContain('<p')
            ->and($rendered)->not->toContain('<a ')
            ->and(trim($rendered))->not->toBe('');
    }

    /*
     * MUTATION: put ->view('store.account.mail.password-reset', [...]) back (a
     * string, no text view) and toBeArray() is red. On the shop that was a
     * reset email a text-only client showed as blank and spam filters scored
     * down — the one message a locked-out customer cannot do without.
     */
});

it('B3: sends both account emails through the store\'s own kbb mailer', function () {
    $c = Customer::create(['email' => 'q@example.com', 'name' => 'Q']);

    expect((new CustomerPasswordReset('tok', $c->id))->toMail($c)->mailer)->toBe(MailConfigurator::MAILER)
        ->and((new CustomerEmailVerification($c))->toMail($c)->mailer)->toBe(MailConfigurator::MAILER);

    /*
     * MUTATION: drop ->mailer(MailConfigurator::MAILER) and `mailer` is null
     * again. On the shop: an .env with MAIL_MAILER=smtp and no host (as
     * env.staging.txt has) sent order mail fine, the test button said fine,
     * and every password reset failed.
     */
});

it('B3: a reset really leaves through the kbb mailer even when the default mailer is something else', function () {
    app('mail.manager');
    config(['mail.default' => 'log', 'mail.mailers.kbb' => ['transport' => 'array']]);
    Illuminate\Support\Facades\Mail::purge('kbb');

    $c = Customer::create(['email' => 'r@example.com', 'name' => 'R']);
    $c->notify(new CustomerPasswordReset('tok-r', $c->id));

    $sent = Illuminate\Support\Facades\Mail::mailer('kbb')->getSymfonyTransport()->messages()->all();

    expect($sent)->toHaveCount(1);

    $email = $sent[0]->getOriginalMessage();

    // Both parts arrived (B2) on the store's mailer (B3).
    expect((string) $email->getTextBody())->toContain('/my-account/reset/')
        ->and((string) $email->getHtmlBody())->toContain('/my-account/reset/');
});

/* ========================================================================= B4 */

it('B4: prints the city once when the emirate repeats it, and the country by name', function () {
    $presented = (new OrderEmailPresenter)->present(rkOrder());

    $address = implode("\n", $presented['address']);

    expect($address)->not->toContain('Dubai Dubai')
        ->and(substr_count($address, 'Dubai'))->toBe(1)
        ->and($presented['address'])->toContain('United Arab Emirates')
        ->and($presented['address'])->not->toContain('AE');

    /*
     * MUTATION: restore `trim(city . ' ' . state)` in OrderEmailPresenter::
     * address() and "Dubai Dubai" is back (red); restore the raw `country`
     * and "AE" is back (red). On the shop that was every UAE order email,
     * because the checkout copies the emirate into the city.
     */
});

it('B4: keeps a city and an emirate that differ, and leaves an unknown country as typed', function () {
    expect(OrderEmailPresenter::cityLine('Al Barsha', 'Dubai'))->toBe('Al Barsha, Dubai')
        ->and(OrderEmailPresenter::cityLine('dubai ', 'Dubai'))->toBe('dubai')
        ->and(OrderEmailPresenter::cityLine('', 'Sharjah'))->toBe('Sharjah')
        ->and(OrderEmailPresenter::countryName('kr'))->toBe('South Korea')
        ->and(OrderEmailPresenter::countryName('United Arab Emirates'))->toBe('United Arab Emirates')
        ->and(OrderEmailPresenter::countryName('ZZ'))->toBe('ZZ');
});

it('B4: the fixed address reaches both parts of the real order emails', function () {
    $order = rkOrder();

    $html = (string) (new OrderConfirmation($order))->render();
    $text = rkText(new OrderConfirmation($order));
    $alert = rkText(new NewOrderAlert($order));

    foreach ([$html, $text, $alert] as $body) {
        expect($body)->not->toContain('Dubai Dubai')->and($body)->toContain('United Arab Emirates');
    }
});

/* =========================================================== the two transports */

it('keeps this server\'s mail as the default, configured exactly as before', function () {
    $settings = app(MailSettings::class);

    expect($settings->transport())->toBe(MailSettings::TRANSPORT_SERVER)
        ->and(app(MailConfigurator::class)->mailerConfig())->toBe(['transport' => ServerMailTransport::NAME])
        ->and(app(MailConfigurator::class)->activeTransport())->toBe(MailSettings::TRANSPORT_SERVER);
});

it('sends through smtp.gmail.com:587 with STARTTLS required when Google Workspace is chosen', function () {
    rkSave([
        'mail_transport' => MailSettings::TRANSPORT_GMAIL,
        'mail_gmail_username' => 'info@kbeautybliss.com',
        'mail_gmail_password' => 'abcd efgh ijkl mnop',
    ]);

    $config = app(MailConfigurator::class)->mailerConfig();

    expect($config['transport'])->toBe('smtp')
        ->and($config['host'])->toBe('smtp.gmail.com')
        ->and($config['port'])->toBe(587)
        ->and($config['scheme'])->toBe('smtp')
        ->and($config['require_tls'])->toBeTrue()
        ->and($config['username'])->toBe('info@kbeautybliss.com')
        // Google's display spacing removed: the password has none.
        ->and($config['password'])->toBe('abcdefghijklmnop')
        ->and(app(MailConfigurator::class)->activeTransport())->toBe(MailSettings::TRANSPORT_GMAIL);

    // The transport Laravel builds from it really is an ESMTP connection to
    // Google that refuses to authenticate in the clear.
    app(MailConfigurator::class)->refresh();
    $transport = Illuminate\Support\Facades\Mail::mailer(MailConfigurator::MAILER)->getSymfonyTransport();

    expect($transport)->toBeInstanceOf(Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport::class)
        ->and((string) $transport)->toContain('smtp.gmail.com:587');

    $requireTls = (new ReflectionProperty($transport, 'requireTls'))->getValue($transport);
    expect($requireTls)->toBeTrue();

    /*
     * MUTATION: delete `'require_tls' => true` and requireTls is false — the
     * app password could cross a connection that never upgraded to TLS.
     */
});

it('sends AS the Google account when the From box is blank, and only on Google', function () {
    rkSave(['mail_gmail_username' => 'info@kbeautybliss.com']);
    expect(app(MailSettings::class)->fromAddress())->not->toBe('info@kbeautybliss.com');

    rkSave(['mail_transport' => MailSettings::TRANSPORT_GMAIL]);
    expect(app(MailSettings::class)->fromAddress())->toBe('info@kbeautybliss.com');

    rkSave(['mail_from_address' => 'care@kbeautybliss.com']);
    expect(app(MailSettings::class)->fromAddress())->toBe('care@kbeautybliss.com');
});

it('falls back to server mail while Google Workspace is half filled in, and says what is missing', function () {
    rkSave(['mail_transport' => MailSettings::TRANSPORT_GMAIL, 'mail_gmail_username' => 'info@kbeautybliss.com']);

    $settings = app(MailSettings::class);

    expect($settings->configured())->toBeFalse()
        ->and($settings->missing())->toBe(['Google app password'])
        ->and(app(MailConfigurator::class)->mailerConfig())->toBe(['transport' => ServerMailTransport::NAME]);

    $result = app(MailTester::class)->send('owner@example.com');

    expect($result['ok'])->toBeFalse()
        ->and($result['status'])->toBe('unconfigured')
        ->and($result['message'])->toContain('Google app password');
});

it('stores the Google app password encrypted, apart from the SMTP one, and never in settings', function () {
    rkSave(['mail_password' => 'smtp-secret-1']);
    rkSave(['mail_gmail_password' => 'gmailsecretabcd']);

    $settings = app(MailSettings::class);

    expect($settings->password())->toBe('smtp-secret-1')
        ->and($settings->gmailPassword())->toBe('gmailsecretabcd');

    $raw = (string) DB::table('mail_credentials')->value('config');
    $everySetting = json_encode(DB::table('settings')->pluck('value', 'key')->all());

    expect($raw)->not->toContain('gmailsecretabcd')
        ->and($everySetting)->not->toContain('gmailsecretabcd');

    // "-" forgets it; a blank leaves it alone.
    rkSave(['mail_gmail_password' => '']);
    expect(app(MailSettings::class)->hasGmailPassword())->toBeTrue();
    rkSave(['mail_gmail_password' => '-']);
    expect(app(MailSettings::class)->hasGmailPassword())->toBeFalse()
        ->and(app(MailSettings::class)->password())->toBe('smtp-secret-1');
});

/* =============================================================== the endpoints */

it('never sends the Google app password back to the browser', function () {
    rkSave(['mail_transport' => 'gmail', 'mail_gmail_username' => 'info@kbeautybliss.com', 'mail_gmail_password' => 'topsecretvalue1']);
    rkAs('owner');

    $r = $this->getJson('/admin-api/emails/sending')->assertOk();

    expect($r->getContent())->not->toContain('topsecretvalue1')
        ->and($r->json('gmail_password'))->toBe(['has_value' => true])
        ->and($r->json('transport'))->toBe('gmail');

    $saved = $this->postJson('/admin-api/emails/sending', ['transport' => 'gmail', 'settings' => ['mail_from_name' => 'K Beauty Bliss']])->assertOk();
    expect($saved->getContent())->not->toContain('topsecretvalue1');
});

it('offers exactly the two transports the owner asked for, and stores only one of them', function () {
    rkAs('owner');

    $r = $this->getJson('/admin-api/emails/sending')->assertOk();
    expect(array_column($r->json('options'), 'key'))->toBe(['server', 'gmail']);

    // A value the screen does not offer is refused, not coerced.
    $this->postJson('/admin-api/emails/sending', ['transport' => 'smtp'])->assertStatus(422);
    $this->postJson('/admin-api/emails/sending', ['transport' => 'nonsense'])->assertStatus(422);
    rkFresh();
    expect(app(MailSettings::class)->transport())->toBe('server');

    $this->postJson('/admin-api/emails/sending', ['transport' => 'gmail', 'settings' => ['mail_gmail_username' => 'info@kbeautybliss.com', 'mail_gmail_password' => 'abcd efgh ijkl mnop']])
        ->assertOk()->assertJson(['ok' => true, 'configured' => true, 'transport' => 'gmail']);
    rkFresh();
    expect(app(MailSettings::class)->transport())->toBe('gmail');

    /*
     * MUTATION: remove the `in_array($data['transport'], $offered)` refusal
     * and `smtp` is saved — a select storing a value it never offered.
     */
});

it('keeps a shop already on dedicated SMTP on it, shown as the current setting', function () {
    rkSave(['mail_transport' => 'smtp']);
    rkAs('owner');

    $options = $this->getJson('/admin-api/emails/sending')->assertOk()->json('options');

    expect(array_column($options, 'key'))->toBe(['server', 'gmail', 'smtp'])
        ->and($options[2]['label'])->toContain('current setting');

    // Saving the From name without touching the choice leaves SMTP alone.
    $this->postJson('/admin-api/emails/sending', ['transport' => 'smtp', 'settings' => ['mail_from_name' => 'KBB']])->assertOk();
    rkFresh();
    expect(app(MailSettings::class)->transport())->toBe('smtp');
});

it('refuses a key that belongs to another screen', function () {
    rkAs('owner');

    $this->postJson('/admin-api/emails/sending', ['transport' => 'server', 'settings' => ['mail_address_korea' => 'x']])->assertStatus(422);
    $this->postJson('/admin-api/emails/branding', ['settings' => ['mail_transport' => 'log']])->assertStatus(422);
    $this->postJson('/admin-api/emails/branding', ['settings' => ['mail_support_whatsapp' => 'javascript:alert(1)']])->assertStatus(422);

    rkFresh();
    expect(app(MailSettings::class)->transport())->toBe('server');
});

it('reports the test-send through the chosen transport, with the real words', function () {
    rkAs('owner');

    $r = $this->postJson('/admin-api/emails/test', ['to' => 'owner@example.com'])->assertOk();

    // Server mail is the default; the suite's guard stands in for mail().
    expect($r->json('ok'))->toBeTrue()
        ->and($r->json('transport'))->toBe('server')
        ->and($r->json('message'))->toContain('owner@example.com');

    $this->postJson('/admin-api/emails/test', ['to' => 'not an address'])->assertStatus(422);
});

/* =============================================================== capabilities */

it('maps every Emails endpoint to an owner-only capability, and refuses the other roles', function () {
    EmailsAdminRoutes::wire(app());

    $routes = EmailsAdminRoutes::registered();
    expect($routes)->toHaveCount(6);

    foreach ($routes as $route) {
        $cap = AdminCapabilities::for($route);

        expect($cap)->toBeIn(['emails.view', 'emails.manage'], $route->uri() . ' has no Emails capability')
            // Nothing in this file is under the unauthenticated /api/*.
            ->and(str_starts_with($route->uri(), 'admin-api/emails/'))->toBeTrue();
    }

    expect(AdminCapabilities::forPath('GET', 'admin-api/emails/overview'))->toBe('emails.view')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/emails/test'))->toBe('emails.manage')
        ->and(AdminCapabilities::CAPABILITIES['emails.view'])->toBe(['owner'])
        ->and(AdminCapabilities::CAPABILITIES['emails.manage'])->toBe(['owner'])
        // The same people who could always reach Store → Mail.
        ->and(AdminCapabilities::CAPABILITIES['store.settings'])->toBe(['owner']);

    foreach (['manager', 'support', 'editor'] as $role) {
        $this->actingAs(rkAdmin($role), 'admin');

        $this->getJson('/admin-api/emails/overview')->assertForbidden();
        $this->getJson('/admin-api/emails/sending')->assertForbidden();
        $this->postJson('/admin-api/emails/sending', ['transport' => 'gmail'])->assertForbidden();
        $this->postJson('/admin-api/emails/test', ['to' => 'x@example.com'])->assertForbidden();
        $this->getJson('/admin-api/emails/branding')->assertForbidden();
        $this->postJson('/admin-api/emails/branding', ['settings' => ['mail_address_korea' => 'x']])->assertForbidden();
    }

    rkFresh();
    expect(app(MailSettings::class)->transport())->toBe('server')
        ->and(app(MailSettings::class)->get('mail_address_korea'))->toBe('');

    $this->actingAs(rkAdmin('owner'), 'admin');
    $this->getJson('/admin-api/emails/overview')->assertOk()->assertJsonPath('transport.key', 'server');
});

/* =================================================== contact details & footer */

it('ships the support email as info@kbeautybliss.com, and lets the owner change or clear it', function () {
    $email = fn () => collect(app(EmailBranding::class)->support())->firstWhere('kind', 'email')['value'] ?? null;

    expect($email())->toBe('info@kbeautybliss.com');

    rkSave(['mail_support_email' => 'care@kbeautybliss.com']);
    expect($email())->toBe('care@kbeautybliss.com');

    rkSave(['mail_support_email' => '', 'mail_reply_to' => 'reply@kbeautybliss.com']);
    expect($email())->toBe('reply@kbeautybliss.com');
});

it('prints the Dubai and Korea addresses at the foot of a customer email, escaped, and not on the merchant alert', function () {
    rkAs('owner');

    $this->postJson('/admin-api/emails/branding', ['settings' => [
        'mail_address_dubai' => "Office 12, Business Bay\nDubai, UAE",
        'mail_address_korea' => "<script>alert(1)</script>\nSeoul",
        'mail_support_whatsapp' => '+971 58 505 2611',
    ]])->assertOk()->assertJsonPath('values.mail_address_dubai', 'Office 12, Business Bay | Dubai, UAE');
    rkFresh();

    $order = rkOrder();
    $html = (string) (new OrderConfirmation($order))->render();
    $text = rkText(new OrderConfirmation($order));
    $alert = (string) (new NewOrderAlert($order))->render();

    expect($html)->toContain('Office 12, Business Bay<br>Dubai, UAE')
        ->and($html)->toContain('>Korea</span>')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;<br>Seoul')
        ->and($html)->not->toContain('<script>alert(1)')
        ->and($text)->toContain("Dubai\nOffice 12, Business Bay\nDubai, UAE")
        ->and($text)->toContain("Korea\n<script>alert(1)</script>\nSeoul")   // text/plain: no markup context
        ->and($alert)->not->toContain('Business Bay');

    /*
     * MUTATION: print `{!! $line !!}` in emails/partials/addresses.blade.php
     * and the raw <script> reaches the HTML part — red.
     */
});

it('takes the Dubai address from Store → Business Details until one is typed, and invents nothing for Korea', function () {
    $settings = app(SettingsService::class);
    $settings->set('store_street', 'Shop 4, Al Wasl Road');
    $settings->set('store_locality', 'Dubai');
    $settings->set('store_region', 'Dubai');
    $settings->set('store_country', 'AE');
    rkFresh();

    $addresses = app(EmailBranding::class)->addresses();

    expect($addresses)->toBe([
        ['place' => 'dubai', 'lines' => ['Shop 4, Al Wasl Road', 'Dubai', 'United Arab Emirates']],
    ]);
});

it('leaves an order email byte-identical when no address is saved anywhere', function () {
    $html = (string) (new OrderConfirmation(rkOrder()))->render();

    // The footer sentence ends its own line, exactly as before this package.
    expect($html)->toMatch('/using this email address\.\n\s*<\/td>/')
        ->and($html)->not->toContain('>Dubai</span>');
});

/* ===================================================================== wiring */

it('ships a cache-clearing migration with the routes', function () {
    expect(glob(base_path('database/migrations/*_clear_caches_emails_menu.php')))->toHaveCount(1);
});

it('is wired into the console exactly once', function () {
    /*
     * THE FINISHED STATE, pinned per CLAUDE.md: one require, one include, one
     * NAV group, the old Store → Mail row moved into it. Red in the lane's
     * worktree until the integrator adds the lines the lane report gives;
     * green after, and red again if anything wires it twice or unwires it.
     */
    $web = (string) file_get_contents(base_path('routes/web.php'));
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect(substr_count($web, "require __DIR__.'/emails-admin.php';"))->toBe(1)
        ->and(substr_count($app, "@include('admin.partials.emails-screens')"))->toBe(1)
        ->and(substr_count($app, "{sec:'Emails'"))->toBe(1)
        ->and($app)->toContain("'emails':['Emails','Overview']")
        ->and($app)->toContain("'emails-sending':['Emails','Sending & delivery']")
        ->and($app)->toContain("'emails-branding':['Emails','Design & branding']")
        ->and($app)->toContain("'emails-sent':['Emails','Sent mail']");
});

it('escapes everything the server hands the screens', function () {
    $screen = (string) file_get_contents(resource_path('views/admin/partials/emails-screens.blade.php'));

    // The app password box never carries a value attribute.
    expect($screen)->toContain('id="eml_mail_gmail_password" data-eml-key="mail_gmail_password" placeholder=')
        ->and($screen)->not->toMatch('/eml_mail_gmail_password[^>]*value=/')
        // Footer preview lines and the test-send's words go through esc().
        ->and($screen)->toContain('a.lines.map(esc)')
        ->and($screen)->toContain("esc(t.message || '')")
        // No layout-measuring JavaScript (CLAUDE.md rule 4).
        ->and($screen)->not->toContain('getBoundingClientRect')
        ->and($screen)->not->toContain('offsetHeight');
});
