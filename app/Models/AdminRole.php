<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\AdminRoles;
use Illuminate\Database\Eloquent\Model;

/**
 * A back-office role (Lane RL). See App\Support\AdminRoles for how an account's
 * access is worked out from one of these; this model is only the row.
 *
 * `capabilities` NULL on a preset means "the code default" — see AdminRoles.
 * Every write flushes the cached role table, so the next permission check in
 * any request reads the new list.
 */
class AdminRole extends Model
{
    protected $table = 'admin_roles';

    protected $fillable = ['slug', 'name', 'description', 'tier', 'is_preset', 'capabilities'];

    protected function casts(): array
    {
        return ['is_preset' => 'boolean', 'capabilities' => 'array'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => AdminRoles::flush());
        static::deleted(fn () => AdminRoles::flush());
    }
}
