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
 * Mounts routes/wallet-domain.php and routes/wallet-checkout.php the way the
 * integrator is told to.
 *
 * CLAUDE.md forbids this lane from editing routes/web.php, so the three routes
 * it adds ship in two files of their own with the require line in each file's
 * header. Nothing dispatches to either controller until somebody else wires
 * them up — which would otherwise leave Apple's domain-verification endpoint
 * and the figure a payment sheet opens with untested until a package shipped
 * them, and both are things that can only be got wrong once.
 *
 * ▲ THIS IS THE SANCTIONED WAY TO TEST AN UNMOUNTED LANE'S ROUTES, and it is
 * the pattern Tests\Support\BannersAdminRoutes and
 * Tests\Support\HomepagePreviewRoutes already set. The alternative is the one
 * CLAUDE.md names as having cost this project three round trips: an
 * `expect($web)->not->toContain('wallet-domain.php')` is green in this worktree
 * and goes RED the moment the integrator does the one thing this lane asked
 * for. WalletPaymentsTest pins the FINISHED state instead —
 * substr_count(...) === 1 — which is the state that can actually regress: zero
 * is "built, never wired up", and two serves Apple's file from two routes and
 * registers the amount endpoint twice.
 *
 * THE STACK IS `web` AND ONLY `web`. Both files declare their own exemptions
 * from inside — wallet-domain.php drops the five session and cookie classes the
 * crawl files drop, and wallet-checkout.php keeps all of them because it reads
 * the shopper's basket out of the session cookie. Applying a stack here that
 * the real mount does not apply would test a pipeline nothing ships.
 */
final class WalletRoutes
{
    /** The group routes/web.php mounts both files inside. */
    public const STACK = ['web'];

    /** The three URIs the two files add, without a leading slash. */
    public const URIS = [
        '.well-known/apple-developer-merchantid-domain-association',
        '.well-known/apple-developer-merchantid-domain-association.txt',
        'checkout/wallet/amount',
    ];

    public static function wire(Application $app): void
    {
        self::reclaimContainer($app);

        /*
         * Resolve the HTTP kernel first. The middleware ALIASES (`throttle`
         * among them, which wallet-checkout.php names) are registered on the
         * router by Kernel::syncMiddlewareToRouter(), which runs in the
         * kernel's constructor and nowhere else. Without it the pipeline dies
         * with a 500 — still a refusal, so a guard test would pass for the
         * wrong reason.
         */
        $app->make(\Illuminate\Contracts\Http\Kernel::class);

        $router = RouteFacade::getFacadeRoot();

        foreach ($router->getRoutes()->getRoutes() as $existing) {
            // Idempotent: several cases in one file call this, and registering
            // the same routes twice makes the name lookup ambiguous.
            if ($existing->uri() === self::URIS[0]) {
                return;
            }
        }

        // The router is serving a CompiledRouteCollection here — the migration
        // set runs route:cache — and routes added to one of those are not
        // matched. Copying into a plain RouteCollection first is what makes a
        // route registered at runtime actually dispatch.
        $kept = new RouteCollection();

        foreach ($router->getRoutes() as $route) {
            $kept->add($route);
        }

        $router->setRoutes($kept);

        // ONE middleware() call per group, not two chained: RouteRegistrar's
        // middleware() REPLACES the pending middleware rather than appending
        // to it, so a chained pair registers routes carrying only the second.
        RouteFacade::middleware(self::STACK)->group(base_path('routes/wallet-domain.php'));
        RouteFacade::middleware(self::STACK)->group(base_path('routes/wallet-checkout.php'));

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
}
