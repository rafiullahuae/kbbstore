<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxRate extends Model
{

    /** Dormant under D-64 — VAT is a display line only. Kept for the later tax engine. */
    protected $guarded = [];

    protected function casts(): array
    {
        return ['rate' => 'float', 'is_inclusive' => 'bool', 'applies_to_shipping' => 'bool'];
    }

}
