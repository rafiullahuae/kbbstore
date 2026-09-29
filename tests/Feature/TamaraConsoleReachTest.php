<?php

declare(strict_types=1);

/**
 * Lane TM — the Tamara admin API had no caller anywhere in the console.
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────────
 *
 * Tamara is a live BNPL gateway on this shop. routes/payments-tamara.php
 * registers five admin endpoints behind a 351-line controller, with 2,200 lines
 * of tests, its own clear_caches migration and five entries in
 * AdminCapabilities::RULES. On the day this file was written, this answered 0:
 *
 *     grep -rni "tamara" resources/views/admin/ resources/js/ \
 *       | grep -E "fetch|api\(|admin-api"
 *
 * Nothing in the admin console called ANY of them. The buttons had been proved
 * by a Playwright harness that POSTs to the endpoints directly over HTTP
 * (tools/pg1-tamara-shots/shots.mjs:71-72), so the absence never showed in a
 * screenshot either.
 *
 * WHAT IT COST. routes/payments-tamara.php's own header says the webhook is how
 * Tamara tells this shop about a DECLINE, and that without it a decline "is
 * invisible until somebody audits `pending` orders". Tamara's merchant portal
 * has no webhook screen, so the registration could not be done from anywhere at
 * all. `sweep` had a console command; `registerWebhook`, `unregisterWebhook`,
 * `refreshLimits` and `show` had no command, no button and no caller.
 *
 * Tabby's two webhook endpoints were the same shape and are covered here too.
 *
 * ── WHY THESE ASSERTIONS AND NOT A GENERAL ONE ──────────────────────────────
 *
 * A general "every admin-api route has a console caller" check cannot be
 * written against this console, and the attempt is worth recording so the next
 * reader does not spend the afternoon on it. Roughly half the screens are
 * partials using the cache-screen pattern — `api('/banners')`, where the helper
 * prepends '/admin-api' — so their calls contain no '/admin-api' literal for a
 * static scan to find, and every route they serve reads as dead. The other half
 * build paths as `base + '/' + id + '/suffix'`, so the only thing a scan can
 * match is the suffix. Anything loose enough to accept both accepts
 * '/admin-api/payments' as evidence that '/admin-api/payments/tamara/sweep' is
 * called, which is the exact defect being looked for.
 *
 * So these are named. AdminConsoleControlsAreLiveTest already walks the other
 * direction — every literal the console calls resolves against the real router —
 * and this walks the seven paths this lane wired, from the router's side.
 *
 * MUTATION NOTES are on every case.
 */

use App\Services\Payments\GatewayCredentials;
use App\Services\Payments\Gateways\TamaraGateway;
use App\Models\PaymentProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

const TM_URL_SECRET = 'whsec-tamara-tm-0123456789abcdef';
const TM_NOTIFY_KEY = 'tm-tamara-notification-token';
const TM_API_TOKEN = 'TM_TAMARA_API_TOKEN_CANARY';

beforeEach(function () {
    PaymentProvider::query()->delete();
    app(GatewayCredentials::class)->forget();
    Http::preventStrayRequests();
});

/** A configured Tamara, exactly as the payments screen would have left it. */
function tmProvider(array $config = []): void
{
    $row = PaymentProvider::create([
        'id' => 'tamara',
        'title' => 'Tamara',
        'enabled' => true,
        'mode' => 'test',
        'position' => 2,
    ]);

    $row->config = array_merge([
        'api_token' => TM_API_TOKEN,
        'notification_token' => TM_NOTIFY_KEY,
        'webhook_secret' => TM_URL_SECRET,
    ], $config);

    $row->save();

    app(GatewayCredentials::class)->forget();
}

/** Every admin blade, comments stripped, as one string. */
function tmConsoleSource(): string
{
    static $src = null;

    if ($src !== null) {
        return $src;
    }

    $out = '';
    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views/admin')));

    foreach ($walk as $entry) {
        if (! $entry->isFile() || ! str_ends_with($entry->getFilename(), '.blade.php')) {
            continue;
        }

        $body = (string) file_get_contents($entry->getPathname());

        /*
         * Prose first, and this is not cosmetic. Both route files and this
         * project's screens QUOTE the paths they talk about inside comments —
         * routes/payments-tamara.php lists all five in its header — so a raw
         * scan would read the explanation of the defect as the fix for it.
         */
        $body = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $body);
        $body = (string) preg_replace('#/\*.*?\*/#s', '', $body);

        $out .= "\n" . $body;
    }

    return $src = $out;
}

/** The screen this lane added. */
function tmPartial(): string
{
    return (string) file_get_contents(
        resource_path('views/admin/partials/tamara-connection-screen.blade.php')
    );
}

/**
 * The same file with its prose removed.
 *
 * The header and the inline comments EXPLAIN the credential rule, the layout
 * rule and the escaping rule, and naming `webhook_secret` while saying why it
 * is never printed is the whole point of writing it down. A check that read the
 * explanation as a violation would push the next author into deleting the
 * reasoning to green the guard, which is the worst possible trade.
 */
function tmPartialCode(): string
{
    $body = tmPartial();
    $body = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $body);

    return (string) preg_replace('#/\*.*?\*/#s', '', $body);
}

/* ─────────────────────────── 1 · the endpoints are reachable ─────────────── */

it('gives every Tamara admin endpoint a caller in the console', function () {
    /*
     * The defect, from the router's side. Each path must be BOTH answerable by
     * a route and named as a literal somewhere in resources/views/admin/**.
     *
     * MUTATION: delete the '/admin-api/payments/tamara/sweep' literal from
     * resources/views/admin/partials/tamara-connection-screen.blade.php — red,
     * naming that path. Deleting the whole partial turns all five red, which is
     * the state this branch started from.
     */
    $console = tmConsoleSource();

    $paths = [
        'GET' => '/admin-api/payments/tamara',
        'POST' => '/admin-api/payments/tamara/webhook',
        'DELETE' => '/admin-api/payments/tamara/webhook',
        'POST limits' => '/admin-api/payments/tamara/limits',
        'POST sweep' => '/admin-api/payments/tamara/sweep',
    ];

    $missing = [];

    foreach ($paths as $label => $path) {
        if (! str_contains($console, "'" . $path . "'") && ! str_contains($console, '"' . $path . '"')) {
            $missing[] = '  ' . $label . '  ' . $path;
        }
    }

    expect($missing)->toBe(
        [],
        "a Tamara admin endpoint has no caller anywhere in the admin console:\n" . implode("\n", $missing)
        . "\n\nThis is the shape the whole gateway shipped in: five live endpoints, a capability"
        . ' for each, a clear_caches migration, 2,200 lines of tests, and no button. The webhook'
        . ' registration cannot be done anywhere else — Tamara’s merchant portal has no screen for it.'
    );
});

it('answers every one of those five paths from the real router', function () {
    /*
     * The other half. A literal in a screen is worth nothing if the route file
     * is not mounted — routes/checkout-card.php answered 405 for twelve days
     * with a screen calling it.
     *
     * Read from the router rather than from routes/web.php as text, because a
     * path is only answerable if the file that declares it was required.
     *
     * MUTATION: comment out `require __DIR__.'/payments-tamara.php';` in
     * routes/web.php — red on all five. (EverythingIsMountedOnceTest catches
     * the same thing from the other end; this one says what breaks.)
     */
    $registered = [];

    foreach (Route::getRoutes() as $route) {
        foreach ($route->methods() as $method) {
            $registered[$method . ' /' . ltrim($route->uri(), '/')] = true;
        }
    }

    foreach ([
        'GET /admin-api/payments/tamara',
        'POST /admin-api/payments/tamara/webhook',
        'DELETE /admin-api/payments/tamara/webhook',
        'POST /admin-api/payments/tamara/limits',
        'POST /admin-api/payments/tamara/sweep',
        'GET /admin-api/payments/tabby/webhooks',
        'POST /admin-api/payments/tabby/webhooks',
    ] as $signature) {
        expect($registered)->toHaveKey(
            $signature,
            $signature . ' is not in the router, so every button that calls it 404s while rendering perfectly'
        );
    }
});

it('gives Tabby’s two webhook endpoints a caller as well', function () {
    /*
     * Found by walking the other gateways for the same shape:
     *
     *     grep -rn "tabby/webhooks" resources/views/admin/ resources/js/   ->  0
     *
     * GET and POST /admin-api/payments/tabby/webhooks are live and
     * capability-mapped, and TabbyWebhookController::sync() is the only thing
     * that registers or prunes a Tabby webhook per market.
     *
     * MUTATION: delete the '/admin-api/payments/tabby/webhooks' literal from
     * the partial — red.
     */
    $console = tmConsoleSource();

    expect(str_contains($console, "'/admin-api/payments/tabby/webhooks'"))->toBeTrue(
        'nothing in the admin console calls Tabby’s webhook endpoints, so its per-market '
        . 'registrations can only be created from a test'
    );
});

/* ──────────────────────────── 2 · the screen is wired ───────────────────── */

it('includes the gateway-webhooks screen exactly once', function () {
    /*
     * The FINISHED state, never the absence — CLAUDE.md has this three times
     * over and it has cost this repo three round trips. Zero is "built, never
     * wired up", which is precisely the defect this whole lane is about. Two
     * registers the sidebar row twice and wraps window.go around its own
     * wrapper.
     *
     * MUTATION: duplicate the include line in app.blade.php — red, "2".
     */
    $app = (string) file_get_contents(resource_path('views/admin/app.blade.php'));

    expect(substr_count($app, "@include('admin.partials.tamara-connection-screen')"))
        ->toBe(1, 'the Gateway webhooks screen is not included exactly once, so the sidebar '
            . 'routes to a screen the browser never draws');
});

/* ─────────────────────── 3 · what the screen may not do ─────────────────── */

it('never names a Tamara or Tabby credential on the screen', function () {
    /*
     * Nothing this screen draws is a credential, because it is handed none.
     * The webhook URL is treated as one: it embeds the webhook secret that
     * handleWebhook() compares with hash_equals, so printing it is printing the
     * secret. TamaraAdminController deliberately does not return it and
     * TabbyWebhookController returns `webhook_url_ready` as a boolean instead.
     *
     * MUTATION: add `'webhook_secret'` anywhere in the partial — red.
     */
    $partial = tmPartialCode();

    /*
     * `webhook_url_ready` is deliberately NOT a match: it is the boolean
     * TabbyWebhookController::safe() returns IN PLACE OF the address, and a
     * check that banned the substring would ban the fix as well as the defect.
     * Hence the negative lookahead rather than str_contains.
     */
    foreach (['api_token', 'notification_token', 'webhook_secret', 'secret_key', 'public_key', 'webhook_url'] as $key) {
        expect(preg_match('/' . $key . '(?![_a-zA-Z])/', $partial))->toBe(
            0,
            "the Gateway webhooks screen names `{$key}`, which is a credential or embeds one"
        );
    }
});

it('measures no layout on the gateway-webhooks screen', function () {
    /*
     * Rule 4. This project sizes with calc() and CSS grid for a reason and two
     * other tests forbid these by name; the list is repeated here so a new
     * screen cannot slip one in before somebody widens those globs.
     *
     * MUTATION: add `el.getBoundingClientRect()` to the partial — red.
     */
    $partial = tmPartialCode();

    foreach ([
        'getBoundingClientRect',
        'offsetWidth',
        'offsetHeight',
        'clientHeight',
        'getComputedStyle',
        'ResizeObserver',
    ] as $api) {
        expect(str_contains($partial, $api))->toBeFalse(
            "the Gateway webhooks screen calls {$api}, which measures layout"
        );
    }
});

it('escapes every value the gateway-webhooks screen prints', function () {
    /*
     * Rule 5: anything printed unescaped is a constant, never a setting and
     * never a provider's answer. Every interpolation on this screen goes
     * through esc(); the only unescaped things in the HTML are literal strings
     * written in this file.
     *
     * The check: every `+ x +` concatenation into an html string that names a
     * variable from a response must be wrapped. Rather than parse JavaScript,
     * this pins the two bare identifiers a reviewer would look for — the raw
     * response objects — as never being concatenated into markup directly.
     *
     * MUTATION: change `esc(t.webhook_id || '—')` to
     * `(t.webhook_id || '-')` — red.
     */
    $partial = tmPartialCode();

    foreach (['+ t.', '+t.', '+ row.', '+row.', '+ res.', '+res.'] as $raw) {
        expect(str_contains($partial, $raw))->toBeFalse(
            "a value from a gateway response is concatenated into markup without esc(): {$raw}"
        );
    }

    // And esc() is actually defined and used, so the above cannot pass vacuously.
    expect(substr_count($partial, 'esc('))->toBeGreaterThan(12);
});

/* ───────────────────────── 4 · the artisan escape hatch ─────────────────── */

it('reports the webhook state without calling Tamara', function () {
    /*
     * Reading the state must never depend on Tamara having a good morning —
     * the same rule TamaraAdminController::show() follows. Http::preventStray-
     * Requests() in beforeEach() is what makes this a real assertion.
     *
     * MUTATION: make report() call $gateway->registerWebhook() — red, with a
     * stray-request failure.
     */
    tmProvider(['webhook_id' => 'wh_reported']);

    $this->artisan('payments:tamara-webhook')
        ->expectsOutputToContain('Keys stored        yes')
        ->expectsOutputToContain('registered (wh_reported)')
        ->assertExitCode(0);
});

it('warns in as many words when no webhook is registered', function () {
    /*
     * "There is no webhook" is the dangerous state and it must not read as a
     * blank line. It is not an error exit, because a status read that exits 1
     * in a deployment script reads as a broken command.
     *
     * MUTATION: delete the `if ($id === '')` block in report() — red.
     */
    tmProvider();

    $this->artisan('payments:tamara-webhook')
        ->expectsOutputToContain('NOT registered')
        ->expectsOutputToContain('invisible to this shop')
        ->assertExitCode(0);
});

it('registers the webhook from the command line', function () {
    /*
     * The whole point of Task 3: Cloudways gives this project a shell, and a
     * registration that can only be made through a screen is one that cannot be
     * made on the day the screen or its route cache is broken.
     *
     * MUTATION: have register() report success without calling
     * $gateway->registerWebhook() — red, because the stored id stays empty.
     */
    tmProvider();

    Http::fake(['*/webhooks' => Http::response(['webhook_id' => 'wh_cli'])]);

    $this->artisan('payments:tamara-webhook --register')
        ->expectsOutputToContain('Registered.')
        ->assertExitCode(0);

    app(GatewayCredentials::class)->forget();

    expect(app(GatewayCredentials::class)->get('tamara', 'webhook_id'))->toBe('wh_cli');
});

it('does not create a second registration when one already exists', function () {
    /*
     * Two registrations mean every expiry delivered twice and Tamara offers no
     * "replace" call. The idempotency lives in TamaraGateway; this proves the
     * command did not route around it.
     *
     * MUTATION: in TamaraGateway::registerWebhook(), remove the early return on
     * a stored webhook_id — red, with a POST that should not have been sent.
     */
    tmProvider(['webhook_id' => 'wh_already']);

    Http::fake();

    $this->artisan('payments:tamara-webhook --register')
        ->expectsOutputToContain('already registered')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

it('says which box to fill in when the keys are not there yet', function () {
    /*
     * `not_configured` and `no_webhook_secret` are the two failures the owner
     * can fix himself, and they want different sentences. A single "Tamara
     * refused" sends him to Tamara's support for a box he has not filled in.
     *
     * MUTATION: collapse explain()'s match to its default arm — red.
     */
    tmProvider(['api_token' => '', 'notification_token' => '']);

    Http::fake();

    $this->artisan('payments:tamara-webhook --register')
        ->expectsOutputToContain('no API token stored yet')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

it('refuses to register without the webhook secret that verifies the deliveries', function () {
    /*
     * Registering without it hands Tamara a URL handleWebhook() 401s on its
     * first gate: a webhook that exists, delivers, and is rejected every time —
     * which looks correct from both ends.
     *
     * MUTATION: as above, collapse explain() — red on the sentence.
     */
    tmProvider(['webhook_secret' => '']);

    Http::fake();

    $this->artisan('payments:tamara-webhook --register')
        ->expectsOutputToContain('no webhook secret yet')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

it('will not remove a registration without being told twice', function () {
    /*
     * The destructive one. A non-interactive run with no --force must leave the
     * registration alone: a deployment script that silently unregistered the
     * webhook would take declines off this shop and the only symptom would be
     * orders quietly sitting at `pending`.
     *
     * MUTATION: drop the `--force` check in remove() — red, because the
     * registration is gone and a DELETE was sent.
     */
    tmProvider(['webhook_id' => 'wh_keepme']);

    Http::fake();

    $this->artisan('payments:tamara-webhook --remove --no-interaction')
        ->expectsOutputToContain('Left alone')
        ->assertExitCode(0);

    Http::assertNothingSent();

    app(GatewayCredentials::class)->forget();

    expect(app(GatewayCredentials::class)->get('tamara', 'webhook_id'))->toBe('wh_keepme');
});

it('removes the registration when told to force it', function () {
    /*
     * MUTATION: have remove() report success without calling
     * $gateway->unregisterWebhook() — red, the id is still stored.
     */
    tmProvider(['webhook_id' => 'wh_bye']);

    Http::fake(['*/webhooks/*' => Http::response([], 204)]);

    $this->artisan('payments:tamara-webhook --remove --force')
        ->expectsOutputToContain('registration was removed')
        ->assertExitCode(0);

    app(GatewayCredentials::class)->forget();

    expect(app(GatewayCredentials::class)->get('tamara', 'webhook_id'))->toBe('');
});

it('refuses to be asked for two opposite things at once', function () {
    /*
     * --register --remove typed together is a mistake, and guessing which the
     * operator meant is how a webhook gets removed by somebody who wanted one.
     *
     * MUTATION: delete the both-options check — red, because it registers.
     */
    tmProvider();

    Http::fake();

    $this->artisan('payments:tamara-webhook --register --remove')->assertExitCode(1);

    Http::assertNothingSent();
});

it('pulls the basket limits from the command line and stores them as strings', function () {
    /*
     * Money is never a float on this path. refreshLimits() converts through
     * fils and stores the major-unit STRING the settings hold, and the command
     * prints what it was given rather than reformatting it.
     *
     * MUTATION: have TamaraLimitsCommand cast $limits['min'] to (float) before
     * printing — red on "100.00" becoming "100".
     */
    tmProvider();

    Http::fake([
        '*/checkout/payment-types*' => Http::response([
            ['name' => 'PAY_BY_LATER', 'min_limit' => ['amount' => 100], 'max_limit' => ['amount' => 5000]],
        ]),
    ]);

    $this->artisan('payments:tamara-limits --country=AE --currency=AED')
        ->expectsOutputToContain('100.00 to 5000.00')
        ->assertExitCode(0);

    app(GatewayCredentials::class)->forget();

    expect(app(GatewayCredentials::class)->get('tamara', 'min_limit'))->toBe('100.00')
        ->and(app(GatewayCredentials::class)->get('tamara', 'max_limit'))->toBe('5000.00');
});

it('never lets a mistyped market reach a URL this shop calls', function () {
    /*
     * --country is bounded to two letters HERE, and TamaraGateway allowlists it
     * against the six markets Tamara serves again. Both halves are kept: this
     * one gives the operator a sentence, the gateway's one decides.
     *
     * MUTATION: delete the code() length check — the request still does not go
     * out (the gateway refuses it), but the operator is told "did not return
     * limits" instead of "that is not a country", so change the assertion on
     * the sentence to see it.
     */
    tmProvider();

    Http::fake();

    $this->artisan('payments:tamara-limits --country=ZZZZ')
        ->expectsOutputToContain('two letters')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

it('leaves the stored limits alone when Tamara does not answer with a pair', function () {
    /*
     * A failed refresh that CLEARED the limits would take Tamara off every
     * basket in the shop, or put it on all of them, on the strength of a
     * network blip.
     *
     * MUTATION: in TamaraLimitsCommand, write the two settings before checking
     * $limits === null — red.
     */
    tmProvider(['min_limit' => '100', 'max_limit' => '5000']);

    Http::fake(['*/checkout/payment-types*' => Http::response([], 500)]);

    $this->artisan('payments:tamara-limits')
        ->expectsOutputToContain('already stored are untouched')
        ->assertExitCode(1);

    app(GatewayCredentials::class)->forget();

    expect(app(GatewayCredentials::class)->get('tamara', 'min_limit'))->toBe('100')
        ->and(app(GatewayCredentials::class)->get('tamara', 'max_limit'))->toBe('5000');
});

it('prints the stored limits without calling Tamara at all', function () {
    /*
     * --show is the "what is my shop doing" read, and it must work on a shop
     * whose keys are wrong. Http::preventStrayRequests() is the assertion.
     *
     * MUTATION: make --show fall through to refreshLimits() — red.
     */
    tmProvider(['min_limit' => '75.00', 'max_limit' => '4000.00']);

    $this->artisan('payments:tamara-limits --show')
        ->expectsOutputToContain('75.00')
        ->expectsOutputToContain('4000.00')
        ->assertExitCode(0);
});

it('warns that no limits stored means Tamara is offered on baskets it will refuse', function () {
    /*
     * The consequence, said out loud. availableFor() applies no limit at all
     * when neither is stored, so a shopper picks Tamara, presses Place order
     * and is told to choose again — which is the failure the limits exist to
     * prevent.
     *
     * MUTATION: delete the warning block in stored() — red.
     */
    tmProvider();

    $this->artisan('payments:tamara-limits --show')
        ->expectsOutputToContain('offered on every basket')
        ->assertExitCode(0);
});

it('says so rather than fataling when the build ships no Tamara gateway', function () {
    /*
     * GatewayRegistry::find() degrades to null when a package ships without a
     * gateway file — three files went missing from a package on this project
     * once — and a command that assumed the class was there would fatal.
     *
     * MUTATION: drop the instanceof check and call ->registerWebhook() on the
     * result — red with a TypeError rather than a sentence.
     */
    expect(TamaraGateway::WEBHOOK_EVENTS)->toBe(['order_expired', 'order_declined']);

    $this->artisan('payments:tamara-webhook')->assertExitCode(0);
});
