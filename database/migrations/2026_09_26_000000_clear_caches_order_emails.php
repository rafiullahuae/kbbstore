<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear compiled caches for the transactional order emails.
 *
 * The package is not a git deploy — it arrives as a zip applied through Store →
 * Core Updates — and it changes PHP classes that the server may already be
 * holding compiled copies of:
 *
 *   - MailServiceProvider gained a boot() that registers the order-mail
 *     observers. `bootstrap/cache/services.php` and `packages.php` record the
 *     provider list and what each provides; a stale copy is how a provider gets
 *     loaded without its new bindings.
 *   - ModuleRegistry gained a group and five rows. The Store → Modules screen
 *     and OrderMailer read from it.
 *   - MailSettings::SCHEMA gained `mail_merchant_address`, which is what puts the
 *     field on Store → Mail at all.
 *   - CheckoutController now dispatches the confirmation.
 *
 * `kbb.modules` is forgotten because the five new keys have no `module_toggles`
 * row — moduleEnabled() falls back to the registry default for a key it does not
 * find, and it caches the whole map forever. A map cached before this package
 * landed is not wrong about the new keys (they are absent either way), but it
 * costs nothing to drop and it removes the question.
 *
 * No route is added by this package, so the route cache is dropped only for the
 * same reason as always: it is cheap and a stale one has cost this project a
 * release before.
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

        Cache::forget('kbb.modules');
        Cache::forget('kbb.settings');
        Cache::forget('kbb.settings.map');

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        if (app()->runningInConsole()) {
            echo "Cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
