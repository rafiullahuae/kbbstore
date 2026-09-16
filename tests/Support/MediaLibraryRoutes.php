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
 * Mounts routes/media-library-admin.php the way the integrator is told to.
 *
 * CLAUDE.md forbids this lane from editing routes/web.php, so the Media Library
 * routes ship in their own file with the require line in the header. Nothing
 * dispatches to the controller until someone else wires it up, which leaves the
 * gap Lane D hit with payments and Lane T with customers: a mistake in the
 * guard would not surface until a package shipped an endpoint listing every
 * asset the store owns — including images on unpublished products — and a
 * DELETE that unlinks files out of the public web root, to anyone who asks.
 *
 * ONE middleware() CALL, NOT TWO. RouteRegistrar::middleware() REPLACES the
 * pending middleware rather than appending to it, so
 *
 *     RouteFacade::middleware('web')->middleware('auth:admin')->group(...)
 *
 * registers routes carrying `auth:admin` and NOT `web` — or, with the order
 * reversed, no guard at all while still reading as though it had one. A guard
 * test written against that harness passes against nothing. The whole stack
 * goes in one array below, and the tests read the middleware back off the
 * REGISTERED routes rather than trusting this file's intent.
 *
 * Deliberately a sibling of Tests\Support\ReviewsAdminRoutes rather than a
 * shared base class: that file belongs to another lane and is not this one's to
 * change.
 */
final class MediaLibraryRoutes
{
    /** The exact stack routes/web.php's admin-api group applies. */
    public const STACK = ['web', 'auth:admin', NoStoreAdminApi::class];

    public static function wire(Application $app): void
    {
        self::reclaimContainer($app);

        /*
         * Resolve the HTTP kernel first, or the guard is not what it appears.
         *
         * The `auth` middleware ALIAS is registered on the router by
         * Kernel::syncMiddlewareToRouter(), which runs in the kernel's
         * constructor and nowhere else. A test that never makes a real request
         * never builds the kernel, so 'auth' stays unresolved, the pipeline
         * resolves the AuthManager instead and the request dies with a 500 —
         * still a refusal, so a 401 assertion would be passing for the wrong
         * reason.
         */
        $app->make(\Illuminate\Contracts\Http\Kernel::class);

        $router = RouteFacade::getFacadeRoot();

        // The router is serving a CompiledRouteCollection here — the migration
        // set runs route:cache — and routes added to one of those are not
        // matched. Copying into a plain RouteCollection first is what makes a
        // route registered at runtime actually dispatch.
        $kept = new RouteCollection();

        foreach ($router->getRoutes() as $route) {
            $kept->add($route);
        }

        $router->setRoutes($kept);

        RouteFacade::middleware(self::STACK)
            ->prefix('admin-api')
            ->group(base_path('routes/media-library-admin.php'));

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }

    /**
     * See the long note in tests/Pest.php: the migration set runs config:cache
     * and route:cache, each of which constructs a throwaway Application and
     * points the container, the facade root and Eloquent's connection resolver
     * at it. Nothing puts them back, so anything reaching for app() afterwards
     * can be talking to a discarded application.
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
     * Every route this lane's file added, so a test can assert over all of them
     * rather than a list it has to remember to keep up to date.
     *
     * Filtered by CONTROLLER, not by URI: routes/web.php already registers
     * POST /admin-api/media/upload pointing at MediaUploadController, which is
     * another lane's file and is guarded by the same group for its own reasons.
     * Matching on the URI prefix would silently pull that route into this
     * lane's assertions and make them pass for somebody else's work.
     *
     * @return list<\Illuminate\Routing\Route>
     */
    public static function registered(): array
    {
        return collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_contains((string) $r->getAction('controller'), 'MediaLibraryApiController'))
            ->values()
            ->all();
    }
}
