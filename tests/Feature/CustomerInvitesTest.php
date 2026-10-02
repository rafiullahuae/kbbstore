<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\Customer;
use App\Services\CustomerInvites\CustomerInviter;
use App\Services\CustomerInvites\InviteTemplate;
use App\Services\Mail\MailConfigurator;
use App\Support\AdminCapabilities;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\Support\CustomersAdminRoutes;

/**
 * Store → Customers → Send account invite. (Lane PQ)
 *
 * THE OWNER'S ASK, in his words: "for all guests orders, i want an option to
 * send their account logins with temporary password with reset link etc, but
 * manually i need to select all guests press on send email i will need to see
 * the email template and can be edited."
 *
 * His WooCommerce import turns every guest checkout into a customer record with
 * no password. This is how he gets them into accounts: select them (or every
 * guest at once), see and edit the email, send it in batches the console drives.
 *
 * THE SECURITY DECISION these tests pin: no temporary password is emailed. Each
 * customer gets a one-time "set your password" link — 256 random bits, stored
 * only as sha256, expiring, single use, dead when a newer invite is sent. A
 * password in an inbox is a credential that lives as long as the mailbox.
 *
 * Every test names the mutation that turns it red; each was run.
 */

/* ------------------------------------------------------------------ fixtures */

function pqAdmin(string $role = 'owner'): AdminUser
{
    return AdminUser::create([
        'name' => 'PQ ' . $role,
        'email' => 'pq-' . $role . '-' . uniqid() . '@example.test',
        'password' => 'secret-secret',
        'role' => $role,
    ]);
}

function pqGuest(array $attributes = []): Customer
{
    static $n = 0;
    $n++;

    return Customer::create(array_merge([
        'name' => 'Guest Shopper ' . $n,
        'first_name' => 'Guest' . $n,
        'email' => 'pq-guest-' . $n . '-' . uniqid() . '@example.test',
    ], $attributes));
}

/** The shop's own `kbb` mailer, on Symfony's in-memory transport: a real send. */
function pqArrayMailer(array $config = ['transport' => 'array']): void
{
    Mail::clearResolvedInstances();
    app()->forgetInstance('mail.manager');
    app()->forgetInstance('mailer');

    // Resolve the manager FIRST: MailServiceProvider's afterResolving hook runs
    // MailConfigurator::apply(), which writes the shop's own (unconfigured →
    // server mail()) settings over mail.mailers.kbb. Set ours after it.
    app('mail.manager');
    config()->set('mail.mailers.' . MailConfigurator::MAILER, $config);
    Mail::purge(MailConfigurator::MAILER);
}

/** @return list<\Symfony\Component\Mailer\SentMessage> */
function pqSent(): array
{
    $transport = Mail::mailer(MailConfigurator::MAILER)->getSymfonyTransport();

    return $transport instanceof ArrayTransport ? $transport->messages()->all() : [];
}

function pqTokenFrom(\Symfony\Component\Mailer\SentMessage $sent): string
{
    $html = (string) $sent->getOriginalMessage()->getHtmlBody();
    preg_match('#/my-account/welcome/([a-f0-9]{64})/#', $html, $m);

    return $m[1] ?? '';
}

function pqTemplate(array $overrides = []): array
{
    return array_merge([
        'subject' => InviteTemplate::DEFAULT_SUBJECT,
        'body' => InviteTemplate::DEFAULT_BODY,
        'expiry_days' => 7,
    ], $overrides);
}

/** Start a run for these ids through the real endpoint and drive it to the end. */
function pqSendAll(array $ids, array $template = [], bool $includeRecent = false): array
{
    $prep = test()->postJson('/admin-api/customers/invites/prepare', ['ids' => $ids])->assertOk()->json();

    $expected = $includeRecent ? $prep['eligible'] + $prep['recent'] : $prep['eligible'];

    $start = test()->postJson('/admin-api/customers/invites/send', pqTemplate($template) + [
        'ids' => $ids,
        'expected' => $expected,
        'include_recent' => $includeRecent,
    ]);

    $start->assertOk();
    $run = $start->json('run');

    for ($i = 0; $i < 50 && ! $run['done']; $i++) {
        $run = test()->postJson('/admin-api/customers/invites/runs/' . $run['id'] . '/step')->assertOk()->json('run');
    }

    return $run;
}

beforeEach(function () {
    CustomersAdminRoutes::wire(app());
    app(Illuminate\Contracts\Http\Kernel::class);

    if (! Route::has('customer.invite.welcome')) {
        Route::middleware('web')->group(base_path('routes/auth-customer.php'));
        Route::getRoutes()->refreshNameLookups();
        Route::getRoutes()->refreshActionLookups();
    }

    pqArrayMailer();
    RateLimiter::clear('kbb-invite:' . sha1('127.0.0.1'));
});

/* ============================================================ THE CAPABILITY */

it('lets the owner and a manager send invites and refuses support with a 403 on every route', function () {
    // MUTATION: delete the ['*', 'admin-api/customers/invites/**', ...] rule from
    // AdminCapabilities::RULES — the routes fall through to "unmapped", which is
    // owner-only, and the manager half goes red. Add 'support' to
    // CAPABILITIES['customers.invite'] and the support half goes red.
    $guest = pqGuest();

    $routes = [
        ['get', '/admin-api/customers/invites/template'],
        ['post', '/admin-api/customers/invites/template'],
        ['post', '/admin-api/customers/invites/prepare'],
        ['post', '/admin-api/customers/invites/preview'],
        ['post', '/admin-api/customers/invites/send'],
        ['get', '/admin-api/customers/invites/runs/current'],
        ['get', '/admin-api/customers/invites/runs/1'],
        ['post', '/admin-api/customers/invites/runs/1/step'],
        ['post', '/admin-api/customers/invites/runs/1/cancel'],
    ];

    foreach ($routes as [, $uri]) {
        expect(AdminCapabilities::forPath('POST', ltrim($uri, '/')))->toBe('customers.invite')
            ->and(AdminCapabilities::forPath('GET', ltrim($uri, '/')))->toBe('customers.invite');
    }

    test()->actingAs(pqAdmin('support'), 'admin');

    foreach ($routes as [$method, $uri]) {
        $response = $method === 'get' ? test()->getJson($uri) : test()->postJson($uri, ['ids' => [$guest->id]] + pqTemplate(['expected' => 1]));
        expect($response->getStatusCode())->toBe(403, $method . ' ' . $uri . ' was not refused to support');
        expect($response->getContent())->not->toContain($guest->email);
    }

    expect(pqSent())->toBe([])
        ->and(DB::table('customer_invite_runs')->count())->toBe(0);

    test()->actingAs(pqAdmin('manager'), 'admin');
    test()->getJson('/admin-api/customers/invites/template')->assertOk();
    test()->postJson('/admin-api/customers/invites/prepare', ['ids' => [$guest->id]])->assertOk()->assertJson(['eligible' => 1]);
});

it('refuses an anonymous caller, so the send cannot be reached without the admin session', function () {
    // MUTATION: mount routes/customers-admin.php without `auth:admin`.
    foreach (['/admin-api/customers/invites/template', '/admin-api/customers/invites/runs/current'] as $uri) {
        expect(test()->getJson($uri)->getStatusCode())->toBe(401);
    }

    expect(test()->postJson('/admin-api/customers/invites/send', pqTemplate(['ids' => [1], 'expected' => 1]))->getStatusCode())->toBe(401);
});

/* ======================================================= WHO IS ELIGIBLE */

it('skips and counts customers who already have a password, a WordPress one included', function () {
    // MUTATION: in CustomerInviter::classify() drop the hasNoPassword() check —
    // has_password reads 0 and five messages go out instead of three. Drop the
    // legacy_password half of hasNoPassword() and the WordPress customer is
    // sent an invite for an account they can already sign in to.
    test()->actingAs(pqAdmin(), 'admin');

    $guests = [pqGuest(), pqGuest(), pqGuest()];
    $withPassword = pqGuest(['password' => 'already-chosen-1']);
    $withWordPress = pqGuest(['legacy_password' => '$P$BexamplephpasshashXXXXXXXXXXXX.']);
    $badEmail = pqGuest(['email' => "broken\r\nBcc: x@example.test"]);
    $trashed = pqGuest();
    $trashed->delete();

    $ids = array_merge(array_map(fn ($c) => $c->id, $guests), [$withPassword->id, $withWordPress->id, $badEmail->id, $trashed->id]);

    test()->postJson('/admin-api/customers/invites/prepare', ['ids' => $ids])
        ->assertOk()
        ->assertJson([
            'selected' => 7,
            'eligible' => 3,
            'has_password' => 2,
            'invalid_email' => 1,
            'missing' => 1,
        ]);

    $run = pqSendAll($ids);

    expect($run['sent'])->toBe(3)
        ->and($run['skipped_password'])->toBe(2)
        ->and(count(pqSent()))->toBe(3);

    $recipients = array_map(fn ($s) => $s->getEnvelope()->getRecipients()[0]->getAddress(), pqSent());
    sort($recipients);
    $expectedRecipients = array_map(fn ($c) => $c->email, $guests);
    sort($expectedRecipients);

    expect($recipients)->toBe($expectedRecipients);
    expect($withPassword->fresh()->invite_token_hash)->toBeNull()
        ->and($withWordPress->fresh()->invited_at)->toBeNull();
});

it('re-checks at the moment of sending, so a guest who set a password meanwhile is not invited', function () {
    // MUTATION: drop the password conditions from the UPDATE in
    // CustomerInviter::sendOne() — the customer who checked out with an
    // account between Prepare and Send gets an invite anyway.
    test()->actingAs(pqAdmin(), 'admin');
    $a = pqGuest();
    $b = pqGuest();

    $start = test()->postJson('/admin-api/customers/invites/send', pqTemplate(['ids' => [$a->id, $b->id], 'expected' => 2]))->assertOk();

    // $b creates an account at checkout before the console's first step.
    $b->forceFill(['password' => 'chose-at-checkout'])->save();

    $run = test()->postJson('/admin-api/customers/invites/runs/' . $start->json('run.id') . '/step')->json('run');

    expect($run['sent'])->toBe(1)
        ->and(count(pqSent()))->toBe(1)
        ->and(pqSent()[0]->getEnvelope()->getRecipients()[0]->getAddress())->toBe($a->email)
        ->and(collect($run['failures'])->firstWhere('customer_id', $b->id)['reason'])->toContain('Already has a password');
});

it('never sends twice within ten minutes unless the owner re-confirms', function () {
    // MUTATION: in classify() stop treating a recent invited_at as a skip, or
    // drop the invited_at condition from sendOne()'s UPDATE — the second press
    // sends a second email to the same inbox a minute after the first.
    test()->actingAs(pqAdmin(), 'admin');
    $guest = pqGuest();

    pqSendAll([$guest->id]);
    expect(count(pqSent()))->toBe(1);

    $prep = test()->postJson('/admin-api/customers/invites/prepare', ['ids' => [$guest->id]])->json();
    expect($prep['eligible'])->toBe(0)->and($prep['recent'])->toBe(1);

    test()->postJson('/admin-api/customers/invites/send', pqTemplate(['ids' => [$guest->id], 'expected' => 0]))
        ->assertStatus(422);
    expect(count(pqSent()))->toBe(1);

    // Re-confirmed: the dialog's "send again anyway" box.
    pqSendAll([$guest->id], [], true);
    expect(count(pqSent()))->toBe(2)
        ->and($guest->fresh()->invite_count)->toBe(2);

    // Eleven minutes later it is an ordinary send again.
    $this->travel(11)->minutes();
    expect(test()->postJson('/admin-api/customers/invites/prepare', ['ids' => [$guest->id]])->json('eligible'))->toBe(1);
});

it('selects every customer matching the filter, not just the page on screen', function () {
    // MUTATION: make CustomerInvitesController::selection() ignore all_matching
    // (or make matchingIds() apply forPage()) — the 60 guests become 0 or 50.
    test()->actingAs(pqAdmin(), 'admin');

    for ($i = 0; $i < 60; $i++) {
        pqGuest();
    }
    pqGuest(['password' => 'has-one-already']);

    test()->postJson('/admin-api/customers/invites/prepare', ['all_matching' => true, 'filters' => ['filter' => 'guest']])
        ->assertOk()
        ->assertJson(['selected' => 60, 'eligible' => 60, 'has_password' => 0]);

    // And the same selection with no chip carries the customer with a password,
    // who is then counted rather than sent to.
    test()->postJson('/admin-api/customers/invites/prepare', ['all_matching' => true, 'filters' => []])
        ->assertOk()
        ->assertJson(['selected' => 61, 'eligible' => 60, 'has_password' => 1]);
});

it('asks again when the selection has changed since the owner saw the count', function () {
    // MUTATION: remove the `expected` comparison in send() — 3 are sent on a
    // confirmation that said 2.
    test()->actingAs(pqAdmin(), 'admin');
    $ids = [pqGuest()->id, pqGuest()->id, pqGuest()->id];

    test()->postJson('/admin-api/customers/invites/send', pqTemplate(['ids' => $ids, 'expected' => 2]))
        ->assertStatus(409)
        ->assertJson(['needs_confirmation' => true, 'eligible' => 3]);

    expect(DB::table('customer_invite_runs')->count())->toBe(0);
});

/* ================================================ THE TOKEN: HASHED, EXPIRING */

it('stores only a hash of the link, expires it, and lets it set a password exactly once', function () {
    // MUTATIONS, each red here:
    //  - store $token instead of self::hash($token) in sendOne(): the hash
    //    assertion and the "token is nowhere in the row" assertion fail;
    //  - drop ->where('invite_expires_at', '>', now()) from customerForToken():
    //    the eight-days-later GET renders the form.
    //  (Single use has two layers -- accept()'s spend and applyNewPassword()'s
    //  clear -- so removing one leaves this green; the next test pins the spend.)
    test()->actingAs(pqAdmin(), 'admin');
    $guest = pqGuest();

    pqSendAll([$guest->id], ['expiry_days' => 3]);

    $token = pqTokenFrom(pqSent()[0]);
    expect($token)->toMatch('/^[a-f0-9]{64}$/');

    $row = (array) DB::table('customers')->where('id', $guest->id)->first();
    expect($row['invite_token_hash'])->toBe(hash('sha256', $token))
        ->and(implode('|', array_map('strval', $row)))->not->toContain($token);

    $expires = \Carbon\Carbon::parse($row['invite_expires_at']);
    expect(abs($expires->diffInMinutes(now()->addDays(3))))->toBeLessThan(2);

    // The link works.
    test()->get('/my-account/welcome/' . $token . '/')->assertOk()
        ->assertSee($guest->email)
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    expect((string) test()->get('/my-account/welcome/' . $token . '/')->headers->get('Cache-Control'))->toContain('no-store');

    test()->post('/my-account/welcome', [
        'token' => $token, 'password' => 'my-new-password-1', 'password_confirmation' => 'my-new-password-1',
    ])->assertRedirect();

    expect(Hash::check('my-new-password-1', (string) $guest->fresh()->password))->toBeTrue();

    // Once.
    auth('customer')->logout();
    test()->post('/my-account/welcome', [
        'token' => $token, 'password' => 'attacker-password', 'password_confirmation' => 'attacker-password',
    ]);
    expect(Hash::check('my-new-password-1', (string) $guest->fresh()->password))->toBeTrue()
        ->and(auth('customer')->check())->toBeFalse();

    test()->get('/my-account/welcome/' . $token . '/')->assertOk()->assertDontSee($guest->email)
        ->assertSee(e(__('store.account.welcome_invalid')), false);

    // Expiry, on a second guest.
    $late = pqGuest();
    pqSendAll([$late->id], ['expiry_days' => 7]);
    $lateToken = pqTokenFrom(pqSent()[1]);

    $this->travel(8)->days();

    test()->get('/my-account/welcome/' . $lateToken . '/')->assertOk()
        ->assertDontSee($late->email)
        ->assertSee(e(__('store.account.welcome_invalid')), false);
});

it('spends the link BEFORE it writes the password, so two submits cannot both win', function () {
    // MUTATION: drop the conditional UPDATE that clears the hash in
    // CustomerInviter::accept() — at the moment the password is saved the
    // token is still live in the database, which is the window two racing
    // requests with one link would both get through. (The single-use test
    // above stays green under that mutation, because applyNewPassword() also
    // clears the hash afterwards; this one pins the ORDER.)
    test()->actingAs(pqAdmin(), 'admin');
    $guest = pqGuest();
    pqSendAll([$guest->id]);
    $token = pqTokenFrom(pqSent()[0]);

    $seen = 'not saved';
    Customer::saving(function (Customer $c) use (&$seen, $guest) {
        if ($c->id === $guest->id && $c->isDirty('password')) {
            $seen = DB::table('customers')->where('id', $c->id)->value('invite_token_hash');
        }
    });

    expect(app(CustomerInviter::class)->accept($token, 'racing-password-1'))->not->toBeNull()
        ->and($seen)->toBeNull()
        ->and(app(CustomerInviter::class)->accept($token, 'second-password-1'))->toBeNull();
});

it('answers an expired, a used and a made-up link with the same page', function () {
    // MUTATION: give AccountInviteController::edit() a different message for an
    // unknown token than for an expired one — the pages differ.
    $page = function (string $token): string {
        $html = (string) test()->get('/my-account/welcome/' . $token . '/')->getContent();

        // The CSRF token differs per request, and the address the page was
        // asked for is echoed in its canonical; nothing else may differ.
        $html = str_replace($token, 'TOKEN', $html);

        return (string) preg_replace('/(name="_token" value="|content=")[^"]+"/', '$1"', $html);
    };

    test()->actingAs(pqAdmin(), 'admin');
    $guest = pqGuest();
    pqSendAll([$guest->id], ['expiry_days' => 1]);
    $real = pqTokenFrom(pqSent()[0]);

    $this->travel(2)->days();

    expect($page($real))->toBe($page(str_repeat('a', 64)))
        ->and($page($real))->toBe($page('not-a-real-link'));
});

it('kills the older link when a newer invite is sent', function () {
    // MUTATION: keep a table of tokens (or skip the overwrite) so a second
    // invite leaves the first link working — the first token signs in.
    test()->actingAs(pqAdmin(), 'admin');
    $guest = pqGuest();

    pqSendAll([$guest->id]);
    $first = pqTokenFrom(pqSent()[0]);

    pqSendAll([$guest->id], [], true);
    $second = pqTokenFrom(pqSent()[1]);

    expect($first)->not->toBe($second);

    test()->get('/my-account/welcome/' . $first . '/')->assertDontSee($guest->email);
    test()->get('/my-account/welcome/' . $second . '/')->assertSee($guest->email);
});

it('sets the password, signs the customer in, and the password then works at the sign-in form', function () {
    // MUTATION: remove Auth::guard('customer')->login() from
    // AccountInviteController::update() — the customer lands signed out. Remove
    // applyNewPassword() from accept() — the sign-in afterwards fails.
    test()->actingAs(pqAdmin(), 'admin');
    $guest = pqGuest(['name' => 'Mariam Haddad', 'first_name' => 'Mariam']);

    pqSendAll([$guest->id]);
    $token = pqTokenFrom(pqSent()[0]);

    test()->post('/my-account/welcome', [
        'token' => $token, 'password' => 'short', 'password_confirmation' => 'short',
    ])->assertSessionHasErrors('password');

    $response = test()->post('/my-account/welcome', [
        'token' => $token, 'password' => 'a-good-password-9', 'password_confirmation' => 'a-good-password-9',
    ]);

    $response->assertRedirect();
    expect((string) $response->headers->get('Location'))->toEndWith('/my-account/')
        ->and(auth('customer')->id())->toBe($guest->id);

    $fresh = $guest->fresh();
    expect($fresh->invite_accepted_at)->not->toBeNull()
        ->and($fresh->email_verified_at)->not->toBeNull()
        ->and($fresh->invite_token_hash)->toBeNull();

    // And as an ordinary returning customer.
    auth('customer')->logout();
    test()->post('/my-account/login', ['email' => $guest->email, 'password' => 'a-good-password-9'])->assertRedirect();
    expect(auth('customer')->id())->toBe($guest->id);
});

it('rate-limits the set-password form per IP', function () {
    // MUTATION: delete the RateLimiter block in AccountInviteController::update().
    for ($i = 0; $i < 10; $i++) {
        test()->post('/my-account/welcome', [
            'token' => str_repeat('b', 64), 'password' => 'whatever-123', 'password_confirmation' => 'whatever-123',
        ]);
    }

    test()->post('/my-account/welcome', [
        'token' => str_repeat('c', 64), 'password' => 'whatever-123', 'password_confirmation' => 'whatever-123',
    ])->assertSessionHasErrors('password');

    expect(session('errors')->first('password'))->toContain('Too many attempts');
});

it('kills a pending invite link when the password is set through Forgot password instead', function () {
    // MUTATION: remove the invite_token_hash clearing from
    // Customer::applyNewPassword() — the invite link still works after reset.
    test()->actingAs(pqAdmin(), 'admin');
    $guest = pqGuest();
    pqSendAll([$guest->id]);
    $token = pqTokenFrom(pqSent()[0]);

    $guest->fresh()->applyNewPassword('reset-the-other-way');

    expect($guest->fresh()->invite_token_hash)->toBeNull();
    test()->get('/my-account/welcome/' . $token . '/')->assertDontSee($guest->email);
});

/* =================================================== THE TEMPLATE */

it('refuses to save a template without the link placeholder, with an unknown one, or with the link in the subject', function () {
    // MUTATION: drop the {set_password_link} check from InviteTemplate::problems()
    // — the first save answers 200 and the shop would mail people an "account
    // ready" message they cannot act on.
    test()->actingAs(pqAdmin(), 'admin');

    test()->postJson('/admin-api/customers/invites/template', ['subject' => 'Hello', 'body' => 'Hi {first_name}, welcome.', 'expiry_days' => 7])
        ->assertStatus(422)
        ->assertJsonPath('errors.body', fn ($m) => str_contains($m, '{set_password_link}'));

    test()->postJson('/admin-api/customers/invites/template', ['subject' => 'Hello', 'body' => 'Hi {firstname}: {set_password_link}', 'expiry_days' => 7])
        ->assertStatus(422)
        ->assertJsonPath('errors.body', fn ($m) => str_contains($m, '{firstname}'));

    test()->postJson('/admin-api/customers/invites/template', ['subject' => 'Go {set_password_link}', 'body' => '{set_password_link}', 'expiry_days' => 7])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['subject']]);

    expect(DB::table('settings')->where('key', InviteTemplate::KEY_BODY)->exists())->toBeFalse();

    // And the send refuses the same template, so the check is not only at "Save".
    $guest = pqGuest();
    test()->postJson('/admin-api/customers/invites/send', ['subject' => 'Hello', 'body' => 'No link here', 'expiry_days' => 7, 'ids' => [$guest->id], 'expected' => 1])
        ->assertStatus(422);
    expect(pqSent())->toBe([]);

    test()->postJson('/admin-api/customers/invites/template', ['subject' => 'Your {shop_name} login', 'body' => "Dear {name},\n{set_password_link}", 'expiry_days' => 12])
        ->assertOk();

    test()->getJson('/admin-api/customers/invites/template')
        ->assertJsonPath('template.subject', 'Your {shop_name} login')
        ->assertJsonPath('template.body', "Dear {name},\n{set_password_link}")
        ->assertJsonPath('template.expiry_days', 12);
});

it('escapes the owner\'s text and the customer\'s name in the email, and puts no markup of theirs in it', function () {
    // MUTATION: print the text segments with {!! nl2br($segment['text']) !!}
    // (no e()) in emails/customer-invite.blade.php — both the owner's <b> and
    // the shopper's <img onerror> arrive as live markup.
    test()->actingAs(pqAdmin(), 'admin');
    $guest = pqGuest(['name' => '<img src=x onerror=alert(1)>', 'first_name' => '<img src=x onerror=alert(1)>']);

    pqSendAll([$guest->id], ['body' => "Hi {first_name}, <b>bold</b> <script>alert(2)</script>\n{set_password_link}"]);

    $html = (string) pqSent()[0]->getOriginalMessage()->getHtmlBody();

    expect($html)->not->toContain('<img src=x')
        ->and($html)->not->toContain('<script>')
        ->and($html)->not->toContain('<b>bold</b>')
        ->and($html)->toContain('&lt;img src=x onerror=alert(1)&gt;')
        ->and($html)->toContain('&lt;script&gt;')
        ->and($html)->toContain(__('email.customer_invite.button'));

    // The text part carries the link itself; the subject is one line.
    $text = (string) pqSent()[0]->getOriginalMessage()->getTextBody();
    expect($text)->toMatch('#/my-account/welcome/[a-f0-9]{64}/#');
});

it('fills every placeholder for the customer, with a fallback for a missing first name', function () {
    // MUTATION: drop the 'there' fallback in InviteTemplate::values() — the
    // greeting reads "Hi ," for an imported customer with no name.
    $guest = new Customer(['name' => '', 'first_name' => '', 'email' => 'nameless@example.test']);
    $values = app(InviteTemplate::class)->values($guest, 'https://shop.test/x', \Carbon\CarbonImmutable::create(2026, 10, 9, 12));

    expect(InviteTemplate::text("Hi {first_name} / {name} / {email} / {link_expires}\n{set_password_link}", $values))
        ->toBe("Hi there / nameless@example.test / nameless@example.test / 9 October 2026\nhttps://shop.test/x");

    // A customer whose name IS a placeholder stays literal: one pass, not a chain.
    $tricky = new Customer(['name' => '{set_password_link}', 'first_name' => '{email}', 'email' => 't@example.test']);
    $v = app(InviteTemplate::class)->values($tricky, 'LINK', \Carbon\CarbonImmutable::now());
    expect(InviteTemplate::subject('For {first_name} {name}', $v))->toBe('For {email} {set_password_link}');
});

it('renders the real email as the preview, with a link that does not work', function () {
    // MUTATION: render the preview from anything but CustomerAccountInvite —
    // the button label and the escaping disappear from it.
    test()->actingAs(pqAdmin(), 'admin');
    $guest = pqGuest(['name' => 'Layla Noor', 'first_name' => 'Layla']);

    $out = test()->postJson('/admin-api/customers/invites/preview', [
        'customer_id' => $guest->id, 'subject' => 'Hello {first_name}', 'body' => "Hi {first_name}\n{set_password_link}", 'expiry_days' => 7,
    ])->assertOk()->json();

    expect($out['subject'])->toBe('Hello Layla')
        ->and($out['html'])->toContain('Hi Layla')
        ->and($out['html'])->toContain(__('email.customer_invite.button'))
        ->and($out['html'])->toContain('preview-link-is-created-when-sent')
        ->and($out['to'])->toBe($guest->email);

    // A preview mints nothing.
    expect($guest->fresh()->invite_token_hash)->toBeNull()->and(pqSent())->toBe([]);
});

/* ========================================== BATCHES, RESUMING AND FAILURES */

it('sends in batches, resumes where it stopped, and never resends an interrupted one', function () {
    // MUTATIONS:
    //  - raise STEP_MAX past 45 / drop the per-step limit: one step sends all
    //    45 and the "20 then 25 pending" assertion fails;
    //  - make retireStaleClaims() put the stale row back to 'pending': the
    //    customer whose send died mid-SMTP gets a second email.
    test()->actingAs(pqAdmin(), 'admin');

    $ids = [];
    for ($i = 0; $i < 45; $i++) {
        $ids[] = pqGuest()->id;
    }

    $run = test()->postJson('/admin-api/customers/invites/send', pqTemplate(['ids' => $ids, 'expected' => 45]))->json('run');

    $run = test()->postJson('/admin-api/customers/invites/runs/' . $run['id'] . '/step')->json('run');
    expect($run['sent'])->toBe(CustomerInviter::STEP_MAX)
        ->and($run['pending'])->toBe(45 - CustomerInviter::STEP_MAX)
        ->and($run['done'])->toBeFalse();

    // The console tab is closed; one row was mid-send when PHP died.
    $dying = DB::table('customer_invite_items')->where('run_id', $run['id'])->where('status', 'pending')->orderBy('id')->first();
    DB::table('customer_invite_items')->where('id', $dying->id)->update(['status' => 'sending', 'claimed_at' => now()->subMinutes(10)]);

    // A new page load finds the run and offers to resume it.
    test()->getJson('/admin-api/customers/invites/runs/current')->assertJsonPath('run.id', $run['id']);

    // Nothing else may start meanwhile.
    test()->postJson('/admin-api/customers/invites/send', pqTemplate(['ids' => [pqGuest()->id], 'expected' => 1]))->assertStatus(409);

    for ($i = 0; $i < 10 && ! $run['done']; $i++) {
        $run = test()->postJson('/admin-api/customers/invites/runs/' . $run['id'] . '/step')->json('run');
    }

    expect($run['done'])->toBeTrue()
        ->and($run['sent'])->toBe(44)
        ->and($run['failed'])->toBe(1)
        ->and(collect($run['failures'])->firstWhere('customer_id', $dying->customer_id)['reason'])->toContain('Interrupted');

    $recipients = array_map(fn ($s) => $s->getEnvelope()->getRecipients()[0]->getAddress(), pqSent());
    expect(count($recipients))->toBe(44)
        ->and(count(array_unique($recipients)))->toBe(44)
        ->and($recipients)->not->toContain(Customer::find($dying->customer_id)->email);

    test()->getJson('/admin-api/customers/invites/runs/current')->assertJsonPath('run', null);
});

it('records the transport\'s reason per recipient and leaves a failed customer exactly as before', function () {
    // MUTATION: remove the `update($previous)` in sendOne()'s catch — the
    // customer keeps a live token and an invited_at for an email that never
    // went, and the "Invited" pill lies.
    test()->actingAs(pqAdmin(), 'admin');
    $guest = pqGuest();

    pqArrayMailer(['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 2]);

    $run = pqSendAll([$guest->id]);

    expect($run['failed'])->toBe(1)
        ->and($run['sent'])->toBe(0)
        ->and($run['failures'][0]['reason'])->toContain('TransportException');

    $fresh = $guest->fresh();
    expect($fresh->invite_token_hash)->toBeNull()
        ->and($fresh->invited_at)->toBeNull()
        ->and($fresh->invite_count)->toBe(0);
});

it('can be cancelled, and nothing pending is sent after that', function () {
    // MUTATION: make cancel() leave pending rows pending — `pending` reads 2
    // and the rows would still be there for anything that ignored the status.
    test()->actingAs(pqAdmin(), 'admin');
    $ids = [pqGuest()->id, pqGuest()->id];

    $run = test()->postJson('/admin-api/customers/invites/send', pqTemplate(['ids' => $ids, 'expected' => 2]))->json('run');
    test()->postJson('/admin-api/customers/invites/runs/' . $run['id'] . '/cancel')->assertOk()
        ->assertJsonPath('run.status', 'cancelled')
        ->assertJsonPath('run.pending', 0)
        ->assertJsonPath('run.skipped_during', 2);
    test()->postJson('/admin-api/customers/invites/runs/' . $run['id'] . '/step')->assertOk();

    expect(pqSent())->toBe([]);
});

/* ========================================================== THE LIST */

it('shows Invited and Activated on the list, filters "Invited, not activated", and never returns the hash', function () {
    // MUTATION: drop 'invited' from CustomersApiController::SEGMENTS — the
    // filter falls back to "all" and returns three rows instead of one.
    test()->actingAs(pqAdmin(), 'admin');
    $waiting = pqGuest();
    $activated = pqGuest();
    pqGuest(); // never invited

    pqSendAll([$waiting->id, $activated->id]);
    $token = collect(pqSent())->map(fn ($s) => [$s->getEnvelope()->getRecipients()[0]->getAddress(), pqTokenFrom($s)])
        ->firstWhere(0, $activated->email)[1];

    auth('admin')->logout();
    test()->post('/my-account/welcome', ['token' => $token, 'password' => 'pw-activated-1', 'password_confirmation' => 'pw-activated-1']);
    auth('customer')->logout();
    test()->actingAs(pqAdmin(), 'admin');

    $list = test()->getJson('/admin-api/customers/list?filter=invited')->assertOk();
    expect(collect($list->json('customers'))->pluck('id')->all())->toBe([$waiting->id])
        ->and($list->json('counts.invited'))->toBe(1)
        ->and($list->json('customers.0.invited_at'))->not->toBeNull()
        ->and($list->json('customers.0.invite_count'))->toBe(1);

    $all = test()->getJson('/admin-api/customers/list')->assertOk();
    $row = collect($all->json('customers'))->firstWhere('id', $activated->id);
    expect($row['invite_accepted_at'])->not->toBeNull()
        ->and($all->getContent())->not->toContain('invite_token_hash')
        ->and($all->getContent())->not->toContain((string) DB::table('customers')->where('id', $waiting->id)->value('invite_token_hash'));

    test()->getJson('/admin-api/customers/' . $waiting->id)->assertOk()
        ->assertJsonPath('customer.invite_count', 1)
        ->assertJsonMissingPath('customer.invite_token_hash');
});

it('wires the console partial exactly once and the invite routes once each', function () {
    // MUTATION: delete the @include of admin.partials.customer-invites (zero)
    // or paste it twice (two) — the console loses the modal, or binds it twice.
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    expect(substr_count($app, "@include('admin.partials.customer-invites')"))->toBe(1);

    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'admin-api/customers/invites'))
        ->map(fn ($r) => implode('|', array_diff($r->methods(), ['HEAD'])) . ' ' . $r->uri())
        ->values()->all();

    expect(count($routes))->toBe(count(array_unique($routes)))
        ->and(count($routes))->toBe(9);
});
