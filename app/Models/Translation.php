<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One translated string. See the create_translations_table migration for the
 * shape and for why this is a table rather than a file.
 *
 * The write-side cache eviction lives here, on `saved` and `deleted`, rather
 * than in whichever service happened to do the writing. The shop has already
 * paid for the other arrangement twice: Setting::flushMap() forgot the cache
 * key, and SettingsService's memo leaked a stale value between tests. A hook on
 * the model is the only place that catches EVERY writer — the admin screen, the
 * machine-translation runner, a seeder, a migration, and a `php artisan tinker`
 * session — including the ones written after this file.
 */
class Translation extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_MACHINE = 'machine';

    public const SOURCE_IMPORT = 'import';

    /** Interface strings, as opposed to a row of content. */
    public const GROUP_UI = 'ui';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'item_id' => 'int',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(static function (self $translation): void {
            \App\Services\Translation\TranslationStore::flush();
        });

        static::deleted(static function (self $translation): void {
            \App\Services\Translation\TranslationStore::flush();
        });
    }

    /** Only these are ever shown to a shopper. */
    public function scopePublished($query)
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    /**
     * Has the English this was translated from changed since?
     *
     * A published translation of copy that no longer exists is worse than no
     * translation: it is confident and wrong, and nothing on the page admits
     * it. Null source_hash means the row predates the check, which is treated
     * as "not known to be stale" rather than as stale — this shop has no rows
     * like that yet, and inventing a backlog of false alarms would make the
     * progress screen useless on the day it shipped.
     */
    public function isStaleAgainst(?string $englishSource): bool
    {
        if ($this->source_hash === null || $englishSource === null) {
            return false;
        }

        return ! hash_equals((string) $this->source_hash, sha1($englishSource));
    }
}
