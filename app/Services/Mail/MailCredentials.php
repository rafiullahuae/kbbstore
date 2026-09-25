<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\MailCredential;
use App\Services\SecretStore;

/**
 * The SMTP password, and nothing else.
 *
 * Modelled on App\Services\Payments\GatewayCredentials, for the same reason:
 * the `settings` table is plain text and is returned wholesale by
 * `GET /admin-api/settings` (`Setting::map()`, no allowlist), so a credential
 * put there is a credential handed to every admin page load and to every
 * database backup. This reads and writes `mail_credentials.config`, which the
 * model casts `encrypted:array`.
 *
 * Nothing here returns the password to a caller that only wanted to know
 * whether one is set -- use filled() for that, and note that the admin API
 * never calls get() at all.
 *
 * ── AND IT IS THE VAULT ModuleSchema WRITES A `secret` INTO (Lane M4) ───────
 *
 * App\Services\SecretStore is `put()` and `has()` and NO GETTER. That is the
 * whole point of the interface: ModuleSchema::write() types its parameter as
 * SecretStore, so the schema physically cannot read a credential back, however
 * the render loop is later rewritten. This class keeps get() for
 * MailConfigurator, which has to build a transport out of it -- the narrowing
 * is at the SEAM, not at the store.
 *
 * put() and has() are one line each on top of save() and filled(), which have
 * always had these semantics; the interface names them rather than adding them.
 */
class MailCredentials implements SecretStore
{
    public const MAILER = 'smtp';

    /**
     * Read once per request. A page that sends mail asks for the password on
     * the way in and the configurator asks again on the way out.
     *
     * Per-instance, not static: the container binds this scoped, so it is
     * dropped at the end of a request and a queue worker does not serve the
     * next job from a memo written during the last one. That is the
     * Setting::map() trap in CLAUDE.md, avoided rather than repeated.
     *
     * @var array<string, mixed>|null
     */
    private ?array $memo = null;

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        $row = MailCredential::find(self::MAILER);

        /*
         * A decrypt failure -- rotated APP_KEY, a row written by another
         * install -- must not take a page down. An empty config reads as
         * "no password stored", which makes the screen say "not set" and the
         * test-send say "SMTP is not configured". Both are the safe answer.
         */
        try {
            $config = $row?->config;
        } catch (\Throwable) {
            $config = null;
        }

        return $this->memo = is_array($config) ? $config : [];
    }

    public function get(string $key, string $default = ''): string
    {
        $value = $this->all()[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    /** Is this credential present and non-empty? The question a screen may ask. */
    public function filled(string $key): bool
    {
        return $this->get($key) !== '';
    }

    /**
     * Merge changes in.
     *
     * A blank value means "leave the stored one alone" -- that is what lets the
     * admin screen render the password box empty without a save wiping it.
     * Passing null clears the key deliberately, which is how the screen offers
     * "remove the stored password".
     *
     * @param array<string, string|null> $values
     */
    public function save(array $values): void
    {
        $row = MailCredential::firstOrNew(['id' => self::MAILER]);

        try {
            $config = is_array($row->config) ? $row->config : [];
        } catch (\Throwable) {
            $config = [];
        }

        foreach ($values as $key => $value) {
            if ($value === null) {
                unset($config[$key]);

                continue;
            }

            if (trim((string) $value) === '') {
                continue;   // blank box = unchanged
            }

            $config[$key] = trim((string) $value);
        }

        $row->config = $config;
        $row->save();

        $this->memo = null;
    }

    public function forget(): void
    {
        $this->memo = null;
    }

    /**
     * SecretStore. `null` forgets the key; anything else is stored verbatim.
     *
     * A blank value never arrives here from ModuleSchema::write() -- a blank
     * secret box means "unchanged" and write() returns before calling this --
     * but save() below treats one as "unchanged" anyway, which is the behaviour
     * the admin screen has always had and the one that keeps a save of the SMTP
     * host from wiping the password.
     */
    public function put(string $key, ?string $value): void
    {
        $this->save([$key => $value]);
    }

    /** SecretStore. The one fact a screen may learn about a stored credential. */
    public function has(string $key): bool
    {
        return $this->filled($key);
    }
}
