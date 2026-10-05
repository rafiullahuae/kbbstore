<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An admin account's access to the owner app (Lane MAC). `pin_hash` is a
 * Hash::make() hash and is hidden: no serialisation of this model can carry
 * it, and no endpoint returns this model anyway — every response is built
 * from an explicit allowlist.
 */
class OwnerAppMember extends Model
{
    protected $table = 'owner_app_members';

    protected $guarded = ['id'];

    protected $hidden = ['pin_hash'];

    protected function casts(): array
    {
        return [
            'enabled' => 'bool',
            'notify' => 'array',
            'pin_set_at' => 'datetime',
            'locked_until' => 'datetime',
            'failed_count' => 'int',
        ];
    }

    public function admin()
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }

    public function devices()
    {
        return $this->hasMany(OwnerAppDevice::class, 'member_id');
    }
}
