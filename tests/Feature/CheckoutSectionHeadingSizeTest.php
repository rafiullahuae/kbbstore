<?php

declare(strict_types=1);

use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;

/*
 * "the headings of the sections like shipping details etc, make 2 font size
 * increase" (the owner, 9 October). The migration moves both Section heading
 * size controls from 100% (13px) to 115% (~15px), and leaves a value the owner
 * set himself alone.
 *
 * MUTATION: write 100 instead of 115 in the migration -> red.
 */
function cshRun(): void
{
    $m = require base_path('database/migrations/2027_10_16_140000_checkout_section_headings_two_px_larger.php');
    $m->up();
    SettingsService::forgetMemo();
}

it('sets both section heading sizes to 115% where the owner left them alone', function () {
    DB::table('settings')->whereIn('key', ['checkoutpage_d_t_h2', 'checkoutpage_m_t_h2'])->delete();
    cshRun();

    expect((string) DB::table('settings')->where('key', 'checkoutpage_d_t_h2')->value('value'))->toBe('115')
        ->and((string) DB::table('settings')->where('key', 'checkoutpage_m_t_h2')->value('value'))->toBe('115');
});

it('keeps a size the owner chose himself', function () {
    app(SettingsService::class)->set('checkoutpage_m_t_h2', '130');
    DB::table('settings')->where('key', 'checkoutpage_d_t_h2')->delete();
    cshRun();

    expect((string) DB::table('settings')->where('key', 'checkoutpage_m_t_h2')->value('value'))->toBe('130')
        ->and((string) DB::table('settings')->where('key', 'checkoutpage_d_t_h2')->value('value'))->toBe('115');
});
