<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One Quiqup state change (QUIQUP-PLAN §4.1). UNIQUE (shipment_id, state,
 * state_at) is the documented dedupe key; `applied_at` NULL means the minute
 * cron still owes this event an apply. `reason` (the courier's free-text
 * failure note) is admin-only and never reaches a customer.
 */
class ShipmentEvent extends Model
{
    public $timestamps = false;

    /** Microseconds kept: Quiqup's state_updated_at orders events (§1.6). */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = [];

    protected $hidden = ['reason', 'body_sha256', 'facts'];

    protected function casts(): array
    {
        return [
            'state_at' => 'datetime',
            'received_at' => 'datetime',
            'applied_at' => 'datetime',
            'facts' => 'array',
            'attempts' => 'int',
        ];
    }

    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }
}
