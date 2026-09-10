<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UpdateRelease extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime', 'file_count' => 'int'];
    }

    public function canRollBack(): bool
    {
        return $this->status === 'applied' && ! empty($this->backup_id);
    }
}
