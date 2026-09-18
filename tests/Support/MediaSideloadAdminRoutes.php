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
 * Mounts routes/media-sideload-admin.php the way the integrator is told to.
 *
 * A deliberate sibling of Tests\Support\UrlsMediaAdminRoutes, copied rather
 * than shared for the reason that file gives about ImportAdminRoutes: its STACK
 * constant is that lane's statement about its own endpoints, and two lanes
 * asserting the same thing about two different route files is not duplication
 * worth removing — a shared base means one lane's edit silently changes what
 * another lane's guard test is testing.
 *
 * ONE middleware() CALL, NOT TWO: RouteRegistrar::middleware() REPLACES the
 * pending middleware rather than appending, so a chained pair registers routes
 * carrying only the second one, and a guard test written against such a harness
 * passes against nothing.
 */
final class MediaSideloadAdminRoutes
{
    /** The exact stack routes/web.php's admin-api group applies. */
    public const STACK = ['web', 'auth:admin', NoStoreAdminApi::class];

    public static function wire(Application $app): void
    {
        Container::setInstance($app);

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        Model::setConnectionResolver($app->make('db'));
        Model::setEventDispatcher($app->make('events'));

        // The `auth` middleware ALIAS is registered on the router by
        // Kernel::syncMiddlewareToRouter() and nowhere else. Without resolving
        // the kernel first the pipeline dies with a 500 — still a refusal, so a
        // 401 assertion would pass for the wrong reason.
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
            ->group(base_path('routes/media-sideload-admin.php'));

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }

    /**
     * Every route THIS lane's file added.
     *
     * Filtered by the four exact URIs rather than by the shared
     * `admin-api/urls-media/` prefix, because Lane GB's file lives under the
     * same prefix and a test that swept the prefix would silently start
     * asserting over another lane's endpoints the day both are mounted.
     *
     * @return list<string>
     */
    public const URIS = [
        'admin-api/urls-media/progress',
        'admin-api/urls-media/progress-page',
        'admin-api/urls-media/sideload.csv',
        'admin-api/urls-media/sideload',
    ];

    /** @return list<\Illuminate\Routing\Route> */
    public static function registered(): array
    {
        return collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn ($r) => in_array($r->uri(), self::URIS, true))
            ->values()
            ->all();
    }
}
