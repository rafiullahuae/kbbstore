<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lane ER — the campaign report: opens, and the device of a click.
 *
 * The owner, 10 October: "for marketing emails i need the full report like
 * how many opened, how many clicked and came to the website". Lane MK left
 * opens out on purpose (D10: clicks and orders are the honest numbers). They
 * come in now, labelled as an estimate, and classified so the Apple Mail
 * Privacy Protection prefetch is counted apart (App\Services\Marketing\OpenPixel).
 *
 * On mkt_sends, written by ONE update by primary key per pixel load:
 *   first_open_at  the first load of the pixel, whatever loaded it
 *   open_count     every load (a proxy caches, so this is a floor)
 *   open_class     the best evidence seen: human > proxy > apple > scanner
 *   open_ua        what the user agent looked like (mail, proxy, apple, bot, none)
 *   open_ip        where it came from: apple, google, other. The class of the
 *                  address only, NEVER the address.
 *
 * On mkt_clicks: dev, the click's device (mobile / tablet / desktop / bot),
 * from the user agent, for the device split. The user agent itself is not kept.
 *
 * No ->after() (MigrationConventionTest).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('mkt_sends')) {
            Schema::table('mkt_sends', function (Blueprint $t) {
                if (! Schema::hasColumn('mkt_sends', 'first_open_at')) {
                    $t->timestamp('first_open_at')->nullable();
                }
                if (! Schema::hasColumn('mkt_sends', 'open_count')) {
                    $t->unsignedInteger('open_count')->default(0);
                }
                if (! Schema::hasColumn('mkt_sends', 'open_class')) {
                    $t->string('open_class', 8)->nullable();
                }
                if (! Schema::hasColumn('mkt_sends', 'open_ua')) {
                    $t->string('open_ua', 8)->nullable();
                }
                if (! Schema::hasColumn('mkt_sends', 'open_ip')) {
                    $t->string('open_ip', 8)->nullable();
                }
            });
        }

        if (Schema::hasTable('mkt_clicks') && ! Schema::hasColumn('mkt_clicks', 'dev')) {
            Schema::table('mkt_clicks', function (Blueprint $t) {
                $t->string('dev', 8)->default('');
            });
        }
    }

    public function down(): void
    {
        foreach (['first_open_at', 'open_count', 'open_class', 'open_ua', 'open_ip'] as $c) {
            if (Schema::hasColumn('mkt_sends', $c)) {
                Schema::table('mkt_sends', fn (Blueprint $t) => $t->dropColumn($c));
            }
        }

        if (Schema::hasColumn('mkt_clicks', 'dev')) {
            Schema::table('mkt_clicks', fn (Blueprint $t) => $t->dropColumn('dev'));
        }
    }
};
