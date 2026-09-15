<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

/**
 * Clear compiled caches for "use this server's mail", and for the email redesign.
 *
 * WHAT CHANGED, and what stale bytecode would do with each:
 *
 *   MailSettings, MailConfigurator, MailTester, MailServiceProvider and the new
 *   ServerMailTransport. On stale bytecode the `kbb-server` transport is not
 *   registered while the settings say to use it, and every order email throws
 *   "Unsupported mail transport [kbb-server]" inside OrderMailer's swallow — a
 *   logged line and a customer who hears nothing, which is this release's own
 *   bug wearing a different hat.
 *
 *   bootstrap/cache/services.php and packages.php, because the provider's
 *   registered bindings changed. A stale services.php is how a provider's new
 *   afterResolving hook silently does not run.
 *
 *   Every compiled Blade view, because all thirteen email templates changed —
 *   the layout, the item table's new Qty column, the support block, the
 *   signature. A half-cleared view cache renders the new layout around an old
 *   items partial, or the other way round.
 *
 *   `kbb.modules`, because ModuleRegistry gained `email_show_logo` and the
 *   module map is a rememberForever cache.
 *
 * WHAT THIS MIGRATION DELIBERATELY DOES NOT DO: write a value for
 * `mail_transport`. The new default is computed in MailSettings, not seeded, so
 * an install that has already chosen SMTP — or chosen the log, on purpose, to
 * check a template — finds the same setting after this package lands.
 * ServerMailDefaultTest pins both halves of that.
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
