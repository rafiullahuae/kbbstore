<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Http\Middleware\CheckRedirects;
use App\Http\Middleware\ResolveLocaleSlugs;
use App\Http\Middleware\SetLocaleFromPath;
use Illuminate\Contracts\Http\Kernel as KernelContract;

/**
 * Puts `ResolveLocaleSlugs` into the global stack the way the integrator is told
 * to, so the Arabic-slug policy can be exercised end to end before it is wired.
 *
 * WHY THIS EXISTS RATHER THAN AN ASSERTION THAT IT IS NOT WIRED. CLAUDE.md is
 * explicit: a lane that cannot edit the file that mounts its work must not pin
 * the absence of the mount, because that assertion goes red the moment the
 * integrator does the one thing the lane asked for, and the only way to green it
 * again is to unmount the feature. Three lanes paid for that in one day. So this
 * file is the other route the same rule points at — register it in the test, the
 * way Tests\Support\UgcAdminRoutes and HomepagePreviewRoutes do for routes.
 *
 * WHERE IT GOES, AND WHY THE POSITION IS THE WHOLE POINT. It must run AFTER
 * SetLocaleFromPath (it needs the bound locale and /ar off the path) and AFTER
 * CheckRedirects (running it first turns the retrofit's own redirect into an
 * infinite bounce — ResolveLocaleSlugs' header has the argument). Inserting it
 * relative to the classes already in the stack, rather than appending, is what
 * makes this harness exercise the order the integrator is being asked for rather
 * than a different one that happens to work.
 *
 * THE ONE LINE THE INTEGRATOR ADDS, in `App\Providers\AppServiceProvider::boot()`
 * beside the CheckRedirects registration that is already there:
 *
 *     $kernel->pushMiddleware(\App\Http\Middleware\ResolveLocaleSlugs::class);
 *
 * `pushMiddleware`, because it must land after CheckRedirects, which
 * AppServiceProvider prepends. `bootstrap/app.php` is on BuildPackage::NEVER_SHIP
 * so a registration written only there could never reach the live server, which
 * is how /ar 404'd on the owner's shop for a month.
 */
final class LocaleSlugMiddleware
{
    /** @return list<class-string> the stack as it stands after wiring */
    public static function wire(): array
    {
        $kernel = app(KernelContract::class);

        $stack = self::stack($kernel);

        if (in_array(ResolveLocaleSlugs::class, $stack, true)) {
            return $stack;
        }

        // Straight after whichever of the two runs last, so the harness cannot
        // accidentally test a looser order than the one being asked for.
        $anchor = -1;

        foreach ([SetLocaleFromPath::class, CheckRedirects::class] as $class) {
            $at = array_search($class, $stack, true);

            if ($at !== false) {
                $anchor = max($anchor, (int) $at);
            }
        }

        if ($anchor < 0) {
            $stack[] = ResolveLocaleSlugs::class;
        } else {
            array_splice($stack, $anchor + 1, 0, [ResolveLocaleSlugs::class]);
        }

        self::setStack($kernel, $stack);

        return $stack;
    }

    /** @return list<class-string> */
    public static function stack(KernelContract $kernel): array
    {
        $property = new \ReflectionProperty($kernel, 'middleware');
        $property->setAccessible(true);

        /** @var list<class-string> */
        return array_values($property->getValue($kernel));
    }

    private static function setStack(KernelContract $kernel, array $stack): void
    {
        $property = new \ReflectionProperty($kernel, 'middleware');
        $property->setAccessible(true);
        $property->setValue($kernel, array_values($stack));
    }
}
