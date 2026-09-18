<?php

declare(strict_types=1);

namespace App\Services\Import;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Everything the migration is doing, in one read — Lane GD.
 *
 * The owner asked for "a live progress url of everything". "Everything" is
 * taken literally and narrowly at the same time: EVERY STAGE THAT HAS A
 * TRUTHFUL NUMBER, and no stage that does not.
 *
 *   PICTURES        fetched / remaining / failed / bytes, from MediaSideloader,
 *                   recomputed from the catalogue and the disk on every call.
 *
 *   IMAGE PATHS     present / missing / still-on-the-old-site, from MediaAudit.
 *                   This is the number that says whether the migration is
 *                   finished; the pictures stage is the number that says how
 *                   far along it is.
 *
 *   ADDRESSES       the redirect map's three buckets, from RedirectMap.
 *
 *   CATALOGUE       read from `import_checkpoints` and `import_runs`, which are
 *                   TABLES, not another lane's source. ImportDriver writes them
 *                   inside the same transaction as the rows they count, so they
 *                   are the most trustworthy numbers on this page.
 *
 * WHAT IS HONESTLY MISSING, and why it is stated rather than faked: there is no
 * TOTAL row count anywhere in `import_checkpoints`. The table records how many
 * source rows were consumed and nothing about how many there are, because the
 * source is a CSV whose length is not known until it has been read. So the
 * catalogue stage reports rows DONE and refuses to draw a percentage. A
 * progress bar built on a denominator nobody has is the kind of number that
 * looks like information and is not, and this page exists because the owner
 * could not tell a finished run from a stalled one.
 *
 * THE LAYOUT IS BY STAGE, and each stage carries its own `state` and `note`.
 * Adding a stage later — the order documents, the reviews, whatever comes — is
 * appending one entry to `stages()`, not rebuilding the page.
 */
final class MigrationProgress
{
    public function __construct(
        private readonly MediaSideloader $sideloader = new MediaSideloader,
        private readonly MediaAudit $audit = new MediaAudit,
        private readonly RedirectMap $map = new RedirectMap,
    ) {}

    /**
     * @param  array{failures?: int, hosts?: list<string>}  $options
     * @return array<string, mixed>
     */
    public function snapshot(array $options = []): array
    {
        $hosts = $this->sideloader->hosts();
        $plan = $this->sideloader->plan($hosts);
        $run = $this->sideloader->run();
        $audit = $this->audit->audit();
        $counts = $this->audit->summarise($audit);

        return [
            'ok' => true,
            'generated_at' => now()->toIso8601String(),
            /*
             * The client polls on this, and it is the SERVER that decides.
             * A page that keeps hitting a shared host every two seconds after
             * the run has finished is a page that costs the owner money for
             * nothing, so the interval widens when nothing is happening and
             * `poll` goes false outright when there is nothing left to watch.
             */
            'poll' => $run['state'] === 'running',
            'poll_seconds' => $run['state'] === 'running' ? 3 : 15,
            'stages' => [
                $this->pictures($plan, $run),
                $this->paths($counts),
                $this->addresses(),
                $this->catalogue(),
            ],
            'sideload' => [
                'plan' => $plan,
                'run' => $run,
                'hosts' => $hosts,
                'failures' => $this->sideloader->failures((int) ($options['failures'] ?? 50)),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $run
     * @return array<string, mixed>
     */
    private function pictures(array $plan, array $run): array
    {
        $remaining = (int) $plan['remaining'];
        $present = (int) $plan['present'];
        $total = (int) $plan['total'];
        $state = (string) $run['state'];

        /*
         * The sentence the whole page is for. IDLE with work left and RUNNING
         * with work left read completely differently and used to render as the
         * same bar.
         */
        $headline = match (true) {
            $total === 0 => 'No picture on this shop is served by another site.',
            $state === 'running' && $remaining > 0 => 'Running — '.$present.' of '.$total.' fetched, '.$remaining.' to go.',
            $state === 'running' => 'Running — the last batch found nothing left to fetch.',
            $state === 'stalled' => 'STALLED — '.$remaining.' still to fetch, and the last batch did not come back. '
                .'Nothing is lost; press Fetch to continue.',
            $remaining > 0 => 'Idle — '.$remaining.' picture'.($remaining === 1 ? '' : 's').' still to fetch.',
            default => 'Finished — every picture the catalogue names is on this shop\'s own disk.',
        };

        return [
            'key' => 'pictures',
            'title' => 'Pictures fetched from the old site',
            'state' => $state,
            'headline' => $headline,
            'note' => (string) $run['note'],
            'done' => $present,
            'total' => $total,
            'remaining' => $remaining,
            'failed' => (int) $plan['failed'],
            'refused' => (int) $plan['refused'],
            'bytes' => (int) $plan['bytes_on_disk'],
            'bytes_label' => $this->sideloader->bytes((int) $plan['bytes_on_disk']),
            'estimate_label' => $this->sideloader->bytes((int) $plan['estimated_bytes']),
            'free_label' => $plan['free_bytes'] === null ? 'unknown' : $this->sideloader->bytes((int) $plan['free_bytes']),
            'enough_room' => (bool) $plan['enough_room'],
            'hosts' => $plan['hosts'],
        ];
    }

    /**
     * @param  array{present: int, missing: int, remote: int}  $counts
     * @return array<string, mixed>
     */
    private function paths(array $counts): array
    {
        $total = $counts['present'] + $counts['missing'] + $counts['remote'];

        return [
            'key' => 'paths',
            'title' => 'Image references in the catalogue',
            'state' => ($counts['missing'] + $counts['remote']) === 0 ? 'done' : 'attention',
            'headline' => $counts['remote'] > 0
                ? $counts['remote'].' image references are still served by another site and will break the day it '
                    .'is switched off.'
                : ($counts['missing'] > 0
                    ? $counts['missing'].' image references point at a file that is not on this shop\'s disk.'
                    : 'Every image reference resolves to a file under this shop\'s web root.'),
            'note' => 'Fetching a picture puts the file on disk; it does not change the row. Re-point the rows on '
                .'the Addresses & pictures screen once the fetch is finished, and "still on the old site" goes to '
                .'zero.',
            'done' => $counts['present'],
            'total' => $total,
            'remaining' => $counts['missing'] + $counts['remote'],
            'breakdown' => $counts,
        ];
    }

    /** @return array<string, mixed> */
    private function addresses(): array
    {
        $proposals = $this->map->propose();
        $buckets = ['migrate' => 0, 'ask' => 0, 'discard' => 0];

        foreach ($proposals as $proposal) {
            $decision = (string) ($proposal['decision'] ?? '');

            if (array_key_exists($decision, $buckets)) {
                $buckets[$decision]++;
            }
        }

        $stored = Schema::hasTable('redirects') ? (int) DB::table('redirects')->count() : 0;

        return [
            'key' => 'addresses',
            'title' => 'Old addresses',
            'state' => $buckets['migrate'] === 0 ? 'done' : 'attention',
            'headline' => $buckets['migrate'].' redirect'.($buckets['migrate'] === 1 ? '' : 's').' proposed, '
                .$stored.' stored on the shop now.',
            'note' => 'A redirect only fires on an address that 404s. Rows this shop already answers are in the '
                .'discard and ask buckets with the reason on each one.',
            'done' => $stored,
            'total' => count($proposals),
            'remaining' => $buckets['migrate'],
            'breakdown' => $buckets,
        ];
    }

    /**
     * The catalogue import, read out of the two tables ImportDriver writes.
     *
     * READ-ONLY, and through the table names those classes publish as
     * constants rather than as literals, so a rename is a fatal error here
     * rather than a stage that quietly reports zero for ever.
     *
     * @return array<string, mixed>
     */
    private function catalogue(): array
    {
        if (! Schema::hasTable(Checkpoint::TABLE)) {
            return [
                'key' => 'catalogue',
                'title' => 'Catalogue import',
                'state' => 'unknown',
                'headline' => 'The import checkpoint table is not present on this installation.',
                'note' => '',
                'done' => 0,
                'total' => 0,
                'remaining' => 0,
                'entities' => [],
            ];
        }

        $rows = DB::table(Checkpoint::TABLE)
            ->where('run_key', \App\Services\ImportConsole\ImportDriver::RUN_KEY)
            ->orderBy('id')
            ->get();

        $entities = [];
        $processed = 0;
        $rejected = 0;
        $finished = 0;

        foreach ($rows as $row) {
            $processed += (int) $row->processed;
            $rejected += (int) $row->rejected_rows;
            $finished += $row->finished_at === null ? 0 : 1;

            $entities[] = [
                'entity' => (string) $row->entity,
                'processed' => (int) $row->processed,
                'created' => (int) $row->created_rows,
                'updated' => (int) $row->updated_rows,
                'unchanged' => (int) $row->unchanged_rows,
                'rejected' => (int) $row->rejected_rows,
                'source' => $row->source_label === null ? null : (string) $row->source_label,
                'finished_at' => $row->finished_at === null ? null : (string) $row->finished_at,
            ];
        }

        $run = Schema::hasTable(\App\Services\ImportConsole\ImportDriver::TABLE)
            ? DB::table(\App\Services\ImportConsole\ImportDriver::TABLE)
                ->where('run_key', \App\Services\ImportConsole\ImportDriver::RUN_KEY)
                ->first()
            : null;

        $status = $run === null ? 'never' : (string) $run->status;

        return [
            'key' => 'catalogue',
            'title' => 'Catalogue import',
            'state' => $status === 'running' ? 'running' : ($rows->count() === 0 ? 'never' : 'idle'),
            'headline' => $rows->count() === 0
                ? 'No catalogue import has been run from the admin screen.'
                : number_format($processed).' source rows imported across '.$rows->count().' entities'
                    .($rejected > 0 ? ', '.number_format($rejected).' refused' : '').'.',
            /*
             * Said on the screen and not only in a doc, because the absence of
             * a percentage here is a deliberate answer and not an oversight.
             */
            'note' => 'There is no percentage for this stage and there cannot be one: `import_checkpoints` records '
                .'how many source rows were consumed, and nothing records how many a CSV contains until it has '
                .'been read to the end. Rows done is a true number; a bar would not be.',
            'done' => $processed,
            'total' => 0,
            'remaining' => 0,
            'mode' => $run === null ? null : (string) $run->mode,
            'status' => $status,
            'entities_finished' => $finished,
            'entities' => $entities,
        ];
    }
}
