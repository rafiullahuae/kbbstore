<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuizSubmission extends Model
{

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'recommended_routines' => 'array',
            'utm' => 'array',
            'consent' => 'bool',
            'expert_requested' => 'bool',
            'expert_requested_at' => 'datetime',
            'consent_at' => 'datetime',
        ];
    }

}
