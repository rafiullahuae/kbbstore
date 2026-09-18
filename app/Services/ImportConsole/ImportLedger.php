<?php

declare(strict_types=1);

namespace App\Services\ImportConsole;

use App\Services\Import\EntityReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What this shop has imported, what it is about to import again, and the
 * sentence that tells the two apart — Lane GF.
 *
 * =============================================================================
 * THIS IS NOT A CORRECTNESS BACKSTOP AND MUST NOT BE MISTAKEN FOR ONE
 * =============================================================================
 *
 * The importer is ALREADY idempotent by external id, and it was proved so at
 * volume rather than argued: 671 products, 4,159 orders and 3,712 customers
 * imported twice, second pass `created 0, updated 0`, byte-identical database
 * (docs/FV-IMPORT-AT-VOLUME.md §4). Nothing in this file protects any row from
 * anything. Remove it entirely and a double import still produces the identical
 * database.
 *
 * What it protects is the OWNER'S AFTERNOON. He asked to be told he is
 * importing something he has already imported, and the reason he asked is that
 * the alternative is pressing Import, watching sixty browser steps go past for
 * several minutes, and being handed a report that says every row was unchanged
 * — which is a correct result, an expensive one, and indistinguishable at a
 * glance from nothing having worked.
 *
 * So: refuse with a sentence, and let him override it deliberately.
 *
 * =============================================================================
 * THE THREE CASES, AND WHICH ONE BLOCKS
 * =============================================================================
 *
 * A file is recognised BY ITS BYTES — `sha256`, from the manifest's own
 * vocabulary and matched against the digest of the file on this shop's disk.
 * An export is recognised by `export_id`, and that is used for the WORDS, not
 * for the decision. That split is the whole design and each half earns its
 * keep:
 *
 *   ALREADY IMPORTED — every file present carries a sha256 that a finished
 *     live run has already read. This is the one that blocks. One sentence
 *     naming the export, where it came from, and when it went in; and a tick
 *     box that does it anyway.
 *
 *   PART-WAY — some files match and some do not. NEVER BLOCKS, and this is the
 *     important one to get right, because it is not an unusual state, it is the
 *     normal one. An import on shared hosting is sixty browser requests and the
 *     owner closes the tab; a delta export carries three files of nine; a run
 *     that stopped after customers is continued the next morning. Every one of
 *     those is part-way, and a guard that refused them would refuse the
 *     resumability this whole console exists to provide. It says which files
 *     are already in and carries on.
 *
 *   CORRECTED RE-EXPORT — the same export id and the same entity, carrying a
 *     DIFFERENT sha256. The owner found something wrong, fixed it in WordPress,
 *     exported again. THIS MUST NEVER BE REFUSED, and it is the case a guard
 *     written the lazy way — "have I seen this export id before" — gets exactly
 *     backwards: it would refuse the one import that has new information in it
 *     and allow the one that has none. It is reported as its own state, with
 *     its own sentence, so the owner can see that the shop noticed.
 *
 * A manifest with no export id, or no manifest at all, still gets the sha256
 * half: the bytes are on the disk either way. It loses only the wording.
 *
 * =============================================================================
 * A PREVIEW IS NEVER BLOCKED
 * =============================================================================
 * A preview writes nothing and rolls itself back. Refusing to LOOK at an export
 * because it is already imported would be refusing the one operation that is
 * free, and the owner's reason for looking is usually that he is not sure what
 * is in it. The verdict is still computed and still shown for a preview; it
 * just does not stop it.
 */
final class ImportLedger
{
    public const TABLE = 'import_history';

    /** How many past runs the history screen shows before it asks for the CSV. */
    public const PAGE_RUNS = 40;

    /**
     * Observations that are RESTATED on every step rather than accumulated.
     *
     * Everything an EntityReport records about a ROW happens once, because each
     * row is read once across a run — the checkpoint sees to that — so summing
     * the steps is right. These two are facts about the WHOLE ENTITY, produced
     * afresh by every run() call, and the screen calls run() once per browser
     * step. Summed, an import in slices of two reports one more discard per
     * step than the identical import in one go: the same defect
     * ImportDriver::mergeNotes() already solves for the verification note, in
     * the same shape and for the same reason.
     *
     * Matched on the prefix of the kind rather than on an exact string because
     * the kinds are long sentences that carry their own explanation and will be
     * reworded; the opening clause is what identifies them.
     *
     * ▲ AND A HONEST LIMIT ON THE FIRST OF THEM, found while testing this and
     * reported in docs/GF-IMPORT-REFINEMENT.md rather than papered over.
     * ImportRunner resets `columnsSeen`/`columnsRead` at the start of EVERY
     * run() call, and a column is "read" only on a row where the importer
     * actually reached for it — so a slice of two rows can call a column
     * ignored that the next slice reads. The count kept here is therefore the
     * LAST SLICE'S observation, not a fact about the file, and a sliced run and
     * an unsliced run of the same export can disagree about it. Fixing that
     * properly means carrying the two column sets across HTTP requests in the
     * checkpoint, which is a change to the importer this lane did not make. The
     * row-level kinds beside it are exact: each row is read once, so they add
     * up.
     */
    public const RESTATED_KINDS = [
        'columns in this export that no field of this importer reads',
        'a file in the export folder that no importer opens',
    ];

    /**
     * Record one entity's work in one run, replacing what this run last said
     * about it.
     *
     * CALLED AFTER THE BATCH TRANSACTION, NOT INSIDE IT, and that is deliberate
     * in both directions. Not inside, because this is a narrative for a person
     * and the transaction that carries rows must not grow a second writer that
     * can fail it. Not skipped when a step dies either: the next step for the
     * same entity rewrites the row from the checkpoint, which is the number
     * that survived, so the history converges on the truth rather than
     * accumulating whatever the last surviving request happened to hold.
     *
     * @param  array<string, mixed>  $fields
     */
    public function record(string $runUid, string $entity, array $fields): void
    {
        if (! Schema::hasTable(self::TABLE) || $runUid === '') {
            return;
        }

        $now = now();

        $row = [
            'run_uid' => $runUid,
            'run_key' => (string) ($fields['run_key'] ?? ImportDriver::RUN_KEY),
            'mode' => ($fields['mode'] ?? 'live') === 'preview' ? 'preview' : 'live',
            'entity' => $entity,
            'export_id' => $fields['export_id'] ?? null,
            'export_generated_at' => $fields['export_generated_at'] ?? null,
            'source_site' => $fields['source_site'] ?? null,
            'source' => isset($fields['source']) ? (string) json_encode($fields['source']) : null,
            'file_sha256' => $fields['file_sha256'] ?? null,
            'file_name' => $fields['file_name'] ?? null,
            'manifest_rows' => $fields['manifest_rows'] ?? null,
            'manifest_sha256' => $fields['manifest_sha256'] ?? null,
            'rows_counted' => $fields['rows_counted'] ?? null,
            'processed' => (int) ($fields['processed'] ?? 0),
            'created_rows' => (int) ($fields['created'] ?? 0),
            'updated_rows' => (int) ($fields['updated'] ?? 0),
            'unchanged_rows' => (int) ($fields['unchanged'] ?? 0),
            'rejected_rows' => (int) ($fields['rejected'] ?? 0),
            /*
             * MERGED, NOT REPLACED AND NOT BLINDLY SUMMED. Every other counter
             * here comes from `import_checkpoints`, which is cumulative for the
             * run and survives a killed request. The adjustment and discard
             * lists do not: they live in the ImportReport of ONE step and die
             * with it. So they arrive as this step's observations, by kind, and
             * are folded into what the row already holds — see mergeKinds().
             * A step that dies before this runs loses that step's observations
             * and no rows, which is the same trade the rejection CSV already
             * makes and is why a step is small.
             */
            'adjusted' => 0,
            'discarded' => 0,
            'adjusted_kinds' => null,
            'discarded_kinds' => null,
            'finished' => (bool) ($fields['finished'] ?? false),
            'notes' => isset($fields['notes']) ? (string) json_encode($fields['notes']) : null,
            'verification' => isset($fields['verification']) ? (string) json_encode($fields['verification']) : null,
            'started_at' => $fields['started_at'] ?? $now,
            'finished_at' => ($fields['finished'] ?? false) ? $now : null,
            'updated_at' => $now,
        ];

        $existing = DB::table(self::TABLE)
            ->where('run_uid', $runUid)
            ->where('entity', $entity)
            ->first();

        $adjusted = self::mergeKinds(
            $existing === null ? [] : self::decodeKinds($existing->adjusted_kinds ?? null),
            (array) ($fields['adjusted_kinds'] ?? []),
        );

        $discarded = self::mergeKinds(
            $existing === null ? [] : self::decodeKinds($existing->discarded_kinds ?? null),
            (array) ($fields['discarded_kinds'] ?? []),
        );

        $row['adjusted_kinds'] = $adjusted === [] ? null : (string) json_encode($adjusted);
        $row['discarded_kinds'] = $discarded === [] ? null : (string) json_encode($discarded);
        $row['adjusted'] = array_sum($adjusted);
        $row['discarded'] = array_sum($discarded);

        if ($existing === null) {
            $row['created_at'] = $now;
            DB::table(self::TABLE)->insert($row);

            return;
        }

        /*
         * Once finished, stays finished with the time it finished at. A later
         * step re-presenting the same entity (which the runner does — a
         * completed checkpoint restarts from row one) must not move the moment
         * this entity was completed forward to now.
         */
        if ((bool) $existing->finished && $row['finished']) {
            $row['finished_at'] = $existing->finished_at;
        }

        unset($row['run_uid'], $row['entity']);

        DB::table(self::TABLE)->where('id', $existing->id)->update($row);
    }

    /**
     * Has this exact file been imported, finished, by a live run before?
     *
     * @return object|null the most recent such row
     */
    public function lastImportOf(string $sha256): ?object
    {
        if ($sha256 === '' || ! Schema::hasTable(self::TABLE)) {
            return null;
        }

        return DB::table(self::TABLE)
            ->where('file_sha256', $sha256)
            ->where('mode', 'live')
            ->where('finished', true)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * The most recent finished live import of this entity FROM THIS EXPORT,
     * whatever the bytes were.
     *
     * This is the half that recognises a corrected re-export: it finds the row,
     * the caller compares the digests, and a difference is news rather than a
     * duplicate.
     */
    public function lastImportOfEntityFrom(string $exportId, string $entity): ?object
    {
        if ($exportId === '' || ! Schema::hasTable(self::TABLE)) {
            return null;
        }

        return DB::table(self::TABLE)
            ->where('export_id', $exportId)
            ->where('entity', $entity)
            ->where('mode', 'live')
            ->where('finished', true)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * What would happen if the owner pressed Import now.
     *
     * @param  array<string, array{sha256: ?string, file: string, label: string}>  $present
     *                                                                                      the entities that have a file, keyed by entity
     * @return array<string, mixed>
     */
    public function verdict(ImportManifest $manifest, array $present): array
    {
        $exportId = $manifest->exportId();
        $entities = [];

        $imported = 0;
        $changed = 0;
        $fresh = 0;

        foreach ($present as $entity => $file) {
            $sha = (string) ($file['sha256'] ?? '');
            $seen = $sha === '' ? null : $this->lastImportOf($sha);

            if ($seen !== null) {
                $imported++;

                $entities[$entity] = [
                    'entity' => $entity,
                    'label' => $file['label'],
                    'state' => 'imported',
                    'when' => $seen->finished_at === null ? null : (string) $seen->finished_at,
                    'export_id' => $seen->export_id === null ? null : (string) $seen->export_id,
                    'rows' => (int) $seen->processed,
                    'sentence' => $file['label'].' — these exact bytes were imported'
                        .($seen->finished_at === null ? '' : ' on '.self::day((string) $seen->finished_at))
                        .', '.number_format((int) $seen->processed).' rows.',
                ];

                continue;
            }

            $before = $exportId === null ? null : $this->lastImportOfEntityFrom($exportId, $entity);

            if ($before !== null) {
                $changed++;

                $entities[$entity] = [
                    'entity' => $entity,
                    'label' => $file['label'],
                    'state' => 'changed',
                    'when' => $before->finished_at === null ? null : (string) $before->finished_at,
                    'export_id' => $exportId,
                    'rows' => (int) $before->processed,
                    'sentence' => $file['label'].' has CHANGED since it was imported'
                        .($before->finished_at === null ? '' : ' on '.self::day((string) $before->finished_at))
                        .'. '
                        .(($file['disputed'] ?? false)
                            // A digest that does not match is a correction OR a
                            // file that did not arrive whole, and calling the
                            // second one a correction would be the shop
                            // reassuring the owner about the thing that is
                            // wrong. The row count is what tells them apart.
                            ? 'It also does not match the row count its own manifest gives it, so this is '
                                .'not a re-export — it is a file that did not arrive whole. Upload it again '
                                .'before importing.'
                            : 'This is a corrected re-export and it will be imported again — that is the '
                                .'point of it, and nothing here refuses it.'),
                ];

                continue;
            }

            $fresh++;

            $entities[$entity] = [
                'entity' => $entity,
                'label' => $file['label'],
                'state' => 'new',
                'when' => null,
                'export_id' => $exportId,
                'rows' => 0,
                'sentence' => $file['label'].' has not been imported before.',
            ];
        }

        $total = count($present);

        $status = match (true) {
            $total === 0 => 'nothing',
            $imported === $total => 'already',
            $imported > 0 || $changed > 0 => 'partial',
            default => 'fresh',
        };

        return [
            'status' => $status,
            // ONLY 'already' BLOCKS. Everything else is information.
            'blocking' => $status === 'already',
            'export_id' => $exportId,
            'export_label' => $manifest->label(),
            'manifest' => $manifest->usable(),
            'counts' => ['imported' => $imported, 'changed' => $changed, 'new' => $fresh, 'files' => $total],
            'entities' => array_values($entities),
            'sentence' => $this->sentence($status, $manifest, $imported, $changed, $fresh, $total),
        ];
    }

    /**
     * The history, newest run first, assembled for a screen.
     *
     * @return list<array<string, mixed>>
     */
    public function runs(int $limit = self::PAGE_RUNS): array
    {
        if (! Schema::hasTable(self::TABLE)) {
            return [];
        }

        $limit = max(1, min(500, $limit));

        /*
         * The run uids of the N most recent runs, then every row belonging to
         * them. Two queries and no aggregate SQL — App\Support\AggregatesQueries
         * exists because of what the aggregate-plus-bare-column shape has cost
         * this repo, and the largest thing this table will ever hold is a few
         * thousand rows.
         */
        $uids = DB::table(self::TABLE)
            ->select('run_uid')
            ->orderByDesc('id')
            ->limit($limit * 12)
            ->pluck('run_uid')
            ->unique()
            ->take($limit)
            ->values()
            ->all();

        if ($uids === []) {
            return [];
        }

        $rows = DB::table(self::TABLE)
            ->whereIn('run_uid', $uids)
            ->orderBy('id')
            ->get();

        $runs = [];

        foreach ($rows as $row) {
            $uid = (string) $row->run_uid;

            $runs[$uid] ??= [
                'run_uid' => $uid,
                'mode' => (string) $row->mode,
                'export_id' => $row->export_id === null ? null : (string) $row->export_id,
                'export_generated_at' => $row->export_generated_at === null ? null : (string) $row->export_generated_at,
                'source_site' => $row->source_site === null ? null : (string) $row->source_site,
                'source' => $row->source === null ? null : json_decode((string) $row->source, true),
                'started_at' => $row->started_at === null ? null : (string) $row->started_at,
                'finished_at' => null,
                'entities' => [],
                'totals' => [
                    'processed' => 0, 'created' => 0, 'updated' => 0,
                    'unchanged' => 0, 'rejected' => 0, 'adjusted' => 0, 'discarded' => 0,
                ],
            ];

            $runs[$uid]['entities'][] = [
                'entity' => (string) $row->entity,
                'file' => $row->file_name === null ? null : (string) $row->file_name,
                'file_sha256' => $row->file_sha256 === null ? null : (string) $row->file_sha256,
                'manifest_rows' => $row->manifest_rows === null ? null : (int) $row->manifest_rows,
                'rows_counted' => $row->rows_counted === null ? null : (int) $row->rows_counted,
                'processed' => (int) $row->processed,
                'created' => (int) $row->created_rows,
                'updated' => (int) $row->updated_rows,
                'unchanged' => (int) $row->unchanged_rows,
                'rejected' => (int) $row->rejected_rows,
                'adjusted' => (int) $row->adjusted,
                'discarded' => (int) $row->discarded,
                // The kinds, because "2,297 adjusted" is a number and "1,046
                // order amounts carrying fils on a shop that prints whole
                // dirhams" is a decision. docs/FV-IMPORT-AT-VOLUME.md §10.
                'adjusted_kinds' => self::decodeKinds($row->adjusted_kinds ?? null),
                'discarded_kinds' => self::decodeKinds($row->discarded_kinds ?? null),
                'finished' => (bool) $row->finished,
                'finished_at' => $row->finished_at === null ? null : (string) $row->finished_at,
                'notes' => $row->notes === null ? [] : (array) json_decode((string) $row->notes, true),
                'verification' => $row->verification === null ? null : json_decode((string) $row->verification, true),
            ];

            foreach ([
                'processed' => 'processed', 'created' => 'created_rows', 'updated' => 'updated_rows',
                'unchanged' => 'unchanged_rows', 'rejected' => 'rejected_rows',
                'adjusted' => 'adjusted', 'discarded' => 'discarded',
            ] as $key => $column) {
                $runs[$uid]['totals'][$key] += (int) $row->{$column};
            }

            if ($row->finished_at !== null
                && ((string) $row->finished_at) > (string) ($runs[$uid]['finished_at'] ?? '')) {
                $runs[$uid]['finished_at'] = (string) $row->finished_at;
            }
        }

        // Newest run first. Sorted on the id order the rows arrived in rather
        // than on a timestamp string, because two runs in one second are a
        // thing the test suite does and a person never will.
        return array_reverse(array_values($runs));
    }

    /**
     * Every entity of every run, flattened, for the spreadsheet.
     *
     * @return list<array<string, string|int>>
     */
    public function flat(int $limit = 5000): array
    {
        $out = [];

        foreach ($this->runs($limit) as $run) {
            foreach ($run['entities'] as $entity) {
                $out[] = [
                    'when' => (string) ($entity['finished_at'] ?? $run['started_at'] ?? ''),
                    'mode' => (string) $run['mode'],
                    'export_id' => (string) ($run['export_id'] ?? ''),
                    'taken_from' => (string) ($run['source_site'] ?? ''),
                    'taken_at' => (string) ($run['export_generated_at'] ?? ''),
                    'entity' => (string) $entity['entity'],
                    'file' => (string) ($entity['file'] ?? ''),
                    'sha256' => (string) ($entity['file_sha256'] ?? ''),
                    'rows_in_file' => (string) ($entity['manifest_rows'] ?? $entity['rows_counted'] ?? ''),
                    'processed' => (int) $entity['processed'],
                    'created' => (int) $entity['created'],
                    'updated' => (int) $entity['updated'],
                    'unchanged' => (int) $entity['unchanged'],
                    'refused' => (int) $entity['rejected'],
                    'adjusted' => (int) $entity['adjusted'],
                    'discarded' => (int) $entity['discarded'],
                    'finished' => $entity['finished'] ? 'yes' : 'no',
                ];
            }
        }

        return $out;
    }

    /** Counts off an EntityReport, in the shape record() wants. */
    public static function fromReport(EntityReport $report): array
    {
        $verification = $report->verification();

        $counts = static fn (array $groups): array => array_map(
            static fn (array $g): int => (int) ($g['count'] ?? 0),
            $groups,
        );

        return [
            'created' => $report->created,
            'updated' => $report->updated,
            'unchanged' => $report->unchanged,
            'rejected' => $report->rejectedCount(),
            'adjusted_kinds' => $counts($report->adjustments()),
            'discarded_kinds' => $counts($report->discards()),
            'verification' => [
                'verdict' => $verification['verdict'],
                'sentence' => $verification['sentence'],
            ],
        ];
    }

    /* --------------------------------------------------------------- guts */

    private function sentence(
        string $status,
        ImportManifest $manifest,
        int $imported,
        int $changed,
        int $fresh,
        int $total,
    ): string {
        $what = $manifest->usable() ? $manifest->label() : 'this export';

        return match ($status) {
            'nothing' => 'There are no files to import yet.',
            'already' => 'THIS HAS ALREADY BEEN IMPORTED. All '.$total.' file'.($total === 1 ? '' : 's')
                .' here are byte-for-byte the ones a finished import already read — '.$what.'. Importing '
                .'again is safe and does nothing: every row is matched on its WooCommerce id, so it would '
                .'take several minutes and report every row as unchanged. Upload the newer export instead, '
                .'or tick the box below to do it again anyway.',
            'partial' => $changed > 0 && $imported > 0
                ? number_format($imported).' of '.$total.' files here have already been imported and '
                    .number_format($changed).' '.($changed === 1 ? 'has' : 'have').' changed since. The '
                    .'changed '.($changed === 1 ? 'one is a corrected re-export and will be' : 'ones are '
                    .'corrected re-exports and will be').' read again; the rest will report as unchanged.'
                : ($changed > 0
                    ? number_format($changed).' of '.$total.' files here '.($changed === 1 ? 'has' : 'have')
                        .' changed since '.($changed === 1 ? 'it was' : 'they were').' imported. That is a '
                        .'corrected re-export and it is exactly what should be imported again.'
                    : number_format($imported).' of '.$total.' files here have already been imported and '
                        .number_format($fresh + $changed).' '.($fresh + $changed === 1 ? 'has' : 'have')
                        .' not. This is what a part-finished import and a delta export both look like, and '
                        .'both are fine to continue.'),
            default => $manifest->usable()
                ? 'Nothing here has been imported before — '.$what.'.'
                : 'Nothing here has been imported before. This export carries no manifest.json, so the shop '
                    .'can only recognise it file by file, by their contents.',
        };
    }

    /**
     * Fold this step's observations into the run's.
     *
     * @param  array<string, int>  $existing
     * @param  array<string, int>  $seen
     * @return array<string, int>
     */
    public static function mergeKinds(array $existing, array $seen): array
    {
        foreach ($seen as $kind => $count) {
            $kind = (string) $kind;
            $count = (int) $count;

            $restated = false;

            foreach (self::RESTATED_KINDS as $prefix) {
                if (str_starts_with($kind, $prefix)) {
                    $restated = true;
                    break;
                }
            }

            $existing[$kind] = $restated ? $count : (int) ($existing[$kind] ?? 0) + $count;
        }

        return $existing;
    }

    /** @return array<string, int> */
    private static function decodeKinds(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? array_map('intval', $decoded) : [];
    }

    /** '2026-09-18 14:02:11' -> '18 Sep 2026'. Never throws on a value from the database. */
    private static function day(string $timestamp): string
    {
        $time = strtotime($timestamp);

        return $time === false ? $timestamp : date('j M Y', $time);
    }
}
