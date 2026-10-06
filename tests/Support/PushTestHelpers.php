<?php

declare(strict_types=1);

use App\Models\AdminUser;
use App\Services\OwnerApp\WebPush;
use App\Services\Push\PushRules;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * Lane PN's test helpers, shared by the Push*Test files (require_once). Names
 * start with `pn` so no other test file's helpers collide with them.
 */

/** One subscribed phone, with a real P-256 key so the payload really encrypts. */
function pnPhone(array $attrs = []): int
{
    static $n = 0;
    $n++;
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $endpoint = $attrs['endpoint'] ?? 'https://fcm.googleapis.com/fcm/send/pn-'.$n.'-'.bin2hex(random_bytes(4));
    unset($attrs['endpoint']);

    return (int) DB::table('site_app_push_subscriptions')->insertGetId($attrs + [
        'endpoint' => $endpoint, 'endpoint_hash' => hash('sha256', $endpoint),
        'p256dh' => WebPush::b64u(WebPush::publicPoint($key)), 'auth' => WebPush::b64u(random_bytes(16)),
        'cookie_hash' => hash('sha256', 'pn-cookie-'.$n.'-'.$endpoint), 'locale' => 'en', 'country' => 'AE',
        'region' => 'Dubai', 'city' => 'Dubai', 'location_source' => 'order', 'platform' => 'android',
        'status' => 'active', 'fail_count' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function pnAdmin(string $role = 'owner', ?string $email = null): AdminUser
{
    return AdminUser::create(['name' => 'PN '.$role, 'email' => $email ?? 'pn-'.$role.'-'.uniqid().'@example.test', 'password' => 'password123', 'role' => $role]);
}

function pnRules(array $rules): void
{
    app(PushRules::class)->save($rules);
    app()->forgetInstance(PushRules::class);
}

/** A campaign row ready to send. */
function pnCampaign(array $audience = [], array $attrs = []): int
{
    return (int) DB::table('push_campaigns')->insertGetId($attrs + [
        'title' => 'Sharjah weekend sale', 'body' => '20% off this weekend', 'url' => '/sale/',
        'audience' => json_encode($audience), 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** Every push service answers 201 unless told otherwise; records what was posted. */
function pnFakePush(array $byHost = []): void
{
    Http::fake(function (HttpRequest $r) use ($byHost) {
        foreach ($byHost as $host => $status) {
            if (str_contains($r->url(), $host)) {
                return Http::response('', $status);
            }
        }

        return Http::response('', 201);
    });
}

