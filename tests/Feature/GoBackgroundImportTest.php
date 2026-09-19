<?php

declare(strict_types=1);

/**
 * The import that keeps going with the tab closed — Lane GO.
 *
 * > "import also batch by batch. with real progress and with have pause or stop
 * >  button. i want this job must be running in background, even i close the tab."
 *
 * Three of those four clauses already shipped and this lane changed none of
 * them. What is under test here is the fourth, and the things that can go
 * catastrophically wrong once a browser is no longer the thing driving:
 *
 *   1. EXACTLY ONE CHAIN. Two chains alternating slices means every row read
 *      twice and, in the worst arrangement, a checkpoint advanced past work
 *      nobody did. The baton is one conditional UPDATE and this file attacks it
 *      from every side: forged, replayed, expired, concurrent, superseded.
 *
 *   2. STOP MEANS STOP, and so does Pause. A chain already in flight must not
 *      continue past either, and resuming must land on the row AFTER the last
 *      committed one rather than redoing it.
 *
 *   3. A RUNAWAY IS WORSE THAN A STALL. Every bound is tested by tripping it,
 *      and a chain that cannot continue must SAY it has stopped rather than
 *      leaving a bar frozen at a number that will never move.
 *
 *   4. CONTINUING IS AUTHORISED WITHOUT A SESSION, and that authority is not
 *      guessable, not replayable, and never in a URL.
 *
 *   5. THE BROWSER-DRIVEN RUN IS UNTOUCHED. Background is an addition. A
 *      regression here would take away the thing that works everywhere in
 *      exchange for the thing that works on most hosts.
 *
 *   6. THE HOST MAY REFUSE ALL OF IT, and then the screen must say so in words
 *      the owner can act on rather than silently doing nothing.
 */

use App\Models\AdminUser;
use App\Services\ImportConsole\ImportChain;
use App\Services\ImportConsole\ImportDriver;
use App\Services\ImportConsole\ImportWorkspace;
use App\Support\AdminCapabilities;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ImportAdminRoutes;
use Tests\Support\ImportBackgroundRoutes;

/* ------------------------------------------------------------------ set-up */

beforeEach(function () {
    ImportAdminRoutes::wire($this->app);
    ImportBackgroundRoutes::wire($this->app);

    goPurge(storage_path('app/import'));

    // Nothing in this file wants a real outbound request. The tests that care
    // what was sent install their own fake over the top.
    goFake([
        '*' => Http::response('', 204),
    ]);
});

afterEach(function () {
    goPurge(storage_path('app/import'));
});

/**
 * Install an outbound stub, starting from a CLEAN client factory.
 *
 * Factory::fake() MERGES: a second stub is appended behind the first and the
 * first one that matches wins. So a test that "overrides" the default stub set
 * in beforeEach() would silently keep getting the default, its own closure
 * would never run, and it would pass or fail for a reason that has nothing to
 * do with what it says it is testing. Swapping the factory is what makes the
 * override an override.
 */
function goFake($stub): void
{
    Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
    Http::fake($stub);
}

function goPurge(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir.'/'.$entry;

        is_dir($path) ? goPurge($path) : @unlink($path);
    }

    @rmdir($dir);
}

function goAdmin(): AdminUser
{
    return AdminUser::create([
        'name' => 'Background Owner',
        'email' => 'go-owner-'.uniqid().'@example.test',
        'password' => 'secret-secret',
        'role' => 'owner',
    ]);
}

/** A file with content this test wrote, uploaded under whatever name it likes. */
function goFile(string $name, string $body): UploadedFile
{
    $temp = sys_get_temp_dir().'/kbb-go-'.bin2hex(random_bytes(6));
    file_put_contents($temp, $body);

    return new UploadedFile($temp, $name, 'text/csv', null, true);
}

/**
 * A brands export with $n rows, so a slice can be small enough to leave the
 * file part-done and the resume point can be checked against a known number.
 */
function goBrands(int $n): UploadedFile
{
    $rows = ["term_id,name,slug,description,position"];

    for ($i = 1; $i <= $n; $i++) {
        $rows[] = (7000 + $i).',Brand '.$i.',go-brand-'.$i.',,'.$i;
    }

    return goFile('brands.csv', implode("\n", $rows)."\n");
}

/** Put one known export on the server and start a live run. */
function goStartRun(int $brands = 40): void
{
    test()->postJson('/admin-api/import/upload', ['file' => goBrands($brands)])
        ->assertOk()->assertJsonPath('ok', true);

    test()->postJson('/admin-api/import/start', ['mode' => 'live', 'force' => true])
        ->assertOk()->assertJsonPath('ok', true);
}

function goRunRow(): ?object
{
    return DB::table(ImportDriver::TABLE)->where('run_key', ImportDriver::RUN_KEY)->first();
}

function goSetRun(array $fields): void
{
    DB::table(ImportDriver::TABLE)->where('run_key', ImportDriver::RUN_KEY)->update($fields);
}

/**
 * Put a baton of our own choosing on the run and hand back the secret.
 *
 * Used rather than begin() wherever the test is about what the CLAIM does,
 * because begin() also fires a kick and a test about the claim should not be
 * able to pass or fail on the kick.
 */
function goIssue(int $ttl = 600): string
{
    $secret = bin2hex(random_bytes(32));

    goSetRun([
        'background' => true,
        'paused' => false,
        'chain_token' => hash('sha256', $secret),
        'chain_expires_at' => now()->addSeconds($ttl),
        'chain_started_at' => now(),
        'chain_beat_at' => now(),
        'chain_rows' => 5,
    ]);

    return $secret;
}

/** Present a baton at the public endpoint exactly as the server does. */
function goContinue(string $secret)
{
    return test()->withHeaders([ImportChain::HEADER => $secret])
        ->post('/import-chain/continue');
}

function goChain(): ImportChain
{
    return new ImportChain;
}

/**
 * Drive a real relay, one link at a time, in this process.
 *
 * The loopback call is faked to CAPTURE the baton rather than to re-enter the
 * application, and the loop then presents it at the real endpoint. That is the
 * same sequence the server performs — claim, slice, hand on — with the one
 * difference that matters for a test: the links do not nest, so a run of forty
 * slices is forty flat requests rather than a forty-deep stack, and each one's
 * response, database state and refusal can be asserted on its own.
 *
 * The genuinely out-of-process proof is the rehearsal in
 * docs/GO-BACKGROUND-IMPORT.md, against a running server with the driving
 * connection closed. This is the part of it the suite can own.
 *
 * @return array{links: int, last: int}
 */
function goRelay(int $max = 60): array
{
    $pending = null;

    goFake(function ($request) use (&$pending) {
        $header = $request->header(ImportChain::HEADER);
        $pending = is_array($header) ? ($header[0] ?? null) : $header;

        return Http::response('', 204);
    });

    goChain()->begin();

    $links = 0;
    $last = 204;

    while ($pending !== null && $links < $max) {
        $secret = $pending;
        $pending = null;

        $last = goContinue($secret)->status();

        if ($last !== 204) {
            break;
        }

        $links++;
    }

    return ['links' => $links, 'last' => $last];
}

/* ====================================================================== */
/*  1 · WHERE THE ROUTES MOUNT, AND WHAT GUARDS THEM                       */
/* ====================================================================== */

it('refuses every admin endpoint of this lane to a caller with no session', function () {
    foreach ([
        ['get', '/admin-api/import/background'],
        ['get', '/admin-api/import/background-page'],
        ['post', '/admin-api/import/background'],
        ['post', '/admin-api/import/background-control'],
    ] as [$verb, $path]) {
        $response = $verb === 'get'
            ? test()->getJson($path)
            : test()->postJson($path, ['action' => 'pause']);

        expect($response->status())->toBeIn([401, 403, 302], $verb.' '.$path.' let an anonymous caller in');
    }
});

it('maps every admin endpoint to data.import and never to the closed default', function () {
    /*
     * Asked of AdminCapabilities::forPath() rather than read out of the RULES
     * table, because a rule shadowed by an earlier wildcard is dead text and
     * reading the table cannot see that. The point of this test is that NO NEW
     * RULE WAS ADDED: if a future tidy-up narrows `admin-api/import/**`, these
     * endpoints fall through to the closed owner-only default and start 403ing
     * on a host with no shell. That must fail here instead.
     */
    foreach (ImportBackgroundRoutes::adminRoutes() as $route) {
        $capability = AdminCapabilities::forPath(
            collect($route->methods())->first(fn ($m) => ! in_array($m, ['HEAD', 'OPTIONS'], true)) ?? 'GET',
            $route->uri()
        );

        expect($capability)->toBe('data.import', $route->uri().' does not map to data.import');
    }
});

it('gives the admin endpoints the whole admin-api stack, not a subset of it', function () {
    foreach (ImportBackgroundRoutes::adminRoutes() as $route) {
        $middleware = $route->gatherMiddleware();

        foreach (ImportBackgroundRoutes::STACK as $expected) {
            expect(in_array($expected, $middleware, true))
                ->toBeTrue($route->uri().' is missing '.$expected);
        }
    }
});

it('excludes CSRF from the loopback route and nothing else', function () {
    $route = ImportBackgroundRoutes::chainRoute();

    expect($route)->not->toBeNull();

    $excluded = $route->excludedMiddleware();

    expect($excluded)->toBe([ImportBackgroundRoutes::CSRF]);

    // And `web` is still on it, so the exclusion is a real exclusion from a
    // real stack rather than a stack that never had it.
    expect(in_array('web', $route->middleware(), true))->toBeTrue();
});

it('throttles the loopback route and answers only POST', function () {
    $route = ImportBackgroundRoutes::chainRoute();

    $throttles = array_values(array_filter(
        $route->gatherMiddleware(),
        fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')
    ));

    expect($throttles)->not->toBeEmpty();

    $verbs = array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS']));

    expect($verbs)->toBe(['POST']);
});

it('carries no auth:admin on the loopback route, so the capability map steps aside', function () {
    /*
     * EnforceAdminCapability acts on a route that DECLARES auth:admin and steps
     * aside for everything else. The loopback route maps to no capability at
     * all — forPath() returns null, which is the closed answer — so if it ever
     * acquired auth:admin it would 403 every call and the background run would
     * die on a host where everything else worked.
     */
    $route = ImportBackgroundRoutes::chainRoute();

    $auth = array_values(array_filter(
        $route->gatherMiddleware(),
        fn ($m) => is_string($m) && str_starts_with($m, 'auth:')
    ));

    expect($auth)->toBe([]);
    expect(AdminCapabilities::forPath('POST', ImportBackgroundRoutes::CHAIN_URI))->toBeNull();
});

it('names its own filename in the require line each route file tells the integrator to add', function () {
    /*
     * A route file must not lie about where it goes. Both of this lane's files
     * carry a require line for the integrator to paste; a copy-paste slip that
     * named a sibling file would be a package that mounts the wrong thing, and
     * nothing else in the suite would notice.
     */
    foreach (['import-background-admin.php', 'import-chain.php'] as $file) {
        $body = (string) file_get_contents(base_path('routes/'.$file));

        expect(substr_count($body, "require __DIR__.'/".$file."';"))->toBe(1, $file);
    }
});

/* ====================================================================== */
/*  2 · THE BATON                                                          */
/* ====================================================================== */

it('does nothing at all for a forged baton', function () {
    $this->actingAs(goAdmin(), 'admin');
    goStartRun();
    goIssue();

    $before = (int) (goRunRow()->chain_slices ?? 0);

    goContinue(bin2hex(random_bytes(32)))->assertStatus(404)->assertSee('', false);

    expect((int) goRunRow()->chain_slices)->toBe($before);
});

it('works once and never again for the same baton', function () {
    $this->actingAs(goAdmin(), 'admin');
    goStartRun();
    $secret = goIssue();

    goContinue($secret)->assertStatus(204);

    // Replay. Identical request, identical header, and it must do nothing.
    $slices = (int) goRunRow()->chain_slices;

    goContinue($secret)->assertStatus(404);

    expect((int) goRunRow()->chain_slices)->toBe($slices);
});

it('refuses a baton whose time is up', function () {
    $this->actingAs(goAdmin(), 'admin');
    goStartRun();
    $secret = goIssue();

    goSetRun(['chain_expires_at' => now()->subSecond()]);

    goContinue($secret)->assertStatus(404);
});

it('stores the digest of the baton and never the baton', function () {
    $this->actingAs(goAdmin(), 'admin');
    goStartRun();
    $secret = goIssue();

    $stored = (string) goRunRow()->chain_token;

    expect($stored)->toBe(hash('sha256', $secret));
    expect(str_contains($stored, $secret))->toBeFalse();
    expect(strlen($stored))->toBe(64);
});

it('replaces the baton in the same statement that accepts it', function () {
    $this->actingAs(goAdmin(), 'admin');
    goStartRun();
    $secret = goIssue();

    $before = (string) goRunRow()->chain_token;

    $next = goChain()->claim($secret);

    expect($next)->toBeString();
    expect((string) goRunRow()->chain_token)->not->toBe($before);
    expect((string) goRunRow()->chain_token)->toBe(hash('sha256', (string) $next));
});

it('hands the baton to exactly one of two callers presenting it at once', function () {
    $this->actingAs(goAdmin(), 'admin');
    goStartRun();
    $secret = goIssue();

    /*
     * Not threads — the suite cannot make two requests collide to the
     * microsecond, and a test that tried would be flaky rather than strict.
     * What IS tested is the property that makes a collision safe: the claim is
     * one conditional UPDATE, so the second caller matches zero rows however
     * close behind it arrives.
     */
    $first = goChain()->claim($secret);
    $second = goChain()->claim($secret);

    expect($first)->toBeString();
    expect($second)->toBeNull();
    expect((int) goRunRow()->chain_slices)->toBe(1);
});

it('never puts the baton in a URL', function () {
    $this->actingAs(goAdmin(), 'admin');
    goStartRun();

    $seen = [];

    goFake(function ($request) use (&$seen) {
        $seen[] = $request;

        return Http::response('', 204);
    });

    $result = goChain()->begin();

    expect($result['ok'])->toBeTrue($result['message']);
    expect($seen)->toHaveCount(1);

    $request = $seen[0];
    $url = $request->url();

    // The header carries it.
    expect($request->header(ImportChain::HEADER))->not->toBeEmpty();
    $secret = $request->header(ImportChain::HEADER)[0];

    // And nothing else does. str_contains, not toContain — CLAUDE.md: toContain
    // is variadic and a ->not->toContain($needle, $message) passes vacuously.
    expect(str_contains($url, $secret))->toBeFalse('the baton was in the URL');
    expect(str_contains($url, '?'))->toBeFalse('the loopback URL carries a query string');
    expect(str_contains((string) $request->body(), $secret))->toBeFalse('the baton was in the body');
});

it('refuses to follow a redirect while holding the baton', function () {
    /*
     * Guzzle follows redirects by default and carries custom headers across the
     * hop, so a host that 301s to another name would forward the secret to it.
     */
    $this->actingAs(goAdmin(), 'admin');
    goStartRun();

    $options = null;

    goFake(function ($request, $opts) use (&$options) {
        $options = $opts;

        return Http::response('', 204);
    });

    goChain()->begin();

    expect($options['allow_redirects'] ?? null)->toBeFalse();
});

it('ignores the request body entirely', function () {
    $this->actingAs(goAdmin(), 'admin');
    goStartRun(6);
    $secret = goIssue();

    test()->withHeaders([ImportChain::HEADER => $secret])
        ->post('/import-chain/continue', [
            'rows' => 999999,
            'mode' => 'preview',
            'entity' => 'orders',
            'restart' => true,
        ])
        ->assertStatus(204);

    $run = goRunRow();

    // Nothing the body asked for happened: the mode is the one the admin chose,
    // and the slice size is the one on the run.
    expect((string) $run->mode)->toBe('live');
    expect((int) $run->chain_rows)->toBeLessThan(999999);
});

it('will not take the baton from anywhere but the header', function () {
    /*
     * The header is the whole of the guarantee that the secret never reaches an
     * access log, a proxy log or a Referer. An endpoint that ALSO accepted it in
     * the body or the query would make that guarantee a convention instead of a
     * property — and a well-meaning future caller would put it in a URL.
     */
    $this->actingAs(goAdmin(), 'admin');
    goStartRun();
    $secret = goIssue();

    $before = (int) goRunRow()->chain_slices;

    // The real secret, in every place except the one that counts.
    test()->post('/import-chain/continue?baton='.$secret, ['baton' => $secret, 'token' => $secret, 'secret' => $secret])
        ->assertStatus(404);

    expect((int) goRunRow()->chain_slices)->toBe($before, 'the baton was accepted out of the request');

    // And it still works from the header, so the test is not passing because
    // the baton was never good.
    goContinue($secret)->assertStatus(204);
});

it('answers before it does any of the work', function () {
    /*
     * THE PROPERTY THAT MAKES THE CHAIN A RELAY AND NOT A STACK. If the slice
     * ran before the response, every link would hold its caller open and a long
     * import would be a deep nest of live PHP workers — on shared hosting, an
     * exhausted pool inside a minute.
     *
     * Asserted by driving the kernel by hand, because the ordinary test helper
     * calls terminate() for you and would hide the very gap being tested:
     * handle() produces the response, and only terminate() runs the deferred
     * callback.
     */
    $this->actingAs(goAdmin(), 'admin');
    goStartRun(20);
    $secret = goIssue();

    $kernel = app(\Illuminate\Contracts\Http\Kernel::class);

    $request = \Illuminate\Http\Request::create(
        '/import-chain/continue', 'POST', [], [], [], ['HTTP_'.str_replace('-', '_', strtoupper(ImportChain::HEADER)) => $secret]
    );

    $response = $kernel->handle($request);

    expect($response->getStatusCode())->toBe(204);

    // The baton has been taken — the caller has been told the relay was picked
    // up — and not one row has been imported yet.
    expect((int) goRunRow()->chain_slices)->toBe(1);
    expect(DB::table('import_checkpoints')->where('run_key', ImportDriver::RUN_KEY)->count())->toBe(0);

    $kernel->terminate($request, $response);

    // Only now.
    expect((int) DB::table('import_checkpoints')
        ->where('run_key', ImportDriver::RUN_KEY)->where('entity', 'brands')->value('processed'))->toBeGreaterThan(0);
});

it('answers with an empty body whichever way it goes', function () {
    $this->actingAs(goAdmin(), 'admin');
    goStartRun();
    $secret = goIssue();

    $refused = goContinue(bin2hex(random_bytes(32)));
    $accepted = goContinue($secret);

    expect($refused->status())->toBe(404);
    expect($refused->getContent())->toBe('');
    expect($accepted->status())->toBe(204);
    expect($accepted->getContent())->toBe('');

    // A 404 that carried a distinguishing header would be the same oracle in a
    // different place.
    expect($refused->headers->get('X-KBB-Import-Chain'))->toBeNull();
});

/* ====================================================================== */
/*  3 · EXACTLY ONE CHAIN                                                  */
/* ====================================================================== */

it('kills the chain in flight when a second one is started', function () {
    $this->actingAs(goAdmin(), 'admin');
    goStartRun();

    $first = goIssue();

    // A second start rotates the baton.
    goChain()->begin();

    goContinue($first)->assertStatus(404);
});

it('does not hand the baton on when it has been superseded mid-slice', function () {
    $this->actingAs(goAdmin(), 'admin');
    goStartRun(8);

    $secret = goIssue();
    $next = goChain()->claim($secret);

    // While "the slice runs", somebody starts a fresh chain.
    $kicks = 0;

    goFake(function () use (&$kicks) {
        $kicks++;

        return Http::response('', 204);
    });

    goSetRun(['chain_token' => hash('sha256', bin2hex(random_bytes(32)))]);

    goChain()->advance((string) $next);

    expect($kicks)->toBe(0, 'a superseded chain handed its stale baton on');
});

it('imports each row once when two chains are set going deliberately', function () {
    $this->actingAs(goAdmin(), 'admin');
    goStartRun(30);

    /*
     * The failure this is about: two chains alternating slices would both read
     * the same checkpoint, both import the same rows, and both advance it — so
     * the offset ends up past work nobody did and the totals still look right.
     *
     * A baton is issued and held, as a chain already in flight would hold it.
     * Then a second chain is started on top of it and run to the end. The held
     * baton is presented at every single link along the way.
     */
    $stale = goIssue();

    $pending = null;

    goFake(function ($request) use (&$pending) {
        $header = $request->header(ImportChain::HEADER);
        $pending = is_array($header) ? ($header[0] ?? null) : $header;

        return Http::response('', 204);
    });

    goChain()->begin();

    $staleAccepted = 0;
    $links = 0;

    while ($pending !== null && $links < 60) {
        $secret = $pending;
        $pending = null;

        // The other chain, still holding its baton, tries at every step.
        if (goContinue($stale)->status() === 204) {
            $staleAccepted++;
        }

        if (goContinue($secret)->status() !== 204) {
            break;
        }

        $links++;

        if (goRunRow()->status !== 'running') {
            break;
        }
    }

    expect($staleAccepted)->toBe(0, 'the second chain imported alongside the first');
    expect($links)->toBeGreaterThan(1);

    $slugs = DB::table('brands')->where('slug', 'like', 'go-brand-%')->pluck('slug');

    expect($slugs->count())->toBe($slugs->unique()->count(), 'a brand was imported twice');
    expect($slugs->count())->toBe(30, 'rows were skipped: the checkpoint ran ahead of the work');
});

it('finishes the whole import with nothing driving it but the server', function () {
    $this->actingAs(goAdmin(), 'admin');
    goStartRun(30);

    // Small slices, so the run genuinely takes several links rather than one.
    goSetRun(['chain_rows' => 4]);

    $relay = goRelay();

    expect($relay['links'])->toBeGreaterThan(2);
    expect((string) goRunRow()->status)->toBe('complete');
    expect(DB::table('brands')->where('slug', 'like', 'go-brand-%')->count())->toBe(30);

    // And when there is nothing left, the chain reports that it is over rather
    // than carrying on.
    expect(goChain()->state()['state'])->toBe('finished');
});

/* ====================================================================== */
/*  4 · STOP MEANS STOP, AND SO DOES PAUSE                                 */
/* ====================================================================== */

it('refuses to continue a run that was stopped', function () {
    $this->actingAs(goAdmin(), 'admin');
    goStartRun();
    $secret = goIssue();

    test()->postJson('/admin-api/import/stop')->assertOk();

    goContinue($secret)->assertStatus(404);
});

it('does not let a chain already in flight take another slice after Stop', function () {
    $this->actingAs(goAdmin(), 'admin');
    goStartRun(30);

    $secret = goIssue();
    $next = goChain()->claim($secret);

    $kicks = 0;

    goFake(function () use (&$kicks) {
        $kicks++;

        return Http::response('', 204);
    });

    // The owner presses Stop while the slice is running.
    test()->postJson('/admin-api/import/background-control', ['action' => 'stop'])->assertOk();

    goChain()->advance((string) $next);

    expect($kicks)->toBe(0, 'the chain kept going after Stop');
    expect(goRunRow()->chain_token)->toBeNull();
});

it('refuses a baton on a paused run even when the baton itself is still valid', function () {
    /*
     * ISOLATING ONE GUARD FROM THE OTHER. pause() does two things — it sets the
     * flag AND it drops the baton — so the ordinary pause test passes even with
     * `paused = 0` deleted from the claim, because the token is gone anyway.
     * That is two guards where one is dead text, and the mutation that removed
     * the flag stayed green until this test existed.
     *
     * Here the flag is set and the baton is deliberately LEFT IN PLACE, so the
     * only thing that can refuse the call is the clause in the claim.
     */
    $this->actingAs(goAdmin(), 'admin');
    goStartRun();
    $secret = goIssue();

    goSetRun(['paused' => true]);

    expect(goRunRow()->chain_token)->not->toBeNull('the baton was cleared, so this tests nothing');

    goContinue($secret)->assertStatus(404);

    expect((int) goRunRow()->chain_slices)->toBe(0);
});

it('refuses a baton on a run nobody asked to carry on in the background', function () {
    /*
     * The same isolation for `background = 1`. A run being driven from a browser
     * tab has no business being advanced by a loopback call, and the only thing
     * that says so is this clause of the claim — every other path that would
     * refuse it also clears the baton.
     */
    $this->actingAs(goAdmin(), 'admin');
    goStartRun();
    $secret = goIssue();

    goSetRun(['background' => false]);

    expect(goRunRow()->chain_token)->not->toBeNull('the baton was cleared, so this tests nothing');

    goContinue($secret)->assertStatus(404);

    expect((int) goRunRow()->chain_slices)->toBe(0);
});

it('stops a chain in flight when Stop is pressed on the console, which leaves the baton alone', function () {
    /*
     * The console's own Stop button — ImportApiController::stop(), untouched by
     * this lane — sets `status = 'stopped'` and does NOT know about the chain,
     * so the baton is still sitting in the table afterwards. The only thing
     * that ends the chain here is advance()'s re-read of the run's status.
     *
     * The background page's Stop additionally halts the chain, which is why the
     * OTHER stop test cannot exercise this: there, the "have I been superseded"
     * check fires first and the status re-check is never reached. Two guards,
     * and this is the test that makes the second one earn its place.
     */
    $this->actingAs(goAdmin(), 'admin');
    goStartRun(30);

    $secret = goIssue();
    $next = goChain()->claim($secret);

    $kicks = 0;

    goFake(function () use (&$kicks) {
        $kicks++;

        return Http::response('', 204);
    });

    test()->postJson('/admin-api/import/stop')->assertOk();

    expect(goRunRow()->chain_token)->not->toBeNull('the console\'s Stop cleared the baton, so this tests nothing');

    goChain()->advance((string) $next);

    expect($kicks)->toBe(0, 'the chain took another slice after the console\'s Stop');
    expect(goChain()->state()['state'])->toBe('finished');
});

it('does not blame the host when the kick was refused because Stop had just been pressed', function () {
    /*
     * FOUND IN THE REHEARSAL, not by reasoning. Stop was pressed during a real
     * background run, in the window between a process deciding to kick and the
     * far end answering. The far end refused the baton — correctly, the run had
     * just been stopped — and that 404 was reported as "this host will not let
     * the shop call itself", overwriting the true note. Everything BEHAVED
     * correctly and the screen accused the owner's hosting of a fault he had
     * just caused himself, pointing him at the one remedy that could not help.
     */
    $this->actingAs(goAdmin(), 'admin');
    goStartRun(30);

    $secret = goIssue();
    $next = goChain()->claim($secret);

    // The kick goes out, and while it is in the air the run is stopped from the
    // screen — which writes the true note and drops the baton.
    goFake(function () {
        test()->postJson('/admin-api/import/background-control', ['action' => 'stop']);

        return Http::response('', 404);
    });

    goChain()->advance((string) $next);

    $state = goChain()->state();

    expect($state['state'])->toBe('finished');
    expect(str_contains((string) goRunRow()->chain_note, 'will not let the shop call itself'))
        ->toBeFalse('the host was blamed for a Stop the owner pressed: '.goRunRow()->chain_note);
    expect((string) goRunRow()->chain_note)->toBe('stopped from the progress page');
});

it('still blames the host when the kick really did fail and nothing else changed', function () {
    // The other side of the same guard: a genuine loopback failure must still
    // be reported, or the sentence the owner needs would never appear.
    $this->actingAs(goAdmin(), 'admin');
    goStartRun(30);

    $secret = goIssue();
    $next = goChain()->claim($secret);

    goFake(function () {
        throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
    });

    goChain()->advance((string) $next);

    expect(goChain()->state()['state'])->toBe('halted');
    expect(str_contains((string) goRunRow()->chain_note, 'will not let the shop call itself'))->toBeTrue();
});

it('does not let a slice finishing after Stop put the run back to running', function () {
    /*
     * FOUND IN THE REHEARSAL, and it is a pre-existing race in ImportDriver
     * rather than in anything this lane wrote. The row liveStep() writes at the
     * END of a slice carried `status => 'running'` unconditionally, so a Stop
     * pressed DURING that slice was set straight back to running when it
     * finished — and the run the owner had just stopped was, on the screen and
     * in the database, going again.
     *
     * Survivable in the browser-driven run only because the console's loop also
     * stops in the tab. Not survivable in a run with no tab attached.
     *
     * THE STOP HAS TO LAND MID-SLICE, and getting that wrong is how the first
     * version of this test passed against the bug: stopping BEFORE the call
     * makes step() refuse at its own front door, liveStep() never runs, and the
     * line under test is never reached. The mutation that removed the fix
     * stayed GREEN until this was rewritten. So the stop is fired from a query
     * listener on the importer's own first INSERT — genuinely between step()
     * starting and its bookkeeping landing.
     */
    $this->actingAs(goAdmin(), 'admin');
    goStartRun(30);

    $stopped = false;

    DB::listen(function ($query) use (&$stopped) {
        if ($stopped || ! str_contains(strtolower($query->sql), 'insert into')) {
            return;
        }

        if (! str_contains(strtolower($query->sql), 'brands')) {
            return;
        }

        $stopped = true;

        DB::table(ImportDriver::TABLE)
            ->where('run_key', ImportDriver::RUN_KEY)
            ->update(['status' => 'stopped', 'finished_at' => now()]);
    });

    test()->postJson('/admin-api/import/step', ['rows' => 5]);

    expect($stopped)->toBeTrue('the listener never fired, so nothing was tested');
    expect((string) goRunRow()->status)->toBe('stopped', 'a finishing slice put the run back to running');

    // The rows it did commit are still committed and still accounted for — the
    // fix is about the STATUS, not about throwing work away.
    expect((int) DB::table('import_checkpoints')
        ->where('run_key', ImportDriver::RUN_KEY)->where('entity', 'brands')->value('processed'))->toBeGreaterThan(0);
});

it('refuses to continue a paused run and carries on where it left off when resumed', function () {
    $this->actingAs(goAdmin(), 'admin');
    goStartRun(30);

    $secret = goIssue();

    test()->postJson('/admin-api/import/background-control', ['action' => 'pause'])->assertOk();

    goContinue($secret)->assertStatus(404);

    $run = goRunRow();
    expect((bool) $run->paused)->toBeTrue();
    expect($run->chain_token)->toBeNull();
    expect(goChain()->state()['state'])->toBe('paused');

    test()->postJson('/admin-api/import/background-control', ['action' => 'resume'])
        ->assertOk()->assertJsonPath('ok', true);

    $run = goRunRow();
    expect((bool) $run->paused)->toBeFalse();
    expect($run->chain_token)->not->toBeNull();
    expect($run->status)->toBe('running');
});

it('resumes onto the row after the last committed one and never re-runs it', function () {
    /*
     * The property the whole design exists for, asked of pause/resume rather
     * than of the browser loop: this is Lane FV's claim about checkpoints, and
     * it has to still be true when the thing doing the resuming is the server.
     */
    $this->actingAs(goAdmin(), 'admin');
    goStartRun(30);

    // Two small slices, then a pause.
    goSetRun(['background' => true, 'chain_rows' => 4]);

    test()->postJson('/admin-api/import/step', ['rows' => 4])->assertOk();
    test()->postJson('/admin-api/import/step', ['rows' => 4])->assertOk();

    $processed = (int) DB::table('import_checkpoints')
        ->where('run_key', ImportDriver::RUN_KEY)->where('entity', 'brands')->value('processed');

    expect($processed)->toBe(8);

    test()->postJson('/admin-api/import/background-control', ['action' => 'pause'])->assertOk();
    test()->postJson('/admin-api/import/background-control', ['action' => 'resume'])->assertOk();

    // The resume must not have moved the offset by itself, and the next slice
    // must start at row 9 rather than row 1.
    expect((int) DB::table('import_checkpoints')
        ->where('run_key', ImportDriver::RUN_KEY)->where('entity', 'brands')->value('processed'))->toBe(8);

    test()->postJson('/admin-api/import/step', ['rows' => 4])->assertOk();

    expect((int) DB::table('import_checkpoints')
        ->where('run_key', ImportDriver::RUN_KEY)->where('entity', 'brands')->value('processed'))->toBe(12);

    // And every row that was imported is there exactly once.
    $slugs = DB::table('brands')->where('slug', 'like', 'go-brand-%')->pluck('slug');
    expect($slugs->count())->toBe($slugs->unique()->count());
});

/* ====================================================================== */
/*  5 · A RUNAWAY IS WORSE THAN A STALL                                    */
/* ====================================================================== */

it('stops on purpose when a chain has taken too many slices', function () {
    $this->actingAs(goAdmin(), 'admin');
    goStartRun();
    $secret = goIssue();

    goSetRun(['chain_slices' => ImportChain::MAX_SLICES]);

    // The claim increments past the bound, so this one is refused.
    goContinue($secret)->assertStatus(404);

    $state = goChain()->state();

    expect($state['state'])->toBe('halted');
    expect(str_contains((string) $state['note'], (string) ImportChain::MAX_SLICES))->toBeTrue();
    expect(goRunRow()->chain_token)->toBeNull();
});

it('stops on purpose when a chain has been going too long', function () {
    $this->actingAs(goAdmin(), 'admin');
    goStartRun();
    $secret = goIssue();

    goSetRun(['chain_started_at' => now()->subSeconds(ImportChain::MAX_CHAIN_SECONDS + 60)]);

    goContinue($secret)->assertStatus(404);

    expect(goChain()->state()['state'])->toBe('halted');
    expect(str_contains((string) goChain()->state()['note'], 'hours'))->toBeTrue();
});

it('stops a chain that keeps failing rather than letting it spin', function () {
    $this->actingAs(goAdmin(), 'admin');
    goStartRun(30);

    // One real slice, so there is a part-finished checkpoint to resume from.
    test()->postJson('/admin-api/import/step', ['rows' => 4])->assertOk();

    /*
     * Now make the slice fail for a reason the importer genuinely produces:
     * Checkpoint::open() refuses to resume BY POSITION into a file that is not
     * the file the offset was recorded against, because rows 5-8 of the new
     * file are not the rows 5-8 that were already imported.
     */
    test()->postJson('/admin-api/import/upload', ['file' => goBrands(31)])->assertOk();

    $secret = goIssue();
    $next = goChain()->claim($secret);

    // This chain has already failed MAX_FAILS - 1 times; the slice about to run
    // is the one that reaches the bound.
    goSetRun(['chain_fails' => ImportChain::MAX_FAILS - 1]);

    $kicks = 0;

    goFake(function () use (&$kicks) {
        $kicks++;

        return Http::response('', 204);
    });

    goChain()->advance((string) $next);

    expect($kicks)->toBe(0, 'a chain that cannot make progress kept going');

    $state = goChain()->state();

    expect($state['state'])->toBe('halted');
    expect(str_contains((string) $state['note'], 'in a row'))->toBeTrue($state['note']);
    expect((int) goRunRow()->chain_fails)->toBe(ImportChain::MAX_FAILS);
});

it('reports a halted chain as halted and not as running', function () {
    $this->actingAs(goAdmin(), 'admin');
    goStartRun();
    goIssue();

    // A fresh heartbeat AND no baton: this is the case that would read as
    // "running" if the states were tested in the wrong order, and it would keep
    // reading that way for the whole STALE_SECONDS window.
    goChain()->halt('the background run reached a limit');

    goSetRun(['chain_beat_at' => now()]);

    $state = goChain()->state();

    expect($state['state'])->toBe('halted');
    expect($state['note'])->toBe('the background run reached a limit');
});

/* ====================================================================== */
/*  6 · STALLED, AND PICKING IT BACK UP                                    */
/* ====================================================================== */

it('calls a background run with a cold heartbeat stalled, not running', function () {
    $this->actingAs(goAdmin(), 'admin');
    goStartRun();
    goIssue();

    expect(goChain()->state()['state'])->toBe('running');

    goSetRun(['chain_beat_at' => now()->subSeconds(ImportChain::STALE_SECONDS + 5)]);

    $state = goChain()->state();

    expect($state['state'])->toBe('stalled');
    expect(str_contains((string) $state['note'], 'Nothing is lost'))->toBeTrue();
});

it('picks a stalled run back up when somebody looks at the page', function () {
    $this->actingAs(goAdmin(), 'admin');
    goStartRun();
    $dead = goIssue();

    goSetRun(['chain_beat_at' => now()->subSeconds(ImportChain::STALE_SECONDS + 5)]);

    $kicks = 0;

    goFake(function () use (&$kicks) {
        $kicks++;

        return Http::response('', 204);
    });

    test()->getJson('/admin-api/import/background')->assertOk();

    expect($kicks)->toBe(1, 'looking at the page did not pick the stalled run back up');

    // And the dead chain's baton is worthless, so the revival cannot have
    // produced a second chain running beside the first.
    goContinue($dead)->assertStatus(404);
});

it('waits for the killed slice\'s own claim before reviving, rather than burning the failure budget', function () {
    /*
     * The commonest way a chain dies is the host killing the request, and that
     * leaves ImportDriver's per-slice claim held because the `finally` that
     * releases it never ran. A revival inside that window is refused by step(),
     * counts as a failed slice, chains into another refusal, and five polls of
     * the progress page would HALT a run that was about to resume cleanly.
     */
    $this->actingAs(goAdmin(), 'admin');
    goStartRun();
    goIssue();

    goSetRun([
        'chain_beat_at' => now()->subSeconds(ImportChain::STALE_SECONDS + 5),
        // A claim taken by a process that is no longer alive.
        'locked_at' => now()->subSeconds(10),
        'lock_token' => bin2hex(random_bytes(8)),
    ]);

    $kicks = 0;

    goFake(function () use (&$kicks) {
        $kicks++;

        return Http::response('', 204);
    });

    // It says STALLED — honestly, because nothing is running...
    expect(goChain()->state()['state'])->toBe('stalled');

    // ...and it does NOT kick while the dead slice's claim is still held.
    test()->getJson('/admin-api/import/background')->assertOk();

    expect($kicks)->toBe(0, 'a revival raced the dead slice\'s claim and would have been refused');

    // The claim ages out on its own. It is not a wedge — that is what makes
    // this a wait and not a deadlock.
    goSetRun(['locked_at' => now()->subSeconds(ImportDriver::LOCK_SECONDS + 5)]);

    test()->getJson('/admin-api/import/background')->assertOk();

    expect($kicks)->toBe(1, 'the run was never picked back up');
});

it('does not touch a run that is going along fine', function () {
    $this->actingAs(goAdmin(), 'admin');
    goStartRun();
    goIssue();

    $kicks = 0;

    goFake(function () use (&$kicks) {
        $kicks++;

        return Http::response('', 204);
    });

    test()->getJson('/admin-api/import/background')->assertOk();

    expect($kicks)->toBe(0, 'a healthy chain was kicked a second time');
});

/* ====================================================================== */
/*  7 · WHEN THE HOST REFUSES                                              */
/* ====================================================================== */

it('says so, and changes nothing, when the host will not let the shop call itself', function () {
    $this->actingAs(goAdmin(), 'admin');
    goStartRun();

    goFake(function () {
        throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
    });

    $response = test()->postJson('/admin-api/import/background')->assertStatus(409);

    $message = (string) $response->json('message');

    expect(str_contains($message, 'call itself'))->toBeTrue($message);
    expect(str_contains($message, 'Continue'))->toBeTrue('the fallback is not named');
    expect(str_contains($message, 'route table'))->toBeTrue('the stale route cache is not named');

    $run = goRunRow();

    // EVERYTHING IS PUT BACK. The run is exactly as it was, so the console's
    // browser-driven loop carries on untouched.
    expect((bool) $run->background)->toBeFalse();
    expect($run->chain_token)->toBeNull();
    expect((string) $run->status)->toBe('running');

    // And it really does still work.
    test()->postJson('/admin-api/import/step', ['rows' => 5])
        ->assertOk()->assertJsonPath('ok', true);
});

it('reports the status code when the host answers but does not accept', function () {
    $this->actingAs(goAdmin(), 'admin');
    goStartRun();

    goFake(['*' => Http::response('Not Found', 404)]);

    $message = (string) test()->postJson('/admin-api/import/background')->assertStatus(409)->json('message');

    expect(str_contains($message, '404'))->toBeTrue($message);
});

it('tries the shop address first and then forces it to this machine', function () {
    $this->actingAs(goAdmin(), 'admin');
    goStartRun();

    $optionSets = [];

    goFake(function ($request, $options) use (&$optionSets) {
        $optionSets[] = $options;

        return Http::response('', 500);
    });

    /*
     * Driven through a request on a real-looking host, because the second
     * attempt is deliberately SKIPPED when the shop already answers on
     * 127.0.0.1 or localhost — forcing the resolver at an address that is
     * already the loopback would be the same attempt twice, and one failure
     * printed as two lines of the same failure.
     */
    test()->post('http://shop.example.test/admin-api/import/background')->assertStatus(409);

    expect(count($optionSets))->toBe(2, 'the second attempt was not made');
    expect(isset($optionSets[0]['curl'][CURLOPT_RESOLVE]))->toBeFalse();
    expect($optionSets[1]['curl'][CURLOPT_RESOLVE][0] ?? '')->toContain('127.0.0.1');
});

/* ====================================================================== */
/*  8 · THE BROWSER-DRIVEN RUN IS UNTOUCHED                                */
/* ====================================================================== */

it('still drives an import from the browser exactly as it did before', function () {
    $this->actingAs(goAdmin(), 'admin');
    goStartRun(25);

    for ($i = 0; $i < 30; $i++) {
        $result = test()->postJson('/admin-api/import/step', ['rows' => 5])->assertOk()->json();

        expect($result['ok'] ?? false)->toBeTrue((string) ($result['message'] ?? ''));

        if (($result['status']['run']['status'] ?? '') !== 'running') {
            break;
        }
    }

    expect((string) goRunRow()->status)->toBe('complete');
    expect(DB::table('brands')->where('slug', 'like', 'go-brand-%')->count())->toBe(25);

    // And the chain was never involved.
    expect((bool) goRunRow()->background)->toBeFalse();
});

it('leaves the status payload the console draws itself from alone', function () {
    $this->actingAs(goAdmin(), 'admin');
    goStartRun();

    $status = test()->getJson('/admin-api/import/status')->assertOk()->json();

    foreach (['ok', 'files', 'entities', 'overall', 'manifest', 'duplicate', 'run', 'rejects', 'limits', 'defaults'] as $key) {
        expect(array_key_exists($key, $status))->toBeTrue($key.' went missing from the status payload');
    }
});

/* ====================================================================== */
/*  9 · WHAT THE SCREEN READS                                              */
/* ====================================================================== */

it('reports the real numbers out of the checkpoints', function () {
    $this->actingAs(goAdmin(), 'admin');
    goStartRun(20);

    test()->postJson('/admin-api/import/step', ['rows' => 6])->assertOk();

    $progress = test()->getJson('/admin-api/import/background')->assertOk()->json();

    $brands = collect($progress['entities'])->firstWhere('entity', 'brands');

    expect($brands['processed'])->toBe(6);
    expect($brands['denominator'])->toBe(20);
    expect($brands['percent'])->toBe(30);
    expect($progress['overall']['done'])->toBe(6);
    expect($progress['overall']['total'])->toBe(20);
});

it('draws no whole-export bar when one present file has no count that can be believed', function () {
    $this->actingAs(goAdmin(), 'admin');
    goStartRun(10);

    // A second file with a header and no rows at all is a legitimate export and
    // has a denominator of zero, which is not a proportion.
    test()->postJson('/admin-api/import/upload', [
        'file' => goFile('categories.csv', "term_id,name,slug,parent,description\n"),
    ])->assertOk();

    $progress = test()->getJson('/admin-api/import/background')->assertOk()->json();

    expect($progress['overall']['total'])->toBeNull();
    expect($progress['overall']['percent'])->toBeNull();
});

it('leaves the page able to find the endpoint it polls', function () {
    /*
     * FOUND IN THE BROWSER, not in the suite. The page works out its own API
     * base from its own URL, because the production host serves this
     * application under /kbb-upgrade and a hard-coded /admin-api would 404
     * there. It was written stripping ONE path segment and needs TWO, and the
     * symptom is not an error: the frame renders, every poll 404s at
     * .../import/import/background, and the page sits empty forever.
     *
     * Tied to the routes rather than to a string, so moving either endpoint
     * fails here instead of in a blank page on a host with no shell.
     */
    $page = collect(ImportBackgroundRoutes::adminRoutes())
        ->first(fn ($r) => str_ends_with($r->uri(), 'background-page'));
    $poll = collect(ImportBackgroundRoutes::adminRoutes())
        ->first(fn ($r) => $r->uri() === 'admin-api/import/background' && in_array('GET', $r->methods(), true));

    expect($page)->not->toBeNull();
    expect($poll)->not->toBeNull();

    $blade = (string) file_get_contents(resource_path('views/admin/import-background.blade.php'));

    // The suffix the page strips off its own path, spelled out in the page.
    expect(substr_count($blade, 'var PAGE = /\/import\/background-page\/?$/;'))->toBe(1);

    // And stripping it really does leave the prefix the poll hangs off.
    $base = (string) preg_replace('#/import/background-page/?$#', '', '/'.$page->uri());

    expect($base.'/import/background')->toBe('/'.$poll->uri());
});

it('serves the page to an admin and refuses it to everyone else', function () {
    test()->get('/admin-api/import/background-page')->assertStatus(302);

    $this->actingAs(goAdmin(), 'admin');

    $page = test()->get('/admin-api/import/background-page')->assertOk();

    // The states are on the page rather than only in the payload, because a
    // page that renders all of them identically is the failure they exist for.
    foreach (['Import progress', 'background'] as $needle) {
        expect(str_contains($page->getContent(), $needle))->toBeTrue($needle);
    }
});

/* ====================================================================== */
/*  10 · A DATABASE THAT HAS NOT HAD THE UPDATE                            */
/* ====================================================================== */

it('reports itself unavailable, in a sentence, without the chain columns', function () {
    $this->actingAs(goAdmin(), 'admin');
    goStartRun();

    Schema::table(ImportDriver::TABLE, function ($t) {
        $t->dropColumn('chain_token');
    });

    $chain = goChain();

    expect($chain->available())->toBeFalse();
    expect($chain->state()['state'])->toBe('unavailable');

    $result = $chain->begin();

    expect($result['ok'])->toBeFalse();
    expect(str_contains($result['message'], 'database'))->toBeTrue($result['message']);

    // And nothing can be claimed, so there is no path to a slice.
    expect($chain->claim(bin2hex(random_bytes(32))))->toBeNull();
});

it('refuses to start a background run when nothing is running', function () {
    $this->actingAs(goAdmin(), 'admin');

    $message = (string) test()->postJson('/admin-api/import/background')->assertStatus(409)->json('message');

    expect(str_contains($message, 'Nothing is running'))->toBeTrue($message);
});
