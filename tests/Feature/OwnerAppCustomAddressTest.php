<?php

declare(strict_types=1);

/*
 * Platform → Users & Roles → Owner app → (the address card) → Custom address
 * (Lane OA3). The owner, 5 October: "i need to be able to change the back
 * login url for the app owner." He types the address; the server decides
 * whether it may be used, and saving it behaves exactly like New address.
 */

use App\Models\AdminUser;
use App\Services\OwnerApp\OwnerAppPath;
use App\Support\AdminRoles;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\Support\OwnerAppRoutes as OA;

beforeEach(function () {
    OA::wire($this->app);
    RateLimiter::clear('oa-admin-address');
});

function oa3cRewire(): void
{
    $router = Route::getFacadeRoot();
    $kept = new RouteCollection();
    foreach ($router->getRoutes() as $r) {
        if (! str_starts_with((string) $r->getName(), 'owner-app.')) {
            $kept->add($r);
        }
    }
    $router->setRoutes($kept);
    Route::middleware('web')->group(base_path('routes/owner-app.php'));
    $router->getRoutes()->refreshNameLookups();
    $router->getRoutes()->refreshActionLookups();
}

it('moves the app to the address the owner typed, and does everything New address does', function () {
    // MUTATION: drop the revoke loop from address() and the device row stays
    // live; skip OwnerAppPath::set() and the old link keeps answering.
    $owner = OA::admin();
    OA::member($owner);
    OA::enrol($this);
    $device = (int) DB::table('owner_app_devices')->value('id');
    DB::table('owner_app_push_subscriptions')->insert(['device_id' => $device, 'endpoint' => 'https://fcm.googleapis.com/fcm/send/x', 'endpoint_hash' => hash('sha256', 'x'),
        'p256dh' => 'k', 'auth' => 'a', 'created_at' => now(), 'updated_at' => now()]);
    $old = OA::base();
    @mkdir(base_path('bootstrap/cache'), 0777, true);
    file_put_contents($compiled = base_path('bootstrap/cache/routes-oa3-probe.php'), '<?php return [];');

    $r = $this->actingAs($owner, 'admin')->postJson('/admin-api/owner-app/address', ['path' => '  /Rafi_Store-2027/ '])->assertOk()
        ->assertJsonPath('url', 'http://localhost/rafi_store-2027/');

    // Short, and "rafi" (his own name) + "store": accepted, with a warning.
    expect((string) $r->json('hint'))->toContain('short')->toContain('ordinary words')
        ->and(OwnerAppPath::current())->toBe('rafi_store-2027')
        ->and(DB::table('owner_app_devices')->where('id', $device)->value('revoked_reason'))->toBe('address_changed')
        ->and(DB::table('owner_app_push_subscriptions')->count())->toBe(0)
        ->and(file_exists($compiled))->toBeFalse();

    oa3cRewire();
    $this->flushHeaders()->get($old)->assertNotFound();
    $this->flushHeaders()->get('/rafi_store-2027')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
});

it('refuses every address that breaks a rule, with the reason in plain words', function (mixed $path, string $says) {
    // MUTATION: return null at the top of customProblem() and every row is a 200.
    $owner = OA::admin();
    $before = OwnerAppPath::current();
    DB::table('pages')->insert(['title' => 'Spring', 'slug' => 'spring_sale-2027', 'status' => 'published', 'content' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('redirects')->insert(['source' => '/old_shop-link/x/', 'target' => '/shop/', 'code' => 301, 'created_at' => now(), 'updated_at' => now()]);

    $r = $this->actingAs($owner, 'admin')->postJson('/admin-api/owner-app/address', ['path' => $path === '@current' ? $before : $path])->assertStatus(422);

    expect((string) $r->json('errors.path.0'))->toContain($says)
        ->and(OwnerAppPath::current())->toBe($before);
})->with([
    'empty' => ['', 'Type the address'],
    'not a string' => [['rafi_store-2027'], 'Type the address'],
    'seven characters' => ['ab_cdef', '8–40 characters'],
    'forty-one characters' => [str_repeat('a', 20).'_'.str_repeat('b', 20), '8–40 characters'],
    'a space' => ['rafi store_27', 'lowercase letters'],
    'a dot' => ['rafi.store_27', 'lowercase letters'],
    'a slash inside' => ['rafi/store_27', 'lowercase letters'],
    'unicode' => ['rafí_store-27', 'lowercase letters'],
    'no underscore' => ['rafistore-2027', 'underscore'],
    'starts with _' => ['_rafistore27', 'Start and end'],
    'ends with -' => ['rafi_store27-', 'Start and end'],
    'the admin path' => ['admin_secret27', 'may not begin with “admin”'],
    'admin-api' => ['admin-api_x27', 'may not begin with “admin'],
    'api' => ['api_secret-27', 'may not begin with “api”'],
    'a route the shop serves' => ['refund_returns', 'already uses /refund_returns/'],
    'a published page' => ['spring_sale-2027', 'A page on the shop'],
    'a redirect under it' => ['old_shop-link', 'A redirect on the shop'],
    'the current address' => ['@current', 'already the app’s address'],
]);

it('lets only a Full Admin choose the address, even one handed ownerapp.manage', function () {
    // MUTATION: delete the isFull() check in address() and this is a 200.
    $roleId = (int) DB::table('admin_roles')->insertGetId(['slug' => 'oa3-keeper', 'name' => 'App keeper', 'tier' => 'support', 'is_preset' => false,
        'capabilities' => json_encode(['admin.access', 'ownerapp.manage']), 'created_at' => now(), 'updated_at' => now()]);
    $keeper = OA::admin('support', 'keeper@example.com', 'Kay Keeper');
    AdminUser::query()->whereKey($keeper->id)->update(['role_id' => $roleId]);
    Cache::forget(AdminRoles::CACHE_KEY);
    AdminRoles::flush();
    $keeper->refresh();
    $before = OwnerAppPath::current();

    $this->actingAs($keeper, 'admin')->postJson('/admin-api/owner-app/address', ['path' => 'kq7z_m2xw-9k4p'])->assertStatus(403);
    $this->actingAs($keeper, 'admin')->getJson('/admin-api/owner-app')->assertOk()->assertJsonPath('custom.full', false);
    expect(OwnerAppPath::current())->toBe($before);

    // A role without the capability never reaches the controller at all.
    $editor = OA::admin('editor', 'ed@example.com', 'Ed Itor');
    $this->actingAs($editor, 'admin')->postJson('/admin-api/owner-app/address', ['path' => 'kq7z_m2xw-9k4p'])->assertStatus(403);
    expect(OwnerAppPath::current())->toBe($before);
});

it('refuses when the address is pinned in .env', function () {
    config(['owner_app.path' => 'pinned_from_env_x1']);
    OwnerAppPath::forgetMemo();
    try {
        $this->actingAs(OA::admin(), 'admin')->postJson('/admin-api/owner-app/address', ['path' => 'kq7z_m2xw-9k4p'])->assertStatus(422)
            ->assertJsonPath('message', 'The address is set by KBB_OWNER_APP_PATH in .env and can only be changed there.');
    } finally {
        config(['owner_app.path' => '']);
        OwnerAppPath::forgetMemo();
    }
});

it('is rate limited: ten tries a minute, then 429', function () {
    // MUTATION: drop ->middleware('throttle:10,1,oa-admin-address') from routes/owner-app-admin.php.
    $owner = OA::admin();
    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($owner, 'admin')->postJson('/admin-api/owner-app/address', ['path' => 'x'])->assertStatus(422);
    }
    $this->actingAs($owner, 'admin')->postJson('/admin-api/owner-app/address', ['path' => 'kq7z_m2xw-9k4p'])->assertStatus(429);
});

it('takes a custom address on the dedicated host too, as the path on that host', function () {
    $owner = OA::admin();
    try {
        OwnerAppPath::setHost('owner.example.test');
        $this->actingAs($owner, 'admin')->postJson('/admin-api/owner-app/address', ['path' => 'kq7z_m2xw-9k4p3hv'])->assertOk()
            ->assertJsonPath('url', 'https://owner.example.test/kq7z_m2xw-9k4p3hv/')
            ->assertJsonMissingPath('hint');
        oa3cRewire();
        $this->flushHeaders()->get('http://owner.example.test/kq7z_m2xw-9k4p3hv')->assertOk();
        $this->flushHeaders()->get('http://localhost/kq7z_m2xw-9k4p3hv')->assertNotFound();
    } finally {
        OwnerAppPath::setHost('');
        oa3cRewire();
    }
});

it('warns about a weak address and never blocks one', function () {
    // MUTATION: make weakness() return null and the first two lines go red.
    expect(OwnerAppPath::weakness('owner_app-2027'))->toContain('short')->toContain('ordinary words')
        ->and(OwnerAppPath::weakness('secret_store-panel-2027'))->not->toContain('short')->toContain('ordinary words')
        ->and(OwnerAppPath::weakness('rafi_qz7m-k2xw9p4t', ['Rafi Owner']))->toBeNull()
        ->and(OwnerAppPath::weakness('kq7z_m2xw-9k4p'))->toContain('short')->not->toContain('ordinary words')
        ->and(OwnerAppPath::customProblem('owner_app-2027'))->toBeNull();
});

it('keeps an 8-character typed address mountable (valid() agrees with the custom rule)', function () {
    // DEFECT this would be: valid() kept its old 12-character floor, so a
    // saved 8–11 character address resolved to "not configured" and the app
    // vanished. MUTATION: put {10,62} back in valid().
    expect(OwnerAppPath::valid('ab_cdefg'))->toBeTrue()
        ->and(OwnerAppPath::valid('ab_cdef'))->toBeFalse();
    $this->actingAs(OA::admin(), 'admin')->postJson('/admin-api/owner-app/address', ['path' => 'kq_7zm2x'])->assertOk();
    OwnerAppPath::forgetMemo();
    expect(OwnerAppPath::current())->toBe('kq_7zm2x');
});

it('draws the control once, beside Copy link and New address, with the underscore reason on screen', function () {
    $partial = (string) file_get_contents(resource_path('views/admin/partials/owner-app-access.blade.php'));
    expect(substr_count($partial, 'data-oa="custom"'))->toBe(1)
        ->and(substr_count($partial, '+ custom() +'))->toBe(1)
        ->and($partial)->toContain('nothing you publish later can take over this link')
        ->toContain('Every phone is signed out')
        // No request per keystroke: the input handler only repaints the hint.
        ->and((string) preg_match("/addEventListener\('input'[^}]*api\(/s", $partial))->toBe('0');
});
