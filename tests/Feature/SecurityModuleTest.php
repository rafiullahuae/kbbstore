<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\AuditEvent;
use App\Models\Setting;
use App\Models\UpdateRelease;
use App\Services\SecurityModule;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * The security module, part one: the audit trail and Store → Security.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT WAS WRONG WITH THE SHOP BEFORE THIS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Nothing recorded administrative action. A setting that is wrong today was
 * right last week and there was no row anywhere saying who moved it, from what
 * address, or what it said before — while `not_found_log` remembered every
 * address a visitor mistyped and `payment_events` remembered every message a
 * gateway sent. A wrong password on the admin login left no trace at all, and a
 * 429 was answered to the caller and forgotten by the shop the same instant.
 *
 * Every `it(...)` below states the defect it stands for in its own words, and
 * each one goes red against the tree as it was before this lane.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * AND THE HALF THAT IS NOT A FEATURE: THIS BLOCKS NOTHING
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Phase 18's sequencing is report before enforce, so the module's most
 * important property is a NEGATIVE one — not one request the shop answered
 * before may be refused now. The last section pins that directly rather than
 * by inspection: the storefront is walked with the module on and every
 * response code is compared with the same walk taken with it off.
 */

/** An admin of the given role, with a password the login form can use. */
function secAdmin(string $role = 'owner', string $password = 'lane-c-password'): AdminUser
{
    return AdminUser::create([
        'name' => 'Sec '.$role,
        'email' => 'sec-'.$role.'-'.uniqid().'@example.test',
        'password' => $password,
        'role' => $role,
    ]);
}

/**
 * The two endpoints, registered exactly as routes/web.php will carry them.
 *
 * routes/web.php is the integrator's file and no lane may edit it, so the
 * route file is declared and left unwired. Registering it here from the real
 * file — rather than restating two Route:: calls — means this suite exercises
 * the file the integrator will require, including its middleware and its
 * names, and a typo in it fails here rather than after a package is applied.
 */
function secRegisterRoutes(): void
{
    Route::middleware(['web', 'auth:admin', \App\Http\Middleware\NoStoreAdminApi::class])
        ->prefix('admin-api')
        ->group(base_path('routes/security-admin.php'));
}

/* ═══════════════════════════════════════════ 1. administrative changes ═══ */

it('records who changed a setting, from where, and what it said before', function () {
    /*
     * THE DEFECT. `settings` is the whole configuration of this shop — the
     * currency, the admin path, the mail transport — and until now a change to
     * any row of it left no record of any kind. The first question after an
     * incident ("who changed this, and what was it before?") had no answer
     * anywhere in the application.
     */
    $admin = secAdmin('owner');
    $settings = app(SettingsService::class);

    $settings->set('sec_probe_setting', 'before-value');

    $this->actingAs($admin, 'admin');

    $settings->set('sec_probe_setting', 'after-value');

    $row = AuditEvent::query()->where('event', SecurityModule::E_SETTING)
        ->where('subject', 'sec_probe_setting')->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->actor_label)->toBe($admin->email)
        ->and($row->actor_role)->toBe('owner')
        ->and($row->before)->toBe('before-value')
        ->and($row->after)->toBe('after-value')
        ->and($row->summary)->toContain('sec_probe_setting');

    /*
     * MUTATION NOTE. Delete the Setting::saved hook from
     * SecurityModule::listen() — or drop the `actor_label` assignment in
     * record() — and this is red: the row is either absent or anonymous.
     */
});

it('records nothing at all when nobody is signed in', function () {
    /*
     * The other half of the same rule, and the one that keeps the trail
     * meaningful AND the storefront free. IndexNow mints its key into
     * `settings` on an ordinary visitor's request, and `php artisan migrate`
     * writes settings on a fresh install: neither is an administrative act,
     * and a trail that recorded them would arrive full of rows nobody did.
     */
    app(SettingsService::class)->set('sec_probe_unattended', 'x');

    expect(AuditEvent::query()->count())->toBe(0);

    /*
     * MUTATION NOTE. Remove the `$group === 'audit' && $admin === null` guard
     * in record() and this is red with one row whose actor is blank.
     */
});

it('reads no setting of its own when nobody is signed in', function () {
    /*
     * THE BUG THIS LANE SHIPPED AND THEN FIXED, written down because it is the
     * subtlest thing in the module.
     *
     * record() used to consult its own on/off switch BEFORE it asked whether an
     * admin was signed in. Reading a switch means SettingsService::all(), and
     * that fills `kbb.settings` — a rememberForever cache — with whatever the
     * settings table holds at that instant. `php artisan migrate` writes
     * settings rows, so the hook ran DURING the migration set and cached a
     * HALF-MIGRATED table for the rest of the process.
     *
     * It did not look like this module at all. Five unrelated tests went red:
     * /shop and every category archive paginated from settings that did not
     * exist yet, so `/shop?paged=57` answered 200 where it must answer 404, and
     * a product page derived a star rating it should never have had.
     *
     * The fix is one reordering — hasUser() first, which is a property read
     * that touches no query, no cache and no setting.
     */
    \Illuminate\Support\Facades\Cache::forget('kbb.settings');
    SettingsService::forgetMemo();

    // Exactly what a migration does: a write straight at the model.
    Setting::query()->updateOrCreate(['key' => 'sec_probe_cold'], ['value' => 'x', 'autoload' => true]);

    expect(\Illuminate\Support\Facades\Cache::has('kbb.settings'))->toBeFalse(
        'writing a setting with nobody signed in filled the settings cache from inside the write'
    );

    /*
     * MUTATION NOTE. Move the `$group === 'audit' && $admin === null` test back
     * below the `$this->get($switch)` read in record() and this is red — and so
     * are ProductSeoTest and three of SeoCrawlSurfaceTest, which is how it was
     * found.
     */
});

it('writes one row for the value that moved and none for the forty that did not', function () {
    /*
     * THE DEFECT THIS AVOIDS. Every settings screen in this console posts its
     * whole schema on Save. Recording each write would bury the one change that
     * matters under forty rows that say nothing, which is the failure mode of
     * most audit logs — they are ignored because they are noise.
     */
    $this->actingAs(secAdmin('owner'), 'admin');

    $settings = app(SettingsService::class);
    $settings->set('sec_probe_a', 'one');
    $settings->set('sec_probe_b', 'two');

    $before = AuditEvent::query()->count();

    // Re-save both with identical values, as a screen's Save button does.
    $settings->set('sec_probe_a', 'one');
    $settings->set('sec_probe_b', 'two');
    $settings->set('sec_probe_a', 'CHANGED');

    expect(AuditEvent::query()->count())->toBe($before + 1)
        ->and(AuditEvent::query()->latest('id')->first()->after)->toBe('CHANGED');

    /*
     * MUTATION NOTE. Drop the `wasChanged('value')` test in the Setting::saved
     * hook and this is red with three extra rows.
     */
});

it('withholds the value of a setting whose name says it holds a credential', function () {
    /*
     * THE DEFECT THIS PREVENTS. `settings` holds `admin_path` — the secret
     * address of this console — and `indexnow_key`, and CLAUDE.md records both
     * by name as things that have leaked from this shop before. An audit trail
     * that copied every old and new value would be a second, permanent,
     * screen-readable copy of all of them, in every backup.
     */
    $this->actingAs(secAdmin('owner'), 'admin');

    $settings = app(SettingsService::class);
    $settings->set('stripe_secret_key', 'sk_live_0000000000');
    $settings->set('stripe_secret_key', 'sk_live_1111111111');
    $settings->set('sec_probe_plain', 'not-a-secret');

    $secret = AuditEvent::query()->where('subject', 'stripe_secret_key')->latest('id')->first();
    $plain = AuditEvent::query()->where('subject', 'sec_probe_plain')->latest('id')->first();

    expect($secret->after)->toBe(SecurityModule::WITHHELD)
        ->and($secret->before)->toBe(SecurityModule::WITHHELD)
        ->and(AuditEvent::query()->where('after', 'like', '%sk_live%')->count())->toBe(0)
        ->and(AuditEvent::query()->where('before', 'like', '%sk_live%')->count())->toBe(0)
        // and the redaction is not simply "withhold everything".
        ->and($plain->after)->toBe('not-a-secret');

    /*
     * MUTATION NOTE. Remove 'secret' (or 'key') from SecurityModule::
     * SECRET_HINTS and this is red — the live key is then in two rows and in
     * the like-query counts above.
     */
});

it('records a role change as the alert it is, and never the password', function () {
    /*
     * THE DEFECT. A support account PUTting its own row to role=owner is the
     * exact reach App\Support\AdminCapabilities was built to close. Closing it
     * stops the reach; it leaves no record of an owner-level account being
     * created by hand either, which is the same act by a different route.
     */
    $this->actingAs(secAdmin('owner'), 'admin');

    $victim = secAdmin('support');
    $victim->update(['role' => 'owner', 'password' => 'a-new-password-entirely']);

    $row = AuditEvent::query()->where('event', SecurityModule::E_ACCOUNT)
        ->where('subject', $victim->email)->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->severity)->toBe('alert')
        ->and($row->before)->toContain('role: support')
        ->and($row->after)->toContain('role: owner')
        ->and($row->after)->toContain('password: changed')
        // The hash itself is nowhere in the row, in either column.
        ->and($row->after)->not->toContain('$2y$')
        ->and((string) $row->before)->not->toContain('$2y$');

    /*
     * MUTATION NOTE. Change accountChanges() to record
     * `'after' => $admin->password` and this is red on the last two
     * expectations.
     */
});

it('records which package was installed', function () {
    // "Who installed which package" — the third of the three the plan names.
    $this->actingAs(secAdmin('owner'), 'admin');

    $release = UpdateRelease::create([
        'name' => 'security-module.zip', 'version' => '2.60.999', 'status' => 'pending',
    ]);
    $release->update(['status' => 'applied']);

    $rows = AuditEvent::query()->where('event', SecurityModule::E_PACKAGE)
        ->orderBy('id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[1]->summary)->toContain('2.60.999')
        ->and($rows[1]->summary)->toContain('applied')
        ->and($rows[1]->before)->toBe('status: pending');
});

/* ══════════════════════════════════════════════════════ 2. sign-ins ═══ */

it('records a failed sign-in with the email tried and never the password', function () {
    /*
     * THE DEFECT. A wrong password on /admin/login produced a validation error
     * and nothing else. A hundred of them produced a hundred validation errors
     * and nothing else: there was no way, from inside the shop, to know anybody
     * had ever tried.
     */
    secAdmin('owner', 'the-real-password');

    $this->post(route('admin.login.post'), [
        'email' => 'sec-intruder@example.test',
        'password' => 'hunter2-wrong-guess',
    ]);

    $row = AuditEvent::query()->where('event', SecurityModule::E_SIGNIN_FAILED)->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->subject)->toBe('sec-intruder@example.test')
        ->and($row->actor_label)->toBe('sec-intruder@example.test')
        ->and($row->severity)->toBe('notice')
        // The credentials array carries the password. It must not be here, in
        // any column, in any form.
        ->and(collect($row->getAttributes())->filter(
            fn ($v) => is_string($v) && str_contains($v, 'hunter2')
        )->all())->toBe([]);

    /*
     * MUTATION NOTE. Change the Failed listener to pass
     * `'after' => json_encode($event->credentials)` and the last expectation is
     * red with the plaintext password in it.
     */
});

it('records a successful sign-in and a sign-out', function () {
    $admin = secAdmin('owner', 'the-real-password');

    $this->post(route('admin.login.post'), [
        'email' => $admin->email,
        'password' => 'the-real-password',
    ]);

    expect(AuditEvent::query()->where('event', SecurityModule::E_SIGNIN)->count())->toBe(1);

    $this->actingAs($admin, 'admin')->post(route('admin.logout'));

    expect(AuditEvent::query()->where('event', SecurityModule::E_SIGNOUT)->count())->toBe(1);
});

it('records the login throttle turning somebody away', function () {
    /*
     * THE DEFECT, and the one rate-limit trip nothing else in this module can
     * see. AdminAuthController's own throttle answers with a validation error
     * and a 302 rather than a 429, and it refuses BEFORE the guard is asked —
     * so neither the Failed event nor the 429 listener hears it. It is the
     * limiter standing in front of the password, and it left no trace at all.
     */
    $admin = secAdmin('owner', 'the-real-password');

    for ($i = 0; $i < 6; $i++) {
        $this->post(route('admin.login.post'), [
            'email' => $admin->email,
            'password' => 'wrong-'.$i,
        ]);
    }

    $blocked = AuditEvent::query()->where('event', SecurityModule::E_SIGNIN_BLOCKED)->latest('id')->first();

    expect($blocked)->not->toBeNull()
        ->and($blocked->severity)->toBe('alert')
        ->and($blocked->subject)->toBe(strtolower($admin->email))
        ->and($blocked->after)->toContain('cooldown');

    /*
     * MUTATION NOTE. Remove the recordSignInBlocked() call from
     * AdminAuthController::login() and this is red: five failed sign-ins are
     * recorded and the refusal that followed them is not.
     */
});

/* ═════════════════════════════════════════════ 3. rate-limit trips ═══ */

it('records a request the shop refused as too many, and collapses the repeats', function () {
    /*
     * THE DEFECT, in the plan's own words: rate-limit trips "vanish silently
     * today". Six controllers and the /api product index carry limiters; each
     * answers 429 to that one caller and forgets, so a shop being scraped looks
     * exactly like a quiet one from the backend.
     *
     * AND THE REASON THEY COLLAPSE. A trip arrives repeatedly BY DEFINITION.
     * One row per 429 would turn a flood into a flood of inserts, which is the
     * shape of a denial of service the logging invented.
     */
    /*
     * TWO PATH SEGMENTS, deliberately. routes/web.php ends in a catch-all
     * single root segment — the shape of every storefront URL there is — so a
     * one-segment probe registered from a test lands after it and is read as an
     * article slug. AdminCapabilityMapTest carries the same note for the same
     * reason.
     */
    Route::middleware(['web', 'throttle:1,1'])->get('/sec-probe/limited', fn () => response('ok'));

    $this->get('/sec-probe/limited')->assertOk();
    $this->get('/sec-probe/limited')->assertStatus(429);
    $this->get('/sec-probe/limited')->assertStatus(429);
    $this->get('/sec-probe/limited')->assertStatus(429);

    $rows = AuditEvent::query()->where('event', SecurityModule::E_RATE_LIMIT)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->path)->toBe('/sec-probe/limited')
        ->and($rows[0]->hits)->toBe(3)
        ->and($rows[0]->actor_label)->toBeNull();

    /*
     * MUTATION NOTE. Remove the RequestHandled listener from
     * SecurityModule::listen() and this is red with no rows at all; remove the
     * cache-keyed collapse in recordRateLimitTrip() and it is red with three.
     */
});

/* ══════════════════════════════════════════════════ 4. the screen ═══ */

it('gives the owner the report and refuses every other role', function () {
    /*
     * Secure by construction: the screen has a capability of its own and fails
     * closed. Every row it draws names an operator's email, their role and an
     * IP address, which is precisely the pair `/api/*` has leaked before.
     */
    secRegisterRoutes();

    expect(AdminCapabilities::forPath('GET', 'admin-api/security'))->toBe('security.view')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/security'))->toBe('security.view')
        ->and(AdminCapabilities::CAPABILITIES['security.view'])->toBe(['owner']);

    $this->actingAs(secAdmin('owner'), 'admin')->getJson('/admin-api/security')
        ->assertOk()
        ->assertJsonStructure(['tabs', 'report' => ['verdict' => ['tone', 'line', 'detail'], 'counts']]);

    foreach (['manager', 'support', 'editor'] as $role) {
        $this->actingAs(secAdmin($role), 'admin')->getJson('/admin-api/security')->assertForbidden();
    }

    /*
     * MUTATION NOTE. Change the `security.view` row in AdminCapabilities to
     * include 'manager' and the loop is red on its first pass.
     */
});

it('refuses the report to a request with no admin session at all', function () {
    secRegisterRoutes();

    // auth:admin owns this case and sends it to the login form, which is the
    // behaviour EnforceAdminCapability's docblock requires: a logged-out owner
    // must never meet a dead end on a host with no other way in.
    $this->get('/admin-api/security')->assertRedirect(route('admin.login'));
});

it('says plainly that there is nothing to act on, and says so differently when there is', function () {
    /*
     * "A plain verdict line at the top rather than a dashboard that has to be
     * interpreted" — the owner's ask, read literally. A dashboard of four
     * numbers is a dashboard whatever it is called; the test is that the shop
     * reaches a CONCLUSION and prints it as a sentence.
     */
    $module = app(SecurityModule::class);

    expect($module->report()['verdict']['tone'])->toBe('quiet')
        ->and($module->report()['verdict']['line'])->toBe('Nothing to act on.');

    $module->save(['fail_threshold' => 3]);

    $this->actingAs(secAdmin('owner'), 'admin');

    for ($i = 0; $i < 3; $i++) {
        AuditEvent::create([
            'occurred_at' => Carbon::now(), 'last_seen_at' => Carbon::now(), 'hits' => 1,
            'event' => SecurityModule::E_SIGNIN_FAILED, 'severity' => 'notice',
            'ip' => '203.0.113.'.$i, 'summary' => 'Failed sign-in',
        ]);
    }

    $verdict = app(SecurityModule::class)->report()['verdict'];

    expect($verdict['tone'])->toBe('act')
        ->and($verdict['line'])->toContain('3 failed sign-ins')
        // and it still says, in every branch, what it will not do for you.
        ->and($verdict['detail'])->toContain('blocks nothing');

    /*
     * MUTATION NOTE. Raise `fail_threshold`'s comparison in verdict() from >=
     * to > and the tone is 'quiet' here.
     */
});

it('says so when it is recording nothing, rather than showing an empty screen', function () {
    /*
     * The failure this exists for: three switches off and a screen that looks
     * identical to a quiet week. "Nothing happened" and "nothing is being
     * watched" must never render the same.
     */
    app(SecurityModule::class)->save(['audit_on' => false, 'signin_on' => false, 'rl_on' => false]);

    $verdict = app(SecurityModule::class)->report()['verdict'];

    expect($verdict['tone'])->toBe('act')
        ->and($verdict['line'])->toBe('Nothing is being recorded.');
});

it('stops recording when the switch is off and keeps what it already had', function () {
    $this->actingAs(secAdmin('owner'), 'admin');

    app(SettingsService::class)->set('sec_probe_kept', 'one');
    $kept = AuditEvent::query()->count();

    expect($kept)->toBeGreaterThan(0);

    app(SecurityModule::class)->save(['audit_on' => false]);
    $afterSwitch = AuditEvent::query()->count();

    app(SettingsService::class)->set('sec_probe_kept', 'two');

    expect(AuditEvent::query()->count())->toBe($afterSwitch)
        ->and(AuditEvent::query()->where('subject', 'sec_probe_kept')->count())->toBeGreaterThan(0);
});

it('refuses a setting it does not know and clamps one it does', function () {
    secRegisterRoutes();

    $owner = secAdmin('owner');

    $this->actingAs($owner, 'admin')
        ->postJson('/admin-api/security', ['settings' => ['not_a_setting' => 1]])
        ->assertStatus(422);

    // A range stores one of its own bounds or a value inside them — the same
    // rule a select stores one of its own options. keep_days = 0 would turn
    // "open the screen" into "delete the whole trail".
    $this->actingAs($owner, 'admin')
        ->postJson('/admin-api/security', ['settings' => ['keep_days' => 0]])
        ->assertOk();

    expect(app(SecurityModule::class)->get('keep_days'))
        ->toBe(SecurityModule::SCHEMA['keep_days'][4]['min']);
});

it('deletes evidence older than the retention it was given', function () {
    // Blocks and findings carry request data, which is personal data, and it
    // should expire on a schedule rather than accumulate — Phase 18's own open
    // question, answered with a slider and a prune on screen open.
    $module = app(SecurityModule::class);
    $module->save(['keep_days' => 7]);

    AuditEvent::create([
        'occurred_at' => Carbon::now()->subDays(30), 'last_seen_at' => Carbon::now()->subDays(30),
        'event' => SecurityModule::E_SETTING, 'severity' => 'info', 'summary' => 'old row',
    ]);
    AuditEvent::create([
        'occurred_at' => Carbon::now()->subDay(), 'last_seen_at' => Carbon::now()->subDay(),
        'event' => SecurityModule::E_SETTING, 'severity' => 'info', 'summary' => 'recent row',
    ]);

    expect(app(SecurityModule::class)->prune())->toBe(1)
        ->and(AuditEvent::query()->pluck('summary')->all())->toBe(['recent row']);
});

it('masks the last part of an address only when asked to', function () {
    $this->actingAs(secAdmin('owner'), 'admin');

    app(SecurityModule::class)->save(['ip_mask' => true]);
    app(SettingsService::class)->set('sec_probe_masked', 'x');

    $row = AuditEvent::query()->where('subject', 'sec_probe_masked')->latest('id')->first();

    // The test client's address is 127.0.0.1.
    expect($row->ip)->toBe('127.0.0.x');
});

/* ═══════════════════════════════════════════════════ 5. the cost ═══ */

it('reads the report in the same number of queries however many rows there are', function () {
    /*
     * No N+1, measured rather than asserted — the rule StorefrontQueryBudgetTest
     * sets for the storefront, applied to the one screen in this module that
     * lists rows. The design that makes it true is the denormalised actor on
     * every row: there is no join to admin_users, so the cost cannot grow with
     * the number of distinct actors, settings or addresses on the page.
     */
    $module = app(SecurityModule::class);

    $seed = function (int $n) {
        for ($i = 0; $i < $n; $i++) {
            AuditEvent::create([
                'occurred_at' => Carbon::now()->subMinutes($i), 'last_seen_at' => Carbon::now(),
                'event' => SecurityModule::E_SETTING, 'severity' => 'info',
                'actor_label' => 'admin-'.$i.'@example.test', 'actor_role' => 'owner',
                'ip' => '198.51.100.'.($i % 250), 'subject' => 'key_'.$i, 'summary' => 'Setting changed',
            ]);
        }
    };

    $count = function () use ($module) {
        $queries = 0;
        DB::listen(function () use (&$queries) { $queries++; });
        $module->report();

        return $queries;
    };

    /*
     * A WARM-UP PASS FIRST, for the reason StorefrontQueryBudgetTest states:
     * several caches here live for the life of the PROCESS rather than the
     * request — the settings snapshot among them — so the very first report()
     * pays for reads no later one repeats, and measuring it would compare a
     * cold page with a warm one and call the difference an improvement.
     */
    $seed(5);
    $module->report();
    $small = $count();

    $seed(120);
    $large = $count();

    expect($large)->toBe($small, "the report went {$small} -> {$large} queries as the trail grew");
});

/* ══════════════════════════════════════ 6. and it blocks nothing ═══ */

it('refuses no request the shop answered before', function () {
    /*
     * THE NON-NEGOTIABLE, and the reason this module reports before it
     * enforces. A security layer that starts blocking on day one blocks the
     * owner, the payment provider's webhooks and Google's crawler, gets
     * switched off, and leaves the shop worse off than before — because
     * everyone now believes it is protected.
     *
     * Asserted rather than described: a walk of the storefront with everything
     * recording, compared against the same walk with every switch off. Any gate
     * in this module, now or later by accident, moves one of these codes.
     */
    /*
     * Pages this fixture actually serves, plus one address it does not. The
     * missing one is load-bearing: without it the loop below could be satisfied
     * by a shop that answers everything, including what it should refuse.
     *
     * `/journal` is deliberately absent — it is a 404 in this fixture as it
     * stands, which is the storefront's business and not this module's.
     */
    $paths = ['/', '/shop', '/cart', '/checkout', '/wishlist', '/sec-probe-not-a-page'];

    $with = [];

    foreach ($paths as $path) {
        $with[$path] = $this->get($path)->getStatusCode();
    }

    app(SecurityModule::class)->save(['audit_on' => false, 'signin_on' => false, 'rl_on' => false]);

    $without = [];

    foreach ($paths as $path) {
        $without[$path] = $this->get($path)->getStatusCode();
    }

    expect($with)->toBe($without);

    /*
     * AND THE SAME THING IN ABSOLUTE TERMS, which is the half that actually
     * bites. The comparison above measures the module against ITSELF: a gate
     * that refuses in both passes matches itself perfectly and the comparison
     * reports all clear. Verified by mutation — forcing a 403 on /cart from
     * inside SecurityModule left the two walks identical and the expectation
     * above green.
     *
     * So the codes are also named: every page the shop serves still answers,
     * and the only refusal is the address that does not exist.
     */
    foreach ($paths as $path) {
        if ($path === '/sec-probe-not-a-page') {
            expect($with[$path])->toBe(404, 'a missing page stopped being a 404');

            continue;
        }

        expect($with[$path])->toBeLessThan(400, "{$path} answered {$with[$path]}");
    }

    // And a storefront walk writes no audit row at all: nobody is signed in,
    // so nothing on it is an administrative act.
    expect(AuditEvent::query()->where('event', SecurityModule::E_SETTING)->count())->toBe(0);

    /*
     * MUTATION NOTE. Make the RequestHandled listener call
     * $event->response->setStatusCode(403) for any path — one line, and the
     * first line any request gate would grow — and this is red on the loop
     * above. It was green on the comparison alone, which is why the loop is
     * here.
     */
});

it('registers no middleware anywhere', function () {
    /*
     * The structural half of the same promise, and the one that survives a
     * later lane. "Report before enforce" is a property of WHERE this module
     * hooks in, not of what its code happens to do today: a listener on
     * RequestHandled fires with the finished response in hand and has nothing
     * to return, while a middleware could return early from anywhere in the
     * file.
     *
     * So the module's source may not name the middleware registration API at
     * all, and the class must not be one.
     */
    $source = (string) file_get_contents(app_path('Services/SecurityModule.php'));

    foreach ([
        'prependMiddleware',
        'pushMiddleware',
        'appendMiddlewareToGroup',
        'prependMiddlewareToGroup',
        'aliasMiddleware',
        'function handle(',
    ] as $forbidden) {
        expect(str_contains($source, $forbidden))->toBeFalse(
            "SecurityModule names {$forbidden}: this lane ships the report, and the gate is a later round"
        );
    }

    expect(class_exists(\App\Http\Middleware\SecurityModule::class))->toBeFalse();

    /*
     * MUTATION NOTE. Add a `public function handle(Request $r, Closure $next)`
     * to SecurityModule — the first line of any request gate — and this is red.
     */
});

it('draws its own sidebar row and asks for the report over the guarded prefix', function () {
    // The screen is included in the console and reaches the endpoint under
    // /admin-api, not /api — the unauthenticated prefix this project has
    // leaked from three times.
    $console = (string) file_get_contents(resource_path('views/admin/app.blade.php'));
    $screen = (string) file_get_contents(resource_path('views/admin/partials/security-screen.blade.php'));

    expect($console)->toContain("@include('admin.partials.security-screen')")
        ->and($screen)->toContain("group: 'Store'")
        ->and($screen)->toContain("label: 'Security'")
        ->and($screen)->toContain("'/admin-api'")
        // Nothing on this screen may measure layout: two tests in this repo
        // forbid the element-measuring APIs by name and this screen sizes in
        // CSS, on one render.
        ->and($screen)->not->toContain('getBoundingClientRect')
        ->and($screen)->not->toContain('offsetWidth')
        ->and($screen)->not->toContain('scrollWidth')
        // A grid child's default min-width is auto, which is the defect
        // AdminScreenGridOverflowTest exists for.
        ->and($screen)->toMatch('/\.sx-wrap\s*>\s*\*\{[^}]*min-width:0/');
});
