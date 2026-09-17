<?php

declare(strict_types=1);

/**
 * The delivery record — Lane EE.
 *
 * Every mail failure in this application is swallowed on purpose, so that a
 * dead mail server cannot turn into a failed order. That trade is right and it
 * leaves the owner, who has no shell on this host, unable to see that anything
 * failed at all. App\Services\Mail\MailLog is the record he can read; these
 * tests pin that it records the truth rather than a hopeful summary of it.
 *
 * NOTHING HERE SENDS REAL MAIL. The success path uses the `array` mailer, which
 * is a genuine Symfony transport that really runs the send and really fires
 * MessageSending/MessageSent — so the events under test are the real ones —
 * while delivering into memory. Mail::fake() is deliberately NOT used for those:
 * it replaces the Mailer itself and never fires the transport events, so a test
 * built on it would assert that the fake works.
 */

use App\Services\Mail\MailLog;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/** Send one real (in-memory) message through the application's own mailer. */
function sendThroughArrayMailer(string $to, string $subject = 'Hello'): void
{
    config()->set('mail.default', 'array');

    Mail::mailer('array')->raw('body', function ($message) use ($to, $subject) {
        $message->to($to)->subject($subject);
    });
}

it('records a message that was sent, with the transport\'s own message id', function () {
    sendThroughArrayMailer('shopper@example.com', 'Your order');

    $row = DB::table('mail_deliveries')->orderByDesc('id')->first();

    expect($row)->not->toBeNull('nothing was recorded at all');
    expect($row->status)->toBe('sent');
    expect($row->recipient)->toBe('shopper@example.com');
    expect($row->subject)->toBe('Your order');

    /*
     * THE FIELD THIS WHOLE CLASS EXISTS FOR.
     *
     * A null mailer, a message dropped by a misconfigured MTA and a real
     * delivery all finish without throwing, so "no exception" proves nothing.
     * A Message-ID is evidence that comes back FROM the transport: something on
     * the other side took the message and gave it a name.
     */
    expect($row->message_id)->not->toBeNull();
    expect($row->message_id)->toContain('@');
    expect($row->error)->toBeNull();
});

it('records a send that threw as a failure, with the transport\'s words', function () {
    /*
     * A transport that genuinely fails, rather than a mock that reports it.
     * Pointing SMTP at a closed port on localhost makes Symfony's own
     * transport raise its own exception, which is the string the owner needs to
     * see and the one a stub would have invented.
     */
    config()->set('mail.default', 'smtp');
    config()->set('mail.mailers.smtp', [
        'transport' => 'smtp',
        'host' => '127.0.0.1',
        'port' => 1,
        'timeout' => 1,
    ]);

    try {
        Mail::mailer('smtp')->raw('body', fn ($m) => $m->to('shopper@example.com')->subject('Doomed'));
    } catch (\Throwable $e) {
        app(MailLog::class)->recordFailure($e);
    }

    $row = DB::table('mail_deliveries')->orderByDesc('id')->first();

    expect($row)->not->toBeNull();
    expect($row->status)->toBe('failed');
    expect($row->recipient)->toBe('shopper@example.com');
    expect($row->message_id)->toBeNull();
    expect($row->error)->not->toBeNull();
})->skip(fn () => @fsockopen('127.0.0.1', 1, $e, $s, 1) !== false, 'something is listening on port 1');

it('shows a message the transport never confirmed as a failure, not as in flight', function () {
    /*
     * The row an interrupted send leaves behind. MailLog opens a row BEFORE the
     * transport is called precisely so that a send which took the process down
     * with it still leaves evidence — and a record that still said "sending"
     * three weeks later would be worse than none.
     */
    DB::table('mail_deliveries')->insert([
        'kind' => 'order.confirmation',
        'recipient' => 'shopper@example.com',
        'subject' => 'Your order',
        'transport' => 'smtp',
        'status' => 'sending',
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);

    $entry = app(MailLog::class)->recent(10)[0];

    expect($entry['status'])->toBe('failed');
    expect($entry['error'])->not->toBeNull();

    // And the "show me what went wrong" filter must include it, or it hides
    // exactly the case the owner opened the screen for.
    expect(app(MailLog::class)->recent(10, 'failed'))->toHaveCount(1);
    expect(app(MailLog::class)->recent(10, 'sent'))->toHaveCount(0);
});

it('labels the message so the record says which email it was', function () {
    app(MailLog::class)->labelNext('order.confirmation');

    sendThroughArrayMailer('shopper@example.com');

    expect(DB::table('mail_deliveries')->orderByDesc('id')->value('kind'))
        ->toBe('order.confirmation');
});

it('does not carry a label over to an unrelated later message', function () {
    app(MailLog::class)->labelNext('password.reset');

    sendThroughArrayMailer('one@example.com');
    sendThroughArrayMailer('two@example.com');

    $rows = DB::table('mail_deliveries')->orderBy('id')->get();

    expect($rows[0]->kind)->toBe('password.reset');
    // A label that stuck would attribute an order confirmation to a password
    // reset, which is worse than saying nothing.
    expect($rows[1]->kind)->toBe(MailLog::KIND_UNKNOWN);
});

it('never stores the body of a message', function () {
    /*
     * The one rule this table cannot break. A password reset's body carries a
     * live token and a verification mail carries a live signed link; a log that
     * rendered either would be a permanent, searchable second copy of a
     * credential the notification classes go out of their way never to write
     * down.
     */
    config()->set('mail.default', 'array');

    Mail::mailer('array')->raw('SECRET-TOKEN-abc123 do not log me', function ($message) {
        $message->to('shopper@example.com')->subject('Reset your password');
    });

    $row = (array) DB::table('mail_deliveries')->orderByDesc('id')->first();

    foreach ($row as $column => $value) {
        expect((string) $value)->not->toContain('SECRET-TOKEN-abc123', "column {$column} carried the body");
    }
});

it('records every recipient, blind copies included', function () {
    config()->set('mail.default', 'array');

    Mail::mailer('array')->raw('body', function ($message) {
        $message->to('shopper@example.com')
            ->bcc('owner@example.com')
            ->subject('Order');
    });

    // "Who received this" is the question the record exists to answer, and a
    // blind copy is still a recipient.
    $recipient = (string) DB::table('mail_deliveries')->orderByDesc('id')->value('recipient');

    expect($recipient)->toContain('shopper@example.com');
    expect($recipient)->toContain('owner@example.com');
});

it('counts sent and failed so the two always add up to the total', function () {
    sendThroughArrayMailer('one@example.com');

    DB::table('mail_deliveries')->insert([
        'kind' => 'test', 'recipient' => 'x@example.com', 'subject' => 's',
        'transport' => 'smtp', 'status' => 'failed',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $counts = app(MailLog::class)->counts();

    expect($counts['sent'])->toBe(1);
    expect($counts['failed'])->toBe(1);
    expect($counts['sent'] + $counts['failed'])->toBe($counts['total']);
});

it('orders the list on id, so two mails in the same second cannot tie', function () {
    /*
     * An order confirmation and the merchant alert go out back to back and
     * their created_at values tie to the second. A tie in the sort key of a
     * list that is then sliced is the defect the stable-ordering package went
     * through this codebase to remove.
     */
    $at = now();

    foreach (['first@example.com', 'second@example.com', 'third@example.com'] as $to) {
        DB::table('mail_deliveries')->insert([
            'kind' => 'test', 'recipient' => $to, 'subject' => 's',
            'transport' => 'smtp', 'status' => 'sent',
            'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    $recipients = array_column(app(MailLog::class)->recent(10), 'recipient');

    expect($recipients)->toBe(['third@example.com', 'second@example.com', 'first@example.com']);
});

it('prunes oldest first and keeps the newest', function () {
    for ($i = 1; $i <= 12; $i++) {
        DB::table('mail_deliveries')->insert([
            'kind' => 'test', 'recipient' => "n{$i}@example.com", 'subject' => 's',
            'transport' => 'smtp', 'status' => 'sent',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    app(MailLog::class)->prune(5);

    expect(DB::table('mail_deliveries')->count())->toBe(5);
    // The newest survive: a quiet month must not erase the only record of the
    // failure the owner is trying to investigate.
    expect(DB::table('mail_deliveries')->orderBy('id')->value('recipient'))->toBe('n8@example.com');
});

it('records nothing and throws nothing when the table is not there', function () {
    /*
     * A package that landed without its migration, which has happened on this
     * host before — see PackageMigrationFlagTest. A recorder that threw here
     * would turn a logged mail failure back into the 500 the swallow exists to
     * prevent, on the checkout's own request.
     */
    Schema::dropIfExists('mail_deliveries');

    sendThroughArrayMailer('shopper@example.com');

    expect(app(MailLog::class)->recent())->toBe([]);
    expect(app(MailLog::class)->counts())->toBe(['total' => 0, 'sent' => 0, 'failed' => 0]);
});
