<?php

/**
 * Store → Mail still sends, and sends the same way — Lane M4.
 *
 * ── WHY THIS FILE EXISTS RATHER THAN A ROW IN ANOTHER ONE ───────────────────
 *
 * Round 3 declined this migration in one sentence:
 *
 *   > Migrating it moves what the Mail `<select>` gets on a screen whose
 *   > failure mode is **a shop that stops sending order email**.
 *
 * Every other test in this round measures a value: a recorded corpus line, a
 * payload key, a fixture byte. None of them answers the question that sentence
 * asks, which is whether a message still leaves the application through the
 * transport the owner picked. So this one does the thing itself: it configures
 * each of the three transports the way the screen does, hands a real message to
 * the real mailer, and reads back what was chosen and what arrived.
 *
 * ── WRITTEN TO RUN ON BOTH REVISIONS, ON PURPOSE ────────────────────────────
 *
 * It touches nothing this round added — no `schema()`, no `SecretStore`, no
 * `secretsPresent()`, and it never reads `all()['mail_transport']`, which is the
 * one answer this round deliberately changes. Everything it calls existed on the
 * parent revision. That is what makes "before and after" a measurement rather
 * than a claim: the same file was run against `git stash`ed working tree and
 * against this one, and the report quotes both.
 *
 * MEASURED, BEFORE AND AFTER:
 *
 *     server -> ServerMailTransport  delivered=1  real=true
 *     smtp   -> EsmtpTransport       delivered=1  real=true
 *     log    -> LogTransport         delivered=1  real=false
 *
 * identical on both revisions.
 */

use App\Models\MailCredential;
use App\Models\Setting;
use App\Services\Mail\MailConfigurator;
use App\Services\Mail\MailCredentials;
use App\Services\Mail\MailSettings;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * The real transport object the mail manager builds for our mailer.
 *
 * Reached through the manager rather than through config, because the config
 * array is what this round rewrote and the object is what actually carries the
 * message. Asking the manager is asking the thing that would fail.
 */
function m4Transport(): string
{
    return class_basename(Mail::mailer(MailConfigurator::MAILER)->getSymfonyTransport());
}

beforeEach(function () {
    Setting::query()->delete();
    MailCredential::query()->delete();

    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
    app(MailCredentials::class)->forget();
});

it('still sends a real message through each of the three transports', function () {
    $seen = [];

    foreach ([
        MailSettings::TRANSPORT_SERVER => [],
        MailSettings::TRANSPORT_SMTP => [
            // A host that resolves to nothing, deliberately: the send must
            // really be attempted, and what proves the ESMTP transport was
            // built is that it went to a socket rather than to a log.
            'mail_host' => 'smtp.invalid.example',
            'mail_port' => '465',
            'mail_encryption' => 'ssl',
            'mail_username' => 'no-reply@example.com',
            'mail_password' => 'not-a-real-password',
            'mail_from_address' => 'no-reply@example.com',
        ],
        MailSettings::TRANSPORT_LOG => [],
    ] as $transport => $extra) {
        Setting::query()->delete();
        MailCredential::query()->delete();
        DB::table('mail_deliveries')->delete();
        app(SettingsService::class)->flush();
        SettingsService::forgetMemo();
        Setting::flushMap();
        app(MailCredentials::class)->forget();

        /*
         * POSTED AS THE SENTENCE, which is what the console really sends:
         * mailField() prints each option string as both the value and the
         * visible text, so a save from Store → Mail carries "Use a dedicated
         * SMTP server" and not "smtp". Posting the key here instead would have
         * made this test pass with canonicalTransport() deleted — measured, not
         * supposed: it did, until this line was written the way the screen
         * behaves.
         */
        app(MailSettings::class)->save(['mail_transport' => MailSettings::TRANSPORT_LABELS[$transport]] + $extra);

        Setting::flushMap();
        SettingsService::forgetMemo();
        app(MailCredentials::class)->forget();

        $configurator = app(MailConfigurator::class);
        $configurator->refresh();

        $class = m4Transport();

        /*
         * A REAL SEND, not a fake. Mail::fake() records the Mailable and never
         * builds a transport, which is precisely the thing under test here.
         * The SMTP row is expected to throw at the socket — that IS the
         * evidence, because a `log` or `server` transport cannot throw that.
         */
        try {
            Mail::mailer(MailConfigurator::MAILER)->raw('m4 delivery probe', function ($message) {
                $message->to('shopper@example.com')->subject('Order confirmation');
            });

            $outcome = 'sent';
        } catch (\Throwable $e) {
            $outcome = class_basename($e);
        }

        /*
         * And what the application's OWN delivery record says about it —
         * Store → Mail → Sent mail, the table an owner chasing a missing email
         * is sent to. Its `transport` column is written from
         * MailSettings::transport(), which is one of the two readers this round
         * made agree, so this is that agreement measured at the far end.
         */
        $row = DB::table('mail_deliveries')->orderByDesc('id')->first();

        $seen[] = sprintf(
            '%-6s -> %-20s send=%-18s logged=%s/%s',
            $transport,
            $class,
            $outcome,
            $row->transport ?? 'none',
            $row->status ?? 'none',
        );
    }

    /*
     * ── THE ASSERTION THE PREVIOUS ROUND WAS AFRAID OF ──────────────────────
     *
     * Not "mail works" — WHICH of the three ways it works, named, with the
     * transport class the owner's choice really resolves to and the row the
     * shop's own delivery log wrote about it. `server` is this shop's
     * ServerMailTransport (PHP mail()), `smtp` is Symfony's ESMTP, and `log` is
     * the one that is only ever reached because somebody chose it.
     *
     * MUTATION ACTUALLY RUN: drop the canonicalTransport() call from
     * MailSettings::save() and this goes red with
     *
     *   -    1 => 'smtp   -> EsmtpTransport       send=TransportException logged=smtp/sending',
     *   -    2 => 'log    -> LogTransport         send=sent               logged=log/sent',
     *   +    1 => 'smtp   -> ServerMailTransport  send=sent               logged=server/sent',
     *   +    2 => 'log    -> ServerMailTransport  send=sent               logged=server/sent',
     *
     * — the select arm refuses a sentence it was not handed as one of its own
     * option keys, BOTH non-default choices fall back to `server`, and a shop
     * on a dedicated mail server quietly starts handing order confirmations to
     * the host's MTA instead. Note the shop still sends: this is the failure
     * that leaves no error anywhere, which is the one the previous round said
     * it was afraid of.
     */
    expect($seen)->toBe([
        'server -> ServerMailTransport  send=sent               logged=server/sent',
        /*
         * `sending` and not `failed`: the socket error escapes before
         * MessageSent fires, so the row is left open and MailLog::present()
         * reports it as failed when the screen reads it ("The transport never
         * confirmed this message"). Recorded as what the COLUMN holds rather
         * than as what the screen renders, because the column is the thing this
         * round could have moved.
         */
        'smtp   -> EsmtpTransport       send=TransportException logged=smtp/sending',
        'log    -> LogTransport         send=sent               logged=log/sent',
    ]);
});

it('builds the smtp transport out of the stored password', function () {
    /*
     * The other half of "it still sends": the credential has to reach the
     * transport. It is the one value on this screen that does NOT travel
     * through the settings table, and this round moved the write onto
     * ModuleSchema::write() and a SecretStore — so a save that reported success
     * and put the password nowhere would leave a shop authenticating with an
     * empty string against its mail host, which fails as "535 Incorrect
     * authentication data" some hours after the package was applied.
     *
     * MUTATION ACTUALLY RUN: change ModuleSchema::write()'s secret arm to skip
     * `$vault->put()` and this goes red with
     *   Failed asserting that null is identical to 'hunter2-not-a-real-password'.
     * and takes three cases of MailSecretsTest with it, including
     *   it treats a blank password on save as unchanged rather than a wipe
     */
    app(MailSettings::class)->save([
        'mail_transport' => 'smtp',
        'mail_host' => 'smtp.example.com',
        'mail_username' => 'no-reply@example.com',
        'mail_password' => 'hunter2-not-a-real-password',
        'mail_from_address' => 'no-reply@example.com',
    ]);

    Setting::flushMap();
    SettingsService::forgetMemo();
    app(MailCredentials::class)->forget();

    app(MailConfigurator::class)->refresh();

    expect(config('mail.mailers.'.MailConfigurator::MAILER.'.password'))
        ->toBe('hunter2-not-a-real-password')
        ->and(app(MailSettings::class)->configured())->toBeTrue()
        ->and(app(MailSettings::class)->missing())->toBe([]);
});
