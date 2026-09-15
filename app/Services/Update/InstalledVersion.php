<?php

declare(strict_types=1);

namespace App\Services\Update;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What version is actually installed.
 *
 * Three places asked config('kbb.version') for this, which reads
 * env('KBB_VERSION') and falls back to '1.0.0'. That variable has never been
 * set on this server, so the Core Updates header has been reporting 1.0.0
 * while the site ran 2.60.98 — the screen whose whole job is telling you what
 * is installed was the one screen that did not know.
 *
 * It is not cosmetic. UpdatePackage compares requires_version against the same
 * value, so a package declaring any prerequisite above 1.0.0 would be refused
 * on a server that already had it. That is why no package built in this
 * project has ever dared set requires_version.
 *
 * The truth is in `update_releases`: every applied package writes a row. The
 * newest row with status 'applied' is the installed version, full stop. No env
 * var to keep in step, and nothing to forget to bump.
 *
 * Ordered by version rather than by time, because a rollback followed by a
 * re-apply can leave rows whose id order and version order disagree.
 */
final class InstalledVersion
{
    private static ?string $memo = null;

    public static function get(): string
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        return self::$memo = self::resolve();
    }

    /** For tests and for the moment after a package is applied. */
    public static function forget(): void
    {
        self::$memo = null;
    }

    private static function resolve(): string
    {
        // A fresh install has no table yet; the env fallback covers that one
        // case and nothing else.
        if (! Schema::hasTable('update_releases')) {
            return (string) config('kbb.version', '1.0.0');
        }

        $versions = DB::table('update_releases')
            ->where('status', 'applied')
            ->pluck('version')
            ->all();

        if ($versions === []) {
            return (string) config('kbb.version', '1.0.0');
        }

        usort($versions, static fn ($a, $b) => version_compare((string) $a, (string) $b));

        return (string) end($versions);
    }
}
