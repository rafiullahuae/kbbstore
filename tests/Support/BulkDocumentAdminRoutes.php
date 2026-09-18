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
 * Mounts routes/bulk-documents-admin.php the way the integrator is told to.
 *
 * CLAUDE.md forbids this lane from editing routes/web.php, so the bulk route
 * ships in its own file with the require line in the header, and nothing
 * dispatches to BulkDocumentController until somebody else wires it up. That
 * leaves the gap every lane before this one hit: a mistake in the guard would
 * not surface until a package shipped an endpoint that prints a HUNDRED
 * buyers' names, street addresses, phone numbers and order values on one page
 * built for a browser to show.
 *
 * A SIBLING OF Tests\Support\InvoiceAdminRoutes ON PURPOSE, and copied from it
 * rather than shared with it: that file belongs to Lane AE and is not this
 * one's to change, and the two mount different route files. The comments below
 * are its hard-won ones and are kept because they are still true here.
 *
 * ONE middleware() CALL, NOT TWO. RouteRegistrar::middleware() REPLACES the
 * pending middleware rather than appending to it, so
 *
 *     RouteFacade::middleware('web')->middleware('auth:admin')->group(...)
 *
 * registers routes carrying `auth:admin` and NOT `web` — or, with the order
 * reversed, no guard at all while still reading as though it had one. A guard
 * test written against that harness passes against nothing. The whole stack
 * goes in one array below, and BulkDocumentsTest reads the middleware back off
 * the REGISTERED route rather than trusting this file's intent.
 */
final class BulkDocumentAdminRoutes
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
         * still a refusal, so the test would pass for the wrong reason.
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
            ->group(base_path('routes/bulk-documents-admin.php'));

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
     * The one route this lane's file adds.
     *
     * Filtered by controller as well as by path, so the order routes
     * routes/web.php already registers are not swept in and silently counted as
     * this lane's work.
     *
     * @return list<\Illuminate\Routing\Route>
     */
    public static function registered(): array
    {
        return collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'admin-api/orders-bulk-documents'))
            ->filter(fn ($r) => str_contains((string) $r->getAction('controller'), 'BulkDocumentController'))
            ->values()
            ->all();
    }
}
