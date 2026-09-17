<?php

declare(strict_types=1);

namespace App\Services\Translation;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

/**
 * Where the owner's machine-translation API key lives.
 *
 * ── IN THE DATABASE, ENCRYPTED, NOT IN .env ─────────────────────────────────
 *
 * .env cannot be written by an update package (UpdateGuard::FORBIDDEN_PREFIXES)
 * and there is no shell to edit it with, so a key that had to go in .env is a
 * key the owner cannot save. Same conclusion MailCredential reached for the
 * SMTP password, and this follows that precedent rather than inventing a second
 * one.
 *
 * Encrypted with the application key, because a settings row is in every
 * database dump and every nightly backup, and this one is billable: a leaked
 * key is somebody else's translation bill on the owner's Google account.
 *
 * ── AND IT IS NOT A PUBLIC SETTING ──────────────────────────────────────────
 *
 * CLAUDE.md records that /api/* is unauthenticated and that the settings
 * endpoint has leaked three times. SettingController::PUBLIC_KEYS is an
 * explicit allowlist, so a new key is private by default — but "by default" is
 * how the last three got out, so BilingualFoundationTest asserts by name that
 * this key is not on it and that the public settings endpoint does not return
 * it.
 */
final class TranslationCredentials
{
    public const SETTING_KEY = 'translate_api_key';

    public const PROVIDER_KEY = 'translate_provider';

    /** The decrypted key, or null if none is saved or it cannot be decrypted. */
    public static function apiKey(): ?string
    {
        $row = Setting::query()->find(self::SETTING_KEY);

        $stored = $row?->value;

        if (! is_string($stored) || $stored === '') {
            return null;
        }

        try {
            $plain = Crypt::decryptString($stored);
        } catch (\Throwable) {
            /*
             * An APP_KEY rotation makes every ciphertext in the database
             * unreadable. Returning null degrades to "no provider configured",
             * which greys the Translate buttons out and leaves the manual path
             * — the one that must always work — untouched. Throwing here would
             * take out any screen that asks whether translation is available.
             */
            return null;
        }

        return $plain === '' ? null : $plain;
    }

    public static function hasKey(): bool
    {
        return self::apiKey() !== null;
    }

    /** Save, or clear with an empty string. */
    public static function saveApiKey(?string $plain): void
    {
        $plain = $plain === null ? '' : trim($plain);

        Setting::query()->updateOrCreate(
            ['key' => self::SETTING_KEY],
            ['value' => $plain === '' ? '' : Crypt::encryptString($plain), 'autoload' => false],
        );

        Setting::flushMap();
    }
}
