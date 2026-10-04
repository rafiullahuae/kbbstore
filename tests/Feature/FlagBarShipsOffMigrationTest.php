<?php

declare(strict_types=1);

use App\Models\Setting;
use App\Services\HeaderSettings;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

/**
 * THE COUNTRIES BAR COMES OFF ON A SHOP THAT HAS ALREADY SAVED IT ON.
 *                                                                (Lane PI-B)
 *
 * "Turn off the top countries bar entirely for now." The schema default moving
 * to false covers a shop that has never saved Appearance → Header. The live
 * shop HAS: `banner_ships_as_image_slider` wrote `fb_mobile: true` and
 * `fb_desktop: true` into its `header_settings` row, and a stored value beats
 * a default every time. Without the migration the package would apply, the
 * screen would say off, and the bar would still be on every page of the shop.
 *
 * Run against SQLite in the default suite and against MySQL under
 * -c phpunit-mysql.xml, because MySQL is what the shop runs.
 */

function fbsoRun(string $direction = 'up'): void
{
    /** @var \Illuminate\Database\Migrations\Migration $migration */
    $migration = require base_path('database/migrations/2027_07_06_000000_flag_bar_ships_off.php');

    ob_start();
    $migration->{$direction}();
    ob_end_clean();

    SettingsService::forgetMemo();
}

/** Write the row exactly as a shop that saved the Header screen carries it. */
function fbsoStore(array $header): void
{
    DB::table('settings')->updateOrInsert(
        ['key' => 'header_settings'],
        ['value' => json_encode($header), 'autoload' => true, 'created_at' => now(), 'updated_at' => now()],
    );
    app(SettingsService::class)->flush();
}

function fbsoRow(): ?array
{
    $raw = DB::table('settings')->where('key', 'header_settings')->value('value');

    return is_string($raw) ? json_decode($raw, true) : null;
}

it('turns a stored ON off for phones and for desktop, and nothing else in the row', function () {
    /*
     * MUTATION, RUN: delete the `$header[$key] = false;` line and this is red
     * at "the live shop would keep its countries bar on phones" — the row still
     * says true and the storefront still draws `<div class="kfb kfb-m kfb-d`.
     */
    fbsoStore([
        'fb_mobile' => true, 'fb_desktop' => true,
        'fb_text' => 'Authentic, from Seoul', 'fb_bg' => '#FFEEDD', 'fb_height' => 34,
        'bar_height' => 52,
    ]);

    expect(test()->get('/shop/')->assertOk()->getContent())->toContain('<div class="kfb ');

    fbsoRun();

    $row = fbsoRow();

    expect($row['fb_mobile'])->toBeFalse('the live shop would keep its countries bar on phones')
        ->and($row['fb_desktop'])->toBeFalse('the live shop would keep its countries bar on desktop')
        // Everything else he had set is his, and comes back with the switch.
        ->and($row['fb_text'])->toBe('Authentic, from Seoul')
        ->and($row['fb_bg'])->toBe('#FFEEDD')
        ->and($row['fb_height'])->toBe(34)
        ->and($row['bar_height'])->toBe(52);

    expect(app(HeaderSettings::class)->flagBarOn())->toBeFalse();

    // ▲ (Lane HC) `/` left the list: there the strip is the `countries`
    // homepage section, phones only, which the owner asked for after this
    // migration — "below the main banner, i need that countries strip".
    foreach (['/shop/'] as $path) {
        expect(str_contains(test()->get($path)->assertOk()->getContent(), '<div class="kfb '))
            ->toBeFalse("{$path} still draws the countries bar after the migration");
    }
});

it('reads through a warm settings cache, because it drops it', function () {
    /*
     * SettingsService caches the whole settings map forever, and
     * `php artisan migrate --force` from a shell does not clear it. A write
     * underneath a warm cache is a write the shop does not read.
     *
     * MUTATION, RUN: delete `app(SettingsService::class)->flush()` from up()
     * and this is red — all() still answers the cached `true`.
     */
    fbsoStore(['fb_mobile' => true, 'fb_desktop' => true]);

    // Warm it, the way the first storefront request after deploy would.
    expect(app(HeaderSettings::class)->all()['fb_mobile'])->toBeTrue();

    $migration = require base_path('database/migrations/2027_07_06_000000_flag_bar_ships_off.php');
    ob_start();
    $migration->up();
    ob_end_clean();

    // NOT forgetMemo() here: only the migration's own flush may make this pass.
    expect(app(SettingsService::class)->all()['header_settings']['fb_mobile'] ?? null)->toBeFalse();
});

it('creates no settings row on a shop that never had one', function () {
    /*
     * The schema default already answers off there, and a row created for
     * nothing is a changed payload on /admin-api/seo/settings —
     * SeoBackOfficePayloadTest caught exactly that on the banner migration.
     */
    Setting::query()->where('key', 'header_settings')->delete();
    app(SettingsService::class)->flush();

    fbsoRun();

    expect(DB::table('settings')->where('key', 'header_settings')->exists())->toBeFalse()
        ->and(app(HeaderSettings::class)->flagBarOn())->toBeFalse();
});

it('puts both back on when rolled back', function () {
    fbsoStore(['fb_mobile' => true, 'fb_desktop' => true, 'fb_text' => 'Kept']);

    fbsoRun('up');
    fbsoRun('down');

    $row = fbsoRow();

    expect($row['fb_mobile'])->toBeTrue()
        ->and($row['fb_desktop'])->toBeTrue()
        ->and($row['fb_text'])->toBe('Kept');
});
