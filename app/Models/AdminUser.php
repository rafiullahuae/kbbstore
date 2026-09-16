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
        return ['password' => 'hashed'];
    }
}
