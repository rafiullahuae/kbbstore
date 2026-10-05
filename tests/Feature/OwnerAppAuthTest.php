<?php

declare(strict_types=1);

/*
 * The owner app's front door (Lane MAC): PINs, devices, lockout, sessions.
 *
 * Each test names the defect it would catch on the live shop and the one-line
 * change that turns it red.
 */

use App\Services\OwnerApp\OwnerAppAuth;
use App\Services\OwnerApp\OwnerAppPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\OwnerAppRoutes as OA;

beforeEach(function () {
    OA::wire($this->app);
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-pin:127.0.0.1');
    \Illuminate\Support\Facades\RateLimiter::clear('owner-app-enrol:127.0.0.1');
});

it('stores the PIN the owner sets only as a hash, and never sends it back', function () {
    // DEFECT: a PIN readable from a database dump or an API response.
    // MUTATION: write 'pin_hash' => $pin in OwnerAppAdminController::member()
    // and the first expectation is red.
    $owner = OA::admin();
    $staff = OA::admin('support', 'sara@example.com', 'Sara Support');

    $r = $this->actingAs($owner, 'admin')->putJson('/admin-api/owner-app/members/'.$staff->id, ['enabled' => true, 'pin' => '7391']);
    $r->assertOk();

    $hash = (string) DB::table('owner_app_members')->where('admin_user_id', $staff->id)->value('pin_hash');
    expect($hash)->not->toBe('7391')->and($hash)->not->toContain('7391')
        ->and(Hash::check('7391', $hash))->toBeTrue()
        ->and($r->getContent())->not->toContain('7391');

    $list = $this->actingAs($owner, 'admin')->getJson('/admin-api/owner-app')->assertOk();
    expect($list->getContent())->not->toContain($hash)->not->toContain('pin_hash')->not->toContain('7391')
        ->and($list->json('members.1.has_pin'))->toBeTrue();
});

it('refuses a PIN that is not 4–8 digits, or is 1111 or 1234', function (string $pin) {
    $owner = OA::admin();
    $this->actingAs($owner, 'admin')->putJson('/admin-api/owner-app/members/'.$owner->id, ['enabled' => true, 'pin' => $pin])
        ->assertStatus(422);
    expect(DB::table('owner_app_members')->count())->toBe(0);
})->with(['123', '123456789', '12a4', '1111', '1234', '9876']);

it('will not switch the app on for a member who has no PIN', function () {
    $owner = OA::admin();
    $this->actingAs($owner, 'admin')->putJson('/admin-api/owner-app/members/'.$owner->id, ['enabled' => true])->assertStatus(422);
});

it('enrols a device into HttpOnly, Secure, SameSite=Strict cookies scoped to the app, keeping only their hashes', function () {
    // DEFECT: a device token a script can read, or one sent to every page of
    // the shop, or one stored in the clear. MUTATION: pass `false` for
    // httpOnly in OwnerAppAuth::cookie() and this is red.
    $owner = OA::admin();
    OA::member($owner);

    $r = $this->withHeaders(['X-OA' => '1'])->postJson(OA::base().'/api/enrol', ['email' => 'OWNER@example.com', 'pin' => '4826'])->assertOk();

    $cookies = collect($r->headers->getCookies())->keyBy(fn ($c) => $c->getName());
    foreach ([OwnerAppAuth::DEVICE_COOKIE, OwnerAppAuth::SESSION_COOKIE] as $name) {
        $c = $cookies[$name];
        expect($c->isHttpOnly())->toBeTrue()
            ->and($c->isSecure())->toBeTrue()
            ->and($c->getSameSite())->toBe('strict')
            ->and($c->getPath())->toBe(OA::base());
    }

    $token = $cookies[OwnerAppAuth::DEVICE_COOKIE]->getValue();
    $row = DB::table('owner_app_devices')->first();
    expect($row->token_hash)->toBe(hash('sha256', $token))
        ->and(json_encode($row))->not->toContain($token)
        ->and($r->getContent())->not->toContain($token)
        ->and($r->json('me.name'))->toBe('Rafi Owner')
        ->and($r->getContent())->not->toContain('owner@example.com');
});

it('answers an unknown email, a member without access and a wrong PIN with the same words', function () {
    // DEFECT: the sign-in form as a way to find out who is staff.
    $owner = OA::admin();
    OA::member($owner);
    $off = OA::admin('manager', 'off@example.com', 'Off');
    OA::member($off, '4826', false);

    $a = $this->withHeaders(['X-OA' => '1'])->postJson(OA::base().'/api/enrol', ['email' => 'nobody@example.com', 'pin' => '4826']);
    $b = $this->withHeaders(['X-OA' => '1'])->postJson(OA::base().'/api/enrol', ['email' => 'owner@example.com', 'pin' => '0000']);
    $c = $this->withHeaders(['X-OA' => '1'])->postJson(OA::base().'/api/enrol', ['email' => 'off@example.com', 'pin' => '4826']);

    expect($a->status())->toBe(422)->and($b->status())->toBe(422)->and($c->status())->toBe(422)
        ->and($a->json())->toBe($b->json())->and($b->json())->toBe($c->json());
});

it('locks a member for 15 minutes after 5 wrong PINs, on the right PIN too', function () {
    // DEFECT: unlimited PIN guessing. MUTATION: change MEMBER_LOCK_AFTER to 50.
    $owner = OA::admin();
    OA::member($owner);
    [$cookies] = OA::enrol($this);

    for ($i = 1; $i <= 4; $i++) {
        OA::post($this, 'unlock', ['pin' => '1357'], $cookies, null)->assertStatus(422)->assertJsonPath('left', 5 - $i);
    }
    OA::post($this, 'unlock', ['pin' => '1357'], $cookies, null)->assertStatus(423)->assertJsonPath('code', 'locked');
    OA::post($this, 'unlock', ['pin' => '4826'], $cookies, null)->assertStatus(423);

    $this->travel(16)->minutes();
    OA::post($this, 'unlock', ['pin' => '4826'], $cookies, null)->assertOk()->assertJsonPath('ok', true);
});

it('revokes a device after a long run of wrong PINs, so the email is needed again', function () {
    // DEFECT: a stolen phone guessing forever at five tries per quarter hour.
    // MUTATION: drop the revoke() call in OwnerAppAuth::wrongPin().
    $owner = OA::admin();
    OA::member($owner);
    [$cookies] = OA::enrol($this);

    for ($i = 0; $i < OwnerAppAuth::DEVICE_REVOKE_AFTER - 1; $i++) {
        DB::table('owner_app_members')->update(['locked_until' => null]);
        OA::post($this, 'unlock', ['pin' => '1357'], $cookies, null);
    }
    DB::table('owner_app_members')->update(['locked_until' => null]);
    OA::post($this, 'unlock', ['pin' => '1357'], $cookies, null)->assertStatus(403)->assertJsonPath('code', 'no_device');

    expect(DB::table('owner_app_devices')->value('revoked_reason'))->toBe('too_many_wrong_pins');
    OA::post($this, 'unlock', ['pin' => '4826'], $cookies, null)->assertStatus(403);
});

it('lets the owner revoke a device from the admin, which ends it and its push subscription', function () {
    $owner = OA::admin();
    OA::member($owner);
    [$cookies] = OA::enrol($this);
    $device = (int) DB::table('owner_app_devices')->value('id');
    DB::table('owner_app_push_subscriptions')->insert(['device_id' => $device, 'endpoint' => 'https://fcm.googleapis.com/fcm/send/x', 'endpoint_hash' => hash('sha256', 'x'),
        'p256dh' => 'k', 'auth' => 'a', 'created_at' => now(), 'updated_at' => now()]);

    OA::get($this, 'orders', $cookies)->assertOk();

    $this->actingAs($owner, 'admin')->postJson('/admin-api/owner-app/devices/'.$device.'/revoke')->assertOk();

    OA::get($this, 'orders', $cookies)->assertStatus(401)->assertJsonPath('code', 'no_device');
    expect(DB::table('owner_app_push_subscriptions')->count())->toBe(0);
});

it('asks for the PIN again after the idle time, and the live poll does not keep a session alive', function () {
    // DEFECT: an app left open on a desk that never locks. MUTATION: remove
    // ->defaults('oa_passive', true) from the changes route.
    $owner = OA::admin();
    OA::member($owner);
    [$cookies] = OA::enrol($this);

    for ($h = 0; $h < 13; $h++) {
        $this->travel(1)->hours();
        OA::get($this, 'changes?after=1', $cookies);
    }

    OA::get($this, 'orders', $cookies)->assertStatus(401)->assertJsonPath('code', 'locked');
    $this->withCredentials()->withUnencryptedCookies($cookies)->withHeaders(['X-OA' => '1'])->getJson(OA::base().'/api/state')->assertJsonPath('stage', 'pin');
});

it('ends every session when the owner sets a new PIN or switches access off', function () {
    $owner = OA::admin();
    OA::member($owner);
    [$cookies] = OA::enrol($this);
    OA::get($this, 'orders', $cookies)->assertOk();

    $this->actingAs($owner, 'admin')->putJson('/admin-api/owner-app/members/'.$owner->id, ['pin' => '2580'])->assertOk();
    OA::get($this, 'orders', $cookies)->assertStatus(401)->assertJsonPath('code', 'locked');

    $this->actingAs($owner, 'admin')->putJson('/admin-api/owner-app/members/'.$owner->id, ['enabled' => false])->assertOk();
    OA::post($this, 'unlock', ['pin' => '2580'], $cookies, null)->assertStatus(403)->assertJsonPath('code', 'disabled');
});

it('writes every attempt to the sign-in log, right or wrong', function () {
    $owner = OA::admin();
    OA::member($owner);
    $this->withHeaders(['X-OA' => '1'])->postJson(OA::base().'/api/enrol', ['email' => 'owner@example.com', 'pin' => '9999']);
    [$cookies] = OA::enrol($this);
    OA::post($this, 'unlock', ['pin' => '4826'], $cookies, null)->assertOk();

    $rows = DB::table('owner_app_logins')->orderBy('id')->get(['kind', 'success', 'reason']);
    expect($rows->map(fn ($r) => $r->kind.':'.(int) $r->success.':'.$r->reason)->all())
        ->toBe(['enrol:0:bad_pin', 'enrol:1:ok', 'unlock:1:ok']);
});

it('refuses a connection that keeps guessing with 429, before any PIN is checked', function () {
    $owner = OA::admin();
    OA::member($owner);
    for ($i = 0; $i < OwnerAppAuth::IP_MAX_ENROL_FAILS; $i++) {
        $this->withHeaders(['X-OA' => '1'])->postJson(OA::base().'/api/enrol', ['email' => 'x'.$i.'@example.com', 'pin' => '4826']);
    }
    $this->withHeaders(['X-OA' => '1'])->postJson(OA::base().'/api/enrol', ['email' => 'owner@example.com', 'pin' => '4826'])->assertStatus(429);
});

it('lives at an address with an underscore that no article slug can ever take', function () {
    $path = OwnerAppPath::current();
    $regex = '#^(?:'.\App\Http\Controllers\Store\PageController::slugPattern().')$#D';

    expect($path)->toContain('_')
        ->and(OwnerAppPath::valid($path))->toBeTrue()
        ->and(preg_match($regex, $path))->toBe(0)
        ->and(OwnerAppPath::valid('admin'))->toBeFalse()
        ->and(OwnerAppPath::valid('my-owner-app-page'))->toBeFalse();
});

it('mounts nothing at all when no address is configured', function () {
    DB::table('settings')->where('key', OwnerAppPath::SETTING)->delete();
    \Illuminate\Support\Facades\Cache::flush();
    OwnerAppPath::forgetMemo();

    expect(OwnerAppPath::current())->toBeNull();
});
