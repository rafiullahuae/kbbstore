<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Tests\TestCase;

/*
|------------------------------------------------------------------------------
| Build the schema before the first test, not inside it
|------------------------------------------------------------------------------
|
| RefreshDatabase normally runs `migrate:fresh` from the setUp of whichever test
| happens to run first. On this app that has two effects, on that one test only:
|
|   1. Its wrapping transaction does not survive the migration run, so its
|      writes commit and leak into every test after it — which surfaces as
|      unique-constraint violations in a test that has nothing to do with the
|      one that actually leaked.
|   2. Routes registered after the application booted are not matched, so a
|      request to one 404s while Route::getRoutes() still lists it.
|
| Both are ordering artefacts: the same test passes when it runs second. Doing
| the migration here — at suite bootstrap, before any test exists — removes the
| special case. RefreshDatabaseState::$migrated is the flag the trait itself
| checks, so it then goes straight to opening the transaction it is wanted for.
*/
(static function (): void {
    if (RefreshDatabaseState::$migrated) {
        return;
    }

    applyPhpUnitEnvironment();

    // In a process of its own — see tests/build-test-schema.php for why it is
    // that script and not `artisan migrate:fresh`. The environment applied
    // above is inherited by the child.
    exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/build-test-schema.php') . ' 2>&1',
        $output,
        $status,
    );

    if ($status !== 0) {
        throw new RuntimeException(
            "Could not build the test schema.\n" . implode("\n", $output)
        );
    }

    RefreshDatabaseState::$migrated = true;
})();

/**
 * Apply phpunit.xml's <php><env> block to this process.
 *
 * PHPUnit applies that block before it runs tests but after Pest has loaded
 * this file, so at this point the application would still boot against .env —
 * pointing the migration at the developer's own database rather than the test
 * one. Read out of phpunit.xml rather than repeated here, so there is one
 * place that says what the test environment is.
 */
function applyPhpUnitEnvironment(): void
{
    $config = dirname(__DIR__) . '/phpunit.xml';

    if (! is_file($config)) {
        return;
    }

    $xml = @simplexml_load_file($config);

    if ($xml === false) {
        return;
    }

    foreach ($xml->php->env ?? [] as $entry) {
        $name = (string) $entry['name'];
        $value = (string) $entry['value'];

        if ($name === '') {
            continue;
        }

        // force="false" is PHPUnit's default: an existing value wins.
        if (getenv($name) !== false && strtolower((string) ($entry['force'] ?? '')) !== 'true') {
            continue;
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

uses(TestCase::class, RefreshDatabase::class)->in('Feature');
uses(TestCase::class)->in('Unit');
