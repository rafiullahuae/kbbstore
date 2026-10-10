<?php

declare(strict_types=1);

/**
 * Lane RV — Emails → Customer emails → Review request → Schedule & rules: the
 * controls the owner asked for ("give me full controls also"), who may use
 * them, and what they cost.
 *
 * MUTATION NOTES, each one run:
 *   - AdminCapabilities::RULES: delete the admin-api/emails/review-requests row
 *       -> 'has its own capability' is red (the emails wildcard claims it).
 *   - ReviewRequestSettings::clean(): return $value for a select
 *       -> 'stores only what each control allows' is red.
 *   - review-request-rules.blade.php: print d.recent's order without esc()
 *       -> 'escapes every server string' is red.
 */

use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Setting;
use App\Services\Reviews\ReviewRequestSettings;
use App\Services\SettingsService;
use App\Support\AdminCapabilities;
use Illuminate\Support\Facades\DB;
use Tests\Support\ReviewRequestRoutes;

beforeEach(function () {
    app(SettingsService::class)->flush();
    SettingsService::forgetMemo();
    Setting::flushMap();
    ReviewRequestRoutes::wire(app());
});

function rvaAdmin(string $role): AdminUser
{
    return AdminUser::create(['name' => 'RA ' . $role, 'email' => 'ra-' . $role . '-' . uniqid() . '@example.test', 'password' => 'secret-secret', 'role' => $role]);
}

it('has its own capability, for the owner and managers, and fails closed for everyone else', function () {
    expect(AdminCapabilities::forPath('GET', 'admin-api/emails/review-requests'))->toBe('emails.review_requests')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/emails/review-requests'))->toBe('emails.review_requests')
        ->and(AdminCapabilities::CAPABILITIES['emails.review_requests'])->toBe(['owner', 'manager'])
        // The neighbours are unchanged.
        ->and(AdminCapabilities::forPath('GET', 'admin-api/emails/customer'))->toBe('emails.view')
        ->and(AdminCapabilities::forPath('POST', 'admin-api/emails/templates/{template}'))->toBe('emails.manage');

    $this->actingAs(rvaAdmin('manager'), 'admin')->getJson('/admin-api/emails/review-requests')->assertOk();
    $this->actingAs(rvaAdmin('support'), 'admin')->getJson('/admin-api/emails/review-requests')->assertForbidden();
    $this->actingAs(rvaAdmin('editor'), 'admin')->postJson('/admin-api/emails/review-requests', ['settings' => ['review_request_days' => 9]])->assertForbidden();
    expect(app(ReviewRequestSettings::class)->int('review_request_days'))->toBe(2);
});

it('ships at the values the owner asked for: on, two days, three products', function () {
    $d = $this->actingAs(rvaAdmin('owner'), 'admin')->getJson('/admin-api/emails/review-requests')->assertOk()->json();

    expect($d['enabled'])->toBeTrue()
        ->and($d['settings']['review_request_days'])->toBe(2)
        ->and($d['settings']['review_request_max_products'])->toBe(3)
        ->and($d['settings']['review_request_skip_refunded'])->toBeTrue()
        ->and($d['settings']['review_request_require_completed'])->toBeTrue()
        ->and($d['settings']['review_request_star_colour'])->toBe('gold')
        ->and(collect($d['schema'])->pluck('key')->all())->toBe(array_keys(ReviewRequestSettings::FIELDS))
        ->and($d['stats']['ready'])->toBeTrue();
});

it('stores only what each control allows: numbers to their bounds, a colour from its own list, switches as switches', function () {
    $d = $this->actingAs(rvaAdmin('owner'), 'admin')->postJson('/admin-api/emails/review-requests', ['settings' => [
        'review_request_days' => 500,
        'review_request_max_products' => 0,
        'review_request_link_days' => 'abc',
        'review_request_star_colour' => '#ff0000;background:url(x)',
        'review_request_skip_refunded' => 'false',
        'admin_path' => 'owned',
    ]])->assertOk()->json();

    expect($d['settings']['review_request_days'])->toBe(60)
        ->and($d['settings']['review_request_max_products'])->toBe(1)
        ->and($d['settings']['review_request_link_days'])->toBe(60)
        ->and($d['settings']['review_request_star_colour'])->toBe('gold')
        ->and($d['settings']['review_request_skip_refunded'])->toBeFalse()
        ->and(DB::table('settings')->where('key', 'admin_path')->value('value'))->not->toBe('owned');

    $this->actingAs(rvaAdmin('owner'), 'admin')->postJson('/admin-api/emails/review-requests', ['settings' => ['review_request_star_colour' => 'rose']])->assertOk();
    SettingsService::forgetMemo();
    expect(app(ReviewRequestSettings::class)->starHex())->toBe('#C13E63');
});

it('reports what it sent and what came back, by order number and status, never by address', function () {
    $order = Order::create(['order_number' => 'KBB-RA-1', 'email' => 'secret-buyer@example.com', 'status' => 'completed', 'currency' => 'AED', 'total' => 100, 'subtotal' => 100]);
    DB::table('review_requests')->insert(['order_id' => $order->id, 'email' => 'secret-buyer@example.com', 'completed_at' => now(), 'status' => 'sent', 'sent_at' => now(), 'reviews_count' => 2, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('review_requests')->insert(['order_id' => $order->id + 1000, 'email' => 'x@example.com', 'completed_at' => now(), 'status' => 'skipped', 'reason' => 'refunded', 'created_at' => now(), 'updated_at' => now()]);
    \App\Models\Review::create(['source' => 'review_request', 'source_id' => $order->id * 1_000_000_000 + 1, 'author_name' => 'A', 'author_email' => 'secret-buyer@example.com', 'rating' => 5, 'content' => 'x', 'status' => 'pending']);

    $res = $this->actingAs(rvaAdmin('owner'), 'admin')->getJson('/admin-api/emails/review-requests')->assertOk();
    $d = $res->json();

    expect($d['stats']['sent'])->toBe(1)
        ->and($d['stats']['skipped'])->toBe(1)
        ->and($d['stats']['skipped_by'])->toBe(['refunded' => 1])
        ->and($d['stats']['reviews'])->toBe(1)
        ->and($d['stats']['pending'])->toBe(1)
        ->and($d['stats']['rate'])->toBe(100)
        ->and($d['recent'][1]['order'])->toBe('KBB-RA-1')
        ->and((string) $res->getContent())->not->toContain('secret-buyer@example.com')
        ->and((string) $res->getContent())->not->toContain('token');
});

it('costs the same few queries with three requests as with forty', function () {
    $count = function (int $n): int {
        DB::table('review_requests')->delete();

        for ($i = 0; $i < $n; $i++) {
            DB::table('review_requests')->insert(['order_id' => 9000 + $i, 'email' => "q{$i}@example.com", 'completed_at' => now(), 'status' => $i % 2 ? 'sent' : 'scheduled', 'created_at' => now(), 'updated_at' => now()]);
        }

        $admin = rvaAdmin('owner');
        $this->actingAs($admin, 'admin')->getJson('/admin-api/emails/review-requests')->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin, 'admin')->getJson('/admin-api/emails/review-requests')->assertOk();
        $q = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $q;
    };

    expect($count(40))->toBe($count(3));
});

it('draws the Schedule & rules tab inside the template editor, for this email only, with no layout-measuring script and every server string escaped', function () {
    $screen = (string) file_get_contents(resource_path('views/admin/partials/emails-templates-screens.blade.php'));
    $rules = (string) file_get_contents(resource_path('views/admin/partials/review-request-rules.blade.php'));

    expect(substr_count($screen, "@include('admin.partials.review-request-rules')"))->toBe(1)
        ->and($screen)->toContain("ed.template === 'review_request' && window.kbbRvRules")
        ->and($screen)->toContain("['rules', 'Schedule & rules']");

    foreach (['getBoundingClientRect', 'offsetWidth', 'offsetHeight', 'offsetTop', 'clientWidth', 'clientHeight', 'getComputedStyle', 'scrollHeight', 'elementFromPoint', 'setInterval', 'setTimeout'] as $api) {
        expect($rules)->not->toContain($api);
    }

    // Every value from the server goes through esc() before innerHTML.
    preg_match_all("/\\+ (d|st|r|f|o|p|cur)\\.[a-z_]+(?:\\[[^\\]]+\\])? \\+/", $rules, $raw);
    expect($raw[0])->toBe([]);

    // And the console renders with it.
    $html = $this->actingAs(rvaAdmin('owner'), 'admin')->get('/admin')->assertOk()->getContent();
    expect(substr_count($html, 'window.kbbRvRules = {'))->toBe(1);
});
