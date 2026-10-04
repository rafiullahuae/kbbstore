<?php

declare(strict_types=1);

use App\Mail\CampaignMail;
use App\Services\Marketing\CampaignSender;
use App\Services\Marketing\CampaignTick;
use App\Services\Marketing\SendLimits;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\MarketingEmailsRoutes;
use Tests\Support\MarketingFixtures as F;

/**
 * Marketing Emails — the sending pipeline (Lane MK, docs/EMAILS-PLAN.md §4,
 * tests owed in §7 for E3 and E4).
 *
 * The defects each case is here for are the ones a send to thousands of real
 * customers cannot afford: one address mailed twice by two drivers racing,
 * a step that died mid-SMTP leaving rows claimed for ever, a daily cap that
 * does not hold on Google Workspace (whose limit is the account's, not ours),
 * a scheduled campaign started twice by two cron ticks, and — the one the
 * plan names first — marketing mail sent on the tail of a SHOPPER's page view.
 */

beforeEach(function () {
    // Every campaign carries a postal address (CampaignSender::hasPostalAddress()).
    app(App\Services\SettingsService::class)->set('mail_address_dubai', 'Office 1, Dubai');
    @unlink(CampaignTick::markerPath());
    SettingsService::forgetMemo();
});

function mkCustomers(int $n, string $prefix = 'buyer'): array
{
    $out = [];

    for ($i = 1; $i <= $n; $i++) {
        $c = F::customer("{$prefix}{$i}@example.com");
        F::order($c, [['Medicube', 10000]]);
        $out[] = $c;
    }

    return $out;
}

function mkAllCustomersCampaign(string $template = 'best-sellers'): int
{
    F::product('Zero Pore Pad', 79, ['brand_id' => F::brand('Medicube')->id, 'total_sales' => 50]);

    return F::campaign($template, F::group('All', []));
}

function mkRunToEnd(int $id, int $max = 50): array
{
    $sender = app(CampaignSender::class);
    $p = [];

    for ($i = 0; $i < $max; $i++) {
        $p = $sender->step($id);

        if ($p['done'] ?? false) {
            break;
        }
    }

    return $p;
}

it('sends a whole campaign: one message per address, with the unsubscribe headers, a text part and the footer', function () {
    Mail::fake();
    mkCustomers(4);
    $id = mkAllCustomersCampaign();

    [$ok] = app(CampaignSender::class)->start($id);
    expect($ok)->toBeTrue();

    $p = mkRunToEnd($id);

    expect($p['status'])->toBe('sent')->and($p['sent'])->toBe(4)->and($p['pending'])->toBe(0);

    Mail::assertSent(CampaignMail::class, 4);

    foreach (['buyer1@example.com', 'buyer2@example.com', 'buyer3@example.com', 'buyer4@example.com'] as $to) {
        Mail::assertSent(CampaignMail::class, fn ($m) => $m->hasTo($to));
    }

    $mail = null;
    Mail::assertSent(CampaignMail::class, function ($m) use (&$mail) {
        $mail = $m;

        return true;
    });

    $headers = $mail->headers()->text;
    expect($headers['List-Unsubscribe'])->toMatch('#^<https?://[^>]+/email/u/[0-9a-z]+-[0-9a-f]{32}>$#')
        ->and($headers['List-Unsubscribe-Post'])->toBe('List-Unsubscribe=One-Click')
        ->and($headers['Precedence'])->toBe('bulk');

    $html = $mail->render();
    expect($html)->toContain('/email/u/')
        ->and($html)->toContain('/email/c/')
        ->and($html)->not->toContain('data-mkb');
});

it('never sends one address twice when two steps race for the same rows', function () {
    /*
     * The defect: two drivers (an open tab and the scheduler) both read "the
     * next 25 pending" and both send them. The claim is a conditional UPDATE
     * per row, so a row already claimed by the other step is not sent here.
     *
     * MUTATION: drop `->where('status', 'pending')` from the claim UPDATE in
     * CampaignSender::sendSome() and the pre-claimed row below is sent too —
     * Mail::assertSent(..., 3) goes red at 4.
     */
    Mail::fake();
    mkCustomers(4, 'race');
    $id = mkAllCustomersCampaign();
    app(CampaignSender::class)->start($id);
    app(CampaignSender::class)->step($id);   // writes the list

    // Another step owns this row right now.
    $other = DB::table('mkt_sends')->where('campaign_id', $id)->orderBy('id')->first();
    DB::table('mkt_sends')->where('id', $other->id)->update(['status' => 'claimed', 'claimed_at' => now()]);

    app(CampaignSender::class)->step($id);
    app(CampaignSender::class)->step($id);

    Mail::assertSent(CampaignMail::class, 3);
    Mail::assertNotSent(CampaignMail::class, fn ($m) => $m->hasTo($other->email));

    // And the list itself cannot hold an address twice: unique(campaign_id, email).
    expect(fn () => DB::table('mkt_sends')->insert([
        'campaign_id' => $id, 'email' => $other->email, 'token' => str_repeat('a', 40), 'status' => 'pending',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('recovers a claim a dead step left behind after 180 seconds, and not before', function () {
    /*
     * The defect: a step killed mid-request (a PHP timeout, a host restart)
     * leaves its rows `claimed`, and nothing ever sends them.
     *
     * MUTATION: delete the recoverStale() call in CampaignSender::step() and
     * the stale row is never sent — the final assertSent count is 1, not 2.
     */
    Mail::fake();
    mkCustomers(2, 'stale');
    $id = mkAllCustomersCampaign();
    app(CampaignSender::class)->start($id);
    app(CampaignSender::class)->step($id);

    $rows = DB::table('mkt_sends')->where('campaign_id', $id)->orderBy('id')->get();
    DB::table('mkt_sends')->where('id', $rows[0]->id)->update(['status' => 'claimed', 'claimed_at' => now()->subSeconds(100)]);

    app(CampaignSender::class)->step($id);
    Mail::assertSent(CampaignMail::class, 1);
    expect(DB::table('mkt_sends')->where('id', $rows[0]->id)->value('status'))->toBe('claimed');

    DB::table('mkt_sends')->where('id', $rows[0]->id)->update(['claimed_at' => now()->subSeconds(181)]);
    app(CampaignSender::class)->step($id);

    Mail::assertSent(CampaignMail::class, 2);
    expect(DB::table('mkt_sends')->where('id', $rows[0]->id)->value('status'))->toBe('sent');
});

it('holds the daily cap across campaigns, and the per-minute rate', function () {
    /*
     * The defect: Google Workspace allows about 2,000 messages a day per
     * account; a cap that resets per campaign, or per step, lets two campaigns
     * spend 4,000 and gets the shop's mailbox suspended mid-afternoon.
     *
     * MUTATION: make SendLimits::room() return STEP_MAX unconditionally and
     * the first expectation is 5, not 3.
     */
    Mail::fake();
    mkCustomers(5, 'cap');
    app(SettingsService::class)->set('mkt_daily_cap', 3, false);
    $id = mkAllCustomersCampaign();
    app(CampaignSender::class)->start($id);
    mkRunToEnd($id, 10);

    Mail::assertSent(CampaignMail::class, 3);
    expect(app(CampaignSender::class)->progress($id)['pending'])->toBe(2)
        ->and(app(CampaignSender::class)->step($id)['room']['allowed'])->toBe(0);

    // The rate: two a minute means two, whatever the cap.
    app(SettingsService::class)->set('mkt_daily_cap', 1000, false);
    app(SettingsService::class)->set('mkt_rate_per_minute', 2, false);
    DB::table('mkt_sends')->where('status', 'sent')->update(['sent_at' => now()->subMinutes(5)]);
    app(CampaignSender::class)->step($id);
    Mail::assertSent(CampaignMail::class, 5);

    expect(app(SendLimits::class)->room()['minute_left'])->toBe(0);
});

it('defaults the daily cap to 2,000 on Google Workspace and to a conservative 500 otherwise', function () {
    expect(app(SendLimits::class)->perDay())->toBe(SendLimits::CAP_OTHER)->and(SendLimits::CAP_OTHER)->toBe(500);

    app(SettingsService::class)->set('mail_transport', 'gmail');
    SettingsService::forgetMemo();
    expect(app(SendLimits::class)->perDay())->toBe(2000)
        ->and(app(SendLimits::class)->perMinute())->toBe(60);

    // Never above Google's own ceiling, whatever is stored.
    app(SettingsService::class)->set('mkt_daily_cap', 9000, false);
    expect(app(SendLimits::class)->perDay())->toBe(2000);
});

it('starts a scheduled campaign exactly once, however many ticks race for it', function () {
    /*
     * The defect: two overlapping cron runs (or a tick and an open tab) both
     * see "scheduled and due" and both start it — two lists, everybody mailed
     * twice.
     *
     * MUTATION: drop `->whereIn('status', ['draft', 'scheduled'])` from the
     * conditional UPDATE in CampaignSender::start() and the second start()
     * reports true.
     */
    Mail::fake();
    mkCustomers(2, 'sched');
    $id = mkAllCustomersCampaign();
    DB::table('mkt_campaigns')->where('id', $id)->update(['status' => 'scheduled', 'scheduled_at' => now()->subMinute()]);

    $first = app(CampaignTick::class)->run();
    $second = app(CampaignTick::class)->run();

    expect($first['started'])->toBe(1)->and($second['started'])->toBe(0);
    expect(app(CampaignSender::class)->start($id)[0])->toBeFalse();

    mkRunToEnd($id);
    Mail::assertSent(CampaignMail::class, 2);

    // A tick was seen, so the screen will not ask for the cron line.
    expect(CampaignTick::alive())->toBeTrue();
});

it('registers the scheduler entry once, every minute, without overlapping', function () {
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->filter(fn ($e) => str_contains((string) $e->command, 'kbb:campaigns-step'));

    expect($events)->toHaveCount(1);

    $e = $events->first();
    expect($e->expression)->toBe('* * * * *')
        ->and($e->withoutOverlapping)->toBeTrue()
        ->and($e->expiresAt)->toBe(5);
});

it('does not start a future scheduled campaign, and says when no tick has been seen', function () {
    Mail::fake();
    mkCustomers(1, 'future');
    $id = mkAllCustomersCampaign();
    DB::table('mkt_campaigns')->where('id', $id)->update(['status' => 'scheduled', 'scheduled_at' => now()->addHour()]);

    expect(CampaignTick::alive())->toBeFalse();
    app(CampaignTick::class)->run();

    expect(DB::table('mkt_campaigns')->where('id', $id)->value('status'))->toBe('scheduled');
    Mail::assertNothingSent();
});

it('never sends marketing on a shopper\'s request', function () {
    /*
     * Plan §4.5: back-in-stock and basket reminders ride OutboundTick on the
     * tail of page views; marketing must not — hundreds of SMTP conversations
     * on the end of a customer's page load. A campaign is left `sending` with
     * its list written, a shopper browses, and nothing goes.
     *
     * MUTATION: call app(CampaignTick::class)->run() from OutboundTick's
     * terminating callback and this is red.
     */
    Mail::fake();
    mkCustomers(2, 'shopper');
    $id = mkAllCustomersCampaign();
    app(CampaignSender::class)->start($id);
    app(CampaignSender::class)->step($id);

    $this->get('/')->assertOk();
    $this->get('/shop/');

    Mail::assertNothingSent();

    foreach (['app/Services/OutboundTick.php', 'app/Services/Mail/OrderReminderTick.php'] as $file) {
        $src = (string) file_get_contents(base_path($file));
        expect($src)->not->toContain('Marketing')->and($src)->not->toContain('Campaign');
    }
});

it('pauses, resumes and cancels; a cancelled campaign sends nothing more', function () {
    Mail::fake();
    mkCustomers(3, 'pause');
    $id = mkAllCustomersCampaign();
    $s = app(CampaignSender::class);
    $s->start($id);
    $s->step($id);

    expect($s->pause($id))->toBeTrue();
    $s->step($id);
    Mail::assertNothingSent();

    expect($s->resume($id))->toBeTrue()->and($s->cancel($id))->toBeTrue();
    $s->step($id);
    Mail::assertNothingSent();

    $p = $s->progress($id);
    expect($p['status'])->toBe('cancelled')->and($p['skipped'])->toBe(3);
});

it('skips an address that unsubscribed after the list was written', function () {
    Mail::fake();
    mkCustomers(2, 'late');
    $id = mkAllCustomersCampaign();
    app(CampaignSender::class)->start($id);
    app(CampaignSender::class)->step($id);

    DB::table('email_suppressions')->insert(['email' => 'late1@example.com', 'reason' => 'unsubscribe', 'created_at' => now()]);
    mkRunToEnd($id);

    Mail::assertSent(CampaignMail::class, 1);
    Mail::assertNotSent(CampaignMail::class, fn ($m) => $m->hasTo('late1@example.com'));
    expect(DB::table('mkt_sends')->where('email', 'late1@example.com')->value('status'))->toBe('skipped');
});

it('writes a bounce suppression on the second hard refusal for the same address', function () {
    /*
     * Plan §4 "Bounces": a synchronous 5xx marks the send failed, and a second
     * hard failure for that address suppresses it, so the third campaign does
     * not knock on the same dead mailbox and spend the shop's reputation.
     */
    mkCustomers(1, 'dead');
    $sender = app(CampaignSender::class);
    Mail::shouldReceive('mailer')->andReturnSelf();
    Mail::shouldReceive('to')->andReturnSelf();
    Mail::shouldReceive('send')->andThrow(new \RuntimeException('Expected response code 250 but got code "550", with message "550 5.1.1 User unknown"'));

    foreach ([1, 2] as $round) {
        $id = mkAllCustomersCampaign();
        $sender->start($id);
        mkRunToEnd($id);
        expect(DB::table('mkt_sends')->where('campaign_id', $id)->value('status'))->toBe('failed');
    }

    expect(DB::table('email_suppressions')->where('email', 'dead1@example.com')->value('reason'))->toBe('bounce');
});

it('refuses to start an email over 95 KB, because Gmail clips at 102 KB', function () {
    /*
     * MUTATION: raise CampaignRenderer::MAX_BYTES to 500 KB and start() is
     * allowed through with a 110 KB email.
     */
    mkCustomers(1, 'big');
    $id = mkAllCustomersCampaign();
    $blocks = [App\Services\Marketing\Blocks::make('mini_header')];

    for ($i = 0; $i < 37; $i++) {
        $blocks[] = App\Services\Marketing\Blocks::make('text', ['body' => str_repeat('Glow serum for dewy skin. ', 115)]);
    }

    $blocks[] = App\Services\Marketing\Blocks::make('footer');
    DB::table('mkt_campaigns')->where('id', $id)->update(['blocks' => json_encode($blocks)]);

    [$ok, $why] = app(CampaignSender::class)->start($id);

    expect($ok)->toBeFalse()->and($why)->toContain('95 KB')
        ->and(DB::table('mkt_campaigns')->where('id', $id)->value('status'))->toBe('draft');
});

it('drives a send from the admin\'s open tab: typed count, then steps with progress', function () {
    Mail::fake();
    MarketingEmailsRoutes::wire(app());
    mkCustomers(3, 'tab');
    $id = mkAllCustomersCampaign();
    $this->actingAs(F::admin('manager'), 'admin');

    $this->postJson("/admin-api/email-marketing/campaigns/{$id}/send", ['confirm' => '2'])
        ->assertStatus(422)->assertJsonPath('expected', 3);
    expect(DB::table('mkt_campaigns')->where('id', $id)->value('status'))->toBe('draft');

    $this->postJson("/admin-api/email-marketing/campaigns/{$id}/send", ['confirm' => '3'])->assertOk()
        ->assertJsonPath('progress.status', 'sending');

    for ($i = 0; $i < 5; $i++) {
        $r = $this->postJson("/admin-api/email-marketing/campaigns/{$id}/step")->assertOk()->json('progress');

        if ($r['done']) {
            break;
        }
    }

    expect($r['status'])->toBe('sent')->and($r['sent'])->toBe(3);
    Mail::assertSent(CampaignMail::class, 3);
});

it('refuses to start a campaign with no postal address in the footer, and the sent email prints it', function () {
    /*
     * The kit leaves an unfilled address out rather than print a placeholder,
     * so without this guard a campaign goes out with no postal address — which
     * every marketing email must carry beside its unsubscribe link (plan §4).
     *
     * MUTATION: delete the hasPostalAddress() check in CampaignSender::start()
     * and the first start() is allowed.
     */
    Mail::fake();
    mkCustomers(1, 'addr');
    $id = mkAllCustomersCampaign();
    app(SettingsService::class)->set('mail_address_dubai', '');
    app(SettingsService::class)->set('mail_address_korea', '');
    SettingsService::forgetMemo();

    [$ok, $why] = app(CampaignSender::class)->start($id);
    expect($ok)->toBeFalse()->and($why)->toBe(CampaignSender::NO_ADDRESS);

    app(SettingsService::class)->set('mail_address_dubai', 'Office 7, Business Bay, Dubai');
    SettingsService::forgetMemo();
    expect(app(CampaignSender::class)->start($id)[0])->toBeTrue();
    mkRunToEnd($id);

    Mail::assertSent(CampaignMail::class, function ($m) {
        $html = $m->render();

        return str_contains($html, 'Office 7, Business Bay, Dubai') && str_contains($html, '/email/u/');
    });
});

it('tells each recipient the true reason in the footer: bought before, or has an account', function () {
    /*
     * The defect: the "Never ordered" group (customers with an account and no
     * order) was told "you are receiving this because you bought from us
     * before" — a false statement in the one line that explains consent.
     *
     * MUTATION: pass $snap['audience'] instead of $snap['who'] to the renderer
     * in CampaignSender::sendOne() and the account holder is told they bought.
     */
    Mail::fake();
    F::customer('account-only@example.com');
    $buyer = F::customer('buyer-why@example.com');
    F::order($buyer, [['Anua', 5000]]);
    F::product('Toner', 60, ['total_sales' => 3]);
    $id = F::campaign('best-sellers', F::group('Everyone', []));

    app(CampaignSender::class)->start($id);
    mkRunToEnd($id);

    $bought = __('email.mkt.why_customers', ['store' => config('app.name')]);
    $account = __('email.mkt.why_account', ['store' => config('app.name')]);

    Mail::assertSent(CampaignMail::class, fn ($m) => $m->hasTo('account-only@example.com') && str_contains($m->render(), e(explode(':store', __('email.mkt.why_account'))[0])) && ! str_contains($m->render(), e(explode(':store', __('email.mkt.why_customers'))[0])));
    Mail::assertSent(CampaignMail::class, fn ($m) => $m->hasTo('buyer-why@example.com') && str_contains($m->render(), e(explode(':store', __('email.mkt.why_customers'))[0])));
    expect($bought)->not->toBe($account);
});
