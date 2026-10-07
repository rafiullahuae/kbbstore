<?php

declare(strict_types=1);

namespace App\Services\DomainMove;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * What the world's DNS says about a name. READ-ONLY, bounded. (Lane DW)
 *
 * Used by Platform -> Domain switch for "Check DNS now". The caller decides
 * WHICH names may be asked about (DomainSwitch::dnsHosts(), the configured
 * domains and nothing typed); this class only answers.
 *
 * WHY GOOGLE'S PUBLIC RESOLVER FIRST, AND dns_get_record() ONLY AS A FALLBACK.
 *
 *   1. Bounded. dns_get_record() takes no timeout: it waits as long as the
 *      server's resolver configuration says (5 s x attempts x nameservers), and
 *      a name whose nameservers are half-moved is exactly the case it hangs on.
 *      An HTTP request has a hard timeout, and the queries run in parallel.
 *   2. The same view the checklist asks for. Step 9 of
 *      docs/KBEAUTYBLISS-SWITCH-CHECKLIST.md is `dig +short kbeautybliss.com
 *      @8.8.8.8`; this is that question, asked of that resolver. The server's
 *      own resolver may answer from a cache, or from a hosts-file entry the
 *      hosting panel wrote for its own domains, which is not what a shopper or
 *      Let's Encrypt sees.
 *
 * When the public resolver cannot be reached, the server's own resolver is
 * asked instead and the answer says so (`source`). Nothing here ever reports a
 * name as clean because a lookup failed: a failed lookup is `ok => false`.
 */
class DnsLookup
{
    public const DOH = 'https://dns.google/resolve';

    public const TIMEOUT = 4;

    public const CONNECT_TIMEOUT = 3;

    /** RFC 1035 type numbers, for reading the resolver's JSON. */
    public const TYPES = ['A' => 1, 'NS' => 2, 'CNAME' => 5, 'MX' => 15, 'TXT' => 16, 'AAAA' => 28];

    /**
     * @param  list<array{0: string, 1: string}>  $queries  [host, type]; type one of TYPES' keys
     * @return array<string, array{ok: bool, records: list<string>, source: string}> keyed "host TYPE"
     */
    public function lookup(array $queries): array
    {
        $queries = array_values(array_filter($queries, static fn ($q) => isset(self::TYPES[$q[1]])));

        if ($queries === []) {
            return [];
        }

        try {
            $responses = Http::pool(function (Pool $pool) use ($queries) {
                $out = [];

                foreach ($queries as [$host, $type]) {
                    $out[] = $pool->as($host.' '.$type)
                        ->timeout(self::TIMEOUT)
                        ->connectTimeout(self::CONNECT_TIMEOUT)
                        ->withOptions(['allow_redirects' => false])
                        ->acceptJson()
                        ->get(self::DOH, ['name' => $host, 'type' => $type]);
                }

                return $out;
            });
        } catch (\Throwable) {
            $responses = [];
        }

        $out = [];

        foreach ($queries as [$host, $type]) {
            $key = $host.' '.$type;
            $parsed = $this->parse($responses[$key] ?? null, $type);

            if ($parsed === null) {
                $native = $this->native($host, $type);
                $parsed = $native === null
                    ? ['ok' => false, 'records' => [], 'source' => 'none']
                    : ['ok' => true, 'records' => $native, 'source' => 'server'];
            }

            $out[$key] = $parsed;
        }

        return $out;
    }

    /** @return array{ok: bool, records: list<string>, source: string}|null null when the answer is unusable */
    private function parse(mixed $response, string $type): ?array
    {
        if (! $response instanceof Response || ! $response->successful()) {
            return null;
        }

        $body = $response->json();

        if (! is_array($body) || ! isset($body['Status'])) {
            return null;
        }

        // 0 = an answer, 3 = the name does not exist. Both are facts; anything
        // else (SERVFAIL, REFUSED) is "could not be told".
        if ((int) $body['Status'] === 3) {
            return ['ok' => true, 'records' => [], 'source' => 'public'];
        }

        if ((int) $body['Status'] !== 0) {
            return null;
        }

        $records = [];

        foreach ((array) ($body['Answer'] ?? []) as $answer) {
            if (! is_array($answer) || (int) ($answer['type'] ?? 0) !== self::TYPES[$type]) {
                continue;   // a CNAME on the way to an A answer, for example
            }

            $value = $this->clean($type, (string) ($answer['data'] ?? ''));

            if ($value !== '') {
                $records[] = $value;
            }
        }

        sort($records);

        return ['ok' => true, 'records' => array_values(array_unique($records)), 'source' => 'public'];
    }

    /**
     * The server's own resolver. Protected so a test can stand in for it --
     * dns_get_record() is the one boundary Http::fake() cannot reach.
     *
     * @return list<string>|null null when the lookup itself failed
     */
    protected function native(string $host, string $type): ?array
    {
        $constant = ['A' => DNS_A, 'NS' => DNS_NS, 'CNAME' => DNS_CNAME, 'MX' => DNS_MX, 'TXT' => DNS_TXT, 'AAAA' => DNS_AAAA][$type];
        $rows = @dns_get_record($host, $constant);

        if (! is_array($rows)) {
            return null;
        }

        $out = [];

        foreach ($rows as $row) {
            $value = match ($type) {
                'A' => (string) ($row['ip'] ?? ''),
                'AAAA' => (string) ($row['ipv6'] ?? ''),
                'MX' => ($row['pri'] ?? '').' '.($row['target'] ?? ''),
                'TXT' => (string) ($row['txt'] ?? ''),
                default => (string) ($row['target'] ?? ''),
            };
            $value = $this->clean($type, $value);

            if ($value !== '') {
                $out[] = $value;
            }
        }

        sort($out);

        return array_values(array_unique($out));
    }

    /** One record as a person reads it: lower-case names with no trailing dot, TXT without quotes. */
    private function clean(string $type, string $value): string
    {
        $value = trim($value);

        if ($type === 'TXT') {
            return substr(trim($value, '"'), 0, 255);
        }

        if ($type === 'A') {
            return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? $value : '';
        }

        if ($type === 'AAAA') {
            return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? strtolower($value) : '';
        }

        return substr(strtolower(rtrim(preg_replace('/\s+/', ' ', $value) ?? '', '.')), 0, 255);
    }
}
