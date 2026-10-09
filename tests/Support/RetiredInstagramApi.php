<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * The Instagram API module's files, retired by Lane IGR and deliberately LEFT ON DISK.
 *
 * THE OWNER, 9 October 2026: "the old instagram api etc will be discontinue from
 * the app, and also the instagram connect page too." The module is unmounted —
 * no route, no screen, no nav row, no schedule, no capability, no module switch,
 * no shortcode output, no homepage row — but its files stay, because an update
 * package cannot delete a file: `kbb:package` selects `git diff
 * --diff-filter=ACMR` (added, copied, modified, renamed) and UpdateRunner has no
 * removal list. A file deleted here would therefore still be on the server, and
 * this repository is the record of what the server holds.
 *
 * So they stay, and are proven DEAD instead: RetiredInstagramApiTest asserts that
 * no live file names any of them (outside comments), and the guards that walk
 * routes/ and the admin partials looking for a file nobody mounts skip exactly
 * these — a file on this list is unmounted on purpose, which is the opposite of
 * the "built and never wired up" fault those guards exist to catch.
 *
 * app/Console/Commands/InstagramSyncCommand.php is NOT on the list: Laravel
 * discovers every command in that directory, so it is kept live and replaced by
 * a no-op that says the module is retired.
 */
final class RetiredInstagramApi
{
    /** Paths relative to the application root. */
    public const FILES = [
        'app/Services/InstagramFeed.php',
        'app/Services/InstagramSettings.php',
        'app/Services/SpottedInstagram.php',
        'app/Services/Instagram/FacebookConnect.php',
        'app/Services/Instagram/FacebookGraphClient.php',
        'app/Services/Instagram/IgPath.php',
        'app/Services/Instagram/InstagramAuth.php',
        'app/Services/Instagram/InstagramClient.php',
        'app/Services/Instagram/InstagramCredentials.php',
        'app/Services/Instagram/InstagramReconnectNotice.php',
        'app/Services/Instagram/InstagramSource.php',
        'app/Services/Instagram/InstagramSync.php',
        'app/Http/Controllers/Admin/InstagramController.php',
        'app/Http/Controllers/Admin/SpottedInstagramApiController.php',
        'app/Models/InstagramPost.php',
        'routes/instagram-admin.php',
        'resources/views/instagram/section.blade.php',
        'resources/views/instagram/assets.blade.php',
        'resources/views/admin/partials/instagram-screen.blade.php',
        'resources/views/partials/spotted-ig-card.blade.php',
    ];

    /**
     * What a live file would have to say to reach one of them: a class name, a
     * view name, a route file name or a partial name.
     */
    public const NAMES = [
        'InstagramFeed', 'InstagramSettings', 'SpottedInstagram', 'FacebookConnect',
        'FacebookGraphClient', 'IgPath', 'InstagramAuth', 'InstagramClient',
        'InstagramCredentials', 'InstagramReconnectNotice', 'InstagramSource',
        'InstagramSync', 'InstagramController', 'SpottedInstagramApiController',
        'InstagramPost', 'instagram-admin.php', 'instagram.section', 'instagram.assets',
        'instagram-screen', 'spotted-ig-card',
    ];

    public static function isRetired(string $path): bool
    {
        $rel = ltrim(str_replace('\\', '/', str_starts_with($path, base_path()) ? substr($path, strlen(base_path())) : $path), '/');

        return in_array($rel, self::FILES, true);
    }

    /** True for a bare file name (e.g. 'instagram-admin.php', 'instagram-screen') of a retired file. */
    public static function isRetiredName(string $name): bool
    {
        foreach (self::FILES as $file) {
            $base = basename($file);

            if ($name === $base || $name === basename($base, '.php') || $name === basename($base, '.blade.php')) {
                return true;
            }
        }

        return false;
    }
}
