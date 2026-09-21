<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for Appearance → Cart page.
 *
 * NEW ROUTES, so the compiled route table is the point of this file rather
 * than a precaution: the router dispatches against bootstrap/cache/routes-*.php
 * and not against routes/web.php, so this package can land complete and every
 * call the new screen and the new address sheet make still answer 404.
 *
 * AND A CHANGED BLADE, which matters as much here. The cart page's own view is
 * compiled into storage/framework/views; a stale compiled copy is a shop where
 * the setting saves, the screen says it saved, and the page goes on rendering
 * exactly what it rendered before. That is this project's signature failure —
 * a control that saves and does nothing — and it is why the view cache is on
 * the list below rather than only the route table.
 *
 * What changed:
 *
 *   routes/cart-address.php           new. GET/POST /cart/address and POST
 *                                     /cart/address/{id}/choose, in the `web`
 *                                     group, NOT in routes/api.php.
 *
 *   routes/cart-page-admin.php        new. GET/POST /admin-api/cart-page and
 *                                     GET /admin-api/cart-page/products.
 *
 *   app/Services/CartPage.php         new. The schema, the defaults, the
 *                                     clamps, and the custom properties the
 *                                     page is sized from.
 *
 *   app/Http/Controllers/Store/CartAddressController.php
 *                                     new. The sheet's three calls. A guest's
 *                                     chosen address lives in the session; a
 *                                     signed-in shopper's goes through
 *                                     $customer->addresses(), so a wrong id is
 *                                     a 404 and never a 403.
 *
 *   app/Http/Controllers/Admin/CartPageApiController.php
 *                                     new. Fields, save, and the catalogue
 *                                     search behind the rail picker.
 *
 *   app/Support/AdminCapabilities.php cartpage.manage — owner, manager, editor.
 *
 *   app/Models/Address.php            `label` added to $fillable, for the
 *                                     Home/Office pill. The column arrives in
 *                                     2026_11_28_000000_add_address_label.
 *
 *   resources/views/store/cart.blade.php and
 *   resources/views/store/cart-inner.blade.php
 *                                     the squeezed layout, all of it behind
 *                                     `layout = squeeze`, which ships off.
 *
 *   resources/css/kbb/kbb-cart.css    the new rules, every one of them scoped
 *                                     under .cpg-squeeze.
 *
 *   resources/views/admin/app.blade.php and
 *   resources/views/admin/partials/cart-page-screen.blade.php
 *                                     the screen. A stale compiled view is how
 *                                     a package lands and the screen it adds
 *                                     goes on not existing.
 *
 * NO SETTING ROWS ARE WRITTEN HERE, and that is the point rather than an
 * omission. CartPage::SCHEMA carries every default and every reader goes
 * through all(), so an absent row and a row holding the default are the same
 * thing — and the default of `layout` is `classic`, which is the page exactly
 * as it renders today. Seeding rows would make "never touched" indistinguish-
 * able from "set back to the default", and would be the one way this package
 * could change a live shop's cart page by applying it.
 *
 * No schema changes here. The one column this package adds is in its own
 * migration, and nothing positions a column with an AFTER clause — the thing
 * that made nine earlier migrations silent no-ops on MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cleared = 0;

        foreach ([
            storage_path('framework/views/*.php'),
            base_path('bootstrap/cache/config.php'),
            base_path('bootstrap/cache/routes-*.php'),
            base_path('bootstrap/cache/services.php'),
            base_path('bootstrap/cache/packages.php'),
        ] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (is_file($file) && @unlink($file)) {
                    $cleared++;
                }
            }
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files; the cart page's address calls and the\n"
                . "Appearance -> Cart page screen can now be reached.\n";
        }
    }

    public function down(): void {}
};
