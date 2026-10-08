<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Http\Controllers\Store\SeoFilesController;
use App\Http\Middleware\NoStoreAdminApi;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Lane SEO: mount the feed's two route files the way docs/seo-wiring.json
 * blocks 1 and 2 put them in routes/web.php -- the public file in the web group
 * minus the session classes (beside /sitemap.xml), the admin file in the
 * guarded admin-api group -- so the tests exercise the wiring instruction now
 * and keep passing unchanged once the integrator applies it (a re-registration
 * of the same method + URI replaces rather than duplicates).
 */
final class MerchantFeedRoutes
{
    public const STACK = ['web', 'auth:admin', NoStoreAdminApi::class];

    public static function wire(): void
    {
        app()->make(\Illuminate\Contracts\Http\Kernel::class);

        $router = RouteFacade::getFacadeRoot();

        foreach ($router->getRoutes()->getRoutes() as $existing) {
            if ($existing->uri() === 'admin-api/merchant-feed') {
                return;
            }
        }

        // A CompiledRouteCollection does not match routes added at run time.
        $kept = new RouteCollection();

        foreach ($router->getRoutes() as $route) {
            $kept->add($route);
        }

        $router->setRoutes($kept);

        RouteFacade::middleware('web')->withoutMiddleware(SeoFilesController::STATELESS)
            ->group(base_path('routes/merchant-feed.php'));
        RouteFacade::middleware(self::STACK)->prefix('admin-api')->group(base_path('routes/merchant-feed-admin.php'));

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }
}
