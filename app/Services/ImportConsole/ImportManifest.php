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
