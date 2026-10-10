<?php

declare(strict_types=1);

use App\Services\Marketing\Bounces\BounceBook;
use App\Services\Marketing\Bounces\BounceCodes;
use App\Services\Marketing\Bounces\BounceMailbox;
use App\Services\Marketing\Bounces\BounceReader;
use App\Services\Marketing\Bounces\BounceRef;
use App\Services\Marketing\Bounces\DsnParser;
use App\Services\Marketing\Bounces\ImapTransport;
use App\Services\Marketing\UnsubscribeToken;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeImapTransport;
use Tests\Support\MarketingFixtures as F;

/**
 * Lane EB — bounces: the report parser, the "Not sending to" list, and the
 * IMAP reader that fills it from the owner's Gmail label.
 *
 * The owner: "if email bounce back, i need a proper list building of bounced
 * email etc. and those emails will auto removed from the list and sit as
 * seperate list, and i don't want to receive emails for bounce back".
 */

beforeEach(function () {
    @unlink(BounceReader::statePath());
    SettingsService::forgetMemo();
});

/** A send row for $email in a fresh campaign, and its signed Message-ID ref. */
function ebSend(string $email): array
{
    $gid = F::group('EB ' . uniqid(), []);
    $cid = F::campaign('best-sellers', $gid);
    $id = (int) DB::table('mkt_sends')->insertGetId([
        'campaign_id' => $cid, 'email' => $email, 'token' => bin2hex(random_bytes(20)), 'status' => 'sent', 'sent_at' => now(),
    ]);

    return ['id' => $id, 'campaign_id' => $cid, 'ref' => BounceRef::for($id, $email)];
}

function ebSample(string $file, string $email, string $ref = 'zz.00000000000000000000000000000000', string $token = ''): string
{
    $raw = (string) file_get_contents(base_path('tests/Fixtures/eb-dsn/' . $file));

    // Real IMAP hands back CRLF; the fixtures are stored with LF.
    return str_replace(["\r\n", "\n"], ["\n", "\r\n"], str_replace(['{{EMAIL}}', '{{REF}}', '{{TOKEN}}'], [$email, $ref, $token], $raw));
}

function ebMailbox(): void
{
    $s = app(SettingsService::class);
    $s->set('mail_transport', 'gmail');
    $s->set('mail_gmail_username', 'info@kbeautybliss.com');
    app(App\Services\Mail\MailCredentials::class)->put('gmail_password', 'abcd efgh ijkl mnop');
    $s->set(BounceMailbox::KEYS['enabled'], '1', false);
    SettingsService::forgetMemo();
}

/* ------------------------------------------------------------- the parser */

it('reads Google\'s 5.1.1 "address not found" report as a HARD bounce for the right address', function () {
    /*
     * The defect this guards: nothing read these reports at all. Google's
     * "Delivery Status Notification (Failure)" landed in the owner's inbox
     * and the dead address stayed on every list. The parser must find the
     * recipient in the delivery-status part — not the shop's own address in
     * To:, and not the Remote-MTA line folded across two lines.
     */
    $r = DsnParser::parse(ebSample('gmail-5.1.1.eml', 'Nobody.Here@Example.com', 'a1.' . str_repeat('ab', 16)));

    expect($r['type'])->toBe('bounce')
        ->and($r['recipients'])->toHaveCount(1)
        ->and($r['recipients'][0]['email'])->toBe('nobody.here@example.com')
        ->and($r['recipients'][0]['status'])->toBe('5.1.1')
        ->and($r['recipients'][0]['kind'])->toBe('hard')
        ->and($r['recipients'][0]['diagnostic'])->toContain('does not exist')
        ->and($r['refs'])->toContain('a1.' . str_repeat('ab', 16))
        ->and($r['report_id'])->toBe('66f2c1a8.050a0220.dsn511@mx.google.com');
});

it('treats 5.2.2 "mailbox full" as SOFT — counted, not removed at once', function () {
    /*
     * DECIDED (BounceCodes): RFC 3463 calls X.2.2 "useful for both permanent
     * and persistent transient errors". A full mailbox gets emptied; removing
     * a real customer on the first one is the wrong call. MUTATION: drop the
     * 5.2.2 line in kindFor() and this reads 'hard'.
     */
    $r = DsnParser::parse(ebSample('gmail-5.2.2.eml', 'full@example.com'));

    expect($r['type'])->toBe('bounce')->and($r['recipients'][0]['status'])->toBe('5.2.2')
        ->and($r['recipients'][0]['kind'])->toBe('soft');
});

it('records a 4.4.1 "delivery delayed" report as a delay that never counts toward removal', function () {
    /*
     * Google sends a Delay report while it is still retrying, then a Failure
     * report if it gives up. Counting the delay as a soft bounce would count
     * one message twice and remove an address that was in fact delivered.
     * Also: the text part is quoted-printable — decoded, not misread.
     */
    $r = DsnParser::parse(ebSample('gmail-delay-4.4.1.eml', 'slow@example.net'));

    expect($r['type'])->toBe('bounce')->and($r['recipients'][0]['kind'])->toBe('delay')
        ->and($r['recipients'][0]['status'])->toBe('4.4.1');
});

it('does NOT treat an out-of-office auto-reply as a bounce, even with "550" and "5.1.1" in its words', function () {
    /*
     * The defect: a bounce detector that greps for codes would remove every
     * customer on holiday. An automatic reply means the mailbox EXISTS and a
     * person reads it. MUTATION: move the autoreply check above the daemon
     * check and drop the From test — still autoreply; drop it entirely and the
     * message is 'unknown', never 'bounce', because no daemon sent it.
     */
    $r = DsnParser::parse(ebSample('out-of-office.eml', 'sara@example.com'));

    expect($r['type'])->toBe('autoreply')->and($r['recipients'])->toBe([]);
});

it('reads an abuse report (ARF) as a complaint, and an Exim report without a DSN part as a hard bounce', function () {
    $arf = DsnParser::parse(ebSample('arf-complaint.eml', 'angry@yahoo.com'));
    expect($arf['type'])->toBe('complaint')->and($arf['recipients'][0]['email'])->toBe('angry@yahoo.com');

    $exim = DsnParser::parse(ebSample('exim-nonstandard.eml', 'gone@example.org'));
    expect($exim['type'])->toBe('bounce')->and($exim['recipients'][0]['email'])->toBe('gone@example.org')
        ->and($exim['recipients'][0]['kind'])->toBe('hard');
});

it('classifies refusals at send time: the address, or Google telling the SHOP to slow down', function () {
    /*
     * THE DEFECT FIXED HERE: Lane MK matched /\b5\d\d\b/ and called every 5xx
     * HARD. "550 5.4.5 Daily user sending limit exceeded" is a 5xx about the
     * shop's account, so the day the limit was hit every remaining recipient
     * was marked HARD and then suppressed. MUTATION: delete the 5.4.5 test in
     * atSend() and the second expectation reads 'soft'.
     */
    $c = fn (string $m) => BounceCodes::atSend($m)['class'];

    expect($c('Expected response code "250" but got code "550", with message "550 5.1.1 The email account that you tried to reach does not exist."'))->toBe('hard')
        ->and($c('Expected response code "250" but got code "550", with message "550 5.4.5 Daily user sending limit exceeded."'))->toBe('sender')
        ->and($c('421 4.7.0 Try again later, closing connection.'))->toBe('sender')
        ->and($c('454 4.7.0 Too many login attempts, please try again later.'))->toBe('sender')
        ->and($c('451 4.3.0 Mail server temporarily rejected message.'))->toBe('sender')
        ->and($c('Connection could not be established with host "smtp.gmail.com:587"'))->toBe('sender')
        ->and($c('552 5.2.2 The email account that you tried to reach is over quota.'))->toBe('soft')
        ->and($c('550 5.7.1 Message rejected due to policy'))->toBe('sender');
});

/* ----------------------------------------------------------- the list */

it('removes a hard-bounced address from every list at once and moves a subscriber to the separate Bounced list', function () {
    /*
     * The owner's words: "auto removed from the list and sit as seperate
     * list". MUTATION: make record() return before suppress() for 'hard' and
     * email_suppressions has no row; the subscriber stays `subscribed`.
     */
    F::subscriber('dead@example.com');
    $r = app(BounceBook::class)->record(['email' => 'Dead@Example.com', 'kind' => 'hard', 'code' => '5.1.1', 'detail' => 'does not exist', 'source' => 'dsn', 'report_id' => 'r1']);

    expect($r)->toBe('suppressed')
        ->and(DB::table('email_suppressions')->where('email', 'dead@example.com')->value('reason'))->toBe('bounce')
        ->and(DB::table('email_suppressions')->where('email', 'dead@example.com')->value('code'))->toBe('5.1.1')
        ->and(DB::table('subscribers')->where('email', 'dead@example.com')->value('status'))->toBe('bounced');

    // The same report read twice is one row.
    expect(app(BounceBook::class)->record(['email' => 'dead@example.com', 'kind' => 'hard', 'source' => 'dsn', 'report_id' => 'r1']))->toBe('duplicate');
});

it('counts soft bounces and removes the address on the third within 30 days, not before', function () {
    /*
     * MUTATION: SOFT_LIMIT = 1 removes on the first full mailbox; the
     * second expectation goes red.
     */
    $book = app(BounceBook::class);
    $soft = fn (string $id) => $book->record(['email' => 'busy@example.com', 'kind' => 'soft', 'code' => '4.2.2', 'source' => 'dsn', 'report_id' => $id]);

    // One 40 days ago does not count.
    $soft('old');
    DB::table('email_bounces')->where('report_id', 'old')->update(['created_at' => now()->subDays(40)]);

    expect($soft('s1'))->toBe('counted')->and($soft('s2'))->toBe('counted')
        ->and(DB::table('email_suppressions')->where('email', 'busy@example.com')->exists())->toBeFalse()
        ->and($soft('s3'))->toBe('suppressed')
        ->and(DB::table('email_suppressions')->where('email', 'busy@example.com')->value('detail'))->toStartWith('3 soft bounces in 30 days');
});

it('restores a bounced address back to the list with its soft count reset, and never restores an unsubscribe', function () {
    F::subscriber('back@example.com');
    $book = app(BounceBook::class);
    $book->record(['email' => 'back@example.com', 'kind' => 'hard', 'source' => 'smtp']);

    expect($book->restore('back@example.com'))->toBeTrue()
        ->and(DB::table('email_suppressions')->where('email', 'back@example.com')->exists())->toBeFalse()
        ->and(DB::table('subscribers')->where('email', 'back@example.com')->value('status'))->toBe('subscribed')
        ->and(DB::table('email_bounces')->where('email', 'back@example.com')->whereNull('cleared_at')->count())->toBe(0);

    DB::table('email_suppressions')->insert(['email' => 'stop@example.com', 'reason' => 'unsubscribe', 'created_at' => now()]);
    expect($book->restore('stop@example.com'))->toBeFalse()
        ->and(DB::table('email_suppressions')->where('email', 'stop@example.com')->value('reason'))->toBe('unsubscribe');
});

it('records but removes nothing when "Remove bounced addresses automatically" is switched off', function () {
    app(SettingsService::class)->set(BounceBook::AUTO_KEY, '0', false);
    SettingsService::forgetMemo();

    expect(app(BounceBook::class)->record(['email' => 'x@example.com', 'kind' => 'hard', 'source' => 'dsn']))->toBe('recorded')
        ->and(DB::table('email_suppressions')->where('email', 'x@example.com')->exists())->toBeFalse()
        ->and(DB::table('email_bounces')->where('email', 'x@example.com')->exists())->toBeTrue();
});

/* --------------------------------------------------------- the reader */

it('reads ONLY the bounce label over IMAP, files each report and moves it to Processed — the inbox is never opened', function () {
    /*
     * The owner's account holds his real mail. The reader must SELECT the
     * label and nothing else, fetch only UIDs that label's own SEARCH
     * returned, never mark anything read (BODY.PEEK), and never EXPUNGE.
     * MUTATION: change select($this->box->label()) to select('INBOX') and the
     * SELECT assertion and the inbox's untouched message both go red.
     */
    ebMailbox();
    $a = ebSend('dead@example.com');
    F::customer('busy@example.com');

    $fake = new FakeImapTransport([
        'INBOX' => [7 => "Subject: a customer's order question\r\n\r\nHello"],
        'KBB Bounces' => [
            11 => ebSample('gmail-5.1.1.eml', 'dead@example.com', $a['ref']),
            12 => ebSample('gmail-5.2.2.eml', 'busy@example.com'),
            13 => ebSample('out-of-office.eml', 'someone@example.com'),
            14 => ebSample('gmail-5.1.1.eml', 'stranger@nowhere.example'),
        ],
    ]);
    app()->instance(ImapTransport::class, $fake);

    $r = app(BounceReader::class)->run();

    expect($r['ok'])->toBeTrue()->and($r['read'])->toBe(4)->and($r['hard'])->toBe(1)->and($r['soft'])->toBe(1)->and($r['ignored'])->toBe(2);

    $selects = array_values(array_filter($fake->log, fn ($l) => str_starts_with($l, 'SELECT')));
    expect($selects)->toBe(['SELECT "KBB Bounces"']);

    foreach ($fake->log as $line) {
        expect($line)->not->toMatch('/^(EXPUNGE|STORE|UID STORE|DELETE|EXAMINE)\b/')
            ->and($line)->not->toContain('INBOX')
            ->and($line)->not->toContain('abcdefghijklmnop');   // the password is never logged by us either
        if (str_starts_with($line, 'UID FETCH')) {
            expect($line)->toContain('BODY.PEEK');
        }
    }

    expect(array_keys($fake->boxes['KBB Bounces']))->toBe([])
        ->and(array_keys($fake->boxes['KBB Bounces/Processed']))->toBe([11, 12, 13, 14])
        ->and(array_keys($fake->boxes['INBOX']))->toBe([7]);

    // Attributed to the campaign by the signed Message-ID it quoted.
    $row = DB::table('email_bounces')->where('email', 'dead@example.com')->first();
    expect((int) $row->campaign_id)->toBe($a['campaign_id'])->and((int) $row->send_id)->toBe($a['id'])
        ->and(DB::table('email_suppressions')->where('email', 'dead@example.com')->value('reason'))->toBe('bounce')
        // A stranger the shop never mailed is written nowhere.
        ->and(DB::table('email_bounces')->where('email', 'stranger@nowhere.example')->exists())->toBeFalse();

    // Read again: the label is empty, nothing is counted twice.
    $again = app(BounceReader::class)->run();
    expect($again['read'])->toBe(0)->and(DB::table('email_bounces')->count())->toBe(2);
});

it('opens no connection at all while "Read bounces" is off', function () {
    ebMailbox();
    app(SettingsService::class)->set(BounceMailbox::KEYS['enabled'], '0', false);
    SettingsService::forgetMemo();
    $fake = new FakeImapTransport(['KBB Bounces' => [1 => 'x']]);
    app()->instance(ImapTransport::class, $fake);

    expect(app(BounceReader::class)->run()['ran'])->toBeFalse()->and($fake->log)->toBe([]);
});

it('says plainly when Google refuses the app password, without echoing it', function () {
    ebMailbox();
    $fake = new FakeImapTransport(['KBB Bounces' => []]);
    $fake->password = 'a-different-one';
    app()->instance(ImapTransport::class, $fake);

    $t = app(BounceReader::class)->test();
    expect($t['ok'])->toBeFalse()->and($t['message'])->toContain('refused the sign-in')
        ->and($t['message'])->not->toContain('abcdefghijklmnop');

    $fake->password = 'abcdefghijklmnop';
    expect(app(BounceReader::class)->test())->toMatchArray(['ok' => true, 'waiting' => 0]);
});

it('refuses to point the reader at the inbox, Sent, Spam or any Gmail system folder', function () {
    /*
     * The reader MOVES what it reads. A label setting of "INBOX" would make
     * it file the owner's real mail. MUTATION: drop 'inbox' from the refused
     * names and the first expectation is red.
     */
    foreach (['INBOX', 'inbox/x', 'Sent', 'Spam', '[Gmail]/All Mail', 'Trash', '', 'a"b', "x\r\nLOGOUT"] as $bad) {
        expect(BounceMailbox::cleanLabel($bad))->toBeNull($bad);
    }

    expect(BounceMailbox::cleanLabel('KBB Bounces'))->toBe('KBB Bounces')
        ->and(BounceMailbox::cleanLabel('Shop/Bounces'))->toBe('Shop/Bounces');
});

it('honours the List-Unsubscribe mailto: read from the label, by its signed token only', function () {
    ebMailbox();
    $a = ebSend('leaver@example.com');
    F::subscriber('leaver@example.com');
    $token = UnsubscribeToken::for($a['id'], 'leaver@example.com');

    $reader = app(BounceReader::class);
    expect($reader->file(ebSample('mailto-unsubscribe.eml', 'leaver@example.com', '', $token))['unsubscribe'])->toBe(1)
        ->and(DB::table('email_suppressions')->where('email', 'leaver@example.com')->value('reason'))->toBe('unsubscribe')
        ->and(DB::table('subscribers')->where('email', 'leaver@example.com')->value('status'))->toBe('unsubscribed');

    // A forged token unsubscribes nobody.
    $forged = substr($token, 0, -1) . (str_ends_with($token, '0') ? '1' : '0');
    expect($reader->file(ebSample('mailto-unsubscribe.eml', 'x@example.com', '', $forged))['ignored'])->toBe(1);
});

it('will not attribute a report to a send whose Message-ID signature does not match', function () {
    /*
     * The label is filled by a From-line filter, and a From line is easy to
     * forge. A ref for send N signed for ANOTHER address must not resolve.
     */
    $a = ebSend('real@example.com');
    $forged = base_convert((string) $a['id'], 10, 36) . '.' . str_repeat('0', 32);

    expect(BounceRef::resolve($a['ref']))->not->toBeNull()
        ->and(BounceRef::resolve($forged))->toBeNull();
});
