<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Http\Middleware\NoStoreAdminApi;
use App\Models\AdminUser;
use App\Providers\OwnerAppServiceProvider;
use App\Services\OwnerApp\OwnerAppAuth;
use App\Services\OwnerApp\OwnerAppPath;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Testing\TestResponse;

/**
 * Mounts the owner app (Lane MAC) the way tools/mac-wire.php wires it, and
 * drives it the way a phone does.
 *
 * NOT an assertion that the app is unwired: OwnerAppWiringTest pins the
 * FINISHED state (each require exactly once). This harness only registers what
 * the router does not already carry, so it behaves the same before and after
 * the integrator runs the wire script.
 *
 * Why the app routes are registered here even when web.php requires them: the
 * routes read the secret address from the settings table while the router is
 * built, and in the first test of a process that is BEFORE RefreshDatabase has
 * migrated — so the table is empty and, by design, nothing is mounted.
 */
final class OwnerAppRoutes
{
    public static function wire(Application $app): void
    {
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        Model::setConnectionResolver($app->make('db'));
        Model::setEventDispatcher($app->make('events'));

        $app->make(\Illuminate\Contracts\Http\Kernel::class);
        OwnerAppPath::forgetMemo();
        OwnerAppPath::ensure();

        $router = RouteFacade::getFacadeRoot();
        $kept = new RouteCollection();
        foreach ($router->getRoutes() as $route) {
            $kept->add($route);
        }
        $router->setRoutes($kept);

        if (! $kept->hasNamedRoute('owner-app.shell')) {
            RouteFacade::middleware('web')->group(base_path('routes/owner-app.php'));
        }

        $hasAdmin = collect($kept->getRoutes())->contains(fn ($r) => $r->uri() === 'admin-api/owner-app');
        if (! $hasAdmin) {
            RouteFacade::middleware(['web', 'auth:admin', NoStoreAdminApi::class])->prefix('admin-api')
                ->group(base_path('routes/owner-app-admin.php'));
        }

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();

        $app->register(OwnerAppServiceProvider::class);
    }

    public static function base(): string
    {
        return '/'.OwnerAppPath::current();
    }

    /** An admin account on a legacy role (owner = Full Admin, manager, support, editor). */
    public static function admin(string $role = 'owner', string $email = 'owner@example.com', string $name = 'Rafi Owner'): AdminUser
    {
        return AdminUser::query()->create(['name' => $name, 'email' => $email, 'password' => 'secret-password', 'role' => $role]);
    }

    public static function member(AdminUser $admin, string $pin = '4826', bool $enabled = true): int
    {
        return (int) DB::table('owner_app_members')->insertGetId([
            'admin_user_id' => $admin->id, 'enabled' => $enabled, 'pin_hash' => Hash::make($pin),
            'pin_set_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string,string> cookie name => value, from a response */
    public static function cookies(TestResponse $r, array $into = []): array
    {
        foreach ($r->headers->getCookies() as $c) {
            if ($c->getValue() === '' || $c->getExpiresTime() < time()) {
                unset($into[$c->getName()]);
            } else {
                $into[$c->getName()] = (string) $c->getValue();
            }
        }

        return $into;
    }

    /**
     * Enrol a phone: returns [cookies, csrf].
     *
     * @return array{0: array<string,string>, 1: string}
     */
    public static function enrol($test, string $email = 'owner@example.com', string $pin = '4826'): array
    {
        $r = $test->withHeaders(['X-OA' => '1'])->postJson(self::base().'/api/enrol', ['email' => $email, 'pin' => $pin]);
        $r->assertOk();

        return [self::cookies($r), (string) $r->json('csrf')];
    }

    public static function get($test, string $path, array $cookies): TestResponse
    {
        return $test->withCredentials()->withUnencryptedCookies($cookies)->withHeaders(['X-OA' => '1'])->getJson(self::base().'/api/'.$path);
    }

    public static function post($test, string $path, array $data, array $cookies, ?string $csrf): TestResponse
    {
        $headers = ['X-OA' => '1'];
        if ($csrf !== null) {
            $headers['X-OA-CSRF'] = $csrf;
        }

        return $test->withCredentials()->withUnencryptedCookies($cookies)->withHeaders($headers)->postJson(self::base().'/api/'.$path, $data);
    }

    public static function deviceToken(array $cookies): string
    {
        return $cookies[OwnerAppAuth::DEVICE_COOKIE] ?? '';
    }
}
