<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Translation\ArabicStructureRepair;
use Illuminate\Console\Command;

/**
 * Line the stored Arabic product copy up with its English: blank lines and list
 * boundaries only, never a word. (Lane ARDX, 14 November 2026)
 *
 *   php artisan kbb:ar-structure --dry-run              what would change, per product; writes nothing (the default)
 *   php artisan kbb:ar-structure --dry-run --recent=50  the 50 newest products only
 *   php artisan kbb:ar-structure --apply                write the repairs (backup of every row first)
 *   php artisan kbb:ar-structure --apply --product=123  one product
 *   php artisan kbb:ar-structure --batches              the last repairs, newest first
 *   php artisan kbb:ar-structure --undo=<batch>         put a batch back
 *
 * Anything that cannot be lined up with certainty is listed with the reason and
 * left alone. App\Services\Translation\ArabicStructureRepair has the rules.
 */
final class ArabicStructureCommand extends Command
{
    protected $signature = 'kbb:ar-structure
        {--dry-run : list what would change (the default)}
        {--apply : write the repairs, backing up every changed row first}
        {--recent= : only the newest N products}
        {--product=* : only these product ids}
        {--undo= : put back the batch with this id}
        {--batches : list the last repair batches}';

    protected $description = 'Arabic product copy: put back the blank lines and list breaks its English has (no word changes).';

    public function handle(): int
    {
        if ($this->option('batches')) {
            foreach (ArabicStructureRepair::batches(20) as $b) {
                $this->line(sprintf('%s  %d row(s)  %s%s', $b['batch'], $b['rows'], $b['at'], $b['undone'] ? '  (undone)' : ''));
            }

            return self::SUCCESS;
        }

        if (($batch = $this->option('undo')) !== null) {
            $r = ArabicStructureRepair::undo((string) $batch);
            $this->info("Undo {$batch}: {$r['restored']} row(s) put back, {$r['kept']} kept (edited since).");

            return self::SUCCESS;
        }

        $ids = $this->scope();

        if (! $this->option('apply')) {
            $scan = ArabicStructureRepair::scan($ids);

            foreach ($scan['fixable'] as $f) {
                $this->line(sprintf('FIX   #%d %s %s: %s -> %s  (English: %s)', (int) $f['product_id'], $f['slug'], $f['field'], $f['before'], $f['after'], $f['en']));
                foreach ($f['changes'] as $c) {
                    $this->line('        - '.$c);
                }
            }
            foreach ($scan['human'] as $h) {
                $this->line(sprintf('LEAVE #%d %s %s: %s', (int) $h['product_id'], $h['slug'], $h['field'], $h['why']));
            }

            $this->info(sprintf(
                'Dry run: %d Arabic row(s) read, %d already line up, %d would be repaired, %d need a person, %d not translated. Nothing was written.',
                $scan['rows'], $scan['aligned'], count($scan['fixable']), count($scan['human']), $scan['untranslated'],
            ));

            return self::SUCCESS;
        }

        try {
            $r = ArabicStructureRepair::apply($ids);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($r['batch'] === null
            ? "Nothing to repair. {$r['human']} row(s) need a person (see --dry-run)."
            : "Repaired {$r['changed']} row(s); backup batch {$r['batch']} (undo: php artisan kbb:ar-structure --undo={$r['batch']}). {$r['human']} row(s) need a person (see --dry-run).");

        return self::SUCCESS;
    }

    /** @return list<int>|null */
    private function scope(): ?array
    {
        $ids = array_values(array_filter(array_map('intval', (array) $this->option('product')), static fn (int $id): bool => $id > 0));

        if ($ids !== []) {
            return $ids;
        }

        $recent = $this->option('recent');

        return $recent !== null && (int) $recent > 0 ? ArabicStructureRepair::recentIds(min(5000, (int) $recent)) : null;
    }
}
