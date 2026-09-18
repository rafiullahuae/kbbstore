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
 * Applies the routing change Lane GA cannot make itself.
 *
 * CLAUDE.md forbids this lane editing routes/web.php, so the corrected
 * registrations for /blog and /post/{slug?} live in
 * routes/kbb-journal-legacy.php and the integrator applies them. The exact
 * anchor and replacement are in that file's header and in
 * docs/GA-SKINCARE-GUIDE.md §5.
 *
 * This drops exactly the two superseded URIs and then loads the new file,
 * which is the state web.php is in once the integrator has made the edit. Same
 * shape as Tests\Support\Phase9Routes, which is the precedent for how this repo
 * tests a web.php edit a lane may not make.
 *
 * ONE CORRECTION TO THE REASONING PHASE9ROUTES STATES, measured rather than
 * assumed, because Lane GA mutation-tested the drop list and the mutation did
 * not go red (docs/GA-SKINCARE-GUIDE.md §8). Phase9Routes says Laravel serves
 * the FIRST route registered for a URI. For two routes whose URIs differ that
 * is the effect; for two registrations of the SAME method and URI it is not.
 * RouteCollection::addToCollections() keys both of its lookups on
 * method . domain . uri, so a second registration of 'post/{slug?}' REPLACES
 * the first in the collection and the LAST one wins. Removing either entry
 * from SUPERSEDED below therefore leaves this file's tests passing.
 *
 * The list stays, for two reasons. It is what web.php actually looks like
 * after the edit, which is the state these tests claim to describe; and it is
 * the only thing that keeps them honest if the replacement is ever spelled
 * differently from the line it replaces — at which point last-wins stops
 * applying and order is all there is. It is documentation of the edit, not a
 * guard, and it is not claimed as one.
 */
final class JournalLegacyRoutes
{
    /**
     * URIs (as Laravel normalises them — no leading or trailing slash) whose
     * web.php registration routes/kbb-journal-legacy.php replaces.
     *
     * Deliberately explicit: if the integrator removes a different set of
     * lines, these tests have stopped describing the deployed router, and that
     * is worth a red suite rather than a quiet pass.
     */
    private const SUPERSEDED = [
        // 301 to the Journal index, built with route() — which drops the
        // trailing slash and knows nothing about /ar.
        'blog',
        // 301 to the article, same two faults.
        'post/{slug?}',
    ];

    /**
     * @param Application $app the application under test — pass $this->app.
     *        See Phase9Routes::reclaimContainer() for why the container has to
     *        be reclaimed rather than resolved; tests/Pest.php now does most of
     *        that per-test, and these four lines keep this helper usable on its
     *        own.
     */
    public static function wire(Application $app): void
    {
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        Model::setConnectionResolver($app->make('db'));

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

        require base_path('routes/kbb-journal-legacy.php');

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }

    /** Which controller action would actually serve this path? */
    public static function actionFor(string $path): string
    {
        return RouteFacade::getRoutes()
            ->match(Request::create($path, 'GET'))
            ->getActionName();
    }
}
