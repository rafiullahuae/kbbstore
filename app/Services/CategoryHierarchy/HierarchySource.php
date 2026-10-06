<?php

declare(strict_types=1);

namespace App\Services\CategoryHierarchy;

use App\Support\TermName;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

/**
 * kbeautybliss.com's product-category tree, as a flat list of
 * {id, slug, name, parent} -- fetched by the server or read from a file the
 * owner saved. (Lane CH)
 *
 * WHERE IT MAY FETCH FROM. One host, from config (`kbb.hierarchy_source_host`,
 * env KBB_HIERARCHY_SOURCE_HOST, default kbeautybliss.com), over https, at a
 * fixed path. Nothing in the request names a URL, so the admin endpoint cannot
 * be turned into a fetch-anything proxy. One redirect is followed, and only to
 * the same host or its www. twin over https -- WordPress commonly 301s the bare
 * domain to www.
 *
 * WHAT IT TRUSTS. Nothing. Every field is re-typed: ids are positive ints,
 * slugs and names are strings capped at 200 characters, and a list longer
 * than MAX_NODES is refused rather than truncated (a truncated tree would
 * report real categories as "not on kbeautybliss.com").
 */
final class HierarchySource
{
    public const MAX_NODES = 5000;
    public const MAX_PAGES = 30;
    public const PER_PAGE = 100;
    public const MAX_UPLOAD_KB = 2048;
    public const MAX_FILES = 10;
    private const MAX_FIELD = 200;

    /** WP core first; WooCommerce's public Store API if a site has hidden it. */
    private const ENDPOINTS = [
        '/wp-json/wp/v2/product_cat' => ['_fields' => 'id,name,slug,parent'],
        '/wp-json/wc/store/v1/products/categories' => [],
    ];

    public static function host(): string
    {
        $host = strtolower(trim((string) config('kbb.hierarchy_source_host', 'kbeautybliss.com')));

        // A hostname, not a URL, not an IP literal, not a port.
        if (preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) !== 1) {
            return 'kbeautybliss.com';
        }

        return $host;
    }

    /**
     * @return array{nodes: list<array{id:int, slug:string, name:string, parent:int}>, source:string}
     *
     * @throws HierarchySourceError
     */
    public function fetch(): array
    {
        $host = self::host();
        $lastError = '';

        foreach (self::ENDPOINTS as $path => $extra) {
            try {
                $nodes = $this->fetchAll($host, $path, $extra);
            } catch (HierarchySourceError $e) {
                $lastError = $e->getMessage();
                continue;
            }

            return ['nodes' => $nodes, 'source' => 'https://'.$host.$path];
        }

        throw new HierarchySourceError($lastError !== '' ? $lastError : 'The site did not answer.');
    }

    /**
     * @return array{nodes: list<array{id:int, slug:string, name:string, parent:int}>, source:string}
     *
     * @throws HierarchySourceError
     */
    public function fromUpload(UploadedFile $file): array
    {
        return $this->fromUploads([$file]);
    }

    /**
     * One file, or several: WordPress serves 100 categories a page, so a shop
     * with 230 saves three pages from the browser and uploads them together.
     *
     * @param  array<UploadedFile>  $files
     * @return array{nodes: list<array{id:int, slug:string, name:string, parent:int}>, source:string}
     *
     * @throws HierarchySourceError
     */
    public function fromUploads(array $files): array
    {
        if ($files === [] || count($files) > self::MAX_FILES) {
            throw new HierarchySourceError('Upload between 1 and '.self::MAX_FILES.' files.');
        }

        $nodes = [];
        $names = [];

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                throw new HierarchySourceError('The upload did not arrive.');
            }

            if ($file->getSize() > self::MAX_UPLOAD_KB * 1024) {
                throw new HierarchySourceError('That file is larger than '.(self::MAX_UPLOAD_KB / 1024).' MB.');
            }

            $ext = strtolower((string) $file->getClientOriginalExtension());

            if (! in_array($ext, ['json', 'csv', 'txt'], true)) {
                throw new HierarchySourceError('Upload the .json saved from the browser or the categories .csv export.');
            }

            foreach ($this->parseText((string) file_get_contents($file->getRealPath())) as $node) {
                $nodes[] = $node;
            }

            $names[] = mb_substr(basename((string) $file->getClientOriginalName()), 0, 80);
        }

        return ['nodes' => $this->finish($nodes), 'source' => 'upload: '.implode(', ', $names)];
    }

    /**
     * JSON when it looks like JSON, CSV otherwise.
     *
     * @return list<array{id:int, slug:string, name:string, parent:int}>
     *
     * @throws HierarchySourceError
     */
    public function parseText(string $body): array
    {
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body;
        $trimmed = ltrim($body);

        if ($trimmed === '') {
            throw new HierarchySourceError('That file is empty.');
        }

        if ($trimmed[0] === '[' || $trimmed[0] === '{') {
            $data = json_decode($trimmed, true, 16);

            if (! is_array($data)) {
                throw new HierarchySourceError('That file is not valid JSON.');
            }

            return $this->finish($this->fromJsonList($data));
        }

        return $this->finish($this->fromCsv($body));
    }

    /**
     * @param  array<string, string>  $query
     * @return list<array{id:int, slug:string, name:string, parent:int}>
     *
     * @throws HierarchySourceError
     */
    private function fetchAll(string $host, string $path, array $query): array
    {
        $nodes = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            [$list, $totalPages] = $this->fetchPage($host, $path, $query + ['per_page' => self::PER_PAGE, 'page' => $page]);

            foreach ($this->fromJsonList($list) as $node) {
                $nodes[] = $node;
            }

            if (count($nodes) > self::MAX_NODES) {
                throw new HierarchySourceError('The site lists more than '.self::MAX_NODES.' categories.');
            }

            // X-WP-TotalPages when the site sends it; otherwise a short page
            // is the last one.
            if ($list === [] || ($totalPages !== null ? $page >= $totalPages : count($list) < self::PER_PAGE)) {
                return $this->finish($nodes);
            }
        }

        throw new HierarchySourceError('The site kept answering past '.self::MAX_PAGES.' pages.');
    }

    /**
     * @return array{0: array<mixed>, 1: int|null}
     *
     * @throws HierarchySourceError
     */
    private function fetchPage(string $host, string $path, array $query): array
    {
        $url = 'https://'.$host.$path;

        for ($hop = 0; $hop < 2; $hop++) {
            try {
                $response = Http::withoutRedirecting()
                    ->connectTimeout(5)
                    ->timeout(12)
                    ->acceptJson()
                    ->withHeaders(['User-Agent' => 'KBB-Storefront/hierarchy-copy'])
                    ->get($url, $query);
            } catch (ConnectionException) {
                throw new HierarchySourceError('This server could not reach '.$host.' (timed out or refused).');
            } catch (\Throwable) {
                throw new HierarchySourceError('This server could not reach '.$host.'.');
            }

            if ($response->redirect()) {
                $next = (string) $response->header('Location');
                $parts = parse_url($next);
                $nextHost = strtolower((string) ($parts['host'] ?? ''));

                if (($parts['scheme'] ?? '') !== 'https'
                    || ! in_array($nextHost, [$host, 'www.'.$host, preg_replace('/^www\./', '', $host)], true)) {
                    throw new HierarchySourceError($host.' redirected somewhere this screen will not follow.');
                }

                $host = $nextHost;
                $url = 'https://'.$host.(string) ($parts['path'] ?? $path);
                continue;
            }

            // WordPress answers 400 rest_post_invalid_page_number past the end.
            if ($response->status() === 400 && ($query['page'] ?? 1) > 1) {
                return [[], null];
            }

            if (! $response->successful()) {
                throw new HierarchySourceError($host.' answered HTTP '.$response->status().'.');
            }

            if (strlen($response->body()) > self::MAX_UPLOAD_KB * 1024) {
                throw new HierarchySourceError($host.' sent a page larger than expected.');
            }

            $data = json_decode($response->body(), true, 16);

            if (! is_array($data) || ! array_is_list($data)) {
                throw new HierarchySourceError($host.' did not answer with a category list (not JSON).');
            }

            $total = $response->header('X-WP-TotalPages');
            $total = ctype_digit((string) $total) ? max(1, (int) $total) : null;

            return [$data, $total];
        }

        throw new HierarchySourceError($host.' redirected too many times.');
    }

    /**
     * @param  array<mixed>  $data
     * @return list<array{id:int, slug:string, name:string, parent:int}>
     *
     * @throws HierarchySourceError
     */
    private function fromJsonList(array $data): array
    {
        if (! array_is_list($data)) {
            throw new HierarchySourceError('Expected a list of categories, as /wp-json/wp/v2/product_cat returns.');
        }

        $out = [];

        foreach ($data as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = self::int($item['id'] ?? null);
            $slug = self::str($item['slug'] ?? null);

            if ($id === null || $id === 0 || $slug === '') {
                continue;
            }

            $out[] = [
                'id' => $id,
                'slug' => $slug,
                'name' => self::str($item['name'] ?? null),
                'parent' => self::int($item['parent'] ?? 0) ?? 0,
            ];
        }

        return $out;
    }

    /**
     * A categories CSV: term_id/id, name, slug, parent. `parent` may be the
     * parent's term id (this repo's exporter, WooCommerce tools) or its slug
     * or name (WP All Export and friends).
     *
     * @return list<array{id:int, slug:string, name:string, parent:int}>
     *
     * @throws HierarchySourceError
     */
    private function fromCsv(string $body): array
    {
        // fgetcsv over a stream, not a split on newlines: a description cell
        // in a WooCommerce export carries line breaks inside its quotes.
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $body);
        rewind($stream);
        $header = null;
        $rows = [];

        while (($cells = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            if ($cells === [null] || implode('', array_map('strval', $cells)) === '') {
                continue;
            }

            if ($header === null) {
                $header = array_map(static fn ($h) => strtolower(trim((string) $h)), $cells);
                continue;
            }

            $rows[] = $cells;

            if (count($rows) > self::MAX_NODES) {
                fclose($stream);
                throw new HierarchySourceError('That file lists more than '.self::MAX_NODES.' categories.');
            }
        }

        fclose($stream);

        if ($header === null) {
            throw new HierarchySourceError('That CSV has no header row.');
        }

        $col = static function (array $names) use ($header): ?int {
            foreach ($names as $n) {
                $i = array_search($n, $header, true);
                if ($i !== false) {
                    return (int) $i;
                }
            }

            return null;
        };

        $iId = $col(['term_id', 'id', 'term id']);
        $iSlug = $col(['slug', 'category slug', 'term slug']);
        $iName = $col(['name', 'category name', 'term name', 'title']);
        $iParent = $col(['parent', 'parent_id', 'parent_term_id', 'parent slug', 'parent_slug', 'parent name', 'parent category']);

        if ($iSlug === null || $iParent === null) {
            throw new HierarchySourceError('That CSV needs a "slug" column and a "parent" column.');
        }

        $out = [];
        $bySlug = [];
        $byName = [];
        $next = 1_000_000_000;

        foreach ($rows as $cells) {
            $slug = self::str($cells[$iSlug] ?? null);

            if ($slug === '') {
                continue;
            }

            $id = $iId !== null ? self::int($cells[$iId] ?? null) : null;
            $id = $id ?: $next++;
            $out[] = [
                'id' => $id,
                'slug' => $slug,
                'name' => $iName !== null ? self::str($cells[$iName] ?? null) : '',
                'parent' => 0,
                'parentRaw' => self::str($cells[$iParent] ?? null),
            ];
            $bySlug[self::key($slug)] ??= $id;
            $byName[mb_strtolower(TermName::plain($out[array_key_last($out)]['name']))] ??= $id;
        }

        foreach ($out as &$node) {
            $raw = $node['parentRaw'];
            unset($node['parentRaw']);

            if ($raw === '' || $raw === '0') {
                continue;
            }

            $node['parent'] = ctype_digit($raw)
                ? (int) $raw
                : ($bySlug[self::key($raw)] ?? $byName[mb_strtolower(TermName::plain($raw))] ?? -1);
        }
        unset($node);

        return $out;
    }

    /**
     * One node per id; names decoded.
     *
     * @param  list<array{id:int, slug:string, name:string, parent:int}>  $nodes
     * @return list<array{id:int, slug:string, name:string, parent:int}>
     *
     * @throws HierarchySourceError
     */
    private function finish(array $nodes): array
    {
        if (count($nodes) > self::MAX_NODES) {
            throw new HierarchySourceError('More than '.self::MAX_NODES.' categories.');
        }

        $seen = [];
        $out = [];

        foreach ($nodes as $n) {
            if (isset($seen[$n['id']])) {
                continue;
            }
            $seen[$n['id']] = true;
            $n['name'] = mb_substr(TermName::plain($n['name']), 0, self::MAX_FIELD);
            $out[] = $n;
        }

        if ($out === []) {
            throw new HierarchySourceError('No categories were found in that list.');
        }

        return $out;
    }

    /** Slug comparison key: WordPress percent-encodes non-ASCII slugs. */
    public static function key(string $slug): string
    {
        return mb_strtolower(rawurldecode(trim($slug)));
    }

    private static function int(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v >= 0 && $v <= 2_147_483_647 ? $v : null;
        }

        if (is_string($v) && preg_match('/^\d{1,10}$/', trim($v)) === 1) {
            $i = (int) trim($v);

            return $i <= 2_147_483_647 ? $i : null;
        }

        return null;
    }

    private static function str(mixed $v): string
    {
        if (! is_string($v) && ! is_int($v)) {
            return '';
        }

        $s = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $v) ?? '');

        return mb_substr($s, 0, self::MAX_FIELD);
    }
}
