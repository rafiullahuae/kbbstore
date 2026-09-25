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
 * Mounts routes/homepage-preview-admin.php the way the integrator is told to.
 *
 * CLAUDE.md forbids this lane from editing routes/web.php, so the preview route
 * ships in its own file with the require line in its header. Nothing dispatches
 * to it until somebody wires that up — and an endpoint that renders a whole
 * page and is never driven in a suite is an endpoint whose guard nobody has
 * checked. This is the same harness Tests\Support\HtmlBlocksAdminRoutes built
 * for the same reason; the notes below are its, kept because every one of them
 * is still load-bearing.
 */
final class HomepagePreviewRoutes
{
    /**
     * The exact stack routes/web.php's admin-api group applies.
     *
     * ONE middleware() call with the whole array, not three chained ones.
     * RouteRegistrar::middleware() REPLACES the pending middleware rather than
     * appending to it, so a chained form registers only the last one — and a
     * guard test written against that harness passes against nothing.
     */
    public const STACK = ['web', 'auth:admin', NoStoreAdminApi::class];

    public static function wire(Application $app): void
    {
        self::reclaimContainer($app);

        /*
         * Resolve the HTTP kernel first, or the guard is not what it appears.
         * The `auth` middleware ALIAS is registered on the router by
         * Kernel::syncMiddlewareToRouter(), which runs in the kernel's
         * constructor and nowhere else. Without it the pipeline resolves the
         * AuthManager instead and the request dies with a 500 — still a
         * refusal, so a guard test would pass for the wrong reason.
         */
        $app->make(\Illuminate\Contracts\Http\Kernel::class);

        $router = RouteFacade::getFacadeRoot();

        // The migration set runs route:cache, so the router is serving a
        // CompiledRouteCollection, and routes added to one of those are not
        // matched. Copying into a plain RouteCollection first is what makes a
        // route registered at runtime actually dispatch.
        $kept = new RouteCollection();

        foreach ($router->getRoutes() as $route) {
            $kept->add($route);
        }

        $router->setRoutes($kept);

        RouteFacade::middleware(self::STACK)
            ->prefix('admin-api')
            ->group(base_path('routes/homepage-preview-admin.php'));

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }

    /**
     * See the note in tests/Pest.php: the migration set runs config:cache and
     * route:cache, each of which constructs a throwaway Application and points
     * the container, the facade root and Eloquent's connection resolver at it.
     * Nothing puts them back.
     */
    private static function reclaimContainer(Application $app): void
    {
        Container::setInstance($app);

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        Model::setConnectionResolver($app->make('db'));
        Model::setEventDispatcher($app->make('events'));
    }

    /**
     * Every route this lane's file registers, read off the ROUTER rather than
     * written out here — a hand-kept list keeps passing after somebody adds a
     * route and forgets to add it to the list, which is the one case a guard
     * test exists to catch.
     *
     * @return list<\Illuminate\Routing\Route>
     */
    public static function registered(): array
    {
        return collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'admin-api/homepage/preview'))
            ->values()
            ->all();
    }
}
