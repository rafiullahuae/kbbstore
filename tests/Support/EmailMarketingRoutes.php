<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Lane EK: mounts routes/emails-marketing-admin.php (inside the admin-api
 * stack, as routes/web.php will) and routes/emails-marketing-public.php (in
 * the web group) for a test, the way EmailsAdminRoutes does for Lane RK's file
 * — a no-op once the integrator has wired them, so the same tests run before
 * and after the wiring (CLAUDE.md: pin the finished state, never the absence).
 */
final class EmailMarketingRoutes
{
    public static function wire(Application $app): void
    {
        EmailsAdminRoutes::wire($app);

        $router = RouteFacade::getFacadeRoot();
        $have = [];

        foreach ($router->getRoutes()->getRoutes() as $existing) {
            $have[$existing->uri()] = true;
        }

        $admin = ! isset($have['admin-api/email-marketing/overview']);
        $public = ! isset($have['m/u/{token}']);

        if (! $admin && ! $public) {
            return;
        }

        $kept = new RouteCollection();

        foreach ($router->getRoutes() as $route) {
            $kept->add($route);
        }

        $router->setRoutes($kept);

        if ($admin) {
            RouteFacade::middleware(EmailsAdminRoutes::STACK)->prefix('admin-api')->group(base_path('routes/emails-marketing-admin.php'));
        }

        if ($public) {
            RouteFacade::middleware('web')->group(base_path('routes/emails-marketing-public.php'));
        }

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }
}
