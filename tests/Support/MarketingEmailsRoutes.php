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
 * Mounts Lane MK's two route files the way the integrator is told to:
 * routes/marketing-emails-admin.php inside the admin-api stack, and
 * routes/marketing-public.php at the storefront level (the `web` group).
 *
 * The CLAUDE.md way ("register the group in the test", as
 * Tests\Support\EmailsAdminRoutes and HomepagePreviewRoutes do) rather than
 * asserting the require is absent — and a no-op once routes/web.php mounts
 * them, so the same tests hold before and after the integrator's two lines.
 *
 * ONE middleware() CALL with the whole stack: RouteRegistrar::middleware()
 * REPLACES rather than appends (Tests\Support\CustomersAdminRoutes).
 */
final class MarketingEmailsRoutes
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
        $haveAdmin = false;
        $havePublic = false;

        foreach ($router->getRoutes()->getRoutes() as $existing) {
            $haveAdmin = $haveAdmin || $existing->uri() === 'admin-api/email-marketing/overview';
            $havePublic = $havePublic || $existing->uri() === 'email/u/{token}';
        }

        if ($haveAdmin && $havePublic) {
            return;
        }

        // A CompiledRouteCollection does not match routes added at runtime.
        $kept = new RouteCollection();

        foreach ($router->getRoutes() as $route) {
            $kept->add($route);
        }

        $router->setRoutes($kept);

        if (! $haveAdmin) {
            RouteFacade::middleware(self::STACK)->prefix('admin-api')->group(base_path('routes/marketing-emails-admin.php'));
        }

        if (! $havePublic) {
            RouteFacade::middleware('web')->group(base_path('routes/marketing-public.php'));
        }

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }

    /** @return list<\Illuminate\Routing\Route> */
    public static function admin(): array
    {
        return collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'admin-api/email-marketing/'))
            ->values()->all();
    }

    /** @return list<\Illuminate\Routing\Route> */
    public static function public(): array
    {
        return collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'email/'))
            ->values()->all();
    }
}
