<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One named, ordered set of shoppable clips, insertable anywhere by shortcode.
 *
 * The owner: "i need a full module, where i can create multiple sections of
 * videos contain, and can insert anywhere in the site, products and pages etc
 * via short code."
 *
 * ── THE HANDLE IS A SHORTCODE ATTRIBUTE, SO IT IS NARROW ON PURPOSE ─────────
 *
 * `[kbb_videos section="glass-skin"]`, parsed by App\Support\Shortcodes, which
 * matches `key="value"` with `[^"]*` inside the quotes. A handle carrying a
 * quote would therefore truncate the attribute and a handle carrying `]` would
 * truncate the whole shortcode — so HANDLE_RE is lowercase letters, digits and
 * hyphens and nothing else, checked when it is written rather than when it is
 * read. Rule 5: a value that ends up inside somebody else's syntax is validated
 * at the boundary it crosses.
 *
 * ── WHY A PIVOT AND NOT A COLUMN ON THE VIDEO ──────────────────────────────
 *
 * The same clip belongs in several rails — the sunscreen clip wants to be in the
 * homepage rail AND on the sun-care category page — and the alternative to a
 * pivot is uploading the file twice, which doubles the storage, doubles the
 * transcode and splits the like count in half.
 *
 * ── PUBLICATION IS TWO GATES, NOT ONE ──────────────────────────────────────
 *
 * A section is shown when the section itself is published AND the module is on.
 * Each video inside it is then filtered by UgcVideo::published() — which already
 * fails closed on `rights_status` — so an unpublished or rights-pending clip
 * silently drops out of a published rail rather than blocking it. That is the
 * behaviour the owner wants while he is chasing permissions: the rail works with
 * the four clips he has cleared.
 */
class UgcSection extends Model
{
    use HasTranslations;

    /**
     * The two fields a shopper reads, and therefore the two that are
     * translatable. `title` is the operator's own label in the admin list and is
     * never printed on the shop, so it is not here.
     *
     * NOT in TranslationEstimate's machine map, for the reason UgcVideo is not:
     * a heading over a named creator's work is editorial copy, and this project
     * types those.
     *
     * @var list<string>
     */
    protected array $translatable = ['heading', 'subheading'];

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['status', 'position'];

    public const STATUSES = ['draft', 'publish'];

    /** null = both storefronts. */
    public const LOCALES = ['en', 'ar'];

    /**
     * What a handle may contain.
     *
     * Deliberately NARROWER than a slug: no dots, no underscores, no unicode.
     * It is typed into a shortcode by hand, read aloud over the phone, and
     * pasted into a page body — every character that is ambiguous in one of
     * those three is a support call.
     */
    public const HANDLE_RE = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** How many tiles a rail may carry at most, whatever the section asks for. */
    public const MAX_TILES = 48;

    protected function casts(): array
    {
        return [
            'max_tiles' => 'int',
            'position' => 'int',
        ];
    }

    /**
     * The clips in this section, in the owner's own drag order.
     *
     * Ordered by the PIVOT's position and not the video's: ugc_videos.position
     * is the library's order and a section is allowed to disagree with it.
     * `id` last, for the reason App\Support\Shortcodes spells out at length —
     * every position ties at 0 until somebody reorders something, and a LIMIT
     * over a tie is a truncation the database gets to decide.
     *
     * @return BelongsToMany<UgcVideo>
     */
    public function videos(): BelongsToMany
    {
        return $this->belongsToMany(UgcVideo::class, 'ugc_section_video')
            ->withPivot(['position'])
            ->orderBy('ugc_section_video.position')
            ->orderBy('ugc_section_video.id');
    }

    /**
     * Sections a shopper may be shown.
     *
     * No `published_at` here and none in the table: a section is a container the
     * operator turns on, not a dated post. The clips inside it carry the dates.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     */
    public function scopePublished($query)
    {
        return $query->where('status', 'publish');
    }

    /**
     * ...and of those, the ones this storefront language may show.
     *
     * A null `locale` is both shops — the same rule UgcVideo::scopeForLocale
     * applies to a clip, written separately for the same reason: the admin list
     * wants the first without the second.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     */
    public function scopeForLocale($query, string $locale)
    {
        return $query->where(fn ($q) => $q->whereNull('locale')->orWhere('locale', $locale));
    }

    /** The shortcode that renders this section, for the admin to copy. */
    public function shortcode(): string
    {
        return '[kbb_videos section="'.$this->handle.'"]';
    }

    /**
     * How many tiles this section renders, clamped.
     *
     * Clamped on READ rather than only on write, because the column is an
     * unsigned smallint and a row written before MAX_TILES existed — or by a
     * hand-run UPDATE on the live box, which this shop's owner does have a shell
     * for now — could carry 6000. A rail of 6000 is a page that never finishes.
     */
    public function tileCap(): int
    {
        return max(1, min(self::MAX_TILES, (int) $this->max_tiles));
    }
}
