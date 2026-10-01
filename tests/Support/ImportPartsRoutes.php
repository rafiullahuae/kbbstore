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
 * Mounts routes/import-parts-admin.php the way that file's header asks.
 *
 * CLAUDE.md forbids this lane from editing routes/web.php, so the five routes
 * ship in their own file with the require line in that file's header. Without
 * this harness nothing would dispatch to the controller until somebody else
 * wired it up, which would leave a FILE-WRITE endpoint's capability guard and
 * its path validation untested until a package shipped them.
 *
 * This is the shape CLAUDE.md asks for in as many words: *"A lane that
 * genuinely needs its routes registered before they are mounted should do it
 * the way `tests/Support/UgcAdminRoutes.php` and `HomepagePreviewRoutes` do —
 * register the group in the test — rather than by asserting the absence of the
 * require."* Nothing in this lane asserts that the require is absent; the pin
 * is on the FINISHED state, which is the thing that can regress.
 *
 * ONE middleware() CALL, NOT TWO — the trap the sibling harnesses document:
 * RouteRegistrar::middleware() REPLACES the pending middleware rather than
 * appending, so chaining two calls registers routes carrying only the second
 * and a guard test written against that harness passes against nothing.
 */
final class ImportPartsRoutes
{
    /** The exact stack routes/web.php's admin-api group applies. */
    public const STACK = ['web', 'auth:admin', NoStoreAdminApi::class];

    /** Every path this lane's file adds, without the admin-api prefix. */
    public const PATHS = [
        'import/part/limits',
        'import/part/begin',
        'import/part/finish',
        'import/part/abandon',
        'import/part',
    ];

    public static function wire(Application $app): void
    {
        self::reclaimContainer($app);

        /*
         * Resolve the HTTP kernel first, or the guard is not what it appears:
         * the `auth` middleware ALIAS is registered on the router by
         * Kernel::syncMiddlewareToRouter(), which runs in the kernel's
         * constructor and nowhere else. Without it the pipeline resolves the
         * AuthManager and the request dies with a 500 — still a refusal, so a
         * guard test would pass for the wrong reason.
         */
        $app->make(\Illuminate\Contracts\Http\Kernel::class);

        $router = RouteFacade::getFacadeRoot();

        foreach ($router->getRoutes()->getRoutes() as $existing) {
            // Idempotent: several cases in one file call this, and registering
            // the same five routes twice makes the name lookup ambiguous.
            if ($existing->uri() === 'admin-api/import/part') {
                return;
            }
        }

        // The router may be serving a CompiledRouteCollection — the migration
        // set runs route:cache — and routes added to one of those are not
        // matched. Copying into a plain RouteCollection first is what makes a
        // route registered at runtime actually dispatch.
        $kept = new RouteCollection;

        foreach ($router->getRoutes() as $route) {
            $kept->add($route);
        }

        $router->setRoutes($kept);

        RouteFacade::middleware(self::STACK)
            ->prefix('admin-api')
            ->group(base_path('routes/import-parts-admin.php'));

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }

    /**
     * Every route this lane's file added, taken from the ROUTER rather than
     * from PATHS, so a route added to the file and forgotten here still shows
     * up in a test that asserts over all of them.
     *
     * @return list<\Illuminate\Routing\Route>
     */
    public static function registered(): array
    {
        return collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn ($r) => $r->uri() === 'admin-api/import/part'
                || str_starts_with($r->uri(), 'admin-api/import/part/'))
            ->values()
            ->all();
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
}
