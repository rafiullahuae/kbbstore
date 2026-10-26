<?php

declare(strict_types=1);

use App\Models\PaymentProvider;
use Illuminate\Database\Migrations\Migration;

/*
 * TABBY AUTOMATIC CAPTURE GOES BACK OFF (the owner, 26 October 2026, after a
 * day with no Tabby orders: "reverse the auto capture functionality that you
 * did today ... only reverse whatever you did for tabby ... and give me a
 * switch to turn the payment auto capture on or off").
 *
 * 2.60.601 shipped Tabby auto-capture ON through
 * 2027_12_21_100000_tabby_auto_capture_on_by_default. This writes the Off the
 * payments screen itself writes (the empty string, see
 * TabbyGateway::autoCapture()), once, so the every-five-minutes check
 * (TabbyCaptureSweep::run()) returns before any call to Tabby and writes
 * nothing. The switch stays where it is -- Store -> Payments -> Tabby ->
 * Settings -> "Capture automatically" -- for him to turn back on.
 *
 * Only the Tabby row's one key is written: its API keys and every other
 * setting are kept byte for byte, Tamara's own auto-capture is a different
 * row and is not read. A config this install cannot decrypt is left alone,
 * exactly as the ON migration leaves it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $row = PaymentProvider::query()->find('tabby');
        if ($row === null) {
            return;
        }

        try {
            $config = $row->config;
        } catch (\Throwable) {
            return;
        }

        $config = is_array($config) ? $config : [];
        if (($config['auto_capture'] ?? null) === '') {
            return;
        }

        $config['auto_capture'] = '';
        $row->config = $config;
        $row->save();
    }

    public function down(): void
    {
        // Not reversed: the owner's switch decides from here on.
    }
};
