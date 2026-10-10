<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Lane RV: mounts routes/review-request-public.php (web group) and
 * routes/review-request-admin.php (inside the admin-api stack) for a test, the
 * way EmailMarketingRoutes does — and the email templates routes the editor
 * needs. A no-op for each file once the integrator has wired it into
 * routes/web.php, so the same tests run before and after (CLAUDE.md: pin the
 * finished state, never the absence).
 */
final class ReviewRequestRoutes
{
    public static function wire(Application $app): void
    {
        EmailMarketingRoutes::wire($app);

        $router = RouteFacade::getFacadeRoot();
        $have = [];

        foreach ($router->getRoutes()->getRoutes() as $existing) {
            $have[$existing->uri()] = true;
        }

        $public = ! isset($have['review-order/{token}']);
        $admin = ! isset($have['admin-api/emails/review-requests']);

        if (! $public && ! $admin) {
            return;
        }

        $kept = new RouteCollection();

        foreach ($router->getRoutes() as $route) {
            $kept->add($route);
        }

        $router->setRoutes($kept);

        if ($public) {
            RouteFacade::middleware('web')->group(base_path('routes/review-request-public.php'));
        }

        if ($admin) {
            RouteFacade::middleware(EmailsAdminRoutes::STACK)->prefix('admin-api')->group(base_path('routes/review-request-admin.php'));
        }

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }
}
