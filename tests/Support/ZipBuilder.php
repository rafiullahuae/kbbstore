<?php

declare(strict_types=1);

namespace Tests\Support;

use ZipArchive;

/**
 * Builds the archives ImportArchive has to refuse.
 *
 * WHY THESE ARE BUILT AND NOT CHECKED IN. Four of the five hostile zips this
 * lane tests against cannot be committed to a git repository at all — a member
 * named `../../../.env` is a file git would decline to check out, and a 400MB
 * expansion is not something to carry in a repo whose worktrees already filled
 * this disk once (CLAUDE.md, the savepoint landmine). They are written here, by
 * hand, at the byte level where ZipArchive will not write them for us.
 *
 * ZipArchive REFUSES TO CREATE SOME OF THEM, which is itself the reason this
 * class exists: `addFromString('../../../.env', ...)` is normalised by libzip
 * before it reaches the archive, so a test built with ZipArchive alone would be
 * testing a name that is not the name the attack uses. The hostile members are
 * therefore assembled from raw local-file headers and a central directory —
 * ~60 lines of struct packing, in exchange for an archive that says exactly
 * what we meant it to say.
 */
final class ZipBuilder
{
    /**
     * An ordinary archive, the way an export plugin would write one.
     *
     * @param  array<string, string>  $members  published name => contents
     */
    public static function ordinary(array $members): string
    {
        $path = self::tempPath();

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($members as $name => $body) {
            $zip->addFromString($name, $body);
        }

        $zip->close();

        return $path;
    }

    /**
     * An archive whose member names are written verbatim, however hostile.
     *
     * @param  list<array{name: string, body: string, mode?: int, declared?: int}>  $members
     *   `mode` is the unix mode for the external attributes — 0120777 makes the
     *   entry a symlink. `declared` overrides the uncompressed size written into
     *   both headers, which is how a zip lies about how big it unpacks to.
     */
    public static function raw(array $members): string
    {
        $path = self::tempPath();
        $out = fopen($path, 'wb');

        if ($out === false) {
            throw new \RuntimeException('could not open a temp file for the zip');
        }

        $central = '';
        $offset = 0;

        foreach ($members as $member) {
            $name = $member['name'];
            $body = $member['body'];
            $crc = crc32($body);
            $size = $member['declared'] ?? strlen($body);

            /*
             * DEFLATED WHEN ASKED, STORED OTHERWISE.
             *
             * Stored keeps the hostile-name fixtures simple — the point of
             * those is the header, and compression would put a second variable
             * between the test and what it is testing. The BOMB has to be
             * deflated, and that is not a detail: a stored bomb is an 80MB
             * upload, which this shop refuses at the door for being 80MB, so
             * the test would pass without the expansion guard ever running.
             * Compressed, the same 80MB arrives as an archive of a few hundred
             * kilobytes and the only thing that can stop it is a cap counted
             * from bytes read.
             */
            $method = ($member['deflate'] ?? false) ? 8 : 0;
            $payload = $method === 8 ? (string) gzdeflate($body, 9) : $body;

            $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, $method, 0, 0, $crc, strlen($payload), $size, strlen($name), 0)
                .$name;

            fwrite($out, $local);
            fwrite($out, $payload);

            // The unix mode lives in the TOP 16 bits of the external
            // attributes, and opsys 3 (unix) is what makes a reader look there.
            $external = isset($member['mode']) ? ($member['mode'] << 16) : (0100644 << 16);

            $central .= pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50,
                (3 << 8) | 20,   // version made by: unix
                20,              // version needed
                0,               // flags
                $method,
                0, 0,            // time, date
                $crc,
                strlen($payload),
                $size,
                strlen($name),
                0, 0,            // extra, comment
                0,               // disk
                0,               // internal attributes
                $external,
                $offset,
            ).$name;

            $offset += strlen($local) + strlen($payload);
        }

        $centralOffset = $offset;

        fwrite($out, $central);
        fwrite($out, pack(
            'VvvvvVVv',
            0x06054b50,
            0, 0,
            count($members), count($members),
            strlen($central),
            $centralOffset,
            0,
        ));

        fclose($out);

        return $path;
    }

    /**
     * A bomb: a member whose header understates its contents by orders of
     * magnitude, so that only a cap counted from bytes ACTUALLY READ stops it.
     */
    public static function bomb(string $name, int $realBytes, int $declaredBytes): string
    {
        return self::raw([[
            'name' => $name,
            // Highly repetitive, so it deflates to almost nothing while still
            // being the real number of bytes the reader has to swallow.
            'body' => str_repeat('a,b,c,d,e,f,g,h,i,j,k,l,m,n,o,p'."\n", intdiv($realBytes, 32)),
            'declared' => $declaredBytes,
            'deflate' => true,
        ]]);
    }

    /**
     * An archive with no members at all.
     *
     * ZipArchive writes NO FILE when asked to create one with nothing in it, so
     * this is the end-of-central-directory record on its own — twenty-two
     * bytes, which is what an empty zip is.
     */
    public static function empty(): string
    {
        $path = self::tempPath();

        file_put_contents($path, pack('VvvvvVVv', 0x06054b50, 0, 0, 0, 0, 0, 0, 0));

        return $path;
    }

    /** A download that stopped part way through: the central directory is gone. */
    public static function truncated(array $members): string
    {
        $path = self::ordinary($members);
        $bytes = (string) file_get_contents($path);

        file_put_contents($path, substr($bytes, 0, intdiv(strlen($bytes), 2)));

        return $path;
    }

    public static function tempPath(string $suffix = '.zip'): string
    {
        return sys_get_temp_dir().'/kbb-gm-'.bin2hex(random_bytes(8)).$suffix;
    }
}
