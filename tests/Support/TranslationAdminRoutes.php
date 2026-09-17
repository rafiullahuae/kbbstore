<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Http\Middleware\NoStoreAdminApi;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Mounts routes/translations-admin.php the way the integrator is told to.
 *
 * CLAUDE.md forbids this lane from editing routes/web.php, so the translation
 * routes ship unmounted with the require line in their header. Without this
 * helper every test below would be testing a 404, and the first time anybody
 * found out whether the guard worked would be after a package shipped an
 * endpoint that SPENDS THE OWNER'S MONEY on a third-party API and writes text
 * the storefront renders to every shopper.
 *
 * ONE middleware() CALL, NOT TWO — RouteRegistrar::middleware() REPLACES the
 * pending middleware rather than appending, so a chained pair registers routes
 * carrying only the last stack and a guard test written against that harness
 * passes against nothing. Same reasoning, and the same shape, as
 * Tests\Support\OrdersAdminRoutes.
 *
 * Idempotent, because several tests in one file mount it and a second
 * registration would add a duplicate route rather than replacing the first.
 */
final class TranslationAdminRoutes
{
    /** The exact stack routes/web.php's admin-api group applies. */
    public const STACK = ['web', 'auth:admin', NoStoreAdminApi::class];

    private static bool $mounted = false;

    public static function mount(): void
    {
        $app = app();

        // See the long note in tests/Pest.php: the migration set runs
        // config:cache and route:cache, each of which builds a throwaway
        // Application and re-points the container and the facade root at it.
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        Model::setConnectionResolver($app->make('db'));
        Model::setEventDispatcher($app->make('events'));

        /*
         * The HTTP kernel first, or the guard is not what it appears: the
         * `auth` and `throttle` ALIASES are registered on the router by
         * Kernel::syncMiddlewareToRouter(), which runs in the kernel's
         * constructor and nowhere else.
         */
        $app->make(\Illuminate\Contracts\Http\Kernel::class);

        if (self::$mounted && self::registered() !== []) {
            return;
        }

        $router = RouteFacade::getFacadeRoot();

        // The router may be serving a CompiledRouteCollection, and routes added
        // to one of those are never matched.
        $kept = new RouteCollection();

        foreach ($router->getRoutes() as $route) {
            $kept->add($route);
        }

        $router->setRoutes($kept);

        RouteFacade::middleware(self::STACK)
            ->prefix('admin-api')
            ->group(base_path('routes/translations-admin.php'));

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();

        self::$mounted = true;
    }

    /** @return list<\Illuminate\Routing\Route> */
    public static function registered(): array
    {
        return collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'admin-api/translations'))
            ->values()
            ->all();
    }
}
