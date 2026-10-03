<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\SafeUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One hand-picked #KBeautyBliss Spotted post.                       (Lane HB)
 *
 * Appearance → #KBeautyBliss Spotted. The owner: "with instagram feed make it
 * carousel, but with manual selection … button to a new page
 * /kbeautybliss-spotted with a manually selected IG grid".
 *
 * ── EVERY COLUMN IS OPERATOR INPUT, AND IS CHECKED ON THE WAY OUT TOO ───────
 *
 * The admin endpoint validates on the way in. toCard() validates again on the
 * way out, because a row can also arrive by a database restore, by a future
 * importer, or by hand in phpMyAdmin — and `{{ }}` does not stop
 * `javascript:alert(1)` reaching an href byte for byte (App\Support\SafeUrl's
 * header measures it). So the storefront never prints a column; it prints what
 * toCard() returns, which is an ALLOWLIST of checked values.
 *
 * Nothing here is returned by /api/*. There is no toApi() on this model, on
 * purpose: CLAUDE.md records that /api/* is unauthenticated.
 */
class SpottedPost extends Model
{
    protected $guarded = [];

    /** Where a tap on the card goes. */
    public const LINKS = ['instagram', 'product'];

    /** A handle as Instagram allows one: letters, digits, dot, underscore; 30 at most. */
    public const HANDLE_RE = '/^[A-Za-z0-9._]{1,30}$/';

    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'likes' => 'integer',
            'sort' => 'integer',
            'on_home' => 'boolean',
            'on_page' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * An Instagram post address, or null.
     *
     * ONLY https://www.instagram.com/… or https://instagram.com/…, as the owner's
     * brief says. Checked on the parsed URL rather than with a prefix match,
     * because `https://instagram.com.evil.test/` and
     * `https://instagram.com@evil.test/` both START with the right characters:
     * the host has to BE one of the two, and a user, a password or a port is
     * refused outright. The path must be more than "/" — the bare profile root
     * is not a post.
     */
    public static function instagramUrl(?string $raw): ?string
    {
        $url = trim((string) $raw);

        if ($url === '' || strlen($url) > 500 || preg_match('/[\s"\'<>\\\\`]/', $url)) {
            return null;
        }

        $parts = parse_url($url);

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! in_array(strtolower((string) ($parts['host'] ?? '')), ['www.instagram.com', 'instagram.com'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || ! isset($parts['path']) || trim((string) $parts['path'], '/') === '') {
            return null;
        }

        // The scheme must be the first thing in the string: parse_url() reads
        // " https://…" leniently in some versions; trim() already removed it.
        if (! str_starts_with(strtolower($url), 'https://')) {
            return null;
        }

        return $url;
    }

    /**
     * A picture: a path on this shop ("/uploads/…", never "//host"), or an
     * http(s) address — what the media library hands back. Null otherwise.
     */
    public static function imageUrl(?string $raw): ?string
    {
        $url = trim((string) $raw);

        if ($url === '' || strlen($url) > 500 || preg_match('/[\s"\'<>\\\\`]/', $url)) {
            return null;
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $url;
        }

        return SafeUrl::web($url) !== '' ? $url : null;
    }

    /** "@sara.glows" or "sara.glows" → "sara.glows"; anything else → null. */
    public static function cleanHandle(?string $raw): ?string
    {
        $h = ltrim(trim((string) $raw), '@');

        return preg_match(self::HANDLE_RE, $h) === 1 ? $h : null;
    }

    /**
     * 860 → "860", 1240 → "1.2k", 2400000 → "2.4m". Null stays null: no number
     * the owner did not type is ever drawn.
     */
    public static function compactCount(?int $n): ?string
    {
        if ($n === null) {
            return null;
        }

        if ($n < 1000) {
            return (string) $n;
        }

        if ($n < 1000000) {
            return rtrim(rtrim(number_format($n / 1000, 1, '.', ''), '0'), '.').'k';
        }

        return rtrim(rtrim(number_format($n / 1000000, 1, '.', ''), '0'), '.').'m';
    }

    /**
     * The only shape the storefront sees: checked values, or null when the row
     * cannot be drawn safely at all (no usable picture, no usable handle, or
     * nowhere to link to).
     *
     * @return array{id:int, image:string, alt:string, handle:string, caption:string, likes:?string, href:string, external:bool}|null
     */
    public function toCard(): ?array
    {
        $image = self::imageUrl($this->image);
        $handle = self::cleanHandle($this->handle);

        if ($image === null || $handle === null) {
            return null;
        }

        $ig = self::instagramUrl($this->ig_url);
        $product = $this->relationLoaded('product') ? $this->product : null;
        $productUrl = $product !== null && $product->status === 'publish' && $product->is_visible
            ? $product->url()
            : null;

        // The owner's choice first; the other one when his choice is not usable.
        if ($this->link_to === 'product' && $productUrl !== null) {
            [$href, $external] = [$productUrl, false];
        } elseif ($ig !== null) {
            [$href, $external] = [$ig, true];
        } elseif ($productUrl !== null) {
            [$href, $external] = [$productUrl, false];
        } else {
            return null;
        }

        $caption = trim(strip_tags((string) $this->caption));
        $alt = trim(strip_tags((string) $this->image_alt));

        return [
            'id' => (int) $this->id,
            'image' => $image,
            'alt' => $alt !== '' ? $alt : ($caption !== '' ? '@'.$handle.' — '.$caption : '@'.$handle),
            'handle' => $handle,
            'caption' => $caption,
            'likes' => $this->likes === null ? null : self::compactCount((int) $this->likes),
            'href' => $href,
            'external' => $external,
        ];
    }

    /** The owner's order: sort, then the order they were added in. */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort')->orderBy('id');
    }
}
