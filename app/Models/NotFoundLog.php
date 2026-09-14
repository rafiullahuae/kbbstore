<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotFoundLog extends Model
{
    protected $table = 'not_found_log';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'hits' => 'int',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
