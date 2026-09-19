<?php

declare(strict_types=1);

namespace App\Services\ImportConsole;

/**
 * `manifest.json` — the one file in a WooCommerce export that describes the
 * rest of it.
 *
 * THE FORMAT IS NOT THIS LANE'S TO INVENT. It is written down in
 * docs/WP-EXPORT-CONTRACT.md, the integrator owns that file, and Lane GE is
 * building the WordPress plugin that writes it at the same time as this reads
 * it. Everything below is a reader for that document and nothing else; where a
 * rule here looks arbitrary it is quoted from the contract in the comment
 * beside it.
 *
 * ---------------------------------------------------------------------------
 * WHAT IT BUYS, WHICH IS NOT WHAT IT LOOKS LIKE IT BUYS
 * ---------------------------------------------------------------------------
 * `rows` is described as the denominator the progress bar never had. That is
 * true of the LIVE PROGRESS PAGE, whose catalogue stage reads `import_checkpoints`
 * and nothing else and so genuinely has no total. It is NOT true of the Import
 * screen: ImportWorkspace counts every uploaded file to the end at upload time
 * and caches the count in a sidecar, and the console has drawn a per-entity bar
 * off that number since Lane AD. So the honest account of what `rows` adds is
 * three things:
 *
 *   1. A denominator for a stage that reads tables rather than files
 *      (MigrationProgress::catalogue()).
 *   2. A whole-export denominator, which nothing had — "4,832 of 19,977 rows"
 *      across every file rather than nine separate bars.
 *   3. AND THE ONE THAT MATTERS MOST: a denominator that can be WRONG, and
 *      therefore one that can be checked. A count taken off the file itself can
 *      never disagree with the file, so it cannot notice that the file is half
 *      of what the exporter wrote — it just draws a confident bar that reaches
 *      100% over a truncated upload. `rows` comes from the other end of the
 *      pipe, so `rows != counted` is a real fact about a real accident, and
 *      ImportDriver draws NO bar when the two disagree.
 *
 * ---------------------------------------------------------------------------
 * WHAT IT REFUSES, AND WHAT IT SHRUGS AT
 * ---------------------------------------------------------------------------
 * The contract draws that line itself and this class copies it exactly:
 *
 *   "Unknown keys are ignored, never fatal. A newer plugin writing a field this
 *    shop does not read must not stop an import."
 *
 *   "`format` is checked. Anything other than `kbb-export/1` is refused with a
 *    sentence naming what was found, not a stack trace."
 *
 * So exactly two things are fatal — a file that is not JSON at all, and a
 * `format` this shop does not speak — and a fatal manifest is reported as a
 * SENTENCE through refusal(), never as an exception. Everything else degrades:
 * a `files` entry whose `rows` is not a whole number means "this file has no
 * stated row count", which is the same state as an export with no manifest at
 * all, and that state already works.
 *
 * A malformed entry must not become a bogus denominator, which is why rowsFor()
 * returns null rather than 0 for anything it cannot read as a count: 0 is a
 * legitimate answer the contract gives a meaning to ("a file with a header and
 * no data rows is `rows: 0`") and must not double as "I do not know".
 *
 * ---------------------------------------------------------------------------
 * ABSENT FROM `files` IS NOT `rows: 0`
 * ---------------------------------------------------------------------------
 * The contract is explicit that these are different statements — "this shop has
 * no coupons" and "this export does not carry coupons" — so lists() and
 * rowsFor() are two different questions and both are asked.
 */
final class ImportManifest
{
    public const FILE = 'manifest.json';

    /** The only format this shop speaks. From the contract, verbatim. */
    public const FORMAT = 'kbb-export/1';

    /**
     * A manifest is a few hundred bytes of counts. Anything past this is not
     * one, and reading it into memory to find that out is the thing to avoid:
     * this file is parsed on the status endpoint, which the screen polls.
     */
    public const MAX_BYTES = 1024 * 1024;

    /**
     * How many contributing manifests `merged_from` remembers.
     *
     * Lane GK's export has eight groups, so this is every one of them with room
     * for corrections. See merge() for why it is bounded at all.
     */
    public const MAX_MERGED_FROM = 20;

    private function __construct(
        private readonly bool $present,
        private readonly ?string $refusal,
        /** @var array<string, mixed> */
        private readonly array $data,
    ) {}

    /** No manifest in this export. Not an error; most of this repo's fixtures are one. */
    public static function absent(): self
    {
        return new self(false, null, []);
    }

    /** Read the manifest sitting beside the CSVs, if there is one. */
    public static function read(string $path): self
    {
        if (! is_file($path)) {
            return self::absent();
        }

        clearstatcache(true, $path);

        if ((int) filesize($path) > self::MAX_BYTES) {
            return new self(true, 'manifest.json is '.ImportWorkspace::humanBytes((int) filesize($path))
                .', which is not a manifest — it is a list of counts and it should be a few kilobytes. '
                .'Remove it and import again, or replace it with the one the export plugin wrote.', []);
        }

        $body = @file_get_contents($path);

        return $body === false
            ? new self(true, 'manifest.json could not be read off the disk.', [])
            : self::parse($body);
    }

    public static function parse(string $body): self
    {
        // A byte-order mark makes json_decode() return null, and a BOM is what
        // Notepad and Excel leave on a file somebody opened to look at. The
        // manifest is not wrong; the editor was.
        $decoded = json_decode(ltrim($body, "\xEF\xBB\xBF \t\r\n"), true);

        if (! is_array($decoded) || array_is_list($decoded)) {
            return new self(true, 'manifest.json is not readable as JSON. It should be the file the export '
                .'plugin wrote, unedited — a text editor that saved it as something else, or a partial '
                .'download, both look like this. Remove it and the import will run without it.', []);
        }

        $format = $decoded['format'] ?? null;

        /*
         * THE FORMAT CHECK, and the sentence names what was found.
         *
         * A manifest this shop cannot read describes a file set it may not read
         * correctly either, which is why this is a refusal and not a shrug. The
         * escape hatch is deliberately the simplest one there is and is named in
         * the sentence: delete the manifest, and the import runs the way every
         * export before the plugin existed ran.
         */
        if (! is_string($format) || $format === '') {
            return new self(true, 'manifest.json does not say what format it is. This shop reads "'
                .self::FORMAT.'". Remove the manifest to import the CSV files without it.', []);
        }

        if ($format !== self::FORMAT) {
            return new self(true, 'manifest.json says its format is "'.self::clip($format).'" and this shop '
                .'reads "'.self::FORMAT.'". That is usually an export plugin newer than this shop — update '
                .'the shop, or remove the manifest to import the CSV files without it.', []);
        }

        return new self(true, null, $decoded);
    }

    public function present(): bool
    {
        return $this->present;
    }

    /**
     * The decoded manifest, exactly as it was read.
     *
     * For merge() and for nothing else. Everything a screen or an importer
     * wants is reached through an accessor above, which is where the contract's
     * rules about types and absent keys are applied; this returns the raw
     * document because merging two of them has to preserve keys this shop does
     * not read ("unknown keys are ignored, never fatal" — the contract).
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->data;
    }

    /**
     * `groups`, as Lane GK's export screen writes it.
     *
     * Not in the contract's own example and not required by it — it arrives
     * under the contract's "unknown keys are ignored, never fatal" clause, and
     * docs/GK-EXPORT-GROUPS.md §5 is the account of it. Every field is
     * defaulted, so a manifest with no `groups` at all reads as an export that
     * ticked nothing, which is what a pre-groups plugin's export IS.
     *
     * `assumed_already_imported` is the one entry here that is not a fact about
     * the export: it is the operator's claim, made on the WordPress screen,
     * that a prerequisite group is already in THIS shop — a claim GK is
     * explicit that the plugin did not and could not verify. It is carried
     * through unchanged so the import screen can print it at the one moment
     * somebody is looking at the shop the claim is about.
     *
     * @return array{selected: list<string>, skipped: list<string>, files: list<string>, assumed_already_imported: list<array<string, mixed>>}
     */
    public function groups(): array
    {
        $groups = $this->data['groups'] ?? null;
        $groups = is_array($groups) ? $groups : [];

        $strings = static function (mixed $v): array {
            if (! is_array($v)) {
                return [];
            }

            $out = [];

            foreach ($v as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $out[] = self::clip(trim($item), 64);
                }
            }

            return array_values(array_unique($out));
        };

        $claims = [];

        foreach (is_array($groups['assumed_already_imported'] ?? null) ? $groups['assumed_already_imported'] : [] as $claim) {
            if (! is_array($claim)) {
                continue;
            }

            $group = $claim['group'] ?? null;
            $needs = $claim['needs'] ?? null;

            if (! is_string($group) || ! is_string($needs) || trim($group) === '' || trim($needs) === '') {
                continue;
            }

            $severity = $claim['severity'] ?? null;
            $text = $claim['claim'] ?? null;

            $claims[] = [
                'group' => self::clip(trim($group), 64),
                'needs' => self::clip(trim($needs), 64),
                /*
                 * GK draws exactly one edge red and says why: a banner that
                 * shows up eight times is a banner nobody reads. An unrecognised
                 * severity is shown as the quieter one rather than guessed
                 * upward, so a newer plugin's ordinary export cannot turn this
                 * shop's one red warning into noise.
                 */
                'severity' => $severity === 'loses' ? 'loses' : 'reported',
                'claim' => is_string($text) && trim($text) !== '' ? self::clip(trim($text), 400) : null,
            ];
        }

        return [
            'selected' => $strings($groups['selected'] ?? null),
            'skipped' => $strings($groups['skipped'] ?? null),
            'files' => $strings($groups['files'] ?? null),
            'assumed_already_imported' => $claims,
        ];
    }

    /**
     * Do these two manifests describe the same export?
     *
     * `export_id` and nothing else. The contract makes it the export's
     * identity, and the whole point of Lane GL's group zips is that every zip
     * of one export carries the same one.
     *
     * A manifest with NO export id never shares: two anonymous manifests are
     * two unknowns, and treating two unknowns as equal would merge a January
     * export into a September one because neither said which it was.
     */
    public static function sameExport(self $a, self $b): bool
    {
        $left = $a->exportId();

        return $left !== null && $left === $b->exportId();
    }

    /**
     * Fold a second group's manifest into the one already here.
     *
     * ---------------------------------------------------------------------
     * WHY THIS EXISTS AT ALL
     * ---------------------------------------------------------------------
     * Lane GL ships one zip per group, each carrying its own manifest.json and
     * all of them sharing one `export_id`. Uploading Catalogue and then Orders
     * therefore delivers two manifests describing two halves of one export.
     *
     * Letting the second REPLACE the first is the obvious thing and it is
     * wrong, in a way quiet enough to survive a demo. The contract says, and
     * docs/GK-EXPORT-GROUPS.md §5 turns into a load-bearing rule:
     *
     *   "Absent from `files` means the plugin did not write it at all ... 'this
     *    shop has no coupons' and 'this export does not carry coupons' are not
     *    the same fact."
     *
     * After a replace, products.csv is sitting on the disk and is ABSENT from
     * the manifest beside it — so the shop now believes the export does not
     * carry products. Nothing 500s. The bar still draws, because
     * ImportDriver::denominator() falls back to the count it took off the file
     * itself. What is lost is the only thing that count cannot do: notice that
     * the file on this disk is half the file that was sent. The manifest's
     * whole contribution, in this class's own words at the top of this file, is
     * "a denominator that can be WRONG, and therefore one that can be checked"
     * — and a replace silently gives that up for every group but the last one
     * uploaded.
     *
     * ---------------------------------------------------------------------
     * THE RULES
     * ---------------------------------------------------------------------
     * Top-level scalars: FIRST WINS. `export_id` is equal by precondition.
     * `source` and `generated_at` describe the shop and the moment the export
     * began, and the first zip is the one that began it. A later zip's
     * `generated_at` is not discarded — it goes into `merged_from`, so the file
     * can still answer "when did each part of this arrive".
     *
     * `files` and `counts`: union, and where the two overlap **THE INCOMING
     * ENTRY WINS**. This is the opposite of the rule above and it is not an
     * inconsistency: an entry in `files` describes ONE FILE'S BYTES, and the
     * bytes on the disk are the ones that arrived with the manifest that
     * describes them. Getting this backwards is not a small error — Lane GF's
     * `it draws NO bar when the manifest and the file disagree` caught it
     * immediately, and what it caught was this: the owner finds something wrong
     * in WordPress, fixes it, and re-exports THAT GROUP under the same
     * `export_id`. Under first-wins the shop would keep the old row count,
     * compare it to the corrected file, and report the correction as a
     * truncated upload — telling him to upload again the one file that is
     * finally right. GF is explicit that a corrected re-export "is the import
     * he most needs to be able to run".
     *
     * Everything else: UNION. `groups.selected`, `groups.files` and
     * `groups.assumed_already_imported` all say "this export carries these as
     * well".
     *
     * `groups.skipped`: UNION, MINUS everything now selected — and the second
     * half is what makes the first half right.
     *
     * This was written as an INTERSECTION first, on the reasoning that the
     * Catalogue zip lists Orders as skipped and the Orders zip lists Catalogue
     * as skipped, so a union would claim an export carrying both carries
     * neither. Mutation testing turned the intersection into a union and every
     * test stayed green, which is how the reasoning turned out to be wrong in
     * two ways at once:
     *
     *   - it is REDUNDANT against Lane GK's exporter, which writes `skipped` as
     *     every group that was not selected. Union-minus-selected and
     *     intersection-minus-selected are then the same set for every possible
     *     input, because subtracting `selected` already removes exactly the
     *     groups the two lists disagree about. The intersection was doing
     *     nothing.
     *
     *   - and where they DO differ it is WRONG. A plugin that lists only the
     *     skips relevant to the group it is exporting — Catalogue says it left
     *     out Orders, Orders says it left out Reviews — has an intersection of
     *     nothing, so an export that genuinely carries no reviews would claim
     *     not to have skipped them. A group is missing from the whole export
     *     when NOBODY carried it, and union-minus-selected is that set.
     *
     * Unknown keys the incoming manifest brings are kept, and ones already here
     * are not overwritten, because the contract says a newer plugin's fields
     * must not stop an import and quietly dropping them is a smaller version of
     * stopping it.
     *
     * @return array<string, mixed>
     */
    public static function merge(self $existing, self $incoming): array
    {
        $a = $existing->raw();
        $b = $incoming->raw();

        // First wins for everything scalar, and every key the incoming manifest
        // brings that is not already here survives.
        $out = $a + $b;

        // INCOMING FIRST. PHP's `+` keeps the LEFT operand's keys, so this is
        // "the newest description of a file wins, and every file either of them
        // describes survives".
        $out['files'] = $incoming->files() + $existing->files();

        $counts = is_array($a['counts'] ?? null) ? $a['counts'] : [];
        $incomingCounts = is_array($b['counts'] ?? null) ? $b['counts'] : [];
        $out['counts'] = $incomingCounts + $counts;

        $left = $existing->groups();
        $right = $incoming->groups();

        $selected = array_values(array_unique([...$left['selected'], ...$right['selected']]));

        $out['groups'] = [
            'selected' => $selected,
            // Union, then minus what is selected. A group is missing from the
            // export as a whole when nobody carried it; the subtraction is what
            // stops each zip's "I left this out" from outvoting the other zip
            // that brought it.
            'skipped' => array_values(array_diff(
                array_unique([...$left['skipped'], ...$right['skipped']]),
                $selected,
            )),
            'files' => array_values(array_unique([...$left['files'], ...$right['files']])),
            'assumed_already_imported' => self::mergeClaims(
                $left['assumed_already_imported'],
                $right['assumed_already_imported'],
            ),
        ];

        /*
         * What this file is made of, so a manifest that is no longer any one
         * zip's says so. Nothing branches on it; it is here because a merged
         * file that looked like an original would be a file the owner could not
         * reconcile against what he downloaded.
         */
        $from = is_array($a['merged_from'] ?? null) ? $a['merged_from'] : [];

        if ($from === []) {
            $from = [['generated_at' => $existing->generatedAt(), 'files' => array_keys($existing->files())]];
        }

        $from[] = ['generated_at' => $incoming->generatedAt(), 'files' => array_keys($incoming->files())];

        /*
         * BOUNDED, and this is not tidiness.
         *
         * A merge rewrites the manifest, so this list grows by one on every
         * upload of a group belonging to this export — and read() refuses a
         * manifest over MAX_BYTES with "this is not a manifest, remove it". An
         * owner who re-uploaded one group often enough would eventually brick
         * his own manifest with this shop's own bookkeeping, which is the worst
         * shape a record-keeping field can have. Eight groups is the most an
         * export has, so twenty is every group with room for corrections, and
         * the OLDEST go first because the newest are the ones still being
         * reconciled against a download.
         */
        $out['merged_from'] = array_values(array_slice($from, -self::MAX_MERGED_FROM));

        return $out;
    }

    /**
     * One claim per (group, needs) pair.
     *
     * Deduplicated because both zips of one export carry the whole claim list
     * in GK's design rather than only their own; kept rather than dropped when
     * the group it names has since been uploaded, because it is a record of
     * what the operator clicked and that does not stop having been true.
     *
     * @param  list<array<string, mixed>>  $left
     * @param  list<array<string, mixed>>  $right
     * @return list<array<string, mixed>>
     */
    private static function mergeClaims(array $left, array $right): array
    {
        $out = [];

        foreach ([...$left, ...$right] as $claim) {
            $key = ($claim['group'] ?? '').'|'.($claim['needs'] ?? '');

            if (! isset($out[$key])) {
                $out[$key] = $claim;

                continue;
            }

            // Same pair, two severities: the louder one stays. GK draws exactly
            // one edge red and the point of it is that red means something, so
            // a duplicate must not be able to quieten it down.
            if (($claim['severity'] ?? '') === 'loses') {
                $out[$key] = $claim;
            }
        }

        return array_values($out);
    }

    /** The sentence to show instead of importing, or null when the manifest is usable. */
    public function refusal(): ?string
    {
        return $this->refusal;
    }

    public function usable(): bool
    {
        return $this->present && $this->refusal === null;
    }

    /**
     * The export's identity.
     *
     * Null when the manifest does not carry one, which the contract does not
     * allow but a hand-written manifest will do anyway. Everything downstream
     * treats a null export id as "this export cannot be recognised", which is
     * the same state as no manifest and is already handled.
     */
    public function exportId(): ?string
    {
        $id = $this->data['export_id'] ?? null;

        return is_string($id) && trim($id) !== '' ? self::clip(trim($id), 64) : null;
    }

    public function generatedAt(): ?string
    {
        $at = $this->data['generated_at'] ?? null;

        return is_string($at) && trim($at) !== '' ? self::clip(trim($at), 40) : null;
    }

    /**
     * Where the export was taken from.
     *
     * Kept as strings and never parsed into anything. It is printed back to the
     * owner months later to answer "which shop is this data from"; nothing
     * branches on it.
     *
     * @return array{site_url: ?string, wp_version: ?string, woo_version: ?string, plugin_version: ?string}
     */
    public function source(): array
    {
        $source = $this->data['source'] ?? null;
        $source = is_array($source) ? $source : [];

        $get = static function (mixed $v): ?string {
            return is_string($v) && trim($v) !== '' ? self::clip(trim($v), 255) : null;
        };

        return [
            'site_url' => $get($source['site_url'] ?? null),
            'wp_version' => $get($source['wp_version'] ?? null),
            'woo_version' => $get($source['woo_version'] ?? null),
            'plugin_version' => $get($source['plugin_version'] ?? null),
        ];
    }

    /** @return array<string, array{rows: ?int, bytes: ?int, sha256: ?string}> */
    public function files(): array
    {
        $files = $this->data['files'] ?? null;

        if (! is_array($files)) {
            return [];
        }

        $out = [];

        foreach ($files as $name => $entry) {
            if (! is_string($name) || $name === '') {
                continue;
            }

            $entry = is_array($entry) ? $entry : [];

            $out[$name] = [
                'rows' => self::wholeNumber($entry['rows'] ?? null),
                'bytes' => self::wholeNumber($entry['bytes'] ?? null),
                'sha256' => self::digest($entry['sha256'] ?? null),
            ];
        }

        return $out;
    }

    /** Is this file named in `files` at all? Absent and `rows: 0` are different facts. */
    public function lists(string $file): bool
    {
        return array_key_exists($file, $this->files());
    }

    /** The stated row count, header excluded. Null when unstated or unreadable — never 0 for that. */
    public function rowsFor(string $file): ?int
    {
        return $this->files()[$file]['rows'] ?? null;
    }

    public function sha256For(string $file): ?string
    {
        return $this->files()[$file]['sha256'] ?? null;
    }

    public function bytesFor(string $file): ?int
    {
        return $this->files()[$file]['bytes'] ?? null;
    }

    /**
     * Everything worth keeping with the run, so that replacing manifest.json
     * half way through cannot rewrite what the history says was imported.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'export_id' => $this->exportId(),
            'generated_at' => $this->generatedAt(),
            'source' => $this->source(),
            'files' => $this->files(),
        ];
    }

    /** Rebuild from a snapshot() stored on a run row. */
    public static function fromSnapshot(?array $snapshot): self
    {
        if ($snapshot === null || $snapshot === []) {
            return self::absent();
        }

        return new self(true, null, [
            'format' => self::FORMAT,
            'export_id' => $snapshot['export_id'] ?? null,
            'generated_at' => $snapshot['generated_at'] ?? null,
            'source' => $snapshot['source'] ?? [],
            'files' => $snapshot['files'] ?? [],
        ]);
    }

    /**
     * How the export describes itself, in one line for a screen.
     *
     * Deliberately says the site and the date and not the id: the id is a UUID
     * and the owner has never seen it before. The id is still in the payload
     * for anything that needs to match on it.
     */
    public function label(): string
    {
        if (! $this->usable()) {
            return 'no manifest';
        }

        $source = $this->source();
        $site = $source['site_url'] ?? 'an unnamed site';
        $when = $this->generatedAt();

        return $when === null ? 'an export of '.$site : 'an export of '.$site.' taken '.$when;
    }

    private static function wholeNumber(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        // A JSON number that arrived as a float or a numeric string still says
        // a count, as long as it IS one. "671.5" and "many" do not.
        if (is_float($value) && $value >= 0 && floor($value) === $value) {
            return (int) $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private static function digest(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/i', $value) === 1
            ? strtolower($value)
            : null;
    }

    /**
     * Manifest values are printed back into sentences on an admin page, and a
     * manifest is a third-party file. Length is bounded here; the escaping is
     * the view's job and the view does it.
     */
    private static function clip(string $value, int $length = 60): string
    {
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length).'…' : $value;
    }
}
