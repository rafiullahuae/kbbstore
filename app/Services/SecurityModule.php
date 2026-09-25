<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdminUser;
use App\Models\AuditEvent;
use App\Models\ModuleToggle;
use App\Models\Setting;
use App\Models\UpdateRelease;
use App\Services\Security\ContentSecurityPolicy;
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
 * | module.toggled       | ModuleToggle::saved / ::deleted| module, on -> off |
 * | integrity.changed    | IntegrityChecker, from the    | the path, the two  |
 * | integrity.missing    | Security screen only          | hashes, no actor   |
 * | csp.violation        | CspViolations, from a public  | directive, blocked |
 * |                      | endpoint a browser posts to   | thing, page, no by |
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
     * A content-security-policy violation, reported by somebody's browser.
     *
     * Declared on App\Services\Security\CspViolations, restated here for the
     * same reason INTEGRITY is: report() has to keep these out of the
     * administrative list, and a `str_starts_with('csp.')` would quietly
     * swallow an event a later round adds under the same prefix.
     *
     * IT IS THE ONLY EVENT IN THIS TABLE A STRANGER CAN CAUSE. Everything else
     * needs a signed-in admin, or is the shop's own throttle answering, or is
     * the integrity check reading its own disk. That is why it is bounded
     * separately — see CspViolations::enforceCspCap().
     */
    public const E_CSP = \App\Services\Security\CspViolations::EVENT;

    /**
     * A violation report the report endpoint's own throttle turned away.
     *
     * It is a 429, so the RequestHandled listener sees it — and it must not
     * land among the rate-limit trips, which is a list that means "somebody is
     * hammering the shop" and a threshold that fires the verdict. See
     * CspViolations::EVENT_SHED for what this cost on the first real run.
     */
    public const E_CSP_SHED = \App\Services\Security\CspViolations::EVENT_SHED;

    /** Both policy events, for the queries that have to keep them out. */
    public const CSP = [self::E_CSP, self::E_CSP_SHED];

    /**
     * A module switched on or off from Store → Modules.
     *
     * A GAP THIS LANE NAMED IN ROUND ONE AND LEFT OPEN. `module_toggles` is not
     * `settings`, so the Setting::saved hook never saw it — and turning
     * `mega_menu`, `marketing_pixels` or `cart_coupon_field` off changes what
     * every visitor is served while leaving no row anywhere. It is one of the
     * larger levers in the console and it was the one administrative act with
     * no record at all.
     */
    public const E_MODULE = 'module.toggled';

    /**
     * The events the screen files under "failed sign-ins".
     *
     * A list rather than a `str_starts_with('signin.')`, because signin.ok and
     * signin.out start with it too and belong under the changes.
     */
    public const SIGNIN_TROUBLE = [self::E_SIGNIN_FAILED, self::E_SIGNIN_BLOCKED];

    /**
     * Integrity findings. Declared on IntegrityChecker, restated here because
     * report() has to separate them out of the administrative list and a
     * `str_starts_with('integrity.')` would quietly swallow an event a later
     * round adds under the same prefix for a different screen.
     */
    public const INTEGRITY = [IntegrityChecker::E_CHANGED, IntegrityChecker::E_MISSING];

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

    /**
     * One write in this many sweeps the trail back under `max_rows`.
     *
     * A constant and not a slider: it is the cost of the ceiling, not a
     * property of it. 100 means a flood of failed sign-ins pays two extra
     * queries every hundredth row — call it 1% — and can overshoot the ceiling
     * by at most 99 rows before it is pulled back, which is a rounding error on
     * a ceiling whose floor is 1,000.
     */
    public const CAP_EVERY = 100;

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
                        'Rows older than this are deleted when this screen is opened. Evidence is personal data — an address and an email — and it should expire on a schedule rather than accumulate for ever. There is no scheduler on this host, so "when this screen is opened" is the whole of it; the ceiling below is what holds on a shop nobody opens it on.',
                        ['min' => 7, 'max' => 730, 'step' => 1, 'unit' => ' days']],
        /*
         * THE CEILING, and the second half of an honest answer about retention.
         *
         * Round one shipped retention that runs when this screen is opened,
         * because this host has no cron and no queue worker. That is still the
         * only honest answer — but "a shop nobody opens the screen on keeps its
         * rows" is not a safe direction to fail in once you notice WHICH rows.
         * Administrative rows need a signed-in admin, so those are bounded by
         * how much work a person does. Failed sign-ins are not: the login
         * throttle allows five a minute, which is 7,200 rows a day, for as long
         * as somebody cares to keep trying, into a table nothing is trimming.
         *
         * So the count is bounded independently of the calendar and
         * independently of anybody opening anything. See enforceCap().
         */
        'max_rows' => ['range', 'Never keep more than', 20000,
                       'The hard ceiling on the whole trail. When it is passed, the oldest rows go until the count is back under it — and that happens as rows are written, not when this screen is opened, so it holds on a shop nobody ever visits this screen on. It is the backstop for "keep evidence for" above, not a replacement for it.',
                       ['min' => 1000, 'max' => 200000, 'step' => 1000, 'unit' => ' rows']],

        // ── Integrity ──
        /*
         * SHIPS ON. The second place this lane knowingly departs from "a new
         * setting ships at the value the page already has", and the argument is
         * the one round one made for the trail itself.
         *
         * The owner asked for this in as many words — Phase 18 item 3, "the
         * part that answers auto reverse it" — and this host has no shell, so
         * he cannot diff, list or hash anything himself. A package applied
         * twice, half-applied after a timeout, or hand-edited by a support
         * agent with FTP is invisible today. A check that ships off checks
         * nothing until somebody finds the switch.
         *
         * It runs ONLY when this screen is opened, which is owner-only and off
         * every hot path. It refuses nothing, restores nothing and writes
         * nothing outside `audit_events`.
         */
        'integrity_on' => ['bool', 'Check that shipped files still match their package', true,
                           'Every update package carries a SHA-256 for each file it installs. This hashes those files on the server and reports any that differ, or that are gone. It can only speak about files a package installed — not uploads, not anything hand-created, and not a file somebody ADDED, which has no hash to miss.'],
        'integrity_hours' => ['range', 'Check again at most every', 6,
                              'Opening this screen runs the check when the last one is older than this. It is a file-by-file hash on shared hosting, so it is throttled rather than run on every refresh; "Check now" on the card ignores this.',
                              ['min' => 1, 'max' => 168, 'step' => 1, 'unit' => 'h']],
        /*
         * ONE OPTION, ON PURPOSE. Phase 18 lists "Restore automatically, or
         * alert and wait?" under "Open, for the owner", with a recommendation
         * of alert-by-default and restore one click away. That is the owner's
         * decision and a lane does not take it by shipping code for one branch.
         *
         * So this is the seam and not the answer. cast() stores a select value
         * only when it is one of that field's own options and otherwise stores
         * the default, so a hand-rolled POST of 'restore' is stored as 'alert'
         * — the control cannot be moved to a behaviour that does not exist.
         */
        'integrity_action' => ['select', 'When a file does not match', 'alert',
                               'Today there is one answer and it is the recommended one: tell you, and change nothing. Restoring the file from the package that installed it is genuinely possible — the package is still on this server — but it is a WRITE, and it would also silently undo a legitimate hand-edit. That decision is yours to take, and the second option appears here when you have taken it.',
                               IntegrityChecker::ACTIONS],
        // ── Content-Security-Policy, report-only ──
        /*
         * SHIPS OFF, and this one is the rule rather than a departure from it.
         *
         * Round one shipped three switches on and round two shipped a fourth,
         * each time with the owner's own words in Phase 18 behind it and each
         * time called out in the commit. This is not that case. The shop sends
         * no content-security-policy header today, so "a new setting ships at
         * the value the page already has" means OFF, and applying the package
         * changes not one byte of any response until somebody moves this.
         *
         * It is also the honest default on its own merits, and the help text
         * below says so rather than leaving the owner to find out: a
         * report-only policy this shop cannot satisfy yet turns one page view
         * into one page view plus a handful of violation POSTs from every
         * visitor's browser. That is a real cost on a shared plan, it is
         * measured in docs/LC-SECURITY-MODULE.md, and it is his to spend when
         * he wants the measurement.
         */
        'csp_on' => ['bool', 'Send the report-only content-security policy', false,
                     'Tells every visitor\'s browser what this shop is allowed to load — and asks it to REPORT anything outside that rather than block it. Nothing is refused, no page changes, and no shopper sees anything different. While it is on, browsers post a short report for each thing the policy would have stopped, and those land in the list above. Turn it on for a week when you want to know what switching enforcement on would cost; the list fills up in minutes.'],
        /*
         * ONE OPTION, ON PURPOSE, and the same seam IntegrityChecker::ACTIONS
         * is. Phase 18's sequencing puts enforcement after the request gate,
         * with real traffic observed, one rule at a time — so a lane at the
         * CSP-report-only step does not ship an enforce branch, and cast()
         * stores a select value only when it is one of that field's own
         * options. The stronger half is in ContentSecurityPolicy: the enforcing
         * header's name is not in this application's source at all.
         */
        'csp_mode' => ['select', 'What the policy does', 'report',
                       'There is one answer today and it is the one the plan calls for: report, and refuse nothing. Enforcing comes after the request gate has watched real traffic, and it comes one rule at a time — a policy switched to enforce in one step is how a working checkout stops taking cards. The second option appears here when that round arrives.',
                       ContentSecurityPolicy::MODES],
        'csp_window' => ['range', 'Collapse repeat violations within', 3600,
                         'The same violation on the same page lands on one row with a count, for this many seconds, instead of one row per visitor. Without it a single inline script would write one row for every page view on the shop.',
                         ['min' => 300, 'max' => 86400, 'step' => 300, 'unit' => 's']],
        'csp_rows' => ['range', 'Never keep more than', 200,
                       'The ceiling on violation rows, separately from the one on everything else. It matters because this is the only thing in this table a stranger can cause: without a ceiling of its own, enough posted violations would push your sign-ins and setting changes out of the trail to make room. Violations can never take more than this many rows.',
                       ['min' => 20, 'max' => 2000, 'step' => 10, 'unit' => ' rows']],

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
        'integrity' => ['File integrity', 'Whether the shop checks that the files its packages installed are still the files those packages contained — and what it does when one is not. It reports; it changes nothing on disk.',
                        ['integrity_on', 'integrity_hours', 'integrity_action']],
        'csp' => ['Content security policy', 'Whether the shop tells browsers what it is allowed to load, and collects what falls outside that. It reports; it refuses nothing, and there is no setting on this screen that can make it refuse.',
                  ['csp_on', 'csp_mode', 'csp_window', 'csp_rows']],
        'evidence' => ['Evidence & retention', 'How much of the trail this screen draws, how long any of it is kept, and the ceiling that holds when nobody opens this screen.',
                       ['list_rows', 'keep_days', 'max_rows', 'ip_mask']],
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

    /**
     * One value, read on its own.
     *
     * NOT `$this->all()[$key]`, which is what this was. all() walks the whole
     * schema and asks SettingsService for every key in it, so a caller that
     * wanted one switch paid twenty-odd cached reads for it. That was
     * invisible while every caller was an admin screen or a write that had
     * already happened — and it stopped being invisible the moment
     * App\Services\Security\CspHeaders began asking for `csp_on` on every
     * storefront response.
     *
     * Same answer as before, key for key: all() builds exactly this per key,
     * and SecurityCspTest pins the two against each other across the whole
     * schema so they cannot drift.
     */
    public function get(string $key): mixed
    {
        $def = self::SCHEMA[$key] ?? null;

        if ($def === null) {
            return null;
        }

        $saved = $this->settings->get(self::PREFIX.$key, null);

        return $saved === null ? $def[2] : $this->cast($key, $saved);
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

    /**
     * This screen's point on ModuleSchema's six policy axes.
     *
     * Three of the six are unobserved here and are written down rather than
     * inherited: there is no text, no colour and nothing on this screen that
     * stores a string an owner types. Every control is a switch, a slider or a
     * two-option picker.
     *
     * `clamp` is the axis that matters, and it is the one this screen's own
     * comment has always argued for, kept here word for word because it is the
     * reason the arm exists: "a number that arrives from anywhere but the
     * slider — a hand-rolled POST — is pulled back inside them rather than
     * stored, which is what keeps `keep_days` from being set to 0 and turning
     * 'open the screen' into 'delete the whole trail'." `invalid => default` is
     * the other: an unrecognised `csp_mode` falls back to `report`, never to
     * nothing.
     */
    public const POLICY = [
        'max' => 5000,
        'blank' => 'keep',
        'invalid' => 'default',
        'clamp' => true,
        'hex' => 'repair',
        'bool' => 'cast',
    ];

    /**
     * The normalised schema, built once — see CartPage::fields() for why.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function fields(): array
    {
        return ModuleSchema::normalised(self::class, self::SCHEMA, self::POLICY);
    }

    /**
     * Cast by declared type, in the one place every module now casts.
     *
     * NOTHING PECULIAR TO THIS SCREEN SURVIVED THE SORT. Its three arms were
     * the plain bool, the clamped range and the option-set select, byte for
     * byte the same three that thirteen other modules carried — which is the
     * whole argument for the shared cast, and the reason this module was the
     * cheapest of the eight to move.
     */
    private function cast(string $key, mixed $value): mixed
    {
        $field = self::fields()[$key] ?? null;

        // Unchanged: a key this schema does not know is handed back as it came.
        return $field === null ? $value : ModuleSchema::cast($field, $value);
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
         * MODULES. `module_toggles` is a table of its own and not a row in
         * `settings`, so the hook above has never seen it — and this lane said
         * so in round one and left it open.
         *
         * It is not a small gap. Store → Modules is where `mega_menu`,
         * `marketing_pixels`, `cart_coupon_field`, `pay_ship_rules` and
         * `build_my_routine` are switched on and off, and every one of those
         * changes what a visitor is served. Turning one off is among the
         * largest single-click changes the console can make to the shop, and
         * until now it left no row anywhere.
         *
         * ON THE MODEL AND NOT IN SettingsService::setModule(), for the same
         * reason the Setting hook is on the model: every write to this table
         * goes through Eloquent, including the ones a screen written after this
         * one will make, and a hand-maintained list of call sites would miss
         * them. ModuleSeeder runs with nobody signed in, so a fresh install
         * still arrives with an empty trail — the `group === 'audit'` guard in
         * record() covers that without a word here.
         */
        ModuleToggle::saved(static function (ModuleToggle $module): void {
            if (! $module->wasRecentlyCreated && ! $module->wasChanged('enabled')) {
                return;
            }

            $name = (string) $module->getKey();
            $now = $module->enabled ? 'on' : 'off';

            app(self::class)->record(self::E_MODULE, 'Module "'.$name.'" switched '.$now, [
                'subject' => $name,
                'before' => $module->wasRecentlyCreated
                    ? null
                    : (((bool) $module->getOriginal('enabled')) ? 'on' : 'off'),
                'after' => $now,
                'severity' => 'notice',
            ]);
        });

        ModuleToggle::deleted(static function (ModuleToggle $module): void {
            $name = (string) $module->getKey();

            app(self::class)->record(self::E_MODULE, 'Module "'.$name.'" removed from the list', [
                'subject' => $name,
                'before' => ((bool) $module->enabled) ? 'on' : 'off',
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
            $path = '/'.ltrim($request->path(), '/');

            /*
             * WHICH KIND OF 429 THIS IS, decided before any setting is read.
             *
             * A 429 on the policy's own report endpoint is not a caller
             * hammering the shop; it is this module shedding reports its own
             * policy caused. Recorded either way — reports really were lost and
             * that is worth knowing — but under its own event, so it stays out
             * of the trips list and out of the threshold that fires the verdict.
             *
             * WHAT IT COST BEFORE IT WAS SEPARATED. One view of the home page
             * makes a real browser post 158 violation reports; the route allows
             * 60 a minute; so the first screenshot taken of the policy card had
             * the verdict line at the top of this screen reading "Worth a look:
             * 687 requests refused as too many in the last 24 hours", every one
             * of them this module answering itself. Neither half was wrong
             * alone, which is why only running it found it.
             *
             * A string compare and no setting read, so an ordinary storefront
             * 429 pays nothing for it.
             */
            $isReport = $path === app(\App\Services\Security\ContentSecurityPolicy::class)->reportUri();
            $event = $isReport ? self::E_CSP_SHED : self::E_RATE_LIMIT;

            if (! $this->get($isReport ? 'csp_on' : 'rl_on')) {
                return;
            }

            $ip = $this->address($request->ip());
            $window = (int) $this->get($isReport ? 'csp_window' : 'rl_window');
            $cacheKey = 'kbb.sec.rl.'.sha1($ip.'|'.$path);
            $known = Cache::get($cacheKey);

            if (is_int($known) && AuditEvent::whereKey($known)->where('event', $event)->update([
                'hits' => DB::raw('hits + 1'),
                'last_seen_at' => Carbon::now(),
            ]) === 1) {
                return;
            }

            $row = $isReport
                ? $this->record($event, 'Violation reports turned away by the report endpoint\'s own limit', [
                    'group' => 'csp',
                    'subject' => $path,
                    'no_actor' => true,
                    'severity' => 'notice',
                ])
                : $this->record($event, 'Refused as too many requests: '.$path, [
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
     * $opts: group (audit|signin|ratelimit|integrity|csp), subject, before,
     * after, severity, actor_label, anonymous, no_actor, path, method.
     *
     * `anonymous` writes the row with NO actor, NO address and NO request
     * path. It exists for integrity findings, where the admin who opened the
     * screen is emphatically not the person who changed the file — naming them
     * would put the one person the check can prove innocent in the "by" column
     * of an alert. What is known goes in the row; what is not known is left
     * null rather than filled with something false.
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
                'integrity' => 'integrity_on',
                'csp' => 'csp_on',
                default => 'audit_on',
            };

            if (! $this->get($switch)) {
                return null;
            }

            $request = request();

            /*
             * An integrity finding knows the file, the two hashes and the time.
             * It does NOT know who, and it must not borrow the actor, the
             * address or the request path of the admin who happened to open the
             * screen that ran the check. See the `anonymous` note above.
             */
            $anonymous = (bool) ($opts['anonymous'] ?? false);

            /*
             * `no_actor` is the weaker half of `anonymous`, and it exists for
             * exactly one caller: a content-security-policy violation.
             *
             * A violation has no actor for the same reason an integrity
             * finding has none — nobody was signed in, and the one name within
             * reach is the owner, who on the day he browses his own shop
             * signed in would find his email in the "by" column of a row a
             * stranger's browser wrote. But unlike an integrity finding it
             * does know WHERE from and ON WHAT PAGE, and those two are the
             * whole evidence. So: drop the actor, keep the address and the
             * page. What is known goes in the row, what is not stays null.
             */
            $noActor = $anonymous || (bool) ($opts['no_actor'] ?? false);

            $row = AuditEvent::create([
                'occurred_at' => Carbon::now(),
                'last_seen_at' => Carbon::now(),
                'hits' => 1,
                'event' => $event,
                'severity' => (string) ($opts['severity'] ?? 'info'),
                'actor_id' => $noActor ? null : $admin?->getKey(),
                'actor_label' => $noActor ? null : $this->clip($opts['actor_label'] ?? $admin?->email, 191),
                'actor_role' => ($noActor || $admin?->role === null) ? null : $this->clip((string) $admin->role, 32),
                'ip' => $anonymous ? null : $this->address($request?->ip()),
                /*
                 * The caller may name the method and the path instead of the
                 * request naming them, and one caller does. A violation is
                 * posted to /api/csp-report by a browser, so the request's own
                 * path is this module's endpoint on every single row — which
                 * says nothing. The page the violation happened on is the
                 * useful answer, and it comes out of the report body, bounded
                 * and stripped by CspViolations before it gets here.
                 *
                 * array_key_exists rather than ??, so a caller can pass null to
                 * mean "there is no method here" — which is true of a report
                 * that names a page it did not itself request.
                 */
                'method' => array_key_exists('method', $opts)
                    ? $this->clip($opts['method'], 10)
                    : (($anonymous || $request === null) ? null : $this->clip($request->method(), 10)),
                'path' => array_key_exists('path', $opts)
                    ? $this->clip($opts['path'], 191)
                    : (($anonymous || $request === null) ? null : $this->clip('/'.ltrim($request->path(), '/'), 191)),
                'subject' => $this->clip($opts['subject'] ?? null, 191),
                'summary' => (string) $this->clip($summary, 255),
                'before' => $this->clip($opts['before'] ?? null, self::VALUE_CAP),
                'after' => $this->clip($opts['after'] ?? null, self::VALUE_CAP),
            ]);

            /*
             * THE CEILING, AMORTISED ONTO THE WRITE PATH — the half of
             * retention that does not need anybody to open a screen.
             *
             * Keyed on the row's own id rather than on a counter, and that is
             * deliberate on two counts. A counter would have to live somewhere:
             * a class property survives into the next test in the process and
             * would have to be registered in Tests\Support\StaticMemos, and a
             * cache entry is one more thing to read on every write. The id is
             * already in hand, it is monotonic, and `id % CAP_EVERY` fires the
             * sweep on one write in CAP_EVERY whatever the traffic looks like.
             *
             * AFTER the insert and inside the same try, so a sweep that cannot
             * run costs the ceiling and never the row.
             */
            if ((int) $row->getKey() % self::CAP_EVERY === 0) {
                $this->enforceCap();
            }

            /*
             * AND THE POLICY'S OWN CEILING, ON EVERY ROW IT WRITES.
             *
             * `csp` is the only group here a stranger can cause, so it is the
             * only one bounded among itself as well as by the trail's ceiling —
             * otherwise that ceiling, which deletes the OLDEST rows, becomes a
             * way to delete the audit trail by posting enough reports. Here
             * rather than in CspViolations so that both ways a row reaches this
             * table from that public endpoint — a violation and a shed report —
             * are swept by one call. See CspViolations::enforceCap().
             */
            if ($group === 'csp') {
                app(\App\Services\Security\CspViolations::class)->enforceCap();
            }

            return $row;
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

        $integrity = AuditEvent::query()->whereIn('event', self::INTEGRITY)
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit($rows)->get();

        $violations = AuditEvent::query()->where('event', self::E_CSP)
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit($rows)->get();

        /*
         * Integrity findings are excluded here as well as the other two. They
         * are not administrative changes — nobody signed in did them, which is
         * the whole reason the check exists — and letting them fall through to
         * this list would file an intrusion under "who changed what".
         */
        $changes = AuditEvent::query()
            ->whereNotIn('event', array_merge(self::SIGNIN_TROUBLE, self::INTEGRITY, self::CSP, [self::E_RATE_LIMIT]))
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

        $found = 0;

        foreach (self::INTEGRITY as $event) {
            $found += $windowed[$event] ?? 0;
        }

        /*
         * AND CSP VIOLATIONS COME OUT OF IT TOO. `changed` is "administrative
         * changes" and the verdict prints it as such. A violation is written
         * by a stranger's browser with nobody signed in; letting it fall
         * through to this subtraction would have the verdict line report a
         * flood of them as the owner's own work.
         */
        $violated = $windowed[self::E_CSP] ?? 0;
        $shed = $windowed[self::E_CSP_SHED] ?? 0;

        $changed = array_sum($windowed) - $failed - $tripped - $found - $violated - $shed;

        $counts = [
            'failed' => $failed,
            'tripped' => $tripped,
            'changed' => $changed,
            'integrity' => $found,
            'violations' => $violated,
            'total' => (int) AuditEvent::query()->count(),
        ];

        /*
         * THE LAST SCAN, not the history. The list below it is the history —
         * a finding that was recorded a month ago is evidence and stays — but
         * the verdict must speak about the shop as it is NOW, or a file that
         * was put back in March would still be reading as an intrusion in
         * December. So `integrity['findings']` is what the most recent scan
         * actually found on disk, and the rows are what was ever found.
         *
         * NO QUERY: the scan's summary is a cache read. See
         * IntegrityChecker::state() for why it is neither a table nor a
         * setting.
         */
        $state = app(IntegrityChecker::class)->state();

        $integrityBlock = [
            'on' => (bool) $config['integrity_on'],
            'action' => (string) $config['integrity_action'],
            'every_hours' => (int) $config['integrity_hours'],
            'ran_at' => $state['ran_at'] ?? null,
            'expected' => (int) ($state['expected'] ?? 0),
            'checked' => (int) ($state['checked'] ?? 0),
            'skipped' => (int) ($state['skipped'] ?? 0),
            'findings' => (int) ($state['findings'] ?? 0),
            'releases' => (int) ($state['releases'] ?? 0),
            'applied' => (int) ($state['applied'] ?? 0),
            'truncated' => (bool) ($state['truncated'] ?? false),
            'took_ms' => (int) ($state['took_ms'] ?? 0),
            'paths' => array_values(array_map('strval', (array) ($state['paths'] ?? []))),
        ];

        /*
         * The CSP block. No query: every number in it is either a setting or
         * already counted above, and the policy itself is built from constants.
         * `sent` is the one thing the screen cannot work out for itself — a
         * page carries the header only when the switch is on, and a screen
         * that showed a policy without saying whether it is being sent would
         * be the most misleading thing on it.
         */
        $policy = app(ContentSecurityPolicy::class);

        $cspBlock = [
            'on' => (bool) $config['csp_on'],
            'mode' => (string) $config['csp_mode'],
            'header' => ContentSecurityPolicy::HEADER,
            'policy' => $policy->header(),
            'report_uri' => $policy->reportUri(),
            'window' => (int) $config['csp_window'],
            'max_rows' => (int) $config['csp_rows'],
            /*
             * ONE COUNT QUERY, added deliberately rather than derived from the
             * list above — the list is LIMITed to `list_rows` (40) and the
             * ceiling is 200, so counting the rows drawn would understate what
             * is kept by a factor of five and make the sentence about the
             * ceiling meaningless. It is a constant cost: one aggregate,
             * whatever the table holds, which is what the report's own query
             * budget measures.
             */
            'kept' => (int) AuditEvent::query()->where('event', self::E_CSP)->count(),
            /*
             * How many reports the endpoint turned away in the verdict's own
             * window — the number the owner needs in order to read the list
             * below correctly. With reports being shed, an absent violation
             * means "not seen", not "does not happen".
             */
            'shed' => $shed,
        ];

        return [
            'verdict' => $this->verdict($config, $counts, $integrityBlock),
            'counts' => $counts,
            'window_hours' => (int) $config['window_hours'],
            'keep_days' => (int) $config['keep_days'],
            'max_rows' => (int) $config['max_rows'],
            'recording' => [
                'changes' => (bool) $config['audit_on'],
                'signins' => (bool) $config['signin_on'],
                'trips' => (bool) $config['rl_on'],
            ],
            'integrity' => $integrityBlock,
            'csp' => $cspBlock,
            'csp_rows' => $violations->map(fn (AuditEvent $r) => $this->row($r))->all(),
            'signin_trouble' => $signInTrouble->map(fn (AuditEvent $r) => $this->row($r))->all(),
            'trips' => $trips->map(fn (AuditEvent $r) => $this->row($r))->all(),
            'integrity_rows' => $integrity->map(fn (AuditEvent $r) => $this->row($r))->all(),
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
    private function verdict(array $config, array $counts, array $integrity): array
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

        /*
         * INTEGRITY OUTRANKS EVERYTHING BELOW IT, including "nothing is being
         * recorded". A wrong password is somebody trying to get in; a shipped
         * file that no longer matches the package that installed it is
         * somebody who already did — or a package that did not apply cleanly,
         * which on a host with no shell is the same emergency. It is the most
         * serious sentence this screen can say, so it is the first one it
         * checks.
         */
        if ($integrity['findings'] > 0) {
            $n = (int) $integrity['findings'];
            $named = array_slice($integrity['paths'], 0, 3);

            return [
                'tone' => 'act',
                'line' => 'Worth a look: '.$n.' shipped '.($n === 1 ? 'file does' : 'files do')
                    .' not match the package that installed '.($n === 1 ? 'it' : 'them').'.',
                'detail' => ($named === [] ? '' : 'Starting with '.implode(', ', $named).'. ')
                    .'The findings are listed under "Integrity of the files packages installed" below, each with '
                    .'the hash the package declared and the hash the server holds now. '
                    .'Either something changed a file after it was installed, or a package did not apply '
                    .'cleanly — on a host with no shell those look identical from here, and both are worth '
                    .'opening. Nothing has been restored and nothing has been blocked: this screen reports, '
                    .'and whether a file is put back automatically is a decision that has not been taken yet.',
            ];
        }

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
     * non-hot-path moment this application reliably has: THERE IS NO CRON ON
     * THIS HOST AND NO QUEUE WORKER RUNNING, so a schedule would be a schedule
     * that never runs. That was round one's whole answer, and it was half of
     * one — "a shop nobody opens the screen on keeps its rows" is only a safe
     * direction to fail in until you ask how many rows.
     *
     * So this is now the calendar half of two. enforceCap() below is the other,
     * and it runs on the write path rather than here, which is what makes the
     * table bounded on a shop where this screen is never opened at all.
     */
    public function prune(): int
    {
        $days = (int) $this->get('keep_days');

        $byAge = (int) AuditEvent::query()
            ->where('occurred_at', '<', Carbon::now()->subDays($days))
            ->delete();

        return $byAge + $this->enforceCap();
    }

    /**
     * Keep the newest `max_rows` rows and drop the rest. Two queries.
     *
     * ── WHY A COUNT CEILING AS WELL AS A DATE ONE ───────────────────────────
     *
     * `keep_days` only bounds the trail if something runs. Nothing does: no
     * cron, no queue worker, and prune() fires when an owner opens Store →
     * Security. That is fine for the rows an owner's own work produces —
     * settings, accounts, modules, packages — because those need a signed-in
     * admin and are therefore bounded by how much work a person does.
     *
     * It is not fine for the two kinds that do not. Failed sign-ins are written
     * by anybody who can reach the login form, at five a minute past the
     * throttle, which is 7,200 rows a day for as long as somebody cares to keep
     * trying. Rate-limit trips collapse onto one row per address per path per
     * window, so a single flood is cheap — but a rotating address is not. On a
     * shop whose owner opens this screen twice a year, either of those grows a
     * table on a 1 GB shared plan with nothing watching.
     *
     * ── THE CUT, RATHER THAN A COUNT AND AN OFFSET DELETE ───────────────────
     *
     * `skip($max)->take(1)->value('id')` finds the id of the first row PAST the
     * ceiling, ordered newest first; everything at or below it goes. That is
     * one indexed lookup and one ranged delete on the primary key. `DELETE …
     * ORDER BY … LIMIT` is not portable to SQLite, which is what the tests run
     * on, and `whereNotIn` a list of 20,000 ids is not a query anybody should
     * write.
     *
     * BY ID AND NOT BY occurred_at, because the ceiling's job is "how many
     * rows", and id order is insertion order — which is the order the rows were
     * written even where two share a timestamp to the second.
     */
    public function enforceCap(): int
    {
        try {
            $max = (int) $this->get('max_rows');

            $cut = AuditEvent::query()
                ->orderByDesc('id')
                ->skip($max)
                ->take(1)
                ->value('id');

            if ($cut === null) {
                return 0;
            }

            return (int) AuditEvent::query()->where('id', '<=', $cut)->delete();
        } catch (\Throwable) {
            // The ceiling is housekeeping. It never costs a row, a save or a
            // screen.
            return 0;
        }
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
