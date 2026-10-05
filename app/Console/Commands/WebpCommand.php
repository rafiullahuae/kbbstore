<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Media\WebpBulk;
use App\Services\Media\WebpConverter;
use Illuminate\Console\Command;

/**
 * The bulk WebP converter from a shell. (Lane WP)
 *
 *   php artisan kbb:webp --plan               dry run: files, bytes saved, references
 *   php artisan kbb:webp                      convert everything, in batches
 *   php artisan kbb:webp --restore            undo: references back, WebP deleted
 *   php artisan kbb:webp --remove-originals   delete the kept JPEG/PNG originals
 *
 * The same batches the admin screen runs (Content -> Media Library -> WebP
 * images), looped here in one process. Safe to stop with Ctrl-C at any point
 * and run again: every batch is resumable and idempotent.
 */
class WebpCommand extends Command
{
    protected $signature = 'kbb:webp
        {--plan : Dry run. Show what would convert, the bytes saved and the references that would change. Changes nothing.}
        {--restore : Undo. Point every reference back at the original and delete the WebP files.}
        {--remove-originals : Delete the JPEG/PNG originals of converted files. Asks first unless --force.}
        {--force : Do not ask before --remove-originals or --restore.}
        {--limit=0 : Stop after this many files (0 = all).}';

    protected $description = 'Convert the shop\'s JPEG/PNG images to WebP and re-point every use of them.';

    public function handle(): int
    {
        if ($this->option('remove-originals')) {
            return $this->removeOriginals();
        }

        if ($this->option('restore')) {
            return $this->restore();
        }

        if (! WebpConverter::available()) {
            $this->error((string) WebpConverter::unavailableReason());

            return self::FAILURE;
        }

        return $this->option('plan') ? $this->plan() : $this->convert();
    }

    private function plan(): int
    {
        $limit = max(0, (int) $this->option('limit'));
        $after = '';
        $files = $convert = $before = $afterBytes = $refs = $rows = 0;
        $columns = [];
        $samples = [];

        do {
            $batch = WebpBulk::plan($after, WebpBulk::MAX_FILES, 60.0);
            $after = $batch['cursor'];

            foreach ($batch['files'] as $file) {
                $files++;

                if ($this->output->isVerbose()) {
                    $this->line(sprintf('  %-70s %s', $file['path'], $file['ok']
                        ? $this->bytes($file['bytes_before']).' -> '.$this->bytes($file['bytes_after']).', '.$file['refs'].' refs'
                        : 'skip: '.$file['reason']));
                }
            }

            $convert += $batch['convert'];
            $before += $batch['bytes_before'];
            $afterBytes += $batch['bytes_after'];
            $refs += $batch['references']['replacements'];
            $rows += $batch['references']['rows'];

            foreach ($batch['references']['columns'] as $column => $n) {
                $columns[$column] = ($columns[$column] ?? 0) + $n;
            }

            $samples = array_slice(array_merge($samples, $batch['references']['samples']), 0, 10);
        } while (! $batch['done'] && ($limit === 0 || $files < $limit));

        $this->info("Dry run — nothing was changed.");
        $this->line("  Files looked at:     {$files}");
        $this->line("  Would convert:       {$convert}  (".($files - $convert).' left as they are)');
        $this->line('  Bytes:               '.$this->bytes($before).' -> '.$this->bytes($afterBytes)
            .'  (saves '.$this->bytes($before - $afterBytes).')');
        $this->line("  References to move:  {$refs} in {$rows} rows");

        foreach ($columns as $column => $n) {
            $this->line(sprintf('      %-34s %d', $column, $n));
        }

        foreach ($samples as $s) {
            $this->line("  e.g. {$s['table']}.{$s['column']} #{$s['id']}: {$s['from']}");
        }

        return self::SUCCESS;
    }

    private function convert(): int
    {
        $limit = max(0, (int) $this->option('limit'));
        $done = $converted = $skipped = $failed = $saved = $refs = 0;

        do {
            $batch = WebpBulk::run($limit === 0 ? WebpBulk::MAX_FILES : max(1, min(WebpBulk::MAX_FILES, $limit - $done)), 60.0);

            if (isset($batch['error'])) {
                $this->error($batch['error']);

                return self::FAILURE;
            }

            $converted += $batch['converted'];
            $skipped += $batch['skipped'];
            $failed += $batch['failed'];
            $saved += $batch['bytes_before'] - $batch['bytes_after'];
            $refs += $batch['references']['replacements'] ?? 0;
            $done += count($batch['files']);

            foreach ($batch['files'] as $file) {
                $this->line(sprintf('  %-9s %s%s', $file['status'], $file['path'], $file['to'] ? ' -> '.$file['to'] : ' ('.$file['reason'].')'));
            }
        } while (! $batch['done'] && $batch['files'] !== [] && ($limit === 0 || $done < $limit));

        $this->info("Converted {$converted}, left {$skipped} as they were, {$failed} failed. Saved ".$this->bytes($saved).", moved {$refs} references.");
        $this->line('Originals are kept. When the shop looks right: php artisan kbb:webp --remove-originals');

        return self::SUCCESS;
    }

    private function restore(): int
    {
        if (! $this->option('force') && ! $this->confirm('Point every converted image back at its original and delete the WebP files?')) {
            return self::FAILURE;
        }

        $total = 0;

        do {
            $batch = WebpBulk::restore();

            if (isset($batch['error'])) {
                $this->error($batch['error']);

                return self::FAILURE;
            }

            $total += $batch['restored'];
        } while (! $batch['done'] && $batch['restored'] > 0);

        $this->info("Restored {$total} images to their originals.");

        return self::SUCCESS;
    }

    private function removeOriginals(): int
    {
        if (! $this->option('force') && ! $this->confirm('Delete the JPEG/PNG originals of every converted image? Old .jpg links will stop working.')) {
            return self::FAILURE;
        }

        $after = 0;
        $removed = $kept = $bytes = 0;

        do {
            $batch = WebpBulk::removeOriginals($after);

            if (isset($batch['error'])) {
                $this->error($batch['error']);

                return self::FAILURE;
            }

            $after = $batch['cursor'];
            $removed += $batch['removed'];
            $kept += $batch['kept'];
            $bytes += $batch['bytes_freed'];
        } while (! $batch['done']);

        $this->info("Removed {$removed} originals, freed ".$this->bytes($bytes).". Kept {$kept} that something still uses.");

        return self::SUCCESS;
    }

    private function bytes(int $n): string
    {
        $abs = abs($n);

        return match (true) {
            $abs >= 1048576 => round($n / 1048576, 1).' MB',
            $abs >= 1024 => round($n / 1024, 1).' KB',
            default => $n.' B',
        };
    }
}
