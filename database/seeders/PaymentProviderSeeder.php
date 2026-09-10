<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PaymentProvider;
use Illuminate\Database\Seeder;

/**
 * Gateways enabled on production. Credentials are never seeded — they belong in
 * .env on the server and are encrypted into `config` at runtime (D-41).
 */
class PaymentProviderSeeder extends Seeder
{
    public function run(): void
    {
        $providers = [
            ['id' => 'cod', 'title' => 'Cash on delivery', 'position' => 0],
            ['id' => 'tabby', 'title' => 'Tabby: Pay in 4 Installments', 'position' => 1],
            ['id' => 'tamara', 'title' => 'Pay Later with Tamara', 'position' => 2],
            ['id' => 'stripe', 'title' => 'Credit / Debit Card', 'position' => 3],
        ];

        foreach ($providers as $provider) {
            PaymentProvider::firstOrCreate(
                ['id' => $provider['id']],
                ['title' => $provider['title'], 'enabled' => false, 'mode' => 'test', 'position' => $provider['position']]
            );
        }
    }
}
