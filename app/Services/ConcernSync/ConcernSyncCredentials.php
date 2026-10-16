<?php

declare(strict_types=1);

namespace App\Services\ConcernSync;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

/**
 * The owner's Claude API key and model for Catalog → Shop by Concern. (Lane SC)
 *
 * Stored the way TranslationCredentials stores the translation key: encrypted
 * with the app key (Crypt::encryptString), autoload off so it never rides the
 * per-request settings map, and never sent back to a browser -- the screen is
 * told only whether a key is saved. A row that will not decrypt (an APP_KEY
 * rotated since) reads as "no key", so the screen falls back to the rules.
 */
final class ConcernSyncCredentials
{
    public const KEY = 'concern_sync_api_key';

    public const MODEL = 'concern_sync_model';

    /** The models the select offers. The first is the default. */
    public const MODELS = [
        'claude-sonnet-5-5' => 'Claude Sonnet 5.5 (recommended)',
        'claude-opus-5-5' => 'Claude Opus 5.5 (most careful, dearer)',
        'claude-haiku-5-5' => 'Claude Haiku 5.5 (fastest, cheapest)',
    ];

    /** @return array{key:?string, model:string} — one query for both. */
    public static function read(): array
    {
        $rows = Setting::query()->whereIn('key', [self::KEY, self::MODEL])->pluck('value', 'key');

        $key = null;
        $stored = $rows[self::KEY] ?? null;
        if (is_string($stored) && $stored !== '') {
            try {
                $plain = Crypt::decryptString($stored);
                $key = $plain === '' ? null : $plain;
            } catch (\Throwable) {
                $key = null;
            }
        }

        $model = (string) ($rows[self::MODEL] ?? '');

        return ['key' => $key, 'model' => isset(self::MODELS[$model]) ? $model : array_key_first(self::MODELS)];
    }

    public static function saveKey(?string $plain): void
    {
        $plain = trim((string) $plain);

        Setting::query()->updateOrCreate(
            ['key' => self::KEY],
            ['value' => $plain === '' ? '' : Crypt::encryptString($plain), 'autoload' => false],
        );

        Setting::flushMap();
    }

    public static function saveModel(string $model): void
    {
        Setting::query()->updateOrCreate(
            ['key' => self::MODEL],
            ['value' => isset(self::MODELS[$model]) ? $model : array_key_first(self::MODELS), 'autoload' => false],
        );

        Setting::flushMap();
    }
}
