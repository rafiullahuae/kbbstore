<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Instagram\IgPath;
use App\Services\UgcPath;
use Illuminate\Database\Eloquent\Model;

/**
 * One post or reel from OUR OWN Instagram account.
 *
 * Phase 21, Lane IG. docs/IG-PROFILE.md §1 establishes that this is a different
 * and much easier permission problem than the one docs/UGC-ENGAGEMENT.md costed:
 * `like_count` and `comments_count` are ordinary fields on `GET /me/media` for an
 * account we hold a token for.
 *
 * ── EVERY COLUMN ON THIS MODEL CAME FROM SOMEBODY ELSE'S SERVER ─────────────
 *
 * Which makes the whole row remote user input, and the only safe way to treat it
 * is the way this project treats an operator-typed URL: re-check on the way OUT as
 * well as on the way in. `toTile()` below is the only thing the storefront ever
 * sees, and it is an ALLOWLIST — the shape Product::toApi() and
 * SettingController::PUBLIC_KEYS established, because CLAUDE.md records that
 * `/api/*` is unauthenticated and that the settings endpoint has leaked three
 * times.
 *
 * Nothing here is ever returned by a public endpoint at all, and that is not an
 * accident of routing: this model has no `toApi()`, so there is nothing for an
 * `/api/*` controller to reach for by the name every other model on this shop uses.
 */
class InstagramPost extends Model
{
    protected $guarded = [];

    /**
     * The media types Meta documents, and the only three this shop draws.
     *
     * Anything else — a type Meta adds next year — is read as an IMAGE by
     * isVideo() below, which is the safe fall: a still and no play button, rather
     * than a play button over something that will not play.
     */
    public const TYPES = ['IMAGE', 'VIDEO', 'CAROUSEL_ALBUM'];

    /**
     * What may be in a `shortcode` column, and therefore in an embed URL.
     *
     * Instagram's own shortcodes are base64url-ish. This is checked BEFORE the
     * value is stored and again before it is printed, because the value's whole
     * job is to be interpolated into an iframe `src` — rule 5's "anything printed
     * unescaped is a constant, never a setting", applied to the one place in this
     * feature where a remote string becomes part of a URL we build.
     */
    public const SHORTCODE_RE = '/^[A-Za-z0-9_-]{1,64}$/';

    protected function casts(): array
    {
        return [
            'posted_at' => 'datetime',
            'seen_at' => 'datetime',
            /*
             * `integer` and NOT a default, so a null stays a null all the way
             * through. docs/UGC-ENGAGEMENT.md: "NULL is *we do not know* and 0 is
             * *the source said zero*." Laravel's integer cast leaves null alone,
             * which is exactly what is wanted — a `(int)` in a reader would turn
             * "we do not know" into "zero likes" under a reel with fourteen
             * thousand of them.
             */
            'like_count' => 'integer',
            'comments_count' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    /** True for a reel or a video post — the tiles that get a play affordance. */
    public function isVideo(): bool
    {
        return $this->media_type === 'VIDEO';
    }

    /**
     * The flat array a tile is drawn from, and the boundary of this feature.
     *
     * ── WHY THIS IS AN ALLOWLIST AND NOT `toArray()` ────────────────────────
     *
     * `remote_id` is Instagram's internal media id and `seen_at` is a fact about
     * our fetch schedule; neither is any of a shopper's business, and both would
     * ride along on a `toArray()` the day somebody points a controller at this
     * model. The columns a tile needs are named, and a column added later is
     * invisible here until somebody adds it on purpose — which is the whole
     * argument CLAUDE.md makes about `products.wc_id` and `reviews.ip`.
     *
     * ── AND EVERY URL IS RE-CHECKED HERE, NOT IN THE TEMPLATE ───────────────
     *
     * `permalink` becomes an `href` and the embed URL becomes an iframe `src`.
     * Both are built here: the permalink goes through UgcPath::link(), which
     * decodes entities and strips control characters BEFORE reading the scheme,
     * and the embed URL is assembled from `shortcode` only after it has matched
     * SHORTCODE_RE — so not one byte of a remote string reaches that attribute
     * unvalidated. A value that fails is null and the template draws no element
     * for it, which for a permalink means the tile is not a link at all.
     *
     * @return array<string, mixed>
     */
    public function toTile(): array
    {
        $shortcode = (string) ($this->shortcode ?? '');
        $shortcode = preg_match(self::SHORTCODE_RE, $shortcode) === 1 ? $shortcode : '';

        return [
            'id' => (int) $this->id,
            // Our own file, never Meta's CDN. docs/IG-PROFILE.md §2 is the whole
            // argument: a signed CDN URL expires and takes the grid with it.
            'image' => IgPath::stored($this->local_path),
            'width' => $this->width === null ? null : (int) $this->width,
            'height' => $this->height === null ? null : (int) $this->height,
            'video' => $this->isVideo(),
            'carousel' => $this->media_type === 'CAROUSEL_ALBUM',
            'caption' => (string) ($this->caption ?? ''),
            'permalink' => UgcPath::link($this->permalink),
            /*
             * Instagram's official embed, and the reason it is built rather than
             * stored: the stored thing is 64 characters we have matched against
             * SHORTCODE_RE, and this string is a CONSTANT with those characters
             * in it. Empty when there is no usable shortcode, and the template
             * then falls back to the permalink — which is the shipped behaviour
             * anyway (docs/IG-PROFILE.md §2).
             */
            'embed' => $shortcode === '' ? null : 'https://www.instagram.com/p/'.$shortcode.'/embed/captioned/',
            /*
             * NULL PASSES THROUGH AS NULL. Not `?? 0`, not `(int)`. See the class
             * docblock and docs/UGC-ENGAGEMENT.md: an honest zero and "we could
             * not ask" are different facts and the template must be able to tell
             * them apart, because it draws nothing at all for the second.
             */
            'likes' => $this->like_count === null ? null : (int) $this->like_count,
            'comments' => $this->comments_count === null ? null : (int) $this->comments_count,
        ];
    }

    /**
     * Recent posts first, then id — and the second key is load-bearing.
     *
     * App\Support\Shortcodes' own ordering comment makes the argument at length:
     * a LIMIT over a tie is a truncation the database gets to decide. Two posts
     * published in the same second is not a hypothetical on an account that posts
     * a carousel and a reel together.
     *
     * A post with a NULL `posted_at` sorts last on every driver this shop runs
     * (SQLite and MySQL both order NULLs first ascending, last descending), which
     * is the right place for a post whose date we could not read.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     */
    public function scopeRecent($query)
    {
        return $query->orderByDesc('posted_at')->orderByDesc('id');
    }

    /**
     * ...and of those, only the ones there is actually a picture for.
     *
     * A post whose thumbnail download failed has `local_path = null`, and drawing
     * it would be a hole in the grid at first paint. Applied as a SCOPE rather
     * than filtered in PHP after the fact, so the LIMIT counts rows that will be
     * drawn: filtering afterwards is how a "12 posts" setting renders nine.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     */
    public function scopeDrawable($query)
    {
        return $query->whereNotNull('local_path');
    }
}
