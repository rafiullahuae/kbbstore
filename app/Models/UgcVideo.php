<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One shoppable UGC clip, with several products tagged on it.
 *
 * docs/UGC-VIDEO-PLAN.md §4 is the data model this implements and §0b.5 is the
 * pair of columns round three added to it. Nothing on the storefront reads this
 * model yet, deliberately: the owner has not picked a rail or a player (§8
 * questions 1 and 2), and the round that draws one is the round that adds a
 * page.
 *
 * ── A VIDEO IS THREE FILES, NOT ONE ─────────────────────────────────────────
 *
 *   file_path    the full clip, 720x1280, ~1.5 MB for 15s. What an opened
 *                player streams.
 *   teaser_path  2.5s, 360x640, ~400 kbps, no audio, ~130 KB. What a rail tile
 *                loops. OPTIONAL — see below.
 *   poster_path  ~22 KB WebP. What everything shows before anything moves, and
 *                what a rail shows when there is no teaser.
 *
 * Round three measured why that is three files and not one: seeking a full clip
 * back to zero, and the `#t=0,2.5` media fragment that looks like the clever
 * answer, both fetch every byte of the whole file. 12.19 MB for a rail of
 * eight, against 1.01 MB of teasers. A media fragment tells the player where to
 * start and the network nothing.
 *
 * ── THE THREE MEDIA STATES, AND WHY "NO TEASER" IS NOT AN ERROR ─────────────
 *
 * §8 question 4 — does ffmpeg exist on that Cloudways box — has no answer yet,
 * and nobody working in this repo can get one. So a missing teaser is a
 * first-class state rather than a failure:
 *
 *   MEDIA_NONE        no clip yet. Not publishable.
 *   MEDIA_POSTER_ONLY clip + poster, no teaser. PUBLISHABLE. The rail shows the
 *                     poster at ~22 KB a tile, which is exactly what the
 *                     previews already draw under Save-Data — a quieter page,
 *                     not a broken one.
 *   MEDIA_READY       clip + poster + teaser. The rail loops.
 *
 * publishBlockers() is where that is enforced, and UgcPublicationGateTest goes
 * red if the teaser is ever added to it.
 *
 * ── PUBLICATION FAILS CLOSED ON RIGHTS ──────────────────────────────────────
 *
 * §3.3: downloading a creator's video and serving it from this shop is a
 * reproduction, and the creator owns the copyright in it. So `rights_status`
 * is a column, it starts at 'pending', and 'granted' is a precondition of
 * publishing rather than a note somebody meant to check.
 */
class UgcVideo extends Model
{
    use HasTranslations;

    /**
     * §5. The two fields that are prose, and the same allowlist shape Product
     * uses.
     *
     * NOT registered in TranslationEstimate's machine-translation map, and that
     * omission is the decision rather than an oversight: a caption is a named
     * creator's own voice, and a machine-translated caption attributed to her
     * is putting words in her mouth. Type it, or leave the English and let
     * `locale` keep the clip on the English storefront.
     *
     * @var list<string>
     */
    protected array $translatable = ['title', 'caption'];

    protected $guarded = [];

    /**
     * Never serialised to JSON, whatever a later lane hands to response()->
     * json().
     *
     * `rights_evidence` is where a creator's DM or a signed release is
     * recorded, and the other three are the shop's own editorial state. §7
     * lists them by name as things a public endpoint must never return. The
     * allowlist in toApi() is the real guard — this is the second lock, put
     * here for the reason Review::$hidden gives: the endpoints that leaked in
     * production leaked by returning whole models.
     *
     * @var list<string>
     */
    protected $hidden = ['rights_evidence', 'rights_status', 'rights_granted_at', 'status'];

    /** Status vocabulary. A select stores one of its own options or the default. */
    public const STATUSES = ['draft', 'publish'];

    public const RIGHTS = ['pending', 'granted', 'refused'];

    /** §3.1/§3.2: attribution and a link back, never an embed target. */
    public const PLATFORMS = ['upload', 'instagram', 'tiktok', 'youtube'];

    /** null = both storefronts. */
    public const LOCALES = ['en', 'ar'];

    public const MEDIA_NONE = 'none';

    public const MEDIA_POSTER_ONLY = 'poster_only';

    public const MEDIA_READY = 'ready';

    protected function casts(): array
    {
        return [
            'bytes' => 'int',
            'teaser_bytes' => 'int',
            'poster_bytes' => 'int',
            'width' => 'int',
            'height' => 'int',
            'duration_ms' => 'int',
            'position' => 'int',
            'published_at' => 'datetime',
            'rights_granted_at' => 'datetime',
            /*
             * A count of real clicks on this shop, never null and never unknown:
             * we maintain it, so 0 means nobody has pressed it.
             *
             * THERE IS NO COLUMN HERE FOR A THIRD PARTY'S COUNT. The owner cut
             * that mid-round — "leave the counts for now, just get the videos from
             * there" — and the six nullable metrics_* columns an earlier draft
             * carried were deleted rather than shipped unwritten. Nothing in this
             * module asks Instagram, TikTok or YouTube for anything.
             */
            'likes' => 'int',
        ];
    }

    /**
     * The products tagged on this clip — requirement one.
     *
     * Ordered by the pivot's own `position`, so the first is the one a rail
     * tile's card shows and the player's rail follows the owner's drag order
     * rather than the id order the database happens to return.
     *
     * @return BelongsToMany<Product>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'ugc_video_product')
            ->withPivot(['position', 'at_ms'])
            ->orderBy('ugc_video_product.position')
            ->orderBy('ugc_video_product.id');
    }

    /**
     * Rows a shopper may be shown.
     *
     * `published_at` is compared against now() HERE, on read, rather than
     * flipped by a scheduler — this host has no cron and no queue worker, so a
     * scheduled flip would be a flip that never happens. ProductVisibility sets
     * the same precedent for the catalogue.
     *
     * A null `published_at` on a row whose status is 'publish' means "as soon
     * as it is published", not "never": the owner should not have to type
     * today's date to publish today.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     */
    public function scopePublished($query)
    {
        /*
         * ── THIS HAS TO AGREE WITH publishBlockers(), OR PUBLISHING LIES ────
         *
         * The admin gate and this query are the same decision asked twice. When
         * they disagreed the failure was invisible and the worst of both: the
         * owner would set a clip to Published, the screen would say Published,
         * and the rail would silently never show it — because the row was
         * filtered out here. A refusal he can see beats a success that is not
         * true.
         *
         * So the two conditions that stopped being blockers stop being filters.
         * A published clip appears on the shop; the cost of a missing cover or
         * unrecorded permission is carried by the warnings in the admin, which
         * is where somebody can act on it.
         *
         * file_path stays, and status and the schedule stay: a row with no
         * video has nothing to play, and a clip dated for next Tuesday is not
         * live today.
         */
        return $query
            ->where('status', 'publish')
            // Never a clip whose creator said no -- see publishBlockers().
            ->where('rights_status', '!=', 'refused')
            ->whereNotNull('file_path')
            ->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }

    /**
     * ...and of those, the ones this storefront language may show.
     *
     * A null `locale` is both shops. Written as a scope of its own rather than
     * folded into published() because the admin list wants the first without
     * the second.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     */
    public function scopeForLocale($query, string $locale)
    {
        return $query->where(fn ($q) => $q->whereNull('locale')->orWhere('locale', $locale));
    }

    /**
     * Why this clip cannot be published, in words the admin screen prints.
     *
     * THE TEASER IS NOT IN HERE AND MUST NOT BE. §0b.5: "publication should not
     * require the teaser — a video with no teaser yet shows its poster, which
     * is the Save-Data behaviour and is already drawn." On a server with no
     * ffmpeg that is EVERY video, so a teaser precondition would be a feature
     * that cannot be used at all on a box nobody has checked yet.
     *
     * @return list<string>
     */
    public function publishBlockers(): array
    {
        $out = [];

        /*
         * ── ONE BLOCKER LEFT, AND ONLY ONE ─────────────────────────────────
         *
         * A shoppable-video tile with no video is not a degraded tile, it is an
         * empty box that plays nothing. Nothing downstream can make it work and
         * no warning helps, so this stays a refusal.
         *
         * THE OTHER TWO MOVED TO publishWarnings(), at the owner's explicit
         * request: "the video can be published without credits and poster
         * cover, but warnings should remains there." They were blockers because
         * each is a real cost — read publishWarnings() for what each one costs —
         * but they are his costs to accept on his own shop, and a tile without
         * them still works.
         */
        if ((string) $this->file_path === '') {
            $out[] = 'No video file has been uploaded yet.';
        }

        /*
         * ── "NOT YET ASKED" AND "THEY SAID NO" ARE NOT THE SAME STATE ──────
         *
         * This stayed a blocker when `pending` stopped being one, and the
         * distinction is the whole point. The owner asked to publish "without
         * credits", which is the PENDING case: permission he has not got round
         * to recording. `refused` is the creator having been asked and having
         * said no, and there is no warning that makes overriding that
         * acceptable — it is not a cost he may choose to carry, because the
         * cost lands on somebody else.
         *
         * A TEST CAUGHT THIS, and it is worth saying so: the first cut of this
         * change dropped `rights_status` from the gate entirely, which would
         * have put refused clips on the shop. UgcLikeApiTest's 404-consistency
         * case went red because a refused clip became fetchable, and that test
         * exists for the id-oracle reason in CLAUDE.md's Known gaps — an
         * endpoint that answers differently for "exists but you may not" is a
         * directory of exactly the rows nobody should be advertising.
         */
        if ($this->rights_status === 'refused') {
            $out[] = 'The creator refused permission. This clip cannot be published.';
        }

        return $out;
    }

    /**
     * Real costs, stated, that no longer stop a clip going live.
     *
     * DELIBERATE DEFAULT CHANGE, asked for in as many words, and CLAUDE.md rule
     * 1 requires it be called out rather than buried. Before this, a clip with
     * no cover or no recorded permission could not be published at all.
     *
     * Each of these was a blocker for a reason that has not gone away:
     *
     * -- THE COVER. §2 budgets layout shift at 0. The tile's box is still
     *    reserved without one — from the clip's own width and height, or the
     *    9:16 fallback — so the page does not jump; what the shopper sees
     *    before the video paints is an empty box rather than a picture. That is
     *    a worse first frame, not a broken one. On a host whose PHP cannot run
     *    ffmpeg this was the difference between using the feature and not.
     *
     * -- THE PERMISSION. This one is not cosmetic and is not the shop's risk to
     *    shrug at: publishing a creator's video without their recorded
     *    permission is a rights question, not a layout question. It is now a
     *    warning that follows the clip everywhere the admin lists it, rather
     *    than a refusal — but it is still WRONG to ignore, and it says so.
     *
     * @return list<string>
     */
    public function publishWarnings(): array
    {
        $out = [];

        if ((string) $this->poster_path === '') {
            $out[] = 'No cover image — the tile reserves its box but shows nothing until the video paints.';
        }

        /*
         * ANYTHING THAT IS NOT `granted`, EXCEPT `refused` — which is a blocker
         * above, and repeating it here would warn about something he cannot do
         * anyway.
         *
         * Not `=== 'pending'`, and a test caught the difference: the column's
         * default is applied by the DATABASE, so an unsaved `new UgcVideo` holds
         * NULL and a check for the literal 'pending' said nothing at all about
         * it. The screen builds exactly that object to ask what a blank clip
         * still needs.
         */
        if (! in_array($this->rights_status, ['granted', 'refused'], true)) {
            $out[] = 'The creator has not granted permission yet (Rights & credit).';
        }

        return $out;
    }

    public function canPublish(): bool
    {
        return $this->publishBlockers() === [];
    }

    /** One of MEDIA_NONE, MEDIA_POSTER_ONLY, MEDIA_READY. */
    public function mediaState(): string
    {
        if ((string) $this->file_path === '' || (string) $this->poster_path === '') {
            return self::MEDIA_NONE;
        }

        return (string) $this->teaser_path === '' ? self::MEDIA_POSTER_ONLY : self::MEDIA_READY;
    }

    /**
     * The rails this clip appears in — Lane V3.
     *
     * Many-to-many deliberately: the sunscreen clip belongs in the homepage rail
     * AND on the sun-care category page, and the alternative to a pivot is
     * uploading the file twice, which splits its like count in half.
     *
     * @return BelongsToMany<UgcSection>
     */
    public function sections(): BelongsToMany
    {
        return $this->belongsToMany(UgcSection::class, 'ugc_section_video')
            ->withPivot(['position'])
            ->orderBy('ugc_sections.position')
            ->orderBy('ugc_sections.id');
    }

    /**
     * The one number a tile may print, and it is ours.
     *
     * ── WHY THIS IS A METHOD AND NOT JUST $this->likes ─────────────────────
     *
     * Because of what it does NOT return. An earlier draft of this round also
     * carried a source's like, comment and view counts, with a rule that a figure
     * which could not be fetched was ABSENT from this array rather than zero in
     * it — so the template drew no element for it, the way the rating bar draws
     * nothing for a product with no reviews. The owner cut that: "leave the
     * counts for now, just get the videos from there."
     *
     * The rule survives the cut and is worth keeping written down, because it is
     * the rule any future version of this has to follow: A NUMBER THIS SHOP
     * CANNOT VERIFY IS NOT PRINTED. A fabricated like count on a shop is a lie to
     * a shopper, and a zero standing in for "we could not find out" is the same
     * lie in a quieter voice.
     *
     * @return array{own_likes: int}
     */
    public function engagement(): array
    {
        return ['own_likes' => (int) $this->likes];
    }

    /**
     * What a public endpoint may return about this clip — §7.
     *
     * AN EXPLICIT LIST, ITERATED, NOT A MODEL WITH HIDDEN FIELDS. The pattern
     * is SettingController::PUBLIC_KEYS and Product::toApi(), and the reason is
     * the one /api/* has already paid for three times: with an allowlist a
     * column added next year is invisible by default; with a denylist it is
     * public by default and nobody finds out.
     *
     * NEVER HERE: rights_status, rights_evidence, rights_granted_at, status,
     * position, locale, any filesystem path that is not a served one, and any
     * timestamp that is not published_at.
     *
     * NOTHING CALLS THIS YET and no route serves it — the public rail is the
     * next round's. It is written now, and pinned by UgcApiShapeTest, so that
     * round has an allowlist to reach for instead of a model.
     *
     * @return array<string, mixed>
     */
    public function toApi(): array
    {
        return [
            'slug' => (string) $this->slug,
            'title' => (string) $this->t('title'),
            'caption' => (string) ($this->t('caption') ?? ''),
            'poster' => $this->poster_path,
            'src' => $this->file_path,
            'teaser' => $this->teaser_path,
            'width' => $this->width,
            'height' => $this->height,
            'duration_ms' => $this->duration_ms,
            'creator_handle' => $this->creator_handle,
            'creator_url' => $this->creator_url,
            'source_url' => $this->source_url,
            /*
             * WHICH PLATFORM, because the tile's behaviour depends on it and the
             * storefront is not allowed to guess from the URL. An external clip
             * opens THEIR embed, which we do not control and cannot give a teaser
             * loop to; an uploaded one opens our own player. One of
             * UgcVideo::PLATFORMS and nothing else ever reaches the column — a
             * select stores one of its own options or the default.
             */
            'platform' => (string) $this->source_platform,
            'published_at' => $this->published_at?->toIso8601String(),
            /*
             * OUR OWN like count, always present because we count it. The
             * LEDGER behind it (ugc_video_likes) is never published and has no
             * endpoint: its one column is the hash of a live bearer cookie.
             */
            'likes' => (int) $this->likes,

            /*
             * Through the EXISTING allowlist, not a second one. Product::toApi()
             * is already what keeps wc_id, sku and total_sales off the wire, and
             * a copy of it here would be a copy that stops being updated.
             */
            'products' => $this->relationLoaded('products')
                ? $this->products->map(fn (Product $p) => $p->toApi())->values()->all()
                : [],
        ];
    }
}
