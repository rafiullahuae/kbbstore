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
 * Mounts routes/checkout-sign-in.php (Lane CO) the way the integrator is told
 * to: in the `web` group, at the storefront level, with no prefix -- session,
 * CSRF and the shop firewall, which is what the real require gives it.
 *
 * CLAUDE.md forbids this lane from editing routes/web.php. This registers the
 * file for the test rather than asserting that it is not wired -- see the
 * "Do not pin that your own work is NOT wired up yet" rule -- and it is
 * idempotent, so it stays correct after the integrator adds the require.
 */
final class CheckoutSignInRoutes
{
    /** The group routes/web.php mounts this file inside: the storefront level. */
    public const STACK = ['web'];

    /** The one URI the file adds, without a leading slash. */
    public const URI = 'checkout/sign-in';

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
                && str_contains((string) $existing->getActionName(), 'CheckoutSignInController')) {
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

        RouteFacade::middleware(self::STACK)->group(base_path('routes/checkout-sign-in.php'));

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
