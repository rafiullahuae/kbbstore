<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attribute extends Model
{

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_variation_axis' => 'bool', 'is_filterable' => 'bool'];
    }

    public function values()
    {
        return $this->hasMany(AttributeValue::class)->orderBy('position');
    }

    /** The live query var, e.g. filter_color. Preserved verbatim from WooCommerce. */
    public function queryVar(): string
    {
        return $this->query_var ?: 'filter_' . $this->slug;
    }

}
