<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A phone or tablet enrolled into the owner app (Lane MAC). Both tokens it is
 * known by live only in HttpOnly cookies; this row holds their SHA-256
 * hashes, which are hidden from serialisation.
 */
class OwnerAppDevice extends Model
{
    protected $table = 'owner_app_devices';

    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'session_hash'];

    protected function casts(): array
    {
        return [
            'session_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
            'failed_count' => 'int',
        ];
    }

    public function member()
    {
        return $this->belongsTo(OwnerAppMember::class, 'member_id');
    }
}
