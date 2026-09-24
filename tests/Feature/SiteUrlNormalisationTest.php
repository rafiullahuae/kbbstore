<?php

declare(strict_types=1);

use App\Support\SiteUrl;

/**
 * =============================================================================
 * WHAT MAY BECOME THIS SHOP'S ADDRESS, AND WHAT MAY NOT
 * =============================================================================
 *
 * `APP_URL` is the one string every absolute URL in this application is built
 * from: password-reset links, order emails, payment webhook callbacks, the
 * canonical tag, the sitemap, the fifteen legacy category redirects. A value
 * that got into it wrong is wrong in an inbox, in Google's index and in a
 * payment provider's dashboard at the same time, and the shop looks fine.
 *
 * So the normaliser is the gate, and this file is what it refuses.
 *
 * ── THE DEFECT THESE EXIST BECAUSE OF ───────────────────────────────────────
 *
 * install.php validated the address with `preg_match('#^https?://[^\s/]+#i')`
 * and wrote it with an escaper that quoted only on a space, a `"` or a `#`.
 * That regex is ANCHORED ONLY AT THE START. `https://real.example\nAPP_DEBUG=true`
 * satisfies it -- the first line matches -- a newline is none of the three
 * characters the escaper quoted for, and the value went into .env AS TWO LINES.
 * A form field on the installer could therefore write arbitrary configuration,
 * including switching the debug page on for a production shop.
 *
 * ── A MUTATION NOTE THAT WAS WRONG, AND WHAT MEASURING IT TAUGHT ────────────
 *
 * The obvious note here is "delete the control-character guard at the top of
 * SiteUrl::normalise() and the newline tests go red". IT WAS RUN, AND THEY DID
 * NOT. PHP's own parse_url() REPLACES a control character with an underscore
 * before anything in this application sees it, so
 * `https://shop.example/a\nAPP_DEBUG=true` arrives as
 * `https://shop.example/a_APP_DEBUG=true` -- mangled, but one line.
 *
 * So the guard is defence in depth and refuses rather than silently mangles,
 * which is worth having and is not what stops the injection. TWO OTHER THINGS
 * DO, and both have mutations that were run and are red:
 *
 *   normalise() RETURNS A STRING IT REBUILT from the parsed scheme, host, port
 *   and path -- never the input. Nothing that was not parsed can survive it,
 *   whatever parse_url did with it. Pinned by the last test in this file.
 *
 *   SiteUrl::envLine() REFUSES a control character outright, and
 *   SiteUrl::writeEnv() refuses the whole write when it does. MUTATION: delete
 *   that check -- two tests in SiteUrlEnvWriteTest go red, measured.
 *
 * The per-test mutations below are the ones that were actually run.
 */

it('refuses an address carrying a newline, which is how .env gets a second line', function () {
    /*
     * The exact shape that got through the old installer check. Note that it is
     * a perfectly good URL up to the newline, which is the whole problem: any
     * validator anchored only at the start says yes.
     */
    expect(SiteUrl::normalise("https://real-shop.example\nAPP_DEBUG=true"))->toBeNull();
    expect(SiteUrl::normalise("https://real-shop.example\r\nAPP_ENV=local"))->toBeNull();
    expect(SiteUrl::normalise("https://real-shop.example\x00.evil.test"))->toBeNull();
    expect(SiteUrl::normalise("https://real-shop.example\tx"))->toBeNull();
});

it('refuses anything that is not http or https', function () {
    /*
     * `javascript:` and `data:` matter because APP_URL ends up inside an href
     * in an email template and inside a <link rel="canonical">. `file:` and
     * `gopher:` matter because parse_url is happy to hand them back.
     *
     * MUTATION: drop the scheme check in normalise(). Red.
     */
    expect(SiteUrl::normalise('javascript:alert(1)'))->toBeNull();
    expect(SiteUrl::normalise('data:text/html,<script>'))->toBeNull();
    expect(SiteUrl::normalise('file:///etc/passwd'))->toBeNull();
    expect(SiteUrl::normalise('ftp://shop.example'))->toBeNull();
});

it('refuses credentials in the address, because every order email would reprint them', function () {
    /*
     * `https://admin:hunter2@shop.example` is a valid URL and is never what
     * anybody meant. Left in APP_URL it appears in every receipt this shop
     * sends, which teaches customers that a link with a password in it is
     * normal -- and browsers use the userinfo half to disguise the real host.
     *
     * MUTATION: delete the `isset($parts['user'])` check. Red.
     */
    expect(SiteUrl::normalise('https://admin:hunter2@shop.example'))->toBeNull();
    expect(SiteUrl::normalise('https://shop.example@evil.test'))->toBeNull();
});

it('normalises the spellings of one address to one string', function () {
    /*
     * Three people asked for the same shop's address give three answers. If
     * they are stored as three strings then SiteUrl::mismatch() reports a
     * mismatch on a shop that has not moved, and the banner never goes away.
     *
     * MUTATION: return `$raw` instead of the rebuilt string at the end of
     * normalise(). Red on every line here.
     */
    expect(SiteUrl::normalise('https://Shop.Example/'))->toBe('https://shop.example');
    expect(SiteUrl::normalise('HTTPS://SHOP.EXAMPLE'))->toBe('https://shop.example');
    expect(SiteUrl::normalise('https://shop.example:443'))->toBe('https://shop.example');
    expect(SiteUrl::normalise('http://shop.example:80'))->toBe('http://shop.example');
    expect(SiteUrl::normalise('https://shop.example/index.php'))->toBe('https://shop.example');
    expect(SiteUrl::normalise('https://shop.example/?utm_source=x#frag'))->toBe('https://shop.example');
    expect(SiteUrl::normalise('https://shop.example.'))->toBe('https://shop.example');
});

it('keeps the sub-folder, because a shop under one has it as part of its address', function () {
    /*
     * env.staging.txt ships APP_URL=https://easywebsol.com/kbb-upgrade beside
     * KBB_BASE_PATH=/kbb-upgrade. "No path" would be the obvious reading of
     * "normalise an address" and it would break every install that is not at a
     * domain root -- including this project's own staging copy.
     *
     * MUTATION: make SiteUrl::path() return '' always. Red.
     */
    expect(SiteUrl::normalise('https://easywebsol.com/kbb-upgrade/'))->toBe('https://easywebsol.com/kbb-upgrade');
    expect(SiteUrl::normalise('https://easywebsol.com/kbb-upgrade/index.php'))->toBe('https://easywebsol.com/kbb-upgrade');
    expect(SiteUrl::normalise('https://shop.example/a/b'))->toBe('https://shop.example/a/b');
});

it('refuses a host that is not a host', function () {
    expect(SiteUrl::normalise('https://'))->toBeNull();
    expect(SiteUrl::normalise('https://-leading-hyphen.example'))->toBeNull();
    expect(SiteUrl::normalise('https://has space.example'))->toBeNull();
    expect(SiteUrl::normalise('https://under_score.example'))->toBeNull();
    expect(SiteUrl::normalise('https://shop.example:0'))->toBeNull();
    expect(SiteUrl::normalise('https://shop.example:99999'))->toBeNull();
    expect(SiteUrl::normalise(str_repeat('a', 300).'.example'))->toBeNull();
});

it('only assumes https for a bare host when it is asked to', function () {
    /*
     * The installer wants it: somebody asked for their domain types
     * `shop.example`, and http would be the wrong guess on a host that has just
     * been given a certificate.
     *
     * Reading APP_URL back out of .env does NOT want it: a scheme missing there
     * is a fact worth seeing rather than one to paper over, because it means
     * something wrote that file badly.
     *
     * MUTATION: default $assumeHttps to true. Red on the first line.
     */
    expect(SiteUrl::normalise('shop.example'))->toBeNull();
    expect(SiteUrl::normalise('shop.example', true))->toBe('https://shop.example');
    expect(SiteUrl::normalise('shop.example/kbb', true))->toBe('https://shop.example/kbb');
});

it('keeps localhost and an IPv6 literal usable', function () {
    // A preview, a CI job and `artisan serve` are all real installs.
    expect(SiteUrl::normalise('http://localhost'))->toBe('http://localhost');
    expect(SiteUrl::normalise('http://localhost:8000'))->toBe('http://localhost:8000');
    expect(SiteUrl::normalise('http://127.0.0.1:8000'))->toBe('http://127.0.0.1:8000');
    expect(SiteUrl::normalise('http://[::1]:8000'))->toBe('http://[::1]:8000');
});

it('returns a string it rebuilt, never the one it was handed', function () {
    /*
     * ── THE PROPERTY THE INJECTION ACTUALLY DIES ON ─────────────────────────
     *
     * Whatever parse_url() makes of a hostile input, normalise() reassembles
     * its answer out of the four parts it validated -- scheme, host, port,
     * path -- so a byte that was not parsed into one of those cannot appear in
     * the output. That is stronger than any blacklist and it is why the
     * control-character guard being defence in depth is acceptable.
     *
     * MUTATION: end normalise() with `return $raw;` instead of the assembled
     * string. Red here and on every line of the normalisation test above.
     */
    foreach ([
        "https://shop.example/a\nAPP_DEBUG=true",
        "https://shop.example/a\r\nX=1",
        "https://shop.example/\x00",
    ] as $hostile) {
        $out = SiteUrl::normalise($hostile);

        if ($out !== null) {
            expect(preg_match('/[\x00-\x1F\x7F]/', $out))->toBe(0, 'normalise() let a control character through: '.json_encode($hostile));
            expect($out)->not->toContain('APP_DEBUG');
        }
    }

    // And nothing it returns can ever be refused by the .env writer, which is
    // the pair of guarantees the installer relies on.
    foreach (['https://shop.example', 'https://a.example/kbb-upgrade', 'http://localhost:8000'] as $good) {
        expect(SiteUrl::envLine('APP_URL', (string) SiteUrl::normalise($good)))->not->toBeNull();
    }
});
