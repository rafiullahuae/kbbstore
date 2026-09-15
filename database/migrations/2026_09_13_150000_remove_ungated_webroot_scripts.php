<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * Delete the unauthenticated repair scripts from the public web root.
 *
 * kbb-patch-file.php, kbb-fix-now.php, kbb-unstick.php and kbb-check-schema.php
 * have no gate of any kind. They read .env, open database connections and write
 * or delete files, and anyone who requests the URL runs them. They were
 * one-time repairs for the 2.60.36-.48 period -- a missing renderCatalog(), a
 * stuck Core Updates screen, a schema check -- and none of those conditions
 * exists at this version.
 *
 * kbb-doctor.php and kbb-recover.php are deliberately left: both check a token
 * with hash_equals, both tokens are long random strings, and recover is the
 * only way back in if the app will not boot.
 *
 * The web root is a different directory from the application -- the app sits in
 * kbb-upgrade-app, the web root in public_html/kbb-upgrade -- so the update
 * system's own file copying cannot reach it. A migration can, because it runs
 * as PHP on the server.
 *
 * Finding it: migrations triggered from Core Updates run inside a web request,
 * so SCRIPT_FILENAME is the index.php that booted it, and its directory is the
 * web root exactly. Everything else is a guess, so if that is not available the
 * migration does nothing rather than deleting from a directory it inferred.
 *
 * Only these four exact names are ever removed, never a pattern and never a
 * directory walk.
 */
return new class extends Migration
{
    private const TARGETS = [
        'kbb-patch-file.php',
        'kbb-fix-now.php',
        'kbb-unstick.php',
        'kbb-check-schema.php',
    ];

    public function up(): void
    {
        $root = $this->webRoot();

        if ($root === null) {
            $this->say('Web root could not be identified with certainty — nothing removed. Delete the four scripts by hand.');

            return;
        }

        $removed = [];
        $failed = [];

        foreach (self::TARGETS as $name) {
            $path = $root . DIRECTORY_SEPARATOR . $name;

            if (! is_file($path)) {
                continue;
            }

            if (@unlink($path)) {
                $removed[] = $name;
            } else {
                $failed[] = $name;
            }
        }

        if ($removed === [] && $failed === []) {
            $this->say('None of the four scripts are present — nothing to do.');

            return;
        }

        if ($removed !== []) {
            $this->say('Removed from ' . $root . ': ' . implode(', ', $removed));
        }

        if ($failed !== []) {
            $this->say('Could not remove (check permissions): ' . implode(', ', $failed));
        }
    }

    /**
     * The directory the front controller was served from, or null.
     *
     * Verified rather than trusted: the directory must actually contain the
     * index.php that boots this app. Without that check a misreported
     * SCRIPT_FILENAME could point anywhere.
     */
    private function webRoot(): ?string
    {
        // bootstrap/app.php calls usePublicPath() with the real web root, so
        // public_path() is the app's own answer rather than an inference from
        // the request. Tried first; SCRIPT_FILENAME stays as the fallback for
        // an install that has not set it.
        $configured = public_path();

        if (is_dir($configured) && is_file($configured . '/index.php')) {
            return rtrim($configured, DIRECTORY_SEPARATOR);
        }

        $script = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');

        if ($script === '' || ! str_ends_with($script, 'index.php')) {
            return null;
        }

        $dir = dirname($script);

        return (is_dir($dir) && is_file($dir . '/index.php')) ? $dir : null;
    }

    private function say(string $message): void
    {
        echo $message . "\n";
    }

    /** Deleting a security fix is not something to undo. */
    public function down(): void {}
};
