<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the mail foundation package.
 *
 * Three separate reasons, any one of which would be enough:
 *
 *   - ROUTES. This package adds /admin-api/mail, /admin-api/mail (POST) and
 *     /admin-api/mail/test. A compiled route cache knows about none of them, so
 *     until it is cleared the Mail screen 404s on every request and looks
 *     broken rather than unmounted. Same convention every route-adding package
 *     here follows.
 *   - CONFIG. config/mail.php changed: the default mailer moves from `log` to
 *     `kbb`, and the new `kbb` entry is what MailConfigurator fills in at
 *     runtime. A stale bootstrap/cache/config.php keeps the old default, and
 *     the symptom is the worst one available — mail appears to send, writes to
 *     storage/logs, and nobody finds out until a customer says they never got
 *     the reset link.
 *   - SERVICES. bootstrap/providers.php gained MailServiceProvider. The
 *     compiled bootstrap/cache/services.php and packages.php are built from the
 *     provider list; without clearing them the provider never boots and the
 *     database settings are never applied.
 *
 * OPcache too: MailManager resolves through the container on a worker that may
 * be holding the previous compiled copy of config/mail.php.
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
            echo "Cleared {$cleared} compiled files.\n";
        }
    }

    public function down(): void {}
};
