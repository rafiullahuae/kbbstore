<?php

declare(strict_types=1);

/*
 * The owner app's security review (Lane SEC). One test per confirmed defect;
 * each comment says what the defect looked like on the shop and the change
 * that turns the test red again (every MUTATION below was run).
 *
 * The parallel-request half of the PIN lockout lives in OwnerAppRaceTest.
 */

use App\Http\Controllers\OwnerApp\LiveController;
use App\Mail\OwnerAppSecurityAlert;
use App\Models\AdminUser;
use App\Services\OwnerApp\OwnerAppAuth;
use App\Services\OwnerApp\OwnerAppEvents;
use App\Services\OwnerApp\OwnerAppPath;
use App\Services\OwnerApp\OwnerAppThrottle;
use App\Services\OwnerApp\WebPush;
use App\Support\AdminRoles;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\Support\OwnerAppRoutes as OA;

beforeEach(function () {
    OA::wire($this->app);
});

/** Unregister the app's routes and register them again (after the host setting moved). */
function oaSecRewire(): void
{
    $router = Route::getFacadeRoot();
    $kept = new RouteCollection();
    foreach ($router->getRoutes() as $r) {
        if (! str_starts_with((string) $r->getName(), 'owner-app.')) {
            $kept->add($r);
        }
    }
    $router->setRoutes($kept);
    Route::middleware('web')->group(base_path('routes/owner-app.php'));
    $router->getRoutes()->refreshNameLookups();
    $router->getRoutes()->refreshActionLookups();
}

function oaSecEnrolFrom($test, string $ip, string $email, string $pin)
{
    return $test->flushHeaders()->withServerVariables(['REMOTE_ADDR' => $ip])->withHeaders(['X-OA' => '1'])
        ->postJson(OA::base().'/api/enrol', ['email' => $email, 'pin' => $pin]);
}

/** A hasher that counts what it is asked to do. */
function oaSecCountingHasher(Hasher $inner): Hasher
{
    return new class($inner) implements Hasher
    {
        public int $checks = 0;

        public int $makes = 0;

        public function __construct(private Hasher $inner) {}

        public function info($hashedValue)
        {
            return $this->inner->info($hashedValue);
        }

        public function make($value, array $options = [])
        {
            $this->makes++;

            return $this->inner->make($value, $options);
        }

        public function check($value, $hashedValue, array $options = [])
        {
            $this->checks++;

            return $this->inner->check($value, $hashedValue, $options);
        }

        public function needsRehash($hashedValue, array $options = [])
        {
            return $this->inner->needsRehash($hashedValue, $options);
        }
    };
}

/* --------------------------------------------- 1. the lockout ladder */

it('escalates 15 min → 1 h → 24 h → Full Admin only, and emails the Full Admins at 24 h and at the admin lock', function () {
    // DEFECT: a fixed 15-minute pause forever — a patient guesser gets 480
    // tries a day, and nobody is told. MUTATION: set LADDER[2] to 15 and the
    // second retry_minutes is red; drop OwnerAppAlerts::memberLocked() from
    // escalate() and the mail count is red.
    Mail::fake();
    $owner = OA::admin();
    $memberId = OA::member($owner);
    OA::admin('manager', 'max@example.com', 'Max Manager');

    $minutes = [1 => 15, 2 => 60, 3 => 1440];
    for ($round = 1; $round <= 4; $round++) {
        // A fresh phone each round: the per-device cap (10) is its own rule.
        [$c] = OA::enrol($this);
        for ($i = 1; $i <= 4; $i++) {
            OA::post($this, 'unlock', ['pin' => '135790'], $c, null)->assertStatus(422);
        }
        $r = OA::post($this, 'unlock', ['pin' => '135790'], $c, null)->assertStatus(423)->assertJsonPath('code', 'locked');
        // While locked even the RIGHT PIN is refused, without a hash check.
        OA::post($this, 'unlock', ['pin' => '482613'], $c, null)->assertStatus(423);

        if ($round < 4) {
            expect($r->json('retry_minutes'))->toBe($minutes[$round]);
            $this->travel($minutes[$round] + 1)->minutes();
        } else {
            expect($r->json('admin_unlock'))->toBeTrue()->and($r->json('retry_minutes'))->toBeNull();
        }
    }

    $this->travel(30)->days();
    [$c] = OA::enrol($this);
    OA::post($this, 'unlock', ['pin' => '482613'], $c, null)->assertStatus(423)->assertJsonPath('admin_unlock', true);

    $path = OwnerAppPath::current();
    Mail::assertSent(OwnerAppSecurityAlert::class, 2);
    Mail::assertSent(OwnerAppSecurityAlert::class, function (OwnerAppSecurityAlert $m) use ($path) {
        $all = $m->subjectLine.' '.implode(' ', $m->lines).' '.$m->content()->htmlString;

        return $m->hasTo('owner@example.com') && ! $m->hasTo('max@example.com')
            && ! str_contains($all, $path) && ! str_contains($all, 'http') && str_contains($all, 'Rafi Owner');
    });

    // The admin sees it and lifts it: Users & Roles → Owner app → Unlock now.
    $list = $this->actingAs($owner, 'admin')->getJson('/admin-api/owner-app')->assertOk();
    expect($list->json('members.0.locked'))->toBeTrue()->and($list->json('members.0.admin_locked'))->toBeTrue();
    $this->actingAs($owner, 'admin')->putJson('/admin-api/owner-app/members/'.$owner->id, ['unlock' => true])->assertOk();

    OA::post($this, 'unlock', ['pin' => '482613'], $c, null)->assertOk();
    expect((int) DB::table('owner_app_members')->where('id', $memberId)->value('lock_level'))->toBe(0);
});

it('emails the Full Admins once when a phone is signed out for wrong PINs', function () {
    // MUTATION: drop OwnerAppAlerts::deviceRevoked() from revokeForPins().
    Mail::fake();
    $owner = OA::admin();
    OA::member($owner);
    [$c] = OA::enrol($this);

    for ($i = 1; $i < OwnerAppAuth::DEVICE_REVOKE_AFTER; $i++) {
        DB::table('owner_app_members')->update(['locked_until' => null]);
        OA::post($this, 'unlock', ['pin' => '135790'], $c, null);
    }
    DB::table('owner_app_members')->update(['locked_until' => null]);
    OA::post($this, 'unlock', ['pin' => '135790'], $c, null)->assertStatus(403)->assertJsonPath('code', 'no_device');

    Mail::assertSent(OwnerAppSecurityAlert::class, 1);
    Mail::assertSent(OwnerAppSecurityAlert::class, fn ($m) => str_contains($m->subjectLine, 'signed out') && ! str_contains($m->content()->htmlString, OwnerAppPath::current()));
});

it('keeps the sign-in form’s failures off the PIN pad, so a stranger cannot lock the owner out of his own phone', function () {
    // DEFECT: one counter for both. Five wrong guesses at the public sign-in
    // form with the owner's email locked every phone he had already enrolled
    // — a denial of service for anyone who knows the address and an email.
    // MUTATION: point ENROL at the UNLOCK columns and the owner's unlock is 423.
    $owner = OA::admin();
    $memberId = OA::member($owner);
    [$c] = OA::enrol($this);

    for ($i = 1; $i <= 7; $i++) {
        oaSecEnrolFrom($this, '198.51.100.7', 'owner@example.com', '135790')->assertStatus(422)->assertJsonPath('code', 'not_recognised');
    }

    $m = DB::table('owner_app_members')->where('id', $memberId)->first();
    expect((int) $m->enrol_lock_level)->toBe(1)->and((int) $m->lock_level)->toBe(0)->and($m->locked_until)->toBeNull();

    // His phone still opens…
    OA::post($this, 'unlock', ['pin' => '482613'], $c, null)->assertOk();
    // …and the form stays paused, saying nothing different with the right PIN.
    oaSecEnrolFrom($this, '198.51.100.8', 'owner@example.com', '482613')->assertStatus(422)->assertJsonPath('code', 'not_recognised');
});

it('counts a connection by its IPv4 address or its IPv6 /64, in the database, atomically', function () {
    // DEFECT: the limiter keyed on the full IPv6 address — one phone line or
    // cloud VM owns a /64, 2^64 fresh limits — and counted in the FILE cache,
    // whose increment() is read-add-write with no lock. MUTATION: return $ip
    // unchanged from OwnerAppThrottle::network() and the 11th try is a 422.
    expect(OwnerAppThrottle::network('2001:db8:1:2:aaaa::1'))->toBe(OwnerAppThrottle::network('2001:db8:1:2:ffff:1:2:9'))
        ->and(OwnerAppThrottle::network('2001:db8:1:2::1'))->not->toBe(OwnerAppThrottle::network('2001:db8:1:3::1'))
        ->and(OwnerAppThrottle::network('::ffff:203.0.113.9'))->toBe('203.0.113.9')
        ->and(OwnerAppThrottle::network('203.0.113.9'))->toBe('203.0.113.9');

    for ($i = 1; $i <= OwnerAppAuth::IP_MAX_ENROL_FAILS; $i++) {
        oaSecEnrolFrom($this, '2001:db8:1:2::'.dechex($i), 'nobody'.$i.'@example.com', '135790')->assertStatus(422);
    }
    oaSecEnrolFrom($this, '2001:db8:1:2:ffff::1', 'nobody@example.com', '135790')->assertStatus(429);
    oaSecEnrolFrom($this, '2001:db8:9:9::1', 'nobody@example.com', '135790')->assertStatus(422);

    $granted = 0;
    for ($i = 0; $i < 40; $i++) {
        $granted += OwnerAppThrottle::reserve('t|x', 30, 900) ? 1 : 0;
    }
    expect($granted)->toBe(30);
});

it('needs a PIN of six to eight digits, and switches off a member who somehow has a shorter one', function () {
    // MUTATION: put PIN_RULE back to {4,8} and the four-digit enrol succeeds.
    $owner = OA::admin();
    $id = OA::member($owner, '4826');

    oaSecEnrolFrom($this, '198.51.100.9', 'owner@example.com', '4826')->assertStatus(422);

    (require base_path('database/migrations/2027_08_25_100310_owner_app_security_hardening.php'))->up();

    $row = DB::table('owner_app_members')->where('id', $id)->first();
    expect($row->pin_hash)->toBeNull()->and((bool) $row->enabled)->toBeFalse();
    expect(OwnerAppAuth::pinProblem('48261'))->not->toBeNull()->and(OwnerAppAuth::pinProblem('482613'))->toBeNull();
});

/* ------------------------------------------ 2. same-origin script access */

it('never hands the CSRF value to a GET, and refuses every request behind the PIN without it', function () {
    // DEFECT: GET /api/state returned `csrf`, and GETs needed only the
    // cookies — which the browser attaches to ANY same-origin fetch(), so a
    // script on any shop page (an XSS, a third-party tag) could read orders
    // and customers, then write with the value it had just read.
    // MUTATION: delete the "no X-OA-CSRF -> 401 pin" branch in
    // OwnerAppSession and the header-less GET /orders is a 200.
    $owner = OA::admin();
    OA::member($owner);
    [$c, $csrf] = OA::enrol($this);
    expect($csrf)->toBe(OwnerAppAuth::csrfFor($c[OwnerAppAuth::SESSION_COOKIE]));

    $bare = $this->flushHeaders()->withCredentials()->withUnencryptedCookies($c)->withHeaders(['X-OA' => '1'])->getJson(OA::base().'/api/state')->assertOk();
    expect($bare->json('stage'))->toBe('pin')->and($bare->json('me'))->toBeNull()->and($bare->getContent())->not->toContain($csrf)->not->toContain('"csrf"');

    $app = $this->flushHeaders()->withCredentials()->withUnencryptedCookies($c)->withHeaders(['X-OA' => '1', 'X-OA-CSRF' => $csrf])->getJson(OA::base().'/api/state')->assertOk();
    expect($app->json('stage'))->toBe('app')->and($app->getContent())->not->toContain('"csrf"');

    OA::get($this, 'orders', $c, '')->assertStatus(401)->assertExactJson(['ok' => false, 'code' => 'pin']);
    OA::get($this, 'customers', $c, '')->assertStatus(401)->assertJsonPath('code', 'pin');
    OA::get($this, 'orders', $c, str_repeat('a', 64))->assertStatus(419);
    OA::get($this, 'orders', $c)->assertOk();
});

it('takes a PIN on a phone whose session is still live, rotating the session and the CSRF value', function () {
    // The app's fresh-launch path: the cookie is live, the in-memory value is
    // gone. MUTATION: have unlock() keep the old session and the old cookie
    // still opens /orders.
    $owner = OA::admin();
    OA::member($owner);
    [$c, $csrf] = OA::enrol($this);

    $u = OA::post($this, 'unlock', ['pin' => '482613'], $c, null)->assertOk();
    $fresh = OA::cookies($u, $c);

    expect($u->json('csrf'))->toBe(OwnerAppAuth::csrfFor($fresh[OwnerAppAuth::SESSION_COOKIE]))->not->toBe($csrf);
    OA::get($this, 'orders', $c)->assertStatus(401)->assertJsonPath('code', 'locked');
    OA::get($this, 'orders', $fresh)->assertOk();
});

it('serves the app only from its own host when one is set, with host-only cookies and nothing on the shop’s host', function () {
    // MUTATION: drop the ->domain() call in routes/owner-app.php and the
    // shop-host request is a 200.
    $owner = OA::admin();
    OA::member($owner);
    OA::enrol($this);

    try {
        oaSecHostCase($this, $owner);
    } finally {
        // Static memo and the router outlive this test: put both back.
        OwnerAppPath::setHost('');
        oaSecRewire();
    }
});

function oaSecHostCase($test, AdminUser $owner): void
{
    $test->actingAs($owner, 'admin')->putJson('/admin-api/owner-app/security', ['host' => 'Owner.Example.test'])->assertOk()
        ->assertJsonPath('security.host', 'owner.example.test')
        ->assertJsonPath('url', 'https://owner.example.test/'.OwnerAppPath::current().'/');
    expect(DB::table('owner_app_devices')->whereNull('revoked_at')->count())->toBe(0)
        ->and(DB::table('owner_app_devices')->value('revoked_reason'))->toBe('host_changed');

    oaSecRewire();
    $base = OA::base();

    $test->flushHeaders()->getJson('http://owner.example.test'.$base.'/api/state')->assertOk()->assertJsonPath('stage', 'enrol')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    $test->flushHeaders()->getJson('http://localhost'.$base.'/api/state')->assertNotFound();
    expect(DB::table('not_found_log')->whereRaw('LOWER(path) LIKE ?', ['%'.OwnerAppPath::current().'%'])->count())->toBe(0);

    $r = $test->flushHeaders()->withHeaders(['X-OA' => '1'])->postJson('http://owner.example.test'.$base.'/api/enrol', ['email' => 'owner@example.com', 'pin' => '482613'])->assertOk();
    foreach ($r->headers->getCookies() as $cookie) {
        expect($cookie->getDomain())->toBeNull()->and($cookie->getPath())->toBe($base);
    }

    // Bad hosts are refused, and an empty one puts the app back.
    foreach (['https://owner.example.test', 'owner.example.test/', 'owner', '203.0.113.9', 'owner.example.test:8443', '-x.example.com', ['a']] as $bad) {
        $test->actingAs($owner, 'admin')->putJson('http://localhost/admin-api/owner-app/security', ['host' => $bad])->assertStatus(422);
    }
    $test->actingAs($owner, 'admin')->putJson('http://localhost/admin-api/owner-app/security', ['host' => ''])->assertOk()->assertJsonPath('security.host', '');
    expect(OwnerAppPath::host())->toBeNull();
}

/* ------------------------------------------------- 3. enumeration by timing */

it('costs exactly one bcrypt check on every refusal, and refuses a malformed PIN before looking up the email', function () {
    // DEFECT: an unknown email cost Hash::make() AND Hash::check() (the dummy
    // was made per process, and PHP-FPM is a process per request), a member
    // one check — a timing oracle for "is this email staff?". And a malformed
    // PIN was tested only after the lookup. MUTATION: replace dummyHash() with
    // Hash::make(random_bytes(16)) and `makes` is 1; move the PIN_RULE test
    // below the lookup and admin_users appears in the query log.
    $owner = OA::admin();
    OA::member($owner);
    $off = OA::admin('manager', 'off@example.com', 'Off');
    OA::member($off, '482613', false);
    $locked = OA::admin('support', 'lock@example.com', 'Locked');
    $lockedId = OA::member($locked);
    DB::table('owner_app_members')->where('id', $lockedId)->update(['enrol_lock_level' => 1, 'enrol_locked_until' => now()->addHour()]);

    oaSecEnrolFrom($this, '198.51.100.1', 'warm@example.com', '135790');   // the dummy hash exists from here on

    $hasher = oaSecCountingHasher(Hash::getFacadeRoot());
    Hash::swap($hasher);

    $answers = [];
    foreach (['nobody@example.com', 'off@example.com', 'lock@example.com', 'owner@example.com'] as $i => $email) {
        $hasher->checks = $hasher->makes = 0;
        $answers[] = oaSecEnrolFrom($this, '198.51.100.'.(20 + $i), $email, '135790')->assertStatus(422)->json();
        expect([$email, $hasher->checks, $hasher->makes])->toBe([$email, 1, 0]);
    }
    expect(array_unique(array_map('json_encode', $answers)))->toHaveCount(1);

    $hasher->checks = 0;
    DB::flushQueryLog();
    DB::enableQueryLog();
    oaSecEnrolFrom($this, '198.51.100.40', 'owner@example.com', '12ab56')->assertStatus(422)->assertJson($answers[0]);
    oaSecEnrolFrom($this, '198.51.100.41', 'owner@example.com', '4826')->assertStatus(422)->assertJson($answers[0]);
    expect($hasher->checks)->toBe(0)
        ->and(collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains($q, 'admin_users') || str_contains($q, 'owner_app_members'))->all())->toBe([]);
});

/* -------------------------------------------- 4. background refreshes */

it('does not keep the session alive for a GET marked X-OA-Passive, and ignores that header on a write', function () {
    // DEFECT: the app's silent refreshes kept an unattended app unlocked
    // forever. MUTATION: drop the X-OA-Passive test from
    // OwnerAppSession::passive() and the first expectation is red.
    $owner = OA::admin();
    OA::member($owner);
    [$c, $csrf] = OA::enrol($this);
    $seen = fn () => (string) DB::table('owner_app_devices')->value('session_seen_at');

    $this->travel(5)->minutes();
    $before = $seen();
    OA::get($this, 'orders', $c, null, ['X-OA-Passive' => '1'])->assertOk();
    $this->flushHeaders()->withCredentials()->withUnencryptedCookies($c)->withHeaders(['X-OA' => '1', 'X-OA-CSRF' => $csrf, 'X-OA-Passive' => '1'])->getJson(OA::base().'/api/state')->assertJsonPath('stage', 'app');
    expect($seen())->toBe($before);

    OA::get($this, 'orders', $c)->assertOk();
    expect($seen())->not->toBe($before);

    $this->travel(5)->minutes();
    $before = $seen();
    $this->flushHeaders()->withCredentials()->withUnencryptedCookies($c)->withHeaders(['X-OA' => '1', 'X-OA-CSRF' => $csrf, 'X-OA-Passive' => '1'])
        ->postJson(OA::base().'/api/notify', ['groups' => ['orders']])->assertOk();
    expect($seen())->not->toBe($before);

    // And thirteen hours of passive refreshes still end at the PIN pad.
    for ($h = 0; $h < 13; $h++) {
        $this->travel(1)->hours();
        OA::get($this, 'orders', $c, null, ['X-OA-Passive' => '1']);
    }
    OA::get($this, 'orders', $c)->assertStatus(401)->assertJsonPath('code', 'locked');
});

/* ---------------------------------------------- 5. revoke without refusal */

it('refuses to sign out the phone of an account that outranks the admin doing it', function () {
    // DEFECT: revoke() skipped AdminRoles::refusal(), so anybody given
    // ownerapp.manage could sign the owner's phones out. MUTATION: delete the
    // refusal() block in OwnerAppAdminController::revoke().
    $owner = OA::admin();
    OA::member($owner);
    OA::enrol($this);
    $ownerDevice = (int) DB::table('owner_app_devices')->value('id');

    $roleId = (int) DB::table('admin_roles')->insertGetId(['slug' => 'oa-keeper', 'name' => 'App keeper', 'tier' => 'support', 'is_preset' => false,
        'capabilities' => json_encode(['admin.access', 'ownerapp.manage']), 'created_at' => now(), 'updated_at' => now()]);
    $keeper = OA::admin('support', 'keeper@example.com', 'Kay Keeper');
    $peer = OA::admin('support', 'peer@example.com', 'Pat Peer');
    AdminUser::query()->whereIn('id', [$keeper->id, $peer->id])->update(['role_id' => $roleId]);
    Cache::forget(AdminRoles::CACHE_KEY);
    AdminRoles::flush();
    $keeper->refresh();
    OA::member($peer);
    OA::enrol($this, 'peer@example.com');
    $peerDevice = (int) DB::table('owner_app_devices')->where('id', '!=', $ownerDevice)->value('id');

    $this->actingAs($keeper, 'admin')->postJson('/admin-api/owner-app/devices/'.$ownerDevice.'/revoke')->assertStatus(403)->assertJsonPath('error', 'outranks');
    expect(DB::table('owner_app_devices')->where('id', $ownerDevice)->value('revoked_at'))->toBeNull();

    $this->actingAs($keeper, 'admin')->postJson('/admin-api/owner-app/devices/'.$peerDevice.'/revoke')->assertOk();
    expect(DB::table('owner_app_devices')->where('id', $peerDevice)->value('revoked_reason'))->toBe('revoked_by_admin');

    // And the security settings are Full Admin only, whatever the role says.
    $this->actingAs($keeper, 'admin')->putJson('/admin-api/owner-app/security', ['host' => 'owner.example.test'])->assertStatus(403);
});

/* ----------------------------------------------- 6. 404s and 500s */

it('answers a miss under the secret address itself, never writing the address into the 404 log', function () {
    // DEFECT: /{secret}/anything fell through to the shop's 404 handler,
    // which wrote the live address into not_found_log — a screen every
    // manager reads — without the app's noindex/no-store headers.
    // MUTATION: remove the catch-all route and the first response has no
    // X-Robots-Tag; remove OwnerAppPath::covers() from NotFoundLogger and the
    // upper-case miss is logged.
    $path = OwnerAppPath::current();

    $this->get('/'.$path.'/no/such/screen')->assertNotFound()->assertJsonPath('code', 'not_found')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->assertHeader('Referrer-Policy', 'no-referrer');
    expect((string) $this->get('/'.$path.'/nothing')->headers->get('Cache-Control'))->toContain('no-store');
    $this->getJson('/'.$path.'/api/orders/not-a-number')->assertNotFound()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    $this->get('/'.strtoupper($path).'/x')->assertNotFound();
    $this->get('/'.str_replace('_', '%5F', $path).'/y')->assertNotFound();

    // Control: the logger does record an ordinary miss.
    $this->get('/a-page-that-never-was')->assertNotFound();

    expect(DB::table('not_found_log')->where('path', '/a-page-that-never-was')->count())->toBe(1)
        ->and(DB::table('not_found_log')->whereRaw('LOWER(path) LIKE ?', ['%'.substr($path, 0, 8).'%'])->count())->toBe(0);
});

it('sends noindex and no-store on a 500 under the secret address too', function () {
    $owner = OA::admin();
    OA::member($owner);
    [$c] = OA::enrol($this);
    $this->app->bind(LiveController::class, fn () => throw new RuntimeException('boom'));

    $r = OA::get($this, 'changes?after=1', $c)->assertStatus(500);
    expect($r->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow, noarchive')
        ->and((string) $r->headers->get('Cache-Control'))->toContain('no-store')
        ->and($r->headers->get('Referrer-Policy'))->toBe('no-referrer');
});

/* ------------------------------------------------------ optional items */

it('ends every app session of a member whose admin password changes', function () {
    // MUTATION: remove the AdminUser::updated hook in OwnerAppServiceProvider.
    $owner = OA::admin();
    OA::member($owner);
    [$c] = OA::enrol($this);
    OA::get($this, 'orders', $c)->assertOk();

    $owner->update(['name' => 'Rafi O.']);
    OA::get($this, 'orders', $c)->assertOk();

    $owner->update(['password' => 'a-brand-new-password']);
    OA::get($this, 'orders', $c)->assertStatus(401)->assertJsonPath('code', 'locked');
});

it('answers a nested array in the notification groups with 422, not a 500', function () {
    // DEFECT: array_intersect() on [['orders']] threw "Array to string
    // conversion". MUTATION: put back `(array) $request->input(...)`.
    $owner = OA::admin();
    OA::member($owner);
    [$c, $csrf] = OA::enrol($this);

    OA::post($this, 'notify', ['groups' => [['orders']]], $c, $csrf)->assertStatus(422);
    OA::post($this, 'notify', ['groups' => ['orders', ['x' => 1]]], $c, $csrf)->assertStatus(422);
    OA::post($this, 'notify', ['groups' => ['orders', 'nonsense']], $c, $csrf)->assertOk()->assertJsonPath('groups', ['orders']);

    $this->actingAs($owner, 'admin')->putJson('/admin-api/owner-app/members/'.$owner->id, ['notify' => [['a']]])->assertStatus(422);
    $this->actingAs($owner, 'admin')->putJson('/admin-api/owner-app/members/'.$owner->id, ['notify' => ['stock']])->assertOk();
});

/** RFC 8291, the receiving side (as OwnerAppLiveTest's), to read what was pushed. */
function oaSecDecrypt(string $body, \OpenSSLAsymmetricKey $uaPrivate, string $uaPublic, string $auth): string
{
    $salt = substr($body, 0, 16);
    $idlen = ord($body[20]);
    $asPublic = substr($body, 21, $idlen);
    $cipher = substr($body, 21 + $idlen);
    $shared = openssl_pkey_derive(WebPush::keyFromPoint($asPublic), $uaPrivate, 32);
    $prk = hash_hmac('sha256', $shared, $auth, true);
    $ikm = substr(hash_hmac('sha256', "WebPush: info\0".$uaPublic.$asPublic."\x01", $prk, true), 0, 32);
    $prk2 = hash_hmac('sha256', $ikm, $salt, true);
    $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk2, true), 0, 16);
    $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk2, true), 0, 12);
    $plain = (string) openssl_decrypt(substr($cipher, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($cipher, -16));

    return substr(rtrim($plain, "\0"), 0, -1);
}

it('keeps names and amounts off the lock screen when the text is set to Generic, on the server', function () {
    // MUTATION: pass `false` for $generic in OwnerAppEvents::deliver() and
    // the generic payload carries "Sabina" and the amount.
    $owner = OA::admin();
    OA::member($owner);
    [$c, $csrf] = OA::enrol($this);
    $ua = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $uaPublic = WebPush::publicPoint($ua);
    $auth = random_bytes(16);
    OA::post($this, 'push', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/sec-1', 'keys' => ['p256dh' => WebPush::b64u($uaPublic), 'auth' => WebPush::b64u($auth)]], $c, $csrf)->assertOk();

    $event = ['id' => 1, 'type' => 'order.new', 'ref' => 7, 'title' => 'New order #33001', 'body' => 'AED 161.00 · Sabina · Tabby'];
    $pushed = function () use ($event, $ua, $uaPublic, $auth) {
        Http::fake(['fcm.googleapis.com/*' => Http::response('', 201)]);
        OwnerAppEvents::deliver([$event]);
        $body = null;
        Http::assertSent(function (HttpRequest $r) use (&$body) {
            $body = $r->body();

            return true;
        });

        return json_decode(oaSecDecrypt((string) $body, $ua, $uaPublic, $auth), true);
    };

    expect($pushed())->toMatchArray(['t' => 'New order #33001', 'b' => 'AED 161.00 · Sabina · Tabby']);

    $this->actingAs($owner, 'admin')->putJson('/admin-api/owner-app/security', ['push_text' => 'loud'])->assertStatus(422);
    $this->actingAs($owner, 'admin')->putJson('/admin-api/owner-app/security', ['push_text' => 'generic'])->assertOk()->assertJsonPath('security.push_text', 'generic');

    $p = $pushed();
    expect($p['t'])->toBe('New order')->and($p['b'])->toBe('Open the app to see it.')
        ->and(json_encode($p, JSON_UNESCAPED_UNICODE))->not->toContain('Sabina')->not->toContain('161')->not->toContain('33001');
});

it('reads KBB_OWNER_APP_PATH through config, so config:cache keeps it', function () {
    // DEFECT: env() outside config/ is null once config is cached, and the
    // pinned address silently fell back to the settings row.
    // MUTATION: read env('KBB_OWNER_APP_PATH') in OwnerAppPath again.
    config(['owner_app.path' => 'pinned_from_env_x1']);
    OwnerAppPath::forgetMemo();

    expect(OwnerAppPath::current())->toBe('pinned_from_env_x1')->and(OwnerAppPath::isLockedByEnv())->toBeTrue();
    expect((string) file_get_contents(app_path('Services/OwnerApp/OwnerAppPath.php')))->not->toContain("env('KBB_OWNER_APP_PATH'");

    config(['owner_app.path' => '']);
    OwnerAppPath::forgetMemo();
});
