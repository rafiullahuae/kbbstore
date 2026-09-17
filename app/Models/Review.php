<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Review extends Model
{

    protected $guarded = [];

    /**
     * Never serialised to JSON.
     *
     * A review carries the reviewer's email address and the IP they submitted
     * from. Both are needed for moderation and neither is anyone else's
     * business. Put here rather than only in the controllers because the
     * endpoints returned whole models, and the next one to do so will inherit
     * this instead of repeating the mistake.
     */
    protected $hidden = ['author_email', 'ip'];

    protected function casts(): array
    {
        return ['images' => 'array', 'verified' => 'bool', 'rating' => 'int', 'helpful' => 'int'];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /** Business reviews carry no product. In WordPress this was product_id = 0. */
    public function scopeBusiness($query)
    {
        return $query->whereNull('product_id');
    }

    /**
     * Rows a real person really wrote — demo-seeded ones excluded.
     *
     * Every storefront and crawler-facing reader of this table applies this
     * beside ->approved(). The two questions are deliberately separate scopes
     * rather than one: `approved` is a moderation state the owner controls and
     * `real` is a question about provenance, and the admin needs to filter on
     * the first while still showing rows that fail the second.
     *
     * The predicate itself lives in App\Support\DemoReviews, because answering
     * it takes both `reviews.source` and the `demo_seed_log` table and neither
     * half alone is complete. See that class.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     */
    public function scopeReal($query, ?string $table = null)
    {
        return \App\Support\DemoReviews::exclude($query, $table);
    }

}
