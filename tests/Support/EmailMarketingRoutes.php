<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Lane EK: mounts routes/emails-templates-admin.php and routes/marketing-
 * emails-admin.php (inside the admin-api stack, as routes/web.php will) and
 * routes/marketing-public.php (in the web group) for a test, the way EmailsAdminRoutes does for Lane RK's file
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

        $templates = ! isset($have['admin-api/emails/customer']);
        $admin = ! isset($have['admin-api/email-marketing/overview']) && is_file(base_path('routes/marketing-emails-admin.php'));
        $public = ! isset($have['email/u/{token}']) && is_file(base_path('routes/marketing-public.php'));

        if (! $templates && ! $admin && ! $public) {
            return;
        }

        $kept = new RouteCollection();

        foreach ($router->getRoutes() as $route) {
            $kept->add($route);
        }

        $router->setRoutes($kept);

        if ($templates) {
            RouteFacade::middleware(EmailsAdminRoutes::STACK)->prefix('admin-api')->group(base_path('routes/emails-templates-admin.php'));
        }

        if ($admin) {
            RouteFacade::middleware(EmailsAdminRoutes::STACK)->prefix('admin-api')->group(base_path('routes/marketing-emails-admin.php'));
        }

        if ($public) {
            RouteFacade::middleware('web')->group(base_path('routes/marketing-public.php'));
        }

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }
}
