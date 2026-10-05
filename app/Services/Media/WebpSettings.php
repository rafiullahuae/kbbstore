<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Services\SettingsService;

/**
 * Content -> Media Library -> WebP images: the four settings. (Lane WP)
 *
 * ON AS IT SHIPS, BECAUSE THE OWNER ASKED FOR IT. CLAUDE.md, 30 September: "a
 * thing he asked for is the shop's new state, not a switch he has to go and
 * find." He asked for uploads to become WebP, so `enabled` defaults to true;
 * the switch exists so he can have the old behaviour back.
 *
 * KEEP THE UPLOADED ORIGINAL: OFF, AND WHY. An upload's JPEG has never been
 * published — the response hands back the WebP's URL, so no page, email or
 * search engine has ever seen the original's address. Keeping it protects
 * nothing from a 404 and costs disk on a plan where disk is limited. The bulk
 * converter is the opposite case (those originals ARE published) and keeps
 * every original until the owner confirms "Remove originals" — that is not
 * a setting, it is the procedure.
 *
 * Stored with autoload=false so four admin-only values never ride along in the
 * storefront's settings cache.
 */
final class WebpSettings
{
    public const ENABLED = 'media.webp.enabled';

    public const QUALITY = 'media.webp.quality';

    public const MAX_WIDTH = 'media.webp.max_width';

    public const KEEP_ORIGINAL = 'media.webp.keep_upload_original';

    public const DEFAULTS = [
        'enabled' => true,
        'quality' => 82,
        'max_width' => 2400,
        'keep_original' => false,
    ];

    public const QUALITY_MIN = 40;

    public const QUALITY_MAX = 100;

    /** 0 means "no cap". Otherwise between these two. */
    public const WIDTH_MIN = 640;

    public const WIDTH_MAX = 8000;

    private const KEYS = [
        'enabled' => self::ENABLED,
        'quality' => self::QUALITY,
        'max_width' => self::MAX_WIDTH,
        'keep_original' => self::KEEP_ORIGINAL,
    ];

    /** @return array{enabled: bool, quality: int, max_width: int, keep_original: bool} */
    public static function all(): array
    {
        $settings = app(SettingsService::class);
        $out = [];

        foreach (self::KEYS as $field => $key) {
            try {
                $raw = $settings->get($key, self::DEFAULTS[$field]);
            } catch (\Throwable) {
                $raw = self::DEFAULTS[$field];
            }

            $out[$field] = self::normalise($field, $raw);
        }

        /** @var array{enabled: bool, quality: int, max_width: int, keep_original: bool} $out */
        return $out;
    }

    /** Is conversion on upload switched on AND possible on this server? */
    public static function convertsUploads(): bool
    {
        return self::all()['enabled'] && WebpConverter::available();
    }

    /**
     * Write what the admin sent, field by field. Unknown fields are ignored;
     * a value out of range is clamped, never stored as typed.
     *
     * @param  array<string, mixed>  $input
     */
    public static function save(array $input): array
    {
        $settings = app(SettingsService::class);

        foreach (self::KEYS as $field => $key) {
            if (! array_key_exists($field, $input)) {
                continue;
            }

            $value = self::normalise($field, $input[$field]);
            $settings->set($key, is_bool($value) ? ($value ? '1' : '0') : (string) $value, false);
        }

        return self::all();
    }

    public static function normalise(string $field, mixed $value): bool|int
    {
        return match ($field) {
            'enabled', 'keep_original' => self::bool($value, self::DEFAULTS[$field]),
            'quality' => is_numeric($value)
                ? max(self::QUALITY_MIN, min(self::QUALITY_MAX, (int) $value))
                : self::DEFAULTS['quality'],
            'max_width' => ! is_numeric($value)
                ? self::DEFAULTS['max_width']
                : ((int) $value <= 0 ? 0 : max(self::WIDTH_MIN, min(self::WIDTH_MAX, (int) $value))),
            default => throw new \InvalidArgumentException("Unknown WebP setting [{$field}]."),
        };
    }

    private static function bool(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $v = strtolower(trim((string) $value));

        return match ($v) {
            '1', 'true', 'on', 'yes' => true,
            '0', 'false', 'off', 'no' => false,
            default => $default,
        };
    }
}
