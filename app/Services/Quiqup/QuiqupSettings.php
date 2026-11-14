<?php

declare(strict_types=1);

namespace App\Services\Quiqup;

use App\Models\QuiqupConfig;
use App\Support\OrderAddress;
use App\Support\UaePhone;
use App\Support\Url;
use Illuminate\Support\Str;

/**
 * Store -> Quiqup's configuration, read and written in one place.
 *
 * NOT the settings table (QUIQUP-PLAN §4.1, §8): nothing here is read on a shop
 * page, so the settings map and SettingsRequestMemo never see this module.
 *
 * SECRETS ARE WRITE-ONLY. publicView() turns each one into {filled, last4} and
 * nothing in this class returns a secret to a caller that renders it. The base
 * URL of each environment is a code constant (HOSTS) -- no host is ever read
 * from a setting, so there is no SSRF surface (§5.1).
 */
final class QuiqupSettings
{
    public const ENVS = ['sandbox', 'live'];

    /** The two documented last-mile hosts (§1.1). Constants on purpose. */
    public const HOSTS = [
        'sandbox' => 'https://api.staging.quiqup.com',
        'live' => 'https://api-ae.quiqup.com',
    ];

    public const DASHBOARDS = [
        'sandbox' => 'https://quiqdash-beta.staging.quiqup.com',
        'live' => 'https://business-ae-beta.quiqup.com',
    ];

    /** The services the sheet may book (§1.4). Return/export are not offered. */
    public const SERVICES = [
        'partner_same_day' => 'Same Day',
        'partner_next_day' => 'Next Day',
        'partner_4hr' => '4-hour',
    ];

    /** Our emirate spelling -> the spelling the Quiqup docs use (§1.3). */
    public const EMIRATE_MAP = [
        'Abu Dhabi' => 'Abu Dhabi',
        'Dubai' => 'Dubai',
        'Sharjah' => 'Sharjah',
        'Ajman' => 'Ajman',
        'Umm Al Quwain' => 'Umm al-Qaywayn',
        'Ras Al Khaimah' => 'Ras Al-Khaimah',
        'Fujairah' => 'Fujairah',
    ];

    /** How long the previous webhook signing secret is still accepted. */
    public const ROTATION_GRACE_SECONDS = 86400;

    private const TEXT = '/^[^\x00-\x1F\x7F<>]*$/u';

    /**
     * THE MODE (the owner, 14 Nov 2026): Off -> Internal testing -> Live.
     *
     *   off      nothing is booked; bookings already made keep syncing.
     *   testing  on the live website, only for test orders (isTestOrder()):
     *            the owner's own orders, by email, phone or a per-order flag.
     *            Booked on Quiqup's STAGING host and key by default, or with a
     *            real courier on production (TESTING_ENV_LABELS).
     *   live     the production host and key, every order.
     *
     * Going live is gated by checklist(); every change is audited and kept
     * in options['mode_log']. A shipment remembers the environment that
     * created it (shipments.env) and is always synced, labelled and
     * cancelled there, whatever the mode is later.
     */
    public const MODES = ['off', 'testing', 'live'];

    public const MODE_LABELS = ['off' => 'Off', 'testing' => 'Internal testing', 'live' => 'Live'];

    /**
     * WHERE INTERNAL TESTING BOOKS (the owner, 14 Nov 2026: "if quiqup has
     * staging/test api, then i would love to use test apis, so we can check
     * everything instant"). Staging is the default; Live books the owner's
     * TEST orders with a real, billed courier and is gated by liveMissing().
     * Non-test orders are refused in Internal testing either way. Written
     * only by setTestingEnv(); a stored value outside ENVS reads as staging.
     */
    public const TESTING_ENV_LABELS = [
        'sandbox' => 'Quiqup test system (staging)',
        'live' => 'Live (real courier) — test orders only',
    ];

    public function mode(): string
    {
        $m = $this->options()['mode'] ?? 'off';

        return in_array($m, self::MODES, true) ? $m : 'off';
    }

    /**
     * The environment NEW bookings use: live in Live mode; in Internal
     * testing, the one "Internal testing books on" names; staging otherwise.
     * A shipment keeps the env it was created with (shipments.env).
     */
    public function env(): string
    {
        $o = $this->options();

        return self::envFor(in_array($o['mode'] ?? 'off', self::MODES, true) ? (string) $o['mode'] : 'off', self::cleanTestingEnv($o['testing_env'] ?? null));
    }

    private static function envFor(string $mode, string $testingEnv): string
    {
        return $mode === 'live' ? 'live' : ($mode === 'testing' ? $testingEnv : 'sandbox');
    }

    /** Where Internal testing books: 'sandbox' (default) or 'live'. */
    public function testingEnv(): string
    {
        return self::cleanTestingEnv($this->options()['testing_env'] ?? null);
    }

    private static function cleanTestingEnv(mixed $v): string
    {
        return is_string($v) && in_array($v, self::ENVS, true) ? $v : 'sandbox';
    }

    public function row(string $env): QuiqupConfig
    {
        $env = in_array($env, self::ENVS, true) ? $env : 'sandbox';

        return QuiqupConfig::query()->find($env) ?? new QuiqupConfig(['id' => $env, 'active' => false]);
    }

    /** @return array<string, mixed> */
    public function secrets(string $env): array
    {
        $s = $this->row($env)->secrets;

        return is_array($s) ? $s : [];
    }

    public function apiKey(string $env): string
    {
        return (string) ($this->secrets($env)['api_key'] ?? '');
    }

    public function configured(string $env): bool
    {
        return $this->apiKey($env) !== '';
    }

    /**
     * The signing secrets a webhook may be verified against right now: the
     * current one, and for 24 h after a replacement the previous one (§5.2).
     *
     * @return list<string>
     */
    public function signingSecrets(string $env): array
    {
        $s = $this->secrets($env);
        $out = [];

        if (($s['webhook_secret'] ?? '') !== '') {
            $out[] = (string) $s['webhook_secret'];
        }

        $rotated = (int) ($s['webhook_secret_rotated_at'] ?? 0);

        if (($s['webhook_secret_prev'] ?? '') !== '' && $rotated > 0 && time() - $rotated <= self::ROTATION_GRACE_SECONDS) {
            $out[] = (string) $s['webhook_secret_prev'];
        }

        return $out;
    }

    public function urlSecret(string $env): string
    {
        return (string) ($this->secrets($env)['url_secret'] ?? '');
    }

    /**
     * Pickup, services and status mapping are the SHOP's, not an
     * environment's: one set, kept on the sandbox row, so switching to Live
     * does not silently blank the pickup address.
     */
    public const OPTIONS_ROW = 'sandbox';

    /** @return array<string, mixed> */
    public function options(): array
    {
        $stored = $this->row(self::OPTIONS_ROW)->options;

        return self::mergeOptions(self::defaults(), is_array($stored) ? $stored : []);
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'pickup' => ['contact_name' => '', 'contact_phone' => '', 'address1' => '', 'address2' => '', 'town' => 'Dubai', 'emirate' => 'Dubai', 'notes' => ''],
            'services' => ['enabled' => ['partner_next_day'], 'default' => 'partner_next_day', 'by_method' => []],
            'parcels' => 1,
            'release' => true,
            'note_policy' => 'customer',
            'four_hour_time' => '10:00',
            'cutoffs' => ['partner_same_day' => '', 'partner_next_day' => '', 'partner_4hr' => ''],
            'send_products' => true,
            'uae_only' => true,
            'statuses' => QuiqupStates::DEFAULTS,
            // Internal testing: who counts as a test customer. Orders are
            // order ids flagged one by one from the Orders list.
            'test' => ['emails' => [], 'phones' => [], 'orders' => []],
            // Written only by setMode().
            'mode' => 'off',
            'mode_log' => [],
            // Written only by setTestingEnv().
            'testing_env' => 'sandbox',
        ];
    }

    /** @param array<string, mixed> $stored @return array<string, mixed> */
    private static function mergeOptions(array $defaults, array $stored): array
    {
        $out = $defaults;

        foreach ($stored as $k => $v) {
            if (! array_key_exists($k, $defaults)) {
                continue;
            }

            if (is_array($defaults[$k]) && is_array($v) && ! array_is_list($defaults[$k]) && $k !== 'by_method' && $k !== 'test') {
                $out[$k] = self::mergeOptions($defaults[$k], $v);
            } else {
                $out[$k] = $v;
            }
        }

        // by_method is a free map (method title -> service), kept as stored.
        if (isset($stored['services']['by_method']) && is_array($stored['services']['by_method'])) {
            $out['services']['by_method'] = $stored['services']['by_method'];
        }

        return $out;
    }

    public function webhookUrl(string $env): string
    {
        $secret = $this->urlSecret($env);

        return $secret === '' ? '' : Url::external('/api/shipping/quiqup/webhook/' . $secret);
    }

    /**
     * What GET /admin-api/quiqup-settings returns. Secrets as {filled, last4}.
     *
     * @return array<string, mixed>
     */
    public function publicView(): array
    {
        $env = $this->env();
        $envs = [];

        foreach (self::ENVS as $e) {
            $row = $this->row($e);
            $s = $this->secrets($e);
            $envs[$e] = [
                'api_key' => self::mask((string) ($s['api_key'] ?? '')),
                'webhook_secret' => self::mask((string) ($s['webhook_secret'] ?? '')),
                'webhook_url' => $this->webhookUrl($e),
                'dashboard' => self::DASHBOARDS[$e],
                'host' => parse_url(self::HOSTS[$e], PHP_URL_HOST),
                'last_webhook_at' => $row->last_webhook_at?->toIso8601String(),
                'last_webhook_state' => $row->last_webhook_state,
                'last_poll_at' => $row->last_poll_at?->toIso8601String(),
                'last_poll_error' => $row->last_poll_error,
                'verified_at' => $row->verified_at?->toIso8601String(),
            ];
        }

        return [
            'mode' => $this->mode(),
            'mode_labels' => self::MODE_LABELS,
            'mode_log' => array_reverse($this->options()['mode_log'] ?? []),
            'testing_env' => $this->testingEnv(),
            'testing_env_labels' => self::TESTING_ENV_LABELS,
            'testing_live_missing' => $this->liveMissing(),
            'checklist' => $this->checklist(),
            'env' => $env,
            'configured' => $this->configured($env),
            'envs' => $envs,
            'options' => $this->options(),
            'services' => self::SERVICES,
            'emirates' => array_keys(self::EMIRATE_MAP),
            'states' => QuiqupStates::ALL,
            'state_labels' => QuiqupStates::ADMIN_LABELS,
            'targets' => QuiqupStates::TARGETS,
        ];
    }

    /** @return array{filled: bool, last4: string} */
    public static function mask(string $secret): array
    {
        return ['filled' => $secret !== '', 'last4' => $secret === '' ? '' : substr($secret, -4)];
    }

    /**
     * Validate and store one save from Store -> Quiqup. Returns the list of
     * human-readable things that changed (for the audit line), or throws
     * InvalidArgumentException with the first problem.
     *
     * @param  array<string, mixed>  $in
     * @return list<string>
     */
    public function save(array $in): array
    {
        $changed = [];
        $env = $this->env();

        // Secrets are written to the environment named in the request (the one
        // whose card the owner typed into), defaulting to the active one.
        $target = isset($in['secrets_env']) && in_array($in['secrets_env'], self::ENVS, true) ? (string) $in['secrets_env'] : $env;
        $row = $this->row($target);
        $secrets = is_array($row->secrets) ? $row->secrets : [];
        $secretsChanged = false;

        if (isset($in['api_key']) && is_string($in['api_key']) && trim($in['api_key']) !== '') {
            $key = trim($in['api_key']);

            if (strlen($key) > 4096 || preg_match('/^[\x21-\x7E]+$/', $key) !== 1) {
                throw new \InvalidArgumentException('That API key has spaces or characters an API key never has. Copy it again from Quiqdash → Organization → API keys.');
            }

            $secrets['api_key'] = $key;
            $secretsChanged = true;
            // A new key has not passed Test connection yet.
            $row->verified_at = null;
            $changed[] = $target . ' API key replaced';
        }

        if (isset($in['webhook_secret']) && is_string($in['webhook_secret']) && trim($in['webhook_secret']) !== '') {
            $ws = trim($in['webhook_secret']);

            if (strlen($ws) > 512 || preg_match('/^[\x21-\x7E]+$/', $ws) !== 1) {
                throw new \InvalidArgumentException('That signing secret has spaces or characters a signing secret never has. Reveal it again in Quiqdash (the eye icon) and copy it.');
            }

            if (($secrets['webhook_secret'] ?? '') !== '' && $secrets['webhook_secret'] !== $ws) {
                $secrets['webhook_secret_prev'] = $secrets['webhook_secret'];
                $secrets['webhook_secret_rotated_at'] = time();
            }

            $secrets['webhook_secret'] = $ws;
            $secretsChanged = true;
            $changed[] = $target . ' webhook signing secret replaced';
        }

        if (! empty($in['regenerate_url']) || ($secrets['url_secret'] ?? '') === '') {
            $secrets['url_secret'] = Str::random(40);
            $secretsChanged = true;

            if (! empty($in['regenerate_url'])) {
                $changed[] = $target . ' webhook address regenerated';
            }
        }

        if ($secretsChanged) {
            $row->secrets = $secrets;
            $row->save();
        }

        if (isset($in['options']) && is_array($in['options'])) {
            unset($in['options']['mode'], $in['options']['mode_log'], $in['options']['testing_env']);
            $clean = self::cleanOptions($in['options'], $this->options());
            $row = $this->row(self::OPTIONS_ROW);
            $row->options = $clean;
            $row->save();
            $changed[] = 'options saved';
        }

        return $changed;
    }

    /**
     * Every option is re-built from its allowlist: a select stores one of its
     * own options or the default, text is length-capped and free of control
     * characters and angle brackets, numbers are clamped.
     *
     * @param  array<string, mixed>  $in
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    public static function cleanOptions(array $in, array $current): array
    {
        $d = self::defaults();
        $out = $current;

        $text = static function (mixed $v, int $max): string {
            $v = trim(is_scalar($v) ? (string) $v : '');
            $v = mb_substr($v, 0, $max);

            return preg_match(self::TEXT, $v) === 1 ? $v : '';
        };
        $time = static fn (mixed $v): string => is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) === 1 ? $v : '';

        if (isset($in['pickup']) && is_array($in['pickup'])) {
            $p = $in['pickup'];
            $phone = $text($p['contact_phone'] ?? '', 40);
            $e164 = UaePhone::e164($phone) ?? (preg_match('/^\+[1-9]\d{7,14}$/', $phone) === 1 ? $phone : null);

            if ($phone !== '' && $e164 === null) {
                throw new \InvalidArgumentException('The pickup phone must be a full number with the country code, for example +971 50 123 4567.');
            }

            $em = (string) ($p['emirate'] ?? '');
            $out['pickup'] = [
                'contact_name' => $text($p['contact_name'] ?? '', 100),
                'contact_phone' => $e164 ?? '',
                'address1' => $text($p['address1'] ?? '', 200),
                'address2' => $text($p['address2'] ?? '', 200),
                'town' => $text($p['town'] ?? '', 100),
                'emirate' => array_key_exists($em, self::EMIRATE_MAP) ? $em : $d['pickup']['emirate'],
                'notes' => $text($p['notes'] ?? '', 250),
            ];
        }

        if (isset($in['services']) && is_array($in['services'])) {
            $s = $in['services'];
            $enabled = array_values(array_intersect(array_keys(self::SERVICES), is_array($s['enabled'] ?? null) ? $s['enabled'] : []));

            if ($enabled === []) {
                $enabled = $d['services']['enabled'];
            }

            $default = in_array($s['default'] ?? null, $enabled, true) ? $s['default'] : $enabled[0];
            $by = [];

            foreach (is_array($s['by_method'] ?? null) ? array_slice($s['by_method'], 0, 40, true) : [] as $method => $kind) {
                $method = $text($method, 100);

                if ($method !== '' && in_array($kind, $enabled, true)) {
                    $by[$method] = $kind;
                }
            }

            $out['services'] = ['enabled' => $enabled, 'default' => $default, 'by_method' => $by];
        }

        if (array_key_exists('parcels', $in)) {
            $out['parcels'] = max(1, min(10, (int) $in['parcels']));
        }

        foreach (['release', 'send_products', 'uae_only'] as $flag) {
            if (array_key_exists($flag, $in)) {
                $out[$flag] = (bool) $in[$flag];
            }
        }

        if (array_key_exists('note_policy', $in)) {
            $out['note_policy'] = in_array($in['note_policy'], ['customer', 'none'], true) ? $in['note_policy'] : $d['note_policy'];
        }

        if (array_key_exists('four_hour_time', $in)) {
            $t = $time($in['four_hour_time']);
            $out['four_hour_time'] = $t !== '' && $t >= '08:00' && $t <= '18:59' ? $t : $d['four_hour_time'];
        }

        if (isset($in['cutoffs']) && is_array($in['cutoffs'])) {
            foreach (array_keys(self::SERVICES) as $k) {
                $out['cutoffs'][$k] = $time($in['cutoffs'][$k] ?? '');
            }
        }

        if (isset($in['test']) && is_array($in['test'])) {
            $emails = [];
            foreach (array_slice(is_array($in['test']['emails'] ?? null) ? $in['test']['emails'] : [], 0, 50) as $e) {
                $e = strtolower(trim(is_string($e) ? $e : ''));
                if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) !== false && strlen($e) <= 190) {
                    $emails[$e] = true;
                } elseif ($e !== '') {
                    throw new \InvalidArgumentException('"' . mb_substr($e, 0, 60) . '" is not an email address.');
                }
            }
            $phones = [];
            foreach (array_slice(is_array($in['test']['phones'] ?? null) ? $in['test']['phones'] : [], 0, 50) as $ph) {
                $ph = trim(is_string($ph) ? $ph : '');
                if ($ph === '') {
                    continue;
                }
                $n = UaePhone::e164($ph) ?? (preg_match('/^\+[1-9]\d{7,14}$/', preg_replace('/[\s\-()]/', '', $ph) ?? '') === 1 ? preg_replace('/[\s\-()]/', '', $ph) : null);
                if ($n === null) {
                    throw new \InvalidArgumentException('"' . mb_substr($ph, 0, 40) . '" is not a phone number with its country code.');
                }
                $phones[$n] = true;
            }
            $out['test']['emails'] = array_keys($emails);
            $out['test']['phones'] = array_keys($phones);
        }

        if (isset($in['statuses']) && is_array($in['statuses'])) {
            foreach (QuiqupStates::ALL as $state) {
                $row = is_array($in['statuses'][$state] ?? null) ? $in['statuses'][$state] : null;

                if ($row === null) {
                    continue;
                }

                $status = (string) ($row['status'] ?? '');
                $out['statuses'][$state] = [
                    'status' => in_array($status, QuiqupStates::TARGETS, true) ? $status : QuiqupStates::DEFAULTS[$state]['status'],
                    'en' => $text($row['en'] ?? '', 60),
                    'ar' => $text($row['ar'] ?? '', 60),
                    'email' => (bool) ($row['email'] ?? false),
                    'push' => (bool) ($row['push'] ?? false),
                ];
            }
        }

        return $out;
    }

    /**
     * Is this one of the owner's test orders? Email or phone on the allowlist,
     * or the order flagged by hand. Plain comparisons on columns the caller
     * already holds: no query.
     */
    public function isTestOrder(\App\Models\Order $order, ?array $options = null): bool
    {
        $t = ($options ?? $this->options())['test'] ?? [];

        if (in_array((int) $order->id, array_map('intval', $t['orders'] ?? []), true)) {
            return true;
        }

        if ($order->email !== null && in_array(strtolower(trim((string) $order->email)), $t['emails'] ?? [], true)) {
            return true;
        }

        $addr = is_array($order->shipping_address) ? $order->shipping_address : [];
        foreach ([$order->phone, $addr['phone'] ?? null] as $ph) {
            $n = UaePhone::e164(is_string($ph) ? $ph : null) ?? (is_string($ph) ? preg_replace('/[\s\-()]/', '', $ph) : null);
            if ($n !== null && $n !== '' && in_array($n, $t['phones'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    /** Flag or unflag one order as a test order (cap 200). */
    public function setTestOrder(int $orderId, bool $on): void
    {
        $o = $this->options();
        $ids = array_values(array_unique(array_map('intval', $o['test']['orders'] ?? [])));
        $ids = $on ? array_values(array_unique(array_merge($ids, [$orderId]))) : array_values(array_diff($ids, [$orderId]));
        $o['test']['orders'] = array_slice($ids, -200);
        $row = $this->row(self::OPTIONS_ROW);
        $row->options = $o;
        $row->save();
    }

    /**
     * What must be true before Live can be switched on. Each item is shown
     * with a tick on the Go-Live confirmation; all must pass.
     *
     * @return list<array{key: string, label: string, ok: bool, detail: string}>
     */
    public function checklist(): array
    {
        // Delivered on EITHER environment counts (staging, or a real courier
        // when Internal testing books on Live); the detail says which.
        $envs = \App\Models\Shipment::query()->where('test', true)->where('state', 'delivery_complete')
            ->distinct()->pluck('env')->all();
        $where = array_values(array_filter([
            in_array('sandbox', $envs, true) ? 'on staging' : null,
            in_array('live', $envs, true) ? 'with a real courier' : null,
        ]));
        $endToEnd = $where !== [];

        return [...$this->liveBasics(), ['key' => 'test_order', 'label' => 'At least one test order delivered end to end in Internal testing', 'ok' => $endToEnd,
            'detail' => $endToEnd ? 'Done — ' . implode(' and ', $where) . '.' : 'Book one of your own orders in Internal testing and drive it to Delivered (in staging Quiqdash, or with a real courier).',
            'envs' => array_values(array_intersect(self::ENVS, $envs))]];
    }

    /**
     * What a real courier needs before ANY booking can go to production: the
     * first three Go-Live checks. Also the gate for "Internal testing books
     * on: Live".
     *
     * @return list<array{key: string, label: string, ok: bool, detail: string}>
     */
    private function liveBasics(): array
    {
        $live = $this->row('live');
        $p = $this->options()['pickup'];

        return [
            ['key' => 'live_key', 'label' => 'Live API key saved and verified with Test connection', 'ok' => $this->configured('live') && $live->verified_at !== null,
                'detail' => ! $this->configured('live') ? 'Paste the production key under Connection → Live.' : ($live->verified_at === null ? 'Press Test connection on the Live card.' : 'Verified ' . $live->verified_at->toDateTimeString() . ' UTC')],
            ['key' => 'live_webhook', 'label' => 'Live webhook signing secret saved', 'ok' => ($this->secrets('live')['webhook_secret'] ?? '') !== '',
                'detail' => 'Register the Live webhook address in production Quiqdash, then paste its signing secret.'],
            ['key' => 'pickup', 'label' => 'Pickup address and contact set', 'ok' => $p['contact_name'] !== '' && $p['contact_phone'] !== '' && $p['address1'] !== '',
                'detail' => 'Pickup & defaults → Pickup.'],
        ];
    }

    /**
     * Why Internal testing cannot book on Live yet: the short labels of the
     * live prerequisites still missing. Empty = ready.
     *
     * @return list<string>
     */
    public function liveMissing(): array
    {
        $short = ['live_key' => 'verify the live API key with Test connection', 'live_webhook' => 'save the live webhook signing secret', 'pickup' => 'set the pickup address'];

        return array_values(array_map(fn ($c) => $short[$c['key']], array_filter($this->liveBasics(), fn ($c) => ! $c['ok'])));
    }

    /**
     * Change where Internal testing books. Live needs $confirm and every
     * live prerequisite; each change is audited by the caller and kept in
     * mode_log as {kind: books_on, from, to}.
     *
     * @return array{from: string, to: string}
     */
    public function setTestingEnv(mixed $to, bool $confirm, string $by): array
    {
        if (! is_string($to) || ! in_array($to, self::ENVS, true)) {
            throw new \InvalidArgumentException('Choose the Quiqup test system (staging) or Live (real courier).');
        }

        $from = $this->testingEnv();

        if ($from === $to) {
            return ['from' => $from, 'to' => $to];
        }

        if ($to === 'live') {
            if (! $confirm) {
                throw new \InvalidArgumentException('Confirm the change first: a real Quiqup courier will collect your test orders.');
            }

            $missing = $this->liveMissing();
            if ($missing !== []) {
                throw new \InvalidArgumentException('Live (real courier) is not ready: ' . implode('; ', $missing) . '.');
            }
        }

        $o = $this->options();
        $o['testing_env'] = $to;
        $log = is_array($o['mode_log'] ?? null) ? $o['mode_log'] : [];
        $log[] = ['at' => now()->toIso8601String(), 'by' => mb_substr($by, 0, 100), 'kind' => 'books_on', 'from' => $from, 'to' => $to];
        $o['mode_log'] = array_slice($log, -20);

        $row = $this->row(self::OPTIONS_ROW);
        $row->options = $o;
        $row->save();
        $this->markActive(self::envFor((string) $o['mode'], $to));

        return ['from' => $from, 'to' => $to];
    }

    private function markActive(string $env): void
    {
        foreach (self::ENVS as $e) {
            $r = $this->row($e);
            $r->active = $e === $env;
            if ($r->exists || $e === $env) {
                $r->save();
            }
        }
    }

    /**
     * Change the mode. Live needs $confirm and a passing checklist; every
     * change is audited (who, when, from -> to).
     *
     * @return array{from: string, to: string}
     */
    public function setMode(string $to, bool $confirm, string $by): array
    {
        if (! in_array($to, self::MODES, true)) {
            throw new \InvalidArgumentException('Choose Off, Internal testing or Live.');
        }

        $from = $this->mode();

        if ($from === $to) {
            return ['from' => $from, 'to' => $to];
        }

        if (! $confirm) {
            throw new \InvalidArgumentException('Confirm the change first.');
        }

        if ($to === 'live') {
            $missing = array_values(array_filter($this->checklist(), fn ($c) => ! $c['ok']));
            if ($missing !== []) {
                throw new \InvalidArgumentException('Live is not ready: ' . implode('; ', array_map(fn ($c) => $c['label'], $missing)) . '.');
            }
        }

        if ($to === 'testing' && $this->testingEnv() === 'sandbox' && ! $this->configured('sandbox')) {
            throw new \InvalidArgumentException('Add your staging API key under Connection → Sandbox first.');
        }

        if ($to === 'testing' && $this->testingEnv() === 'live' && ($missing = $this->liveMissing()) !== []) {
            throw new \InvalidArgumentException('Internal testing is set to book with a real courier, which is not ready: ' . implode('; ', $missing) . '.');
        }

        $o = $this->options();
        $o['mode'] = $to;
        $log = is_array($o['mode_log'] ?? null) ? $o['mode_log'] : [];
        $log[] = ['at' => now()->toIso8601String(), 'by' => mb_substr($by, 0, 100), 'from' => $from, 'to' => $to];
        $o['mode_log'] = array_slice($log, -20);

        $row = $this->row(self::OPTIONS_ROW);
        $row->options = $o;
        $row->save();

        $this->markActive(self::envFor($to, self::cleanTestingEnv($o['testing_env'] ?? null)));

        return ['from' => $from, 'to' => $to];
    }

    public static function quiqupEmirate(?string $ours): ?string
    {
        if ($ours === null) {
            return null;
        }

        foreach (self::EMIRATE_MAP as $k => $v) {
            if (strcasecmp(trim($ours), $k) === 0 || strcasecmp(trim($ours), $v) === 0) {
                return $v;
            }
        }

        return null;
    }

    /** Keep the order-address emirate list and ours in step (pinned by a test). */
    public static function emiratesMatchOrderAddress(): bool
    {
        return array_keys(self::EMIRATE_MAP) === OrderAddress::EMIRATES;
    }
}
