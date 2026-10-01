<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A reusable HTML snippet, placed in page and post content by shortcode.
 *
 * WHAT CHANGED AND WHY. This class was four lines -- no $table, no $fillable,
 * `$timestamps = false`, `$guarded = []` -- and nothing in the application
 * referenced it, because the `blocks` table it resolves to did not exist. The
 * table arrives with this lane (create_blocks_table), so the model now
 * describes it.
 *
 * `$guarded = []` is replaced by an explicit $fillable. Every write reaches
 * this model from Admin\BlocksApiController, which validates first, so the
 * mass-assignment risk was theoretical -- but `id` and the timestamps have no
 * business being settable from a request body, and an allowlist is the habit
 * CLAUDE.md asks for on anything a request can reach.
 *
 * $timestamps is now true. updated_at is shown on the admin list, which is the
 * column that answers "did my edit save?" -- the question a screen with no
 * clock cannot answer.
 */
class Block extends Model
{
    public const STATUSES = ['published', 'draft'];

    /** `blocks.source` for a Rey theme Global Section imported from WordPress. (Lane PJ-B) */
    public const SOURCE_REY = 'rey_global_section';

    protected $fillable = ['slug', 'name', 'content', 'status'];

    protected $casts = ['source_modified_at' => 'datetime'];

    /**
     * A write drops what App\Support\GlobalSections holds for this request.
     * Under PHP-FPM a request ends long before a block changes, but a queue
     * worker or a test drives several "requests" through one container, and a
     * held copy would draw the block as it was before the edit. (Lane PJ-B)
     */
    protected static function booted(): void
    {
        $forget = static fn () => app()->forgetInstance('kbb.global_sections');

        static::saved($forget);
        static::deleted($forget);
    }

    /** Only these render on the storefront. See Shortcodes::block(). */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /**
     * The shortcode that places this block, shown on the screen for copying.
     *
     * Built here rather than in the Blade so the admin list, the editor and
     * the "used in" scan cannot drift into naming three different strings for
     * the same thing.
     */
    public function shortcode(): string
    {
        /*
         * AN IMPORTED REY GLOBAL SECTION IS PLACED BY THE OLD SHOP'S OWN TEXT.
         * (Lane PJ-B) Its products already carry `[rey_global_section
         * id="18159"]` in their descriptions, and App\Support\GlobalSections
         * resolves exactly that against `wc_id`. Showing the owner a
         * [kbb_block] string instead would name a shortcode none of his
         * products contain -- it works too, and it is useless for finding
         * where the block appears.
         */
        if ($this->wc_id !== null && in_array($this->source, \App\Support\GlobalSections::SHORTCODES, true)) {
            return '[' . $this->source . ' id="' . (int) $this->wc_id . '"]';
        }

        return '[kbb_block slug="' . $this->slug . '"]';
    }

    /** Came from the WordPress export rather than from this admin. */
    public function isImported(): bool
    {
        return $this->wc_id !== null;
    }
}
