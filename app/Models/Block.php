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

    protected $fillable = ['slug', 'name', 'content', 'status'];

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
        return '[kbb_block slug="' . $this->slug . '"]';
    }
}
