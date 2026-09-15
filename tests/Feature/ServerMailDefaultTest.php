<?php

/**
 * "Use this server's mail" — the default, and the bug it fixes.
 *
 * THE BUG. `mail_transport` defaulted to `smtp`; MailConfigurator fell back to
 * the `log` transport whenever the SMTP form was not filled in; the owner's live
 * store has never had that form filled in. So every order confirmation the store
 * has ever produced was written to storage/logs and delivered to nobody, and
 * nothing on any screen said so — Store → Mail reported "configured: false" in a
 * banner about SMTP fields, which reads as "there is an optional thing you have
 * not set up", not as "your customers hear nothing when they pay you".
 *
 * WHAT THESE TESTS PIN, in the order the risks matter:
 *
 *   1. An install with nothing configured sends through the server, not the log.
 *      This is the whole release.
 *   2. An install that has ALREADY chosen a transport keeps its choice. No
 *      migration writes a value for this key and none may: an owner who
 *      deliberately set up SMTP, or deliberately set the log for testing, must
 *      find the same setting after the package lands.
 *   3. The screen the owner uses says this in words they can act on, and the
 *      sentence they pick from is turned back into a stored key rather than
 *      being written into the database as prose.
 *   4. What the server transport hands to PHP's mail() is a well-formed message:
 *      no duplicated To or Subject, the MIME body intact, the envelope sender
 *      passed as -f so SPF has something to check.
 */

use App\Http\Controllers\Admin\MailApiController;
use App\Models\Setting;
use App\Services\Mail\MailConfigurator;
use App\Services\Mail\MailCredentials;
use App\Services\Mail\MailSettings;
use App\Services\Mail\MailTester;
use App\Services\Mail\ServerMailTransport;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope as MailerEnvelope;
use Symfony\Component\Mime\Address;

beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
    app(MailCredentials::class)->forget();
});

/**
 * The server transport, with the call to PHP's mail() replaced by a recorder.
 *
 * Everything above deliver() still runs: the message is really rendered, really
 * split into headers and body, really stripped of the two headers mail() writes
 * itself. Only the system call is stubbed, which is the one part a test cannot
 * usefully make and must not make — this machine has no local mail program, and
 * a suite that shells out to /usr/sbin/sendmail is a suite that behaves
 * differently on somebody else's laptop.
 */
function serverTransportSpy(): object
{
    $spy = new class extends ServerMailTransport
    {
        /** @var array<string, string>|null */
        public ?array $sent = null;

        protected function deliver(string $to, string $subject, string $body, string $headers, string $params): bool
        {
            $this->sent = compact('to', 'subject', 'body', 'headers', 'params');

            return true;
        }
    };

    Mail::extend(ServerMailTransport::NAME, fn () => $spy);

    return $spy;
}

/* ------------------------------------------------------- the new default -- */

it('sends through the server rather than the log when nothing is configured', function () {
    // Nothing saved at all: a fresh install, and the owner's live store.
    expect(Setting::query()->where('key', 'mail_transport')->exists())->toBeFalse();

    $settings = app(MailSettings::class);
    $configurator = app(MailConfigurator::class);

    expect($settings->transport())->toBe(MailSettings::TRANSPORT_SERVER)
        ->and($configurator->mailerConfig()['transport'])->toBe(ServerMailTransport::NAME)
        ->and($configurator->activeTransport())->toBe(MailSettings::TRANSPORT_SERVER)
        // The question Store → Mail asks to decide whether to shout at the owner.
        ->and($configurator->usesRealTransport())->toBeTrue()
        // And there is nothing left to fill in, so the screen has nothing to
        // demand. This is what makes "default" mean default rather than
        // "default, once you have done six things".
        ->and($settings->configured())->toBeTrue()
        ->and($settings->missing())->toBe([]);
});

it('really hands a message to the server transport on an unconfigured install', function () {
    $spy = serverTransportSpy();

    Mail::mailer(MailConfigurator::MAILER)->raw('body text', function ($message) {
        $message->to('shopper@example.com')->subject('Hello');
    });

    // Not "a mailer was resolved" — a message reached the transport and the
    // transport got as far as the mail() call.
    expect($spy->sent)->not->toBeNull()
        ->and($spy->sent['to'])->toContain('shopper@example.com')
        ->and($spy->sent['subject'])->toBe('Hello')
        ->and($spy->sent['body'])->toContain('body text');
});

it('never writes a transport row, so no install can be overwritten by one', function () {
    /*
     * The default is COMPUTED, not seeded. If a migration wrote `server` into
     * `settings` it would land on installs that had already chosen something,
     * and the choice would be gone with no way to tell it ever existed. Reading
     * and writing the whole configuration must leave the key absent.
     */
    app(MailSettings::class)->save(['mail_from_name' => 'K Beauty Bliss']);
    Setting::flushMap();

    expect(Setting::query()->where('key', 'mail_transport')->exists())->toBeFalse()
        ->and(app(MailSettings::class)->transport())->toBe(MailSettings::TRANSPORT_SERVER);
});

/* --------------------------------------------- an existing choice stands -- */

it('keeps a stored smtp choice', function () {
    app(MailSettings::class)->save([
        'mail_transport' => 'smtp',
        'mail_host' => 'smtp.hostinger.com',
        'mail_port' => '465',
        'mail_encryption' => 'ssl',
        'mail_username' => 'no-reply@kbeautybliss.com',
        'mail_password' => 'KBBSERVERTEST-pw-0011',
        'mail_from_address' => 'no-reply@kbeautybliss.com',
    ]);
    Setting::flushMap();

    $configurator = app(MailConfigurator::class);

    expect(app(MailSettings::class)->transport())->toBe(MailSettings::TRANSPORT_SMTP)
        ->and($configurator->mailerConfig()['transport'])->toBe('smtp')
        ->and($configurator->mailerConfig()['host'])->toBe('smtp.hostinger.com')
        ->and($configurator->activeTransport())->toBe(MailSettings::TRANSPORT_SMTP);
});

it('keeps a stored log choice, and never reaches log by accident', function () {
    app(MailSettings::class)->save(['mail_transport' => 'log']);
    Setting::flushMap();

    expect(app(MailConfigurator::class)->mailerConfig()['transport'])->toBe('log')
        ->and(app(MailConfigurator::class)->usesRealTransport())->toBeFalse();

    // And the other way round: with the key absent, nothing resolves to log.
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::query()->where('key', 'mail_transport')->delete();
    Setting::flushMap();

    expect(app(MailConfigurator::class)->mailerConfig()['transport'])->not->toBe('log');
});

it('falls back to the server, not the log, when a chosen smtp is half filled in', function () {
    // Host typed, nothing else — the state a form gets left in.
    app(MailSettings::class)->save(['mail_transport' => 'smtp', 'mail_host' => 'smtp.hostinger.com']);
    Setting::flushMap();

    $configurator = app(MailConfigurator::class);

    expect($configurator->mailerConfig()['transport'])->toBe(ServerMailTransport::NAME)
        ->and($configurator->usesRealTransport())->toBeTrue()
        // The screen still says what is missing. Sending anyway is not the same
        // as pretending the SMTP server works.
        ->and(app(MailSettings::class)->missing())->toContain('Password');
});

/* ------------------------------------------- what the owner actually sees -- */

it('offers the choice in plain words rather than transport names', function () {
    $body = app(MailApiController::class)->show()->getData(true);

    $field = collect($body['fields'])->firstWhere('key', 'mail_transport');

    // The admin's mailField() prints each option string as both the value and
    // the visible text, so these strings ARE the dropdown the owner reads.
    expect($field['options'])->toContain("Use this server's mail (default)")
        ->and($field['options'])->toContain('Use a dedicated SMTP server')
        // Nothing in the list requires knowing what a transport is.
        ->and($field['options'])->not->toContain('smtp')
        ->and($field['options'])->not->toContain('server')
        // And the untouched install comes back with the default selected.
        ->and($field['value'])->toBe("Use this server's mail (default)");

    // A comma in any label would break MailApiController's `in:` rule, which
    // joins this list with commas.
    foreach (MailSettings::TRANSPORTS as $label) {
        expect($label)->not->toContain(',');
    }
});

it('stores a key when the screen posts a sentence', function () {
    $response = app(MailApiController::class)->save(new Illuminate\Http\Request([
        'settings' => ['mail_transport' => 'Use a dedicated SMTP server'],
    ]));

    expect($response->getData(true)['ok'])->toBeTrue();

    Setting::flushMap();
    SettingsService::forgetMemo();

    // The row holds `smtp`, not the sentence. Rewording the label later must not
    // invalidate a single install's saved choice.
    expect(Setting::query()->where('key', 'mail_transport')->value('value'))->toBe('smtp')
        ->and(app(MailSettings::class)->transport())->toBe(MailSettings::TRANSPORT_SMTP);
});

it('reads a key written by an older release as the same choice', function () {
    // What is already in the database on the owner's server today.
    app(SettingsService::class)->set('mail_transport', 'log');
    Setting::flushMap();

    expect(app(MailSettings::class)->transport())->toBe(MailSettings::TRANSPORT_LOG)
        // ...and the screen shows the matching sentence, so the right option is
        // preselected instead of the dropdown silently reading as the default.
        ->and(app(MailSettings::class)->get('mail_transport'))
        ->toBe('Send nothing — write to the log (for testing only)');
});

/* ----------------------------------------------------------- the From -- */

it('derives a from address on this domain when the owner has not set one', function () {
    config(['app.url' => 'https://www.kbeautybliss.com/kbb-upgrade']);

    // A shared host rejects or spam-scores a From on a domain it does not host,
    // and config/mail.php's stock hello@example.com is exactly that.
    expect(app(MailSettings::class)->fromAddress())->toBe('no-reply@kbeautybliss.com');

    app(MailConfigurator::class)->apply();

    expect(config('mail.from.address'))->toBe('no-reply@kbeautybliss.com');
});

it('prefers the owner\'s own from address over the derived one', function () {
    config(['app.url' => 'https://www.kbeautybliss.com']);

    app(MailSettings::class)->save(['mail_from_address' => 'orders@kbeautybliss.com']);
    Setting::flushMap();

    expect(app(MailSettings::class)->fromAddress())->toBe('orders@kbeautybliss.com');
});

/* ------------------------------------------------- the test-send button -- */

it('proves delivery through the server transport from the test button', function () {
    $spy = serverTransportSpy();

    $result = app(MailTester::class)->send('owner@example.com');

    expect($result['ok'])->toBeTrue()
        ->and($result['status'])->toBe('sent')
        ->and($result['transport'])->toBe(MailSettings::TRANSPORT_SERVER)
        // Named, because "the mail server" means a different machine depending
        // on the setting, and the owner is being asked to trust this answer.
        ->and($result['message'])->toContain('This server accepted the message')
        // Accepted, never delivered. A shared host takes a message and filters
        // it minutes later.
        ->and(strtolower($result['message']))->not->toContain('delivered to');

    expect($spy->sent['to'])->toContain('owner@example.com');
});

it('surfaces a server refusal in words the owner can act on', function () {
    Mail::extend(ServerMailTransport::NAME, fn () => new class extends ServerMailTransport
    {
        protected function deliver(string $to, string $subject, string $body, string $headers, string $params): bool
        {
            return false;   // what mail() returns when the local MTA refuses
        }
    });

    $result = app(MailTester::class)->send('owner@example.com');

    expect($result['ok'])->toBeFalse()
        ->and($result['status'])->toBe('failed')
        // Not "something went wrong": the two real causes, named.
        ->and($result['message'])->toContain('From address')
        ->and($result['message'])->toContain('Store → Mail');
});

/* ------------------------------- what PHP's mail() is actually handed -- */

it('does not hand mail() a second To or Subject header', function () {
    $spy = serverTransportSpy();

    Mail::mailer(MailConfigurator::MAILER)->raw('hello', function ($message) {
        $message->to('shopper@example.com')->subject('Your order KBB-1');
    });

    $headers = strtolower($spy->sent['headers']);

    /*
     * PHP writes "To:" and "Subject:" itself from its first two arguments. Left
     * in the header block they arrive twice, and a duplicated To is enough for
     * several providers to score a message as spam outright.
     */
    expect($headers)->not->toContain('to:')
        ->and($headers)->not->toContain('subject:')
        // The ones that must survive.
        ->and($headers)->toContain('from:')
        ->and($headers)->toContain('mime-version:')
        ->and($headers)->toContain('content-type:');
});

it('passes the envelope sender as -f so there is something to check SPF against', function () {
    config(['app.url' => 'https://www.kbeautybliss.com']);

    $spy = serverTransportSpy();

    Mail::mailer(MailConfigurator::MAILER)->raw('hello', function ($message) {
        $message->to('shopper@example.com')->subject('x');
    });

    expect($spy->sent['params'])->toBe('-fno-reply@kbeautybliss.com');
});

it('keeps the multipart body Symfony built rather than rebuilding one', function () {
    $spy = serverTransportSpy();

    $mailer = Mail::mailer(MailConfigurator::MAILER);

    $mailer->send([], [], function ($message) {
        $message->to('shopper@example.com')
            ->subject('Both parts')
            ->html('<p>The HTML part</p>')
            ->text('The text part');
    });

    // Both parts, and the boundary that separates them: what reaches mail() is
    // the message the SMTP transport would have put on the wire.
    expect($spy->sent['headers'])->toContain('multipart/alternative')
        ->and($spy->sent['body'])->toContain('The HTML part')
        ->and($spy->sent['body'])->toContain('The text part');
});

it('keeps a blind recipient out of the visible To line', function () {
    $spy = serverTransportSpy();

    Mail::mailer(MailConfigurator::MAILER)->raw('hello', function ($message) {
        $message->to('shopper@example.com')->bcc('owner@example.com')->subject('x');
    });

    /*
     * Nothing in this app uses Bcc today. The obvious implementation — put every
     * envelope recipient in the To line — would print one customer's address to
     * another the first time something does, so it is pinned before that day.
     */
    expect($spy->sent['to'])->toContain('shopper@example.com')
        ->and($spy->sent['to'])->not->toContain('owner@example.com')
        // Delivered all the same: PHP hands the message to sendmail in -t mode,
        // which reads recipients from the headers and strips Bcc itself.
        ->and($spy->sent['headers'])->toContain('Bcc: owner@example.com');
});

it('encodes a non-ascii subject rather than putting raw utf-8 in a header', function () {
    $spy = serverTransportSpy();

    Mail::mailer(MailConfigurator::MAILER)->raw('hello', function ($message) {
        $message->to('shopper@example.com')->subject('Bestellung — glücklich');
    });

    // mail() passes the subject through verbatim, so it has to arrive in the
    // RFC 2047 form Symfony produced. Decoding it here would be mojibake in
    // half the world's mail clients.
    // RFC 2047 encodes only the words that need it, so the ASCII half stays
    // readable and the rest arrives as an encoded-word.
    expect($spy->sent['subject'])->toContain('=?utf-8?')
        ->and($spy->sent['subject'])->toStartWith('Bestellung ')
        ->and($spy->sent['subject'])->not->toContain('glücklich')
        // One line. A folded subject passed to mail() is a malformed header.
        ->and($spy->sent['subject'])->not->toContain("\r")
        ->and($spy->sent['subject'])->not->toContain("\n");
});

it('reports rather than guesses when the host has disabled mail()', function () {
    // available() is the check, and it is a check and not an assumption: a host
    // switches mail() off through disable_functions, and the difference between
    // reporting that and silently failing is the difference between an answer
    // and another month of nobody knowing.
    expect(ServerMailTransport::available())->toBeBool();

    $source = (string) file_get_contents(app_path('Services/Mail/ServerMailTransport.php'));

    expect($source)->toContain('disable_functions')
        ->and($source)->toContain("function_exists('mail')");
});

it('does not use symfony\'s sendmail transport, which needs proc_open', function () {
    /*
     * The decision, pinned so it is not quietly undone.
     *
     * config/mail.php defines a `sendmail` mailer and it is the obvious thing to
     * point "use this server's mail" at. It cannot be: Symfony's SendmailTransport
     * opens its pipe with proc_open, which shared hosts routinely disable, and it
     * needs /usr/sbin/sendmail to exist and to accept `-bs`. There is no shell on
     * the production host to check either of those, and no way to fix them from
     * the admin panel if the guess is wrong.
     */
    app(MailConfigurator::class)->apply();

    expect(config('mail.mailers.' . MailConfigurator::MAILER . '.transport'))
        ->toBe(ServerMailTransport::NAME)
        ->not->toBe('sendmail');

    // And it is not Symfony's sendmail transport wearing a different name.
    expect(app('mail.manager')->mailer(MailConfigurator::MAILER)->getSymfonyTransport())
        ->not->toBeInstanceOf(Symfony\Component\Mailer\Transport\SendmailTransport::class)
        ->toBeInstanceOf(ServerMailTransport::class);
});

it('registers the transport so a send cannot die on an unsupported driver', function () {
    /*
     * MailManager resolves a transport name to a create<Name>Transport method
     * and throws "Unsupported mail transport" when there is none. Unregistered,
     * every order email would throw inside OrderMailer's swallow — a logged line
     * and a customer who hears nothing, which is this release's own bug wearing
     * a different hat.
     */
    app()->forgetInstance('mail.manager');
    Mail::clearResolvedInstances();

    $spy = serverTransportSpy();

    expect(fn () => Mail::mailer(MailConfigurator::MAILER)->raw('x', fn ($m) => $m->to('a@b.com')->subject('s')))
        ->not->toThrow(InvalidArgumentException::class);

    expect($spy->sent)->not->toBeNull();
});

it('puts no smtp password in the message the server transport builds', function () {
    app(MailSettings::class)->save([
        'mail_transport' => 'server',
        'mail_password' => 'KBBSERVERTEST-pw-0011',
        'mail_from_address' => 'no-reply@kbeautybliss.com',
    ]);
    Setting::flushMap();

    $spy = serverTransportSpy();

    Mail::mailer(MailConfigurator::MAILER)->raw('hello', function ($message) {
        $message->to('shopper@example.com')->subject('x');
    });

    $whole = implode("\n", $spy->sent);

    expect($whole)->not->toContain('KBBSERVERTEST-pw-0011');
});

it('builds a sane envelope even for a message with no To header', function () {
    // Defensive: visibleRecipients() falls back to the envelope. A message with
    // recipients only on the envelope is unusual but legal, and it must not
    // arrive with an empty To line.
    $spy = serverTransportSpy();

    $spy->send(
        new Symfony\Component\Mime\RawMessage("From: a@b.com\r\nSubject: Raw\r\n\r\nbody"),
        new MailerEnvelope(new Address('a@b.com'), [new Address('c@d.com')]),
    );

    expect($spy->sent['to'])->toContain('c@d.com')
        ->and($spy->sent['subject'])->toBe('Raw')
        ->and($spy->sent['body'])->toBe('body');
});
