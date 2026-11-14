<?php

declare(strict_types=1);

use App\Models\QuiqupConfig;
use App\Models\Shipment;
use App\Services\Quiqup\QuiqupSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeQuiqupClient;
use Tests\Support\QuiqupKit;
use Tests\Support\QuiqupRoutes;

/*
 * Store -> Quiqup's mode: Off -> Internal testing -> Live (the owner, 14 Nov
 * 2026). What each case prevents: real couriers booked before the owner has
 * tested end to end; a Live switch nobody can trace; a staging booking that
 * stops syncing because the shop moved on to production; a shopper who is not
 * a test customer being treated as one.
 */

beforeEach(function () {
    Http::preventStrayRequests();
    $this->fake = FakeQuiqupClient::install($this->app);
    QuiqupRoutes::wire($this->app);
    $this->actingAs(QuiqupKit::admin('owner'), 'admin');
});

function qqReadyForLive(): void
{
    QuiqupKit::configure('sandbox');
    $live = QuiqupConfig::query()->firstOrNew(['id' => 'live']);
    $live->secrets = ['api_key' => 'live_key_7777', 'webhook_secret' => 'live_whsec', 'url_secret' => str_repeat('L', 40)];
    $live->verified_at = now();
    $live->save();
    QuiqupKit::shipment(QuiqupKit::order(), ['test' => true, 'state' => 'delivery_complete', 'job' => 'created']);
}

it('starts Off: nothing is booked until the owner chooses Internal testing', function () {
    $this->getJson('/admin-api/quiqup-settings')->assertOk()->assertJsonPath('mode', 'off');
    expect(app(QuiqupSettings::class)->env())->toBe('sandbox');
});

it('refuses Internal testing without a staging key, and every change without confirm', function () {
    $this->postJson('/admin-api/quiqup-mode', ['mode' => 'testing', 'confirm' => true])->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'staging API key'));

    QuiqupKit::configure('sandbox', 'k', ['mode' => 'off']);
    $this->postJson('/admin-api/quiqup-mode', ['mode' => 'testing'])->assertStatus(422);
    $this->postJson('/admin-api/quiqup-mode', ['mode' => 'testing', 'confirm' => true])->assertOk()->assertJsonPath('mode', 'testing');
});

it('refuses Live until every checklist item is ticked, and names what is missing', function () {
    QuiqupKit::configure('sandbox');

    $r = $this->postJson('/admin-api/quiqup-mode', ['mode' => 'live', 'confirm' => true])->assertStatus(422);
    expect($r->json('message'))->toContain('Live API key saved and verified')->toContain('test order delivered end to end')
        ->and(collect($r->json('checklist'))->where('ok', false)->pluck('key')->all())->toContain('live_key', 'live_webhook', 'test_order')
        ->and(app(QuiqupSettings::class)->mode())->toBe('testing');
    // MUTATION: skip the checklist check in setMode() and this switches to live -- red.
});

it('goes Live with confirm once the checklist passes, and back in one confirmed step', function () {
    qqReadyForLive();

    $this->postJson('/admin-api/quiqup-mode', ['mode' => 'live', 'confirm' => true])->assertOk()
        ->assertJsonPath('mode', 'live')->assertJsonPath('env', 'live');
    expect(app(QuiqupSettings::class)->env())->toBe('live');

    $this->postJson('/admin-api/quiqup-mode', ['mode' => 'testing', 'confirm' => true])->assertOk()->assertJsonPath('mode', 'testing');
    expect(app(QuiqupSettings::class)->env())->toBe('sandbox');
});

it('a replaced live key must pass Test connection again before Live', function () {
    qqReadyForLive();
    $this->postJson('/admin-api/quiqup-settings', ['secrets_env' => 'live', 'api_key' => 'live_key_new_8888'])->assertOk();
    $this->postJson('/admin-api/quiqup-mode', ['mode' => 'live', 'confirm' => true])->assertStatus(422);

    $this->postJson('/admin-api/quiqup-test', ['env' => 'live'])->assertOk()->assertJsonPath('ok', true);
    $this->postJson('/admin-api/quiqup-mode', ['mode' => 'live', 'confirm' => true])->assertOk();
});

it('writes every mode change to the audit log and the mode history: who, when, from and to', function () {
    qqReadyForLive();
    $this->postJson('/admin-api/quiqup-mode', ['mode' => 'live', 'confirm' => true])->assertOk();
    $this->postJson('/admin-api/quiqup-mode', ['mode' => 'off', 'confirm' => true])->assertOk()
        ->assertJsonPath('mode_log.0.from', 'live')->assertJsonPath('mode_log.0.to', 'off')
        ->assertJsonPath('mode_log.0.by', 'QQ Owner');

    $rows = DB::table('audit_events')->where('event', 'quiqup.mode')->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and(json_encode($rows[0]))->toContain('Internal testing')->toContain('Live')
        ->and(json_encode($rows[1]))->toContain('Off');
});

it('cannot write the mode through the ordinary settings save', function () {
    QuiqupKit::configure('sandbox');
    $this->postJson('/admin-api/quiqup-settings', ['options' => ['mode' => 'live', 'mode_log' => []]])->assertOk();
    expect(app(QuiqupSettings::class)->mode())->toBe('testing');
});

it('knows a test order by the allowlisted email, phone or the per-order flag, and nothing else', function () {
    QuiqupKit::configure('sandbox');
    $this->postJson('/admin-api/quiqup-settings', ['options' => ['test' => ['emails' => ['Owner@KBeautyBliss.com'], 'phones' => ['050 999 8888']]]])->assertOk();

    $s = app(QuiqupSettings::class);
    $byEmail = QuiqupKit::order(['email' => 'owner@kbeautybliss.com', 'phone' => null, 'shipping_address' => ['line1' => 'x', 'country' => 'AE']]);
    $byPhone = QuiqupKit::order(['phone' => '+971 50 999 8888']);
    $byFlag = QuiqupKit::order();
    $shopper = QuiqupKit::order();

    $this->postJson('/admin-api/quiqup-test-order', ['order_id' => $byFlag->id, 'on' => true])->assertOk();

    expect($s->isTestOrder($byEmail))->toBeTrue()
        ->and($s->isTestOrder($byPhone))->toBeTrue()
        ->and($s->isTestOrder($byFlag->fresh()))->toBeTrue()
        ->and($s->isTestOrder($shopper))->toBeFalse();

    $this->postJson('/admin-api/quiqup-settings', ['options' => ['test' => ['emails' => ['not-an-email']]]])->assertStatus(422);
});

it('verifies a staging webhook after the mode went Live, against the staging secret and staging shipments only', function () {
    // Defect prevented: a test parcel booked in Internal testing stops
    // syncing the moment the owner presses Live.
    qqReadyForLive();
    $staging = QuiqupKit::shipment(QuiqupKit::order(), ['env' => 'sandbox', 'qq_id' => 555, 'state_at' => '2026-01-01 00:00:00']);
    $liveTwin = QuiqupKit::shipment(QuiqupKit::order(), ['env' => 'live', 'qq_id' => 555, 'state_at' => '2026-01-01 00:00:00']);
    $this->postJson('/admin-api/quiqup-mode', ['mode' => 'live', 'confirm' => true])->assertOk();

    $raw = QuiqupKit::webhookBody($staging, 'collected', '2026-09-03T09:40:00Z');
    $this->call('POST', '/api/shipping/quiqup/webhook/' . QuiqupKit::URL_SECRET, [], [], [], QuiqupKit::signed($raw), $raw)->assertOk()->assertExactJson(['result' => 'ok']);

    expect(Shipment::find($staging->id)->state)->toBe('collected')
        ->and(Shipment::find($liveTwin->id)->state)->toBe('ready_for_collection');

    // The live address with the staging secret is refused.
    $this->call('POST', '/api/shipping/quiqup/webhook/' . str_repeat('L', 40), [], [], [], QuiqupKit::signed($raw), $raw)->assertStatus(401);
});

it('only lets the owner change the mode or flag test orders', function () {
    QuiqupKit::configure('sandbox');
    $this->actingAs(QuiqupKit::admin('manager'), 'admin');
    $this->postJson('/admin-api/quiqup-mode', ['mode' => 'off', 'confirm' => true])->assertForbidden();
    $this->postJson('/admin-api/quiqup-test-order', ['order_id' => 1, 'on' => true])->assertForbidden();
});
