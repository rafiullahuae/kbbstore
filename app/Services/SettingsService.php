<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ModuleSetting;
use App\Models\ModuleToggle;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Settings and module toggles. Mirrors the WordPress side's split:
 * kbb_settings -> settings, kbb_modules -> module_toggles,
 * kbb_module_settings -> module_settings.
 *
 * Autoloaded settings are cached as one payload, because on shared hosting the
 * cache driver is a file and one read beats forty.
 */
class SettingsService
{
    private const CACHE_KEY = 'kbb.settings';
    private const MODULES_KEY = 'kbb.modules';

    public function all(): array
    {
        $cached = Cache::rememberForever(self::CACHE_KEY, function () {
            /*
             * Written as an explicit loop rather than pluck()->map()->all().
             *
             * The chained form threw "Call to undefined method Setting::map()"
             * in production, which means something in that chain returned a
             * model rather than a collection. Rather than depend on the return
             * type of a fluent call, this reads rows and builds the array
             * directly — there is nothing left to be wrong about.
             */
            $out = [];

            foreach (Setting::query()->where('autoload', true)->get(['key', 'value']) as $row) {
                $out[(string) $row->key] = $this->decode($row->value);
            }

            return $out;
        });

        // A cache written by an older build could hold something else entirely.
        // Never let a stale entry take the storefront down.
        if (! is_array($cached)) {
            Cache::forget(self::CACHE_KEY);

            return [];
        }

        return $cached;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();
        if (array_key_exists($key, $all)) {
            return $all[$key];
        }

        $row = Setting::find($key);

        return $row ? $this->decode($row->value) : $default;
    }

    public function set(string $key, mixed $value, bool $autoload = true): void
    {
        Setting::updateOrCreate(
            ['key' => $key],
            ['value' => is_scalar($value) || $value === null ? $value : json_encode($value), 'autoload' => $autoload]
        );

        Cache::forget(self::CACHE_KEY);

        // Setting::map() keeps its own cache and is read by the SEO layer and
        // the original page controllers. Clearing one without the other leaves
        // half the site on the old value.
        Setting::flushMap();
    }

    /**
     * Is a module on? Mirrors kbb_mod(): unknown modules fall back to the given
     * default, so the storefront renders correctly before anything is configured.
     */
    public function moduleEnabled(string $module, bool $default = false): bool
    {
        $map = Cache::rememberForever(self::MODULES_KEY, function () {
            $out = [];

            foreach (ModuleToggle::query()->get(['module', 'enabled']) as $row) {
                $out[(string) $row->module] = (bool) $row->enabled;
            }

            return $out;
        });

        if (! is_array($map)) {
            Cache::forget(self::MODULES_KEY);

            return $default;
        }

        return array_key_exists($module, $map) ? (bool) $map[$module] : $default;
    }

    public function setModule(string $module, bool $enabled): void
    {
        ModuleToggle::updateOrCreate(['module' => $module], ['enabled' => $enabled]);
        Cache::forget(self::MODULES_KEY);
    }

    public function moduleSetting(string $module, string $key, mixed $default = null): mixed
    {
        $row = ModuleSetting::where('module', $module)->where('key', $key)->first();

        return $row ? $this->decode($row->value) : $default;
    }

    /**
     * The write half of moduleSetting(), which had only a reader.
     *
     * Mirrors setModule(): one row per module and key, matching the plugin's
     * per-module option array.
     */
    public function setModuleSetting(string $module, string $key, mixed $value): void
    {
        ModuleSetting::updateOrCreate(
            ['module' => $module, 'key' => $key],
            ['value' => is_scalar($value) || $value === null ? $value : json_encode($value)]
        );
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::MODULES_KEY);
    }

    private function decode(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : $value;
    }
}
