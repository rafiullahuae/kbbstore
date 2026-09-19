<?php

declare(strict_types=1);

namespace App\Services\ImportConsole;

use ZipArchive;

/**
 * One group's export, as a zip, unpacked into a scratch directory — and every
 * reason a zip is refused instead.
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS
 * ---------------------------------------------------------------------------
 * The owner asked for it in one sentence: *"allow to download each group
 * seperate files. so will have no any heavy file."* Lane GK split the WordPress
 * export into eight named groups; Lane GL makes each group a zip of its own so
 * he fetches Catalogue on Monday and Orders on Tuesday instead of one folder
 * over FTP. This class is the other end of that: Store → Import accepts the
 * zips, so he never unzips anything by hand — which was the entire point.
 *
 * ---------------------------------------------------------------------------
 * THE ONE INVARIANT EVERYTHING ELSE IS DERIVED FROM
 * ---------------------------------------------------------------------------
 * **A zip must behave exactly as if the owner had unzipped it and uploaded its
 * files loose.** Not "nearly": identically. Every file that comes out of a zip
 * goes through ImportWorkspace::accept() — the same CSV parse, the same id
 * column check, the same manifest reader, the same refusal sentences — and a
 * file the loose path would refuse is refused here in the same words.
 *
 * That invariant is why this class does NOT import, does not write into the
 * workspace, and does not know what an entity is. It answers one question —
 * *which byte streams may leave this archive* — and hands them over.
 *
 * ---------------------------------------------------------------------------
 * THE ARCHIVE IS THE ATTACK SURFACE. A zip is worse than an upload.
 * ---------------------------------------------------------------------------
 * A loose upload carries one name this code already discards (ImportWorkspace's
 * rule 2). A zip carries an ARBITRARY NUMBER of names, each of which the
 * archive gets to choose, plus a declared size per entry that it also gets to
 * choose, plus a unix mode. So the rules are listed rather than implied, in the
 * order they are applied, and each is a separate refusal with a sentence of its
 * own so that no guard is ever standing behind another one doing nothing:
 *
 *  1. THE ARCHIVE MUST OPEN, and ZipArchive's own consistency check runs
 *     (ZipArchive::CHECKCONS). A truncated or doctored central directory is a
 *     refusal, not something to work around.
 *
 *  2. NO NAME MAY SAY WHERE. `..`, `.`, an empty segment (which is what a
 *     leading `/` in an absolute path produces), a backslash, a NUL and a drive
 *     letter are each refused BY NAME, before anything is resolved and before
 *     the allowlist is consulted. This guard is deliberately not left to the
 *     allowlist below: `../../../../products.csv` has a perfectly allowed
 *     basename, and an archive that tries it must be refused rather than
 *     quietly sanitised into working. Silently repairing a hostile name is how
 *     the next reader concludes the check is unnecessary.
 *
 *     AND NOTHING ELSE IS FATAL HERE. Refusing an archive because it holds a
 *     `.DS_Store`, a `__MACOSX/` folder or an `[Content_Types].xml` would be
 *     refusing an ordinary zip made on a Mac, and would answer a spreadsheet
 *     with a sentence about file names rather than the one naming it as a
 *     spreadsheet. Fatal is for a name that says WHERE; the allowlist is for a
 *     name that says WHAT, and those are two different questions.
 *
 *  3. AT MOST ONE WRAPPING DIRECTORY, shared by every entry. Lane GL had not
 *     published the zip's exact shape when this was written, so both shapes
 *     are read: `products.csv` at the root and `kbb-export-catalogue/products.csv`
 *     under one folder. Two levels is refused with a sentence naming what was
 *     found, because at that point the shape is not one this side guessed
 *     wrong about — it is a shape nobody agreed to.
 *
 *  4. ONLY CSV AND manifest.json MAY BE WRITTEN AT ALL. An allowlist, not a
 *     blocklist: a `.php`, a `.htaccess`, a `.env`, a nested `.zip` and an
 *     `index.html` are not refused because they are on a list of dangerous
 *     things, they are refused because they are not on the list of two things
 *     an export is made of. The blocklist version of this rule is wrong the
 *     day somebody invents a new extension; this one is not.
 *
 *  5. A DESTINATION IS CLAIMED ONCE. `products.csv` and `wrapper/products.csv`
 *     in one archive are two different entry names landing on one file, and
 *     letting the second overwrite the first would mean the file that gets
 *     imported is not the file that was checked. (Two entries with the IDENTICAL
 *     name never reach this guard — libzip's own consistency check under
 *     CHECKCONS refuses the archive at open. The collision that IS reachable is
 *     the one across the wrapping directory, and that is what the test uses.)
 *
 *  6. THE SIZE CAP IS COUNTED FROM BYTES ACTUALLY READ, not from the size the
 *     archive declares. The declared size is checked too — it is free and it
 *     refuses a bomb before a byte is written — but it is a number the attacker
 *     wrote, so it cannot be the guard. The stream is read in chunks with a
 *     running total and abandoned mid-entry the moment the total passes the
 *     cap, which is the difference between refusing a 4GB expansion and being
 *     killed by it.
 *
 *  7. ENTRIES ARE COUNTED, and the count is checked before the loop rather
 *     than during it, because a zip with 200,000 tiny entries costs its damage
 *     in syscalls and inodes rather than in bytes.
 *
 *  8. SYMLINK ENTRIES ARE REFUSED BY NAME. A zip entry can carry a unix mode
 *     in its external attributes saying "this is a symlink", and its content is
 *     then the link target. PHP's extractTo() happens to write such an entry as
 *     an ordinary file — but this class does not rely on that, because the
 *     property being relied on would be an implementation detail of a C library
 *     rather than a decision made here. A symlink escapes a directory without a
 *     `..` anywhere in it, which is exactly the shape guard 2 cannot see.
 *
 * Nothing is extracted to the workspace. Everything lands in a scratch
 * directory created for this upload and removed in a `finally` — so a refusal
 * half way through leaves nothing behind, and a file that fails
 * ImportWorkspace::accept() never existed anywhere the import can see.
 */
final class ImportArchive
{
    /**
     * Total uncompressed bytes allowed out of one archive.
     *
     * Deliberately equal to the per-file upload cap rather than a multiple of
     * it. A group zip is a handful of CSVs; the biggest single thing this shop
     * accepts loose is 64MB, and an archive is not a reason to raise that.
     */
    public const MAX_TOTAL_BYTES = ImportWorkspace::MAX_BYTES;

    /** Per entry, so one enormous member cannot eat the whole budget alone. */
    public const MAX_ENTRY_BYTES = ImportWorkspace::MAX_BYTES;

    /**
     * How many members an export zip may hold.
     *
     * docs/WP-EXPORT-CONTRACT.md lists seventeen CSVs and a manifest.json, and
     * the largest of Lane GK's eight groups is six files; forty is the whole
     * contract twice over with room for the `__MACOSX/` folder and the
     * `.DS_Store` a Mac adds. It is a LITERAL and not derived from
     * ImportWorkspace::entities(), because the contract's seven gap files have
     * no entity to be counted and a cap derived from the wrong list is a cap
     * that shrinks when somebody removes an importer — which is the same shape
     * as the `max:6` bug ImportApiController's comment records, arrived at from
     * the other direction.
     */
    public const MAX_ENTRIES = 40;

    /** Read in blocks so the running total can stop an entry part way through. */
    private const CHUNK = 65536;

    /**
     * Is this upload a zip at all?
     *
     * The magic number, never the extension and never the Content-Type the
     * browser volunteered — both of those are things the caller chose. `PK\x03\x04`
     * is a local file header; an empty archive starts `PK\x05\x06` and is
     * recognised here so that it is refused as an empty export rather than as
     * "not a CSV".
     */
    public static function looksLikeZip(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $head = (string) fread($handle, 4);
        fclose($handle);

        return $head === "PK\x03\x04" || $head === "PK\x05\x06";
    }

    /**
     * Unpack one archive into a scratch directory.
     *
     * @return array{dir: string, files: list<array{name: string, path: string}>}
     *
     * @throws ImportUploadRejected
     */
    public function unpack(string $zipPath, string $scratchRoot): array
    {
        $zip = new ZipArchive;

        // CHECKCONS makes libzip verify the central directory against the local
        // headers. A doctored archive that disagrees with itself is refused
        // here rather than surprising the reader half way through.
        $opened = $zip->open($zipPath, ZipArchive::RDONLY | ZipArchive::CHECKCONS);

        if ($opened !== true) {
            throw new ImportUploadRejected(
                'That zip could not be opened ('.self::zipError($opened).'). Download it from the export '
                .'screen again — a transfer that stopped part way through looks exactly like this.'
            );
        }

        try {
            return $this->readEntries($zip, $scratchRoot);
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array{dir: string, files: list<array{name: string, path: string}>}
     *
     * @throws ImportUploadRejected
     */
    private function readEntries(ZipArchive $zip, string $scratchRoot): array
    {
        $count = $zip->count();

        if ($count === 0) {
            throw new ImportUploadRejected('That zip is empty — there are no files inside it.');
        }

        /*
         * GUARD 7, and it is BEFORE the loop on purpose. A zip whose damage is
         * 200,000 empty members costs nothing in bytes and everything in
         * syscalls, so counting them after extracting them would be counting
         * them after paying for them.
         */
        if ($count > self::MAX_ENTRIES) {
            throw new ImportUploadRejected(
                'That zip holds '.number_format($count).' files. An export zip holds at most '
                .self::MAX_ENTRIES.' — one group is a few CSVs and a manifest.json. This is not an export '
                .'from the WordPress plugin.'
            );
        }

        $plan = [];
        $declared = 0;
        $skipped = [];

        for ($i = 0; $i < $count; $i++) {
            $raw = $zip->getNameIndex($i);

            if ($raw === false) {
                throw new ImportUploadRejected('That zip has an entry whose name could not be read. It is damaged; download it again.');
            }

            /*
             * A directory member. Nothing is created from it — the scratch
             * directory is flat, and the wrapper check reads the FILE entries'
             * own first segment — so it is checked for a hostile name and then
             * dropped. It is still checked: `../` is a directory member and is
             * a traversal whether or not anything is written from it.
             */
            if (str_ends_with($raw, '/')) {
                $this->refuseUnsafeName($raw, rtrim($raw, '/'));

                continue;
            }

            $segments = $this->refuseUnsafeName($raw, $raw);

            /*
             * GUARD 8. The unix mode lives in the top 16 bits of the external
             * attributes when the archive was made on a unix host, and 0xA000
             * is S_IFLNK. A symlink entry's content is its target, so nothing
             * about the bytes gives it away — this is the only place it can be
             * seen.
             */
            $attributes = [];

            if ($zip->getExternalAttributesIndex($i, $attributes['opsys'], $attributes['attr'])) {
                $mode = ((int) $attributes['attr']) >> 16;

                if (($mode & 0xF000) === 0xA000) {
                    throw new ImportUploadRejected(
                        'That zip contains a symbolic link ("'.self::clip($raw).'"). An export is files, not '
                        .'links — a link points at something outside the export and this shop will not follow '
                        .'one. Re-create the zip from the export screen.'
                    );
                }
            }

            $stat = $zip->statIndex($i);
            $size = is_array($stat) ? (int) ($stat['size'] ?? 0) : 0;

            /*
             * GUARD 6, cheap half. This number is the archive's own claim and
             * is checked again against bytes actually read in extract(); a bomb
             * that lies about its size is caught there instead. Refusing on the
             * claim first means the honest bomb — the common one, written by a
             * tool that had no reason to lie — costs nothing to refuse.
             */
            if ($size > self::MAX_ENTRY_BYTES) {
                throw new ImportUploadRejected(
                    'Inside that zip, "'.self::clip($raw).'" unpacks to '.ImportWorkspace::humanBytes($size)
                    .'. The limit is '.ImportWorkspace::humanBytes(self::MAX_ENTRY_BYTES).' per file. Export '
                    .'that group in pieces — the importer resumes, so several smaller files reach the same '
                    .'result as one large one.'
                );
            }

            $declared += $size;

            if ($declared > self::MAX_TOTAL_BYTES) {
                throw new ImportUploadRejected(
                    'That zip unpacks to more than '.ImportWorkspace::humanBytes(self::MAX_TOTAL_BYTES)
                    .', which is more than this shop accepts in one upload. Upload one group at a time.'
                );
            }

            $name = $segments[count($segments) - 1];

            /*
             * GUARD 4. Two names and no others. Anything else is not written,
             * not to the workspace and not even to the scratch directory, so a
             * .php inside an export never exists as a file on this server at
             * any point.
             *
             * It is remembered rather than dropped: a zip that contained
             * nothing else has to be able to say what it DID contain, or the
             * owner who uploaded a spreadsheet gets "this zip has no export
             * files in it" and no idea why.
             */
            if (! self::isAllowedName($name)) {
                $skipped[] = $raw;

                continue;
            }

            if (isset($plan[$name])) {
                throw new ImportUploadRejected(
                    'That zip contains "'.self::clip($name).'" twice. Which of the two is the export is not '
                    .'something this shop can guess, so neither is imported. Re-create the zip from the '
                    .'export screen.'
                );
            }

            $plan[$name] = ['index' => $i, 'raw' => $raw, 'segments' => $segments];
        }

        $this->refuseTwoWrappers($plan);

        if ($plan === []) {
            throw new ImportUploadRejected($this->nothingUsable($skipped));
        }

        return $this->extract($zip, $plan, $scratchRoot);
    }

    /**
     * GUARD 2 and GUARD 3's first half — the name, before anything is resolved.
     *
     * @return list<string> the path's segments, all of them plain names
     *
     * @throws ImportUploadRejected
     */
    private function refuseUnsafeName(string $raw, string $subject): array
    {
        if ($subject === '' || str_contains($raw, "\0")) {
            throw new ImportUploadRejected(
                'That zip contains an entry with an unusable name. It was not written by the export plugin.'
            );
        }

        /*
         * A backslash is a separator on the host that wrote the archive even
         * when it is not one here, so `..\..\.env` is a traversal that a check
         * splitting on `/` alone would wave through. It is refused rather than
         * translated: an export plugin has no reason to write one.
         */
        if (str_contains($subject, '\\')) {
            throw new ImportUploadRejected(
                'That zip contains an entry whose name has a backslash in it ("'.self::clip($raw).'"). '
                .'An export writes plain file names. This zip was not written by the export plugin.'
            );
        }

        if (preg_match('#^[A-Za-z]:#', $subject) === 1) {
            throw new ImportUploadRejected(
                'That zip contains an entry with an absolute path ("'.self::clip($raw).'"). An export zip '
                .'holds file names, not locations on somebody else\'s disk, and nothing in it is allowed to '
                .'say where on this server it should land.'
            );
        }

        $segments = explode('/', $subject);

        foreach ($segments as $segment) {
            /*
             * The whole of guard 2 is these four lines, and they are the reason
             * the destination below can be built at all.
             *
             * An empty segment is a leading `/` (an absolute path) or a `//`.
             * `..` is the classic escape. `.` is the same escape wearing a hat.
             * Everything else must be an ordinary file name.
             *
             * NOTE: this is NOT covered by the allowlist further down, and a
             * test exists for exactly that gap. `../../../../products.csv`
             * has the basename `products.csv`, which the allowlist is perfectly
             * happy with — so removing these lines would let a traversal
             * through and no other guard would notice.
             */
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new ImportUploadRejected(
                    'That zip contains an entry that tries to write outside the import folder ("'
                    .self::clip($raw).'"). Nothing in an export names a location; this zip was built to put '
                    .'a file somewhere it was not invited. It has not been unpacked.'
                );
            }

            /*
             * AND NOTHING MORE IS FATAL HERE. A name that is merely not an
             * export file — `.htaccess`, `.DS_Store`, `[Content_Types].xml`,
             * the `__MACOSX/` folder every zip made on a Mac carries — is not
             * an attack and must not refuse the whole archive; it is skipped by
             * the allowlist below, which is where "this is not part of an
             * export" belongs.
             *
             * That distinction was NOT obvious and the first version of this
             * class got it wrong in both directions at once: it refused a
             * perfectly ordinary Mac-made zip outright, and it refused a
             * spreadsheet with a sentence about file names instead of the
             * sentence naming it as a spreadsheet that ImportWorkspace's rule 4
             * exists to give. Fatal is for names that try to say WHERE; the
             * allowlist is for names that say WHAT.
             */
        }

        if (count($segments) > 2) {
            throw new ImportUploadRejected(
                'Inside that zip, "'.self::clip($raw).'" sits '.count($segments).' folders deep. An export '
                .'zip holds its files at the top, or inside one folder — not nested. This is not a zip from '
                .'the export screen.'
            );
        }

        return $segments;
    }

    /**
     * GUARD 3's second half: one wrapping directory means ONE.
     *
     * Two files under two different folders is not a wrapped export, it is two
     * exports in one zip — and choosing which one to import is not a decision
     * this code may make silently.
     *
     * @param  array<string, array{index: int, raw: string, segments: list<string>}>  $plan
     *
     * @throws ImportUploadRejected
     */
    private function refuseTwoWrappers(array $plan): void
    {
        $wrappers = [];

        foreach ($plan as $entry) {
            $wrappers[count($entry['segments']) === 2 ? $entry['segments'][0] : ''] = true;
        }

        if (count($wrappers) > 1) {
            $named = array_values(array_filter(array_keys($wrappers), static fn (string $w): bool => $w !== ''));
            sort($named);

            throw new ImportUploadRejected(
                'That zip holds export files in more than one place ('
                .implode(', ', array_map(static fn (string $w): string => '"'.self::clip($w).'/"', $named))
                .(isset($wrappers['']) ? ' and the top level' : '')
                .'). An export zip is one group: its files at the top, or all inside one folder. Upload the '
                .'groups one at a time.'
            );
        }
    }

    /**
     * Stream every planned entry out, counting bytes as they actually arrive.
     *
     * @param  array<string, array{index: int, raw: string, segments: list<string>}>  $plan
     * @return array{dir: string, files: list<array{name: string, path: string}>}
     *
     * @throws ImportUploadRejected
     */
    private function extract(ZipArchive $zip, array $plan, string $scratchRoot): array
    {
        $dir = $this->scratch($scratchRoot);
        $real = realpath($dir);

        if ($real === false) {
            throw new ImportUploadRejected('The server could not make room to unpack that zip.');
        }

        $files = [];
        $total = 0;

        try {
            foreach ($plan as $name => $entry) {
                /*
                 * WHERE THE RESOLVED PATH IS CHECKED, AND WHY THERE IS NO `if`
                 * HERE ANY MORE.
                 *
                 * There was one. It re-derived dirname($destination) and
                 * compared it to the scratch root, as the last line of defence
                 * behind the name check in refuseUnsafeName(). Mutation testing
                 * deleted it and the whole suite stayed GREEN — not because the
                 * suite was thin, but because the check could not fail: $name
                 * is a single validated segment by the time it arrives here, so
                 * comparing its dirname to the directory it was just joined to
                 * is asking whether concatenation works.
                 *
                 * It is removed rather than left in with a comment saying it is
                 * belt and braces. This repository has paid for a guard that
                 * stood behind another one doing nothing twice already —
                 * Api\ProductController's status filter, which hid a 500 for
                 * months, and Lane GF's own second `mismatch === null` in
                 * ImportDriver::status(), which that lane removed for exactly
                 * this reason. Dead text is worse than no text, because the next
                 * reader counts it as protection.
                 *
                 * The resolved path IS checked, and it is checked by
                 * CONSTRUCTION, which is stronger than checking it afterwards:
                 * $real is realpath() of the scratch directory, so any symlink
                 * in the path to it is already resolved; $name is one segment
                 * that cannot be `.`, `..`, empty, or contain a separator; and
                 * the destination is exactly those two joined. There is no
                 * input that produces a path outside $real, which is why no
                 * test can make an `if` here go red.
                 *
                 * Note that refusing `..` OUTRIGHT — rather than resolving it
                 * and then checking where it landed — is the stronger of the
                 * two designs, and this is the reason to prefer it: a
                 * sanitising unpacker has to get the resolution exactly right
                 * on every platform, and a refusing one only has to recognise
                 * two characters.
                 */
                $destination = $real.'/'.$name;

                $total = $this->stream($zip, $entry['index'], $entry['raw'], $destination, $total);

                $files[] = ['name' => $name, 'path' => $destination];
            }
        } catch (\Throwable $e) {
            // A refusal half way through leaves nothing behind. The workspace
            // has not been touched at all at this point — accept() runs after
            // this returns — so there is nothing to undo but the scratch.
            self::purge($dir);

            throw $e;
        }

        return ['dir' => $dir, 'files' => $files];
    }

    /**
     * One entry, in chunks, with the running total that makes the cap real.
     *
     * @return int the new running total
     *
     * @throws ImportUploadRejected
     */
    private function stream(ZipArchive $zip, int $index, string $raw, string $destination, int $total): int
    {
        $in = $zip->getStream($zip->getNameIndex($index) ?: '');

        if ($in === false) {
            throw new ImportUploadRejected(
                'Inside that zip, "'.self::clip($raw).'" could not be read. The zip is damaged; download it '
                .'from the export screen again.'
            );
        }

        $out = @fopen($destination, 'wb');

        if ($out === false) {
            fclose($in);

            throw new ImportUploadRejected('The server could not write the files out of that zip.');
        }

        $written = 0;

        try {
            while (! feof($in)) {
                $chunk = fread($in, self::CHUNK);

                if ($chunk === false) {
                    throw new ImportUploadRejected(
                        'Inside that zip, "'.self::clip($raw).'" could not be read to the end. Download it '
                        .'from the export screen again.'
                    );
                }

                $written += strlen($chunk);
                $total += strlen($chunk);

                /*
                 * GUARD 6, THE HALF THAT CANNOT BE LIED TO.
                 *
                 * The declared size was checked in readEntries(); this is the
                 * same check against bytes that actually arrived, and it is the
                 * one that matters, because the declared size is a field the
                 * archive's author wrote. The test for this ships a zip whose
                 * header understates its contents by three orders of magnitude.
                 *
                 * Note that this aborts INSIDE the entry rather than after it.
                 * A cap applied to the finished file is not a cap, it is a
                 * report on the damage.
                 */
                if ($written > self::MAX_ENTRY_BYTES || $total > self::MAX_TOTAL_BYTES) {
                    throw new ImportUploadRejected(
                        'That zip unpacks to far more than it claims — "'.self::clip($raw).'" is still '
                        .'going past '.ImportWorkspace::humanBytes(self::MAX_TOTAL_BYTES).'. A file that '
                        .'expands like that is not an export. Nothing has been unpacked.'
                    );
                }

                if ($chunk !== '' && fwrite($out, $chunk) === false) {
                    throw new ImportUploadRejected('The server ran out of room unpacking that zip.');
                }
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        return $total;
    }

    /**
     * The two names an export is made of.
     *
     * An allowlist. `.php`, `.htaccess`, `.env`, `.zip` and everything else are
     * refused by not being one of these, rather than by being on a list of
     * things somebody thought of.
     */
    public static function isAllowedName(string $name): bool
    {
        if ($name === ImportManifest::FILE) {
            return true;
        }

        /*
         * Lowercase `.csv` only, and one dot before it. `products.csv.php` has
         * two extensions and is not a CSV; `products.CSV` is refused as well,
         * deliberately — the plugin writes lowercase names, the contract lists
         * lowercase names, and a shop that accepts either would be accepting a
         * file the contract cannot describe. The loose-upload path is unchanged
         * and still takes any name, so nothing the owner can do by hand is lost.
         */
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*\.csv$/', $name) === 1;
    }

    /**
     * The sentence for a zip with nothing in it this shop can use.
     *
     * A spreadsheet is a zip, and so is a .docx, and the owner will try both.
     * "There are no export files in this zip" is true and useless; naming what
     * it actually is, is ImportWorkspace's rule 4 applied to archives.
     *
     * @param  list<string>  $skipped
     */
    private function nothingUsable(array $skipped): string
    {
        foreach ($skipped as $entry) {
            if ($entry === '[Content_Types].xml' || str_starts_with($entry, 'xl/') || str_starts_with($entry, 'word/')) {
                return 'That is a spreadsheet or a Word document, not an export zip. In Excel or Google '
                    .'Sheets choose "Save as" / "Download" and pick CSV, then upload that — or download a '
                    .'group zip from the export screen in WordPress.';
            }
        }

        $shown = array_slice($skipped, 0, 6);

        return 'There are no export files in that zip. It should hold the group\'s .csv files and its '
            .'manifest.json'.($shown === [] ? '' : ', and what it holds instead is: '
                .implode(', ', array_map(static fn (string $s): string => '"'.self::clip($s, 40).'"', $shown))
                .(count($skipped) > count($shown) ? ' and '.(count($skipped) - count($shown)).' more' : ''))
            .'.';
    }

    /** A directory of this upload's own, so two uploads at once cannot mix. */
    private function scratch(string $root): string
    {
        if (! is_dir($root)) {
            @mkdir($root, 0775, true);
        }

        $dir = rtrim($root, '/').'/zip-'.bin2hex(random_bytes(8));

        @mkdir($dir, 0700, true);

        return $dir;
    }

    /** Remove a scratch directory and everything in it. One level; it is flat. */
    public static function purge(string $dir): void
    {
        if ($dir === '' || ! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.'/'.$entry;

            is_dir($path) ? self::purge($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    private static function zipError(int|bool $code): string
    {
        return match ($code) {
            ZipArchive::ER_NOZIP => 'it is not a zip file',
            ZipArchive::ER_INCONS => 'it is inconsistent — the index disagrees with the contents',
            ZipArchive::ER_CRC => 'a file inside it failed its checksum',
            ZipArchive::ER_MEMORY, ZipArchive::ER_OPEN, ZipArchive::ER_READ => 'it could not be read',
            ZipArchive::ER_NOENT => 'it is not there',
            default => 'error '.(int) $code,
        };
    }

    /** Entry names are printed into sentences on an admin page and come from a zip. */
    private static function clip(string $value, int $length = 80): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '?', $value) ?? $value;

        return mb_strlen($value) > $length ? mb_substr($value, 0, $length).'…' : $value;
    }
}
