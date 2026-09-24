<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdminUser;
use App\Models\AuditEvent;
use App\Models\Setting;
use App\Models\UpdateRelease;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * The security module — part one: the record, and nothing else.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THIS BLOCKS NOTHING, AND THAT IS THE DESIGN
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * KBB-Master-Plan Phase 18 states the sequencing before it states the
 * features: "Report before enforce, in every part. A module that starts
 * blocking on day one blocks the owner, the payment provider's webhooks and
 * Google's crawler, and gets switched off — after which the shop is worse off
 * than before, because everyone now believes it is protected."
 *
 * So there is no request gate in this file, no CSP, no integrity enforcement
 * and no block list. Every line below either WRITES A ROW or READS ROWS BACK.
 * Not one code path can change the status of any response, and not one runs
 * before the router. The two ways that is true by construction rather than by
 * intention:
 *
 *   1. NO MIDDLEWARE. Recording is model events, auth events and one listener
 *      on RequestHandled, which fires AFTER the response exists and cannot
 *      replace it. A middleware could return early; a listener has nothing to
 *      return.
 *   2. NOTHING THROWS. record() swallows every Throwable. A shop whose
 *      `audit_events` table has not been created yet — the window between a
 *      package landing and its migration running — must lose the record, never
 *      the admin panel.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT IS RECORDED, AND WHERE IT IS HOOKED
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * | Event                | Hook                          | Carries            |
 * |----------------------|-------------------------------|--------------------|
 * | setting.changed      | Setting::saved / ::deleted    | key, before, after |
 * | admin.account        | AdminUser::created/updated/…  | email, role change |
 * | package.release      | UpdateRelease::created/updated| version, status    |
 * | signin.ok / .out     | Auth Login / Logout events    | actor, IP          |
 * | signin.failed        | Auth Failed event             | the email tried    |
 * | signin.blocked       | AdminAuthController, at the   | the email, the     |
 * |                      | throttle it already had       | cooldown           |
 * | ratelimit.trip       | RequestHandled, on a 429      | path, IP, count    |
 *
 * MODEL EVENTS AND NOT CALLS IN EACH CONTROLLER. Every setting this console
 * writes goes through SettingsService::set() and lands in ONE table, so one
 * hook on that table covers every screen there is — including the screens
 * written after this one, which a hand-maintained list of call sites would
 * miss. The same argument EnforceAdminCapability's docblock makes for keying
 * on the guard rather than on a path.
 *
 * ONLY WHEN AN ADMIN IS SIGNED IN. `Auth::guard('admin')->hasUser()` gates
 * every model hook, and it is a property read rather than a lookup, so:
 *
 *   - the storefront pays nothing (it never writes a setting anyway, and the
 *     one write that exists — IndexNow minting its key — happens with nobody
 *     signed in and is correctly not an administrative act);
 *   - `php artisan migrate` records nothing, so a fresh install does not
 *     arrive with an audit trail of its own migrations;
 *   - the trail means what it says: a row exists because a person did it.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * SECRETS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `settings` holds `admin_path` — the secret address of the console itself —
 * and `indexnow_key`, and nothing stops a future row holding a token. An audit
 * trail that copies the old and new value of every setting is therefore a
 * place secrets accumulate, read by a screen and dumped in every backup. So
 * values are matched against SECRET_HINTS by KEY NAME and replaced with a
 * marker: the row still proves the change happened, at that minute, by that
 * person, which is the whole job — it simply does not restate the secret.
 *
 * That is an allowlist's argument run the other way, and it is the weaker of
 * the two directions, so the cap is the second half: every recorded value is
 * truncated, so a setting that holds a blob cannot put the blob in here.
 */
class SecurityModule
{
    /** Every setting this module owns is prefixed, so nothing else can collide. */
    public const PREFIX = 'sec_';

    /* ───────────────────────────── event keys ───────────────────────────── */

    public const E_SETTING = 'setting.changed';

    public const E_ACCOUNT = 'admin.account';

    public const E_PACKAGE = 'package.release';

    public const E_SIGNIN = 'signin.ok';

    public const E_SIGNOUT = 'signin.out';

    public const E_SIGNIN_FAILED = 'signin.failed';

    public const E_SIGNIN_BLOCKED = 'signin.blocked';

    public const E_RATE_LIMIT = 'ratelimit.trip';

    /**
     * The events the screen files under "failed sign-ins".
     *
     * A list rather than a `str_starts_with('signin.')`, because signin.ok and
     * signin.out start with it too and belong under the changes.
     */
    public const SIGNIN_TROUBLE = [self::E_SIGNIN_FAILED, self::E_SIGNIN_BLOCKED];

    /**
     * A setting whose KEY matches any of these has its value withheld.
     *
     * Matched case-insensitively as a substring of the key, so `stripe_secret`,
     * `SMTP_PASSWORD` and `indexnow_key` are all covered without listing them.
     * Deliberately broad: a false positive costs one row that says "(withheld)"
     * where it could have said "false", and a false negative puts a live
     * credential on a screen and in every backup.
     */
    public const SECRET_HINTS = [
        'password', 'passwd', 'secret', 'token', 'key', 'hash', 'salt',
        'credential', 'auth', 'private', 'admin_path', 'dsn', 'webhook',
    ];

    /** What a withheld value reads as. A constant, so the screen can explain it. */
    public const WITHHELD = '(withheld — this setting holds a credential)';

    /** Longest recorded value. A setting can hold a page of HTML; this cannot. */
    public const VALUE_CAP = 200;

    /**
     * key => [type, label, default, help, options]
     *
     * The same five-element shape as CartPage, CheckoutPage and SlimFooter, so
     * the console's existing field renderer draws this screen with no renderer
     * of its own — and so a new option is a row here rather than a new screen.
     */
    public const SCHEMA = [
        // ── What is recorded ──
        /*
         * ALL THREE SHIP ON, and that is the one place this lane knowingly
         * departs from "a new setting ships at the value the page already has".
         *
         * The owner asked for this module in as many words (Phase 18, items 6
         * and 7: "There is no model for it today… the record an incident is
         * actually reconstructed from"), and a trail that ships off records
         * nothing until somebody finds the switch — which means the first
         * incident after the package lands is as unreconstructable as the last
         * one. Shipping on changes no page, refuses no request and alters no
         * response; it adds rows to a table nothing else reads.
         */
        'audit_on' => ['bool', 'Record administrative changes', true,
                       'Settings, back-office accounts and core-update packages: who changed what, from which address, and what it said before. Off, nothing is written and the screen below stays empty from that moment on — the rows already recorded are kept.'],
        'signin_on' => ['bool', 'Record sign-ins and failed sign-ins', true,
                        'Every admin sign-in and sign-out, every wrong password, and every time the login throttle turned somebody away. The password itself is never recorded, only the email that was tried.'],
        'rl_on' => ['bool', 'Record rate-limit trips', true,
                    'When the shop answers 429 — too many requests — it says so to that visitor and forgets. This remembers it. It does not change who is refused; nothing here refuses anything.'],
        'rl_window' => ['range', 'Collapse repeat trips within', 300,
                        'Repeat trips from the same address on the same path land on one row with a count, for this many seconds, instead of one row each. A flood is then one row that says 4,000 rather than 4,000 rows.',
                        ['min' => 60, 'max' => 3600, 'step' => 60, 'unit' => 's']],

        // ── The verdict ──
        'window_hours' => ['range', 'The verdict covers the last', 24,
                           'The line at the top of the screen counts what happened in this many hours. The lists below it are not limited to it.',
                           ['min' => 1, 'max' => 168, 'step' => 1, 'unit' => 'h']],
        'fail_threshold' => ['range', 'Failed sign-ins before it says look', 10,
                             'Below this the verdict reads "nothing above the usual". At or above it, it says so plainly and names the addresses.',
                             ['min' => 1, 'max' => 200, 'step' => 1, 'unit' => '']],
        'trip_threshold' => ['range', 'Rate-limit trips before it says look', 50,
                             'The same, for requests the shop answered 429. A shop with a busy crawler trips this honestly, which is why it is a number you can move rather than a fixed one.',
                             ['min' => 1, 'max' => 2000, 'step' => 5, 'unit' => '']],

        // ── Evidence ──
        'list_rows' => ['range', 'Rows shown in each list', 40,
                        'How many of the newest rows each of the three lists draws. It does not affect what is recorded or what the verdict counts.',
                        ['min' => 5, 'max' => 200, 'step' => 5, 'unit' => '']],
        'keep_days' => ['range', 'Keep evidence for', 90,
                        'Rows older than this are deleted when this screen is opened. Evidence is personal data — an address and an email — and it should expire on a schedule rather than accumulate for ever.',
                        ['min' => 7, 'max' => 730, 'step' => 1, 'unit' => ' days']],
        'ip_mask' => ['bool', 'Store addresses with the last part masked', false,
                      'Records 203.0.113.x instead of the whole address. It costs you the ability to tell two visitors on one network apart, which is usually the thing you wanted the address for — so it ships off, and is here for a shop that would rather not hold the last part at all.'],
    ];

    /**
     * tab key => [label, description, keys]
     *
     * Three tabs and not one long column, for the reason the Coupons rebuild
     * set as the standard on this project: a band with a heading and one line
     * saying when you would touch it.
     */
    public const TABS = [
        'record' => ['What is recorded', 'Three switches. Turning one off stops new rows of that kind; it never deletes what is already there.',
                     ['audit_on', 'signin_on', 'rl_on', 'rl_window']],
        'verdict' => ['The verdict line', 'The one sentence at the top of this screen, and the two numbers that decide whether it says "nothing to do" or "look at this".',
                      ['window_hours', 'fail_threshold', 'trip_threshold']],
        'evidence' => ['Evidence & retention', 'How much of the trail this screen draws, and how long any of it is kept.',
                       ['list_rows', 'keep_days', 'ip_mask']],
    ];

    public function __construct(private SettingsService $settings) {}

    /* ═══════════════════════════════════════════════════ the settings half ═══ */

    /** @return array<string, mixed> */
    public function all(): array
    {
        $out = [];

        foreach (self::SCHEMA as $key => $def) {
            $saved = $this->settings->get(self::PREFIX.$key, null);
            $out[$key] = $saved === null ? $def[2] : $this->cast($key, $saved);
        }

        return $out;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /** @param array<string, mixed> $values */
    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            if (isset(self::SCHEMA[$key])) {
                $this->settings->set(self::PREFIX.$key, $this->cast($key, $value));
            }
        }
    }

    private function cast(string $key, mixed $value): mixed
    {
        $def = self::SCHEMA[$key] ?? null;

        if ($def === null) {
            return $value;
        }

        return match ($def[0]) {
            'bool' => (bool) $value,
            /*
             * Clamped to the slider's own bounds. A number that arrives from
             * anywhere but the slider — a hand-rolled POST — is pulled back
             * inside them rather than stored, which is what keeps `keep_days`
             * from being set to 0 and turning "open the screen" into "delete
             * the whole trail".
             */
            'range' => max((int) $def[4]['min'], min((int) $def[4]['max'], (int) $value)),
            'select' => isset($def[4][(string) $value]) ? (string) $value : (string) $def[2],
            default => $value,
        };
    }

    /* ═════════════════════════════════════════════════ the recording half ═══ */

    /**
     * Register every hook. Called once, from AppServiceProvider::boot().
     *
     * IN A PROVIDER AND NOT IN bootstrap/app.php, for the reason that file's
     * own docblock and AppServiceProvider::boot() both record at length:
     * bootstrap/ is on BuildPackage::NEVER_SHIP and UpdateGuard's forbidden
     * list, so a registration there can never reach the live server in a
     * package. app/ ships. The Arabic middleware was lost for a whole release
     * to exactly that, and the owner reported it as "/ar gives 404 everywhere".
     *
     * A static METHOD holding no state of any kind, and this class declares no
     * process-level property either. The listeners are held by the container's
     * event dispatcher and by Eloquent's, both of which are rebuilt with the
     * application, so there is nothing to guard against double registration
     * with. A once-only flag kept in a class property would survive into the
     * next test in the process and silently unhook this module — and would have
     * to be registered in Tests\Support\StaticMemos, which is the tell that it
     * would be the wrong shape. (StaticMemoIsolationTest reads this file as
     * TEXT for function-local statics, because they cannot be reflected, so the
     * spelling of that phrase in a comment matters here.)
     */
    public static function listen(): void
    {
        /* ── administrative writes ──────────────────────────────────────── */

        Setting::saved(static function (Setting $setting): void {
            /*
             * Only when the value actually moved. Every settings screen in this
             * console posts its whole schema on Save, so without this a save
             * that changed one slider would write forty identical-looking rows
             * and the one that matters would be lost among them.
             */
            if (! $setting->wasRecentlyCreated && ! $setting->wasChanged('value')) {
                return;
            }

            $key = (string) $setting->getKey();

            app(self::class)->record(self::E_SETTING, 'Setting "'.$key.'" changed', [
                'subject' => $key,
                'before' => $setting->wasRecentlyCreated ? null : self::settingValue($key, $setting->getOriginal('value')),
                'after' => self::settingValue($key, $setting->value),
            ]);
        });

        Setting::deleted(static function (Setting $setting): void {
            $key = (string) $setting->getKey();

            app(self::class)->record(self::E_SETTING, 'Setting "'.$key.'" removed', [
                'subject' => $key,
                'before' => self::settingValue($key, $setting->value),
                'after' => null,
                'severity' => 'notice',
            ]);
        });

        /*
         * Back-office accounts. The hole EnforceAdminCapability was built to
         * close was a support account PUTting its own row to role=owner; if
         * that ever happens again the trail should say so in one line.
         *
         * The password is never read, not even to note that it changed —
         * `isDirty('password')` is enough and is what is recorded.
         */
        AdminUser::created(static function (AdminUser $admin): void {
            app(self::class)->record(self::E_ACCOUNT, 'Back-office account created: '.$admin->email, [
                'subject' => (string) $admin->email,
                'after' => 'role: '.(string) $admin->role,
                'severity' => 'notice',
            ]);
        });

        AdminUser::updated(static function (AdminUser $admin): void {
            $changes = self::accountChanges($admin);

            if ($changes === []) {
                return;
            }

            app(self::class)->record(self::E_ACCOUNT, 'Back-office account changed: '.$admin->email, [
                'subject' => (string) $admin->email,
                'before' => implode(', ', array_column($changes, 'before')),
                'after' => implode(', ', array_column($changes, 'after')),
                'severity' => $admin->wasChanged('role') ? 'alert' : 'notice',
            ]);
        });

        AdminUser::deleted(static function (AdminUser $admin): void {
            app(self::class)->record(self::E_ACCOUNT, 'Back-office account deleted: '.$admin->email, [
                'subject' => (string) $admin->email,
                'before' => 'role: '.(string) $admin->role,
                'severity' => 'alert',
            ]);
        });

        /*
         * Packages. `update_releases` is written by UpdateApiController and by
         * UpdateRunner, and the row IS the install — version, status and the
         * backup it can be rolled back onto.
         */
        UpdateRelease::created(static function (UpdateRelease $release): void {
            app(self::class)->record(self::E_PACKAGE, 'Package '.$release->version.' '.($release->status ?: 'recorded'), [
                'subject' => (string) $release->version,
                'after' => 'status: '.(string) $release->status,
                'severity' => 'notice',
            ]);
        });

        UpdateRelease::updated(static function (UpdateRelease $release): void {
            if (! $release->wasChanged('status')) {
                return;
            }

            app(self::class)->record(self::E_PACKAGE, 'Package '.$release->version.' is now '.$release->status, [
                'subject' => (string) $release->version,
                'before' => 'status: '.(string) $release->getOriginal('status'),
                'after' => 'status: '.(string) $release->status,
                'severity' => 'notice',
            ]);
        });

        /* ── sign-ins ───────────────────────────────────────────────────── */

        /*
         * THE ADMIN GUARD ONLY, on all three. A shopper signing in is not an
         * administrative act, and recording the storefront's own logins would
         * put a row-write on a customer-facing path — which is both a query
         * budget this project measures and a table of shoppers' addresses
         * nobody asked for.
         */
        Event::listen(Login::class, static function (Login $event): void {
            if ($event->guard !== 'admin') {
                return;
            }

            app(self::class)->record(self::E_SIGNIN, 'Signed in', [
                'group' => 'signin',
                'subject' => (string) ($event->user->email ?? ''),
            ]);
        });

        Event::listen(Logout::class, static function (Logout $event): void {
            if ($event->guard !== 'admin') {
                return;
            }

            app(self::class)->record(self::E_SIGNOUT, 'Signed out', [
                'group' => 'signin',
                'subject' => (string) ($event->user->email ?? ''),
            ]);
        });

        Event::listen(Failed::class, static function (Failed $event): void {
            if ($event->guard !== 'admin') {
                return;
            }

            /*
             * $event->credentials holds the PASSWORD that was tried. Only the
             * email is read out of it, by name, and the array itself never
             * reaches record() — an allowlist of one, in the one place on this
             * screen where the wrong move would store a plaintext password
             * (very possibly a correct one for some other account).
             */
            $email = (string) ($event->credentials['email'] ?? '');

            app(self::class)->record(self::E_SIGNIN_FAILED, 'Failed sign-in'.($email === '' ? '' : ' as '.$email), [
                'group' => 'signin',
                'subject' => $email,
                'actor_label' => $email === '' ? null : $email,
                'severity' => 'notice',
            ]);
        });

        /* ── rate-limit trips ───────────────────────────────────────────── */

        /*
         * AFTER THE RESPONSE, WHICH IS WHY THIS IS SAFE.
         *
         * RequestHandled is dispatched by Kernel::handle() with the finished
         * response in hand. A listener cannot replace it, delay the router or
         * refuse anything — there is nothing to return. That is the difference
         * between this and a middleware, and it is the whole reason this lane
         * may record 429s at all without becoming a request gate.
         *
         * It runs on every request including the storefront's, so the first
         * line is the only line that runs there: an integer comparison, no
         * query, no cache read, no settings read. `rl_on` is consulted only
         * once a 429 has already happened.
         */
        Event::listen(RequestHandled::class, static function (RequestHandled $event): void {
            if ($event->response->getStatusCode() !== 429) {
                return;
            }

            app(self::class)->recordRateLimitTrip($event->request);
        });
    }

    /**
     * The one event with no hook of its own: the admin login throttle.
     *
     * AdminAuthController answers its own throttle with a validation error
     * rather than a 429, so neither the Failed event nor the RequestHandled
     * listener above can see it — the attempt never reaches the guard and the
     * response is a 302. It is also the single most interesting rate limit in
     * the shop, being the one in front of the password. So the controller calls
     * this at the point it already decided to turn somebody away.
     *
     * An explicit call at the write, which is what the brief asks for in place
     * of a middleware; it records and returns, and the controller's behaviour
     * on either side of it is untouched.
     */
    public function recordSignInBlocked(string $email, int $seconds): void
    {
        $this->record(self::E_SIGNIN_BLOCKED, 'Sign-in refused by the login throttle'.($email === '' ? '' : ' for '.$email), [
            'group' => 'signin',
            'subject' => $email,
            'actor_label' => $email === '' ? null : $email,
            'after' => 'cooldown: '.$seconds.'s',
            'severity' => 'alert',
        ]);
    }

    /**
     * A 429, collapsed onto one row per address and path per window.
     *
     * One query either way — an UPDATE on the remembered row, or an INSERT
     * when the window has passed — so a flood cannot turn into a flood of
     * inserts. The row id is remembered in the cache rather than looked up,
     * because a SELECT to find the row to increment would double the cost of
     * exactly the case this exists to bound.
     */
    public function recordRateLimitTrip(\Illuminate\Http\Request $request): void
    {
        try {
            if (! $this->get('rl_on')) {
                return;
            }

            $ip = $this->address($request->ip());
            $path = '/'.ltrim($request->path(), '/');
            $window = (int) $this->get('rl_window');
            $cacheKey = 'kbb.sec.rl.'.sha1($ip.'|'.$path);
            $known = Cache::get($cacheKey);

            if (is_int($known) && AuditEvent::whereKey($known)->update([
                'hits' => DB::raw('hits + 1'),
                'last_seen_at' => Carbon::now(),
            ]) === 1) {
                return;
            }

            $row = $this->record(self::E_RATE_LIMIT, 'Refused as too many requests: '.$path, [
                'group' => 'ratelimit',
                'subject' => $path,
                'severity' => 'notice',
            ]);

            if ($row !== null) {
                Cache::put($cacheKey, (int) $row->getKey(), $window);
            }
        } catch (\Throwable) {
            // Recording is never worth a 500. See the class docblock.
        }
    }

    /**
     * Write one row, or do nothing at all.
     *
     * $opts: group (audit|signin|ratelimit), subject, before, after, severity,
     * actor_label.
     *
     * Returns the row so the rate-limit collapser can remember its id, and null
     * whenever nothing was written — which includes the switch being off, the
     * act not being administrative, and the table not existing yet.
     */
    public function record(string $event, string $summary, array $opts = []): ?AuditEvent
    {
        try {
            $group = (string) ($opts['group'] ?? 'audit');

            $admin = Auth::guard('admin')->hasUser() ? Auth::guard('admin')->user() : null;

            /*
             * An administrative write with nobody signed in is not an
             * administrative write: it is a migration, a console command or
             * the shop's own housekeeping. Sign-in and rate-limit rows are
             * exempt, because having no actor is the whole point of them.
             *
             * ▲ THIS TEST COMES FIRST, AND THAT ORDER IS LOAD-BEARING. It was
             * written below the switch read, and reading a setting is what
             * broke five unrelated tests: `php artisan migrate` writes settings
             * rows, so this hook ran during the migration set and
             * SettingsService::all() filled `kbb.settings` — a
             * rememberForever cache — from a HALF-MIGRATED settings table. Every
             * later reader in that process then got that snapshot, and the shop
             * paginated /shop and its category archives from settings that were
             * not there yet.
             *
             * hasUser() is a property read: no query, no cache, no settings.
             * With it first, a console run and every storefront request leave
             * this method having touched nothing at all.
             */
            if ($group === 'audit' && $admin === null) {
                return null;
            }

            $switch = match ($group) {
                'signin' => 'signin_on',
                'ratelimit' => 'rl_on',
                default => 'audit_on',
            };

            if (! $this->get($switch)) {
                return null;
            }

            $request = request();

            return AuditEvent::create([
                'occurred_at' => Carbon::now(),
                'last_seen_at' => Carbon::now(),
                'hits' => 1,
                'event' => $event,
                'severity' => (string) ($opts['severity'] ?? 'info'),
                'actor_id' => $admin?->getKey(),
                'actor_label' => $this->clip($opts['actor_label'] ?? $admin?->email, 191),
                'actor_role' => $admin?->role === null ? null : $this->clip((string) $admin->role, 32),
                'ip' => $this->address($request?->ip()),
                'method' => $request === null ? null : $this->clip($request->method(), 10),
                'path' => $request === null ? null : $this->clip('/'.ltrim($request->path(), '/'), 191),
                'subject' => $this->clip($opts['subject'] ?? null, 191),
                'summary' => (string) $this->clip($summary, 255),
                'before' => $this->clip($opts['before'] ?? null, self::VALUE_CAP),
                'after' => $this->clip($opts['after'] ?? null, self::VALUE_CAP),
            ]);
        } catch (\Throwable) {
            /*
             * Deliberately silent, and the reason is the package model this
             * shop ships under. A package lands as files and its migrations run
             * afterwards; between the two, `audit_events` does not exist and
             * every admin write would otherwise throw from a listener. Losing a
             * row is a gap in a report. Throwing here is an admin panel that
             * 500s on Save, on a host with no shell to repair it from.
             */
            return null;
        }
    }

    /* ═══════════════════════════════════════════════════ the reading half ═══ */

    /**
     * Everything the Security screen draws, in five queries flat.
     *
     * NO N+1 AND NO JOINS, by construction rather than by eager loading: every
     * column the screen prints is on the row itself, so the cost of the page
     * does not move with how many actors, settings or addresses appear in it.
     * Three list queries (each LIMITed), one aggregate for the verdict's
     * counts, one for the totals line.
     *
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $config = $this->all();
        $rows = (int) $config['list_rows'];
        $since = Carbon::now()->subHours((int) $config['window_hours']);

        $signInTrouble = AuditEvent::query()->whereIn('event', self::SIGNIN_TROUBLE)
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit($rows)->get();

        $trips = AuditEvent::query()->where('event', self::E_RATE_LIMIT)
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit($rows)->get();

        $changes = AuditEvent::query()
            ->whereNotIn('event', array_merge(self::SIGNIN_TROUBLE, [self::E_RATE_LIMIT]))
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit($rows)->get();

        /*
         * ONE grouped aggregate for the window rather than three counts. It is
         * also summed over `hits`, not counted over rows: a collapsed
         * rate-limit row stands for every trip it absorbed, and a verdict that
         * counted rows would read "1 trip" during a flood.
         */
        $windowed = AuditEvent::query()->since($since)
            ->selectRaw('event, SUM(hits) as total')
            ->groupBy('event')
            ->pluck('total', 'event')
            ->map(fn ($n) => (int) $n)
            ->all();

        $failed = 0;

        foreach (self::SIGNIN_TROUBLE as $event) {
            $failed += $windowed[$event] ?? 0;
        }

        $tripped = $windowed[self::E_RATE_LIMIT] ?? 0;
        $changed = array_sum($windowed) - $failed - $tripped;

        $counts = [
            'failed' => $failed,
            'tripped' => $tripped,
            'changed' => $changed,
            'total' => (int) AuditEvent::query()->count(),
        ];

        return [
            'verdict' => $this->verdict($config, $counts),
            'counts' => $counts,
            'window_hours' => (int) $config['window_hours'],
            'keep_days' => (int) $config['keep_days'],
            'recording' => [
                'changes' => (bool) $config['audit_on'],
                'signins' => (bool) $config['signin_on'],
                'trips' => (bool) $config['rl_on'],
            ],
            'signin_trouble' => $signInTrouble->map(fn (AuditEvent $r) => $this->row($r))->all(),
            'trips' => $trips->map(fn (AuditEvent $r) => $this->row($r))->all(),
            'changes' => $changes->map(fn (AuditEvent $r) => $this->row($r))->all(),
        ];
    }

    /**
     * The one sentence at the top of the screen.
     *
     * A SENTENCE AND NOT A DASHBOARD, which is the owner's ask read literally:
     * "a plain verdict line at the top rather than a dashboard that has to be
     * interpreted". Every branch below says what is true and, when something
     * is wrong, what to do about it. `tone` is 'quiet', 'watch' or 'act', and
     * the screen colours the line from it rather than deciding for itself.
     *
     * @return array{tone: string, line: string, detail: string}
     */
    private function verdict(array $config, array $counts): array
    {
        $hours = (int) $config['window_hours'];
        $span = $hours === 24 ? 'the last 24 hours' : 'the last '.$hours.' hours';

        /*
         * ONE SHORT CLAUSE, and it is in every branch on purpose: the verdict
         * is the line that gets read, and a security report that does not say
         * what it will NOT do for you is the one people trust too far. The card
         * under it carries the long version and does not repeat these words.
         */
        $honest = 'This screen reports; it blocks nothing.';

        if (! $config['audit_on'] && ! $config['signin_on'] && ! $config['rl_on']) {
            return [
                'tone' => 'act',
                'line' => 'Nothing is being recorded.',
                'detail' => 'All three switches under "What is recorded" are off, so this screen will '
                    .'stay as it is however much happens. '.$honest,
            ];
        }

        if ($counts['failed'] >= (int) $config['fail_threshold']) {
            return [
                'tone' => 'act',
                'line' => 'Worth a look: '.$counts['failed'].' failed sign-ins in '.$span.'.',
                'detail' => 'That is at or above the threshold on the "The verdict line" tab. The addresses '
                    .'they came from are in the first list below, newest first. '.$honest
                    .' If one address stands out, block it at Cloudflare or at the host, which is where '
                    .'blocking belongs and where it costs this shop nothing.',
            ];
        }

        if ($counts['tripped'] >= (int) $config['trip_threshold']) {
            return [
                'tone' => 'watch',
                'line' => 'Worth a look: '.$counts['tripped'].' requests refused as too many in '.$span.'.',
                'detail' => 'At or above the threshold on the "The verdict line" tab. This is usually a '
                    .'crawler or a badly-behaved integration rather than an attack — the second list '
                    .'below names the paths. '.$honest,
            ];
        }

        if ($counts['failed'] + $counts['tripped'] + $counts['changed'] === 0) {
            return [
                'tone' => 'quiet',
                'line' => 'Nothing to act on.',
                'detail' => 'No failed sign-ins, no refused requests and no administrative changes in '
                    .$span.'. '.$honest,
            ];
        }

        return [
            'tone' => 'quiet',
            'line' => 'Nothing to act on.',
            'detail' => $counts['changed'].' administrative '.($counts['changed'] === 1 ? 'change' : 'changes').', '
                .$counts['failed'].' failed '.($counts['failed'] === 1 ? 'sign-in' : 'sign-ins').' and '
                .$counts['tripped'].' refused '.($counts['tripped'] === 1 ? 'request' : 'requests').' in '.$span
                .' — all below the thresholds you set. '.$honest,
        ];
    }

    /**
     * One row, as the screen reads it.
     *
     * Every value is a string or an integer. The screen escapes all of them on
     * the way into the document — `before` and `after` are owner-typed setting
     * values and `subject` can be an email somebody chose, so none of them is a
     * constant and none may be printed raw.
     *
     * @return array<string, mixed>
     */
    private function row(AuditEvent $row): array
    {
        return [
            'id' => (int) $row->getKey(),
            'at' => $row->occurred_at?->toDateTimeString(),
            'last_at' => $row->last_seen_at?->toDateTimeString(),
            'hits' => (int) $row->hits,
            'event' => (string) $row->event,
            'severity' => (string) $row->severity,
            'actor' => (string) ($row->actor_label ?? 'not signed in'),
            'role' => (string) ($row->actor_role ?? ''),
            'ip' => (string) ($row->ip ?? ''),
            'method' => (string) ($row->method ?? ''),
            'path' => (string) ($row->path ?? ''),
            'subject' => (string) ($row->subject ?? ''),
            'summary' => (string) $row->summary,
            'before' => $row->before === null ? null : (string) $row->before,
            'after' => $row->after === null ? null : (string) $row->after,
        ];
    }

    /**
     * Delete evidence older than `keep_days`, and say how many rows went.
     *
     * Called when the screen is opened, which is the only regular, logged-in,
     * non-hot-path moment this application reliably has: there is no cron on
     * this host and no queue worker running. A shop nobody opens the screen on
     * keeps its rows, which is the safe direction to fail in.
     */
    public function prune(): int
    {
        $days = (int) $this->get('keep_days');

        return (int) AuditEvent::query()
            ->where('occurred_at', '<', Carbon::now()->subDays($days))
            ->delete();
    }

    /* ═════════════════════════════════════════════════════════ small parts ═══ */

    /**
     * The address, masked or not.
     *
     * The mask drops the last part of an IPv4 address and the last four groups
     * of an IPv6 one — the same shape analytics packages use, and enough to
     * keep the network while losing the household.
     */
    private function address(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        if (! $this->get('ip_mask')) {
            return $this->clip($ip, 45);
        }

        if (str_contains($ip, ':')) {
            $groups = explode(':', $ip);

            return $this->clip(implode(':', array_slice($groups, 0, 4)).'::x', 45);
        }

        $parts = explode('.', $ip);

        if (count($parts) !== 4) {
            return $this->clip($ip, 45);
        }

        $parts[3] = 'x';

        return implode('.', $parts);
    }

    /**
     * A setting's value as the trail may hold it: withheld if the key sounds
     * like a credential, truncated always, and never an object.
     */
    private static function settingValue(string $key, mixed $value): ?string
    {
        $lower = strtolower($key);

        foreach (self::SECRET_HINTS as $hint) {
            if (str_contains($lower, $hint)) {
                return self::WITHHELD;
            }
        }

        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (! is_scalar($value)) {
            $value = json_encode($value);
        }

        return mb_substr(trim((string) $value), 0, self::VALUE_CAP);
    }

    /**
     * What changed on an account, never including the password itself.
     *
     * @return list<array{before: string, after: string}>
     */
    private static function accountChanges(AdminUser $admin): array
    {
        $out = [];

        foreach (['email', 'name', 'role'] as $column) {
            if ($admin->wasChanged($column)) {
                $out[] = [
                    'before' => $column.': '.(string) $admin->getOriginal($column),
                    'after' => $column.': '.(string) $admin->{$column},
                ];
            }
        }

        if ($admin->wasChanged('password')) {
            // THAT it changed, never what to. Neither hash goes anywhere near
            // a row that is printed on a screen and copied into every backup.
            $out[] = ['before' => 'password: set', 'after' => 'password: changed'];
        }

        return $out;
    }

    private function clip(mixed $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr((string) $value, 0, $length);
    }
}
