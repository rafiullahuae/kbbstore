<?php

declare(strict_types=1);

namespace App\Services\Security;

use Illuminate\Support\Facades\Http;

/**
 * IP address → country, offline, in microseconds.                  (Lane FW)
 *
 * THE DATA: DB-IP "IP to Country Lite" (https://db-ip.com/db/download/ip-to-country-lite),
 * monthly, licensed CC BY 4.0. The licence asks for attribution to DB-IP.com
 * and, in a web application, a link back to DB-IP.com on the pages that
 * display or use its results — Store → Security → Firewall prints
 * ATTRIBUTION with that link.
 *
 * NOT SHIPPED IN THE PACKAGE: the converted file is ~5 MB of binary, and the
 * updater accepts neither a `.bin` file nor anything over 8 MB. It is built ON
 * THE SERVER by download() (the "Download country database" button, or
 * `php artisan kbb:firewall data`, or the monthly schedule) into
 * storage/app/firewall/country.bin. Until it exists every address is "unknown"
 * and every country rule is simply not applied: the firewall fails open.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * THE FILE FORMAT (all integers big-endian)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *   0      8   magic "KBBGEO1\n"
 *   8      4   build date, YYYYMMDD
 *   12     4   IPv4 rows
 *   16     4   IPv6 rows
 *   20     4   country codes in the table
 *   24    32   SHA-256 of every byte from offset 64 to the end
 *   56     8   zero
 *   64   512   country table: 256 two-letter codes, index 0 = "--" (unknown)
 *   576  262148  IPv4 index: 65,537 u32 — row number of the first row whose
 *                start is at or after (bucket << 16), for the top 16 bits
 *   …    262148  IPv6 index: the same, for the top 16 bits of the address
 *   …    5 × n4  IPv4 rows: u32 start, u8 country
 *   …    9 × n6  IPv6 rows: the first 64 bits of the start, u8 country
 *
 * Rows tile the whole address space: every gap is a row with country 0, and
 * the first row of each family starts at zero, so "the last row whose start
 * is ≤ the address" always exists and is the answer.
 *
 * IPv6 IS KEPT TO /64. DB-IP's IPv6 ranges are almost all /64-aligned (340 of
 * 345,868 rows were not, in the June 2026 build); a /64 is one subscriber, so
 * truncating costs nothing a firewall can notice and halves the file.
 *
 * A LOOKUP is: one fstat-free fopen, one 8-byte read from the index (the row
 * window for the address's top 16 bits), then either one read of that window
 * (≤ 256 rows, the usual case for IPv4) and a binary search in memory, or a
 * binary search with fseek over a larger window — ~17 steps for the busiest
 * IPv6 /16. Never the whole file. Measured in the lane report.
 *
 * INTEGRITY. The request path checks the magic and that the file size is
 * exactly what the header's counts say, so a truncated copy is refused, not
 * misread. The SHA-256 over the body is checked in full by verify(), which
 * runs before a freshly built file is moved into place, and on the screen.
 */
final class CountryDb
{
    public const MAGIC = "KBBGEO1\n";

    public const ATTRIBUTION = 'IP Geolocation by DB-IP';

    public const ATTRIBUTION_URL = 'https://db-ip.com';

    /** Official monthly file; %s is YYYY-MM. */
    public const URL = 'https://download.db-ip.com/free/dbip-country-lite-%s.csv.gz';

    private const HEADER = 64;

    private const CC_TABLE = 512;

    private const INDEX = 65537 * 4;

    private const OFF_IDX4 = self::HEADER + self::CC_TABLE;

    private const OFF_IDX6 = self::OFF_IDX4 + self::INDEX;

    private const OFF_ROWS = self::OFF_IDX6 + self::INDEX;

    /** Read a window in one go when it has at most this many rows. */
    private const WINDOW = 256;

    /** @var array{h:resource, n4:int, n6:int, cc:list<string>}|false|null */
    private static mixed $open = null;

    private static ?string $pathOverride = null;

    public static function path(): string
    {
        // A test process never reads a database a developer downloaded into
        // this checkout: it gets one only by usePath().
        return self::$pathOverride ?? storage_path('app/firewall/country'.(app()->runningUnitTests() ? '-test-'.getmypid() : '').'.bin');
    }

    /** Tests point the lookup at a fixture. */
    public static function usePath(?string $path): void
    {
        self::close();
        self::$pathOverride = $path;
    }

    public static function close(): void
    {
        if (is_array(self::$open)) {
            @fclose(self::$open['h']);
        }

        self::$open = null;
    }

    /**
     * Two-letter country for a packed address (IpRange::pack), or '' unknown.
     */
    public static function lookup(string $bin): string
    {
        $db = self::open();

        if ($db === false) {
            return '';
        }

        $v4 = strlen($bin) === 4;
        $width = $v4 ? 4 : 8;
        $row = $width + 1;
        $key = $v4 ? $bin : substr($bin, 0, 8);
        $rows = $v4 ? self::OFF_ROWS : self::OFF_ROWS + $db['n4'] * 5;
        $total = $v4 ? $db['n4'] : $db['n6'];

        if ($total === 0) {
            return '';
        }

        $h = $db['h'];
        $bucket = (ord($key[0]) << 8) | ord($key[1]);

        if (fseek($h, ($v4 ? self::OFF_IDX4 : self::OFF_IDX6) + $bucket * 4) !== 0) {
            return '';
        }

        $pair = fread($h, 8);

        if ($pair === false || strlen($pair) !== 8) {
            return '';
        }

        ['a' => $first, 'b' => $next] = unpack('Na/Nb', $pair);
        $lo = max(0, $first - 1);
        $hi = min($total - 1, max($lo, $next - 1));
        $count = $hi - $lo + 1;

        if ($count <= self::WINDOW) {
            fseek($h, $rows + $lo * $row);
            $buf = fread($h, $count * $row);

            if ($buf === false || strlen($buf) !== $count * $row) {
                return '';
            }

            $a = 0;
            $b = $count - 1;

            while ($a < $b) {
                $mid = ($a + $b + 1) >> 1;

                if (strcmp(substr($buf, $mid * $row, $width), $key) <= 0) {
                    $a = $mid;
                } else {
                    $b = $mid - 1;
                }
            }

            return $db['cc'][ord($buf[$a * $row + $width])] ?? '';
        }

        while ($lo < $hi) {
            $mid = ($lo + $hi + 1) >> 1;
            fseek($h, $rows + $mid * $row);

            if (strcmp((string) fread($h, $width), $key) <= 0) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }

        fseek($h, $rows + $lo * $row + $width);

        return $db['cc'][ord((string) fread($h, 1))] ?? '';
    }

    /** @return array{h:resource, n4:int, n6:int, cc:list<string>}|false */
    private static function open(): array|false
    {
        if (self::$open !== null) {
            return self::$open;
        }

        $h = @fopen(self::path(), 'rb');

        if ($h === false) {
            return self::$open = false;
        }

        $head = fread($h, self::HEADER + self::CC_TABLE);
        $info = is_string($head) ? self::header($head) : null;
        $stat = fstat($h);

        if ($info === null || ($stat['size'] ?? -1) !== self::size($info['n4'], $info['n6'])) {
            fclose($h);

            return self::$open = false;
        }

        $cc = str_split(substr($head, self::HEADER, self::CC_TABLE), 2);
        $cc[0] = '';

        return self::$open = ['h' => $h, 'n4' => $info['n4'], 'n6' => $info['n6'], 'cc' => $cc];
    }

    /** @return array{date:int, n4:int, n6:int, ncc:int, sha:string}|null */
    private static function header(string $head): ?array
    {
        if (strlen($head) < self::HEADER || strncmp($head, self::MAGIC, 8) !== 0) {
            return null;
        }

        $u = unpack('Ndate/Nn4/Nn6/Nncc', substr($head, 8, 16));

        return ['date' => $u['date'], 'n4' => $u['n4'], 'n6' => $u['n6'], 'ncc' => $u['ncc'], 'sha' => substr($head, 24, 32)];
    }

    private static function size(int $n4, int $n6): int
    {
        return self::OFF_ROWS + $n4 * 5 + $n6 * 9;
    }

    /**
     * Full check of a file: header, size and SHA-256 of the body.
     *
     * @return array{ok:bool, date:?int, v4:int, v6:int, countries:int, bytes:int, error:?string}
     */
    public static function verify(?string $path = null): array
    {
        $path ??= self::path();
        $fail = static fn (string $why): array => ['ok' => false, 'date' => null, 'v4' => 0, 'v6' => 0, 'countries' => 0, 'bytes' => 0, 'error' => $why];

        if (! is_file($path)) {
            return $fail('not downloaded yet');
        }

        $h = @fopen($path, 'rb');

        if ($h === false) {
            return $fail('cannot be read');
        }

        $info = self::header((string) fread($h, self::HEADER));
        $size = (int) filesize($path);

        if ($info === null) {
            fclose($h);

            return $fail('not a country database (wrong header)');
        }

        if ($size !== self::size($info['n4'], $info['n6'])) {
            fclose($h);

            return $fail('truncated or padded: '.$size.' bytes, expected '.self::size($info['n4'], $info['n6']));
        }

        $ctx = hash_init('sha256');
        hash_update_stream($ctx, $h);
        fclose($h);

        if (! hash_equals($info['sha'], hash_final($ctx, true))) {
            return $fail('checksum does not match: the file is damaged');
        }

        // The table's entry 0 is "unknown", not a country.
        return ['ok' => true, 'date' => $info['date'], 'v4' => $info['n4'], 'v6' => $info['n6'], 'countries' => $info['ncc'] - 1, 'bytes' => $size, 'error' => null];
    }

    /* ═══════════════════════════════════════════════ building ═══ */

    /**
     * Convert DB-IP CSV (`first,last,CC` per line, IPv4 rows then IPv6 rows,
     * each family sorted — DB-IP's own order) into the format above, write it
     * to $out, verify it, and return verify()'s answer. Streams: memory stays
     * flat whatever the size of the input. `.gz` is read through zlib.
     *
     * @param  list<string>  $inputs  one CSV, or one per family
     */
    public static function build(array $inputs, string $out, int $date): array
    {
        // The directory may not exist yet: `kbb:firewall data --file=` on a
        // fresh server builds here before anything has created it.
        @mkdir(dirname($out), 0775, true);
        $tmp4 = $out.'.v4.tmp';
        $tmp6 = $out.'.v6.tmp';
        $codes = ['--' => 0];
        $state = [
            4 => ['fh' => fopen($tmp4, 'wb'), 'n' => 0, 'end' => null, 'last' => -1, 'lastStart' => null, 'idx' => array_fill(0, 65537, -1)],
            6 => ['fh' => fopen($tmp6, 'wb'), 'n' => 0, 'end' => null, 'last' => -1, 'lastStart' => null, 'idx' => array_fill(0, 65537, -1)],
        ];

        try {
            foreach ($inputs as $input) {
                $in = str_ends_with($input, '.gz') ? gzopen($input, 'rb') : fopen($input, 'rb');

                if ($in === false) {
                    throw new \RuntimeException('cannot open '.basename($input));
                }

                while (($line = str_ends_with($input, '.gz') ? gzgets($in, 512) : fgets($in, 512)) !== false) {
                    $parts = explode(',', trim($line));

                    if (count($parts) < 3) {
                        continue;
                    }

                    $a = @inet_pton(trim($parts[0], '" '));
                    $b = @inet_pton(trim($parts[1], '" '));
                    $cc = strtoupper(trim($parts[2], "\" \r\n"));

                    if ($a === false || $b === false || strlen($a) !== strlen($b)) {
                        continue;
                    }

                    if (preg_match('/^[A-Z]{2}$/', $cc) !== 1 || $cc === 'ZZ') {
                        $cc = '--';
                    }

                    if (! isset($codes[$cc])) {
                        if (count($codes) >= 256) {
                            throw new \RuntimeException('more than 255 country codes');
                        }
                        $codes[$cc] = count($codes);
                    }

                    $family = strlen($a) === 4 ? 4 : 6;
                    self::emit($state[$family], $a, $b, $codes[$cc], $family);
                }

                str_ends_with($input, '.gz') ? gzclose($in) : fclose($in);
            }

            // The space after the last range is unknown, not the last country.
            foreach ([4, 6] as $family) {
                $gap = $state[$family]['end'] !== null ? self::inc($state[$family]['end']) : null;

                if ($gap !== null) {
                    self::row($state[$family], $gap, 0, $family);
                }
            }

            fclose($state[4]['fh']);
            fclose($state[6]['fh']);

            if ($state[4]['n'] < 2 || $state[6]['n'] < 2) {
                throw new \RuntimeException('the file held too few ranges ('.$state[4]['n'].' IPv4, '.$state[6]['n'].' IPv6)');
            }

            $table = str_repeat("\0\0", 256);

            foreach ($codes as $code => $i) {
                $table = substr_replace($table, $code === '--' ? '--' : $code, $i * 2, 2);
            }

            $body = $table.self::index($state[4]).self::index($state[6]);
            $tmp = $out.'.'.getmypid().'.tmp';
            $fh = fopen($tmp, 'wb');
            $ctx = hash_init('sha256');
            hash_update($ctx, $body);

            foreach ([$tmp4, $tmp6] as $part) {
                $ph = fopen($part, 'rb');
                hash_update_stream($ctx, $ph);
                fclose($ph);
            }

            $head = self::MAGIC.pack('NNNN', $date, $state[4]['n'], $state[6]['n'], count($codes)).hash_final($ctx, true).str_repeat("\0", 8);
            fwrite($fh, $head.$body);

            foreach ([$tmp4, $tmp6] as $part) {
                $ph = fopen($part, 'rb');
                stream_copy_to_stream($ph, $fh);
                fclose($ph);
            }

            fclose($fh);
            $check = self::verify($tmp);

            if (! $check['ok']) {
                @unlink($tmp);
                throw new \RuntimeException('the built file failed its own check: '.$check['error']);
            }

            @mkdir(dirname($out), 0775, true);

            if (! @rename($tmp, $out)) {
                @unlink($tmp);
                throw new \RuntimeException('could not move the new file into place');
            }

            if ($out === self::path()) {
                self::close();
            }

            return $check;
        } finally {
            @unlink($tmp4);
            @unlink($tmp6);
        }
    }

    /** Append one CSV range to its family's row file, filling gaps with "--". */
    private static function emit(array &$s, string $a, string $b, int $cc, int $family): void
    {
        $zero = str_repeat("\0", $family === 4 ? 4 : 16);

        if ($s['end'] === null) {
            if ($a !== $zero) {
                self::row($s, $zero, 0, $family);
            }
        } else {
            if (strcmp($a, $s['end']) <= 0) {
                throw new \RuntimeException('the CSV is not sorted (at '.inet_ntop($a).')');
            }

            $gap = self::inc($s['end']);

            if ($gap !== null && strcmp($gap, $a) < 0) {
                self::row($s, $gap, 0, $family);
            }
        }

        self::row($s, $a, $cc, $family);
        $s['end'] = $b;
    }

    private static function row(array &$s, string $start, int $cc, int $family): void
    {
        if ($s['last'] === $cc) {
            return; // merged into the row before it
        }

        $key = $family === 4 ? $start : substr($start, 0, 8);

        // Two ranges inside one /64: the later one wins the /64.
        if ($s['lastStart'] === $key) {
            fseek($s['fh'], -1, SEEK_END);
            fwrite($s['fh'], chr($cc));
            $s['last'] = $cc;

            return;
        }

        fwrite($s['fh'], $key.chr($cc));
        $bucket = (ord($key[0]) << 8) | ord($key[1]);

        // The first row in each bucket; buckets with none are filled in index().
        if ($s['idx'][$bucket] === -1) {
            $s['idx'][$bucket] = $s['n'];
        }

        $s['n']++;
        $s['last'] = $cc;
        $s['lastStart'] = $key;
    }

    /** 65,537 u32: idx[b] = first row with start ≥ b<<16 (n for "none after"). */
    private static function index(array $s): string
    {
        $idx = $s['idx'];
        $idx[65536] = $s['n'];

        for ($b = 65535; $b >= 0; $b--) {
            if ($idx[$b] === -1) {
                $idx[$b] = $idx[$b + 1];
            }
        }

        return pack('N*', ...$idx);
    }

    private static function inc(string $bin): ?string
    {
        for ($i = strlen($bin) - 1; $i >= 0; $i--) {
            $c = ord($bin[$i]);

            if ($c < 255) {
                return substr($bin, 0, $i).chr($c + 1).str_repeat("\0", strlen($bin) - $i - 1);
            }
        }

        return null;
    }

    /* ═══════════════════════════════════════════════ downloading ═══ */

    /**
     * This month's file from DB-IP (or last month's, early in a month), built
     * into place. On any failure the file already in place is left alone.
     *
     * @return array{ok:bool, date:?int, v4:int, v6:int, countries:int, bytes:int, error:?string, source?:string}
     */
    public static function download(): array
    {
        $dir = dirname(self::path());
        @mkdir($dir, 0775, true);
        $gz = $dir.'/dbip-country-lite.csv.gz';
        $error = 'not tried';

        @set_time_limit(300);

        foreach ([now()->format('Y-m'), now()->subMonthNoOverflow()->format('Y-m')] as $month) {
            try {
                $r = Http::timeout(240)->connectTimeout(15)->sink($gz)->get(sprintf(self::URL, $month));

                if ($r->successful() && filesize($gz) > 1_000_000) {
                    $result = self::build([$gz], self::path(), (int) str_replace('-', '', $month).'01');
                    @unlink($gz);

                    return $result + ['source' => sprintf(self::URL, $month)];
                }

                $error = 'HTTP '.$r->status().' for '.$month;
            } catch (\Throwable $e) {
                $error = mb_substr($e->getMessage(), 0, 200);
            }
        }

        @unlink($gz);

        return ['ok' => false, 'date' => null, 'v4' => 0, 'v6' => 0, 'countries' => 0, 'bytes' => 0, 'error' => $error];
    }

    /**
     * Every country code the installed file knows, for the screen's table.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        $db = self::open();

        if ($db === false) {
            return [];
        }

        $codes = array_values(array_filter($db['cc'], static fn (string $c): bool => preg_match('/^[A-Z]{2}$/', $c) === 1));
        sort($codes);

        return $codes;
    }

    public static function forget(): void
    {
        self::close();
        self::$pathOverride = null;
    }
}
