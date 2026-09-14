<?php

/**
 * The SMTP password must not leave the server.
 *
 * Same shape, and the same reasoning, as PaymentSecretsTest: assert the absence
 * of one distinctive string everywhere it could surface, rather than the shape
 * of any single response. A failure here is a live mailbox password in a
 * browser cache, a proxy log or somebody's screen share, not a broken page.
 *
 * The specific trap this file pins: `GET /admin-api/settings` returns
 * `Setting::map()` -- the WHOLE settings table, with no allowlist of any kind.
 * Had the password been stored the way every other operator-set value here is
 * stored, it would be shipped to the admin bundle on every load of the settings
 * screen, by an endpoint this lane does not own and did not change.
 */

use App\Http\Controllers\Admin\MailApiController;
use App\Models\Setting;
use App\Services\Mail\MailCredentials;
use App\Services\Mail\MailSettings;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

/** Distinctive enough that finding it anywhere is unambiguous. */
const SMTP_PASSWORD_CANARY = 'KBBMAILLEAKCANARY-p4ssw0rd-0001';

beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
    app(MailCredentials::class)->forget();

    app(MailSettings::class)->save([
        'mail_transport' => 'smtp',
        'mail_host' => 'smtp.example.com',
        'mail_port' => '465',
        'mail_encryption' => 'ssl',
        'mail_username' => 'no-reply@example.com',
        'mail_password' => SMTP_PASSWORD_CANARY,
        'mail_from_address' => 'no-reply@example.com',
        'mail_from_name' => 'KBB',
    ]);

    Setting::flushMap();
});

it('stores the password nowhere in the settings table', function () {
    // The defence that survives a rewrite of any settings controller: if the
    // value is not in `settings`, no allowlist decision can leak it.
    $values = Setting::query()->pluck('value')->implode(' ');

    expect($values)->not->toContain(SMTP_PASSWORD_CANARY)
        ->and(Setting::query()->count())->toBeGreaterThan(0);   // the table is not simply empty
});

it('stores the password encrypted rather than as a readable column', function () {
    // Straight at the column, past the model's cast.
    $stored = DB::table('mail_credentials')->where('id', 'smtp')->value('config');

    expect($stored)->toBeString()
        ->and($stored)->not->toContain(SMTP_PASSWORD_CANARY);

    // And it really is the password that was stored, not nothing at all.
    expect(app(MailCredentials::class)->get('password'))->toBe(SMTP_PASSWORD_CANARY);
});

it('never returns the password from the mail admin screen', function () {
    $raw = app(MailApiController::class)->show()->getContent();

    expect($raw)->not->toContain(SMTP_PASSWORD_CANARY);
});

it('reports that a password is set without disclosing it', function () {
    $body = app(MailApiController::class)->show()->getData(true);

    $field = collect($body['fields'])->firstWhere('key', 'mail_password');

    expect($field['type'])->toBe('secret')
        // Empty, not masked. A mask still discloses the length.
        ->and($field['value'])->toBe('')
        ->and($field['has_value'])->toBeTrue();

    // A non-secret field is shown normally, so the screen is still usable.
    expect(collect($body['fields'])->firstWhere('key', 'mail_host')['value'])
        ->toBe('smtp.example.com');
});

it('never returns the password from the wholesale admin settings endpoint', function () {
    // This endpoint belongs to another lane and returns Setting::map() entire.
    // It is exactly why the password is not a setting.
    $raw = app(App\Http\Controllers\Admin\AdminController::class)->settings()->getContent();

    expect($raw)->not->toContain(SMTP_PASSWORD_CANARY);
});

it('does not leak the password through any public api endpoint', function () {
    foreach (['/api/settings', '/api/products', '/api/posts', '/api/reviews'] as $url) {
        $raw = $this->getJson($url)->getContent();

        expect($raw)->not->toContain(SMTP_PASSWORD_CANARY);
    }
});

it('does not echo the password back from a save', function () {
    $response = app(MailApiController::class)->save(new Illuminate\Http\Request([
        'settings' => ['mail_password' => SMTP_PASSWORD_CANARY, 'mail_host' => 'smtp.other.com'],
    ]));

    expect($response->getContent())->not->toContain(SMTP_PASSWORD_CANARY);
});

it('keeps every mail config key out of the public settings allowlist', function () {
    $allowed = (new ReflectionClass(App\Http\Controllers\Api\SettingController::class))
        ->getConstant('PUBLIC_KEYS');

    expect($allowed)->toBeArray();

    foreach (array_keys(MailSettings::SCHEMA) as $key) {
        expect($allowed)->not->toContain($key);
    }

    expect($allowed)->not->toContain(MailSettings::LAST_TEST_KEY);
});

it('treats a blank password on save as unchanged rather than a wipe', function () {
    // Exactly what the screen posts back when someone edits the host only: the
    // password box was rendered blank, so blank is what returns.
    app(MailApiController::class)->save(new Illuminate\Http\Request([
        'settings' => ['mail_password' => '', 'mail_host' => 'smtp.updated.com'],
    ]));

    app(MailCredentials::class)->forget();

    expect(app(MailCredentials::class)->get('password'))->toBe(SMTP_PASSWORD_CANARY)
        ->and(app(MailSettings::class)->get('mail_host'))->toBe('smtp.updated.com');
});

it('clears the stored password only when explicitly told to', function () {
    app(MailApiController::class)->save(new Illuminate\Http\Request([
        'settings' => ['mail_password' => '-'],
    ]));

    app(MailCredentials::class)->forget();

    expect(app(MailCredentials::class)->get('password'))->toBe('')
        ->and(app(MailSettings::class)->hasPassword())->toBeFalse();
});

it('refuses an unknown setting key rather than storing it', function () {
    $response = app(MailApiController::class)->save(new Illuminate\Http\Request([
        'settings' => ['mail_host' => 'smtp.new.com', 'admin_path' => 'oops'],
    ]));

    expect($response->getStatusCode())->toBe(422);

    // And nothing was written -- not even the valid half of the payload.
    expect(app(MailSettings::class)->get('mail_host'))->toBe('smtp.example.com')
        ->and(Setting::query()->where('key', 'admin_path')->value('value'))->not->toBe('oops');
});
