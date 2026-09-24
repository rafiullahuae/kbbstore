<?php

declare(strict_types=1);

use App\Support\SiteUrl;

/**
 * =============================================================================
 * WRITING `.env` IS THE PART THAT CAN BRICK A SHOP
 * =============================================================================
 *
 * A shop whose `.env` lost its `APP_KEY` does not boot. Not "shows an error" --
 * does not boot, which on a host with no shell means the admin panel that would
 * repair it is inside the application that will not start. There is exactly one
 * way back (`kbb-recover.php`) and the owner has to have set its token before
 * they needed it.
 *
 * So four properties, each with its own failure:
 *
 *   OTHER KEYS SURVIVE       a rewrite that drops DB_PASSWORD is a dead shop
 *   VALUES CANNOT INJECT     a newline in a value is a second .env line
 *   THE WRITE IS ATOMIC      truncate-then-write has a window with no APP_KEY
 *   THE CONFIG CACHE GOES    or nothing reads the file that was just written
 *
 * The last one is the one that will bite, and it is the oldest landmine in this
 * repository: **`.env` is not read at all while `bootstrap/cache/config.php`
 * exists.** It is why KBB_NOINDEX read `false` for its entire life. A feature
 * that writes APP_URL and leaves the compiled config alone appears to work --
 * the file says the new domain -- and does nothing, because every link is still
 * built from the old one.
 */

/** A throwaway .env, with the shape of a real one. */
function siteUrlEnvFixture(?string $body = null): string
{
    $dir = sys_get_temp_dir().'/kbb-env-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);

    $path = $dir.'/.env';
    file_put_contents($path, $body ?? <<<'ENV'
        APP_NAME="K Beauty Bliss"
        APP_ENV=production
        APP_KEY=base64:AAAABBBBCCCCDDDDEEEEFFFFGGGGHHHHIIIIJJJJKKK=
        APP_DEBUG=false
        APP_URL=https://old-shop.example

        # A comment that must survive.
        KBB_BASE_PATH=

        DB_PASSWORD="p@ss word#1"
        ENV);

    return $path;
}

it('changes the one key it was asked to and leaves every other byte alone', function () {
    /*
     * The failure this prevents is not subtle and is not recoverable from a
     * browser: a rewrite that reformats the file can drop DB_PASSWORD, or
     * re-quote APP_KEY in a way phpdotenv reads back differently -- and an
     * APP_KEY that reads back differently makes every encrypted Stripe key and
     * SMTP password in the database permanently unreadable. That is the one
     * thing docs/CUTOVER-EXTRABEAUTY.md marks as impossible to undo.
     *
     * MUTATION: have SiteUrl::apply() rebuild the file from $pairs alone
     * instead of rewriting matched lines in place. Red on the comment, on
     * APP_KEY and on DB_PASSWORD.
     */
    $path = siteUrlEnvFixture();

    $result = SiteUrl::writeEnv($path, [SiteUrl::KEY => 'https://new-shop.example']);

    expect($result['ok'])->toBeTrue();

    $after = (string) file_get_contents($path);

    expect($after)->toContain('APP_URL="https://new-shop.example"');
    expect($after)->not->toContain('old-shop.example');

    // Everything else, byte for byte.
    expect($after)->toContain('APP_KEY=base64:AAAABBBBCCCCDDDDEEEEFFFFGGGGHHHHIIIIJJJJKKK=');
    expect($after)->toContain('# A comment that must survive.');
    expect($after)->toContain('DB_PASSWORD="p@ss word#1"');
    expect($after)->toContain('APP_ENV=production');
});

it('refuses a value carrying a control character rather than writing it', function () {
    /*
     * ── THE INJECTION, STATED AS A TEST ─────────────────────────────────────
     *
     * If this value reached the file, `.env` would carry APP_DEBUG=true and the
     * shop would print stack traces -- including database credentials -- to
     * every visitor. install.php's old escaper quoted only on a space, a `"` or
     * a `#`, so a newline went through untouched.
     *
     * Refused whole, not sanitised: a partial rewrite of a .env is worse than
     * none, because the install continues on top of it.
     *
     * MUTATION: delete the control-character check in SiteUrl::envLine(). Red,
     * and the file then contains APP_DEBUG=true.
     */
    $path = siteUrlEnvFixture();
    $before = (string) file_get_contents($path);

    $result = SiteUrl::writeEnv($path, [SiteUrl::KEY => "https://evil.example\nAPP_DEBUG=true"]);

    expect($result['ok'])->toBeFalse();
    expect((string) file_get_contents($path))->toBe($before, 'a refused write must change nothing at all');
    expect((string) file_get_contents($path))->not->toContain('APP_DEBUG=true');
});

it('quotes and escapes so the value reads back exactly as it went in', function () {
    /*
     * phpdotenv interpolates `${VAR}` and `$VAR` inside an unquoted or
     * double-quoted value, so an unescaped `$` in a password silently becomes
     * something else -- a database password that is nearly right, on a shop
     * that cannot connect and whose .env "looks fine".
     *
     * MUTATION: drop `$` and the backtick from the escape table in envLine().
     * Red.
     */
    expect(SiteUrl::envLine('APP_URL', 'https://a"b.example'))->toBe('APP_URL="https://a\\"b.example"');
    expect(SiteUrl::envLine('DB_PASSWORD', 'a$HOME`b\\c'))->toBe('DB_PASSWORD="a\\$HOME\\`b\\\\c"');

    // A key that is not a key is not written at all.
    expect(SiteUrl::envLine('app url', 'x'))->toBeNull();
    expect(SiteUrl::envLine('APP_URL', "x\ny"))->toBeNull();
});

it('rewrites every occurrence of a duplicated key, so the file cannot say two things', function () {
    /*
     * phpdotenv resolves a duplicate as last-wins. Rewriting only the first
     * leaves the OLD address as the effective one, on a screen that has just
     * reported success -- the worst possible combination.
     *
     * MUTATION: `break` out of apply()'s loop after the first match. Red.
     */
    $path = siteUrlEnvFixture("APP_URL=https://one.example\nDB_HOST=localhost\nAPP_URL=https://two.example\n");

    SiteUrl::writeEnv($path, [SiteUrl::KEY => 'https://three.example']);

    $after = (string) file_get_contents($path);

    expect(substr_count($after, 'three.example'))->toBe(2);
    expect($after)->not->toContain('one.example');
    expect($after)->not->toContain('two.example');
});

it('appends a key the file does not have yet', function () {
    $path = siteUrlEnvFixture("APP_NAME=Shop\n");

    SiteUrl::writeEnv($path, ['KBB_BASE_PATH' => '/kbb-upgrade']);

    expect((string) file_get_contents($path))->toContain('KBB_BASE_PATH="/kbb-upgrade"');
});

it('writes through a temporary file and renames, so a crash cannot leave a half a .env', function () {
    /*
     * The property is "there is no instant at which .env is incomplete", and it
     * cannot be observed from inside a single-threaded test -- the window is
     * between two syscalls. What CAN be observed is that the implementation has
     * no truncating write in it, so this asserts on the source.
     *
     * That is the same shape as InstallerRecommendedChecksTest's last case and
     * for the same reason: a property that only shows itself on the unlucky
     * host has to be pinned on the machine running the suite.
     *
     * MUTATION: replace the fopen/rename block in SiteUrl::writeEnv() with
     * file_put_contents($path, $next). Red.
     */
    $src = (string) file_get_contents(base_path('app/Support/SiteUrl.php'));

    $body = substr($src, (int) strpos($src, 'public static function writeEnv'));

    // rename() into place, never a truncating write over the live file.
    expect($body)->toContain('rename(');
    expect($body)->not->toContain('file_put_contents(');

    // And the temporary file lands in the same directory, because rename() is
    // only atomic within one filesystem -- sys_get_temp_dir() often is not one.
    expect($body)->toContain("dirname(\$path)");

    // It also leaves nothing behind.
    $path = siteUrlEnvFixture();
    SiteUrl::writeEnv($path, [SiteUrl::KEY => 'https://new.example']);

    expect(glob(dirname($path).'/*.tmp'))->toBe([]);
});

it('clears the compiled config, because otherwise nothing reads the file it just wrote', function () {
    /*
     * ── THE ONE THAT WOULD HAVE SHIPPED BROKEN ──────────────────────────────
     *
     * Laravel skips .env entirely when bootstrap/cache/config.php is present.
     * Writing APP_URL and leaving that file in place is a feature that reports
     * success, changes the file on disk, and changes nothing the shop does --
     * the exact failure that left KBB_NOINDEX reading `false` for its whole
     * life, and the reason the update hatch in this project is a file rather
     * than a setting.
     *
     * MUTATION: make SiteUrl::writeEnv() return without calling
     * clearCompiledConfig(). Red.
     */
    /*
     * The path the APPLICATION says its compiled config is at, not
     * bootstrap/cache guessed by hand. APP_CONFIG_CACHE and APP_ROUTES_CACHE
     * are overridable and this project's own preview harness overrides them
     * (Tests\Support\CompiledCaches) -- a clear that deletes the conventional
     * path reports success while the file actually being read survives. That is
     * exactly what happened on the preview built for this lane's screenshots:
     * the new route answered 404 until `artisan route:clear` was run by hand.
     *
     * MUTATION: use app()->bootstrapPath('cache').'/config.php' inside
     * clearCompiledConfig() instead of getCachedConfigPath(). Red here under
     * the suite's own overrides.
     */
    $configPath = app()->getCachedConfigPath();
    $routesPath = app()->getCachedRoutesPath();

    foreach ([$configPath, $routesPath] as $file) {
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
    }

    file_put_contents($configPath, "<?php return ['app' => ['url' => 'https://old-shop.example']];\n");
    file_put_contents($routesPath, "<?php return [];\n");

    $path = siteUrlEnvFixture();

    $result = SiteUrl::writeEnv($path, [SiteUrl::KEY => 'https://new-shop.example']);

    expect($result['ok'])->toBeTrue();
    expect(is_file($configPath))->toBeFalse('the compiled config must be gone or the new APP_URL is never read');
    expect(is_file($routesPath))->toBeFalse('a cached route file outlives a .env write and keeps serving the old routes');
    expect($result['caches_cleared'])->not->toBe([]);
});

it('says so rather than throwing when there is no .env to update', function () {
    $result = SiteUrl::writeEnv(sys_get_temp_dir().'/kbb-nope-'.bin2hex(random_bytes(4)).'/.env', [SiteUrl::KEY => 'https://x.example']);

    expect($result['ok'])->toBeFalse();
    expect($result['reason'])->toBeString();
});
