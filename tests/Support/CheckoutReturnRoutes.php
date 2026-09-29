<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Mounts routes/checkout-return.php the way the integrator is told to.
 *
 * CLAUDE.md forbids this lane from editing routes/web.php, so the replacement
 * for GET /checkout/pending ships in a file of its own with the edit written
 * out in its header. Nothing dispatches to it until somebody wires that up —
 * and the return leg from Tabby and Tamara is the one path on this shop that a
 * DECLINED shopper walks, so leaving it undriven until a package shipped it
 * would be leaving the failure path untested, which is the whole brief.
 *
 * NOTE WHAT THIS IS *NOT*. It is not an assertion that the route is unwired.
 * tests/Feature/CheckoutPlacingOverlayTest.php pins the FINISHED state —
 * `substr_count($web, "require __DIR__.'/checkout-return.php';") === 1` —
 * because the other assertion goes red the moment the integrator does the one
 * thing this lane asked for, and CLAUDE.md records three rounds lost to it.
 *
 * THE STACK IS `web` AND ONLY `web`, and at the storefront level with no
 * prefix: the closure this file replaces sits in routes/web.php beside
 * `checkout`, `checkout.place` and `checkout.success`, in no group at all.
 * Applying a stack here that the real mount does not apply would be testing a
 * pipeline nothing ships — and this route reads the shopper's session and their
 * cart cookie, so the session middleware is exactly what it needs.
 *
 * IDEMPOTENT. Several cases in one file call wire(), and this route declares
 * the same name as the closure already in routes/web.php — Laravel's
 * RouteCollection keys its name list on the last registration, so registering
 * twice is harmless but pointless, and re-registering after the integrator has
 * wired it for real would be indistinguishable from the harness working.
 */
final class CheckoutReturnRoutes
{
    /** The group routes/web.php mounts this file inside: the storefront level. */
    public const STACK = ['web'];

    /** The one URI the file adds, without a leading slash. */
    public const URI = 'checkout/pending';

    public static function wire(Application $app): void
    {
        self::reclaimContainer($app);

        /*
         * Resolve the HTTP kernel first. The middleware ALIASES are registered
         * on the router by Kernel::syncMiddlewareToRouter(), which runs in the
         * kernel's constructor and nowhere else. Without it a pipeline that
         * names one dies with a 500 — still a refusal, so a guard test would
         * pass for the wrong reason.
         */
        $app->make(\Illuminate\Contracts\Http\Kernel::class);

        $router = RouteFacade::getFacadeRoot();

        foreach ($router->getRoutes()->getRoutes() as $existing) {
            if ($existing->uri() === self::URI
                && str_contains((string) $existing->getActionName(), 'CheckoutReturnController')) {
                return;
            }
        }

        /*
         * The router is serving a CompiledRouteCollection here — the migration
         * set runs route:cache — and routes added to one of those are not
         * matched. Copying into a plain RouteCollection first is what makes a
         * route registered at runtime actually dispatch.
         */
        $kept = new RouteCollection();

        foreach ($router->getRoutes() as $route) {
            $kept->add($route);
        }

        $router->setRoutes($kept);

        RouteFacade::middleware(self::STACK)->group(base_path('routes/checkout-return.php'));

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
}
