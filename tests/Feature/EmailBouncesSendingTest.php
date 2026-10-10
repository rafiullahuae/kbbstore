<?php

declare(strict_types=1);

use App\Mail\CampaignMail;
use App\Services\Mail\MailConfigurator;
use App\Services\Marketing\Bounces\BounceBook;
use App\Services\Marketing\Bounces\BounceMailbox;
use App\Services\Marketing\CampaignSender;
use App\Services\Marketing\CampaignTick;
use App\Services\Marketing\SendBackoff;
use App\Services\Marketing\SendLimits;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\EmailHealthRoutes;
use Tests\Support\MarketingFixtures as F;

/**
 * Lane EB — "it must not be spamming": the pace on Google Workspace, the
 * back-off when Google says slow down, suppression at the moment of sending,
 * the headers every marketing email carries, one-click unsubscribe, and the
 * Bounces & unsubscribes screen's endpoints and capabilities.
 */

beforeEach(function () {
    app(SettingsService::class)->set('mail_address_dubai', 'Office 1, Dubai');
    SendBackoff::clear();
    @unlink(CampaignTick::markerPath());
    SettingsService::forgetMemo();
});

afterEach(function () {
    SendBackoff::clear();
    Carbon::setTestNow();
});

function ebGoogle(): void
{
    $s = app(SettingsService::class);
    $s->set('mail_transport', 'gmail');
    $s->set('mail_gmail_username', 'info@kbeautybliss.com');
    app(App\Services\Mail\MailCredentials::class)->put('gmail_password', 'abcdefghijklmnop');
    SettingsService::forgetMemo();
}

function ebCampaign(int $people, string $prefix = 'p'): int
{
    for ($i = 1; $i <= $people; $i++) {
        F::order(F::customer("{$prefix}{$i}@example.com"), [['Medicube', 10000]]);
    }

    F::product('Zero Pore Pad', 79, ['brand_id' => F::brand('Medicube')->id, 'total_sales' => 50]);
    $id = F::campaign('best-sellers', F::group('All ' . uniqid(), []));
    [$ok] = app(CampaignSender::class)->start($id);
    expect($ok)->toBeTrue();

    // Build the list.
    while (! DB::table('mkt_campaigns')->where('id', $id)->value('audience_built')) {
        app(CampaignSender::class)->step($id);
    }

    return $id;
}

/* -------------------------------------------------------------- pacing */

it('paces Google Workspace sends at one every 8–12 seconds, 6 a minute, 1,500 a day (Google\'s limit is 2,000)', function () {
    /*
     * The owner: "adjust the bulk emails sending duration inbetween … it must
     * not be spamming." Before this, Google Workspace went at 60 a minute in
     * bursts of 25 and up to 2,000 a day — Google's whole allowance, leaving
     * nothing for order confirmations. MUTATION: set GAP_GOOGLE = 0 and
     * room() allows 6 at once; the second expectation block is red.
     */
    ebGoogle();
    $l = app(SendLimits::class);

    expect($l->gap())->toBe(10)->and($l->perMinute())->toBe(6)->and($l->perDay())->toBe(1500)
        ->and($l->effectivePerMinute())->toBe(6);

    // Never above Google's ceiling, whatever is stored; up to it if the owner asks.
    app(SettingsService::class)->set('mkt_daily_cap', 9000, false);
    expect($l->perDay())->toBe(2000);

    // Jitter: every gap within 8–12, and not all the same.
    $gaps = array_map(fn ($id) => $l->gapAfter($id), range(1, 50));
    expect(min($gaps))->toBeGreaterThanOrEqual(8)->and(max($gaps))->toBeLessThanOrEqual(12)
        ->and(count(array_unique($gaps)))->toBeGreaterThan(2)
        // The same message always gets the same answer, so two drivers agree.
        ->and($l->gapAfter(7))->toBe($l->gapAfter(7));

    // Off Google, nothing changes: no pause, 60 a minute, 500 a day.
    app(SettingsService::class)->set('mail_transport', 'server');
    SettingsService::forgetMemo();
    expect($l->gap())->toBe(0)->and($l->perMinute())->toBe(60);
});

it('sends ONE message per step on Google Workspace and makes the next wait out the gap', function () {
    ebGoogle();
    Mail::fake();
    Carbon::setTestNow('2026-10-10 09:00:00');
    $id = ebCampaign(3, 'gap');

    $first = app(CampaignSender::class)->step($id);
    expect($first['sent'])->toBe(1);

    $second = app(CampaignSender::class)->step($id);
    expect($second['sent'])->toBe(1)->and($second['room']['allowed'])->toBe(0)
        ->and($second['room']['wait'])->toBeGreaterThanOrEqual(8)->toBeLessThanOrEqual(12);

    Carbon::setTestNow(now()->addSeconds(12));
    expect(app(CampaignSender::class)->step($id)['sent'])->toBe(2);
});

it('lets the scheduler sleep out each gap and carry on, inside one run', function () {
    /*
     * Driver B is the CLI, so it can wait. Without the sleep, a paced
     * campaign would send ONE message a minute (one per cron tick). The test
     * sleeper advances the clock instead of waiting.
     */
    ebGoogle();
    Mail::fake();
    Carbon::setTestNow('2026-10-10 09:00:00');
    $id = ebCampaign(3, 'tick');
    $slept = [];

    $tick = new CampaignTick(app(CampaignSender::class), function (int $s) use (&$slept) {
        $slept[] = $s;
        Carbon::setTestNow(now()->addSeconds($s));
    });
    $tick->run();

    Mail::assertSent(CampaignMail::class, 3);
    expect($slept)->toHaveCount(2);

    foreach ($slept as $s) {
        expect($s)->toBeGreaterThanOrEqual(8)->toBeLessThanOrEqual(12);
    }
});

/* ------------------------------------------------------------ back-off */

it('backs off on Google\'s daily-limit answer: the row is retried later, NOBODY is marked or suppressed', function () {
    /*
     * The defect: "550 5.4.5 Daily user sending limit exceeded" was read as a
     * HARD bounce of the RECIPIENT (Lane MK's /\b5\d\d\b/), so hitting the
     * limit suppressed customers. MUTATION: drop the SENDER branch in
     * CampaignSender::sendOne() and the row is `failed` with a suppression.
     */
    $id = ebCampaign(2, 'limit');
    Mail::shouldReceive('mailer')->andReturnSelf();
    Mail::shouldReceive('to')->andReturnSelf();
    Mail::shouldReceive('send')->andThrow(new Symfony\Component\Mailer\Exception\TransportException('Expected response code "250" but got code "550", with message "550 5.4.5 Daily user sending limit exceeded. For more information on Gmail sending limits go to https://support.google.com/a/answer/166852"'));

    $p = app(CampaignSender::class)->step($id);

    expect($p['pending'])->toBe(2)->and($p['failed'])->toBe(0)
        ->and(DB::table('email_suppressions')->count())->toBe(0)
        ->and(DB::table('email_bounces')->count())->toBe(0)
        // Paused until after midnight, Dubai time.
        ->and(SendBackoff::wait())->toBeGreaterThan(0)
        ->and(app(SendLimits::class)->room()['allowed'])->toBe(0);
});

it('backs off 5, 10, 20 minutes on "try again later", and pauses the campaign after six in a row', function () {
    $id = ebCampaign(1, 'busy');
    Mail::shouldReceive('mailer')->andReturnSelf();
    Mail::shouldReceive('to')->andReturnSelf();
    Mail::shouldReceive('send')->andThrow(new Symfony\Component\Mailer\Exception\TransportException('421 4.7.0 Try again later, closing connection. (EHLO)'));

    $waits = [];

    for ($i = 1; $i <= SendBackoff::STRIKES_PAUSE; $i++) {
        app(CampaignSender::class)->step($id);
        $waits[] = SendBackoff::wait();
        Carbon::setTestNow(now()->addSeconds(SendBackoff::wait() + 1));
    }

    expect($waits[0])->toBe(300)->and($waits[1])->toBe(600)->and($waits[2])->toBe(1200)
        ->and(DB::table('mkt_campaigns')->where('id', $id)->value('status'))->toBe('paused')
        ->and(DB::table('mkt_sends')->where('campaign_id', $id)->value('status'))->toBe('pending');
});

/* -------------------------------------------------------- suppression */

it('suppresses on the FIRST hard refusal at send time, and skips an address that bounced mid-send', function () {
    /*
     * The owner asked for bounced addresses to be removed automatically; Lane
     * MK waited for a second hard refusal. MUTATION: restore the >= 2 count
     * and the first expectation is red.
     */
    $id = ebCampaign(1, 'dead');
    Mail::shouldReceive('mailer')->andReturnSelf();
    Mail::shouldReceive('to')->andReturnSelf();
    Mail::shouldReceive('send')->once()->andThrow(new Symfony\Component\Mailer\Exception\TransportException('Expected response code "250" but got code "550", with message "550 5.1.1 <dead1@example.com>: Recipient address rejected: User unknown"'));
    app(CampaignSender::class)->step($id);

    expect(DB::table('email_suppressions')->where('email', 'dead1@example.com')->value('reason'))->toBe('bounce')
        ->and(DB::table('email_bounces')->where('email', 'dead1@example.com')->value('source'))->toBe('smtp')
        ->and((int) DB::table('email_bounces')->where('email', 'dead1@example.com')->value('campaign_id'))->toBe($id);
});

it('never mails an address that hard-bounced after the list was written', function () {
    Mail::fake();
    $id = ebCampaign(2, 'mid');
    app(BounceBook::class)->record(['email' => 'mid1@example.com', 'kind' => 'hard', 'source' => 'dsn']);

    for ($i = 0; $i < 5; $i++) {
        app(CampaignSender::class)->step($id);
    }

    Mail::assertSent(CampaignMail::class, 1);
    Mail::assertNotSent(CampaignMail::class, fn ($m) => $m->hasTo('mid1@example.com'));
    expect(DB::table('mkt_sends')->where('email', 'mid1@example.com')->value('status'))->toBe('skipped');
});

/* ------------------------------------------------------------ headers */

function ebRealSend(): Symfony\Component\Mime\Email
{
    // Resolve the manager FIRST: its afterResolving hook applies Store → Mail
    // (MailServiceProvider), which would put the SMTP transport back.
    app('mail.manager');
    config(['mail.mailers.' . MailConfigurator::MAILER => ['transport' => 'array']]);
    app('mail.manager')->purge(MailConfigurator::MAILER);
    $id = ebCampaign(1, 'hdr');

    for ($i = 0; $i < 3; $i++) {
        app(CampaignSender::class)->step($id);
    }

    $messages = Mail::mailer(MailConfigurator::MAILER)->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1);

    return $messages[0]->getOriginalMessage();
}

it('sends every marketing email with one-click + mailto unsubscribe, a signed Message-ID on the From domain, Feedback-ID and a text part', function () {
    /*
     * Gmail's bulk-sender rules (Feb 2024): List-Unsubscribe with an https
     * one-click URL AND List-Unsubscribe-Post, a consistent From, RFC 5322
     * Message-ID, a plain-text part. The mailto: appears only while the
     * bounce mailbox that processes it is being read.
     */
    ebGoogle();
    app(SettingsService::class)->set(BounceMailbox::KEYS['enabled'], '1', false);
    SettingsService::forgetMemo();

    $email = ebRealSend();
    $h = $email->getHeaders();

    $lu = $h->get('List-Unsubscribe')->getBodyAsString();
    expect($lu)->toMatch('#^<https?://[^>]+/email/u/[0-9a-z]+-[0-9a-f]{32}>, <mailto:info\+unsubscribe@kbeautybliss\.com\?subject=unsubscribe%20[0-9a-z]+-[0-9a-f]{32}>$#')
        ->and($h->get('List-Unsubscribe-Post')->getBodyAsString())->toBe('List-Unsubscribe=One-Click')
        ->and($h->get('Precedence')->getBodyAsString())->toBe('bulk')
        ->and($h->get('Feedback-ID')->getBodyAsString())->toMatch('/^\d+:mkt:kbb$/')
        ->and($h->get('Message-ID')->getBodyAsString())->toMatch('/^<[0-9a-z]+\.[0-9a-f]{32}@kbeautybliss\.com>$/')
        ->and($email->getFrom()[0]->getAddress())->toBe('info@kbeautybliss.com')
        ->and((string) $email->getTextBody())->not->toBe('')
        ->and((string) $email->getHtmlBody())->toContain('/email/u/');

    // The Message-ID resolves back to its send row — how a bounce names the campaign.
    preg_match('/<([^@]+)@/', $h->get('Message-ID')->getBodyAsString(), $m);
    expect(App\Services\Marketing\Bounces\BounceRef::resolve($m[1])->email)->toBe('hdr1@example.com');
});

it('leaves the mailto: out while nobody reads the bounce mailbox', function () {
    ebGoogle();
    $lu = ebRealSend()->getHeaders()->get('List-Unsubscribe')->getBodyAsString();

    expect($lu)->not->toContain('mailto:')->and($lu)->toStartWith('<http');
});

it('unsubscribes when Gmail POSTs the exact List-Unsubscribe URL with no session and no CSRF token', function () {
    /*
     * End to end: the URL is read off a real sent message, and the POST is
     * shaped as Gmail sends it (form-encoded "List-Unsubscribe=One-Click"),
     * with CSRF checking ON. MUTATION: drop withoutMiddleware(ValidateCsrfToken)
     * from routes/marketing-public.php and this is a 419.
     */
    EmailHealthRoutes::wire(app());
    ebGoogle();
    $lu = ebRealSend()->getHeaders()->get('List-Unsubscribe')->getBodyAsString();
    preg_match('#<(https?://[^>]+)>#', $lu, $m);
    $path = parse_url($m[1], PHP_URL_PATH);

    $this->withMiddleware(ValidateCsrfToken::class);
    $r = $this->call('POST', $path, [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 'List-Unsubscribe=One-Click');

    $r->assertOk();
    expect(DB::table('email_suppressions')->where('email', 'hdr1@example.com')->value('reason'))->toBe('unsubscribe');
});

/* ------------------------------------------------------ the admin screen */

it('maps every Bounces & unsubscribes route to its own capability, failing closed', function () {
    /*
     * MUTATION: delete the ['*', 'admin-api/email-health/**', …mailbox] rule
     * and the three mailbox POSTs resolve to null (owner-only by default) —
     * the expectation below names the capability, so it is red.
     */
    EmailHealthRoutes::wire(app());
    $routes = EmailHealthRoutes::admin();
    expect(count($routes))->toBe(9);

    $p = fn (string $m, string $u) => AdminCapabilities::forPath($m, $u);
    expect($p('GET', 'admin-api/email-health/overview'))->toBe('marketing.bounces.view')
        ->and($p('GET', 'admin-api/email-health/list'))->toBe('marketing.bounces.view')
        ->and($p('GET', 'admin-api/email-health/bounced/export'))->toBe('marketing.export')
        ->and($p('POST', 'admin-api/email-health/bounced/restore'))->toBe('marketing.bounces.restore')
        ->and($p('POST', 'admin-api/email-health/mailbox'))->toBe('marketing.bounces.mailbox')
        ->and($p('POST', 'admin-api/email-health/mailbox/test'))->toBe('marketing.bounces.mailbox')
        ->and($p('POST', 'admin-api/email-health/mailbox/run'))->toBe('marketing.bounces.mailbox')
        ->and($p('POST', 'admin-api/email-health/pace'))->toBe('marketing.email.send')
        ->and($p('POST', 'admin-api/email-health/deliverability/check'))->toBe('marketing.bounces.view')
        // A write added later without a rule is the owner-only mailbox one.
        ->and($p('DELETE', 'admin-api/email-health/anything-new'))->toBe('marketing.bounces.mailbox')
        ->and(AdminCapabilities::CAPABILITIES['marketing.bounces.mailbox'])->toBe(['owner']);

    foreach ($routes as $route) {
        expect(AdminCapabilities::for($route))->not->toBeNull($route->uri());
    }
});

it('answers 403 to support and editor on every route, and keeps the mailbox owner-only', function () {
    EmailHealthRoutes::wire(app());

    foreach (['support', 'editor'] as $role) {
        $this->actingAs(F::admin($role), 'admin');

        foreach (EmailHealthRoutes::admin() as $route) {
            $method = array_values(array_diff($route->methods(), ['HEAD']))[0];
            $this->json($method, '/' . $route->uri(), [])->assertForbidden();
        }
    }

    $this->actingAs(F::admin('manager'), 'admin');
    $this->getJson('/admin-api/email-health/overview')->assertOk()->assertJsonPath('can.mailbox', false)->assertJsonPath('can.restore', true);
    $this->postJson('/admin-api/email-health/mailbox', ['enabled' => true, 'auto_remove' => true])->assertForbidden();
    $this->postJson('/admin-api/email-health/mailbox/test')->assertForbidden();
});

it('stores the bounce app password encrypted and never returns it', function () {
    EmailHealthRoutes::wire(app());
    $this->actingAs(F::admin('owner'), 'admin');

    $this->postJson('/admin-api/email-health/mailbox', [
        'enabled' => true, 'auto_remove' => true, 'username' => 'bounces@kbeautybliss.com', 'label' => 'KBB Bounces', 'password' => 'wxyz wxyz wxyz wxyz',
    ])->assertOk()->assertJsonPath('mailbox.own_password_set', true);

    $raw = (string) DB::table('mail_credentials')->value('config');
    expect($raw)->not->toContain('wxyzwxyz')->and($raw)->not->toBe('');

    $json = $this->getJson('/admin-api/email-health/overview')->assertOk()->getContent();
    expect($json)->not->toContain('wxyz')->and($json)->toContain('bounces@kbeautybliss.com');

    // A label that would open the inbox is refused.
    $this->postJson('/admin-api/email-health/mailbox', ['enabled' => true, 'auto_remove' => true, 'label' => 'INBOX'])->assertStatus(422);
});

it('lists the Bounced tab with the same number of queries for 3 rows as for 60', function () {
    /*
     * Super light: one page, a fixed handful of queries. MUTATION: look the
     * campaign name up inside present()'s map (a query per row) and the 60-row
     * count is higher.
     */
    EmailHealthRoutes::wire(app());
    $this->actingAs(F::admin('owner'), 'admin');
    $cid = F::campaign('best-sellers', F::group('G', []));
    $book = app(BounceBook::class);
    $add = function (int $from, int $to) use ($book, $cid) {
        for ($i = $from; $i <= $to; $i++) {
            $book->record(['email' => "b{$i}@example.com", 'kind' => 'hard', 'code' => '5.1.1', 'detail' => 'gone', 'campaign_id' => $cid, 'source' => 'dsn', 'report_id' => "r{$i}"]);
        }
    };

    $count = function () {
        // Warm: the first request of a test also reads the settings map once.
        $this->getJson('/admin-api/email-health/list?tab=bounced')->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $r = $this->getJson('/admin-api/email-health/list?tab=bounced')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$n, $r];
    };

    $add(1, 3);
    [$small, $r] = $count();
    expect($r->json('total'))->toBe(3)->and($r->json('rows.0.campaign'))->not->toBe('')->and($r->json('rows.0.hard'))->toBe(1);

    $add(4, 60);
    [$large, $r] = $count();
    expect($r->json('total'))->toBe(60)->and($r->json('rows'))->toHaveCount(50)->and($large)->toBe($small);

    // Search.
    expect($this->getJson('/admin-api/email-health/list?tab=bounced&q=b42%40')->json('total'))->toBe(1);
});

it('exports the Bounced list as CSV with formula cells defused, and restores only with confirmation', function () {
    EmailHealthRoutes::wire(app());
    $this->actingAs(F::admin('owner'), 'admin');
    app(BounceBook::class)->record(['email' => 'csv@example.com', 'kind' => 'hard', 'code' => '5.1.1', 'detail' => '=HYPERLINK("x")', 'source' => 'dsn']);

    $csv = $this->get('/admin-api/email-health/bounced/export')->assertOk()->streamedContent();
    expect($csv)->toContain('csv@example.com')->and($csv)->toContain("'=HYPERLINK")->and($csv)->not->toContain(',=HYPERLINK');

    $this->postJson('/admin-api/email-health/bounced/restore', ['email' => 'csv@example.com'])->assertStatus(422);
    $this->postJson('/admin-api/email-health/bounced/restore', ['email' => 'csv@example.com', 'confirm' => true])->assertOk();
    expect(DB::table('email_suppressions')->where('email', 'csv@example.com')->exists())->toBeFalse();
});

it('draws the screen with no markup from data, no layout measuring and no timer, and is included at most once', function () {
    $src = (string) file_get_contents(resource_path('views/admin/partials/email-health-screen.blade.php'));
    $js = substr($src, (int) strpos($src, '<script>'));

    foreach (['innerHTML', 'insertAdjacentHTML', 'outerHTML', 'document.write', 'setInterval', 'setTimeout', 'getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'clientWidth', 'scrollWidth', 'getComputedStyle', 'ResizeObserver', 'IntersectionObserver', 'elementFromPoint', 'eval('] as $api) {
        expect(str_contains($js, $api))->toBeFalse("the screen uses {$api}");
    }

    // Wired at most once: twice would wrap window.go around its own wrapper.
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    expect(substr_count($app, "@include('admin.partials.email-health-screen')"))->toBeLessThanOrEqual(1);
});
