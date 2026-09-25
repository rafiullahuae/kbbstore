<?php

/**
 * Where the SMTP password can be read from — measured, not reasoned about.
 * Lane M4.
 *
 * ── WHY THIS IS NOT A SECOND COPY OF MailSecretsTest ────────────────────────
 *
 * That file asserts the absence of the password in the eight places somebody
 * thought of: the settings table, the mail screen, the wholesale settings
 * endpoint, four named `/api/*` routes, a save's own response. Every one of
 * those is a place a person listed, and the whole class of defect this is
 * guarding against is a place nobody listed.
 *
 * So this one does not list. It plants a real value and then walks EVERY route
 * this application answers a parameterless GET on — 178 of them, admin console
 * and storefront and public API together, with an owner session — and looks for
 * the bytes in the response. Plus the four stores the password could settle in
 * between requests: the settings table, the shop's own delivery log, the
 * Laravel log file, and the recorded fixtures this round added.
 *
 * ── WHAT IT FOUND WHEN IT WAS FIRST RUN, WHICH IS NOTHING ───────────────────
 *
 * Run against the parent revision, before a line of this round was written:
 * 178 routes probed, zero hits; zero in all(), lastTest(), Setting::map(), the
 * settings table, `mail_deliveries`, and storage/logs/laravel.log, with the
 * transport misconfigured on purpose so a real SMTP failure message was
 * produced and recorded. The credential does not reach anywhere it should not
 * today. That is a result rather than a non-event: this round's brief said a
 * live leak would be its headline and take priority over the migration, and
 * the way to find out was to look rather than to read the three redactors and
 * believe them.
 *
 * It is kept because the measurement is cheap and the class of defect is
 * permanent: the next endpoint added to this application is walked by this test
 * on the day it is added, with no list to remember to extend.
 *
 * ── WHY THE THREE SPELLINGS ─────────────────────────────────────────────────
 *
 * MailTester, MailLog and OrderMailer each strip the password from anything on
 * its way to a screen, a log or the settings table, and each strips the
 * literal, the URL-encoded and the base64 forms — because an SMTP AUTH failure
 * echoes the credential back and Symfony's transport exceptions quote the DSN,
 * and both have been seen carrying it. A sweep that looked only for the literal
 * would pass on a response that leaked the base64.
 */

use App\Models\AdminUser;
use App\Models\MailCredential;
use App\Models\Setting;
use App\Services\Mail\MailConfigurator;
use App\Services\Mail\MailCredentials;
use App\Services\Mail\MailSettings;
use App\Services\Mail\MailTester;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/** Distinctive enough that finding it anywhere is unambiguous. */
const M4_SURFACE_CANARY = 'KBBM4SURFACE-p4ssw0rd-QQ71';

/** The literal, and the two forms a transport error has been seen carrying. */
function m4Carries(mixed $blob): bool
{
    $blob = is_string($blob) ? $blob : json_encode($blob);

    if (! is_string($blob)) {
        return false;
    }

    foreach ([M4_SURFACE_CANARY, rawurlencode(M4_SURFACE_CANARY), base64_encode(M4_SURFACE_CANARY)] as $form) {
        if (str_contains($blob, $form)) {
            return true;
        }
    }

    return false;
}

beforeEach(function () {
    Setting::query()->delete();
    MailCredential::query()->delete();

    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
    app(MailCredentials::class)->forget();

    app(MailSettings::class)->save([
        'mail_transport' => 'smtp',
        // Resolves to nothing, so a real send really fails and a real transport
        // error message is produced rather than imagined.
        'mail_host' => '127.0.0.1',
        'mail_port' => '2525',
        'mail_encryption' => 'tls',
        'mail_username' => 'no-reply@example.com',
        'mail_password' => M4_SURFACE_CANARY,
        'mail_from_address' => 'no-reply@example.com',
        'mail_from_name' => 'KBB',
    ]);

    Setting::flushMap();
    SettingsService::forgetMemo();
    app(MailCredentials::class)->forget();
    app(MailConfigurator::class)->refresh();

    // It really is stored, so an empty sweep cannot be an empty store.
    expect(app(MailCredentials::class)->get('password'))->toBe(M4_SURFACE_CANARY);
});

it('does not hand the smtp password to any route this application answers', function () {
    $owner = AdminUser::create([
        'name' => 'Surface Owner',
        'email' => 'surface-owner@example.com',
        'password' => Hash::make('secret-secret'),
        'role' => 'owner',
    ]);

    test()->actingAs($owner, 'admin');

    $routes = collect(Route::getRoutes())
        ->filter(fn ($r) => in_array('GET', $r->methods(), true) && ! str_contains($r->uri(), '{'))
        ->map(fn ($r) => '/'.ltrim($r->uri(), '/'))
        ->unique()->values()->all();

    $hits = [];

    foreach ($routes as $uri) {
        try {
            $body = test()->get($uri)->getContent();
        } catch (\Throwable $e) {
            // A route that throws is still a surface: an APP_DEBUG error page
            // renders the message and the trace, and a trace on this host can
            // carry the transport config.
            $body = $e->getMessage().' '.$e->getTraceAsString();
        }

        if (m4Carries($body)) {
            $hits[] = $uri;
        }
    }

    expect($hits)->toBe([], "These routes returned the SMTP password:\n".implode("\n", $hits));

    /*
     * A guard on the guard. If the route collection is ever filtered down to
     * nothing — a renamed method on the Route object, say — the loop above
     * passes by doing nothing. 178 when written; the assertion is a floor
     * rather than an equality so that adding a route does not fail this.
     */
    expect(count($routes))->toBeGreaterThan(150, 'the route sweep stopped sweeping');
});

it('does not leave the smtp password anywhere a later request could read it', function () {
    /*
     * A failing send first, because that is the path that has historically
     * carried a credential: an AUTH failure echoes it and Symfony's transport
     * exception quotes the DSN. This one fails at the socket, which is the same
     * code path through MailTester::describe() and redact().
     */
    $result = app(MailTester::class)->send('somebody@example.com');

    expect($result['ok'])->toBeFalse()
        ->and(m4Carries($result))->toBeFalse('the test-send result carried the password');

    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();

    $stores = [
        'MailSettings::all()' => app(MailSettings::class)->all(),
        'MailSettings::lastTest()' => app(MailSettings::class)->lastTest(),
        'the settings table' => Setting::query()->get()->toArray(),
        'Setting::map()' => Setting::map(),
    ];

    if (Schema::hasTable('mail_deliveries')) {
        $stores['the mail_deliveries log'] = DB::table('mail_deliveries')->get()->toArray();
    }

    $log = storage_path('logs/laravel.log');

    if (is_file($log)) {
        $stores['storage/logs/laravel.log'] = file_get_contents($log);
    }

    foreach ($stores as $where => $blob) {
        expect(m4Carries($blob))->toBeFalse("the password reached {$where}");
    }

    // And the encrypted column really is ciphertext, read past the model's cast.
    $raw = DB::table('mail_credentials')->where('id', 'smtp')->value('config');

    expect($raw)->toBeString()
        ->and(m4Carries($raw))->toBeFalse('mail_credentials.config is not encrypted');
});

it('keeps every recorded fixture free of a credential', function () {
    /*
     * ── A FIXTURE IS A TRACKED FILE IN A PUBLIC REPOSITORY ──────────────────
     *
     * This round records the round trip of every field of Store → Mail,
     * including the password box, into
     * tests/Fixtures/module-mail-baseline.txt — and the whole payload of
     * /admin-api/mail into module-screen-payloads.json. A recorder that wrote
     * the answer rather than a presence flag would have committed a credential
     * to git, where removing it means rewriting history.
     *
     * So the corpus records `has=true` / `has=false` for a secret and never a
     * value, and this is the assertion that keeps it that way. It scans for the
     * corpus's OWN password inputs as well as this file's canary, because a
     * regression here would write whatever the recorder was driving.
     *
     * IT ALREADY FOUND ONE, which is why the recorder describes a secret's
     * input instead of quoting it. The first version of
     * SettingsCorpus::mailRows() wrote `json_encode($input)` for every type, so
     * the password corpus went into the tracked fixture verbatim — invented
     * strings, nothing disclosed, and exactly the shape that commits a real one
     * the day somebody re-records against a value copied off a screen.
     *
     * MUTATION ACTUALLY RUN: make SettingsCorpus::mailInput() return
     * `json_encode($input)` for a secret again, re-record, and this goes red
     * with
     *   tests/Fixtures/module-mail-baseline.txt carries a credential
     *   Failed asserting that true is false.
     */
    foreach ([
        'tests/Fixtures/module-mail-baseline.txt',
        'tests/Fixtures/module-screen-payloads.json',
    ] as $file) {
        $contents = file_get_contents(base_path($file));

        expect($contents)->toBeString()->not->toBeEmpty();

        foreach (['s3cret-one', M4_SURFACE_CANARY, str_repeat('p', 300)] as $password) {
            expect(str_contains($contents, $password))
                ->toBeFalse("{$file} carries a credential");
        }
    }
});
