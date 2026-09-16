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
        /*
         * Register the transport HERE as well as from the service provider.
         *
         * The provider does it inside afterResolving('mail.manager'), which
         * only fires for resolutions that happen after the provider
         * registered. mail.manager is a singleton, so if anything resolved it
         * earlier in the request the callback never runs for the instance that
         * is actually used -- and the first symptom is
         * "Unsupported mail transport [kbb-server]" at the moment somebody
         * presses Send test message. That is what the owner hit on the live
         * server while every test here passed, because the ordering that
         * breaks it does not occur in the test bootstrap.
         *
         * Doing it at the point of use removes the ordering question entirely:
         * anything that sends mail through this application calls apply()
         * first, and extend() is a single array write, so calling it again is
         * free and idempotent.
         *
         * Deliberately NOT wrapped in a silent catch. The provider's copy is,
         * which is why the real cause was invisible: a failure there left the
         * driver unregistered and produced an error message about the symptom
         * rather than the cause. If registration genuinely cannot work, the
         * send is going to fail anyway and the operator is better served by
         * the reason.
         */
        self::registerTransports(app('mail.manager'));

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
    /**
     * Manager instances this process has already registered the driver on.
     *
     * A WeakMap KEYED BY THE MANAGER ITSELF, not by spl_object_id().
     *
     * This was `array<int, true>` keyed on spl_object_id($manager), and that is
     * unsound: PHP reuses an object id the moment the object it belonged to is
     * freed. Proved directly — five Probe objects created and released in
     * sequence all reported id 1. So a FRESH MailManager could land on the id of
     * a retired one, be treated as already registered, and never learn the
     * kbb-server transport. The next send then threw "Unsupported mail transport
     * [kbb-server]" — the owner's original symptom, from a different cause.
     *
     * Non-deterministic by nature, because it depends on allocation history:
     * MailTransportOrderingTest failed intermittently and moved in and out of
     * failing as unrelated tests were added, which is how it was finally caught.
     * Under PHP-FPM one manager per request mostly hides it; a queue worker that
     * rebuilds the manager is where it silently costs a real order email.
     *
     * WeakMap holds no strong reference, so an entry disappears with its
     * manager and a new object can never match a dead key. Same "ensure, don't
     * replace" semantics as before, minus the collision.
     *
     * @var \WeakMap<object, true>
     */
    private static ?\WeakMap $registered = null;

    /**
     * Teach a mail manager about the server transport, once per instance.
     *
     * ENSURE, not replace. Registration is called from two places on purpose --
     * the service provider, and apply() at the point of use -- because the
     * provider's copy runs inside afterResolving('mail.manager'), which only
     * fires for resolutions that happen after it registered. mail.manager is a
     * singleton, so a request that resolved it earlier got an instance the
     * callback never touched, and the owner's first symptom was
     * "Unsupported mail transport [kbb-server]" from Send test message.
     *
     * Calling extend() unconditionally the second time would fix that and
     * break something else: anything that deliberately swapped in its own
     * transport -- the argument spies that assert what reaches mail(), a
     * debugging hook -- would be silently overwritten the next time the
     * configuration was applied. Two tests caught exactly that. So the second
     * call is a no-op, and an override registered afterwards keeps winning.
     */
    public static function registerTransports($manager): void
    {
        self::$registered ??= new \WeakMap();

        if (isset(self::$registered[$manager])) {
            return;
        }

        self::$registered[$manager] = true;

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
