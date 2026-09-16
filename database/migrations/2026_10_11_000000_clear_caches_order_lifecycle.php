<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Clear compiled caches for the order lifecycle repairs. (Lane BK)
 *
 * NO ROUTE IS ADDED by this package, so the usual sharp edge does not apply —
 * but the cached files still have to go, for reasons that are specific enough
 * to be worth naming rather than copied.
 *
 * SERVICES, and this is the one that matters here. The refund email is driven
 * by model events registered in App\Services\Mail\OrderMailObserver::register(),
 * which MailServiceProvider::boot() calls, and this package REPLACES that
 * registration: one `Refund::saved` listener becomes a `Refund::created` and a
 * `Refund::updated` pair. bootstrap/cache/services.php is the compiled provider
 * manifest, and a stale one is how a host keeps booting the provider list it
 * cached rather than the one on disk. The failure mode if it is left is the
 * quiet kind this lane exists to remove: refunds would go on sending the
 * duplicate "we have sent your money back" that the split was written to stop,
 * and nothing on screen would say why.
 *
 * OPCACHE, for the standing reason in CLAUDE.md, and with more force than
 * usual. Every file this package changes is a long-lived class the server holds
 * compiled: Api\CheckoutController (delivery now priced from the shipping
 * zones), Admin\OrdersApiController (the bulk status path now emails the
 * customers it just moved), Mail\OrderRefunded (partial vs full) and
 * Services\Mail\OrderMailObserver. On a host with no shell access and no way to
 * restart PHP-FPM, an un-reset OPcache is exactly how packages 2.60.102-.106
 * came to revert three files and 500 every product page — still cited in
 * CLAUDE.md, still the reason this block is not optional.
 *
 * VIEWS AND CONFIG go too. No Blade file changed in this package, so the view
 * clear is precautionary rather than required; it is kept because the compiled
 * view directory is keyed by path with no content check, and the order email
 * templates are rendered by the mailables that DID change. Cheap, and the
 * alternative is reasoning about it again next time.
 *
 * NO SCHEMA CHANGE. Nothing here adds a column, a table or an index. In
 * particular nothing positions a column with an AFTER clause, the thing that
 * made nine earlier migrations in this directory silent no-ops on MySQL.
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
