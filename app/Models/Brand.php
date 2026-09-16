<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Url;
use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{

    protected $guarded = [];

    /**
     * `seo` and `banner` are json columns and were being handed to the views
     * as raw strings, because this model declared no casts at all while
     * Category did. Brand::$seo therefore came back as `{"title":"…"}` — a
     * string that is truthy, has no ->title, and would render as literal JSON
     * anywhere it was echoed. Same shape as Category now, so the two cannot
     * disagree about what a json column on a taxonomy row means.
     */
    protected function casts(): array
    {
        return ['position' => 'int', 'seo' => 'array', 'banner' => 'array'];
    }

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
