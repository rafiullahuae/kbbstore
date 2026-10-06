<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Lane PN: mount routes/push-admin.php the way docs/pn-wiring.json will
 * (inside the guarded admin-api group), for a test running before the
 * integrator has. A no-op once it is wired. The shop app's own routes come
 * from SiteAppPushRoutes, as Lane NT's tests take them.
 */
final class PushAdminRoutes
{
    public static function wire(Application $app): void
    {
        SiteAppPushRoutes::wire($app);

        $router = RouteFacade::getFacadeRoot();
        foreach ($router->getRoutes()->getRoutes() as $route) {
            if ($route->uri() === 'admin-api/push') {
                return;
            }
        }

        RouteFacade::middleware(SiteAppRoutes::ADMIN_STACK)->prefix('admin-api')->group(base_path('routes/push-admin.php'));
        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }
}
