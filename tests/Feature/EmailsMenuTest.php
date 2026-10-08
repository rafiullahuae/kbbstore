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
    // Nine from routes/emails-admin.php; Lane EK's Customer emails and
    // template editor (routes/emails-templates-admin.php) add theirs under
    // the same prefix once wired, each mapped below like these.
    expect(count($routes))->toBeGreaterThanOrEqual(9);

    foreach ($routes as $route) {
        $cap = AdminCapabilities::for($route);

        expect($cap)->toBeIn(['emails.view', 'emails.manage', 'emails.test'], $route->uri() . ' has no Emails capability')
            // Nothing in this file is under the unauthenticated /api/*.
            ->and(str_starts_with($route->uri(), 'admin-api/emails/'))->toBeTrue();
    }

    expect(AdminCapabilities::forPath('GET', 'admin-api/emails/overview'))->toBe('emails.view')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/emails/test'))->toBe('emails.manage')
        // docs/EMAILS-PLAN.md §6, as the integrator set it (Lane EK): a
        // manager may READ the overview, the customer-email list and sent
        // mail; changing anything stays the owner's.
        ->and(AdminCapabilities::CAPABILITIES['emails.view'])->toBe(['owner', 'manager'])
        ->and(AdminCapabilities::CAPABILITIES['emails.manage'])->toBe(['owner'])
        // The same people who could always reach Store → Mail.
        ->and(AdminCapabilities::CAPABILITIES['store.settings'])->toBe(['owner']);

    foreach (['manager', 'support', 'editor'] as $role) {
        $this->actingAs(rkAdmin($role), 'admin');

        $this->getJson('/admin-api/emails/overview')->assertStatus($role === 'manager' ? 200 : 403);
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

    // Look A's footer (Lane EM): a pin, the place in small caps, the lines.
    expect($html)->toMatch('/Office 12, Business Bay<br>\s*Dubai, UAE/')
        ->and($html)->toContain('>Korea</div>')
        ->and($html)->toMatch('/&lt;script&gt;alert\(1\)&lt;\/script&gt;<br>\s*Seoul/')
        ->and($html)->not->toContain('<script>alert(1)')
        ->and($text)->toContain("Dubai\nOffice 12, Business Bay\nDubai, UAE")
        ->and($text)->toContain("Korea\n<script>alert(1)</script>\nSeoul")   // text/plain: no markup context
        ->and($alert)->not->toContain('Business Bay');

    /*
     * MUTATION: print `{!! $line !!}` in emails/partials/addresses.blade.php
     * and the raw <script> reaches the HTML part — red.
     */
});

it('hides each footer address while its own box is empty, and never borrows one from Business Details', function () {
    /*
     * THE DEFECT (Lane EM): EmailBranding::addresses() fell back to Store →
     * Business Details' street and city when the Dubai box was blank, so a shop
     * that had filled in its registered address -- which the LocalBusiness
     * schema needs anyway -- printed it under a "Dubai" heading on every
     * receipt, as if the owner had typed it there. The owner: the Dubai and
     * Korea addresses "are not known yet ... hide each one while it is empty.
     * Never invent an address."
     *
     * MUTATION: restore `if ($dubai === []) { $dubai = $this->storeAddressLines(); }`
     * and the first expectation is red with the Al Wasl Road address in it.
     */
    $settings = app(SettingsService::class);
    $settings->set('store_street', 'Shop 4, Al Wasl Road');
    $settings->set('store_locality', 'Dubai');
    $settings->set('store_region', 'Dubai');
    $settings->set('store_country', 'AE');
    rkFresh();

    expect(app(EmailBranding::class)->addresses())->toBe([]);

    $html = (string) (new OrderConfirmation(rkOrder()))->render();
    expect($html)->not->toContain('Al Wasl Road');

    // Korea alone prints alone.
    rkSave(['mail_address_korea' => 'Seoul office']);
    expect(app(EmailBranding::class)->addresses())->toBe([
        ['place' => 'korea', 'lines' => ['Seoul office']],
    ]);
});

it('draws no address row at all when no address is saved anywhere', function () {
    $html = (string) (new OrderConfirmation(rkOrder()))->render();

    // Neither the pin nor a place name: an empty footer row is not printed.
    expect($html)->not->toContain('&#128205;')
        ->and($html)->not->toContain('>Dubai</div>');
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
        // Lane AP: the sidebar is App\Support\AdminNav's (server-rendered), not a NAV literal.
        ->and(count(array_filter(\App\Support\AdminNav::GROUPS, fn ($g) => $g['sec'] === 'Emails')))->toBe(1)
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
        // The test-send's words go through esc(), and the preview (the real
        // order email) sits in an iframe whose script cannot run.
        ->and($screen)->toContain("esc(t.message || '')")
        ->and($screen)->toContain('sandbox="" src="')
        // No layout-measuring JavaScript (CLAUDE.md rule 4).
        ->and($screen)->not->toContain('getBoundingClientRect')
        ->and($screen)->not->toContain('offsetHeight');
});

/* ============================================================ fonts & colours */

it('ships the email font as Outfit with the brand colours, and a stable public font file', function () {
    $look = app(App\Services\Mail\EmailLook::class);

    expect($look->values())->toBe([
        'email_font_heading' => 'outfit',
        'email_font_body' => 'outfit',
        'email_accent' => '#e0567b',
        'email_button' => '#c13e63',
        'email_background' => '#fff8f5',
        'email_text' => '#2a2228',
        'email_logo' => '',
    ]);

    $present = app(EmailBranding::class)->look();

    expect($present['headingFont'])->toStartWith("'Outfit',")
        ->and($present['bodyFont'])->toContain('sans-serif')
        ->and($present['fontFaceCss'])->toContain('/mail/font/outfit-latin.woff2');

    /*
     * THE URL IS A ROUTE, NOT A FILE UNDER public/ (Lane EM). It used to name
     * public/fonts/email/outfit-latin.woff2, which the updater refuses to ship
     * (only public/build/ is allowed) and which would sit outside the live web
     * root even if it did: every email asked for a font that 404s on the shop.
     *
     * MUTATION: set EmailLook::FONT_PATH back to '/fonts/email/outfit-latin.woff2'
     * and the request below is a 404 -- red.
     */
    Illuminate\Support\Facades\Route::middleware('web')->group(base_path('routes/mail-kit.php'));
    $path = (string) parse_url(App\Services\Mail\EmailLook::FONT_PATH, PHP_URL_PATH);
    $res = $this->get($path);
    $res->assertOk();
    expect($res->headers->get('Content-Type'))->toBe('font/woff2')
        ->and(md5((string) file_get_contents($res->baseResponse->getFile()->getPathname())))
        ->toBe(md5_file(resource_path('fonts/outfit/outfit-latin.woff2')))
        ->and(is_dir(base_path('public/fonts/email')))->toBeFalse();
});

it('stores a font only from its own list and a colour only as #rrggbb', function () {
    rkAs('owner');

    $this->postJson('/admin-api/emails/branding', ['settings' => ['email_font_heading' => 'Comic Sans']])->assertStatus(422);
    $this->postJson('/admin-api/emails/branding', ['settings' => ['email_button' => 'red;background:url(x)']])->assertStatus(422);
    $this->postJson('/admin-api/emails/branding', ['settings' => ['email_accent' => '#12345']])->assertStatus(422);

    rkFresh();
    expect(app(App\Services\Mail\EmailLook::class)->values()['email_font_heading'])->toBe('outfit');

    $this->postJson('/admin-api/emails/branding', ['settings' => [
        'email_font_heading' => 'georgia', 'email_font_body' => 'system', 'email_button' => '#112233',
    ]])->assertOk()
        ->assertJsonPath('look.email_font_heading', 'georgia')
        ->assertJsonPath('look.email_button', '#112233');

    rkFresh();
    $present = app(EmailBranding::class)->look();
    expect($present['headingFont'])->toStartWith('Georgia')
        ->and($present['button'])->toBe('#112233')
        // Neither font is Outfit any more, so no @font-face is offered.
        ->and($present['fontFaceCss'])->toBe('');

    // A row written by anything else is re-checked on the way out.
    app(SettingsService::class)->set('email_accent', 'javascript:alert(1)');
    rkFresh();
    expect(app(EmailBranding::class)->look()['accent'])->toBe('#e0567b');

    /*
     * MUTATION: return $value unchecked from EmailLook::clean() and the
     * 'red;background:url(x)' save answers 200 — a style-attribute injection
     * waiting for the restyle to print it.
     */
});

it('keeps phone and email out of the bottom footer data: addresses, legal pages and unsubscribe only', function () {
    rkSave(['mail_address_korea' => 'Seoul office', 'mail_support_whatsapp' => '+971 58 505 2611']);

    $footer = app(EmailBranding::class)->footer();

    // "don't include phone email, what is repeated in the questions? box.
    //  just keep address, terms pages, un-subscribe option etc."
    expect(array_keys($footer))->toBe(['addresses', 'links', 'unsubscribe'])
        // NO returns link: the shop does not offer returns (the owner, Lane EM).
        // MUTATION: put 'returns' back in EmailBranding::FOOTER_LINKS -- red.
        ->and(array_column($footer['links'], 'kind'))->toBe(['terms', 'privacy'])
        ->and($footer['links'][0]['url'])->toEndWith('/terms-and-conditions/')
        ->and(json_encode($footer))->not->toContain('refund_returns')
        ->and($footer['unsubscribe'])->toBeNull()
        ->and(json_encode($footer))->not->toContain('wa.me')
        ->and(json_encode($footer))->not->toContain('mailto:')
        ->and(json_encode($footer))->toContain('Seoul office');

    // And every linked page is a real route on the shop.
    foreach (['/terms-and-conditions', '/privacy-policy'] as $path) {
        expect(collect(Illuminate\Support\Facades\Route::getRoutes()->getRoutes())->contains(fn ($r) => '/' . $r->uri() === $path))->toBeTrue($path);
    }
});

it('draws every email in Outfit, from the kit fixed font address', function () {
    /*
     * Lane RK stored the look for "the Look A restyle"; Lane EM is that
     * restyle. The shop's Outfit is the default and every email asks for it
     * at the route routes/mail-kit.php serves.
     */
    $html = (string) (new OrderConfirmation(rkOrder()))->render();

    expect($html)->toContain("font-family:'Outfit'")
        ->and($html)->toContain('@font-face')
        ->and($html)->toContain('/mail/font/outfit-latin.woff2');
});

/* ============================================= the approved mocks' extra fields */

it('takes an email logo only from the Media Library or https, and puts it in the email', function () {
    rkAs('owner');

    foreach (['javascript:alert(1)', '//evil.example/x.png', '/x.png" onerror="y', 'data:image/png;base64,AAAA'] as $bad) {
        $this->postJson('/admin-api/emails/branding', ['settings' => ['email_logo' => $bad]])->assertStatus(422);
    }

    $this->postJson('/admin-api/emails/branding', ['settings' => ['email_logo' => '/uploads/2026/10/kbb-logo.png']])->assertOk();
    rkFresh();

    /*
     * (Lane EM, 8 October) The logo has to EXIST to be printed: an email
     * picture is a file in this web root on the shop's public https address
     * (Services\Mail\Kit\MailImage), and a logo with no file behind it prints
     * the wordmark rather than a broken frame (EmailPicturesTest). So the
     * Media Library file is put where the Media Library would have put it.
     */
    $pub = kbbTempDir().'/rk-logo-'.bin2hex(random_bytes(4));
    @mkdir($pub.'/uploads/2026/10', 0777, true);
    $im = imagecreatetruecolor(340, 80);
    imagepng($im, $pub.'/uploads/2026/10/kbb-logo.png');
    imagedestroy($im);
    app()->usePublicPath($pub);
    config(['app.url' => 'https://extrabeauty.ae']);

    expect(app(EmailBranding::class)->logoUrl())->toEndWith('/uploads/2026/10/kbb-logo.png')
        ->and((string) (new OrderConfirmation(rkOrder()))->render())->toContain('https://extrabeauty.ae/uploads/2026/10/kbb-logo.png');

    @unlink($pub.'/uploads/2026/10/kbb-logo.png');
    @rmdir($pub.'/uploads/2026/10');
    @rmdir($pub.'/uploads/2026');
    @rmdir($pub.'/uploads');
    @rmdir($pub);

    /*
     * MUTATION: drop the `//` and quote checks in EmailLook::clean() for LOGO
     * and '//evil.example/x.png' saves — a scheme-relative src in every email.
     */
});

it('sends a real customer email filled with the latest order to the typed address only', function () {
    rkAs('owner');
    $order = rkOrder();

    // The test-send rebuilds the store's own mailer (server mail, the
    // default), whose test-suite guard records what would have reached mail().
    ServerMailTransport::$lastTestDelivery = null;

    $r = $this->postJson('/admin-api/emails/test', ['to' => 'owner@example.com', 'which' => 'order_shipped'])->assertOk();
    expect($r->json('ok'))->toBeTrue();

    $sent = ServerMailTransport::$lastTestDelivery;

    // The owner, never the customer on the order.
    expect($sent)->not->toBeNull()
        ->and($sent['to'])->toContain('owner@example.com')
        ->and($sent['to'])->not->toContain($order->email)
        ->and($sent['headers'] . $sent['subject'])->not->toContain($order->email)
        ->and($sent['subject'])->toContain('KBB-RK-1');

    $this->postJson('/admin-api/emails/test', ['to' => 'owner@example.com', 'which' => 'everything'])->assertStatus(422);
});

it('runs the domain check read-only from public DNS, and keeps the last answer', function () {
    app()->bind(App\Services\Mail\DomainCheck::class, fn () => new class(app(MailSettings::class), app(SettingsService::class)) extends App\Services\Mail\DomainCheck {
        protected function txt(string $host): array
        {
            return [
                'kbeautybliss.com' => ['v=spf1 include:_spf.google.com ~all', 'google-site-verification=x'],
                '_dmarc.kbeautybliss.com' => ['v=DMARC1; p=none'],
            ][$host] ?? [];
        }
    });

    // A finished Google Workspace setup: unfinished, the mail leaves through this server instead.
    rkSave(['mail_transport' => 'gmail', 'mail_from_address' => 'info@kbeautybliss.com', 'mail_gmail_username' => 'info@kbeautybliss.com', 'mail_gmail_password' => 'abcd efgh ijkl mnop']);
    rkAs('owner');

    $r = $this->postJson('/admin-api/emails/dns')->assertOk();
    $byRecord = collect($r->json('last.records'))->keyBy('record');

    expect($r->json('domain'))->toBe('kbeautybliss.com')
        ->and($byRecord['SPF']['status'])->toBe('ok')
        ->and($byRecord['DKIM']['status'])->toBe('missing')        // google._domainkey has no key
        ->and($byRecord['DKIM']['host'])->toBe('google._domainkey.kbeautybliss.com')
        ->and($byRecord['DMARC']['status'])->toBe('ok');

    rkFresh();
    expect($this->getJson('/admin-api/emails/overview')->json('dns.records'))->toHaveCount(3)
        ->and($this->getJson('/admin-api/emails/dns')->json('last.domain'))->toBe('kbeautybliss.com');
});

it('serves the preview as the real order email, sandboxed, to the owner only', function () {
    rkOrder();
    rkAs('owner');

    $r = $this->get('/admin-api/emails/preview')->assertOk();

    expect((string) $r->headers->get('Content-Type'))->toContain('text/html')
        ->and((string) $r->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($r->getContent())->toContain('KBB-RK-1');

    /*
     * SANDBOXED BY THE FRAME, not by a header (Lane EM): this codebase ships
     * no enforcing content-security header anywhere (SecurityCspTest), so the
     * screen's iframe carries sandbox="" -- no script, no forms, no origin.
     * MUTATION: drop sandbox="" from the iframe in emails-screens and red.
     */
    expect((string) file_get_contents(resource_path('views/admin/partials/emails-screens.blade.php')))
        ->toMatch('/<iframe class="eml-frame[^>]*sandbox=""/');

    // Every new endpoint fails closed for anyone but the owner.
    $this->actingAs(rkAdmin('manager'), 'admin');
    $this->get('/admin-api/emails/preview')->assertForbidden();
    $this->getJson('/admin-api/emails/dns')->assertForbidden();
    $this->postJson('/admin-api/emails/dns')->assertForbidden();
});

it('says SPF is a problem when the shop sends through this server but the domain lets only Google send', function () {
    /*
     * THE DEFECT (2.60.376): "emails are sending fine. but all are going to
     * spam." kbeautybliss.com's SPF lets only Google send for it, the shop was
     * sending through this server's mail, so every message failed SPF and went
     * to spam -- and the Domain check said SPF "ok", because it only checked
     * that a record existed. MUTATION: delete the onlyGoogle() arm in
     * DomainCheck::run() and the first expectation is red.
     */
    app()->bind(App\Services\Mail\DomainCheck::class, fn () => new class(app(MailSettings::class), app(SettingsService::class)) extends App\Services\Mail\DomainCheck {
        protected function txt(string $host): array
        {
            return ['kbeautybliss.com' => ['v=spf1 include:_spf.google.com ~all']][$host] ?? [];
        }
    });

    rkSave(['mail_transport' => 'server', 'mail_from_address' => 'info@kbeautybliss.com']);
    rkAs('owner');

    $spf = collect($this->postJson('/admin-api/emails/dns')->assertOk()->json('last.records'))->keyBy('record')['SPF'];

    expect($spf['status'])->toBe('problem')
        ->and($spf['hint'])->toContain('Google Workspace (Gmail SMTP)');

    // The same record is right for Google Workspace, and says so.
    rkSave(['mail_transport' => 'gmail', 'mail_gmail_username' => 'info@kbeautybliss.com', 'mail_gmail_password' => 'abcd efgh ijkl mnop']);
    $spf = collect($this->postJson('/admin-api/emails/dns')->assertOk()->json('last.records'))->keyBy('record')['SPF'];
    expect($spf['status'])->toBe('ok');
});

it('knows an SPF record that lets only Google send from one that also lets this server send', function () {
    expect(App\Services\Mail\DomainCheck::onlyGoogle('v=spf1 include:_spf.google.com ~all'))->toBeTrue()
        ->and(App\Services\Mail\DomainCheck::onlyGoogle('v=spf1 include:_spf.google.com -all'))->toBeTrue()
        ->and(App\Services\Mail\DomainCheck::onlyGoogle('v=spf1 ip4:134.209.147.13 include:_spf.google.com ~all'))->toBeFalse()
        ->and(App\Services\Mail\DomainCheck::onlyGoogle('v=spf1 a mx include:_spf.google.com ~all'))->toBeFalse()
        ->and(App\Services\Mail\DomainCheck::onlyGoogle('v=spf1 include:spf.protection.outlook.com ~all'))->toBeFalse();
});

it('greets Google as the From domain, not as the shop host', function () {
    /*
     * 2.60.376, "all are going to spam": the shop runs on extrabeauty.ae and
     * mails as info@kbeautybliss.com, and the EHLO name was the shop host, so
     * every message carried a second, unrelated domain in its Received line.
     * MUTATION: put parse_url(config('app.url')) back as local_domain and this
     * is red.
     */
    config(['app.url' => 'https://extrabeauty.ae']);
    rkSave([
        'mail_transport' => MailSettings::TRANSPORT_GMAIL,
        'mail_gmail_username' => 'info@kbeautybliss.com',
        'mail_gmail_password' => 'abcd efgh ijkl mnop',
        'mail_from_address' => 'info@kbeautybliss.com',
    ]);

    expect(app(MailConfigurator::class)->mailerConfig()['local_domain'])->toBe('kbeautybliss.com');
});

it('flags SPF when Google Workspace is chosen but unfinished, because the mail then leaves through this server', function () {
    /*
     * MailConfigurator falls back to this server's mail when the Google
     * username or App Password is missing, so orders still email -- and every
     * one of those fails a Google-only SPF. The check must say so rather than
     * "ok". MUTATION: make sendsThroughServer() look at transport() alone and
     * this is red.
     */
    app()->bind(App\Services\Mail\DomainCheck::class, fn () => new class(app(MailSettings::class), app(SettingsService::class)) extends App\Services\Mail\DomainCheck {
        protected function txt(string $host): array
        {
            return ['kbeautybliss.com' => ['v=spf1 include:_spf.google.com ~all']][$host] ?? [];
        }
    });

    rkSave(['mail_transport' => 'gmail', 'mail_gmail_username' => 'info@kbeautybliss.com', 'mail_from_address' => 'info@kbeautybliss.com']);
    rkAs('owner');

    $spf = collect($this->postJson('/admin-api/emails/dns')->assertOk()->json('last.records'))->keyBy('record')['SPF'];

    expect($spf['status'])->toBe('problem');
});
