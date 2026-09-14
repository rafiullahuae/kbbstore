<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Applies the routing change Lane B cannot make itself.
 *
 * CLAUDE.md forbids editing routes/web.php directly, so the Phase 9 routes
 * live in routes/kbb-brands-blog.php and the integrator wires them in. That
 * wiring is not a pure addition: the owner's URL answers reversed two choices
 * an earlier pass had already shipped, so three of web.php's routes are
 * REPLACED rather than added to (see the header of routes/kbb-brands-blog.php,
 * steps 1 and 2).
 *
 * Simply requiring the new file on top of web.php would not test the real
 * thing. Laravel matches the first route registered for a URI, so web.php's
 * /brands/ would keep serving the directory and the new 301 would never fire —
 * the suite would pass while production did the opposite.
 *
 * So this drops exactly the superseded URIs from the router's collection and
 * then loads the new file, which is the state web.php is in once the
 * integrator has made those edits. The list below is deliberately explicit: if
 * the integrator removes a different set of lines, these tests are no longer
 * describing the deployed router, and that is worth noticing.
 */
final class Phase9Routes
{
    /**
     * URIs (as Laravel normalises them — no leading or trailing slash) whose
     * web.php registration the Phase 9 routes replace.
     */
    private const SUPERSEDED = [
        // Was the brand directory; the owner moved that to
        // /korean-skincare-brands/, so this becomes a 301.
        'brands',
        // Was a 301 to /brands/; this is now the directory itself.
        'korean-skincare-brands',
        // Was a 301 to the filtered shop listing; now points at the brand's
        // own landing page.
        'brand/{slug}',
        // Was the article itself; articles moved to the site root.
        'skincare-guide/{slug}',
    ];

    /**
     * @param Application $app the application under test — pass $this->app.
     *        It cannot be resolved from the container here, because the
     *        container itself is what needs reclaiming; see reclaimContainer().
     */
    public static function wire(Application $app): void
    {
        self::reclaimContainer($app);

        $router = RouteFacade::getFacadeRoot();

        $kept = new RouteCollection();

        /** @var Route $route */
        foreach ($router->getRoutes() as $route) {
            if (in_array($route->uri(), self::SUPERSEDED, true)) {
                continue;
            }

            $kept->add($route);
        }

        $router->setRoutes($kept);

        // Required last, exactly as web.php requires it — the root-level slug
        // route inside only behaves correctly when it is registered after
        // every other route.
        require base_path('routes/kbb-brands-blog.php');

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }

    /**
     * Point the global container, the facade root and Eloquent's connection
     * resolver back at the application under test.
     *
     * The migration set is not inert. `warm_caches_2_60_4` calls
     * Artisan::call('route:cache') and 'config:cache', and both of those
     * commands build a *fresh* Application to read clean routes/config from —
     * see RouteCacheCommand::getFreshApplicationRoutes(). Constructing an
     * Application calls Container::setInstance() on itself, and booting it
     * re-points the facade root and Eloquent's static connection resolver at
     * that throwaway instance. Nothing puts them back.
     *
     * Under PHP-FPM that is invisible: the process ends with the request. In
     * the test runner it is not, and it splits the application in two:
     *
     *   - app(), the Route facade and every Model resolve through the
     *     discarded application;
     *   - $this->get() dispatches through $this->app's HTTP kernel.
     *
     * So route changes made through the facade were landing on a router that
     * never served a request, while the assertions still saw the old routes —
     * and, for the same reason, RefreshDatabase's transaction is opened on
     * $this->app's connection while writes go through the discarded one, which
     * is why the first test in a process is not isolated and its rows survive
     * the whole run.
     *
     * This is the same class of bug CLAUDE.md already records for
     * Setting::map(): "Fine under PHP-FPM, a trap in tests and queue workers."
     *
     * Fixed here rather than in tests/Pest.php because that file is shared
     * with every other lane; the root cause is worth fixing globally and is
     * flagged for the integrator.
     */
    private static function reclaimContainer(Application $app): void
    {
        Container::setInstance($app);

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        Model::setConnectionResolver($app->make('db'));
        Model::setEventDispatcher($app->make('events'));
    }

    /** Which controller action would actually serve this path? */
    public static function actionFor(string $path): string
    {
        return RouteFacade::getRoutes()
            ->match(Request::create($path, 'GET'))
            ->getActionName();
    }
}
