<?php

declare(strict_types=1);

use App\Models\QuiqupConfig;
use App\Models\Shipment;
use App\Services\Quiqup\QuiqupClient;
use App\Services\Quiqup\QuiqupSettings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeQuiqupClient;
use Tests\Support\QuiqupKit;
use Tests\Support\QuiqupRoutes;

/*
 * "Internal testing books on" (the owner, 14 Nov 2026): "Real Courior. quiqup
 * don't charge unless they picked up. but if quiqup has staging/test api, then
 * i would love to use test apis, so we can check everything instant."
 *
 * Staging is the default; Live books ONLY his test orders with a real, billed
 * courier. Each case names the failure it prevents on the shop.
 */

const QQE_TEST_PHONE = '+971501234567';

beforeEach(function () {
    Http::preventStrayRequests();
    $this->fake = FakeQuiqupClient::install($this->app);
    QuiqupRoutes::wire($this->app);
    // Internal testing, staging configured, the owner's phone on the test list.
    QuiqupKit::configure('sandbox', 'k', ['test' => ['emails' => [], 'phones' => [QQE_TEST_PHONE], 'orders' => []]]);
    $this->actingAs(QuiqupKit::admin('owner'), 'admin');
});

/** The three live prerequisites: live key saved + verified, live webhook secret. Pickup is set by configure(). */
function qqeLiveReady(bool $verified = true, bool $webhook = true): void
{
    $live = QuiqupConfig::query()->firstOrNew(['id' => 'live']);
    $live->secrets = array_filter(['api_key' => 'live_key_7777', 'webhook_secret' => $webhook ? 'live_whsec' : null, 'url_secret' => str_repeat('L', 40)]);
    $live->verified_at = $verified ? now() : null;
    $live->save();
}

/** Schedule one order and send its create; returns the env the create went to (or null). */
function qqeBook(object $test, int $orderId): ?string
{
    $test->postJson('/admin-api/quiqup-schedule', ['ids' => [$orderId]])->assertOk();
    $test->postJson('/admin-api/quiqup-step', ['ids' => [$orderId]])->assertOk();
    $last = collect($test->fake->calls)->where(0, 'createOrder')->last();

    return $last[1] ?? null;
}

it('defaults to the Quiqup test system (staging): no courier, no charges', function () {
    // Defect prevented: a fresh install (or a package applied over a shop in
    // Internal testing) sending the owner's test orders to a real courier.
    $this->getJson('/admin-api/quiqup-settings')->assertOk()
        ->assertJsonPath('testing_env', 'sandbox')
        ->assertJsonPath('testing_env_labels.sandbox', 'Quiqup test system (staging)');

    expect(QuiqupSettings::defaults()['testing_env'])->toBe('sandbox')
        ->and(app(QuiqupSettings::class)->env())->toBe('sandbox')
        ->and(qqeBook($this, QuiqupKit::order()->id))->toBe('sandbox');
});

it('keeps Live (real courier) disabled, with the reason, until the live key is verified, the live webhook secret saved and the pickup set', function () {
    // Defect prevented: test orders sent to production with a key nobody
    // tested, no webhook to report back, or no address to collect from.
    $r = $this->getJson('/admin-api/quiqup-settings')->assertOk();
    expect($r->json('testing_live_missing'))->toHaveCount(2); // pickup is already set

    $this->postJson('/admin-api/quiqup-mode', ['testing_env' => 'live', 'confirm' => true])->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'not ready') && str_contains($m, 'live API key'));

    qqeLiveReady(verified: false);
    $this->postJson('/admin-api/quiqup-mode', ['testing_env' => 'live', 'confirm' => true])->assertStatus(422)
        ->assertJsonPath('testing_live_missing', ['verify the live API key with Test connection']);

    qqeLiveReady(webhook: false);
    $this->postJson('/admin-api/quiqup-mode', ['testing_env' => 'live', 'confirm' => true])->assertStatus(422)
        ->assertJsonPath('testing_live_missing', ['save the live webhook signing secret']);

    qqeLiveReady();
    $o = app(QuiqupSettings::class)->options();
    $o['pickup']['address1'] = '';
    QuiqupConfig::query()->whereKey('sandbox')->update(['options' => json_encode($o)]);
    $this->postJson('/admin-api/quiqup-mode', ['testing_env' => 'live', 'confirm' => true])->assertStatus(422)
        ->assertJsonPath('testing_live_missing', ['set the pickup address']);

    expect(app(QuiqupSettings::class)->testingEnv())->toBe('sandbox');
    // MUTATION: drop the liveMissing() check in setTestingEnv() and the first post switches -- red.
});

it('asks for confirmation before a real courier collects test orders', function () {
    // Defect prevented: one stray click (or a replayed request) billing the
    // owner for couriers he did not knowingly ask for.
    qqeLiveReady();
    $this->postJson('/admin-api/quiqup-mode', ['testing_env' => 'live'])->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'real Quiqup courier'));
    expect(app(QuiqupSettings::class)->testingEnv())->toBe('sandbox');

    $this->postJson('/admin-api/quiqup-mode', ['testing_env' => 'live', 'confirm' => true])->assertOk()
        ->assertJsonPath('testing_env', 'live')->assertJsonPath('env', 'live')->assertJsonPath('mode', 'testing');

    // The screen's dialog says it in the owner's words.
    $view = file_get_contents(resource_path('views/admin/partials/quiqup-screen.blade.php'));
    expect($view)->toContain('A real Quiqup courier will collect your test orders. Quiqup bills a delivery once it is picked up.');
});

it('books on the chosen environment per mode and option', function () {
    // Defect prevented: the option saved but bookings still going to staging
    // (or, worse, Live mode's every-order bookings following the test option).
    $order = fn () => QuiqupKit::order(['phone' => QQE_TEST_PHONE]);
    expect(qqeBook($this, $order()->id))->toBe('sandbox');

    qqeLiveReady();
    $this->postJson('/admin-api/quiqup-mode', ['testing_env' => 'live', 'confirm' => true])->assertOk();
    $o = $order();
    expect(qqeBook($this, $o->id))->toBe('live')
        ->and(Shipment::where('order_id', $o->id)->value('env'))->toBe('live')
        ->and((bool) Shipment::where('order_id', $o->id)->value('test'))->toBeTrue();

    // Back to staging: the next test order goes to staging again.
    $this->postJson('/admin-api/quiqup-mode', ['testing_env' => 'sandbox'])->assertOk()->assertJsonPath('env', 'sandbox');
    expect(qqeBook($this, $order()->id))->toBe('sandbox');

    // Off ignores the option; Live mode always books production.
    $s = app(QuiqupSettings::class);
    $this->postJson('/admin-api/quiqup-mode', ['mode' => 'off', 'confirm' => true])->assertOk();
    expect($s->env())->toBe('sandbox');
});

it('sends the real-courier booking to the production host, and the default to staging', function () {
    // Defect prevented: the env string saying "live" while the request still
    // goes to the staging host (or the reverse). Real client, Http::fake().
    $this->app->forgetInstance(QuiqupClient::class);
    $this->app->offsetUnset(QuiqupClient::class);
    $created = QuiqupKit::json('create-response.json');
    Http::fake(['*' => Http::response($created, 201)]);

    $a = QuiqupKit::order(['phone' => QQE_TEST_PHONE]);
    $this->postJson('/admin-api/quiqup-schedule', ['ids' => [$a->id]])->assertOk();
    $this->postJson('/admin-api/quiqup-step', ['ids' => [$a->id]])->assertOk();
    Http::assertSent(fn (HttpRequest $r) => str_starts_with($r->url(), 'https://api.staging.quiqup.com/orders'));

    qqeLiveReady();
    $this->postJson('/admin-api/quiqup-mode', ['testing_env' => 'live', 'confirm' => true])->assertOk();
    $b = QuiqupKit::order(['phone' => QQE_TEST_PHONE]);
    $this->postJson('/admin-api/quiqup-schedule', ['ids' => [$b->id]])->assertOk();
    $this->postJson('/admin-api/quiqup-step', ['ids' => [$b->id]])->assertOk();
    Http::assertSent(fn (HttpRequest $r) => str_starts_with($r->url(), 'https://api-ae.quiqup.com/orders')
        && $r->hasHeader('Authorization', 'Bearer live_key_7777'));
});

it('still refuses every non-test order in Internal testing, on either environment', function () {
    // Defect prevented: a real shopper's order booked (and billed) while the
    // owner is only testing.
    $shopper = QuiqupKit::order(['phone' => '0559998877', 'shipping_address' => ['first_name' => 'S', 'last_name' => 'T', 'line1' => 'Villa 1', 'city' => 'Dubai', 'state' => 'Dubai', 'country' => 'AE', 'phone' => '0559998877']]);

    $this->postJson('/admin-api/quiqup-schedule', ['ids' => [$shopper->id]])->assertOk()->assertJsonPath('queued', [])
        ->assertJsonPath('blocked.0.blocks.0.text', fn ($t) => str_contains($t, 'only test orders'));

    qqeLiveReady();
    $this->postJson('/admin-api/quiqup-mode', ['testing_env' => 'live', 'confirm' => true])->assertOk();
    $this->postJson('/admin-api/quiqup-schedule', ['ids' => [$shopper->id]])->assertOk()->assertJsonPath('queued', [])
        ->assertJsonPath('blocked.0.blocks.0.text', fn ($t) => str_contains($t, 'only test orders'));

    expect($this->fake->count('createOrder'))->toBe(0)
        ->and(Shipment::where('order_id', $shopper->id)->exists())->toBeFalse();
});

it('blocks real-courier test bookings again once the live key is replaced and not yet re-verified', function () {
    // Defect prevented: the gate checked once at the switch, then a new,
    // untested live key used for a real courier.
    qqeLiveReady();
    $this->postJson('/admin-api/quiqup-mode', ['testing_env' => 'live', 'confirm' => true])->assertOk();
    $this->postJson('/admin-api/quiqup-settings', ['secrets_env' => 'live', 'api_key' => 'live_key_new_8888'])->assertOk();

    $o = QuiqupKit::order(['phone' => QQE_TEST_PHONE]);
    $this->postJson('/admin-api/quiqup-schedule', ['ids' => [$o->id]])->assertOk()->assertJsonPath('queued', [])
        ->assertJsonPath('blocked.0.blocks', fn ($b) => collect($b)->contains(fn ($x) => str_contains($x['text'], 'real courier, which is not ready')));
});

it('keeps each shipment on the environment that created it after the option flips', function () {
    // Defect prevented: a staging test parcel refreshed, cancelled or
    // labelled against production (or the reverse) after the owner switched.
    $a = QuiqupKit::order(['phone' => QQE_TEST_PHONE]);
    expect(qqeBook($this, $a->id))->toBe('sandbox');

    qqeLiveReady();
    $this->postJson('/admin-api/quiqup-mode', ['testing_env' => 'live', 'confirm' => true])->assertOk();
    $b = QuiqupKit::order(['phone' => QQE_TEST_PHONE]);
    expect(qqeBook($this, $b->id))->toBe('live');

    $this->postJson('/admin-api/quiqup-refresh', ['order_id' => $a->id])->assertOk();
    $this->postJson('/admin-api/quiqup-cancel', ['order_id' => $a->id])->assertOk();
    $this->postJson('/admin-api/quiqup-mode', ['testing_env' => 'sandbox'])->assertOk();
    $this->postJson('/admin-api/quiqup-cancel', ['order_id' => $b->id])->assertOk();

    $calls = collect($this->fake->calls);
    expect($calls->where(0, 'cancel')->pluck(1)->all())->toBe(['sandbox', 'live'])
        ->and($calls->where(0, 'order')->pluck(1)->unique()->values()->all())->toBe(['sandbox'])
        ->and(Shipment::where('order_id', $a->id)->value('env'))->toBe('sandbox')
        ->and(Shipment::where('order_id', $b->id)->value('env'))->toBe('live');

    // A staging webhook is still verified against staging after the flip.
    $s = Shipment::where('order_id', $a->id)->first();
    $raw = QuiqupKit::webhookBody($s, 'cancelled', now()->utc()->format('Y-m-d\TH:i:s\Z'));
    $this->call('POST', '/api/shipping/quiqup/webhook/' . QuiqupKit::URL_SECRET, [], [], [], QuiqupKit::signed($raw), $raw)->assertOk();
});

it('accepts a delivered test order on EITHER environment for the Go-Live checklist, and says which', function () {
    // Defect prevented: the owner tests end to end with a real courier and
    // Go Live still says "not done" because it only counted staging.
    $item = fn () => collect(app(QuiqupSettings::class)->checklist())->firstWhere('key', 'test_order');
    expect($item()['ok'])->toBeFalse();

    QuiqupKit::shipment(QuiqupKit::order(), ['env' => 'live', 'test' => true, 'state' => 'delivery_complete']);
    expect($item()['ok'])->toBeTrue()
        ->and($item()['detail'])->toContain('with a real courier')->not->toContain('on staging')
        ->and($item()['envs'])->toBe(['live']);

    QuiqupKit::shipment(QuiqupKit::order(), ['env' => 'sandbox', 'test' => true, 'state' => 'delivery_complete']);
    expect($item()['detail'])->toContain('on staging and with a real courier')
        ->and($item()['envs'])->toBe(['sandbox', 'live']);

    // A delivered NON-test order does not count.
    Shipment::query()->update(['test' => false]);
    expect($item()['ok'])->toBeFalse();
});

it('reads a hostile or damaged stored value as staging, and refuses one in a request', function () {
    // Defect prevented: a tampered options row (or an old package's shape)
    // silently sending test orders to production.
    foreach (['https://evil.example', ['live'], 1, 'LIVE', null] as $bad) {
        $o = app(QuiqupSettings::class)->options();
        $o['testing_env'] = $bad;
        QuiqupConfig::query()->whereKey('sandbox')->update(['options' => json_encode($o)]);
        expect(app(QuiqupSettings::class)->testingEnv())->toBe('sandbox')
            ->and(app(QuiqupSettings::class)->env())->toBe('sandbox');
    }

    $this->postJson('/admin-api/quiqup-mode', ['testing_env' => 'production', 'confirm' => true])->assertStatus(422);
    $this->postJson('/admin-api/quiqup-mode', ['testing_env' => ['live'], 'confirm' => true])->assertStatus(422);

    // The ordinary settings save cannot write it either (it bypasses the gate).
    qqeLiveReady(verified: false);
    $this->postJson('/admin-api/quiqup-settings', ['options' => ['testing_env' => 'live']])->assertOk();
    expect(app(QuiqupSettings::class)->testingEnv())->toBe('sandbox');
});

it('writes every change to the audit log and the mode history', function () {
    // Defect prevented: real couriers booked for test orders with no trace of
    // who switched it on, or when.
    qqeLiveReady();
    $this->postJson('/admin-api/quiqup-mode', ['testing_env' => 'live', 'confirm' => true])->assertOk()
        ->assertJsonPath('mode_log.0.kind', 'books_on')->assertJsonPath('mode_log.0.from', 'sandbox')
        ->assertJsonPath('mode_log.0.to', 'live')->assertJsonPath('mode_log.0.by', 'QQ Owner');
    $this->postJson('/admin-api/quiqup-mode', ['testing_env' => 'sandbox'])->assertOk();
    // A no-op writes nothing.
    $this->postJson('/admin-api/quiqup-mode', ['testing_env' => 'sandbox'])->assertOk();

    $rows = DB::table('audit_events')->where('event', 'quiqup.testing_env')->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and(json_encode($rows[0], JSON_UNESCAPED_UNICODE))->toContain('Live (real courier)')
        ->and(json_encode($rows[1], JSON_UNESCAPED_UNICODE))->toContain('Quiqup test system (staging)');
});

it('re-entering Internal testing set to a real courier needs the live prerequisites, not the staging key', function () {
    // Defect prevented: Off -> Internal testing landing on real-courier
    // bookings after the live key was replaced and never re-tested.
    qqeLiveReady();
    $this->postJson('/admin-api/quiqup-mode', ['testing_env' => 'live', 'confirm' => true])->assertOk();
    $this->postJson('/admin-api/quiqup-mode', ['mode' => 'off', 'confirm' => true])->assertOk();
    QuiqupConfig::query()->whereKey('live')->update(['verified_at' => null]);

    $this->postJson('/admin-api/quiqup-mode', ['mode' => 'testing', 'confirm' => true])->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'real courier'));

    QuiqupConfig::query()->whereKey('live')->update(['verified_at' => now()]);
    $this->postJson('/admin-api/quiqup-mode', ['mode' => 'testing', 'confirm' => true])->assertOk()
        ->assertJsonPath('env', 'live');
});

it('only lets the owner change where Internal testing books', function () {
    $this->actingAs(QuiqupKit::admin('manager'), 'admin');
    $this->postJson('/admin-api/quiqup-mode', ['testing_env' => 'sandbox'])->assertForbidden();
});
