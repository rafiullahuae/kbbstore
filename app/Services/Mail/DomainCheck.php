<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Services\SettingsService;

/**
 * "Will inboxes trust the domain?" — Emails → Sending & delivery (Lane RK, E1).
 *
 * A READ-ONLY public DNS lookup of the From address's domain: SPF, DKIM and
 * DMARC, the three records that decide inbox or spam (docs/EMAILS-AUDIT.md
 * §4). It changes nothing anywhere; it reads what any mail server on the
 * internet can read. Run only when the owner presses "Check now", never on a
 * page load, and the last answer is kept so the Overview can show it.
 *
 * What it can and cannot know, said on the screen rather than guessed:
 *   - SPF: exactly one TXT record starting v=spf1 must exist. With Google
 *     Workspace chosen it must also authorise Google (_spf.google.com).
 *   - DKIM: a key lives at <selector>._domainkey.<domain>. Google Workspace's
 *     default selector is `google`, so on that transport it is looked up. On
 *     server mail there is no selector to know — "not checked", not "missing".
 *   - DMARC: a TXT record starting v=DMARC1 at _dmarc.<domain>.
 *
 * The lookup is the one protected method, so a test replaces it rather than
 * reaching the network.
 */
class DomainCheck
{
    public const LAST_KEY = 'mail_last_dns_check';

    public function __construct(
        private MailSettings $mail,
        private SettingsService $settings,
    ) {}

    public function domain(): string
    {
        $from = $this->mail->fromAddress();
        $at = strrpos($from, '@');

        return $at === false ? '' : strtolower(substr($from, $at + 1));
    }

    /**
     * @return array{domain: string, at: string, records: list<array{record: string, host: string, status: string, found: ?string, hint: string}>}
     */
    public function run(): array
    {
        $domain = $this->domain();
        $google = $this->mail->transport() === MailSettings::TRANSPORT_GMAIL;
        $records = [];

        if ($domain === '' || preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain) !== 1) {
            $result = ['domain' => $domain, 'at' => now()->toIso8601String(), 'records' => []];
            $this->settings->set(self::LAST_KEY, $result, false);

            return $result;
        }

        // SPF
        $spf = array_values(array_filter($this->txt($domain), static fn (string $t) => stripos($t, 'v=spf1') === 0));
        $records[] = match (true) {
            $spf === [] => $this->row('SPF', $domain, 'missing', null, $google
                ? 'Add one TXT record: v=spf1 include:_spf.google.com ~all'
                : 'Add one TXT record starting v=spf1 that includes this server\'s IP (ip4:…).'),
            count($spf) > 1 => $this->row('SPF', $domain, 'problem', implode(' | ', $spf), 'There must be exactly ONE v=spf1 record; merge them into one.'),
            $google && stripos($spf[0], '_spf.google.com') === false => $this->row('SPF', $domain, 'problem', $spf[0], 'Google Workspace is chosen but the record does not include _spf.google.com.'),
            /*
             * THE SPAM-FOLDER CASE. (2.60.376)
             *
             * The owner: "emails are sending fine. but all are going to spam."
             * kbeautybliss.com's SPF is `v=spf1 include:_spf.google.com ~all`:
             * only Google may send for it. Mail this server sends with that
             * From address therefore fails SPF (softfail), and Gmail files it
             * as spam -- yet this row said "ok", because it only checked that a
             * record existed. A record that lets only Google send is a problem
             * the moment the shop sends through anything but Google.
             */
            $this->sendsThroughServer() && self::onlyGoogle($spf[0])
                => $this->row('SPF', $domain, 'problem', $spf[0], 'Only Google may send email for '.$domain.', but the shop is sending through this server, so inboxes treat it as not really from you and put it in spam. Fix: Sending using → Google Workspace (Gmail SMTP), with an App Password.'),
            default => $this->row('SPF', $domain, 'ok', $spf[0], ''),
        };

        // DKIM
        if ($google) {
            $host = 'google._domainkey.' . $domain;
            $dkim = array_values(array_filter($this->txt($host), static fn (string $t) => stripos($t, 'v=DKIM1') !== false || stripos($t, 'p=') !== false));
            $records[] = $dkim === []
                ? $this->row('DKIM', $host, 'missing', null, 'Google Admin → Apps → Google Workspace → Gmail → Authenticate email: generate the key, add the TXT record, then press Start authentication.')
                : $this->row('DKIM', $host, 'ok', mb_substr($dkim[0], 0, 80) . (mb_strlen($dkim[0]) > 80 ? '…' : ''), '');
        } else {
            $records[] = $this->row('DKIM', '<selector>._domainkey.' . $domain, 'unknown', null, 'This server\'s mail usually signs nothing. A sending service gives you its selector; with Google Workspace it is "google".');
        }

        // DMARC
        $host = '_dmarc.' . $domain;
        $dmarc = array_values(array_filter($this->txt($host), static fn (string $t) => stripos($t, 'v=DMARC1') === 0));
        $records[] = $dmarc === []
            ? $this->row('DMARC', $host, 'missing', null, 'Add a TXT record on _dmarc: v=DMARC1; p=none; rua=mailto:'.$this->mail->fromAddress())
            : $this->row('DMARC', $host, 'ok', $dmarc[0], '');

        $result = ['domain' => $domain, 'at' => now()->toIso8601String(), 'records' => $records];
        $this->settings->set(self::LAST_KEY, $result, false);

        return $result;
    }

    public function last(): ?array
    {
        $raw = $this->settings->get(self::LAST_KEY);

        if (is_string($raw) && $raw !== '') {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) ? $raw : null;
    }

    /**
     * Every TXT string at $host, joined per record. An unreachable resolver
     * answers [] — the screen then says "missing", and the owner can press
     * Check now again; nothing throws into the request.
     *
     * @return list<string>
     */
    protected function txt(string $host): array
    {
        try {
            $rows = @dns_get_record($host, DNS_TXT);
        } catch (\Throwable) {
            return [];
        }

        $out = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $text = isset($row['entries']) && is_array($row['entries'])
                ? implode('', $row['entries'])
                : (string) ($row['txt'] ?? '');

            if ($text !== '') {
                $out[] = $text;
            }
        }

        return $out;
    }

    /**
     * Does mail actually leave through this server's own mail?
     *
     * Either because that is the option chosen, or because Google Workspace is
     * chosen but its username or App Password is missing -- MailConfigurator
     * then falls back to this server's mail so orders still email, and every
     * one of those messages fails a Google-only SPF and goes to spam.
     */
    private function sendsThroughServer(): bool
    {
        $t = $this->mail->transport();

        return $t === MailSettings::TRANSPORT_SERVER
            || ($t === MailSettings::TRANSPORT_GMAIL && ! $this->mail->configured());
    }

    /**
     * True when an SPF record authorises Google and nothing else: no ip4/ip6,
     * no a/mx, no other include, no redirect. Such a record fails every
     * message that does not leave through Google's servers.
     */
    public static function onlyGoogle(string $spf): bool
    {
        if (stripos($spf, 'include:_spf.google.com') === false) {
            return false;
        }

        foreach (preg_split('/\s+/', trim($spf)) ?: [] as $term) {
            $t = strtolower(ltrim($term, '+~?-'));

            if ($t === '' || $t === 'v=spf1' || $t === 'all' || $t === 'include:_spf.google.com') {
                continue;
            }

            return false;
        }

        return true;
    }

    private function row(string $record, string $host, string $status, ?string $found, string $hint): array
    {
        return ['record' => $record, 'host' => $host, 'status' => $status, 'found' => $found, 'hint' => $hint];
    }
}
