<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Defaults for Settings → Site address.
 *
 * Every value here is the OFF position, and that is the point: applying this
 * package must change the behaviour of a running shop by exactly nothing.
 *
 *   canonical_host          ''       SiteHost::classify() answers CANONICAL for
 *                                    every host while this is empty, so no
 *                                    request is forwarded and none is marked
 *                                    private.
 *   host_aliases            ''       nothing to forward.
 *   host_redirect_enabled   '0'      and even once a canonical host is set, the
 *                                    forwarding stays off until it is switched
 *                                    on deliberately.
 *   search_visibility       'public' unchanged behaviour for the live shop.
 *
 * updateOrInsert and not insert: this migration has to be safe to run on a
 * database where somebody has already set one of these, which is the case on
 * any install that took the package, set a value, and then had the migration
 * re-run by a rollback and re-apply.
 */
return new class extends Migration
{
    private const DEFAULTS = [
        'canonical_host' => '',
        'host_aliases' => '',
        'host_redirect_enabled' => '0',
        'search_visibility' => 'public',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        foreach (self::DEFAULTS as $key => $value) {
            $existing = DB::table('settings')->where('key', $key)->first();

            if ($existing !== null) {
                continue;
            }

            DB::table('settings')->insert(['key' => $key, 'value' => $value]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')->whereIn('key', array_keys(self::DEFAULTS))->delete();
    }
};
