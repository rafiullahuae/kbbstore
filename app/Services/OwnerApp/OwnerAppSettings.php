<?php

declare(strict_types=1);

namespace App\Services\OwnerApp;

use Illuminate\Support\Facades\DB;

/**
 * The owner app's two knobs, both under Platform → Users & Roles → Owner app.
 *
 *   owner_app_idle_hours   how long an unlocked app stays unlocked with nobody
 *                          using it before it asks for the PIN again (1–168,
 *                          default 12).
 *   owner_app_low_stock    a product at or below this many units is "low
 *                          stock" in the app and in its notifications (0–999,
 *                          default 5 — the shop's own low_stock_at default).
 *
 * One query per request, read straight from `settings`: Setting::map() is a
 * process-level memo (CLAUDE.md, landmines) and these are written by an admin
 * screen in one request and read by the app in the next.
 */
final class OwnerAppSettings
{
    public const IDLE = 'owner_app_idle_hours';

    public const LOW_STOCK = 'owner_app_low_stock';

    public const DEFAULTS = [self::IDLE => 12, self::LOW_STOCK => 5];

    public const BOUNDS = [self::IDLE => [1, 168], self::LOW_STOCK => [0, 999]];

    /** @var array<string,int>|null */
    private static ?array $memo = null;

    /** @return array<string,int> */
    public static function all(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $out = self::DEFAULTS;

        try {
            $rows = DB::table('settings')->whereIn('key', array_keys(self::DEFAULTS))->pluck('value', 'key');
            foreach ($rows as $key => $value) {
                $out[(string) $key] = self::clamp((string) $key, $value);
            }
        } catch (\Throwable) {
        }

        return self::$memo = $out;
    }

    public static function idleHours(): int
    {
        return self::all()[self::IDLE];
    }

    public static function lowStock(): int
    {
        return self::all()[self::LOW_STOCK];
    }

    /** @param array<string,mixed> $values */
    public static function put(array $values): void
    {
        foreach (self::DEFAULTS as $key => $default) {
            if (! array_key_exists($key, $values)) {
                continue;
            }
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                ['value' => (string) self::clamp($key, $values[$key]), 'autoload' => false, 'updated_at' => now(), 'created_at' => now()],
            );
        }

        self::$memo = null;
        \App\Models\Setting::flushMap();
    }

    public static function forget(): void
    {
        self::$memo = null;
    }

    private static function clamp(string $key, mixed $value): int
    {
        [$min, $max] = self::BOUNDS[$key];
        $n = is_numeric($value) ? (int) $value : self::DEFAULTS[$key];

        return max($min, min($max, $n));
    }
}
