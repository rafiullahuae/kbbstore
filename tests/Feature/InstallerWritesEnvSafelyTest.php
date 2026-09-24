<?php

declare(strict_types=1);

use App\Support\SiteUrl;

/**
 * =============================================================================
 * THE INSTALLER MAY NOT BE THE WAY A SHOP GETS A BAD `.env`
 * =============================================================================
 *
 * `public-web-root/install.php` writes the file every later thing depends on.
 * Three defects lived in it, all three found by reading and none of them
 * visible from a successful install:
 *
 * ── 1. CONFIGURATION INJECTION FROM A FORM FIELD ────────────────────────────
 *
 * The address was validated with `preg_match('#^https?://[^\s/]+#i', $appUrl)`
 * — anchored at the START only — and written with an escaper that quoted only
 * when the value contained a space, a `"` or a `#`. A NEWLINE is none of those.
 * So
 *
 *     https://real-shop.example\nAPP_DEBUG=true
 *
 * passed validation and became TWO LINES of `.env`. Any field on the installer
 * could add arbitrary configuration to the shop it was installing, including
 * switching the debug page — which prints the database password — on for a
 * production site.
 *
 * ── 2. http:// ON EVERY PROXIED HOST ────────────────────────────────────────
 *
 * The default address read `$_SERVER['HTTPS']` and nothing else. Cloudways,
 * Hostinger and every other host that terminates TLS at a proxy leave that
 * unset and say so in `X-Forwarded-Proto`. So a shop with a perfectly good
 * certificate got `APP_URL=http://…` unless the owner noticed the box — and
 * then spent its life emailing http:// links and printing an http:// canonical
 * on an https:// page.
 *
 * ── 3. THE SUB-FOLDER WAS DROPPED ───────────────────────────────────────────
 *
 * A shop installed at `example.com/shop/` got `https://example.com` and
 * `KBB_BASE_PATH=` empty. CUTOVER-EXTRABEAUTY §5 calls a wrong KBB_BASE_PATH
 * "the single most likely cause of every link is wrong".
 *
 * ── AND WHY THESE FUNCTIONS ARE LIFTED RATHER THAN INCLUDED ─────────────────
 *
 * install.php cannot be included: its top level finds the application folder,
 * mints a setup token and prints a page. Same arrangement, and the same reason,
 * as InstallerRecommendedChecksTest and InstallerAndFrontControllerAgreeTest.
 */

/** The named functions, cut out of the shipped file and made callable. */
function iwesLift(): void
{
    static $ready = false;

    if ($ready) {
        return;
    }

    $ready = true;

    $src = (string) file_get_contents(base_path('public-web-root/install.php'));

    foreach (['kbb_env_escape', 'kbb_env_value', 'kbb_normalise_site_url', 'kbb_guess_site_url', 'kbb_base_path_of'] as $name) {
        $start = strpos($src, 'function '.$name.'(');

        expect($start)->not->toBeFalse('install.php no longer defines '.$name.'()');

        $open = (int) strpos($src, '{', (int) $start);
        $depth = 0;
        $end = null;

        for ($i = $open, $n = strlen($src); $i < $n; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}' && --$depth === 0) {
                $end = $i;
                break;
            }
        }

        expect($end)->not->toBeNull('could not brace-match '.$name.'()');

        eval(substr($src, (int) $start, ((int) $end - (int) $start) + 1));
    }
}

beforeEach(function () {
    iwesLift();
});

it('refuses the newline that used to become a second .env line', function () {
    /*
     * ── THE INJECTION, AT THE INSTALLER'S OWN DOOR ──────────────────────────
     *
     * Both halves are asserted: the address is refused by the normaliser, and
     * even if something bypassed it the value writer refuses it too. Defence in
     * depth is warranted here because the consequence is a production shop
     * printing its database password to every visitor.
     *
     * MUTATION: restore the old escaper —
     *   return str_contains($v,' ')||str_contains($v,'"')||str_contains($v,'#')||$v===''
     *       ? '"'.str_replace(['\\','"'],['\\\\','\\"'],$v).'"' : $v;
     * — and the old check `preg_match('#^https?://[^\s/]+#i', $appUrl)`.
     * Red on both expectations, and kbb_env_escape() then returns the newline
     * unquoted.
     */
    expect(kbb_normalise_site_url("https://real-shop.example\nAPP_DEBUG=true"))->toBeNull();
    expect(kbb_env_value('APP_URL', "https://real-shop.example\nAPP_DEBUG=true"))->toBeNull();
    expect(kbb_env_value('DB_PASSWORD', "pass\nAPP_ENV=local"))->toBeNull();

    // And an ordinary value still comes out quoted and readable.
    expect(kbb_env_escape('https://shop.example'))->toBe('"https://shop.example"');
    expect(kbb_env_escape('p@ss word#1'))->toBe('"p@ss word#1"');
    expect(kbb_env_escape('a$B`c\\d'))->toBe('"a\\$B\\`c\\\\d"');
});

it('normalises exactly as App\Support\SiteUrl does, so the two copies cannot drift', function () {
    /*
     * The duplication is deliberate — install.php may have no dependencies,
     * because there may be no vendor/ when it runs — and this is what keeps it
     * honest. Same shape as InstallerAndFrontControllerAgreeTest, which pins
     * the two folder-name lists to each other for the same reason.
     *
     * The installer assumes https for a bare host; SiteUrl only does when
     * asked. That is the one intended difference and it is expressed by passing
     * `true`, not by excusing a mismatch.
     *
     * MUTATION: change either copy's port handling, path handling or host
     * pattern. Red, naming the input.
     */
    $cases = [
        'https://shop.example/',
        'HTTPS://Shop.Example',
        'https://shop.example:443/index.php',
        'http://shop.example:80',
        'shop.example',
        'shop.example/kbb-upgrade/',
        'https://easywebsol.com/kbb-upgrade/',
        'https://admin:pw@shop.example',
        "https://shop.example\nX=1",
        'javascript:alert(1)',
        'ftp://shop.example',
        'https://-bad.example',
        'http://localhost:8000',
        'http://[::1]:8000/',
        'https://shop.example/?a=b#c',
    ];

    foreach ($cases as $case) {
        expect(kbb_normalise_site_url($case))->toBe(
            SiteUrl::normalise($case, true),
            'installer and SiteUrl disagree on: '.json_encode($case)
        );
    }
});

it('reads https from the proxy header, because that is the only place a proxied host puts it', function () {
    /*
     * MUTATION: delete the HTTP_X_FORWARDED_PROTO branch from
     * kbb_guess_site_url(). Red — and every install behind Cloudways or
     * Hostinger silently defaults to http://.
     */
    $keep = $_SERVER;

    $_SERVER = ['HTTP_HOST' => 'shop.example', 'SCRIPT_NAME' => '/install.php', 'HTTP_X_FORWARDED_PROTO' => 'https'];
    expect(kbb_guess_site_url())->toBe('https://shop.example');

    $_SERVER = ['HTTP_HOST' => 'shop.example', 'SCRIPT_NAME' => '/install.php', 'HTTP_X_FORWARDED_SSL' => 'on'];
    expect(kbb_guess_site_url())->toBe('https://shop.example');

    $_SERVER = ['HTTP_HOST' => 'shop.example', 'SCRIPT_NAME' => '/install.php', 'SERVER_PORT' => '443'];
    expect(kbb_guess_site_url())->toBe('https://shop.example');

    $_SERVER = ['HTTP_HOST' => 'shop.example', 'SCRIPT_NAME' => '/install.php', 'HTTPS' => 'on'];
    expect(kbb_guess_site_url())->toBe('https://shop.example');

    // No signal anywhere: http, honestly, rather than a guess that would make
    // every link on the shop a mixed-content warning in the other direction.
    $_SERVER = ['HTTP_HOST' => 'shop.example', 'SCRIPT_NAME' => '/install.php'];
    expect(kbb_guess_site_url())->toBe('http://shop.example');

    $_SERVER = $keep;
});

it('keeps the sub-folder the installer is actually sitting in', function () {
    /*
     * SCRIPT_NAME is the one part of this the SERVER fills in rather than the
     * client, so it costs nothing to get right — and getting it wrong writes
     * KBB_BASE_PATH empty on a shop that needs it, which CUTOVER-EXTRABEAUTY §5
     * names as the most likely cause of "every link is wrong".
     *
     * MUTATION: drop the $dir term from kbb_guess_site_url()'s return. Red.
     */
    $keep = $_SERVER;

    $_SERVER = ['HTTP_HOST' => 'example.com', 'SCRIPT_NAME' => '/shop/install.php', 'HTTPS' => 'on'];
    expect(kbb_guess_site_url())->toBe('https://example.com/shop');
    expect(kbb_base_path_of(kbb_guess_site_url()))->toBe('/shop');

    $_SERVER = ['HTTP_HOST' => 'extrabeauty.ae', 'SCRIPT_NAME' => '/install.php', 'HTTPS' => 'on'];
    expect(kbb_guess_site_url())->toBe('https://extrabeauty.ae');
    expect(kbb_base_path_of(kbb_guess_site_url()))->toBe('', 'a shop at a domain root must get an EMPTY base path');

    $_SERVER = $keep;
});

it('writes .env through a rename, so a crash cannot leave a shop with no APP_KEY', function () {
    /*
     * file_put_contents() truncates and then writes. Between the two the file
     * exists and is empty, and an application with no APP_KEY does not boot at
     * all — on a host whose only repair tool lives inside the application.
     *
     * Asserted on the source for the same reason InstallerRecommendedChecksTest
     * asserts on the source: the window is between two syscalls and cannot be
     * observed from a single-threaded test.
     *
     * MUTATION: put back
     *   $ok = @file_put_contents($file, implode("\n", $lines)."\n", LOCK_EX) !== false;
     * Red.
     */
    $src = (string) file_get_contents(base_path('public-web-root/install.php'));

    $start = (int) strpos($src, 'function kbb_write_env(');
    $body = substr($src, $start, (int) strpos($src, 'function kbb_run_step(') - $start);

    expect($body)->toContain('rename(');
    expect($body)->toContain("'.tmp'");
    expect($body)->not->toContain('@file_put_contents($file');
});

it('sends the address and the base path to .env as one decision', function () {
    /*
     * They cannot disagree: KBB_BASE_PATH is derived from the same normalised
     * address APP_URL is written from, so "APP_URL has the folder and
     * KBB_BASE_PATH is empty" — the state the installer used to produce
     * unconditionally — is now unreachable.
     *
     * MUTATION: hard-code 'KBB_BASE_PATH' => '' in kbb_write_env()'s $values.
     * Red.
     */
    $src = (string) file_get_contents(base_path('public-web-root/install.php'));

    expect($src)->toContain("kbb_base_path_of((string) \$s['app_url'])");
    expect($src)->not->toContain("'KBB_BASE_PATH=',");
});

it('falls back to localhost rather than putting a hostile Host header in the box', function () {
    /*
     * The installer's default comes from HTTP_HOST, which is a header the
     * visitor picks. That is acceptable HERE and only here -- the person whose
     * header it is, is the person installing the shop, and there is nobody else
     * on the site yet to attack. It is still not a reason to put whatever
     * arrives into the page.
     *
     * The old line did: `'://'.($_SERVER['HTTP_HOST'] ?? 'localhost')`, straight
     * through. htmlspecialchars() at the render site stopped it being XSS, but
     * the FIELD would have been pre-filled with the attacker's string, and the
     * installer's job is to offer an address somebody can accept without
     * reading it.
     *
     * Measured against the running installer as well as here: with
     * `Host: evil.example"><script>alert(1)</script>` the field reads
     * https://localhost and the page contains no `alert(1)`.
     *
     * MUTATION: return the unnormalised string from kbb_guess_site_url(). Red.
     */
    $keep = $_SERVER;

    $_SERVER = ['HTTP_HOST' => 'evil.example"><script>alert(1)</script>', 'SCRIPT_NAME' => '/install.php'];
    expect(kbb_guess_site_url())->toBe('https://localhost');

    $_SERVER = ['HTTP_HOST' => 'shop.example:8443', 'SCRIPT_NAME' => '/install.php'];
    expect(kbb_guess_site_url())->toBe('http://shop.example:8443');

    $_SERVER = $keep;
});
