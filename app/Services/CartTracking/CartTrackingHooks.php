<?php

declare(strict_types=1);

namespace App\Services\CartTracking;

use App\Http\Middleware\BlockGate;
use App\Models\Setting;
use App\Services\Security\IpBlockList;

/**
 * Everything Cart Tracking registers at boot, in one call.         (Lane CT)
 *
 * One line in AppServiceProvider::boot() rather than three closures, for the
 * reason that file gives for MediaUsageWriter: it is shared by every lane.
 *
 *   1. BlockGate at the FRONT of the `web` and `api` groups. `web` carries
 *      the cart, the checkout and every form; `api` carries
 *      POST /api/checkout/session, which writes real orders without a cart
 *      and must not be a side door round a block. First in the group, so a
 *      refused request starts no session and runs nothing else. The groups
 *      run after routing, which is what lets the gate ask the ROUTE whether
 *      it is the admin.
 *      prependMiddlewareToGroup() array_searches before it unshifts, so a
 *      second boot in one process registers nothing twice.
 *
 *   2. The retention heartbeat (a stat() per request; see CartTrackingTick).
 *
 *   3. A settings write to any `ct_` key recompiles the block-list file,
 *      which carries the settings the request path reads — whichever screen
 *      or import made the write.
 */
final class CartTrackingHooks
{
    public static function register(object $kernel): void
    {
        if (method_exists($kernel, 'prependMiddlewareToGroup') && method_exists($kernel, 'getMiddlewareGroups')) {
            $groups = $kernel->getMiddlewareGroups();

            foreach (['web', 'api'] as $group) {
                if (isset($groups[$group])) {
                    $kernel->prependMiddlewareToGroup($group, BlockGate::class);
                }
            }
        }

        CartTrackingTick::register();

        $recompile = static function (Setting $setting): void {
            if (str_starts_with((string) $setting->getKey(), CartTrackingSettings::PREFIX)) {
                IpBlockList::rebuild();
            }
        };

        Setting::saved($recompile);
        Setting::deleted($recompile);
    }
}
