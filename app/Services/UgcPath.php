<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The two shapes a shoppable-video row may hold that end up in an attribute.
 *
 * §7 of docs/UGC-VIDEO-PLAN.md: `source_url` and `creator_url` become an
 * `href`, `poster_path` becomes a `src`, and `file_path` becomes a
 * `<video src>`. Every one of them is typed in by an operator, and /api/* on
 * this shop is unauthenticated, so what is stored is what an anonymous visitor
 * may one day be served.
 *
 * ── link(): DECODE, STRIP, THEN READ THE SCHEME ─────────────────────────────
 *
 * The decode is the whole check and it is the step a pattern match skips. A
 * browser resolves `jav&#x09;ascript:alert(1)` to a javascript URL; a
 * `str_starts_with($url, 'javascript:')` sees a string beginning "jav&#x09;"
 * and waves it through. So entities are decoded first, every whitespace and
 * C0/C1 control character is removed, and only then is the scheme read — the
 * check runs on the same string the browser will.
 *
 * App\Support\RichText::url() is the original of this and its docblock carries
 * the argument. It is `private` there and RichText is another lane's file, so
 * this is the same algorithm rather than a call into it; UgcUrlSafetyTest
 * drives both with the same vectors so the two cannot drift apart silently.
 *
 * The scheme list here is SHORTER than RichText's on purpose: `mailto:` is a
 * legitimate thing to write in prose and a nonsensical thing to file as the
 * original post of a video.
 *
 * AND ONE HONEST NOTE, because a mutation run says so rather than a reading of
 * the code. link() refuses a URL with NO scheme at all — see the comment at the
 * foot of the method — and that refusal, not the decode, is what turns away
 * every entity-encoded and whitespace-split vector: `jav&#x09;ascript:` does
 * not match `^[a-z][a-z0-9+.-]*:` in the first place. Removing the decode
 * leaves UgcUrlSafetyTest entirely green. It is kept as the SECOND lock, for
 * the day somebody relaxes the relative-URL rule to allow a link into this
 * shop — RichText allows relative URLs, which is exactly why the decode is
 * load-bearing there and belt and braces here. The same is true of the
 * protocol-relative guard below.
 *
 * ── stored(): AN ALLOWLIST OF SHAPES, NOT A DENYLIST OF TRICKS ──────────────
 *
 * ReviewWall::photos() is the precedent and its comment names the trap:
 * Url::to() passes any scheme through untouched, so the check has to happen
 * BEFORE a path becomes a URL, not inside the thing that builds one. A stored
 * media path is therefore not "a string that does not look dangerous" — it is
 * exactly `/uploads/ugc/<one segment>` and nothing else. No traversal, no
 * second directory, no scheme, no protocol-relative host, no backslash.
 *
 * Neither function trusts the database. A column is only ever as trustworthy as
 * everything that has ever written to it, and these are read on the way OUT as
 * well as checked on the way in.
 */
final class UgcPath
{
    /** What may become an href. */
    private const SAFE_SCHEMES = ['http', 'https'];

    /** Where a stored media file may live, with no leading slash. */
    private const ROOT = 'uploads/ugc/';

    /**
     * An operator-typed URL the page may point at, or null.
     *
     * Returns the ORIGINAL string when it passes, not the decoded probe: the
     * probe is stripped of characters that are meaningful in a query string,
     * and storing it would change the address. The probe decides; the original
     * is stored.
     */
    public static function link(?string $raw): ?string
    {
        $url = trim((string) $raw);

        if ($url === '') {
            return null;
        }

        $probe = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $probe = (string) preg_replace('/[\s\x00-\x1F\x7F-\x9F]+/u', '', $probe);
        $probe = strtolower($probe);

        /*
         * A protocol-relative `//evil.test` carries no scheme and is NOT a
         * relative path: a browser reads it as "same scheme, that host". It is
         * refused before the scheme test, which would otherwise never see it.
         */
        if (str_starts_with($probe, '//')) {
            return null;
        }

        if (preg_match('/^([a-z][a-z0-9+.\-]*):/', $probe, $m) === 1) {
            return in_array($m[1], self::SAFE_SCHEMES, true) ? $url : null;
        }

        /*
         * No scheme at all. RichText keeps these — a relative href in prose is
         * ordinary and safe. Here it is refused: every field this guards is
         * "the original post", which is always somewhere else. A relative
         * `creator_url` would point at this shop, which is never what was
         * meant and is how an operator ends up linking a creator to a 404.
         */
        return null;
    }

    /**
     * A stored media path, exactly as this shop wrote it, or null.
     *
     * Accepts `/uploads/ugc/<name>` and nothing else. The name itself is bounded
     * to the alphabet UgcMedia generates, so a row rewritten by hand to point at
     * another file in the same directory — a customer invoice, say, if one ever
     * lands there — still has to look like something this class made.
     */
    public static function stored(?string $raw): ?string
    {
        $path = trim((string) $raw);

        if ($path === '') {
            return null;
        }

        // Backslashes first: Windows separators, and a segment splitter some
        // path readers honour and a regexp written for '/' does not.
        if (str_contains($path, '\\') || str_contains($path, "\0")) {
            return null;
        }

        if (! str_starts_with($path, '/'.self::ROOT)) {
            return null;
        }

        $name = substr($path, strlen('/'.self::ROOT));

        /*
         * One segment, from the alphabet UgcMedia::store() generates. `..` is
         * unrepresentable in it, and so is a second slash, so traversal is not
         * refused by a rule that has to anticipate its spellings — it simply
         * cannot be written.
         */
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,120}\.(?:mp4|webm|jpg|jpeg|png|webp)$/', $name) === 1
            ? $path
            : null;
    }

    /**
     * A path to a file the Media Library already holds, or null.
     *
     * Wider than stored() by exactly one thing: the folder. The library writes
     * under /uploads/<folder>/<name> — seo, products, brands, categories and
     * whatever the next screen invents — so this accepts any one of those, and
     * nothing else.
     *
     * It is a SEPARATE function rather than a parameter on stored(), because
     * the two answer different questions and only one of them is allowed to be
     * loose. stored() decides what a COLUMN may hold and what this code may
     * delete; this decides what may be COPIED IN, once, from a picker the
     * operator just used. Widening stored() to this would mean a path column
     * that can name any file in the web root, which is the shape
     * ReviewWall::photos() exists to refuse.
     *
     * A full URL is accepted and reduced to its path, because that is what
     * window.kbbPickMedia hands back — Media::urlFor() prefixes site_url. The
     * host is not checked and does not need to be: only the path survives, and
     * the path is then resolved under public_path() on this server.
     */
    public static function library(?string $raw): ?string
    {
        $value = trim((string) $raw);

        if ($value === '' || str_contains($value, "\0") || str_contains($value, '\\')) {
            return null;
        }

        // A URL from the picker, reduced to its path. parse_url returns false
        // for a seriously malformed string, which is a refusal.
        if (preg_match('#^https?://#i', $value) === 1) {
            $path = parse_url($value, PHP_URL_PATH);

            if (! is_string($path) || $path === '') {
                return null;
            }

            $value = $path;
        }

        $value = rawurldecode($value);

        /*
         * Decoded FIRST, then matched. %2e%2e%2f is `../` by the time the
         * filesystem sees it, so a check run before the decode is a check run
         * on a different string — the same trap link() decodes entities for.
         *
         * A mutation run says it is currently a SECOND lock rather than the
         * lock: `%` is outside the accepted alphabet for both segments below,
         * so an encoded traversal is refused by the shape rule whether or not
         * this line exists. It is the line that still holds if that alphabet is
         * ever widened, which is why it is here and in this order.
         */
        if (str_contains($value, "\0") || str_contains($value, '\\')) {
            return null;
        }

        /*
         * Exactly /uploads/<folder>/<name>, both segments from a bounded
         * alphabet with no dots in the folder, so `..` cannot be either of them,
         * and the name ending in an image extension.
         *
         * THE EXTENSION LIST IS NOT THE SECURITY — UgcMedia::adopt() reads the
         * bytes, and a `.jpg` full of PHP is refused there whatever this says.
         * It is here because the only thing this function is used for is a
         * POSTER, and refusing `/uploads/seo/shell.php` at the door rather than
         * opening it and sniffing it is both cheaper and one fewer place a
         * hostile path reaches. GIF is accepted here and refused by adopt(): the
         * library holds them, so the picker can offer one, and the refusal then
         * names the real reason rather than pretending the file is not there.
         * The site may live under a base path — /kbb-upgrade/uploads/... — so
         * anything before /uploads/ is dropped rather than refused, and what is
         * kept is the root-relative path public_path() will be joined with.
         */
        if (preg_match('#(?:^|/)uploads/([a-z0-9][a-z0-9-]{0,39})/([A-Za-z0-9][A-Za-z0-9._-]{0,120}\.(?:jpe?g|png|webp|gif))$#i', $value, $m) !== 1) {
            return null;
        }

        return '/uploads/'.$m[1].'/'.$m[2];
    }
}
