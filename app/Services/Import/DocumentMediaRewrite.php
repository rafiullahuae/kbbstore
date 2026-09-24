<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Media;
use App\Models\Post;
use Illuminate\Support\Facades\DB;

/**
 * The same re-pointing `MediaRewrite` does to CELLS, done to DOCUMENTS.
 *
 * =============================================================================
 * WHY A SECOND CLASS AND NOT FOUR MORE ROWS IN MediaRewrite::COLUMNS
 * =============================================================================
 *
 * `MediaRewrite` re-points a column whose whole value IS one image address:
 * `products.image` holds a URL and nothing else, so "rewrite it" means "assign
 * a different string". `posts.body` holds an ARTICLE — kilobytes of HTML with
 * an unknown number of `<img>` tags buried in it, each with its own address —
 * so the same operation there is not an assignment. It is a search and replace
 * inside a document, and the document has to come back out byte for byte
 * identical apart from the addresses.
 *
 * That difference is the whole reason this is its own class. `posts.cover` IS a
 * cell and belongs in `MediaRewrite::COLUMNS`, where it now is; `posts.body`
 * is not and never was.
 *
 * =============================================================================
 * SURGICAL, NOT RE-SERIALISED. THIS DOES NOT PARSE THE DOCUMENT WITH DOMDocument
 * =============================================================================
 *
 * The obvious implementation is `DOMDocument`: load the body, walk the `img`
 * elements, set `src`, save. It is also wrong here, and not marginally.
 * `saveHTML()` re-writes the whole document — it closes tags it thinks are
 * open, re-orders nothing but re-quotes everything, adds `<p>` where the parser
 * inferred one, and turns every non-ASCII character into an entity. An Arabic
 * article would come back a different document in every byte, for a change to
 * one attribute. `StorefrontEnglishUnchangedTest` is the instrument that says
 * so about the storefront, and the same rule applies one level down: a rewrite
 * that touches what it was not asked to touch cannot be reviewed.
 *
 * So the edit is made on the TEXT. `<img …>` tags are found, the `src`
 * attribute's value inside each one is compared against the address being
 * re-pointed, and only that run of characters is replaced. Everything else in
 * the article — the whitespace, the entities, the attribute order, the tags
 * this class does not understand — is untouched because it is never rebuilt.
 *
 * `sources()` IS SHARED WITH `MediaAudit`, deliberately. The audit has to see
 * the same addresses this rewrites or the sideloader never fetches the files
 * and every proposal here comes back ABSENT. One parser, two callers, no
 * second opinion — the rule `MediaUsage::matches()` already sets for the media
 * library.
 *
 * =============================================================================
 * IDEMPOTENT, BECAUSE THE IMPORT IS
 * =============================================================================
 *
 * `PostImporter` matches on `posts.source_post_id` and re-presents every row on
 * every run, so an article's body is written again each time the owner imports
 * — which is exactly the situation in which a "rewrite the pictures" pass that
 * is not idempotent produces `/wp-content/uploads/wp-content/uploads/…` on the
 * second pass and nobody notices until the page is a wall of broken frames.
 *
 * Two properties make that impossible here, and neither is a flag or a ledger:
 *
 *   1. THE FILTER IS THE HOST. A proposal is only made for an address whose
 *      host is one the owner named. What this writes has NO host, so the second
 *      pass does not see it — the same argument `MediaRewrite`'s header makes
 *      about `propose()` versus `proposeRebase()`.
 *
 *   2. THE MATCH IS BY WHOLE VALUE. A `src` is replaced only when it is
 *      byte-identical to the address in the proposal, never by substring. A
 *      path that already contains `wp-content/uploads/` cannot be matched by a
 *      rule about `https://old-host/wp-content/uploads/`.
 *
 * And, as in `MediaRewrite`, NOTHING IS REWRITTEN UNLESS THE FILE IS ALREADY ON
 * DISK. Re-pointing first and copying afterwards turns an article that renders
 * into an article of broken frames, with no way to tell from the shop which
 * ones were touched.
 */
final class DocumentMediaRewrite
{
    /** Re-using MediaRewrite's vocabulary rather than inventing a second one. */
    public const REWRITE = MediaRewrite::REWRITE;

    public const SAME = MediaRewrite::SAME;

    public const ABSENT = MediaRewrite::ABSENT;

    /**
     * The columns that hold a DOCUMENT with image addresses inside it.
     *
     * One today. `pages.content` is the obvious second and is deliberately not
     * here: the page importer does not run (`PostImporter` refuses a WordPress
     * `page` by name, because this shop ships its own /about/ and which of the
     * two it serves is the owner's decision), so there is no imported page body
     * to re-point and adding one would be a rewrite with nothing to rewrite.
     *
     * @var list<array{0: class-string, 1: string, 2: string}>
     */
    private const DOCUMENTS = [
        [Post::class, 'posts', 'body'],
    ];

    /**
     * Every `<img>` source in a document, in the order they appear, once each.
     *
     * THE ONE PARSER. `MediaAudit` calls this to decide what is still served by
     * the old host, and this class calls it to decide what to re-point. Two
     * implementations would be two answers to "which pictures does this article
     * use", and the failure would be silent in the worst direction: an address
     * the audit cannot see is a file the sideloader never fetches, so the
     * rewrite that depends on it reports ABSENT for ever.
     *
     * ENTITIES ARE DECODED. An imported body has been through
     * `RichText::clean()`, which serialises with DOMDocument and therefore
     * writes `&` as `&amp;` inside an attribute. The catalogue's own idea of
     * that address — and the sideloader's — is the decoded one, so that is what
     * comes back from here. `replace()` re-encodes on the way in, so the
     * document keeps the spelling it had.
     *
     * @return list<string>
     */
    public static function sources(?string $html): array
    {
        if (! is_string($html) || $html === '') {
            return [];
        }

        $out = [];

        foreach (self::tags($html) as $tag) {
            $src = self::srcOf($tag);

            if ($src === null || trim($src['value']) === '') {
                continue;
            }

            $url = trim(html_entity_decode($src['value'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($url !== '' && ! in_array($url, $out, true)) {
                $out[] = $url;
            }
        }

        return $out;
    }

    /**
     * What a rewrite would do to every article body, without doing any of it.
     *
     * One row per (document, address) pair rather than per occurrence: the same
     * photograph appearing three times in one article is one decision, and a
     * list that reported it three times would be a list the owner reads as
     * three problems.
     *
     * @param  list<string>  $hosts  the old site's hosts, named by the owner
     * @return list<array{owner_type: class-string, table: string, id: int, field: string, from: string, to: string, path: string, occurrences: int, decision: string, reason: string}>
     */
    public function propose(array $hosts): array
    {
        $hosts = self::cleanHosts($hosts);

        if ($hosts === []) {
            return [];
        }

        $out = [];

        foreach ($this->documents() as $document) {
            foreach (self::sources($document['html']) as $url) {
                $host = parse_url($url, PHP_URL_HOST);

                if (! is_string($host) || ! in_array(strtolower($host), $hosts, true)) {
                    continue;
                }

                $relative = MediaRewrite::uploadsRelativeTo($url);

                if ($relative === null) {
                    continue;
                }

                $to = Media::urlFor($relative);
                $row = [
                    'owner_type' => $document['model'],
                    'table' => $document['table'],
                    'id' => $document['id'],
                    'field' => $document['field'],
                    'from' => $url,
                    'to' => $to,
                    'path' => $relative,
                    'occurrences' => self::countOf($document['html'], $url),
                ];

                if (! is_file(public_path($relative))) {
                    $out[] = $row + [
                        'decision' => self::ABSENT,
                        'reason' => 'nothing at '.public_path($relative).' yet. Copy wp-content/uploads across, or '
                            .'let the picture fetch finish, before this article stops depending on '.$host.'; '
                            .'re-pointing the tag first would turn a picture that loads into one that does not.',
                    ];

                    continue;
                }

                $out[] = $row + [
                    'decision' => $to === $url ? self::SAME : self::REWRITE,
                    'reason' => $to === $url
                        ? 'already exactly what this would write'
                        : 'the file is under this shop\'s web root, so the <img> can stop depending on '.$host,
                ];
            }
        }

        return $out;
    }

    /**
     * Apply the rewrites in a proposal. Returns how many DOCUMENTS changed.
     *
     * Documents and not addresses, because that is the unit the owner can check:
     * "nine articles were re-pointed" is a sentence he can act on, and "twenty
     * three image tags" is one he cannot without opening every article.
     *
     * One transaction, for `MediaRewrite::apply()`'s reason: a half-applied
     * article is a page with some pictures on one host and some on another and
     * nothing on the shop to say which.
     *
     * @param  list<array{owner_type: class-string, id: int, field: string, from: string, to: string, decision: string}>  $proposals
     */
    public function apply(array $proposals): int
    {
        $changed = 0;

        DB::transaction(function () use ($proposals, &$changed): void {
            foreach ($this->groupByRow($proposals) as $group) {
                /** @var Post|null $row */
                $row = $group['model']::query()->find($group['id']);

                if ($row === null) {
                    continue;
                }

                $before = (string) ($row->{$group['field']} ?? '');
                $after = self::replace($before, $group['map']);

                if ($after === $before) {
                    continue;
                }

                $row->{$group['field']} = $after;
                $row->save();
                $changed++;
            }
        });

        return $changed;
    }

    /**
     * Put a host back in front of every `<img>` this would have re-pointed.
     *
     * The inverse of apply(), and the reason there is no ledger table here
     * either: the transformation drops a scheme and a host and keeps the path
     * byte for byte, so it is exactly invertible. A tag is only restored when
     * its current value is byte-identical to what apply() writes for that path,
     * so an address an admin has since changed by hand is left alone — the same
     * rule `MediaRewrite::restore()` follows.
     *
     * @return array{restored: int, documents: int, kept: list<string>}
     */
    public function restore(string $host): array
    {
        $host = trim($host);
        $restored = 0;
        $documents = 0;
        $kept = [];

        if ($host === '') {
            return ['restored' => 0, 'documents' => 0, 'kept' => []];
        }

        $scheme = str_contains($host, '://') ? '' : 'https://';

        DB::transaction(function () use ($host, $scheme, &$restored, &$documents, &$kept): void {
            foreach ($this->documents() as $document) {
                $map = [];

                foreach (self::sources($document['html']) as $url) {
                    if (parse_url($url, PHP_URL_HOST) !== null) {
                        continue;
                    }

                    $relative = MediaRewrite::uploadsRelativeTo($url);

                    if ($relative === null || Media::urlFor($relative) !== $url) {
                        $kept[] = $document['table'].' '.$document['id'].'.'.$document['field'].' — "'.$url
                            .'" is not the shape this writes, so somebody else set it';

                        continue;
                    }

                    $map[$url] = rtrim($scheme.$host, '/').'/'.$relative;
                }

                if ($map === []) {
                    continue;
                }

                /** @var Post|null $row */
                $row = $document['model']::query()->find($document['id']);

                if ($row === null) {
                    continue;
                }

                $after = self::replace($document['html'], $map);

                if ($after === $document['html']) {
                    continue;
                }

                $row->{$document['field']} = $after;
                $row->save();

                $documents++;
                $restored += count($map);
            }
        });

        return ['restored' => $restored, 'documents' => $documents, 'kept' => $kept];
    }

    /**
     * @param  list<array{decision: string}>  $rows
     * @return array{rewrite: int, same: int, absent: int, documents: int}
     */
    public function summarise(array $rows): array
    {
        $out = [self::REWRITE => 0, self::SAME => 0, self::ABSENT => 0];
        $documents = [];

        foreach ($rows as $row) {
            $out[$row['decision']]++;

            if ($row['decision'] === self::REWRITE) {
                $documents[($row['table'] ?? '').'#'.($row['id'] ?? '')] = true;
            }
        }

        return [
            'rewrite' => $out[self::REWRITE],
            'same' => $out[self::SAME],
            'absent' => $out[self::ABSENT],
            'documents' => count($documents),
        ];
    }

    /**
     * Replace whole `src` values inside `<img>` tags, and nothing else.
     *
     * THE MATCH IS ON THE WHOLE DECODED VALUE, never a substring. A substring
     * replace over a document is how `https://old/wp-content/uploads/x.jpg`
     * inside an `<a href>`, inside a caption, or inside a code sample the
     * article is ABOUT gets silently rewritten too.
     *
     * The replacement is escaped for the quote style the attribute already
     * uses, so an unquoted attribute stays unquoted and a single-quoted one
     * stays single-quoted. In practice the values this writes are paths with
     * nothing to escape; doing it anyway is what makes that true by
     * construction rather than by luck.
     *
     * @param  array<string, string>  $map  old address => new address
     */
    public static function replace(string $html, array $map): string
    {
        if ($map === [] || $html === '') {
            return $html;
        }

        $out = '';
        $cursor = 0;

        foreach (self::tags($html) as $offset => $tag) {
            $src = self::srcOf($tag);

            if ($src === null) {
                continue;
            }

            $url = trim(html_entity_decode($src['value'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if (! array_key_exists($url, $map)) {
                continue;
            }

            // Absolute offsets of the attribute VALUE inside the document.
            $from = $offset + $src['at'];
            $length = strlen($src['value']);

            $out .= substr($html, $cursor, $from - $cursor)
                .htmlspecialchars($map[$url], ENT_QUOTES | ENT_HTML5, 'UTF-8', false);

            $cursor = $from + $length;
        }

        return $out.substr($html, $cursor);
    }

    /**
     * Every `<img …>` tag in the document, keyed by its byte offset.
     *
     * @return array<int, string>
     */
    private static function tags(string $html): array
    {
        if (preg_match_all('/<img\b[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        $out = [];

        foreach ($matches[0] as $match) {
            $out[(int) $match[1]] = (string) $match[0];
        }

        return $out;
    }

    /**
     * The `src` attribute of one tag: its raw value and where in the tag it is.
     *
     * @return array{value: string, at: int}|null
     */
    private static function srcOf(string $tag): ?array
    {
        if (preg_match('/\ssrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $tag, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        foreach ([1, 2, 3] as $group) {
            if (isset($m[$group]) && $m[$group][1] >= 0) {
                return ['value' => (string) $m[$group][0], 'at' => (int) $m[$group][1]];
            }
        }

        return null;
    }

    private static function countOf(string $html, string $url): int
    {
        $n = 0;

        foreach (self::tags($html) as $tag) {
            $src = self::srcOf($tag);

            if ($src !== null && trim(html_entity_decode($src['value'], ENT_QUOTES | ENT_HTML5, 'UTF-8')) === $url) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @param  list<string>  $hosts
     * @return list<string>
     */
    private static function cleanHosts(array $hosts): array
    {
        return array_values(array_filter(
            array_map(static fn ($h): string => strtolower(trim((string) $h)), $hosts),
            static fn (string $h): bool => $h !== '',
        ));
    }

    /**
     * Every document this can rewrite, read one row at a time.
     *
     * `cursor()` and only the two columns, because `posts.body` is a `longText`
     * and an article is kilobytes: a shop with a five-year Journal is megabytes
     * of HTML, and hydrating all of it at once is the kind of memory spike a
     * shared host answers with a blank page.
     *
     * @return iterable<int, array{model: class-string, table: string, id: int, field: string, html: string}>
     */
    private function documents(): iterable
    {
        foreach (self::DOCUMENTS as [$model, $table, $field]) {
            foreach ($model::query()->select(['id', $field])->cursor() as $row) {
                $html = $row->{$field};

                if (! is_string($html) || $html === '') {
                    continue;
                }

                yield [
                    'model' => $model,
                    'table' => $table,
                    'id' => (int) $row->id,
                    'field' => $field,
                    'html' => $html,
                ];
            }
        }
    }

    /**
     * One entry per document, carrying every address to change in it.
     *
     * Grouped so a document with four re-pointed pictures is read, rewritten
     * and saved ONCE. Applying them one at a time would read the body four
     * times and write it four times, and the third write would be against a
     * body the second one had already changed.
     *
     * @param  list<array{owner_type: class-string, id: int, field: string, from: string, to: string, decision: string}>  $proposals
     * @return list<array{model: class-string, id: int, field: string, map: array<string, string>}>
     */
    private function groupByRow(array $proposals): array
    {
        $groups = [];

        foreach ($proposals as $proposal) {
            if ($proposal['decision'] !== self::REWRITE) {
                continue;
            }

            $key = $proposal['owner_type'].'#'.$proposal['id'].'#'.$proposal['field'];

            $groups[$key] ??= [
                'model' => $proposal['owner_type'],
                'id' => (int) $proposal['id'],
                'field' => $proposal['field'],
                'map' => [],
            ];

            $groups[$key]['map'][$proposal['from']] = $proposal['to'];
        }

        return array_values($groups);
    }
}
