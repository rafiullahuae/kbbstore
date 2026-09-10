<?php

declare(strict_types=1);

namespace App\Services\Update;

use Illuminate\Support\Facades\DB;

/**
 * Snapshots exactly what an update is about to change, so it can be put back.
 *
 * Only the files being replaced are copied, not the whole application — on
 * shared hosting a full copy would be slow, would risk the disk quota, and is
 * not needed. Files the update *adds* are recorded by name so a rollback can
 * delete them again.
 *
 * The database dump is written in PHP because shared hosting has no shell, so
 * mysqldump is unavailable.
 */
final class BackupService
{
    public function __construct(private string $backupRoot, private string $appRoot) {}

    /**
     * @param  array<string>  $relativePaths  files the update will write
     * @return array{id: string, path: string, replaced: array<string>, added: array<string>}
     */
    public function snapshotFiles(array $relativePaths): array
    {
        $id = date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $dir = $this->backupRoot . '/' . $id;

        if (! is_dir($dir . '/files') && ! mkdir($dir . '/files', 0755, true)) {
            throw new \RuntimeException('Could not create the backup directory. Check permissions on storage/app.');
        }

        $replaced = [];
        $added = [];

        foreach ($relativePaths as $relative) {
            $source = $this->appRoot . '/' . $relative;

            if (! is_file($source)) {
                $added[] = $relative;

                continue;
            }

            $target = $dir . '/files/' . $relative;

            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }

            if (! copy($source, $target)) {
                throw new \RuntimeException("Could not back up {$relative}. Update aborted before any change was made.");
            }

            $replaced[] = $relative;
        }

        file_put_contents($dir . '/manifest.json', json_encode([
            'id' => $id,
            'created_at' => date('c'),
            'replaced' => $replaced,
            'added' => $added,
        ], JSON_PRETTY_PRINT));

        return ['id' => $id, 'path' => $dir, 'replaced' => $replaced, 'added' => $added];
    }

    /**
     * Chunked SQL dump. Written in 500-row batches so memory stays flat whatever
     * the table size, which matters when the orders table eventually holds 4,000+
     * rows on a 1 GB shared plan.
     */
    public function dumpDatabase(string $dir): ?string
    {
        $path = $dir . '/database.sql';
        $handle = fopen($path, 'w');

        if ($handle === false) {
            return null;
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n");

        foreach (DB::select('SHOW TABLES') as $row) {
            $table = array_values((array) $row)[0];

            $create = DB::select("SHOW CREATE TABLE `{$table}`");
            $sql = array_values((array) $create[0])[1] ?? null;

            if ($sql === null) {
                continue;
            }

            fwrite($handle, "\nDROP TABLE IF EXISTS `{$table}`;\n{$sql};\n");

            $offset = 0;
            do {
                $rows = DB::select("SELECT * FROM `{$table}` LIMIT 500 OFFSET {$offset}");

                foreach ($rows as $record) {
                    $values = array_map(function ($value) {
                        if ($value === null) {
                            return 'NULL';
                        }

                        return is_numeric($value) && ! is_string($value)
                            ? (string) $value
                            : "'" . addslashes((string) $value) . "'";
                    }, (array) $record);

                    fwrite($handle, "INSERT INTO `{$table}` VALUES (" . implode(',', $values) . ");\n");
                }

                $offset += 500;
            } while (count($rows) === 500);
        }

        fwrite($handle, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);

        return $path;
    }

    /** Put the files back exactly as they were. */
    public function restoreFiles(string $backupId): array
    {
        $dir = $this->backupRoot . '/' . $backupId;
        $manifestPath = $dir . '/manifest.json';

        if (! is_file($manifestPath)) {
            throw new \RuntimeException("Backup {$backupId} not found.");
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $restored = 0;
        $removed = 0;

        foreach ((array) ($manifest['replaced'] ?? []) as $relative) {
            $source = $dir . '/files/' . $relative;
            $target = $this->appRoot . '/' . $relative;

            if (is_file($source)) {
                if (! is_dir(dirname($target))) {
                    mkdir(dirname($target), 0755, true);
                }
                if (copy($source, $target)) {
                    $restored++;
                }
            }
        }

        // Files the update introduced are removed, so a rollback leaves no debris.
        foreach ((array) ($manifest['added'] ?? []) as $relative) {
            $target = $this->appRoot . '/' . $relative;

            if (is_file($target) && unlink($target)) {
                $removed++;
            }
        }

        return ['restored' => $restored, 'removed' => $removed];
    }

    /** Keep the most recent backups; delete the rest to protect the disk quota. */
    public function prune(int $keep = 5): int
    {
        $dirs = glob($this->backupRoot . '/*', GLOB_ONLYDIR) ?: [];
        rsort($dirs);
        $deleted = 0;

        foreach (array_slice($dirs, $keep) as $dir) {
            $this->deleteTree($dir);
            $deleted++;
        }

        return $deleted;
    }

    private function deleteTree(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
