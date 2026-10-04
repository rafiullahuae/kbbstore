<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class AdminUser extends Authenticatable
{
    use Notifiable;

    protected $table = 'admin_users';
    protected $fillable = ['name', 'email', 'password', 'role'];
    protected $hidden = ['password', 'remember_token'];

    /**
     * Mirrors the column: admin_users.role is NOT NULL DEFAULT 'owner'
     * (0001_01_01_000003_create_admin_users_table).
     *
     * Without this the schema default and the model disagree for exactly one
     * request: AdminUser::create() without a role stores 'owner' in the
     * database but hands back an instance whose `role` is null, because the
     * default is applied by the database and never read back. Anything acting
     * on that instance before it is refreshed sees an account with no role at
     * all — which, now that EnforceAdminCapability reads this column, means an
     * account that can reach nothing, while the very same row reloaded from the
     * database on the next request is an owner.
     *
     * Declaring it here makes the two agree from the moment the model exists.
     * It changes nothing about what gets stored.
     */
    protected $attributes = ['role' => 'owner'];
    protected function casts(): array
    {
        return ['password' => 'hashed', 'grants' => 'array', 'revokes' => 'array'];
    }

    /**
     * Two rules that keep `role` and `role_id` saying the same thing (Lane RL,
     * App\Support\AdminRoles).
     *
     * 1. Setting the legacy `role` on its own — `kbb:admin --role=support`, or
     *    PUT /admin-api/users/{id} with role=manager — puts the account back on
     *    the preset that role names, by clearing role_id. Otherwise an old
     *    role_id would outrank the role somebody just chose.
     *
     *    Only when the row ALREADY HAS the column: an account loaded before the
     *    roles migration ran carries no role_id attribute, and writing one then
     *    would be an unknown-column error on every save of every account.
     *
     * 2. Any save flushes that account's memoised permissions, so a check later
     *    in the same request reads the new access.
     */
    protected static function booted(): void
    {
        static::saving(function (AdminUser $u): void {
            if ($u->isDirty('role') && ! $u->isDirty('role_id') && array_key_exists('role_id', $u->getAttributes())) {
                $u->setAttribute('role_id', null);
            }
        });
        static::saved(fn (AdminUser $u) => \App\Support\AdminRoles::flush((int) $u->getKey()));
        static::deleted(fn (AdminUser $u) => \App\Support\AdminRoles::flush((int) $u->getKey()));
    }
}
