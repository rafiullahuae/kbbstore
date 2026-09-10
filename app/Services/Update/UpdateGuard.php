<?php

declare(strict_types=1);

namespace App\Services\Update;

/**
 * Decides what an update package is allowed to touch.
 *
 * This is the security core of the updater and it works by allow-list, not
 * block-list. A block-list can be walked around; an allow-list cannot. If a path
 * is not explicitly permitted below, the package is rejected outright — the
 * update never begins, rather than failing halfway through.
 */
final class UpdateGuard
{
    /** Only these roots may ever be written. */
    private const ALLOWED_PREFIXES = [
        'app/',
        'config/',
        'database/migrations/',
        'database/seeders/',
        'resources/',
        'routes/',
        'public/build/',
    ];

    /** Individual files that may be replaced, outside the roots above. */
    private const ALLOWED_FILES = [
        'composer.json',
        'vite.config.js',
        'public/index.php',
        'public/.htaccess',
    ];

    /**
     * Never writable, whatever the package claims.
     *
     * .env holds live credentials. vendor/ is Composer's. storage/ holds
     * customer sessions, logs and the update backups themselves — letting a
     * package write there would let a bad update destroy its own escape route.
     */
    private const FORBIDDEN_PREFIXES = [
        '.env',
        'vendor/',
        'storage/',
        'bootstrap/cache/',
        '.git/',
        'public/kbb-recover.php',
    ];

    private const ALLOWED_EXTENSIONS = [
        'php', 'js', 'css', 'json', 'blade', 'svg', 'png', 'jpg', 'jpeg',
        'webp', 'gif', 'ico', 'woff', 'woff2', 'txt', 'md', 'htaccess', 'map',
    ];

    private const MAX_FILE_BYTES = 8 * 1024 * 1024;   // 8 MB per file
    private const MAX_FILES = 3000;

    /** @return array{ok: bool, errors: array<string>} */
    public function check(array $relativePaths): array
    {
        $errors = [];

        if (count($relativePaths) === 0) {
            $errors[] = 'The package contains no files.';
        }

        if (count($relativePaths) > self::MAX_FILES) {
            $errors[] = sprintf('Package contains %d files, limit is %d.', count($relativePaths), self::MAX_FILES);
        }

        foreach ($relativePaths as $path) {
            $error = $this->checkPath($path);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        return ['ok' => $errors === [], 'errors' => array_slice($errors, 0, 40)];
    }

    public function checkPath(string $path): ?string
    {
        // Reject anything that is not a clean relative path before doing
        // anything else with it.
        if ($path === '' || str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return "Absolute path rejected: {$path}";
        }

        if (preg_match('#(^|/)\.\.(/|$)#', $path)) {
            return "Directory traversal rejected: {$path}";
        }

        if (str_contains($path, "\0") || preg_match('/[\x00-\x1f]/', $path)) {
            return "Control characters in path rejected: {$path}";
        }

        if (preg_match('#^[a-zA-Z]:#', $path) || str_contains($path, '\\')) {
            return "Windows-style path rejected: {$path}";
        }

        foreach (self::FORBIDDEN_PREFIXES as $forbidden) {
            if ($path === rtrim($forbidden, '/') || str_starts_with($path, $forbidden)) {
                return "Protected path, never writable: {$path}";
            }
        }

        if (in_array($path, self::ALLOWED_FILES, true)) {
            return $this->checkExtension($path);
        }

        foreach (self::ALLOWED_PREFIXES as $allowed) {
            if (str_starts_with($path, $allowed)) {
                return $this->checkExtension($path);
            }
        }

        return "Path outside the permitted areas: {$path}";
    }

    public function checkSize(string $path, int $bytes): ?string
    {
        return $bytes > self::MAX_FILE_BYTES
            ? sprintf('%s is %s, over the %s limit.', $path, self::human($bytes), self::human(self::MAX_FILE_BYTES))
            : null;
    }

    private function checkExtension(string $path): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        // ".htaccess" has no extension in the usual sense.
        if ($extension === '' && basename($path) === '.htaccess') {
            return null;
        }

        return in_array($extension, self::ALLOWED_EXTENSIONS, true)
            ? null
            : "File type not permitted: {$path}";
    }

    private static function human(int $bytes): string
    {
        return round($bytes / 1024 / 1024, 1) . ' MB';
    }
}
