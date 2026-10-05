<?php

declare(strict_types=1);

/*
 * PIN guessing by PARALLEL requests (Lane SEC), proved two ways.
 *
 * THE DEFECT, as reviewed: OwnerAppAuth read the member's failed_count and
 * locked_until, ran bcrypt, and then wrote read-value + 1. Fifty guesses sent
 * at once all read 0, all ran bcrypt and all wrote 1 — fifty guesses that the
 * lockout counted as ONE, and the next fifty the same. The fix reserves the
 * attempt with one conditional UPDATE before bcrypt runs.
 *
 * A sequential test cannot see a race, so:
 *
 *   1. INTERLEAVING (every database): a hasher that, while "inside bcrypt",
 *      starts the next request — N requests all past their pre-check and in
 *      flight at once, deterministically. Only five may reach bcrypt.
 *   2. REAL PROCESSES (MySQL, -c phpunit-mysql.xml): sixteen PHP processes,
 *      released at the same microsecond against rows committed on a second
 *      connection, against a cost-11 hash (~150 ms of bcrypt each).
 *
 * MUTATION (run): drop `->where($count, '<', $max)` from
 * OwnerAppAuth::reserve() and 1. counts 10 checks instead of 5 (only the
 * device's own cap stops it), the enrol case 20 instead of 5, and 2. is red
 * on MySQL: all sixteen reach bcrypt together, ten pass the device cap and
 * the member is never locked (0 'bad_pin' rows, every one 'revoked').
 */

use App\Models\OwnerAppDevice;
use App\Services\OwnerApp\OwnerAppAuth;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\OwnerAppRoutes as OA;

beforeEach(function () {
    OA::wire($this->app);
});

/** A hasher that runs $during() the first $times it is asked to check, as if requests arrived mid-bcrypt. */
function oaReentrantHasher(Hasher $inner): Hasher
{
    return new class($inner) implements Hasher
    {
        public int $checks = 0;

        public int $depth = 0;

        public ?\Closure $during = null;

        /** @var list<string> */
        public array $seenAtCheck = [];

        public function __construct(private Hasher $inner) {}

        public function info($hashedValue)
        {
            return $this->inner->info($hashedValue);
        }

        public function make($value, array $options = [])
        {
            return $this->inner->make($value, $options);
        }

        public function check($value, $hashedValue, array $options = [])
        {
            $this->checks++;
            $this->seenAtCheck = array_map(fn ($q) => $q['query'], DB::getQueryLog());
            if ($this->during !== null) {
                ($this->during)();
            }

            return $this->inner->check($value, $hashedValue, $options);
        }

        public function needsRehash($hashedValue, array $options = [])
        {
            return $this->inner->needsRehash($hashedValue, $options);
        }
    };
}

it('lets only five of twenty guesses in flight at once reach bcrypt, and locks the member', function () {
    $owner = OA::admin();
    $memberId = OA::member($owner);
    OA::enrol($this);
    $deviceId = (int) DB::table('owner_app_devices')->value('id');

    $hasher = oaReentrantHasher(Hash::getFacadeRoot());
    Hash::swap($hasher);

    // Request 1 enters bcrypt; while it is there request 2 arrives, enters
    // bcrypt, and so on; a request refused before bcrypt hands straight on to
    // the next. All twenty are past their pre-check before ANY of them has
    // finished a hash — the shape fifty parallel guesses have on a server.
    $statuses = [];
    $fire = function () use (&$statuses, $hasher, $deviceId) {
        $hasher->depth++;
        $request = Request::create('/x', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.'.$hasher->depth]);
        $device = OwnerAppDevice::query()->with('member.admin')->findOrFail($deviceId);
        $statuses[] = OwnerAppAuth::unlock($request, $device, '135790')['status'];
    };
    $hasher->during = function () use ($fire, $hasher) {
        while ($hasher->depth < 20) {
            $fire();
        }
    };
    $fire();

    $m = DB::table('owner_app_members')->where('id', $memberId)->first();

    expect($hasher->checks)->toBe(OwnerAppAuth::MEMBER_LOCK_AFTER)
        ->and((int) $m->lock_level)->toBe(1)
        ->and(now()->lt($m->locked_until))->toBeTrue()
        ->and((int) DB::table('owner_app_devices')->where('id', $deviceId)->value('failed_count'))->toBe(OwnerAppAuth::MEMBER_LOCK_AFTER)
        ->and(count($statuses))->toBe(20)
        ->and(count(array_filter($statuses, fn ($s) => $s === 423)))->toBe(20);
});

it('reserves the attempt with a conditional UPDATE before the PIN is ever hashed', function () {
    // The query SHAPE is the atomicity: one statement that both tests and
    // increments, so the database serialises it. Read-then-write in PHP is
    // the defect, whatever the order.
    $owner = OA::admin();
    OA::member($owner);
    OA::enrol($this);
    $device = OwnerAppDevice::query()->with('member.admin')->firstOrFail();

    $hasher = oaReentrantHasher(Hash::getFacadeRoot());
    Hash::swap($hasher);
    DB::flushQueryLog();
    DB::enableQueryLog();

    OwnerAppAuth::unlock(Request::create('/x', 'POST'), $device, '135790');

    $before = implode("\n", $hasher->seenAtCheck);
    expect($before)->toMatch('/update [`"]owner_app_members[`"] set [`"]failed_count[`"] = [`"]failed_count[`"] \+ 1.*where [`"]id[`"] = \? and [`"]lock_level[`"] < \? and [`"]failed_count[`"] < \? and \([`"]locked_until[`"] is null or [`"]locked_until[`"] <= \?\)/')
        ->and($before)->toMatch('/update [`"]owner_app_devices[`"] set [`"]failed_count[`"] = [`"]failed_count[`"] \+ 1.*where [`"]id[`"] = \? and [`"]revoked_at[`"] is null and [`"]failed_count[`"] < \?/')
        ->and($before)->toMatch('/update [`"]owner_app_throttle[`"] set [`"]hits[`"] = [`"]hits[`"] \+ 1.*where [`"]bucket[`"] = \? and [`"]hits[`"] < \?/');
});

it('lets only five of twenty parallel sign-in guesses reach bcrypt, counted on the enrol ladder alone', function () {
    $owner = OA::admin();
    $memberId = OA::member($owner);

    $hasher = oaReentrantHasher(Hash::getFacadeRoot());
    Hash::swap($hasher);
    $fire = function () use ($hasher) {
        $hasher->depth++;
        // Each from its own address: this is the per-member ladder, not the per-connection one.
        OwnerAppAuth::enrol(Request::create('/x', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.'.$hasher->depth]), 'owner@example.com', '135790', '');
    };
    $hasher->during = function () use ($fire, $hasher) {
        while ($hasher->depth < 20) {
            $fire();
        }
    };
    $fire();

    $m = DB::table('owner_app_members')->where('id', $memberId)->first();
    $badPin = DB::table('owner_app_logins')->where('member_id', $memberId)->where('reason', 'bad_pin')->count();

    expect($badPin)->toBe(OwnerAppAuth::ENROL_LOCK_AFTER)
        ->and((int) $m->enrol_lock_level)->toBe(1)
        ->and((int) $m->lock_level)->toBe(0)
        ->and((int) $m->failed_count)->toBe(0)
        ->and($m->locked_until)->toBeNull();
});

it('holds against sixteen real processes guessing at the same instant (MySQL)', function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Runs under -c phpunit-mysql.xml: SQLite serialises writers at the file, MySQL is where rows race.');
    }

    // Rows on a SECOND connection, committed, so the child processes can see
    // them through RefreshDatabase's open transaction — and removed after.
    config(['database.connections.oa_race' => config('database.connections.mysql')]);
    $race = DB::connection('oa_race');
    $email = 'race-'.bin2hex(random_bytes(4)).'@example.com';
    $adminId = (int) $race->table('admin_users')->insertGetId(['name' => 'Race Owner', 'email' => $email, 'password' => 'x', 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
    $memberId = (int) $race->table('owner_app_members')->insertGetId(['admin_user_id' => $adminId, 'enabled' => true,
        'pin_hash' => password_hash('482613', PASSWORD_BCRYPT, ['cost' => 11]), 'pin_length' => 6, 'pin_set_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $deviceId = (int) $race->table('owner_app_devices')->insertGetId(['member_id' => $memberId, 'token_hash' => hash('sha256', bin2hex(random_bytes(16))),
        'name' => 'Race phone', 'created_at' => now(), 'updated_at' => now()]);

    try {
        $c = config('database.connections.mysql');
        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'), 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => (string) $c['host'],
            'DB_PORT' => (string) $c['port'], 'DB_DATABASE' => (string) $c['database'], 'DB_USERNAME' => (string) $c['username'],
            'DB_PASSWORD' => (string) $c['password'],
        ]);
        $n = 16;
        $startAt = microtime(true) + 6.0;
        $procs = [];
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $procs[$i] = proc_open([PHP_BINARY, base_path('tests/Support/oa-race-child.php'), 'unlock', (string) $deviceId, '135790', sprintf('%.6F', $startAt)],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            $out[$i] = $pipes;
        }
        $results = [];
        foreach ($procs as $i => $p) {
            $results[] = json_decode(trim((string) stream_get_contents($out[$i][1])), true);
            $err = (string) stream_get_contents($out[$i][2]);
            proc_close($p);
            expect($err)->toBe('');
        }

        expect(collect($results)->where('late', true)->count())->toBe(0, 'a child booted after the start instant; raise the head start')
            ->and(collect($results)->pluck('status')->filter()->count())->toBe($n);

        $m = $race->table('owner_app_members')->where('id', $memberId)->first();
        $badPin = $race->table('owner_app_logins')->where('member_id', $memberId)->where('reason', 'bad_pin')->count();

        expect($badPin)->toBe(OwnerAppAuth::MEMBER_LOCK_AFTER)
            ->and((int) $m->lock_level)->toBe(1)
            ->and($m->locked_until)->not->toBeNull()
            ->and((int) $race->table('owner_app_devices')->where('id', $deviceId)->value('failed_count'))->toBe(OwnerAppAuth::MEMBER_LOCK_AFTER);
    } finally {
        $race->table('owner_app_logins')->where('member_id', $memberId)->delete();
        $race->table('owner_app_throttle')->where('bucket', 'like', 'pin|198.51.100.%')->delete();
        $race->table('owner_app_devices')->where('id', $deviceId)->delete();
        $race->table('owner_app_members')->where('id', $memberId)->delete();
        $race->table('admin_users')->where('id', $adminId)->delete();
        $race->disconnect();
    }
});
