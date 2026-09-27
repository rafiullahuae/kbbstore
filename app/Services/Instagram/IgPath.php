<?php

declare(strict_types=1);

namespace App\Services\Instagram;

/**
 * Where a locally cached Instagram thumbnail may live, and nothing else.
 *
 * ── WHY THIS IS NOT UgcPath::stored() ───────────────────────────────────────
 *
 * Because that method's allowlist is `/uploads/ugc/<one segment>` and it is
 * spelled out as a LITERAL ROOT rather than a parameter, deliberately — its
 * docblock makes the argument: "a stored media path is not 'a string that does
 * not look dangerous' — it is exactly `/uploads/ugc/<one segment>` and nothing
 * else." Widening that constant to take a root would weaken the one file in this
 * module whose whole value is that it cannot be talked into a second directory,
 * and UgcPath is the shoppable-video lane's file besides.
 *
 * So this is the same algorithm with its own root, and
 * InstagramSecurityTest drives BOTH with the same vector list so the two cannot
 * drift apart silently — the arrangement UgcUrlSafetyTest already uses to hold
 * UgcPath::link() and RichText::url() together.
 *
 * ── AN ALLOWLIST OF SHAPES, NOT A DENYLIST OF TRICKS ────────────────────────
 *
 * One segment, from an alphabet that cannot spell `..` and cannot contain a
 * second slash, so traversal is not refused by a rule that has to anticipate its
 * spellings — it is unrepresentable. No scheme, no protocol-relative host, no
 * backslash, no NUL.
 *
 * And the check runs on the way OUT as well as on the way in, because a column is
 * only ever as trustworthy as everything that has ever written to it — and this
 * one is written from a filename we derive, on a box whose owner now has a shell.
 *
 * Only IMAGE extensions. This feature never writes a video file: Meta's
 * `media_url` is a signed CDN address that expires, so reels are played through
 * Instagram's own embed and never re-hosted here (docs/IG-PROFILE.md §2). An
 * `.mp4` under this root would mean somebody had started doing that, and it is
 * refused rather than served.
 */
final class IgPath
{
    /** Where a cached thumbnail lives, with no leading slash. */
    public const ROOT = 'uploads/instagram/';

    /**
     * The directory on disk, and it is public_path() rather than a storage disk.
     *
     * UgcMedia::DIR is the precedent — `public_path('uploads/ugc')` — and following
     * it matters more than choosing freshly, because CLAUDE.md records that
     * bootstrap/app.php ends in usePublicPath() pointing at a web root that is a
     * DIFFERENT DIRECTORY from the application root on the live server. A file
     * written through public_path() therefore lands where the web server actually
     * serves from; one written to the `public` storage disk lands under
     * storage/app/public inside the application root and needs a symlink nobody on
     * that host can make. The shoppable-video module already settled this question
     * and it is not being re-opened for a second media type.
     */
    public static function directory(): string
    {
        return public_path(rtrim(self::ROOT, '/'));
    }

    /** The absolute path of a stored thumbnail, or null if the path is not ours. */
    public static function absolute(?string $stored): ?string
    {
        $safe = self::stored($stored);

        return $safe === null ? null : public_path(ltrim($safe, '/'));
    }

    /**
     * A stored thumbnail path the page may point at, or null.
     *
     * Returns the path unchanged when it passes, so the caller stores and prints
     * the same bytes it checked.
     */
    public static function stored(?string $raw): ?string
    {
        $path = trim((string) $raw);

        if ($path === '') {
            return null;
        }

        // Backslashes first: a Windows separator, and a segment splitter some
        // path readers honour that a regexp written for '/' does not.
        if (str_contains($path, '\\') || str_contains($path, "\0")) {
            return null;
        }

        if (! str_starts_with($path, '/'.self::ROOT)) {
            return null;
        }

        $name = substr($path, strlen('/'.self::ROOT));

        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,120}\.(?:jpg|jpeg|png|webp)$/', $name) === 1
            ? $path
            : null;
    }

    /**
     * The filename a post's thumbnail is written as.
     *
     * ── THE REMOTE ID IS NEVER THE FILENAME ─────────────────────────────────
     *
     * It is `sha1()` of it, which is forty hex characters and cannot contain a
     * slash, a dot, a NUL or anything else that means something to a filesystem —
     * whatever Meta decides an id looks like next year. The alternative is a
     * sanitiser that has to be right about every id format Instagram will ever
     * use, and this project has paid for that shape of bet before.
     *
     * The extension comes from the CALLER, which has read the downloaded bytes
     * and decided what they actually are with getimagesize() — never from the
     * URL's own path, which is a remote string and is wrong about the content
     * type whenever somebody wants it to be.
     */
    public static function fileName(string $remoteId, string $extension): string
    {
        $extension = strtolower($extension);

        // Belt and braces: the only callers pass one of these, and a typo at a
        // call site must not become a filename this shop then refuses to serve.
        if (! in_array($extension, ['jpg', 'png', 'webp'], true)) {
            $extension = 'jpg';
        }

        return sha1($remoteId).'.'.$extension;
    }
}
