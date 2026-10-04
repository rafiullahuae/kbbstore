<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Services\AdminPathService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\StaticMemos;

/**
 * =============================================================================
 * THE PREMISE EVERY PROCESS-LEVEL MEMO IN THIS APPLICATION RESTS ON
 * =============================================================================
 *
 * CLAUDE.md records `Setting::map()` as a landmine: it memoises in a
 * process-level static as well as the cache, so "within one long-lived process
 * it will not see writes made after the first call. Fine under PHP-FPM, a trap
 * in tests and queue workers."
 *
 * TESTS ARE COVERED — `Tests\Support\StaticMemos` clears sixteen registered
 * memos before every test and `StaticMemoIsolationTest` refuses a new static in
 * `app/` that is neither registered nor exempt.
 *
 * WHICH LEAVES THE OTHER HALF: is there a long-lived process here at all?
 *
 * ── THE ANSWER IS NO, AND IT IS CHECKED HERE RATHER THAN BELIEVED ──────────
 *
 * Nothing in this application runs outside one HTTP request:
 *
 *   · NOTHING IMPLEMENTS ShouldQueue and nothing is dispatched. Mail goes out
 *     through `->send()` inline; App\Mail\OrderMail's own header says why in as
 *     many words, and there is no app/Jobs directory.
 *   · NO IN-PROCESS SERVER. No Octane, Swoole or RoadRunner in composer.json,
 *     so a PHP-FPM process tears every static down between requests.
 *   · THE SCHEDULER FORKS. Both entries in routes/console.php are
 *     `Schedule::command()`, which runs `artisan` in a child process.
 *     `Schedule::call()` would not, and there are none.
 *   · EVEN THE BACKGROUND IMPORT IS NOT ONE PROCESS. ImportConsole\ImportChain
 *     is a RELAY: each link answers 204, does one slice in `defer()`, and its
 *     process exits while the next request is already working. Its own header
 *     says so.
 *
 * So the memo trap is LATENT here, not live — the same conclusion
 * VariantPriceMemoFreshnessTest reached about its own snapshot, by the same
 * kind of survey. The difference is that it recorded the survey in PROSE. This
 * file makes it an assertion, because a premise that is only written down is a
 * premise that can stop being true without anything going red.
 *
 * ── WHAT IT COSTS TO CLEAR, IF THE PREMISE EVER CHANGES ────────────────────
 *
 * Measured on this tree, so that whoever adds the first worker does not have to
 * guess:
 *
 *   StaticMemos::forgetAll()            48 us   (16 resets)
 *   one homepage render, statics warm   61.2 ms
 *   the same render straight after a clear   67.1 ms
 *
 * So clearing is not 48us — it is the ~5.9 ms of re-warming that follows, per
 * clear. Clearing between JOBS is therefore cheap and obviously right; clearing
 * per request under the `sync` driver would pay it inside a request that had
 * already warmed them, for nothing.
 *
 * ── AND NOT EVERY MEMO WOULD GO STALE, WHICH IS MEASURED BELOW ─────────────
 *
 * The second case runs the experiment rather than reasoning about it, and the
 * answer is not uniform: `Setting::map()` and `AdminPathService::current()` go
 * stale, `SettingsService::all()` re-reads. A future lane fixing this should
 * start from the measurement, not from the size of the registry.
 */
it('has nothing that outlives a single request', function () {
    /*
     * ▲ THE TRIPWIRE. Each of these is the premise stated as a fact about the
     * repository, so the day somebody adds a worker this is red and the memo
     * question is forced instead of being discovered later on a live shop.
     *
     * MUTATION NOTE. Add `implements ShouldQueue` to any Mailable, or a
     * `Schedule::call(...)` to routes/console.php, and this is red naming it.
     * RUN — both.
     */
    $appFiles = [];

    $walk = static function (string $dir) use (&$walk, &$appFiles): void {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;

            if (is_dir($path)) {
                $walk($path);
            } elseif (str_ends_with($entry, '.php')) {
                $appFiles[] = $path;
            }
        }
    };

    $walk(base_path('app'));

    /*
     * The DECLARATION, not the word. App\Mail\OrderMail's header contains the
     * string "ShouldQueue" in a sentence explaining that it does not implement
     * it, and a scan for the bare word reports that class as queued — which is
     * exactly what VariantPriceMemoFreshnessTest's own comment ended up saying.
     */
    $queued = [];

    foreach ($appFiles as $file) {
        $source = (string) file_get_contents($file);

        if (preg_match('/\bimplements\b[^{;]*\bShouldQueue\b/', $source)) {
            $queued[] = str_replace(base_path().'/', '', $file);
        }
    }

    sort($queued);

    expect($queued)->toBe([], 'something in app/ is queued now, so the process-level memos need an answer');

    // No app/Jobs at all, which is the other half of the same claim.
    expect(is_dir(base_path('app/Jobs')))->toBeFalse();

    // No in-process server: a PHP-FPM process tears statics down between
    // requests, and that is the whole reason this is safe on the shop.
    $composer = (string) file_get_contents(base_path('composer.json'));

    foreach (['laravel/octane', 'swoole', 'roadrunner'] as $runtime) {
        expect(str_contains($composer, $runtime))->toBeFalse("{$runtime} would keep statics alive between requests");
    }

    /*
     * Every scheduled entry forks. Schedule::command() runs `artisan` in a
     * child process; Schedule::call() would run the closure inside the
     * scheduler's own long-lived process, which is precisely the shape this
     * file exists to notice.
     */
    $console = (string) file_get_contents(base_path('routes/console.php'));

    expect(substr_count($console, 'Schedule::call('))->toBe(0, 'a scheduled closure runs in the scheduler process');
    // 3 with Lane RL: `kbb:order-reminders`, every minute -- the exact driver
    // for the "Complete your order" reminders and the feedback request. It
    // sends mail inline and holds nothing between runs; the page heartbeat
    // (OrderReminderTick) does the same work when no cron line is installed.
    // 4 with Lane MK: `kbb:campaigns-step`, every minute -- Driver B of
    // Marketing Emails. It starts due scheduled campaigns and sends a step's
    // worth of mail inline, holding nothing between runs; there is NO page
    // heartbeat for it (marketing never runs on a shopper's request).
    // 5 with Lane CT: `kbb:cart-tracking-prune`, daily at 03:17 -- Cart
    // Tracking's retention sweep. It deletes cart events past the owner's
    // "Keep cart events for", in chunks, and holds nothing between runs; the
    // page heartbeat (CartTrackingTick) does the same at most every six hours
    // when no cron line is installed.
    expect(substr_count($console, 'Schedule::command('))->toBe(5, 'the set of scheduled commands has changed');
});

it('names the memos that would go stale the day that premise changes', function () {
    /*
     * ▲ THE EXPERIMENT, NOT THE ARGUMENT. Prime the memos, have ANOTHER process
     * save a setting — a row write plus the cache forget the console does — and
     * read again. Whatever still answers the old value is what a worker would
     * serve until it restarted.
     *
     * The result is not uniform, which is the point: two of these go stale and
     * one does not, so "clear all sixteen per job" is a bigger hammer than the
     * measurement supports.
     *
     * MUTATION NOTE. Delete `Setting::flushMap()` from StaticMemos::resets()
     * and the third expectation is red — the clear no longer reaches it, so it
     * answers the stale value all the way through. RUN.
     */
    $save = static function (string $key, string $value): void {
        DB::table('settings')->updateOrInsert(['key' => $key],
            ['value' => $value, 'autoload' => true, 'updated_at' => now(), 'created_at' => now()]);
        Cache::forget('kbb.settings');
        Cache::forget('kbb.admin_path');
    };

    /*
     * ▲ THE ADMIN PATH IS PUT BACK IN A `finally`, AND THE FIRST DRAFT OF THIS
     * CASE DID NOT DO THAT — IT POISONED EVERY TEST AFTER IT.
     *
     * Measured: with this case run before CartPageScreenTest, that file's
     * console request answered 404. Not the settings row, which
     * RefreshDatabase rolls back, and not the cache, which is `array` and per
     * app — both read clean afterwards. It is THE COMPILED ROUTE TABLE: at the
     * start of the next test `AdminPathService::current()` correctly said
     * `admin` while the registered routes were still `after-path/login`,
     * `after-path/logout`. Changing this setting inside a test therefore
     * outlives the test in a place nothing else looks.
     *
     * So the value is restored and the memo dropped before this case returns,
     * however it returns. AdminPathService::set() unlinks the compiled routes
     * for the same reason in production.
     */
    $restoreAdminPath = static function () use (&$save): void {
        DB::table('settings')->where('key', 'admin_path')->delete();
        Cache::forget('kbb.admin_path');
        Cache::forget('kbb.settings');
        AdminPathService::forgetMemo();

        foreach (glob(base_path('bootstrap/cache/routes*.php')) ?: [] as $compiled) {
            @unlink($compiled);
        }
    };

    try {

    StaticMemos::forgetAll();

    $save('shop_name', 'Before');
    $save('admin_path', 'before-path');

    // Primed, as a long-lived process would be by its first job.
    expect(Setting::map()['shop_name'] ?? null)->toBe('Before')
        ->and(AdminPathService::current())->toBe('before-path');

    // The owner saves, in the admin, in a different process.
    $save('shop_name', 'After');
    $save('admin_path', 'after-path');

    /*
     * ▲ AND THIS PROCESS STILL SAYS "Before". Not a hypothetical: it is the
     * assertion. A queue worker holding these would keep sending mail with the
     * old shop name and building links against the old admin path until
     * somebody restarted it.
     */
    expect(Setting::map()['shop_name'] ?? null)->toBe('Before', 'Setting::map() stopped memoising; the landmine may be gone')
        ->and(AdminPathService::current())->toBe('before-path', 'AdminPathService stopped memoising');

    // And clearing is what fixes it — which is what a worker would have to do.
    StaticMemos::forgetAll();

    expect(Setting::map()['shop_name'] ?? null)->toBe('After')
        ->and(AdminPathService::current())->toBe('after-path');

    } finally {
        $restoreAdminPath();
    }

    // And this process is back where it started, or the next test pays for it.
    expect(AdminPathService::current())->toBe('admin');
});
