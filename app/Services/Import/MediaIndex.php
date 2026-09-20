<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Support\MediaUsage;

/**
 * `media.csv` — what the OLD site said about its own uploads folder, on the day
 * the export was taken.
 *
 * =============================================================================
 * WHY THIS FILE IS WORTH READING AT ALL, WHICH IS ONE COLUMN
 * =============================================================================
 *
 * `MediaAudit` already answers "is this picture on THIS shop's disk" and
 * `MediaSideloader` already fetches the ones that are not, and both derive
 * their work from the catalogue's own image columns rather than from any file.
 * So most of `media.csv` is a second opinion about things this shop can see for
 * itself, and a second opinion is not a reason to read a file.
 *
 * One column is not a second opinion. `docs/GE-WP-EXPORTER.md` §6:
 *
 *     `exists` is a stat() per row, so a file the media library names and the
 *     disk does not have is found NOW, while the old site is still up, rather
 *     than by MediaAudit afterwards.
 *
 * That is a fact about a machine this shop cannot reach, and nothing here can
 * derive it. It matters because of what the migration does next: the owner
 * copies `wp-content/uploads` across by FTP and then presses "Bring these
 * across", or leaves the sideloader fetching. A row whose file was ALREADY GONE
 * on WordPress will never come across by either route. Without this column the
 * owner watches `remote` refuse to reach zero and has no way to tell "not
 * copied yet" from "there is nothing to copy", which is the difference between
 * waiting and re-photographing a product.
 *
 * =============================================================================
 * THE KEY IS THE UPLOADS-RELATIVE PATH, AND NOT THE URL
 * =============================================================================
 *
 * The catalogue's rows and this file's rows are the same pictures spelled
 * differently, and they get spelled differently again as the migration
 * proceeds: `https://kbeautybliss.com/wp-content/uploads/2019/03/x.jpg` in the
 * export, the same string in `products.image` after the import, and
 * `/kbb-upgrade/wp-content/uploads/2019/03/x.jpg` after `MediaRewrite::apply()`
 * has re-pointed it. All three are one photograph.
 *
 * So the key cuts at the UPLOADS ROOT, which is the one part that does not
 * move — the rule `MediaRewrite::uploadsRelative()` follows, for the reason it
 * gives: cutting at whatever `Url::base()` returns today does not survive the
 * shop changing folder, and cutting at the host does not survive the rewrite.
 *
 * NOTHING HERE WRITES. This index answers questions; `MediaRewrite` and
 * `MediaSideloader` are what change rows, and neither of them consults it. A
 * file the owner uploaded cannot, by construction, cause a picture to be
 * re-pointed or deleted.
 */
final class MediaIndex
{
    /** @var array<string, array{exists: bool, bytes: int, size: string}> */
    private array $rows = [];

    private int $read = 0;

    private int $usable = 0;

    /**
     * @param  iterable<int, array<string, string>>  $rows  `media.csv` as CSV rows
     */
    public function __construct(iterable $rows = [])
    {
        foreach ($rows as $row) {
            $this->read++;

            $key = self::key((string) ($row['url'] ?? $row['path'] ?? ''));

            if ($key === null) {
                continue;
            }

            $this->usable++;

            /*
             * LAST ROW WINS, and it has to be a decision rather than an
             * accident. GE §6 writes one row per (url, referrer, field) pair,
             * so one photograph on ten products is ten rows — every one of
             * them carrying the same `exists`, because it is a stat() of one
             * file. Where they ever disagree, the later row is the later
             * stat().
             */
            $this->rows[$key] = [
                'exists' => self::truthy((string) ($row['exists'] ?? '')),
                'bytes' => (int) trim((string) ($row['bytes'] ?? '0')),
                'size' => trim((string) ($row['size'] ?? '')),
            ];
        }
    }

    /** Rows in the file, whether or not this could use them. */
    public function read(): int
    {
        return $this->read;
    }

    /** Distinct pictures this index can answer about. */
    public function pictures(): int
    {
        return count($this->rows);
    }

    /** Rows carrying an address this could key on. */
    public function usable(): int
    {
        return $this->usable;
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    /**
     * Did the OLD site still have this file when the export was taken?
     *
     * Three answers, not two, and the third is the important one: `null` means
     * "this file says nothing about that picture", which is not the same as
     * "that picture was gone". A caller that folded the two together would
     * report every photograph added since the export as missing at source.
     */
    public function existedAtSource(string $url): ?bool
    {
        $key = self::key($url);

        if ($key === null || ! isset($this->rows[$key])) {
            return null;
        }

        return $this->rows[$key]['exists'];
    }

    /**
     * The pictures this file says the old site had already lost.
     *
     * @return list<string>
     */
    public function goneAtSource(): array
    {
        $out = [];

        foreach ($this->rows as $key => $row) {
            if (! $row['exists']) {
                $out[] = $key;
            }
        }

        sort($out);

        return $out;
    }

    /**
     * The uploads-relative path of an address, or null when it is under neither
     * upload root — a supplier's photograph, a CDN, something an admin typed.
     * Skipped rather than "corrected", the same rule `MediaRewrite` follows.
     */
    public static function key(string $raw): ?string
    {
        $path = ltrim(MediaUsage::normalise($raw), '/');

        if ($path === '') {
            return null;
        }

        foreach (['wp-content/uploads/', 'uploads/'] as $root) {
            $at = strpos($path, $root);

            if ($at !== false) {
                return mb_strtolower(substr($path, $at));
            }
        }

        return null;
    }

    /**
     * The exporter writes `1`/`0`; a spreadsheet round trip writes `TRUE`, and
     * a hand-edited file writes `yes`. An unrecognised value reads as EXISTS,
     * because the only thing this index is used for is telling the owner a
     * picture will never arrive, and saying that wrongly sends him to
     * re-photograph a product he already has.
     */
    private static function truthy(string $raw): bool
    {
        $raw = mb_strtolower(trim($raw));

        return ! in_array($raw, ['0', 'false', 'no', 'n', 'missing', 'absent'], true);
    }
}
