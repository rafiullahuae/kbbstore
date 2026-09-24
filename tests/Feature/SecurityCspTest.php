<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Models\AuditEvent;
use App\Services\Security\ContentSecurityPolicy;
use App\Services\Security\CspViolations;
use App\Services\SecurityModule;
use App\Support\Url;
use Illuminate\Support\Facades\Route;

/**
 * The security module, part three: the content-security policy, report-only.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * WHAT WAS WRONG WITH THE SHOP BEFORE THIS
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * This shop sent no content-security policy at all. App\Http\Middleware\
 * SecurityHeaders sends nosniff, X-Frame-Options, a referrer policy and a
 * permissions policy — and Phase 18's own table of what already exists records
 * the gap in the same line: "no CSP and no HSTS yet". So a script injected into
 * any storefront page by any means — a stored value printed unescaped, a
 * compromised third-party tag, a file changed on disk — executed with the full
 * authority of the page and could talk to any host on the internet. Nothing
 * stopped it and nothing noticed it.
 *
 * That is what the plan means by "the single most effective control against
 * injected script actually executing". It also means the second half: "the one
 * most likely to break a working page, so it wants a report-only phase first
 * with violations collected to the same report screen."
 *
 * Every `it(...)` below states the defect it stands for, and each carries the
 * mutation that turns it red.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * AND THE HALF THAT IS NOT A FEATURE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Two negatives matter more than anything positive in this file:
 *
 *   1. NOT ONE REQUEST REFUSED THAT WAS NOT REFUSED BEFORE. Section 3 walks the
 *      storefront with the policy on and with it off, against NAMED ABSOLUTE
 *      status codes — never the two walks against each other, which is how a
 *      gate that refuses in both passes reports all clear. This lane
 *      established that by mutation in round one and does not repeat it.
 *   2. THE ENFORCING HEADER CANNOT BE EMITTED. Section 1 is that, three ways.
 */

/**
 * The public report endpoint, registered exactly as routes/api.php will carry
 * it — inside the SecurityHeaders group, beside the payment webhooks.
 *
 * From the real file rather than a restated Route:: call, so this suite
 * exercises what the integrator requires: the path, the throttle and the name.
 */
function cspRegisterRoute(): void
{
    Route::middleware(['api', \App\Http\Middleware\SecurityHeaders::class])
        ->prefix('api')
        ->group(base_path('routes/security-csp.php'));
}

/** One report body, in the shape `report-uri` browsers actually post. */
function cspReport(array $overrides = []): array
{
    return ['csp-report' => array_merge([
        'document-uri' => 'https://shop.test/shop?q=serum',
        'referrer' => '',
        'violated-directive' => "script-src-elem 'self'",
        'effective-directive' => 'script-src-elem',
        'original-policy' => "default-src 'self'",
        'disposition' => 'report',
        'blocked-uri' => 'inline',
        'line-number' => 428,
        'status-code' => 200,
        'script-sample' => 'window.KBB = {"cart":1}',
    ], $overrides)];
}

/** POST a report the way a browser does: its own content type, no token. */
function cspPost(array $body): \Illuminate\Testing\TestResponse
{
    return test()->call(
        'POST',
        Url::base().'/api/csp-report',
        [], [], [],
        ['CONTENT_TYPE' => 'application/csp-report'],
        (string) json_encode($body)
    );
}

/** The source of a file with every comment removed, so prose cannot satisfy it. */
function cspCode(string $path): string
{
    $code = '';

    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }

            $code .= $token[1];

            continue;
        }

        $code .= $token;
    }

    return $code;
}

/* ══════════════════════════ 1. it cannot become the enforcing header ═══ */

it('cannot emit the enforcing header, because the name is not in the tree', function () {
    /*
     * THE DEFECT THIS STANDS FOR is the one Phase 18's sequencing exists to
     * prevent: a CSP shipped in enforcing mode on a shop with 24 inline
     * <script> blocks and 124 inline handlers takes the storefront apart — the
     * cart, the checkout's card fields and every analytics tag stop working at
     * once — and the owner, with no shell, cannot turn it off from anywhere but
     * the screen that just stopped rendering.
     *
     * Report-only is therefore not a setting. It is the absence of a string.
     * The comments are stripped first because ContentSecurityPolicy's own
     * docblock names the enforcing header in prose to explain why it is not
     * here, and a naive grep would find its own prohibition.
     */
    $files = [];

    $walk = static function (string $dir) use (&$walk, &$files): void {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;

            if (is_dir($path)) {
                $walk($path);

                continue;
            }

            if (str_ends_with($entry, '.php')) {
                $files[] = $path;
            }
        }
    };

    $walk(app_path());

    expect(count($files))->toBeGreaterThan(200);

    foreach ($files as $file) {
        /*
         * `Content-Security-Policy` NOT followed by `-Report-Only`. The
         * report-only name contains the enforcing one as a prefix, so a plain
         * str_contains would match the one string this module is allowed to
         * have.
         */
        expect(cspCode($file))->not->toMatch('/Content-Security-Policy(?!-Report-Only)/i',
            basename($file).' names the enforcing content-security header');
    }

    expect(ContentSecurityPolicy::HEADER)->toBe('Content-Security-Policy-Report-Only');

    /*
     * MUTATION NOTE. Change ContentSecurityPolicy::HEADER to
     * 'Content-Security-Policy' — one word deleted, and the whole of the
     * difference between reporting and refusing — and this is red on every
     * file it walks as well as on the constant.
     */
});

it('stores the only mode it has, whatever is posted at it', function () {
    /*
     * THE SEAM, and the same one round two left on `integrity_action`. Phase 18
     * puts enforcement after the request gate has watched real traffic, one
     * rule at a time; a lane at the report-only step does not ship an enforce
     * branch, and a control that could be moved to a behaviour that does not
     * exist is worse than no control.
     */
    expect(ContentSecurityPolicy::MODES)->toHaveCount(1)
        ->and(array_keys(ContentSecurityPolicy::MODES))->toBe(['report']);

    $module = app(SecurityModule::class);

    $module->save(['csp_mode' => 'enforce']);
    expect($module->get('csp_mode'))->toBe('report');

    $module->save(['csp_mode' => 'block']);
    expect($module->get('csp_mode'))->toBe('report');

    /*
     * MUTATION NOTE. Add 'enforce' => 'Enforce' to MODES and the first
     * expectation is red; make cast()'s select branch return the posted value
     * and the other two are.
     */
});

it('sends the report-only header on a storefront page, and only when switched on', function () {
    cspRegisterRoute();

    /*
     * THE DEFECT: no policy header on any page, ever. Asserted on a real
     * storefront response through the real middleware stack, not on the class.
     */
    $off = $this->get('/');
    expect($off->headers->get(ContentSecurityPolicy::HEADER))->toBeNull(
        'the policy is being sent although the switch ships off — applying the package moved a header'
    );

    app(SecurityModule::class)->save(['csp_on' => true]);

    $on = $this->get('/');
    $header = (string) $on->headers->get(ContentSecurityPolicy::HEADER);

    expect($header)->not->toBe('')
        ->and($on->headers->get('Content-Security-Policy'))->toBeNull()
        // Written from what this shop serves — see section 2.
        ->and($header)->toContain("default-src 'self'")
        ->and($header)->toContain('https://js.stripe.com')
        ->and($header)->toContain('report-uri '.Url::base().'/api/csp-report');

    /*
     * AND THE REPORT ADDRESS IS THE ONE THE ROUTE FILE REGISTERS. A policy that
     * advertises a path the shop does not answer collects nothing and says
     * nothing about it — the silent failure this whole card would have.
     */
    expect(app(ContentSecurityPolicy::class)->reportUri())
        ->toBe(Url::base().'/api/csp-report');

    cspPost(cspReport())->assertNoContent();

    /*
     * MUTATION NOTE. Ship `csp_on` true and the first expectation is red.
     * Change FALLBACK_PATH to '/csp-report' and the last two are.
     */
});

/* ═══════════════════════════ 2. the policy is this shop's, not a template ═══ */

it('allows every host this shop actually loads, read out of the tree', function () {
    /*
     * THE DEFECT A TEMPLATE POLICY WOULD HAVE. A policy copied from an example
     * breaks whatever the example did not have — here, three analytics loaders
     * and Stripe — and breaks it in report-only mode as a wall of noise, then
     * in enforcing mode as a checkout that cannot take a card.
     *
     * So the hosts are not asserted from memory: App\Services\Analytics is the
     * one place that emits analytics markup, and every host it names is read
     * out of its source and required to be somewhere in the policy. A lane that
     * adds a fourth network to that file and not to the policy is red here.
     */
    $policy = app(ContentSecurityPolicy::class)->header();

    preg_match_all(
        '#https://([a-z0-9.-]+)#i',
        (string) file_get_contents(app_path('Services/Analytics.php')),
        $matches
    );

    $hosts = array_unique($matches[1]);

    expect($hosts)->not->toBeEmpty();

    foreach ($hosts as $host) {
        /*
         * str_contains and not ->toContain(): Pest's toContain is VARIADIC, so
         * a second argument is read as a second needle rather than as the
         * failure message — which is how the first run of this test reported
         * that the policy was missing a host it plainly carried.
         */
        expect(str_contains($policy, 'https://'.$host))->toBeTrue(
            "App\\Services\\Analytics loads {$host} and the policy does not allow it"
        );
    }

    // The three the layout and the checkout load, by name, for the same reason.
    foreach ([
        'https://fonts.googleapis.com',   // layouts/store.blade.php, AccountPanel::fontHref()
        'https://fonts.gstatic.com',      // the faces those stylesheets fetch
        'https://js.stripe.com',          // partials/checkout/stripe-elements.blade.php
        'https://api.stripe.com',         // Elements tokenising a card
        'https://hooks.stripe.com',       // 3-D Secure's own frame
    ] as $needed) {
        expect($policy)->toContain($needed);
    }

    /*
     * AND IT DOES NOT QUIETLY ALLOW EVERYTHING. 'unsafe-inline' in script-src
     * is the one line that would make this screen report nothing and protect
     * nothing — an injected <script> IS inline — and 'unsafe-eval' is the
     * other. Neither may appear anywhere in the policy.
     */
    expect($policy)->not->toContain("'unsafe-inline'")
        ->and($policy)->not->toContain("'unsafe-eval'")
        ->and($policy)->toContain("object-src 'none'");

    /*
     * MUTATION NOTE. Delete 'https://analytics.tiktok.com' from
     * ContentSecurityPolicy::DIRECTIVES and the loop is red; add
     * "'unsafe-inline'" to script-src and the last block is.
     */
});

it('does not put the policy on the admin console or on anything but HTML', function () {
    /*
     * THE DEFECT IT AVOIDS. resources/views/admin/app.blade.php is one 1.1 MB
     * document built almost entirely of inline script and inline style. A
     * storefront policy applied to it would post thousands of violations from
     * the one screen the owner reads violations on, bury the storefront's among
     * them, and cost him a request per violation for the privilege.
     */
    app(SecurityModule::class)->save(['csp_on' => true]);

    $admin = AdminUser::create([
        'name' => 'Owner', 'email' => 'csp-owner-'.uniqid().'@example.test',
        'password' => 'lane-c-password', 'role' => 'owner',
    ]);

    $console = $this->actingAs($admin, 'admin')->get('/'.\App\Services\AdminPathService::current());

    expect($console->headers->get(ContentSecurityPolicy::HEADER))->toBeNull(
        'the storefront policy is being sent on the admin console'
    );

    // And a JSON answer, which no browser will ever check a policy against.
    $json = $this->get('/api/settings');

    expect($json->headers->get(ContentSecurityPolicy::HEADER))->toBeNull();

    /*
     * MUTATION NOTE. Delete the routeIs('admin') branch from
     * CspHeaders::applies() and the first is red; delete the text/html test and
     * the second is.
     */
});

it('leaves the crawl files alone and covers every storefront page there is', function () {
    app(SecurityModule::class)->save(['csp_on' => true]);

    /*
     * TWO THINGS THE INTEGRATION ROUND MOVED UNDER THIS LANE, pinned here
     * because both are silent when they break.
     *
     * 1. /sitemap.xml, /robots.txt and /llms.txt now run inside
     *    `Route::withoutMiddleware(SeoFilesController::STATELESS)` so they can
     *    carry a public Cache-Control. CspHeaders is not in that list, so it
     *    still runs on them — and must still decline, because they are XML and
     *    plain text. A content-security policy on a document no browser renders
     *    is a header a shared cache stores for an hour for nothing.
     * 2. /concern/{concern}/ is a new public storefront page. It is HTML served
     *    through the web group, so it is covered by construction — which is the
     *    property worth pinning, because the alternative design (a list of
     *    paths) would have missed it in silence.
     */
    foreach (['/sitemap.xml', '/robots.txt', '/llms.txt'] as $crawl) {
        $response = $this->get($crawl);

        expect($response->getStatusCode())->toBeLessThan(400, "{$crawl} answered {$response->getStatusCode()}");
        expect($response->headers->get(ContentSecurityPolicy::HEADER))->toBeNull(
            "{$crawl} is carrying a content-security policy no browser will read"
        );
        // And the baseline headers that deliberately stayed on them still are.
        expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    }

    /*
     * The concern listing, by the rule rather than by its status code: a
     * concern page does not exist until the owner has tagged products for one,
     * so this fixture may answer it 200 or 404. Either way it is HTML through
     * the web group and must carry the policy — which is the whole point of
     * keying on the content type instead of on a list of paths somebody has to
     * remember to extend.
     */
    $page = $this->get('/concern/acne/');

    expect(str_contains(strtolower((string) $page->headers->get('Content-Type', '')), 'text/html'))
        ->toBeTrue('the concern listing stopped answering HTML');

    expect($page->headers->get(ContentSecurityPolicy::HEADER))->not->toBeNull(
        'a storefront page added after this lane is not carrying the policy'
    );

    /*
     * MUTATION NOTE. Delete the text/html test in CspHeaders::applies() and the
     * three crawl files are red. Make applies() match a list of storefront
     * paths instead of the content type and the concern page is.
     */
});

/* ═════════════════════════════════════════ 3. and it refuses nothing ═══ */

it('refuses no request the shop answered before', function () {
    cspRegisterRoute();

    /*
     * THE NON-NEGOTIABLE. This is the one piece of the security module that is
     * a middleware rather than a listener — a header has to be SET on a
     * response — so the promise that nothing can be refused has to be measured
     * here rather than inferred from where the code hooks in.
     *
     * `/journal` is deliberately absent: it is a 404 in this fixture, which is
     * the storefront's business and not this module's.
     */
    $paths = ['/', '/shop', '/cart', '/checkout', '/wishlist', '/sitemap.xml', '/robots.txt', '/csp-probe-not-a-page'];

    $off = [];

    foreach ($paths as $path) {
        $off[$path] = $this->get($path)->getStatusCode();
    }

    app(SecurityModule::class)->save(['csp_on' => true]);

    $on = [];

    foreach ($paths as $path) {
        $on[$path] = $this->get($path)->getStatusCode();
    }

    expect($on)->toBe($off);

    /*
     * AND THE SAME THING IN ABSOLUTE TERMS, which is the half that bites. The
     * comparison above measures the module against ITSELF: a middleware that
     * refused in both passes would match itself perfectly and report all clear.
     * Established by mutation in round one on the listener; the same trap is
     * open here and wider, because this one CAN return early.
     */
    foreach ($paths as $path) {
        if ($path === '/csp-probe-not-a-page') {
            expect($on[$path])->toBe(404, 'a missing page stopped being a 404');

            continue;
        }

        expect($on[$path])->toBeLessThan(400, "{$path} answered {$on[$path]} with the policy on");
        expect($off[$path])->toBeLessThan(400, "{$path} answered {$off[$path]} with the policy off");
    }

    /*
     * MUTATION NOTE. Make CspHeaders::handle() return response('', 403) for any
     * path and the absolute loop is red. It was green on the comparison alone,
     * which is why the loop is here.
     */
});

it('has no way to answer a request itself', function () {
    /*
     * The structural half of the same promise, and the one that survives a
     * later lane. handle() may call $next and return what it gives back, and
     * may do nothing else that could become an answer.
     */
    $code = cspCode(app_path('Services/Security/CspHeaders.php'));

    foreach ([
        'abort(', 'response(', 'setStatusCode', 'redirect(', 'RedirectResponse',
        'JsonResponse', '->send(', 'terminate(',
    ] as $forbidden) {
        expect(str_contains($code, $forbidden))->toBeFalse(
            "CspHeaders names {$forbidden}: this round reports, and the gate is a later round"
        );
    }

    expect(substr_count($code, 'return $response;'))->toBe(1)
        ->and($code)->toContain('$next($request)');

    /*
     * MUTATION NOTE. Add `if ($request->path() === 'cart') { abort(403); }` to
     * handle() — the first line any gate grows — and this is red without
     * running a single request.
     */
});

/* ═════════════════════════════════ 4. a violation lands on the screen ═══ */

it('records a violation with the directive, the thing and the page, and no actor', function () {
    cspRegisterRoute();
    app(SecurityModule::class)->save(['csp_on' => true]);

    /*
     * SIGNED IN WHILE IT HAPPENS, which is the case that matters. The owner
     * browsing his own shop with an admin session open must not have his email
     * written into the "by" column of a row a stranger's browser caused — and
     * SecurityModule::record() fills the actor from the admin guard by default,
     * so this is a real trap and not a hypothetical one.
     */
    $admin = AdminUser::create([
        'name' => 'Owner', 'email' => 'csp-actor-'.uniqid().'@example.test',
        'password' => 'lane-c-password', 'role' => 'owner',
    ]);

    $this->actingAs($admin, 'admin');

    cspPost(cspReport())->assertNoContent();

    $row = AuditEvent::query()->where('event', CspViolations::EVENT)->first();

    expect($row)->not->toBeNull()
        ->and($row->subject)->toBe('script-src-elem')
        ->and($row->before)->toBe('inline')
        ->and($row->after)->toBe('window.KBB = {"cart":1}')
        // The PAGE it happened on, not the endpoint it was posted to, and
        // without the query string the shopper typed into the search box.
        ->and($row->path)->toBe('/shop')
        ->and($row->summary)->toContain('would have blocked script-src-elem')
        // No actor, in all three columns.
        ->and($row->actor_id)->toBeNull()
        ->and($row->actor_label)->toBeNull()
        ->and($row->actor_role)->toBeNull()
        // And the address IS kept: it is the evidence, unlike on an integrity
        // finding where there is no visitor at all.
        ->and($row->ip)->not->toBeNull();

    // It is on the screen's own list, and NOT among the administrative changes.
    $report = app(SecurityModule::class)->report();

    expect($report['csp_rows'])->toHaveCount(1)
        ->and($report['counts']['violations'])->toBe(1)
        ->and($report['counts']['changed'])->toBe(0)
        ->and(array_column($report['changes'], 'event'))->not->toContain(CspViolations::EVENT);

    /*
     * MUTATION NOTE. Drop `'no_actor' => true` from CspViolations::record() and
     * the three actor expectations are red. Drop E_CSP from report()'s
     * whereNotIn and `changed` becomes 1.
     */
});

it('collapses the same violation onto one row with a count', function () {
    cspRegisterRoute();
    app(SecurityModule::class)->save(['csp_on' => true]);

    /*
     * THE DEFECT WITHOUT IT. A violation arrives on EVERY page view of a page
     * whose inline script the policy does not allow — which on this shop is
     * every page. One row per visitor per inline block makes the screen
     * unreadable within an hour of switching the policy on, long before any
     * ceiling catches it.
     */
    for ($i = 0; $i < 6; $i++) {
        cspPost(cspReport())->assertNoContent();
    }

    $rows = AuditEvent::query()->where('event', CspViolations::EVENT)->get();

    expect($rows)->toHaveCount(1)
        ->and((int) $rows[0]->hits)->toBe(6);

    // A different directive on the same page is a different finding.
    cspPost(cspReport(['effective-directive' => 'style-src-attr', 'blocked-uri' => 'inline']))
        ->assertNoContent();

    expect(AuditEvent::query()->where('event', CspViolations::EVENT)->count())->toBe(2);

    /*
     * MUTATION NOTE. Delete the Cache::get/UPDATE branch in
     * CspViolations::record() and the first expectation reads 6 rows, not 1.
     */
});

it('cannot push the audit trail out of the table however many are posted', function () {
    cspRegisterRoute();

    $module = app(SecurityModule::class);
    $module->save(['csp_on' => true, 'csp_rows' => 20, 'csp_window' => 300]);

    /*
     * THE DEFECT THIS EXISTS FOR, and it is the sharpest thing in this round.
     *
     * `audit_events` already had a ceiling: SecurityModule::max_rows, which
     * deletes the OLDEST rows when the table passes it. That is the right rule
     * for a table only signed-in admins and the shop's own throttle can write
     * to. Put an unauthenticated stranger into the same table and the ceiling
     * becomes a weapon — enough posted violations and the module deletes the
     * owner's sign-in records, setting changes and package installs to make
     * room, on the flooder's schedule.
     */
    $admin = AdminUser::create([
        'name' => 'Owner', 'email' => 'csp-flood-'.uniqid().'@example.test',
        'password' => 'lane-c-password', 'role' => 'owner',
    ]);

    $this->actingAs($admin, 'admin');

    $module->record('setting.changed', 'The row that must survive a flood', ['subject' => 'sec_probe']);

    $keeper = AuditEvent::query()->where('subject', 'sec_probe')->firstOrFail()->getKey();

    /*
     * THE THROTTLE IS OFF FOR THIS ONE, deliberately. It is the first of the
     * five bounds and it has a test of its own below; leaving it on here would
     * mean the throttle did the bounding and the ceiling was never exercised —
     * which is what the first run of this test actually measured, a 429 on the
     * sixty-first post. The question here is what happens when the throttle is
     * not enough, which is the case a botnet is.
     */
    $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

    // Distinct violations, so the collapse cannot do the bounding for us.
    for ($i = 0; $i < 90; $i++) {
        cspPost(cspReport([
            'blocked-uri' => 'https://evil.test/'.$i.'.js',
            'document-uri' => 'https://shop.test/p/'.$i,
        ]))->assertNoContent();
    }

    expect(AuditEvent::query()->whereIn('event', SecurityModule::CSP)->count())
        ->toBeLessThanOrEqual(20, 'violations passed their own ceiling');

    expect(AuditEvent::query()->whereKey($keeper)->exists())->toBeTrue(
        'a flood of violations deleted an administrative row'
    );

    /*
     * MUTATION NOTE. Delete the $this->enforceCspCap() call in
     * CspViolations::record() and the first expectation reads 90. Remove the
     * `where('event', self::EVENT)` from either query in enforceCspCap() and
     * the second is red — the sweep starts eating the trail it was written to
     * protect.
     */
});

/* ══════════════════════════════════ 5. the body is somebody else's ═══ */

it('reads four allowlisted fields out of a hostile body and clamps every one', function () {
    cspRegisterRoute();
    app(SecurityModule::class)->save(['csp_on' => true]);

    /*
     * THE DEFECT. This endpoint is unauthenticated by necessity — a browser has
     * no session and the spec gives it no signature — so the body is exactly
     * the `/api/*` surface CLAUDE.md warns about, with the same answer: an
     * explicit allowlist, never the model, never the payload.
     */
    cspPost(cspReport([
        'effective-directive' => '<script>alert(1)</script>',
        'violated-directive' => '<script>alert(1)</script>',
        'blocked-uri' => 'https://evil.test/a.js?token='.str_repeat('A', 4000),
        'document-uri' => "https://shop.test/product/x?\x00\x07secret=1",
        'script-sample' => "line one\nline two".str_repeat('B', 500),
        'nasty' => ['deeply' => ['nested' => 'ignored']],
    ]))->assertNoContent();

    $row = AuditEvent::query()->where('event', CspViolations::EVENT)->firstOrFail();

    expect($row->subject)->toBe('unknown', 'a directive off the allowlist was stored as itself')
        // The query string goes, which is both a storage bound and what stops a
        // per-request cache-buster defeating the collapse entirely.
        ->and($row->before)->toBe('https://evil.test/a.js')
        ->and(strlen((string) $row->before))->toBeLessThanOrEqual(CspViolations::URI_CAP)
        ->and($row->path)->toBe('/product/x')
        ->and(mb_strlen((string) $row->after))->toBeLessThanOrEqual(CspViolations::SAMPLE_CAP)
        // No control characters reach a row, in any column.
        ->and(preg_match('/[\x00-\x1F\x7F]/', (string) $row->after))->toBe(0)
        ->and(preg_match('/[\x00-\x1F\x7F]/', (string) $row->path))->toBe(0);

    /*
     * AND A BODY TOO BIG IS DROPPED WHOLE rather than truncated: a truncated
     * JSON document does not parse, so truncating would move the work to
     * json_decode and store nothing anyway.
     */
    $before = AuditEvent::query()->where('event', CspViolations::EVENT)->count();

    cspPost(cspReport(['script-sample' => str_repeat('C', CspViolations::MAX_BODY)]))
        ->assertNoContent();

    expect(AuditEvent::query()->where('event', CspViolations::EVENT)->count())->toBe($before);

    /*
     * MUTATION NOTE. Replace the in_array against DIRECTIVES with the raw
     * value and the first expectation stores the script tag. Drop the
     * parse_url in uri() and the blocked URI arrives 4,000 characters long.
     */
});

it('answers the same 204 whatever it did with the report', function () {
    cspRegisterRoute();

    /*
     * THE ORACLE THIS CLOSES. A caller who can tell "stored" from "dropped"
     * learns whether the shop is collecting, what shape it accepts, and — by
     * repeating until the answer changes — where the row ceiling sits. Same
     * rule Api\QuizController::expertRequest follows for a forged token: one
     * answer, whatever happened.
     */
    app(SecurityModule::class)->save(['csp_on' => true]);
    cspPost(cspReport())->assertNoContent()->assertContent('');

    // Malformed.
    $bad = test()->call('POST', Url::base().'/api/csp-report', [], [], [],
        ['CONTENT_TYPE' => 'application/csp-report'], 'not json at all');
    $bad->assertNoContent()->assertContent('');

    // Empty.
    test()->call('POST', Url::base().'/api/csp-report', [], [], [],
        ['CONTENT_TYPE' => 'application/csp-report'], '')
        ->assertNoContent()->assertContent('');

    // Switch off — nothing is stored, and the caller cannot tell.
    app(SecurityModule::class)->save(['csp_on' => false]);
    $countBefore = AuditEvent::query()->where('event', CspViolations::EVENT)->count();
    cspPost(cspReport(['blocked-uri' => 'https://other.test/x.js']))->assertNoContent()->assertContent('');

    expect(AuditEvent::query()->where('event', CspViolations::EVENT)->count())->toBe($countBefore);

    /*
     * MUTATION NOTE. Return a 422 with a message from CspReportController when
     * parse() gives null and the malformed and empty cases are red.
     */
});

it('reads the Reporting API shape as well, and only its first report', function () {
    cspRegisterRoute();
    app(SecurityModule::class)->save(['csp_on' => true]);

    /*
     * Not what ContentSecurityPolicy sends today — it sends `report-uri`, and
     * says why — but a browser that decides to post `application/reports+json`
     * must not leave the screen silently empty. And a batch must not be a way
     * to write many rows from one throttled request.
     */
    test()->call('POST', Url::base().'/api/csp-report', [], [], [],
        ['CONTENT_TYPE' => 'application/reports+json'],
        (string) json_encode([
            ['type' => 'csp-violation', 'body' => [
                'effectiveDirective' => 'img-src',
                'blockedURL' => 'https://cdn.test/a.png',
                'documentURL' => 'https://shop.test/cart',
                'sample' => '',
            ]],
            ['type' => 'csp-violation', 'body' => [
                'effectiveDirective' => 'font-src',
                'blockedURL' => 'https://cdn.test/b.woff2',
                'documentURL' => 'https://shop.test/cart',
            ]],
        ])
    )->assertNoContent();

    $rows = AuditEvent::query()->where('event', CspViolations::EVENT)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->subject)->toBe('img-src')
        ->and($rows[0]->before)->toBe('https://cdn.test/a.png')
        ->and($rows[0]->path)->toBe('/cart');
});

it('turns a flood away at the route before any of it reaches a row', function () {
    cspRegisterRoute();
    app(SecurityModule::class)->save(['csp_on' => true]);

    /*
     * THE FIRST OF THE FIVE BOUNDS, and the one that keeps the other four
     * cheap. 60 a minute per address is far above what a real browser sends
     * for a page view — Chromium collapses identical reports per document —
     * and far below what it would take to make the row ceiling work hard.
     *
     * Not a hypothetical: the ceiling test above was written without this in
     * mind and its sixty-first post came back 429, which is how this test came
     * to exist and why that one now turns the throttle off by name.
     */
    $last = null;

    for ($i = 0; $i < 61; $i++) {
        $last = cspPost(cspReport(['blocked-uri' => 'https://evil.test/'.$i.'.js']));
    }

    expect($last->getStatusCode())->toBe(429, 'the report endpoint has no throttle on it');

    /*
     * AND THE SHED REPORTS DO NOT READ AS AN ATTACK. This is the defect the
     * first screenshot of this card found, and it is the reason the shed event
     * exists at all.
     *
     * One view of the home page makes a real Chromium post 158 violation
     * reports; the throttle allows 60 a minute; and SecurityModule's
     * RequestHandled listener recorded every shed one as a rate-limit trip. The
     * verdict line at the top of Store → Security therefore read "Worth a look:
     * 687 requests refused as too many in the last 24 hours" — every one of
     * them this module's own endpoint answering this module's own policy — and
     * the owner would have read that as somebody attacking his shop the first
     * time he switched the policy on.
     *
     * Neither half was wrong alone, which is why only running it found it.
     */
    $report = app(SecurityModule::class)->report();

    expect($report['counts']['tripped'])->toBe(0,
        'the endpoint shedding its own reports is being counted as the shop being hammered'
    );
    expect($report['trips'])->toBe([],
        'a shed violation report is in the "requests refused as too many" list'
    );
    expect($report['csp']['shed'])->toBeGreaterThan(0,
        'the reports that were turned away are not reported anywhere at all'
    );
    // And it is not filed as administrative work either.
    expect($report['counts']['changed'])->toBe(0);

    /*
     * MUTATION NOTE. Delete the $isReport branch in
     * SecurityModule::recordRateLimitTrip() — one line — and `tripped` reads in
     * the hundreds, `trips` fills up and the verdict changes to "worth a look".
     * Delete `->middleware('throttle:60,1')` from routes/security-csp.php and
     * the 429 expectation is red.
     */
});

/* ═══════════════════════════════════════════════ 6. what it costs ═══ */

it('reads one setting for the header and asks the database for nothing', function () {
    cspRegisterRoute();

    /*
     * THE DEFECT IT WOULD HAVE HAD. SecurityModule::get() used to be
     * `$this->all()[$key]`, which walks the whole schema and asks
     * SettingsService for every key in it — invisible while every caller was an
     * admin screen, and twenty-odd cached reads per storefront response the
     * moment this middleware started asking for one switch on every page.
     *
     * Same answer, key for key, which is the half that has to be pinned or the
     * two drift.
     */
    $module = app(SecurityModule::class);
    $all = $module->all();

    foreach (array_keys(SecurityModule::SCHEMA) as $key) {
        expect($module->get($key))->toBe($all[$key], "get({$key}) disagrees with all()");
    }

    /*
     * EACH WALK IS WARMED FIRST, and the first run of this test is why.
     * Measured cold against warm it read 44 queries against 2 — which is the
     * homepage filling `kbb.settings` and every other rememberForever cache on
     * its first request in the process, not the cost of a header. Saving a
     * setting also flushes that cache, so the warm-up has to come AFTER the
     * save rather than once at the top.
     */
    $measure = function () {
        $this->get('/')->assertOk();   // warm the caches this page fills

        \Illuminate\Support\Facades\DB::enableQueryLog();
        \Illuminate\Support\Facades\DB::flushQueryLog();
        $this->get('/')->assertOk();
        $n = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        return $n;
    };

    $module->save(['csp_on' => false]);
    $without = $measure();

    $module->save(['csp_on' => true]);
    $withPolicy = $measure();

    expect($withPolicy)->toBe($without,
        "the policy header cost {$withPolicy} queries against {$without}"
    );

    /*
     * MUTATION NOTE. Put `return $this->all()[$key] ?? null;` back in
     * SecurityModule::get() and the loop still passes — it is the same answer —
     * but the cost assertion is the one that notices, and the reason the
     * change was made.
     */
});

it('draws the policy and its violations on the Security screen', function () {
    /*
     * The owner has no shell, so this screen is the only place he can read what
     * his own shop is telling browsers. A policy the code sends and the screen
     * does not print is a policy nobody can check.
     */
    $screen = (string) file_get_contents(resource_path('views/admin/partials/security-screen.blade.php'));

    expect($screen)->toContain('function cspHTML(')
        ->toContain('report.csp_rows')
        // Everything drawn from a report row is escaped. The values in these
        // rows came from a stranger's browser, which is exactly the case
        // reviews.author_email already stands for on this project.
        ->toContain('esc(c.policy)')
        ->toContain('esc(c.report_uri)')
        // Nothing on this screen may measure layout.
        ->not->toContain('getBoundingClientRect')
        ->not->toContain('offsetWidth');

    // And the report actually carries what the card draws.
    $report = app(SecurityModule::class)->report();

    expect($report)->toHaveKeys(['csp', 'csp_rows'])
        ->and($report['csp']['header'])->toBe(ContentSecurityPolicy::HEADER)
        ->and($report['csp']['on'])->toBeFalse()
        ->and($report['csp']['policy'])->toContain('report-uri');
});
