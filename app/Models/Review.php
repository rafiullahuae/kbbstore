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

}
