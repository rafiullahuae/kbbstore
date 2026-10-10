<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Http\Middleware\NoStoreAdminApi;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Mounts Lane EB's routes/email-health-admin.php inside the admin-api stack,
 * the way the integrator is told to — CLAUDE.md's "register the group in the
 * test" (Tests\Support\MarketingEmailsRoutes is the model), and a no-op once
 * routes/web.php requires the file, so the same tests hold before and after.
 * Also mounts Lane MK's files, which the sending tests here need.
 *
 * ONE middleware() CALL with the whole stack: RouteRegistrar::middleware()
 * REPLACES rather than appends (Tests\Support\CustomersAdminRoutes).
 */
final class EmailHealthRoutes
{
    public const STACK = ['web', 'auth:admin', NoStoreAdminApi::class];

    public static function wire(Application $app): void
    {
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        Model::setConnectionResolver($app->make('db'));
        Model::setEventDispatcher($app->make('events'));

        // The `auth` alias is registered on the router by the HTTP kernel's
        // constructor (Tests\Support\EmailsAdminRoutes has the long version).
        $app->make(\Illuminate\Contracts\Http\Kernel::class);

        $router = RouteFacade::getFacadeRoot();
        MarketingEmailsRoutes::wire($app);

        $haveAdmin = false;

        foreach ($router->getRoutes()->getRoutes() as $existing) {
            $haveAdmin = $haveAdmin || $existing->uri() === 'admin-api/email-health/overview';
        }

        if ($haveAdmin) {
            return;
        }

        // A CompiledRouteCollection does not match routes added at runtime.
        $kept = new RouteCollection();

        foreach ($router->getRoutes() as $route) {
            $kept->add($route);
        }

        $router->setRoutes($kept);

        RouteFacade::middleware(self::STACK)->prefix('admin-api')->group(base_path('routes/email-health-admin.php'));

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }

    /** @return list<\Illuminate\Routing\Route> */
    public static function admin(): array
    {
        return collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'admin-api/email-health/'))
            ->values()->all();
    }
}
