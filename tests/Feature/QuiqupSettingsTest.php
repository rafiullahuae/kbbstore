<?php

declare(strict_types=1);

use App\Models\QuiqupConfig;
use App\Services\Quiqup\QuiqupSettings;
use App\Support\AdminCapabilities;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Support\FakeQuiqupClient;
use Tests\Support\QuiqupKit;
use Tests\Support\QuiqupRoutes;

/*
 * Store -> Quiqup -> Connection / Pickup & defaults / Statuses / Activity
 * (QUIQUP-PLAN §3.4, §3.5, §5.1). The failures each case prevents: the API key
 * reaching a browser or the settings table, a support account booking billed
 * couriers, a select storing a value it never offered, and a route that
 * nobody mapped being open to everyone.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    $this->fake = FakeQuiqupClient::install($this->app);
    QuiqupRoutes::wire($this->app);
});

it('says "Add your staging API key" until one is saved, and the screen says so too', function () {
    $this->actingAs(QuiqupKit::admin('owner'), 'admin');

    $this->getJson('/admin-api/quiqup-settings')->assertOk()
        ->assertJsonPath('configured', false)->assertJsonPath('env', 'sandbox');

    $screen = (string) file_get_contents(resource_path('views/admin/partials/quiqup-screen.blade.php'));
    expect($screen)->toContain('<b>Add your staging API key</b>')
        ->and(substr_count($screen, 'function ours()'))->toBe(1);
});

it('stores the key encrypted, off the settings table, and only ever returns its last four', function () {
    // Defect prevented: the courier key in a browser, in /api/settings, or
    // readable in a database dump.
    $this->actingAs(QuiqupKit::admin('owner'), 'admin');

    $r = $this->postJson('/admin-api/quiqup-settings', ['secrets_env' => 'sandbox', 'api_key' => 'qq_staging_SECRET_key_a1b2', 'webhook_secret' => 'whsec_SECRET_9z8y'])->assertOk();

    $json = $r->getContent();
    expect($json)->not->toContain('qq_staging_SECRET_key_a1b2')->not->toContain('whsec_SECRET_9z8y')
        ->and($r->json('envs.sandbox.api_key'))->toBe(['filled' => true, 'last4' => 'a1b2'])
        ->and($r->json('configured'))->toBeTrue()
        ->and($r->json('envs.sandbox.webhook_url'))->toContain('/api/shipping/quiqup/webhook/');

    $raw = (string) DB::table('quiqup_config')->where('id', 'sandbox')->value('secrets');
    expect($raw)->not->toContain('qq_staging_SECRET_key_a1b2')
        ->and(DB::table('settings')->where('value', 'like', '%SECRET%')->count())->toBe(0)
        ->and(json_encode(QuiqupConfig::find('sandbox')->toArray()))->not->toContain('SECRET');

    $this->getJson('/admin-api/quiqup-settings')->assertOk()->assertDontSee('qq_staging_SECRET_key_a1b2');
    // MUTATION: return $s['api_key'] instead of mask() in publicView() -- red.
});

it('keeps one key per environment, so a mode change loses nothing', function () {
    $this->actingAs(QuiqupKit::admin('owner'), 'admin');
    $this->postJson('/admin-api/quiqup-settings', ['secrets_env' => 'sandbox', 'api_key' => 'sandbox_key_1111'])->assertOk();
    $this->postJson('/admin-api/quiqup-settings', ['secrets_env' => 'live', 'api_key' => 'live_key_2222'])->assertOk()
        ->assertJsonPath('envs.sandbox.api_key.last4', '1111')->assertJsonPath('envs.live.api_key.last4', '2222')
        ->assertJsonPath('mode', 'off');
});

it('stores only allowlisted option values, escapes nothing it will not print, and validates the pickup phone', function () {
    $this->actingAs(QuiqupKit::admin('owner'), 'admin');
    QuiqupKit::configure();

    $this->postJson('/admin-api/quiqup-settings', ['options' => ['pickup' => ['contact_phone' => 'call me']]])
        ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'country code'));

    $this->postJson('/admin-api/quiqup-settings', ['options' => [
        'pickup' => ['contact_name' => '<script>x</script>', 'contact_phone' => '050 111 2222', 'address1' => 'Warehouse 4', 'emirate' => 'Atlantis'],
        'services' => ['enabled' => ['partner_same_day', 'partner_teleport'], 'default' => 'partner_teleport', 'by_method' => ['Express' => 'partner_same_day', 'Bad' => 'partner_next_day']],
        'parcels' => 99, 'four_hour_time' => '22:00', 'note_policy' => 'gift',
        'statuses' => ['collected' => ['status' => 'refunded', 'en' => 'Picked up', 'ar' => 'x'], 'delivery_complete' => ['status' => 'completed', 'en' => str_repeat('a', 90)]],
    ]])->assertOk();

    $o = app(QuiqupSettings::class)->options();
    expect($o['pickup']['contact_name'])->toBe('')
        ->and($o['pickup']['contact_phone'])->toBe('+971501112222')
        ->and($o['pickup']['emirate'])->toBe('Dubai')
        ->and($o['services']['enabled'])->toBe(['partner_same_day'])
        ->and($o['services']['default'])->toBe('partner_same_day')
        ->and($o['services']['by_method'])->toBe(['Express' => 'partner_same_day'])
        ->and($o['parcels'])->toBe(10)
        ->and($o['four_hour_time'])->toBe('10:00')
        ->and($o['note_policy'])->toBe('customer')
        ->and($o['statuses']['collected']['status'])->toBe('shipped')
        ->and(mb_strlen($o['statuses']['delivery_complete']['en']))->toBe(60);
});

it('ships the owner\'s open questions at the plan defaults, each one a setting', function () {
    $d = QuiqupSettings::defaults();
    expect($d['statuses']['collected']['status'])->toBe('shipped')
        ->and($d['statuses']['delivery_complete']['status'])->toBe('completed')
        ->and($d['statuses']['returned_to_origin']['status'])->toBe('')
        ->and($d['uae_only'])->toBeTrue()
        ->and($d['release'])->toBeTrue()
        ->and($d['note_policy'])->toBe('customer')
        ->and(QuiqupSettings::emiratesMatchOrderAddress())->toBeTrue()
        ->and(QuiqupSettings::quiqupEmirate('Umm Al Quwain'))->toBe('Umm al-Qaywayn')
        ->and(QuiqupSettings::quiqupEmirate('Ras Al Khaimah'))->toBe('Ras Al-Khaimah');
});

it('maps every Quiqup admin route to a quiqup capability, explicitly', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'admin-api/quiqup-'));
    expect($routes->count())->toBeGreaterThanOrEqual(4);

    foreach ($routes as $r) {
        $cap = AdminCapabilities::for($r);
        expect($cap)->not->toBeNull($r->uri())->and(str_starts_with((string) $cap, 'quiqup.'))->toBeTrue($r->uri());
    }

    expect(AdminCapabilities::forPath('POST', 'admin-api/quiqup-settings'))->toBe('quiqup.settings')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/quiqup-test'))->toBe('quiqup.settings')
        ->and(AdminCapabilities::forPath('GET', 'admin-api/quiqup-settings'))->toBe('quiqup.view')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/quiqup-anything-new'))->toBeNull();
});

it('lets support read but not change settings, and an editor sees nothing', function () {
    QuiqupKit::configure();

    $this->actingAs(QuiqupKit::admin('support'), 'admin');
    $this->getJson('/admin-api/quiqup-settings')->assertOk();
    $this->getJson('/admin-api/quiqup-activity')->assertOk();
    $this->postJson('/admin-api/quiqup-mode', ['mode' => 'live', 'confirm' => true])->assertForbidden();
    $this->postJson('/admin-api/quiqup-settings', ['options' => ['parcels' => 3]])->assertForbidden();
    $this->postJson('/admin-api/quiqup-test', [])->assertForbidden();

    $this->actingAs(QuiqupKit::admin('manager'), 'admin');
    $this->postJson('/admin-api/quiqup-settings', ['secrets_env' => 'sandbox', 'api_key' => 'manager_tries'])->assertForbidden();

    $this->actingAs(QuiqupKit::admin('editor'), 'admin');
    $this->getJson('/admin-api/quiqup-settings')->assertForbidden();

    expect(app(QuiqupSettings::class)->mode())->toBe('testing')
        ->and(app(QuiqupSettings::class)->apiKey('sandbox'))->toBe('qq_test_key_abcd1234');
});

it('refuses a guest', function () {
    $this->getJson('/admin-api/quiqup-settings')->assertStatus(401);
});

it('tests the connection with one read and reports the answer', function () {
    QuiqupKit::configure();
    $this->actingAs(QuiqupKit::admin('owner'), 'admin');

    $this->postJson('/admin-api/quiqup-test', ['env' => 'sandbox'])->assertOk()->assertJsonPath('ok', true)
        ->assertJsonPath('message', fn ($m) => str_starts_with($m, 'OK · staging'));
    expect($this->fake->count('ping'))->toBe(1);

    $this->fake->script['ping'][] = \App\Services\Quiqup\QuiqupErrors::describe(401, ['error' => 'unauthorized']);
    $this->postJson('/admin-api/quiqup-test', ['env' => 'sandbox'])->assertOk()->assertJsonPath('ok', false)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'HTTP 401') && str_contains($m, 'API key'));
});

it('shows the webhook health banner when parcels move and no webhook came for 6 hours', function () {
    QuiqupKit::configure();
    $this->actingAs(QuiqupKit::admin('owner'), 'admin');
    QuiqupKit::shipment(QuiqupKit::order(), ['state' => 'out_for_delivery']);

    $this->getJson('/admin-api/quiqup-activity')->assertOk()->assertJsonPath('webhook_stale', true)->assertJsonPath('inflight', 1);

    QuiqupConfig::query()->whereKey('sandbox')->update(['last_webhook_at' => now()->subHour()]);
    $this->getJson('/admin-api/quiqup-activity')->assertOk()->assertJsonPath('webhook_stale', false);
});

it('records every settings save in the security log without the secret', function () {
    $this->actingAs(QuiqupKit::admin('owner'), 'admin');
    $this->postJson('/admin-api/quiqup-settings', ['secrets_env' => 'sandbox', 'api_key' => 'qq_audit_key_zz99'])->assertOk();

    $row = DB::table('audit_events')->where('event', 'quiqup.settings')->latest('id')->first();
    expect($row)->not->toBeNull()
        ->and(json_encode($row))->toContain('API key replaced')->not->toContain('qq_audit_key_zz99');
});
