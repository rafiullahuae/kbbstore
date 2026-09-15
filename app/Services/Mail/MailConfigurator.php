<?php

declare(strict_types=1);

namespace App\Services\Mail;

use Illuminate\Support\Facades\Mail;

/**
 * Turns the rows the owner filled in into `config('mail.*')`.
 *
 * config/mail.php cannot read the database itself: config files are evaluated
 * while the framework is booting (before migrations have necessarily created
 * anything) and `php artisan config:cache` freezes whatever they return into a
 * plain-text file under bootstrap/cache -- which is the last place an SMTP
 * password should be written. So config/mail.php declares an inert `kbb`
 * mailer and this class fills it in at runtime, the moment something actually
 * asks for a mailer.
 *
 * MailServiceProvider hooks it to the resolution of the mail manager, so a page
 * that never sends mail never pays for the lookup.
 */
class MailConfigurator
{
    /** The mailer name the whole app sends through. */
    public const MAILER = 'kbb';

    public function __construct(private MailSettings $settings) {}

    /**
     * Write the live configuration.
     *
     * NOTHING FALLS BACK TO `log` ANY MORE, and that is the correction this
     * class carries. It used to: a store that had not filled the SMTP form in
     * got the log transport, which is what the owner's live server has been
     * doing with every order confirmation since the day order email shipped —
     * writing them to storage/logs and delivering them to nobody, with no error
     * anywhere on the screen. The unconfigured state now resolves to the host's
     * own mail, which needs nothing filled in, and `log` is only ever reached
     * because somebody chose it in Store → Mail.
     *
     * A half-filled SMTP form still must not throw at the transport layer on an
     * unrelated page. It falls back to the server transport instead of to the
     * log, so the customer's receipt still goes out while the screen says which
     * fields are missing; MailTester does that second half and deliberately
     * refuses to dress the fallback up as a working SMTP configuration.
     */
    public function apply(): void
    {
        config(['mail.mailers.' . self::MAILER => $this->mailerConfig()]);

        /*
         * A From address is now always set, derived from APP_URL when the owner
         * has not typed one -- see MailSettings::fromAddress(). Leaving
         * config/mail.php's `hello@example.com` in place would hand a shared
         * host a From on a domain it does not host, which it rejects outright or
         * spam-scores into oblivion; "the emails all went to junk" is the same
         * outcome for a customer as not sending them at all.
         */
        $from = $this->settings->fromAddress();

        if ($from !== '') {
            config([
                'mail.from.address' => $from,
                'mail.from.name' => $this->settings->get('mail_from_name') ?: config('app.name'),
            ]);
        }
    }

    /**
     * Teach the mail manager about the server transport.
     *
     * MailManager resolves a transport name to a `create<Name>Transport` method
     * and there is no such method for ours, so it has to be registered as a
     * custom creator. Called with the manager rather than through the Mail
     * facade because this runs inside afterResolving('mail.manager') and asking
     * the container for the thing it is in the middle of resolving is a trap
     * worth not setting.
     *
     * Idempotent: MailManager::extend overwrites the entry, so a second call
     * from refresh() costs one array write.
     *
     * @param  \Illuminate\Mail\MailManager  $manager
     */
    public static function registerTransports($manager): void
    {
        $manager->extend(
            ServerMailTransport::NAME,
            static fn (array $config = []) => new ServerMailTransport,
        );
    }

    /** Apply, then discard any mailer already built from the previous values. */
    public function refresh(): void
    {
        $this->apply();

        // Mail::purge() on a mailer that was never built is a no-op, so this is
        // safe to call unconditionally -- and without it, saving the form and
        // pressing Send Test in the same request would test the old host.
        Mail::purge(self::MAILER);
    }

    /**
     * Will a message actually leave this server, or only reach a log file?
     *
     * True for SMTP and for the host's own mail; false only for `log`. The old
     * version answered `transport === 'smtp'`, which was the same question when
     * there were two transports and the wrong answer now: it would have reported
     * the working default as "nothing is being sent".
     */
    public function usesRealTransport(): bool
    {
        return ($this->mailerConfig()['transport'] ?? 'log') !== 'log';
    }

    /** Which transport the runtime mailer resolved to: server, smtp or log. */
    public function activeTransport(): string
    {
        return match ($this->mailerConfig()['transport'] ?? 'log') {
            'smtp' => MailSettings::TRANSPORT_SMTP,
            ServerMailTransport::NAME => MailSettings::TRANSPORT_SERVER,
            default => MailSettings::TRANSPORT_LOG,
        };
    }

    /** @return array<string, mixed> */
    public function mailerConfig(): array
    {
        $chosen = $this->settings->transport();

        if ($chosen === MailSettings::TRANSPORT_LOG) {
            // Chosen, never fallen back to.
            return ['transport' => 'log', 'channel' => config('mail.mailers.log.channel')];
        }

        if ($chosen !== MailSettings::TRANSPORT_SMTP || ! $this->settings->configured()) {
            /*
             * The default, and the landing place for an SMTP form that was
             * started and not finished. The second case is deliberate: an owner
             * who half-filled the SMTP boxes has a store that still emails its
             * customers, rather than one that went silent while a banner they
             * may never see says which field is blank.
             */
            return ['transport' => ServerMailTransport::NAME];
        }

        $values = $this->settings->all();
        $port = (int) ($values['mail_port'] ?: 0);
        $encryption = $values['mail_encryption'];

        if ($port === 0) {
            $port = $encryption === 'ssl' ? 465 : 587;
        }

        /*
         * Symfony decides TLS-on-connect from the DSN scheme, not from an
         * `encryption` key: `smtps` wraps the socket immediately (port 465),
         * `smtp` connects in the clear and upgrades with STARTTLS if the server
         * offers it (port 587). Passing the scheme explicitly rather than
         * letting MailManager guess it from the port means a host running SSL
         * on a non-standard port still works.
         */
        $config = [
            'transport' => 'smtp',
            'scheme' => $encryption === 'ssl' ? 'smtps' : 'smtp',
            'host' => $values['mail_host'],
            'port' => $port,
            'username' => $values['mail_username'],
            'password' => $this->settings->password(),
            'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: null,
        ];

        /*
         * Always a timeout, defaulted rather than left null. A shared host that
         * silently drops outbound port 465 does not refuse the connection, it
         * hangs -- and an unbounded wait turns the test button into a request
         * that never comes back and a checkout that times out at the gateway.
         * 20 seconds is long enough for a slow relay and short enough to be an
         * answer.
         */
        $timeout = (int) ($values['mail_timeout'] ?: 0);
        $config['timeout'] = $timeout > 0 ? $timeout : 20;

        return $config;
    }
}
