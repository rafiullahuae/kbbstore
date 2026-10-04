<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row of THE block list (Lane CT). Written only through
 * App\Services\Security\IpBlockList, which validates, refuses the unblockable
 * and recompiles the enforcement file on every change. Never returned whole by
 * any endpoint: IpBlockList::row() is the allowlist.
 */
class IpBlock extends Model
{
    protected $table = 'ip_blocks';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'family' => 'integer',
            'prefix' => 'integer',
            'hits' => 'integer',
            'last_hit_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
