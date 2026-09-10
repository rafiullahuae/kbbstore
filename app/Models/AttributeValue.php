<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttributeValue extends Model
{

    protected $guarded = [];

    public function attribute()
    {
        return $this->belongsTo(Attribute::class);
    }

}
