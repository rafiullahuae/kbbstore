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
 * So the edit is made on the TEXT. `<img …>` and `<a …>` tags are found, the
 * `src` or `href` value inside each one is compared against the address being
 * re-pointed, and only that run of characters is replaced. Everything else in
 * the article — the whitespace, the entities, the attribute order, the tags
 * this class does not understand — is untouched because it is never rebuilt.
 *
 * `<a href>` IS READ AS WELL AS `<img src>`, and `sources()` sets out exactly
 * which anchors are taken and which two kinds are deliberately left alone. In
 * one line: an anchor that names a FILE under an uploads root is the same
 * migration defect as the `<img>` and is fixed the same way; an anchor that
 * names a PAGE is a redirect question and belongs to `RedirectMap`.
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
 *   2. THE MATCH IS BY WHOLE VALUE. A `src` or `href` is replaced only when it
 *      is byte-identical to the address in the proposal, never by substring. A
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
     * The tags that carry a media address, and the attribute each one uses.
     *
     * ONE LIST, read by `tags()`, `attributeOf()` and `addresses()`. It is the
     * whole definition of "an address in a document" for this application, and
     * `MediaAudit` reaches it through `sources()` rather than keeping its own.
     *
     * =========================================================================
     * `srcset` IS NOT HERE, AND THE REASON IS NOT "WE DID NOT GET TO IT"
     * =========================================================================
     *
     * `<img srcset>` and `<source srcset>` are deliberately absent, and Lane U3
     * went and checked the claim this comment used to make rather than
     * repeating it, because it was wrong in a way that mattered.
     *
     * The edit is genuinely different in kind. A `srcset` is a COMMA-SEPARATED
     * LIST of an address plus a descriptor — `a-300.jpg 300w, a-600.jpg 2x` —
     * so re-pointing one is not replacing an attribute's whole value. It needs
     * its own parser, and its own idempotency argument, because the two
     * properties that make this class safe do not carry over unexamined: the
     * whole-value match becomes a per-candidate match, and a candidate address
     * may legally contain a comma, which is the classic way a naive split
     * corrupts the list. `ImageVariants` makes the same observation about
     * commas from the other side.
     *
     * WHAT WOULD MAKE IT A REAL GAP IS AN `<img>` CARRYING BOTH. The `src`
     * would be re-pointed, the `srcset` left naming the old host, and the audit
     * — which reads through `sources()`, this same list — would report REMOTE
     * as zero while every retina reader still loaded the pictures from a site
     * about to be switched off. That is precisely the failure the `<a href>`
     * work above was done to close, so "we simply do not handle it" would not
     * be an answer.
     *
     * IT CANNOT ARISE, BECAUSE THE ATTRIBUTE NEVER REACHES THE COLUMN.
     * `RichText::ALLOWED['img']` is `src, alt, width, height, class, loading`,
     * and `attributes()` REMOVES every attribute not on that list. `picture`
     * and `source` are not allowed elements at all. Both doors into
     * `posts.body` go through that one call — `PostImporter::settleBody()` on
     * import, `PostEditorApiController` on a hand-written or edited article —
     * so a body in this database cannot carry a `srcset` to leave behind.
     *
     * So the decision is: not parsed, because nothing gets here to parse. If
     * `RichText` ever allows the attribute, this stops being true the same day
     * and this class has a real gap again — which is why
     * `tests/Feature/ImportJournalSrcsetTest.php` asserts the stripping at both
     * doors and names this comment. That test failing IS the notice.
     *
     * @var array<string, string>
     */
    private const TAGS = [
        'img' => 'src',
        'a' => 'href',
    ];

    /**
     * Every media address a document points at, in the order they appear.
     *
     * THE ONE PARSER. `MediaAudit` calls this to decide what is still served by
     * the old host, and this class calls it to decide what to re-point. Two
     * implementations would be two answers to "which pictures does this article
     * use", and the failure would be silent in the worst direction: an address
     * the audit cannot see is a file the sideloader never fetches, so the
     * rewrite that depends on it reports ABSENT for ever.
     *
     * =========================================================================
     * `<a href>` IS IN HERE NOW, AND WHICH ANCHORS ARE NOT
     * =========================================================================
     *
     * WordPress writes one every time a thumbnail links to its full-size image:
     * `<a href="…/2021/a.jpg"><img src="…/2021/a-300x200.jpg"></a>`. The two
     * addresses are DIFFERENT FILES, and while only the `<img>` was read the
     * full-size one was never audited, never fetched and never re-pointed — so
     * "remote → 0" was reached with every one of those anchors still hot-linked
     * to a host about to go dark, and the picture a reader gets by clicking was
     * the last thing on the shop still served by WordPress.
     *
     * That is the same migration defect as the `<img>` and it is fixed the same
     * way. The objection this lane raised in round 1 — "rewriting anchors
     * changes link behaviour" — is answered by WHICH anchors are taken, not by
     * leaving them all alone. TWO KINDS ARE DELIBERATELY NOT TAKEN:
     *
     *   1. AN ANCHOR THAT IS NOT AN UPLOADS ADDRESS AT ALL. `<a href>` to a
     *      page on the old site — an article, a category, the home page — is a
     *      redirect question, not a media one. It belongs to `RedirectMap`,
     *      whose answer is a row in `redirects` the owner approves, and
     *      rewriting it here would silently make that decision for him with no
     *      row to show for it and no way to take it back. `uploadsRelativeTo()`
     *      returning null is exactly that test.
     *
     *   2. AN UPLOADS ADDRESS THAT DOES NOT NAME A FILE.
     *      `…/wp-content/uploads/2021/` is a directory listing, and
     *      `/blog/uploads/something/` matches the uploads root by accident
     *      because the cut is made on a substring. Neither is a file this shop
     *      can serve, so neither is a picture — and counting them would put
     *      page addresses into the REMOTE number the whole migration is judged
     *      by. The last path segment must carry an extension. `<img>` needs no
     *      such rule: a `src` names a file or it is a broken frame either way.
     *
     * Everything else about an anchor is the `<img>` rule unchanged, and the
     * two guards that matter apply to both: nothing is rewritten unless the
     * file is already on disk, and the match is on the whole value so a second
     * pass cannot double a path.
     *
     * WHAT IS DROPPED, for an anchor exactly as for an `<img>`: a query string
     * and a fragment. `…/a.pdf?ver=3` is re-pointed to `/wp-content/uploads/…/
     * a.pdf`, because `uploadsRelativeTo()` goes through
     * `MediaUsage::normalise()`, which cuts both. On a static file under the
     * web root a cache-buster is the only thing either can be.
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
        $out = [];

        foreach (self::addresses($html) as $address) {
            if (! in_array($address['url'], $out, true)) {
                $out[] = $address['url'];
            }
        }

        return $out;
    }

    /**
     * The same addresses, each with the attribute it was read from.
     *
     * Separate from `sources()` only because the audit wants to print "this is
     * a link, not a picture" and the rewrite does not care. Duplicates are kept
     * here — the same file linked twice is two places in the document — and
     * collapsed by `sources()`.
     *
     * @return list<array{url: string, tag: string, attribute: string}>
     */
    public static function addresses(?string $html): array
    {
        if (! is_string($html) || $html === '') {
            return [];
        }

        $out = [];

        foreach (self::tags($html) as $tag) {
            $found = self::attributeOf($tag);

            if ($found === null || trim($found['value']) === '') {
                continue;
            }

            $url = trim(html_entity_decode($found['value'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($url === '' || ! self::carried($tag['name'], $url)) {
                continue;
            }

            $out[] = ['url' => $url, 'tag' => $tag['name'], 'attribute' => self::TAGS[$tag['name']]];
        }

        return $out;
    }

    /**
     * Is this address one this class claims, for the tag it was found on?
     *
     * `<img src>` is claimed unconditionally: whatever it names, it is the
     * document asking for a picture, and `MediaAudit` has to see it even when
     * it is on a CDN this class will never rewrite.
     *
     * `<a href>` is claimed only when it names a FILE under an uploads root —
     * the two carve-outs `sources()` sets out above, and the whole difference
     * between re-pointing a picture and quietly re-routing a link.
     */
    private static function carried(string $tag, string $url): bool
    {
        if ($tag === 'img') {
            return true;
        }

        $relative = MediaRewrite::uploadsRelativeTo($url);

        if ($relative === null) {
            return false;
        }

        return preg_match('/\.[A-Za-z0-9]{1,8}$/', $relative) === 1;
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
            foreach (self::carriers($document['html']) as $url => $tags) {
                $host = parse_url($url, PHP_URL_HOST);

                if (! is_string($host) || ! in_array(strtolower($host), $hosts, true)) {
                    continue;
                }

                $relative = MediaRewrite::uploadsRelativeTo($url);

                if ($relative === null) {
                    continue;
                }

                $to = Media::urlFor($relative);

                /*
                 * WHICH TAGS POINT AT IT, said on the row rather than worked
                 * out again by whoever reads the list. "A picture" and "a link
                 * to a file" are the same defect and the same fix, but they are
                 * not the same sentence to somebody deciding whether to press
                 * the button, and the screen prints this.
                 */
                $written = self::wording($tags);

                $row = [
                    'owner_type' => $document['model'],
                    'table' => $document['table'],
                    'id' => $document['id'],
                    'field' => $document['field'],
                    'from' => $url,
                    'to' => $to,
                    'path' => $relative,
                    'occurrences' => self::countOf($document['html'], $url),
                    'tags' => $tags,
                    'carried_by' => $written,
                ];

                if (! is_file(public_path($relative))) {
                    $out[] = $row + [
                        'decision' => self::ABSENT,
                        'reason' => 'nothing at '.public_path($relative).' yet. Copy wp-content/uploads across, or '
                            .'let the picture fetch finish, before this article stops depending on '.$host.'; '
                            .'re-pointing the '.$written.' first would turn something that works into something '
                            .'that does not.',
                    ];

                    continue;
                }

                $out[] = $row + [
                    'decision' => $to === $url ? self::SAME : self::REWRITE,
                    'reason' => $to === $url
                        ? 'already exactly what this would write'
                        : 'the file is under this shop\'s web root, so the '.$written.' can stop depending on '
                            .$host,
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
            $src = self::attributeOf($tag);

            if ($src === null) {
                continue;
            }

            $url = trim(html_entity_decode($src['value'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            /*
             * THE MAP IS THE ALLOWLIST, which is why there is no second copy of
             * the anchor rules here. Every key in it came out of `propose()`,
             * which only ever offers an address `addresses()` claimed — so an
             * `<a href>` to a page, or to an uploads directory, is absent from
             * the map and cannot be matched however this loop is called.
             */
            if (! array_key_exists($url, $map)) {
                continue;
            }

            // Absolute offsets of the attribute VALUE inside the document.
            $from = $offset + $src['at'];
            $length = strlen($src['value']);

            $written = htmlspecialchars($map[$url], ENT_QUOTES | ENT_HTML5, 'UTF-8', false);

            /*
             * AN UNQUOTED ATTRIBUTE IS QUOTED IF THE NEW VALUE NEEDS IT.
             *
             * `<img src=https://old/wp-content/uploads/a%20b.jpg>` is legal
             * HTML — and so is the same thing on an `<a href>` — and
             * `MediaUsage::normalise()` rawurldecodes, so the path
             * this writes back has a real space in it, and written unquoted the
             * space ENDS the attribute: `b.jpg` becomes a second attribute and
             * the picture is a broken frame. htmlspecialchars does not escape
             * whitespace and should not.
             *
             * Not an injection — the quote, angle bracket and ampersand are all
             * escaped above, so nothing can break out of the tag — but it is a
             * document this class would have corrupted, which is the one thing
             * a surgical rewriter must not do. Adding the quotes is valid HTML
             * and changes only the attribute it was already rewriting.
             */
            if ($src['quote'] === '' && preg_match('/[\s>"\'=`]/', $written) === 1) {
                $written = '"'.$written.'"';
            }

            $out .= substr($html, $cursor, $from - $cursor).$written;

            $cursor = $from + $length;
        }

        return $out.substr($html, $cursor);
    }

    /**
     * Every tag this class reads, keyed by its byte offset in the document.
     *
     * `<img>` and `<a>`, and the attribute each one carries its address in is
     * the constant above rather than a second regular expression per tag — two
     * lists of "which tags mean a picture" is how the audit and the rewrite
     * start disagreeing.
     *
     * A `>` INSIDE AN ATTRIBUTE VALUE ends a match early here, because the tag
     * is cut with `[^>]*`. That was already true of `<img>` and is left alone:
     * the failure is a tag this class does not recognise, so the address is
     * neither audited nor rewritten — it is skipped, never corrupted, which is
     * the only direction a surgical rewriter is allowed to be wrong in.
     *
     * @return array<int, array{name: string, text: string}>
     */
    private static function tags(string $html): array
    {
        $names = implode('|', array_keys(self::TAGS));

        if (preg_match_all('/<('.$names.')\b[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        $out = [];

        foreach ($matches[0] as $index => $match) {
            $out[(int) $match[1]] = [
                'name' => strtolower((string) $matches[1][$index][0]),
                'text' => (string) $match[0],
            ];
        }

        return $out;
    }

    /**
     * The address attribute of one tag: its raw value and where in the tag it
     * is.
     *
     * `src` on an `<img>`, `href` on an `<a>` — and never both, because a tag
     * carrying the other one is not carrying an address this class understands.
     *
     * @param  array{name: string, text: string}  $tag
     * @return array{value: string, at: int, quote: string}|null
     */
    private static function attributeOf(array $tag): ?array
    {
        $attribute = self::TAGS[$tag['name']] ?? null;

        if ($attribute === null) {
            return null;
        }

        $pattern = '/\s'.$attribute.'\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i';

        if (preg_match($pattern, $tag['text'], $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        foreach ([1 => '"', 2 => "'", 3 => ''] as $group => $quote) {
            if (isset($m[$group]) && $m[$group][1] >= 0) {
                return ['value' => (string) $m[$group][0], 'at' => (int) $m[$group][1], 'quote' => $quote];
            }
        }

        return null;
    }

    /**
     * How many places in this document carry exactly this address.
     *
     * Counted over `addresses()` and not over the raw tags, so an `<a href>`
     * this class does not claim is not counted as an occurrence of a file it
     * would then refuse to rewrite.
     */
    private static function countOf(string $html, string $url): int
    {
        $n = 0;

        foreach (self::addresses($html) as $address) {
            if ($address['url'] === $url) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Each distinct address in a document, and the tags that point at it.
     *
     * One row per (document, address) — the same photograph appearing three
     * times in one article is ONE decision, and a list that reported it three
     * times would be a list the owner reads as three problems. The tag names
     * come along so the row can say whether it is a picture, a link, or the
     * WordPress thumbnail-to-full-size pair that is both.
     *
     * @return array<string, list<string>>
     */
    private static function carriers(string $html): array
    {
        $out = [];

        foreach (self::addresses($html) as $address) {
            $out[$address['url']] ??= [];

            if (! in_array($address['tag'], $out[$address['url']], true)) {
                $out[$address['url']][] = $address['tag'];
            }
        }

        return $out;
    }

    /**
     * What to call a set of tags in a sentence the owner reads.
     *
     * @param  list<string>  $tags
     */
    private static function wording(array $tags): string
    {
        $picture = in_array('img', $tags, true);
        $link = in_array('a', $tags, true);

        if ($picture && $link) {
            return 'picture and the link to it';
        }

        return $picture ? 'picture' : 'link to this file';
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
