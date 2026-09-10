<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Page extends Model
{

    protected $guarded = [];

    protected function casts(): array
    {
        return ['seo' => 'array'];
    }

}
