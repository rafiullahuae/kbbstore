<?php

declare(strict_types=1);

/**
 * No test inherits another test's process-level statics.
 *
 * A static outlives the container, so it outlives the test. RefreshDatabase
 * rolls the database back and tests/Pest.php puts the container back; neither
 * touches `private static ?array $memo`. Left alone, whichever test first calls
 * Setting::map(), Url::base(), AdminPathService::current() or IndexNow::key()
 * decides the answer for every test after it in the process — so what the suite
 * reports depends on the order it ran in, and the order changes with
 * --order-by=random, with .phpunit.result.cache putting previous failures
 * first, and with running one file instead of all of them.
 *
 * Three things are pinned here, and they fail for three different reasons:
 *
 *   1. nothing is left over at the start of a test (the behaviour);
 *   2. the reset actually clears what it claims to (the mechanism);
 *   3. every process-level static in app/ is either reset or exempt with a
 *      reason (the thing that stops this rotting the next time somebody adds
 *      a memo).
 *
 * (1) is the one that fails without tests/Pest.php's StaticMemos::forgetAll()
 * call, and it fails only when something ran before it — which is the whole
 * shape of the bug. Run it after any file that touches settings:
 *
 *     vendor/bin/pest tests/Feature/SettingsServiceTest.php \
 *                     tests/Feature/StaticMemoIsolationTest.php
 */

use App\Models\Setting;
use App\Services\AdminPathService;
use App\Services\Mail\ServerMailTransport;
use App\Services\Seo\IndexNow;
use App\Services\SettingsService;
use App\Services\Update\InstalledVersion;
use App\Support\Facets;
use App\Support\Money;
use App\Support\Url;
use Tests\Support\StaticMemos;

/* ------------------------------------------------------------- 1. behaviour -- */

it('starts with no process-level memo left over from an earlier test', function () {
    $left = StaticMemos::leftOver();

    $names = implode(', ', array_keys($left));

    expect($left)->toBe(
        [],
        "these statics survived into this test and will answer with another test's data: {$names}"
    );
});

it('sees its own seed data rather than the memo the previous test filled', function () {
    /*
     * The same assertion stated as behaviour rather than as reflection, because
     * an empty-looking static is not the point — reading the right value is.
     *
     * Every one of these is a memo that another test in this process has
     * already filled: the suite is two thousand tests deep by the time most
     * orders reach here.
     */
    app(SettingsService::class)->set('kbb_static_memo_probe', 'seeded-here', autoload: false);

    expect(app(SettingsService::class)->get('kbb_static_memo_probe'))->toBe('seeded-here');

    Setting::query()->updateOrCreate(['key' => 'site_title'], ['value' => 'Memo Probe Shop']);
    Setting::flushMap();

    expect(Setting::map()['site_title'] ?? null)->toBe('Memo Probe Shop');

    // The admin path this test's data names, not whichever one was resolved
    // first in this process.
    expect(AdminPathService::current())->toBe('admin');
});

/* ------------------------------------------------------------- 2. mechanism -- */

it('clears every memo it registers', function () {
    /*
     * Fill each one, prove it is filled, clear, prove it is empty. A reset that
     * quietly stopped reaching one of these would leave test (1) passing —
     * leftOver() would report nothing — while the memo went on answering.
     */
    Money::currency();
    Facets::active();
    Url::base();
    AdminPathService::current();
    InstalledVersion::get();
    IndexNow::key();
    app(SettingsService::class)->get('kbb_no_such_key_at_all');
    ServerMailTransport::$lastTestDelivery = ['to' => 'probe@example.test'];

    /*
     * Setting::map() last, and that ordering is a finding rather than a
     * detail: IndexNow::key() mints a key when the table holds none and calls
     * Setting::flushMap() on the way out, so filling this one first and
     * IndexNow second leaves it empty again.
     */
    Setting::map();

    $filled = StaticMemos::leftOver();

    foreach ([
        'App\Models\Setting::$memo',
        'App\Support\Money::$memo',
        'App\Support\Money::$memoFor',
        'App\Support\Facets::$memo',
        'App\Support\Url::$base',
        'App\Services\AdminPathService::$memo',
        'App\Services\Update\InstalledVersion::$memo',
        'App\Services\Seo\IndexNow::$resolved',
        'App\Services\SettingsService::$memo',
        'App\Services\SettingsService::$snapshotTaken',
        'App\Services\Mail\ServerMailTransport::$lastTestDelivery',
    ] as $expected) {
        $found = array_key_exists($expected, $filled);

        expect($found)->toBeTrue("{$expected} did not fill, so this test proves nothing about clearing it");
    }

    StaticMemos::forgetAll();

    $after = StaticMemos::leftOver();

    expect($after)->toBe([], 'still set after forgetAll(): '.implode(', ', array_keys($after)));
});

/* -------------------------------------------------------------- 3. the guard -- */

it('registers or exempts every process-level static in app/', function () {
    /*
     * The durable half. Everything above is only as good as the registry, and a
     * registry maintained by hand goes stale the first time somebody adds a
     * memo — which is how three of the existing ones got here.
     *
     * Static PROPERTIES come from reflection, which is authoritative. A
     * function-local `static $x` cannot be reflected at all, so those are read
     * out of the source; that is not decoration either — IndexNow::key() held
     * one, and because nothing could reach it the only available fix was a
     * comment in StorefrontRouteWalkTest telling readers not to trust their own
     * seed data.
     */
    $known = array_map(
        static fn ($class) => (string) $class,
        array_merge(array_keys(StaticMemos::resets()), array_keys(StaticMemos::EXEMPT))
    );

    $unregistered = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = substr($file->getPathname(), strlen(app_path()) + 1);
        $class = 'App\\'.str_replace([DIRECTORY_SEPARATOR, '.php'], ['\\', ''], $relative);

        if (! class_exists($class) && ! trait_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        $statics = array_filter(
            $reflection->getProperties(ReflectionProperty::IS_STATIC),
            static fn ($p) => $p->getDeclaringClass()->getName() === $class
        );

        // `static $x` in a method body. The lookbehind drops `static::`,
        // `static function`, `static fn` and `(static ...)`.
        $localStatics = preg_match('/(?<![:\w$])static\s+\$\w+/', (string) file_get_contents($file->getPathname()));

        if (($statics === [] && ! $localStatics) || in_array($class, $known, true)) {
            continue;
        }

        $unregistered[] = $class;
    }

    sort($unregistered);

    expect($unregistered)->toBe(
        [],
        'these classes hold process-level state that survives a test and are neither reset nor exempt in '
        .'Tests\Support\StaticMemos: '.implode(', ', $unregistered)
    );
});

it('gives every exemption a reason', function () {
    foreach (StaticMemos::EXEMPT as $class => $reason) {
        expect(class_exists($class))->toBeTrue("exempted class {$class} no longer exists")
            ->and(strlen($reason))->toBeGreaterThan(40, "the exemption for {$class} does not say why");
    }
});
