<?php

declare(strict_types=1);

/*
 * The contact page's cards, social icons, inquiry form and Store → Inquiries.
 * (Lane CT)
 *
 * The owner, 9 October: "the contact page should have proper sections for
 * whatsapp, contact, email, and a inquiry form nicely design. and our social
 * media icons." Each case below names the defect it would have caught on the
 * shop and the one-line change that turns it red (MUTATION).
 */

use App\Mail\ContactInquiryAlert;
use App\Models\AdminUser;
use App\Models\ContactInquiry;
use App\Models\Setting;
use App\Models\Translation;
use App\Services\SettingsService;
use App\Services\SiteFooter;
use App\Services\Translation\ArabicInterfaceDrafts;
use App\Services\Translation\InterfaceStrings;
use App\Services\Translation\TranslationStore;
use App\Support\ContactPage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\ArabicShop;
use Tests\Support\ContactInquiryRoutes;

function ctForget(): void
{
    Setting::flushMap();
    SettingsService::forgetMemo();
    app()->forgetScopedInstances();
}

function ctSet(string $key, mixed $value): void
{
    app(SettingsService::class)->set($key, $value);
    ctForget();
}

function ctcAdmin(string $role): AdminUser
{
    return AdminUser::create([
        'name' => 'CT '.$role, 'email' => "ct-{$role}@example.test",
        'password' => 'password-long-enough', 'role' => $role,
    ]);
}

/** A valid post, with a stamp old enough to pass the minimum fill time. */
function ctPost(array $over = []): array
{
    return array_replace([
        'name' => 'Aisha Rahman',
        'email' => 'aisha@example.com',
        'phone' => '+971 50 123 4567',
        'topic' => '1',
        'message' => 'Is this sunscreen right for oily skin in summer?',
        'website' => '',
        'ts' => ContactPage::stamp(now()->getTimestamp() - 30),
    ], $over);
}

function ctContact(string $path = '/contact-us/'): string
{
    ctForget();

    return (string) test()->get($path)->assertOk()->getContent();
}

beforeEach(function () {
    ContactInquiryRoutes::wire($this->app);
    Cache::flush();
    RateLimiter::clear('contact-inquiry:'.sha1('127.0.0.1'));
    ctSet('support_email', 'info@kbeautybliss.com');
    ctSet('support_phone', '+971 58 505 2611');
    ctSet('brand_whatsapp', '+971585052611');
    $this->withoutDefer();
});

/* ══════════════════════════════ the page ══════════════════════════════ */

it('draws the WhatsApp, Instagram and email cards, in that order, from the settings the shop already has', function () {
    /*
     * The defect: a contact page that invents a number, or prints one the
     * header does not dial. MUTATION: build the WhatsApp href from
     * SupportContact::phone() instead of whatsappDigits() -> red (a spaced
     * number in a wa.me link).
     */
    $html = ctContact();

    preg_match_all('/class="ctc-card" data-ct="([a-z]+)"/', $html, $m);
    expect($m[1])->toBe(['wa', 'ig', 'email'])
        ->and($html)->toContain('href="https://wa.me/971585052611"')
        ->and($html)->toContain('href="https://ig.me/m/kbeauty.bliss"')
        ->and($html)->toContain('@kbeauty.bliss')
        ->and($html)->toContain('href="mailto:info@kbeautybliss.com"');
});

it('ships the phone card off and says nothing about calls, but keeps the switch', function () {
    /*
     * The owner: "remove the call option, we don't receive calls, we provide
     * support on whatsapp, instagram and email." MUTATION: take 'phone' out of
     * ContactPage::OFF_BY_DEFAULT -> a "Call us" card and a tel: link, red.
     */
    $html = ctContact();
    preg_match('#<main.*</main>#s', $html, $main);
    $body = strip_tags((string) preg_replace('#<(style|script)\b.*?</\1>#s', '', $main[0] ?? $html));

    expect($html)->not->toContain('data-ct="phone"')
        ->and($html)->not->toContain('href="tel:')
        ->and($body)->not->toMatch('/\b(call us|call now|phone(?! calls))\b/i')
        ->and($body)->toContain('We don’t take phone calls');

    // Switched back on by the owner, it draws again.
    expect(ContactPage::save(array_replace(ContactPage::defaults(), ['phone' => true, 'topics' => 'Other'])))->toBe([]);
    expect(ctContact())->toContain('href="tel:+971585052611"');
});

it('opens the footer’s Instagram profile as a direct message, and hides the card without one', function () {
    /*
     * MUTATION: return the profile URL instead of ig.me/m/<handle> in
     * ContactPage::instagram() -> red; drop the `$instagram !== null` check ->
     * an empty profile still draws a card, red.
     */
    expect(ContactPage::instagram('https://www.instagram.com/kbeauty.bliss/'))->toBe(['href' => 'https://ig.me/m/kbeauty.bliss', 'detail' => '@kbeauty.bliss'])
        ->and(ContactPage::instagram('https://instagram.com/some.shop'))->toBe(['href' => 'https://ig.me/m/some.shop', 'detail' => '@some.shop'])
        ->and(ContactPage::instagram('https://linktr.ee/kbb'))->toBe(['href' => 'https://linktr.ee/kbb', 'detail' => 'linktr.ee/kbb'])
        ->and(ContactPage::instagram('javascript:alert(1)'))->toBeNull()
        ->and(ContactPage::instagram('#'))->toBeNull()
        ->and(ContactPage::instagram(''))->toBeNull();

    ctSet('social_instagram', '');
    $html = ctContact();
    expect($html)->not->toContain('data-ct="ig"')->and($html)->toContain('data-ct="wa"');

    ctSet('social_instagram', 'javascript:alert(1)');
    expect(ctContact())->not->toContain('data-ct="ig"');

    ctSet('social_instagram', 'https://www.instagram.com/kbeauty.bliss/');
    expect(ContactPage::save(array_replace(ContactPage::defaults(), ['ig' => false, 'topics' => 'Other'])))->toBe([]);
    expect(ctContact())->not->toContain('data-ct="ig"');
});

it('leaves out a card whose value is empty, and a card switched off', function () {
    /*
     * The defect: an "Email" card with a blank address and a mailto: to
     * nowhere. MUTATION: drop `self::validEmail($email)` from the email card's
     * condition -> red.
     */
    ctSet('support_email', '');
    $html = ctContact();
    expect($html)->not->toContain('data-ct="email"')->and($html)->toContain('data-ct="wa"');

    ctSet('support_email', 'not an address');
    expect(ctContact())->not->toContain('mailto:');

    expect(ContactPage::save(['wa' => false, 'ig' => true, 'phone' => false, 'email' => true, 'socials' => true, 'hours' => true, 'form' => true, 'topics' => "Other"]))->toBe([]);
    ctSet('support_email', 'info@kbeautybliss.com');
    $html = ctContact();
    expect($html)->not->toContain('data-ct="wa"')->and($html)->toContain('data-ct="email"');
});

it('draws the footer’s own social profiles and icons, and none it has no address for', function () {
    /*
     * MUTATION: drop the `$row[2] !== ''` filter in SiteFooter::socials() ->
     * red (a YouTube icon with an empty href). And the icons are the footer's
     * constant, not a second copy: footer-bliss reads the same constant.
     */
    ctSet('social_youtube', '');
    ctSet('social_tiktok', 'javascript:alert(1)');
    $html = ctContact();
    preg_match('#<div class="ctc-soc"[^>]*>(.*?)</div>#s', $html, $m);

    expect($m[1] ?? '')->toContain('aria-label="Instagram"')
        ->and($m[1])->toContain('aria-label="Facebook"')
        ->and($m[1])->not->toContain('YouTube')
        ->and($m[1])->not->toContain('TikTok')
        ->and($m[1])->not->toContain('javascript:')
        ->and($m[1])->toContain(SiteFooter::SOCIAL_ICONS['instagram']);

    expect((string) file_get_contents(resource_path('views/partials/footer-bliss.blade.php')))
        ->toContain('$kftIcons = \App\Services\SiteFooter::SOCIAL_ICONS;')
        ->not->toContain('<rect x="3" y="3" width="18" height="18" rx="5"/>');
});

it('shows the opening hours only when the business screen holds valid ones', function () {
    // MUTATION: drop the OpeningHours::spec() check -> red on the bad text.
    ctSet('store_hours', "Mon-Sat 10:00-22:00\nSun 12:00-20:00");
    expect(ctContact())->toContain('<li>Mon-Sat 10:00-22:00</li>');

    ctSet('store_hours', 'whenever we feel like it');
    expect(ctContact())->not->toContain('<ul class="ctc-hours"');
});

it('changes no other page: no card, no form, no style and no script', function () {
    /*
     * The owner's rule 1. MUTATION: pass ContactPage::view() for every slug in
     * PageController::show() -> red here and in StorefrontEnglishUnchangedTest.
     */
    $html = ctContact('/privacy-policy/');
    expect($html)->not->toContain('ctc-')->and($html)->not->toContain('.ctc{');

    // The contact page itself adds no script: the same external scripts as a
    // sibling content page, and no inline one of its own.
    $contact = ctContact();
    $src = static function (string $h): array {
        preg_match_all('#<script[^>]*\ssrc="([^"]+)"#', $h, $m);

        return $m[1];
    };
    expect($src($contact))->toBe($src($html))
        ->and(preg_match('#<script(?![^>]*\ssrc=)[^>]*>[^<]*ctc#', $contact))->toBe(0)
        ->and($contact)->toContain('<style>'."\n".'.ctc{');
});

it('costs the contact page no query the sibling content page does not make', function () {
    /*
     * Flat: the cards, icons, hours and form come off the request's settings
     * memo. MUTATION: read `contact_page` with Setting::query() in
     * ContactPage::config() -> one more query, red.
     */
    $count = static function (string $path): int {
        ctForget();
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->get($path)->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $count('/contact-us/');
    expect($count('/contact-us/'))->toBe($count('/privacy-policy/'));
});

/* ══════════════════════════════ the form ══════════════════════════════ */

it('stores a valid inquiry with the topic it chose and the page language', function () {
    // MUTATION: store $data['topic'] (the index) instead of the topic line -> red.
    $this->post('/contact-us/send', ctPost())->assertRedirect()->assertSessionHas('ctc_sent', true);

    $row = ContactInquiry::query()->sole();
    expect($row->name)->toBe('Aisha Rahman')
        ->and($row->email)->toBe('aisha@example.com')
        ->and($row->phone)->toBe('+971 50 123 4567')
        ->and($row->topic)->toBe('Product advice')
        ->and($row->locale)->toBe('en')
        ->and($row->read_at)->toBeNull();

    // The thank-you, drawn with focus on it, and no fragment in the redirect
    // (a fragment makes the browser skip autofocus).
    $r = $this->post('/contact-us/send', ctPost(['email' => 'b@example.com']));
    expect($r->headers->get('Location'))->not->toContain('#');
    $html = (string) $this->get($r->headers->get('Location'))->getContent();
    expect($html)->toContain('class="ctc-status ok" role="status" tabindex="-1" autofocus');
});

it('refuses each invalid field with its own message beside it, and stores nothing', function () {
    /*
     * MUTATION: remove 'max:'.$max['message'] from the rule -> the 5,001
     * character message is stored, red.
     */
    $r = $this->post('/contact-us/send', ctPost(['name' => '', 'email' => 'nope', 'topic' => '9', 'message' => 'Hi', 'phone' => 'call me']));
    $r->assertRedirect()->assertSessionHasErrors(['name', 'email', 'topic', 'message', 'phone']);
    expect(ContactInquiry::query()->count())->toBe(0);

    $this->post('/contact-us/send', ctPost(['message' => str_repeat('a', 5001)]))->assertSessionHasErrors(['message']);
    $this->post('/contact-us/send', ctPost(['name' => str_repeat('a', 121)]))->assertSessionHasErrors(['name']);
    $this->post('/contact-us/send', ctPost(['email' => ['x@example.com']]))->assertSessionHasErrors(['email']);
    expect(ContactInquiry::query()->count())->toBe(0);

    // Drawn: the first field in error carries autofocus and is described by its message.
    $html = (string) $this->followingRedirects()->post('/contact-us/send', ctPost(['email' => 'nope']))->getContent();
    expect($html)->toContain('id="ctc-email" name="email"')
        ->and($html)->toMatch('#<input type="email" class="input-text" id="ctc-email"[^>]*aria-invalid="true" aria-describedby="ctc-email-err" autofocus>#')
        ->and($html)->toContain('<p class="form-row kbb-invalid"><span class="woocommerce-input-wrapper fld kbb-fl ico">')
        ->and($html)->toContain('<span class="ctc-err" id="ctc-email-err">'.e(__('store.contact.err_email')).'</span>')
        ->and($html)->toContain('value="Aisha Rahman"');
});

it('answers a filled honeypot exactly like a real message, and stores nothing', function () {
    // MUTATION: delete the honeypot check -> a row is stored, red.
    $this->post('/contact-us/send', ctPost(['website' => 'https://spam.example']))
        ->assertRedirect()->assertSessionHas('ctc_sent', true);

    expect(ContactInquiry::query()->count())->toBe(0);
});

it('refuses a form sent faster than a person can fill it, or with a forged stamp', function () {
    // MUTATION: change MIN_SECONDS to 0 -> the instant post is stored, red.
    $this->post('/contact-us/send', ctPost(['ts' => ContactPage::stamp(now()->getTimestamp())]))
        ->assertSessionHas('ctc_error', __('store.contact.err_fast'));
    $this->post('/contact-us/send', ctPost(['ts' => (now()->getTimestamp() - 60).'.0000000000aaaaaaaaaa']))
        ->assertSessionHas('ctc_error');
    $this->post('/contact-us/send', ctPost(['ts' => null]))->assertSessionHas('ctc_error');

    expect(ContactInquiry::query()->count())->toBe(0);
});

it('limits each address to five posts in ten minutes, with the message on the page', function () {
    // MUTATION: drop the tooManyAttempts() check -> the sixth is stored, red.
    for ($i = 0; $i < 5; $i++) {
        $this->post('/contact-us/send', ctPost(['email' => "p{$i}@example.com"]))->assertSessionHas('ctc_sent');
    }

    $this->post('/contact-us/send', ctPost(['email' => 'six@example.com']))
        ->assertRedirect()->assertSessionHas('ctc_error', __('store.contact.err_limit'));

    expect(ContactInquiry::query()->count())->toBe(5);
});

it('takes the form away, and its route, when the owner switches it off', function () {
    expect(ContactPage::save(array_replace(ContactPage::defaults(), ['form' => false])))->toBe([]);
    ctForget();

    expect(ctContact())->not->toContain('id="ctc-form"')->and(ctContact())->not->toContain('<form class="ctc-form"');
    $this->post('/contact-us/send', ctPost())->assertNotFound();
});

it('is a web route with CSRF, and not one the firewall exempts', function () {
    $route = app('router')->getRoutes()->match(\Illuminate\Http\Request::create('/contact-us/send', 'POST'));

    expect($route->gatherMiddleware())->toContain('web')
        ->and(\App\Services\Security\Firewall::exempt($route))->toBeFalse()
        ->and(str_starts_with($route->uri(), 'api/'))->toBeFalse();
});

/* ══════════════════════════════ the mail ══════════════════════════════ */

it('emails the owner, replying to the visitor, at the recipient the settings choose', function () {
    /*
     * MUTATION: send to SupportContact::email() regardless of the setting ->
     * the "own" case is red.
     */
    Mail::fake();
    $this->post('/contact-us/send', ctPost())->assertSessionHas('ctc_sent');

    Mail::assertSent(ContactInquiryAlert::class, function (ContactInquiryAlert $m) {
        return $m->hasTo('info@kbeautybliss.com') && $m->hasReplyTo('aisha@example.com');
    });
    expect(ContactInquiry::query()->sole()->mailed_at)->not->toBeNull();

    expect(ContactPage::save(array_replace(ContactPage::defaults(), ['recipient' => 'owner@example.com'])))->toBe([]);
    ctForget();
    $this->post('/contact-us/send', ctPost(['email' => 'c@example.com']));
    Mail::assertSent(ContactInquiryAlert::class, fn (ContactInquiryAlert $m) => $m->hasTo('owner@example.com'));
});

it('keeps the inquiry and thanks the visitor when the mail server fails', function () {
    /*
     * The defect this guards: a mail exception escaping the request, which is
     * a lost message AND an error page for the visitor. MUTATION: remove the
     * try/catch around the send in ContactInquiryController::alert() -> red.
     */
    Mail::shouldReceive('mailer')->andThrow(new \RuntimeException('smtp down'));

    $this->post('/contact-us/send', ctPost())->assertRedirect()->assertSessionHas('ctc_sent', true);

    $row = ContactInquiry::query()->sole();
    expect($row->message)->toContain('oily skin')->and($row->mailed_at)->toBeNull();
});

/* ═══════════════════════════════ escaping ═══════════════════════════════ */

it('never prints a visitor’s text unescaped: not on the page, not in the email', function () {
    /*
     * MUTATION: print {!! old('name') !!} in contact-hub-form, or {!! $q['message'] !!}
     * in admin/mail/contact-inquiry -> red.
     */
    $evil = '<script>alert(1)</script>';
    $html = (string) $this->followingRedirects()->post('/contact-us/send', ctPost(['name' => $evil, 'message' => 'x']))->getContent();
    expect($html)->not->toContain($evil)->and($html)->toContain(e($evil));

    $this->post('/contact-us/send', ctPost(['name' => $evil, 'message' => $evil.' and more words']));
    $mail = (new ContactInquiryAlert(ContactInquiry::query()->sole()))->render();
    expect($mail)->not->toContain($evil)->and($mail)->toContain(e($evil));

    // Every raw print in the three shop partials is a code constant.
    foreach (['contact-hub-cards', 'contact-hub-form'] as $view) {
        preg_match_all('/\{!!\s*(.*?)\s*!!\}/', (string) file_get_contents(resource_path("views/store/partials/{$view}.blade.php")), $m);
        foreach ($m[1] as $expr) {
            expect($expr)->toBeIn(["\$ctcCard['icon']", "\$ctcSoc['icon']", "\$hub['waIcon']", "\$ctcAttrs('name')", "\$ctcAttrs('email')", "\$ctcAttrs('phone')", "\$ctcAttrs('topic')", "\$ctcAttrs('message')"]);
        }
    }
    expect((string) file_get_contents(resource_path('views/admin/mail/contact-inquiry.blade.php')))->not->toContain('{!!');
});

/* ══════════════════════════════ the admin ══════════════════════════════ */

it('refuses Store → Inquiries to a role without the capability, and fails closed', function () {
    /*
     * MUTATION: map GET admin-api/inquiries to 'customers.view' -> the editor
     * still fails, but delete it from the map and support is refused too:
     * every rule here is named, not inherited.
     */
    ContactInquiry::create(['name' => 'A', 'email' => 'a@example.com', 'message' => 'Hello there friend']);
    $id = ContactInquiry::query()->value('id');

    $editor = ctcAdmin('editor');
    $this->actingAs($editor, 'admin')->getJson('/admin-api/inquiries')->assertForbidden();
    $this->actingAs($editor, 'admin')->deleteJson("/admin-api/inquiries/{$id}")->assertForbidden();
    $this->actingAs($editor, 'admin')->getJson('/admin-api/inquiries/settings')->assertForbidden();

    $support = ctcAdmin('support');
    $this->actingAs($support, 'admin')->getJson('/admin-api/inquiries')->assertOk();
    $this->actingAs($support, 'admin')->postJson("/admin-api/inquiries/{$id}/read", ['read' => true])->assertOk();
    $this->actingAs($support, 'admin')->deleteJson("/admin-api/inquiries/{$id}")->assertForbidden();
    $this->actingAs($support, 'admin')->postJson('/admin-api/inquiries/settings', ['config' => ContactPage::defaults()])->assertForbidden();

    expect(ContactInquiry::query()->count())->toBe(1);

    \Illuminate\Support\Facades\Auth::guard('admin')->logout();
    $this->getJson('/admin-api/inquiries')->assertUnauthorized();
});

it('lists newest first with the unread marked, marks read, and deletes', function () {
    // MUTATION: orderBy('id') ascending in index() -> red.
    foreach (['First', 'Second', 'Third'] as $n) {
        ContactInquiry::create(['name' => $n, 'email' => strtolower($n).'@example.com', 'message' => "Message from {$n}", 'ip' => '10.0.0.1']);
    }
    $owner = ctcAdmin('owner');

    $j = $this->actingAs($owner, 'admin')->getJson('/admin-api/inquiries')->assertOk()->json();
    expect(array_column($j['rows'], 'name'))->toBe(['Third', 'Second', 'First'])
        ->and($j['unread'])->toBe(3)
        ->and(array_keys($j['rows'][0]))->not->toContain('ip');

    $third = $j['rows'][0]['id'];
    $this->actingAs($owner, 'admin')->postJson("/admin-api/inquiries/{$third}/read", ['read' => true])->assertOk()->assertJson(['unread' => 2]);
    expect($this->actingAs($owner, 'admin')->getJson('/admin-api/inquiries?filter=unread')->json('rows.*.name'))->toBe(['Second', 'First']);

    $this->actingAs($owner, 'admin')->deleteJson("/admin-api/inquiries/{$third}")->assertOk();
    $this->actingAs($owner, 'admin')->deleteJson("/admin-api/inquiries/{$third}")->assertNotFound();
    expect(ContactInquiry::query()->pluck('name')->sort()->values()->all())->toBe(['First', 'Second']);
});

it('reads the inbox in the same two queries whether it holds three inquiries or forty', function () {
    // MUTATION: count unread per row (an N+1) in toAdmin() -> red.
    $owner = ctcAdmin('owner');
    $count = function () use ($owner): int {
        $this->actingAs($owner, 'admin');
        $this->getJson('/admin-api/inquiries'); // warm the session and role reads
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/admin-api/inquiries')->assertOk();
        $n = count(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'contact_inquiries')));
        DB::disableQueryLog();

        return $n;
    };

    for ($i = 0; $i < 3; $i++) {
        ContactInquiry::create(['name' => "N{$i}", 'email' => "n{$i}@example.com", 'message' => 'Hello there friend']);
    }
    $three = $count();
    for ($i = 3; $i < 40; $i++) {
        ContactInquiry::create(['name' => "N{$i}", 'email' => "n{$i}@example.com", 'message' => 'Hello there friend']);
    }

    expect($three)->toBe(2)->and($count())->toBe(2);
});

it('saves the contact page settings only when every value is one it can use', function () {
    // MUTATION: drop the recipient check in ContactPage::save() -> red.
    $owner = ctcAdmin('owner');
    $bad = $this->actingAs($owner, 'admin')->postJson('/admin-api/inquiries/settings', ['config' => array_replace(ContactPage::defaults(), ['recipient' => 'nobody'])]);
    $bad->assertStatus(422)->assertJsonPath('fields.recipient', 'The recipient is not an email address.');

    $this->actingAs($owner, 'admin')->postJson('/admin-api/inquiries/settings', ['config' => array_replace(ContactPage::defaults(), ['topics' => array_map(fn ($i) => "Topic {$i}", range(1, 9))])])->assertStatus(422);
    $this->actingAs($owner, 'admin')->postJson('/admin-api/inquiries/settings', ['config' => array_replace(ContactPage::defaults(), ['topics' => ['']])])->assertStatus(422);

    $ok = $this->actingAs($owner, 'admin')->postJson('/admin-api/inquiries/settings', ['config' => array_replace(ContactPage::defaults(), ['email' => false, 'topics' => ["Order question", "Collaboration\r\n"]])])->assertOk()->json();
    expect($ok['config']['email'])->toBeFalse()->and($ok['config']['topics'])->toBe(['Order question', 'Collaboration']);

    // A row written by hand is read back as something save() would have stored.
    ctSet(ContactPage::KEY, ['form' => 'yes', 'recipient' => 'javascript:x', 'topics' => [['nested'], str_repeat('a', 61)]]);
    expect(ContactPage::config())->toMatchArray(['form' => true, 'recipient' => '', 'topics' => array_values(ContactPage::DEFAULT_TOPICS)]);
});

it('ships every part on as the owner asked, the phone card off as he then asked, and the four sane topics', function () {
    expect(ContactPage::defaults())->toBe([
        'wa' => true, 'ig' => true, 'email' => true, 'phone' => false, 'socials' => true, 'hours' => true, 'form' => true,
        'recipient' => '', 'topics' => ['Order question', 'Product advice', 'Wholesale', 'Other'],
    ]);
});

/* ═══════════════════════════════ Arabic ═══════════════════════════════ */

it('labels the Arabic page through the shop’s translations, once the owner approves them', function () {
    /*
     * MUTATION: write a label as bare English in contact-hub-form -> the
     * published Arabic does not appear, red.
     */
    $keys = array_map(fn ($k) => 'store.'.$k, array_filter(array_keys(InterfaceStrings::all()['store']), fn ($k) => str_starts_with($k, 'contact.')));
    expect(count($keys))->toBeGreaterThan(30);
    foreach ($keys as $k) {
        expect(ArabicInterfaceDrafts::all())->toHaveKey($k);
    }

    ArabicShop::on();
    DB::table('translations')->where('locale', 'ar')->where('group', Translation::GROUP_UI)
        ->where('field', 'like', 'store.contact.%')->update(['status' => Translation::STATUS_PUBLISHED]);
    TranslationStore::flush();

    $html = ctContact('/ar/contact-us/');
    expect($html)->toContain('أرسلي لنا رسالة')
        ->and($html)->toContain('إرسال الرسالة')
        ->and($html)->toContain('<option value="0">سؤال عن طلب</option>')
        ->and($html)->toContain('action="/ar/contact-us/send"');

    $this->post('/ar/contact-us/send', ctPost())->assertRedirect('/ar/contact-us/');
    expect(ContactInquiry::query()->sole()->locale)->toBe('ar')
        ->and(ContactInquiry::query()->sole()->topic)->toBe('Product advice');
});

/* ═══════════════════════════ the page's own words ═══════════════════════════ */

it('replaces the seeded contact wording, keeps the old one as a hidden draft, and gives it back on rollback', function () {
    /*
     * The owner: "remove the foot line, bcz we have already have the contact
     * details on the contact page … write nicely this stuff." MUTATION: skip
     * the backup insert in up() -> down() has nothing to restore, red; drop the
     * "still holds our text" check in down() -> the owner's later edit is
     * overwritten, red.
     */
    $migration = require database_path('migrations/2027_10_15_120300_contact_us_support_copy.php');
    $page = fn () => DB::table('pages')->where('slug', 'contact-us')->first();
    $copy = $migration::copyHtml();

    // The migration set has already run: the new words are in, the old ones kept as a draft.
    expect($page()->content)->toBe($copy)->and($page()->title)->toBe('Contact Us');
    $backup = DB::table('pages')->where('slug', 'contact-us-previous')->first();
    expect($backup->status)->toBe('draft')
        ->and($backup->title)->toBe('Contact Us — previous wording')
        ->and($backup->content)->toContain('printed at the foot of every page');

    // The draft is never served.
    $this->get('/contact-us-previous/')->assertNotFound();

    // The shop shows the new words, none of the old, and no h1 of its own.
    $html = ctContact();
    expect($html)->toContain('WhatsApp</strong> is the quickest way to reach us')
        ->and($html)->not->toContain('foot of every page')
        ->and($html)->not->toContain('placeholder wording')
        ->and(substr_count($html, '<h1'))->toBe(1)
        ->and($copy)->not->toContain('<h1')
        ->and($copy)->not->toMatch('/\+?\d[\d ]{7,}/')
        ->and(\App\Support\RichText::clean($copy))->toBe($copy);

    // A re-run changes nothing and keeps the one backup.
    $migration->up();
    expect(DB::table('pages')->where('slug', 'contact-us-previous')->count())->toBe(1);

    // Rollback puts the old words back and removes the draft.
    $migration->down();
    expect($page()->content)->toBe($backup->content)
        ->and(DB::table('pages')->where('slug', 'contact-us-previous')->exists())->toBeFalse();

    // But never over a page the owner has edited since.
    $migration->up();
    DB::table('pages')->where('slug', 'contact-us')->update(['content' => '<p>His own words.</p>']);
    $migration->down();
    expect($page()->content)->toBe('<p>His own words.</p>');
});

it('hides the floating WhatsApp button on the contact page only', function () {
    /*
     * The page's WhatsApp card makes the float redundant here, and at 390px
     * its chip and bubble covered the centre of the card buttons and Send.
     * MUTATION: delete `#kbbWa{display:none!important}` from
     * contact-hub-head -> the first expectation is red; move it into kbb.css
     * or the layout -> the home and product expectations are red.
     */
    $rule = '#kbbWa{display:none!important}';

    expect(ctContact())->toContain($rule);
    ArabicShop::on();
    expect(ctContact('/ar/contact-us/'))->toContain($rule);

    $brand = \App\Models\Brand::create(['slug' => 'ct-float-brand', 'name' => 'CT Float Brand']);
    $category = \App\Models\Category::create(['slug' => 'ct-float-cat', 'name' => 'CT Float Cat', 'path' => 'ct-float-cat', 'depth' => 0]);
    $product = \App\Models\Product::create([
        'slug' => 'ct-float-product', 'name' => 'CT Float Product', 'sku' => 'CTFLOAT1',
        'brand_id' => $brand->id, 'category_id' => $category->id, 'type' => 'simple',
        'status' => 'publish', 'is_visible' => true, 'price' => 4000, 'stock_status' => 'instock',
    ]);
    $product->categories()->syncWithoutDetaching([$category->id]);

    foreach (['/', '/product/'.$product->slug, '/privacy-policy/'] as $path) {
        ctForget();
        $r = $this->get($path);
        expect($r->getStatusCode())->toBe(200, $path);
        expect((string) $r->getContent())->not->toContain('#kbbWa{display:none');
    }
});

it('draws the form with the checkout’s floating-label fields and no visible “Get in touch” heading', function () {
    /*
     * The owner: "do the same as we have on the checkout page. along with
     * inner place holder" and "remove the get in touch heading". MUTATION: put
     * a field's <label> before its control -> `:placeholder-shown ~ label` can
     * no longer lift it, red; print the cards' h2 again -> red.
     */
    $html = ctContact();

    expect(substr_count($html, '<span class="woocommerce-input-wrapper fld kbb-fl ico"><span class="lead" aria-hidden="true">'))->toBe(5);
    foreach (['name' => 'input', 'email' => 'input', 'phone' => 'input', 'topic' => 'select', 'message' => 'textarea'] as $field => $tag) {
        expect($html)->toMatch('#<'.$tag.' [^>]*class="input-text" id="ctc-'.$field.'"[^>]*>.*?<label for="ctc-'.$field.'">#s');
    }
    // The checkout's own hints, shown inside the box once the label lifts.
    expect($html)->toContain('placeholder="'.e(__('store.checkout.field_full_name_placeholder')).'"')
        ->and($html)->toContain('placeholder="'.e(__('store.checkout.field_email_placeholder')).'"')
        ->and($html)->toContain('.ctc-form .fld.kbb-fl :is(input,textarea):not(:focus)::placeholder{color:transparent}');

    expect($html)->not->toContain('id="ctc-reach"')
        ->and($html)->toContain('<section class="ctc ctc-top" aria-label="'.e(__('store.contact.reach_heading')).'">');
});
