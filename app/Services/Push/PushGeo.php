<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Services\Marketing\Audience;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Where a phone is, from its IP address alone, without asking (Lane PN).
 *
 * The owner: "the area/city is imp to catch after installation via ip or
 * google geo location ... no disturbance to the customer about setting".
 *
 * WHY NOT THE TWO THINGS HE NAMED, said plainly on the screen too:
 *   - the browser's geolocation always shows the shopper a permission prompt,
 *     which is exactly the disturbance he ruled out;
 *   - Google's Geolocation API needs a paid key and, called from the server,
 *     sees only the same IP address this table does.
 *
 * So: an OPTIONAL offline table, the lowest tier of three. A phone's place
 * comes first from its shopper's delivery address (accurate), then from the
 * proxy's geo headers if the host sends them, and only when neither is known
 * from this lookup — source `ip-db`, shown as "approximate", because a mobile
 * network's address often names the operator's hub rather than the phone's
 * emirate.
 *
 * THE DATA: DB-IP's free "IP to City Lite" (https://db-ip.com/db/download/ip-to-city-lite),
 * monthly, licensed CC BY 4.0, which asks for attribution and a link on the
 * page that shows its results — the Subscribers screen prints ATTRIBUTION.
 * The file is the whole world (~130 MB gzipped); import() streams it and keeps
 * the UAE's rows only, a few thousand ranges, as 32-hex-digit 128-bit
 * addresses so one indexed range lookup answers IPv4 and IPv6 alike.
 *
 * NEVER ON A SHOPPER'S CRITICAL PATH: only the subscribe call looks it up
 * (one indexed SELECT), a missing table answers null, and the download runs
 * from the scheduler or over SSH, never in a web request.
 */
final class PushGeo
{
    public const URL = 'https://download.db-ip.com/free/dbip-city-lite-%s.csv.gz';

    public const ATTRIBUTION = 'IP Geolocation by DB-IP';

    public const ATTRIBUTION_URL = 'https://db-ip.com';

    public const META = 'push_geo_meta';

    /** DB-IP's and the other common spellings of each emirate, beyond Marketing's list. */
    private const EXTRA = [
        'dubai' => ['dubayy', 'emirate of dubai'],
        'abu_dhabi' => ['abu zaby', 'abu dhabi emirate', 'emirate of abu dhabi'],
        'sharjah' => ['ash shariqah', 'ash-shariqah', 'emirate of sharjah'],
        'ajman' => ['emirate of ajman'],
        'umm_al_quwain' => ['umm al qaywayn', 'imarat umm al qaywayn', 'umm al-qaywayn', 'umm al quwain'],
        'ras_al_khaimah' => ["ra's al khaymah", 'ras al khaymah', 'ras al-khaymah', 'ras al khaimah'],
        'fujairah' => ['al fujayrah', 'al fujairah', 'emirate of fujairah'],
    ];

    /** 32 lower-case hex digits for an address (IPv4 as ::ffff:a.b.c.d), or null. */
    public static function hex(string $ip): ?string
    {
        $bin = @inet_pton(trim($ip));
        if ($bin === false) {
            return null;
        }
        if (strlen($bin) === 4) {
            $bin = str_repeat("\0", 10)."\xff\xff".$bin;
        }

        return strlen($bin) === 16 ? bin2hex($bin) : null;
    }

    /** The emirate key a DB-IP state name stands for, or null. */
    public static function emirate(?string $state): ?string
    {
        $s = mb_strtolower(trim((string) $state));
        if ($s === '') {
            return null;
        }
        $key = Audience::emirateOf($s, 'AE');
        if ($key !== null && $key !== 'outside') {
            return $key;
        }
        foreach (self::EXTRA as $k => $names) {
            if (in_array($s, $names, true) || in_array(str_replace('-', ' ', $s), $names, true)) {
                return $k;
            }
        }

        return null;
    }

    /**
     * Where an address is, from the table: {country, region, city,
     * location_source: 'ip-db'} or null. Public addresses only; one indexed
     * SELECT; never throws.
     *
     * @return array{country:string, region:?string, city:?string, location_source:string}|null
     */
    public static function locate(?string $ip): ?array
    {
        if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }
        $hex = self::hex($ip);
        if ($hex === null) {
            return null;
        }
        try {
            $row = DB::table('push_geo_ranges')->where('ip_from', '<=', $hex)->orderByDesc('ip_from')->first(['ip_to', 'emirate', 'city']);
        } catch (\Throwable) {
            return null;
        }
        if ($row === null || strcmp((string) $row->ip_to, $hex) < 0) {
            return null;
        }
        $region = $row->emirate !== null ? (Audience::EMIRATES[$row->emirate][0] ?? null) : null;

        return ['country' => 'AE', 'region' => $region, 'city' => $row->city ?: $region, 'location_source' => 'ip-db'];
    }

    /**
     * Read a DB-IP City Lite CSV (plain or .gz), keep the UAE's rows, and
     * replace the table with them in one transaction. Rows look like
     *   1.2.3.0,1.2.3.255,AS,AE,Dubai,Dubai,25.2,55.3
     * (an optional leading ipv4/ipv6 column is tolerated). Returns the number
     * of ranges kept.
     */
    public static function import(string $file): int
    {
        $fh = str_ends_with(strtolower($file), '.gz') ? @gzopen($file, 'rb') : @fopen($file, 'rb');
        if ($fh === false) {
            throw new \RuntimeException('Cannot read '.basename($file));
        }
        $gz = str_ends_with(strtolower($file), '.gz');

        $rows = [];
        try {
            while (($line = $gz ? gzgets($fh, 4096) : fgets($fh, 4096)) !== false) {
                if (! str_contains($line, ',AE,') && ! str_contains($line, '"AE"')) {
                    continue;   // the cheap test first: 99 % of the world is skipped here
                }
                $f = str_getcsv(trim($line), ',', '"', '');
                if (isset($f[0]) && in_array(strtolower($f[0]), ['ipv4', 'ipv6'], true)) {
                    array_shift($f);
                }
                if (count($f) < 6 || strtoupper((string) $f[3]) !== 'AE') {
                    continue;
                }
                $from = self::hex((string) $f[0]);
                $to = self::hex((string) $f[1]);
                if ($from === null || $to === null || strcmp($from, $to) > 0) {
                    continue;
                }
                $city = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $f[5]));
                $rows[] = ['ip_from' => $from, 'ip_to' => $to, 'emirate' => self::emirate((string) $f[4]),
                    'city' => $city === '' || ! mb_check_encoding($city, 'UTF-8') ? null : mb_substr($city, 0, 80)];
            }
        } finally {
            $gz ? gzclose($fh) : fclose($fh);
        }

        if ($rows === []) {
            throw new \RuntimeException('The file holds no UAE rows; the table was left as it was.');
        }

        DB::transaction(function () use ($rows) {
            DB::table('push_geo_ranges')->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('push_geo_ranges')->insert($chunk);
            }
        });

        return count($rows);
    }

    /**
     * Download this month's file (or last month's, early in a month before
     * the new one is out) into storage and import it. Returns the ranges kept.
     */
    public static function download(): int
    {
        $dir = storage_path('app/push-geo');
        @mkdir($dir, 0775, true);
        $file = $dir.'/dbip-city-lite.csv.gz';
        $error = 'not tried';
        foreach ([now()->format('Y-m'), now()->subMonthNoOverflow()->format('Y-m')] as $month) {
            try {
                $r = Http::timeout(600)->connectTimeout(15)->sink($file)->get(sprintf(self::URL, $month));
                if ($r->successful() && filesize($file) > 1000) {
                    $n = self::import($file);
                    self::meta(['rows' => $n, 'month' => $month, 'at' => now()->toIso8601String(), 'error' => null]);
                    @unlink($file);

                    return $n;
                }
                $error = 'HTTP '.$r->status().' for '.$month;
            } catch (\Throwable $e) {
                $error = mb_substr($e->getMessage(), 0, 200);
            }
        }
        @unlink($file);
        self::meta(['error' => $error, 'tried_at' => now()->toIso8601String()]);
        throw new \RuntimeException($error);
    }

    /** @return array<string, mixed> what the last import did */
    public static function status(): array
    {
        $meta = [];
        try {
            $raw = DB::table('settings')->where('key', self::META)->value('value');
            $meta = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
            $meta['ranges'] = (int) DB::table('push_geo_ranges')->count();
        } catch (\Throwable) {
            $meta['ranges'] = 0;
        }

        return $meta;
    }

    /** @param  array<string, mixed>  $change */
    public static function meta(array $change): void
    {
        $now = self::status();
        unset($now['ranges']);
        app(SettingsService::class)->set(self::META, array_replace($now, $change), false);
    }
}
