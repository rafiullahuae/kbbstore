<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingZone extends Model
{

    protected $guarded = [];

    public function locations()
    {
        return $this->hasMany(ShippingZoneLocation::class);
    }

    /**
     * The methods to OFFER a shopper: enabled only.
     *
     * The `enabled` filter is part of the relation rather than of each caller,
     * because ShippingService::ratesFor() walks this and charges what it finds
     * — a switched-off method that reached it would be sold.
     */
    public function methods()
    {
        return $this->hasMany(ShippingMethod::class)->where('enabled', true)->orderBy('position');
    }

    /**
     * The methods to EDIT: every row on the zone, switched off included.
     *
     * A DIFFERENT QUESTION FROM methods(), AND THAT IS THE POINT. Store →
     * Delivery & Shipping read the scoped relation above, so the moment the
     * owner unticked a method and saved, the row vanished from the screen —
     * and ShippingApiController::save() only updates ids the browser posts,
     * which are the ids the screen was given. The switch was one-way: nothing
     * in the admin could turn a method back on, on a host with no shell access
     * and no database client. Unticking both methods on the zone covering the
     * UAE additionally leaves ratesFor() returning [], which
     * Store\CheckoutController::place() reports as "We do not deliver to that
     * country yet" — every checkout refused, with no screen able to undo it.
     */
    public function allMethods()
    {
        return $this->hasMany(ShippingMethod::class)->orderBy('position');
    }

}
