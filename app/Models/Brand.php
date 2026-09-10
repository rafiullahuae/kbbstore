<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Url;
use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{

    protected $guarded = [];

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    /**
     * URL contract U-05: production filters brands with a query parameter on /shop/,
     * not a brand archive path. This must not be "improved" into a pretty URL.
     */
    public function url(): string
    {
        return Url::to('/shop/') . '?filter_brands=' . $this->slug;
    }

}
