<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Authenticatable
{

    use Notifiable;
    use SoftDeletes;

    protected $guarded = [];

    protected $hidden = ['password', 'legacy_password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'last_order_at' => 'datetime',
            'whatsapp_optin' => 'bool',
            'total_spent' => 'int',
        ];
    }

    public function addresses()
    {
        return $this->hasMany(Address::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function carts()
    {
        return $this->hasMany(Cart::class);
    }

    public function defaultAddress(string $type = 'shipping'): ?Address
    {
        return $this->addresses()->where('type', $type)->where('is_default', true)->first()
            ?? $this->addresses()->where('type', $type)->first();
    }

    public function displayName(): string
    {
        return $this->name ?: trim($this->first_name . ' ' . $this->last_name) ?: $this->email;
    }

}
