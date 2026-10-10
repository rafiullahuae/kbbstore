<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Lane ER's two route files, registered for a test the way they will be
 * mounted (CLAUDE.md: register in the test, never assert the require is
 * absent). routes/marketing-report-admin.php goes in the admin-api group with
 * Lane MK's stack; routes/marketing-open-public.php at the top level, `web`.
 * Once the integrator has required them, this finds them and adds nothing.
 */
final class MarketingReportRoutes
{
    public static function wire(Application $app): void
    {
        MarketingEmailsRoutes::wire($app);

        $router = RouteFacade::getFacadeRoot();
        $haveAdmin = false;
        $havePublic = false;

        foreach ($router->getRoutes()->getRoutes() as $existing) {
            $haveAdmin = $haveAdmin || $existing->uri() === 'admin-api/email-marketing/reports/{id}/recipients';
            $havePublic = $havePublic || $existing->uri() === 'email/o/{token}.gif';
        }

        if ($haveAdmin && $havePublic) {
            return;
        }

        $kept = new RouteCollection();

        foreach ($router->getRoutes() as $route) {
            $kept->add($route);
        }

        $router->setRoutes($kept);

        if (! $haveAdmin) {
            RouteFacade::middleware(MarketingEmailsRoutes::STACK)->prefix('admin-api')->group(base_path('routes/marketing-report-admin.php'));
        }

        if (! $havePublic) {
            RouteFacade::middleware('web')->group(base_path('routes/marketing-open-public.php'));
        }

        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }
}
