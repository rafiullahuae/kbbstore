<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give Marketing Pixels its own copy of the owner's Meta app. (Lane MP)
 *
 * "Connect with Facebook" on Marketing Pixels → Meta used to fall back to the
 * app the owner set up for the Instagram module. That module is being retired
 * (Lane IGR, migrations from 2027_10_15_140000), and its settings rows deleted,
 * so this runs FIRST and copies the two values across once:
 *
 *   settings.instagram_fb_app_id       (plain text)        → module_settings
 *                                                             marketing_pixels.meta_app_id
 *   settings.instagram_fb_app_secret   (Crypt::encryptString) → module_settings
 *                                                             marketing_pixels.meta_app_secret
 *                                                             (re-encrypted)
 *
 * Read straight from the `settings` table by key name — no Instagram class is
 * touched, so this works whether or not that code still exists.
 *
 * As a PAIR, and only when Marketing Pixels has neither: an app id from one
 * app beside a secret from another would fail every login. Each value is
 * shape-checked (id: digits; secret: 32 hex) and a secret that does not
 * decrypt under this server's APP_KEY is skipped. Nothing is ever logged or
 * echoed except a count.
 */
return new class extends Migration
{
    private const MODULE = 'marketing_pixels';

    public function up(): void
    {
        if (! Schema::hasTable('settings') || ! Schema::hasTable('module_settings')) {
            return;
        }

        $mine = DB::table('module_settings')->where('module', self::MODULE)
            ->whereIn('key', ['meta_app_id', 'meta_app_secret'])->pluck('value', 'key');

        if (trim((string) ($mine['meta_app_id'] ?? '')) !== '' || trim((string) ($mine['meta_app_secret'] ?? '')) !== '') {
            $this->say('Marketing Pixels already has its own Meta app; nothing copied.');

            return;
        }

        $id = trim((string) DB::table('settings')->where('key', 'instagram_fb_app_id')->value('value'));
        $stored = (string) DB::table('settings')->where('key', 'instagram_fb_app_secret')->value('value');
        $secret = '';

        if ($stored !== '') {
            try {
                $secret = trim(Crypt::decryptString($stored));
            } catch (\Throwable) {
                $secret = '';
            }
        }

        if (preg_match('/^[0-9]{5,25}$/', $id) !== 1 || preg_match('/^[a-f0-9]{32}$/i', $secret) !== 1) {
            $this->say('No complete Meta app in the Instagram settings; nothing copied.');

            return;
        }

        $now = now();

        foreach (['meta_app_id' => $id, 'meta_app_secret' => Crypt::encryptString($secret)] as $key => $value) {
            DB::table('module_settings')->updateOrInsert(
                ['module' => self::MODULE, 'key' => $key],
                ['value' => $value, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        try {
            app(\App\Services\SettingsService::class)->flush();
            \App\Services\SettingsService::forgetMemo();
        } catch (\Throwable) {
        }

        $this->say('Copied the Meta app (id and secret) into Marketing Pixels.');
    }

    public function down(): void {}

    private function say(string $line): void
    {
        if (app()->runningInConsole()) {
            echo $line . "\n";
        }
    }
};
