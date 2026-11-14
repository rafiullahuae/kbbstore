<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A courier booking (QUIQUP-PLAN §4.1). Admin-only: there is deliberately no
 * toApi() and no /api route that returns one; the customer timeline (P3) is
 * built from an explicit allowlist.
 *
 * `job` is OUR side (queued|creating|created|failed|cancelled); `state` is
 * Quiqup's, verbatim. `draft` holds the create body only until Quiqup has the
 * order, encrypted because it carries the customer's phone and address.
 */
class Shipment extends Model
{
    public const JOBS = ['queued', 'creating', 'created', 'failed', 'cancelled'];

    /** Quiqup's documented end states (§1.5). */
    public const END_STATES = ['delivery_complete', 'returned_to_origin', 'cancelled'];

    /** Quiqup accepts a cancel only in these (§1.2). */
    public const CANCELLABLE = ['pending', 'ready_for_collection'];

    /** Microseconds kept: Quiqup's state_updated_at orders events (§1.6). */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = [];

    protected $hidden = ['draft', 'request_uuid', 'last_reason'];

    protected function casts(): array
    {
        return [
            'draft' => 'encrypted:array',
            'state_at' => 'datetime',
            'collection_after' => 'datetime',
            'collection_before' => 'datetime',
            'delivery_after' => 'datetime',
            'delivery_before' => 'datetime',
            'delivered_at' => 'datetime',
            'last_error_at' => 'datetime',
            'claimed_at' => 'datetime',
            'next_poll_at' => 'datetime',
            'qq_id' => 'int',
            'cod_fils' => 'int',
            'parcels' => 'int',
            'attempts' => 'int',
            'tries' => 'int',
            'generation' => 'int',
            'test' => 'bool',
        ];
    }

    public function order()
    {
        return $this->belongsTo(Order::class)->withTrashed();
    }

    public function events()
    {
        return $this->hasMany(ShipmentEvent::class)->orderBy('state_at')->orderBy('id');
    }

    public function isCancellable(): bool
    {
        return $this->job === 'created' && $this->qq_id !== null
            && in_array((string) $this->state, self::CANCELLABLE, true);
    }
}
