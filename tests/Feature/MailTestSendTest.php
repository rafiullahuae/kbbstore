<?php

/**
 * The test-send, which is the whole point of this package.
 *
 * The plan's warning about this project is that it has twice shipped the signup
 * half of a feature without the sending half, producing a switch that looks
 * like it works and does not. A test button that reports success because
 * nothing threw would be the same mistake wearing a green tick, so these tests
 * assert the three things that make the answer worth having:
 *
 *   - a real transport failure comes back as the transport's OWN message,
 *   - the log transport is never reported as a send,
 *   - and whatever happened is written down, so the answer outlives the tab.
 *
 * The transport is swapped through Mail::extend('smtp', ...), which MailManager
 * consults before its own createSmtpTransport(). That exercises the real
 * Illuminate\Mail send path -- mailer resolution, from address, the synchronous
 * call -- while keeping the test off the network.
 */

use App\Http\Controllers\Admin\MailApiController;
use App\Models\Setting;
use App\Services\Mail\MailConfigurator;
use App\Services\Mail\MailCredentials;
use App\Services\Mail\MailSettings;
use App\Services\Mail\MailTester;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\RawMessage;

const TEST_SMTP_PASSWORD = 'KBBSENDCANARY-pw-0002';

beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
    app(MailCredentials::class)->forget();
});

/** Fill the form in as the owner would, so configured() is true. */
function configureSmtp(array $overrides = []): void
{
    app(MailSettings::class)->save(array_merge([
        'mail_transport' => 'smtp',
        'mail_host' => 'smtp.example.com',
        'mail_port' => '465',
        'mail_encryption' => 'ssl',
        'mail_username' => 'no-reply@example.com',
        'mail_password' => TEST_SMTP_PASSWORD,
        'mail_from_address' => 'no-reply@example.com',
        'mail_from_name' => 'KBB',
    ], $overrides));

    Setting::flushMap();
}

/** A transport that accepts everything, standing in for a working mail server. */
function acceptingTransport(): void
{
    Mail::extend('smtp', fn () => new class implements Symfony\Component\Mailer\Transport\TransportInterface
    {
        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            return new SentMessage($message, $envelope ?? new Envelope(
                new Symfony\Component\Mime\Address('no-reply@example.com'),
                [new Symfony\Component\Mime\Address('owner@example.com')],
            ));
        }

        public function __toString(): string
        {
            return 'accepting';
        }
    });
}

/** A transport that fails the way a real one does, with the server's own words. */
function failingTransport(string $message): void
{
    Mail::extend('smtp', fn () => new class($message) implements Symfony\Component\Mailer\Transport\TransportInterface
    {
        public function __construct(private string $message) {}

        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            throw new TransportException($this->message);
        }

        public function __toString(): string
        {
            return 'failing';
        }
    });
}

/* ------------------------------------------------------------ a real send -- */

it('records a successful send with the time, the recipient and the result', function () {
    configureSmtp();
    acceptingTransport();

    $result = app(MailTester::class)->send('owner@example.com');

    expect($result['ok'])->toBeTrue()
        ->and($result['status'])->toBe('sent')
        ->and($result['transport'])->toBe('smtp')
        ->and($result['to'])->toBe('owner@example.com');

    // And it survives the request, which is the point of recording it.
    SettingsService::forgetMemo();
    $recorded = app(MailSettings::class)->lastTest();

    expect($recorded)->toBeArray()
        ->and($recorded['ok'])->toBeTrue()
        ->and($recorded['to'])->toBe('owner@example.com')
        ->and($recorded['status'])->toBe('sent')
        ->and($recorded['at'])->toBeString()
        // A parseable timestamp, not a pretty string nobody can sort on.
        ->and(strtotime($recorded['at']))->toBeGreaterThan(0);
});

it('does not claim delivery, only that the server accepted the message', function () {
    configureSmtp();
    acceptingTransport();

    $result = app(MailTester::class)->send('owner@example.com');

    // A mail server can accept a message and bounce it minutes later. The
    // wording has to leave the owner looking at the inbox, not trusting this.
    expect(strtolower($result['message']))->toContain('accepted')
        ->and(strtolower($result['message']))->not->toContain('delivered to');
});

/* --------------------------------------------------------- a real failure -- */

it('surfaces the transport error rather than a generic failure', function () {
    configureSmtp();
    failingTransport('Connection could not be established with host "smtp.example.com:465": stream_socket_client(): Connection refused');

    $result = app(MailTester::class)->send('owner@example.com');

    expect($result['ok'])->toBeFalse()
        ->and($result['status'])->toBe('failed')
        // The server's own words. This string is the deliverable: it is what
        // tells the owner the host blocks the port rather than that the
        // password is wrong.
        ->and($result['message'])->toContain('Connection could not be established')
        ->and($result['message'])->toContain('smtp.example.com:465')
        ->and($result['error'])->toContain('TransportException');
});

it('records a failure too, so the answer is not lost with the tab', function () {
    configureSmtp();
    failingTransport('535 Incorrect authentication data');

    app(MailTester::class)->send('owner@example.com');

    SettingsService::forgetMemo();
    $recorded = app(MailSettings::class)->lastTest();

    expect($recorded['ok'])->toBeFalse()
        ->and($recorded['message'])->toContain('535 Incorrect authentication data')
        ->and($recorded['to'])->toBe('owner@example.com');
});

it('redacts the password out of a transport error before it reaches a screen', function () {
    configureSmtp();

    // Symfony quotes the DSN in some transport exceptions, and an AUTH failure
    // can echo the credential straight back. Either way it must not be rendered
    // into the admin page or written to the settings table.
    failingTransport('Failed to authenticate on SMTP server with username "no-reply@example.com" using password "' . TEST_SMTP_PASSWORD . '"');

    $result = app(MailTester::class)->send('owner@example.com');

    expect($result['message'])->not->toContain(TEST_SMTP_PASSWORD)
        ->and($result['message'])->toContain('[password redacted]')
        ->and($result['error'])->not->toContain(TEST_SMTP_PASSWORD);

    SettingsService::forgetMemo();
    expect(json_encode(app(MailSettings::class)->lastTest()))->not->toContain(TEST_SMTP_PASSWORD);
});

/* ------------------------------------------------- the honest non-answers -- */

it('refuses to pretend an unconfigured store just sent something', function () {
    // Nothing filled in, which is this app's state today.
    $result = app(MailTester::class)->send('owner@example.com');

    expect($result['ok'])->toBeFalse()
        ->and($result['status'])->toBe('unconfigured')
        // And it names the fields, so the owner knows what to do next.
        ->and($result['message'])->toContain('SMTP host')
        ->and($result['message'])->toContain('Password');
});

it('says so when the message went to the log instead of a mail server', function () {
    configureSmtp(['mail_transport' => 'log']);

    $result = app(MailTester::class)->send('owner@example.com');

    // ok, because nothing went wrong -- but never dressed up as a send.
    expect($result['ok'])->toBeTrue()
        ->and($result['status'])->toBe('logged')
        ->and($result['transport'])->toBe('log')
        ->and($result['message'])->toContain('Nothing was sent');
});

it('sends synchronously, so ok means the transport finished', function () {
    configureSmtp();

    $reached = false;

    Mail::extend('smtp', function () use (&$reached) {
        return new class($reached) implements Symfony\Component\Mailer\Transport\TransportInterface
        {
            public function __construct(private bool &$reached) {}

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                $this->reached = true;

                return new SentMessage($message, $envelope ?? new Envelope(
                    new Symfony\Component\Mime\Address('no-reply@example.com'),
                    [new Symfony\Component\Mime\Address('owner@example.com')],
                ));
            }

            public function __toString(): string
            {
                return 'sync';
            }
        };
    });

    $result = app(MailTester::class)->send('owner@example.com');

    // The transport ran inside the call, not after it on a queue. Nothing here
    // touches Queue at all -- there is no job to fail silently later.
    expect($reached)->toBeTrue()
        ->and($result['ok'])->toBeTrue();
});

/* ------------------------------------------------------------ the wiring -- */

it('builds the smtp mailer from the stored settings, not from env', function () {
    configureSmtp();

    $config = app(MailConfigurator::class)->mailerConfig();

    expect($config['transport'])->toBe('smtp')
        ->and($config['host'])->toBe('smtp.example.com')
        ->and($config['port'])->toBe(465)
        // 465 is TLS-on-connect, which Symfony takes from the scheme.
        ->and($config['scheme'])->toBe('smtps')
        ->and($config['username'])->toBe('no-reply@example.com')
        ->and($config['password'])->toBe(TEST_SMTP_PASSWORD)
        // Never unbounded: a host that silently drops port 465 hangs rather
        // than refusing, and an untimed wait never comes back.
        ->and($config['timeout'])->toBeGreaterThan(0);
});

it('uses starttls on 587 rather than wrapping the socket', function () {
    configureSmtp(['mail_port' => '587', 'mail_encryption' => 'tls']);

    expect(app(MailConfigurator::class)->mailerConfig()['scheme'])->toBe('smtp');
});

it('falls back to the log transport while the form is unfilled', function () {
    // The important half: an unconfigured store must not throw at the transport
    // layer on some unrelated page that tries to send.
    $configurator = app(MailConfigurator::class);

    expect($configurator->mailerConfig()['transport'])->toBe('log')
        ->and($configurator->usesRealTransport())->toBeFalse();
});

it('does not resolve the smtp password through config caching', function () {
    // config/mail.php is what `php artisan config:cache` serialises to a plain
    // text file under bootstrap/cache. Nothing in it may read a credential.
    $source = (string) file_get_contents(config_path('mail.php'));

    expect($source)->not->toContain('MAIL_PASSWORD_DB')
        ->and($source)->not->toContain('MailCredential')
        ->and($source)->not->toContain('DB::');

    // And the mailer the app defaults to is the runtime-configured one.
    expect($source)->toContain("env('MAIL_MAILER', 'kbb')");
});

it('applies the stored settings to the default mailer without anyone asking', function () {
    configureSmtp();

    // The five blocked features mostly send through Laravel's own plumbing --
    // Password::sendResetLink() reaches for the DEFAULT mailer and never calls
    // anything in App\Services\Mail. Resolving the manager has to be enough.
    app()->forgetInstance('mail.manager');
    Mail::clearResolvedInstances();
    app('mail.manager');

    expect(config('mail.mailers.' . MailConfigurator::MAILER . '.host'))->toBe('smtp.example.com')
        ->and(config('mail.from.address'))->toBe('no-reply@example.com');
});

/* --------------------------------------------------------- the controller -- */

it('answers a test-send with a body the screen can render, not a 500', function () {
    configureSmtp();
    failingTransport('535 Incorrect authentication data');

    $response = app(MailApiController::class)->test(new Illuminate\Http\Request(['to' => 'owner@example.com']));

    // A failed SMTP handshake is the answer that was asked for, not a server
    // fault -- and the admin bundle's fetch wrapper turns a non-2xx into
    // "Request failed", which would hide the one string that matters.
    expect($response->getStatusCode())->toBe(200);

    $body = $response->getData(true);

    expect($body['ok'])->toBeFalse()
        ->and($body['message'])->toContain('535 Incorrect authentication data');
});

it('rejects a test-send to something that is not an address', function () {
    configureSmtp();

    expect(fn () => app(MailApiController::class)->test(new Illuminate\Http\Request(['to' => 'not-an-address'])))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

it('reports the last test alongside the configuration', function () {
    configureSmtp();
    acceptingTransport();

    app(MailTester::class)->send('owner@example.com');
    SettingsService::forgetMemo();

    $body = app(MailApiController::class)->show()->getData(true);

    expect($body['configured'])->toBeTrue()
        ->and($body['missing'])->toBe([])
        ->and($body['transport'])->toBe('smtp')
        ->and($body['last_test']['to'])->toBe('owner@example.com')
        ->and($body['last_test']['ok'])->toBeTrue();
});

it('names the fields still missing so the screen can say what to fill in', function () {
    app(MailSettings::class)->save(['mail_transport' => 'smtp', 'mail_host' => 'smtp.example.com']);
    Setting::flushMap();

    $body = app(MailApiController::class)->show()->getData(true);

    expect($body['configured'])->toBeFalse()
        ->and($body['missing'])->toContain('Username')
        ->and($body['missing'])->toContain('Password')
        ->and($body['missing'])->toContain('From address')
        ->and($body['missing'])->not->toContain('SMTP host');
});
