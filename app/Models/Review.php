<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Review extends Model
{

    protected $guarded = [];

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
