<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\MediaUsageWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Re-derive `media_usages` from App\Support\MediaUsage, or just report the gap.
 *
 * WHY A RECORDED ANSWER NEEDS THIS AND A DERIVED ONE DOES NOT. MediaUsage
 * recomputes from the owning rows every time it is asked, so it cannot be out
 * of date. `media_usages` can. The hooks in MediaUsageWriter cover every write
 * path this application has today, but "today" is the operative word in a repo
 * several lanes are editing at once, and a bulk Eloquent delete already fires
 * no events. A table that can quietly diverge and has no way to SHOW that it
 * has diverged is more dangerous than the walk it replaced.
 *
 * So this exists, and --check is the half that matters: it makes drift
 * something a person can see rather than something that has to be deduced from
 * a wrong badge.
 *
 *     php artisan media:usages-reconcile --check    report, change nothing
 *     php artisan media:usages-reconcile            re-derive and write
 *
 * --check exits 1 when the table disagrees with the derivation, so it can be
 * wired to something that watches, and 0 when they match.
 */
class ReconcileMediaUsages extends Command
{
    protected $signature = 'media:usages-reconcile
                            {--check : Report the difference and write nothing}';

    protected $description = 'Re-derive media_usages from the image URLs on products, brands and categories';

    /** How many differing rows to print before summarising the rest. */
    private const SHOWN = 20;

    public function handle(): int
    {
        if (! Schema::hasTable('media_usages')) {
            $this->error('There is no media_usages table. Run the migrations first.');

            return self::FAILURE;
        }

        if ($this->option('check')) {
            return $this->report();
        }

        $result = MediaUsageWriter::rebuild();

        $this->info(sprintf(
            '%d recorded, %d stale removed, %d already correct.',
            $result['added'],
            $result['removed'],
            $result['kept']
        ));

        return self::SUCCESS;
    }

    /** The --check half: say what is wrong, change nothing. */
    private function report(): int
    {
        $drift = MediaUsageWriter::drift();

        if ($drift['missing'] === [] && $drift['extra'] === []) {
            $this->info('media_usages agrees with the derived answer.');

            return self::SUCCESS;
        }

        /*
         * Named separately, because the two failures are not equally bad.
         *
         * A MISSING row means an image that is on the shop reads as unused on
         * the grid. An EXTRA row means an image that is free reads as in use.
         * Only the first can lead anybody towards deleting something live, and
         * even then the delete guard reads MediaUsage::verify() rather than
         * this table and would still refuse.
         */
        $this->warn(sprintf(
            '%d association(s) missing from the table, %d stale row(s) in it.',
            count($drift['missing']),
            count($drift['extra'])
        ));

        foreach (['missing' => 'Missing', 'extra' => 'Stale'] as $bucket => $label) {
            foreach (array_slice($drift[$bucket], 0, self::SHOWN) as $row) {
                $this->line(sprintf(
                    '  %-7s media #%d -> %s #%d (%s)',
                    $label,
                    $row['media_id'],
                    $row['owner_type'],
                    $row['owner_id'],
                    $row['field']
                ));
            }

            $rest = count($drift[$bucket]) - self::SHOWN;

            if ($rest > 0) {
                $this->line(sprintf('  ... and %d more %s.', $rest, mb_strtolower($label)));
            }
        }

        $this->line('Run without --check to fix.');

        return self::FAILURE;
    }
}
