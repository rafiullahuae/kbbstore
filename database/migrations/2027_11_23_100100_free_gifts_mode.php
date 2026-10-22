<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Free gifts: the master on/off becomes a three-way Mode (Lane FG2, the
 * owner: "make sure it does not go live without our checking completely. Add
 * an IP list which will see this feature, so we can test.").
 *
 *   off      nobody sees anything
 *   testing  only the IPs on `testers` (same settings row) see it
 *   live     everyone
 *
 * A saved `on: true` becomes `live`; anything else becomes `off`. The feature
 * has never been on in production, so in practice it becomes `off`. `on` is
 * kept, mirroring "mode is not off", for anything that still reads it.
 * Idempotent: a row that already has a mode is left as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $row = DB::table('settings')->where('key', 'free_gifts')->first();
        $saved = $row ? json_decode((string) $row->value, true) : null;
        $saved = is_array($saved) ? $saved : [];

        if (isset($saved['mode']) && in_array($saved['mode'], ['off', 'testing', 'live'], true)) {
            return;
        }

        $saved['mode'] = ! empty($saved['on']) ? 'live' : 'off';
        $saved['on'] = $saved['mode'] !== 'off';
        $saved['testers'] = is_array($saved['testers'] ?? null) ? $saved['testers'] : [];

        DB::table('settings')->updateOrInsert(
            ['key' => 'free_gifts'],
            ['value' => json_encode($saved), 'autoload' => true, 'updated_at' => now()],
        );

        try {
            Cache::forget('kbb.settings');
            Cache::forget('kbb.settings.map');
        } catch (\Throwable) {
            // A cache that cannot forget expires on its own.
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            echo "Free gifts: mode is now '{$saved['mode']}' (Growth & Marketing -> Free gifts -> Overview).\n";
        }
    }

    public function down(): void {}
};
