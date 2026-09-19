<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Http\Middleware\NoStoreAdminApi;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Mounts BOTH of Lane GO's route files the way the integrator is told to.
 *
 * Both, in one place, because the whole feature is the relationship between
 * them: an admin endpoint inside `auth:admin` that issues a baton, and an
 * endpoint OUTSIDE every guard that spends it. A harness that mounted only one
 * could not test either honestly — the guard test needs the group, and the
 * chain test needs a real loopback target with real middleware on it.
 *
 * A deliberate sibling of Tests\Support\ImportAdminRoutes and
 * Tests\Support\MediaSideloadAdminRoutes, copied rather than shared for the
 * reason those files give: a lane's STACK constant is that lane's statement
 * about its own endpoints, and a shared base means one lane's edit silently
 * changes what another lane's guard test is testing.
 *
 * ONE middleware() CALL PER GROUP, NOT TWO: RouteRegistrar::middleware()
 * REPLACES the pending middleware rather than appending, so a chained pair
 * registers routes carrying only the second one and a guard test written
 * against such a harness passes against nothing.
 */
final class ImportBackgroundRoutes
{
    /** The exact stack routes/web.php's admin-api group applies. */
    public const STACK = ['web', 'auth:admin', NoStoreAdminApi::class];

    /**
     * The stack a top-level require in routes/web.php applies — the same one
     * newsletter-public.php and checkout-card.php get, and nothing more.
     *
     * `web` INCLUDES ValidateCsrfToken. That is the point: routes/import-chain.php
     * excludes it AT THE ROUTE, and a harness that quietly left CSRF out of the
     * stack would make that exclusion untestable and would let a regression
     * that removed it pass.
     */
    public const PUBLIC_STACK = ['web'];

    public const ADMIN_URIS = [
        'admin-api/import/background',
        'admin-api/import/background-page',
        'admin-api/import/background-control',
    ];

    public const CHAIN_URI = 'import-chain/continue';

    public static function wire(Application $app): void
    {
        self::reclaimContainer($app);

        /*
         * Resolve the HTTP kernel first, or the guard is not what it appears:
         * the `auth` middleware ALIAS is registered on the router by
         * Kernel::syncMiddlewareToRouter() and nowhere else, and without it the
         * pipeline dies with a 500 — still a refusal, so a 401 assertion would
         * pass for the wrong reason. ValidateCsrfToken's alias arrives the same
         * way, which matters twice as much here.
         */
        $app->make(\Illuminate\Contracts\Http\Kernel::class);

        $router = RouteFacade::getFacadeRoot();

        // The migration set runs route:cache, so the router is serving a
        // CompiledRouteCollection and routes added to one are never matched.
        $kept = new RouteCollection;

        foreach ($router->getRoutes() as $route) {
            $kept->add($route);
        }

        $router->setRoutes($kept);

        RouteFacade::middleware(self::STACK)
            ->prefix('admin-api')
            ->group(base_path('routes/import-background-admin.php'));

        RouteFacade::middleware(self::PUBLIC_STACK)
            ->group(base_path('routes/import-chain.php'));

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }

    /** See the long note in tests/Pest.php. */
    private static function reclaimContainer(Application $app): void
    {
        Container::setInstance($app);

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        Model::setConnectionResolver($app->make('db'));
        Model::setEventDispatcher($app->make('events'));
    }

    /**
     * Every route this lane's two files added.
     *
     * Filtered by exact URI rather than by the `admin-api/import/` prefix,
     * because Lane AD's and Lane GF's files live under the same prefix and a
     * test that swept it would silently start asserting over their endpoints
     * the day all three are mounted.
     *
     * @return list<\Illuminate\Routing\Route>
     */
    public static function registered(): array
    {
        $uris = [...self::ADMIN_URIS, self::CHAIN_URI];

        return collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn ($r) => in_array($r->uri(), $uris, true))
            ->values()
            ->all();
    }

    /** @return list<\Illuminate\Routing\Route> */
    public static function adminRoutes(): array
    {
        return collect(self::registered())
            ->filter(fn ($r) => in_array($r->uri(), self::ADMIN_URIS, true))
            ->values()
            ->all();
    }

    public static function chainRoute(): ?\Illuminate\Routing\Route
    {
        return collect(self::registered())
            ->first(fn ($r) => $r->uri() === self::CHAIN_URI);
    }

    /** The CSRF middleware the chain route must exclude, named once. */
    public const CSRF = ValidateCsrfToken::class;
}
