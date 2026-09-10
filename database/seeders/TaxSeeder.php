<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\TaxRate;
use Illuminate\Database\Seeder;

/**
 * Dormant under D-64 — VAT is a display line and nothing reads this table yet.
 * Seeded so the later tax engine has its rate already in place.
 */
class TaxSeeder extends Seeder
{
    public function run(): void
    {
        TaxRate::firstOrCreate(
            ['name' => 'VAT', 'country' => 'AE'],
            ['rate' => 5.000, 'is_inclusive' => true, 'applies_to_shipping' => false, 'priority' => 1]
        );
    }
}
