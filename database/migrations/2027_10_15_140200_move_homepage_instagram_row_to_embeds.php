<?php

declare(strict_types=1);

use App\Services\SettingsService;
use Illuminate\Database\Migrations\Migration;

/*
 * Lane IGR: the homepage keeps its Instagram section.
 *
 * Appearance → Homepage had an `instagram` row (the API-fed "Instagram
 * Profile", retired with the module at the owner's request) and, since Lane
 * IGE, an `igembeds` row. The `instagram` row is gone from
 * HomepageSections::REGISTRY. Where the owner had it SWITCHED ON (desktop or
 * phone), `igembeds` takes its place:
 *
 *   - POSITION: `igembeds` gets the `instagram` row's saved `order`. That slot
 *     is free now, so every other row keeps its relative place; settle()
 *     renumbers 0..n-1 on the next read.
 *   - ON/OFF: per device, `igembeds` is on where EITHER row was on. Taking the
 *     old row's flags alone could switch off a device the owner already shows
 *     the embeds on; the union never hides a section that was showing.
 *
 * Where `instagram` was off (it shipped off), `igembeds` is left exactly as it
 * is. The dead `instagram` key is dropped from the payload either way.
 *
 * The raw payload, not HomepageSections::all(): the registry no longer has the
 * key, so all() cannot see the row this has to read. A stored null means the
 * field's default, the same `??` rule castRow() applies — `instagram` defaulted
 * off, `igembeds` defaults on.
 */
return new class extends Migration
{
    public function up(): void
    {
        $settings = app(SettingsService::class);
        $saved = $settings->get('homepage_sections');

        if (! is_array($saved) || ! array_key_exists('instagram', $saved)) {
            $this->say('Homepage: no Instagram Profile row saved; nothing to move (0 rows changed).');

            return;
        }

        $old = is_array($saved['instagram']) ? $saved['instagram'] : [];
        unset($saved['instagram']);

        $on = fn (array $row, string $device, bool $default): bool => filter_var($row[$device] ?? $default, FILTER_VALIDATE_BOOLEAN);
        $oldD = $on($old, 'desktop', false);
        $oldM = $on($old, 'mobile', false);

        if (! $oldD && ! $oldM) {
            $settings->set('homepage_sections', $saved);
            $this->say('Homepage: the Instagram Profile row was off; Instagram embeds left as it was (0 rows changed).');

            return;
        }

        $row = is_array($saved['igembeds'] ?? null) ? $saved['igembeds'] : [];
        $row['desktop'] = $oldD || $on($row, 'desktop', true);
        $row['mobile'] = $oldM || $on($row, 'mobile', true);

        if (isset($old['order']) && is_numeric($old['order'])) {
            $row['order'] = (int) $old['order'];
        }

        $saved['igembeds'] = $row;
        $settings->set('homepage_sections', $saved);
        $this->say('Homepage: Instagram embeds took the Instagram Profile row\'s place (1 row changed).');
    }

    private function say(string $line): void
    {
        if (app()->runningInConsole()) {
            echo $line."\n";
        }
    }

    public function down(): void {}
};
