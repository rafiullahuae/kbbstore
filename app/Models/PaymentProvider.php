<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentProvider extends Model
{

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'bool', 'config' => 'encrypted:array'];
    }

    /**
     * Any write to a provider row rewrites the wallet projection.
     *
     * THE MODEL AND NOT THE CONTROLLER, because there are four writers and
     * only one of them is the payments screen: GatewayCredentials::save()
     * writes the config blob, StripeConnect writes it twice more during a
     * connect and a disconnect, and PaymentsApiController writes the `enabled`
     * column beside it. App\Services\Payments\Wallets keeps its answer as a
     * settings row so that the footer's payment marks cost the query budget
     * nothing on every page of the shop (see its docblock), and a projection
     * that only one of four writers refreshed would leave the marks and the
     * checkout button disagreeing with the screen that had just been saved.
     *
     * `saved` covers create and update alike; `deleted` covers a row removed
     * outright, which is how a gateway is taken off this shop entirely.
     *
     * It cannot fail the write it is hanging off: Wallets::project() swallows a
     * settings table it cannot write and leaves nothing behind on this model —
     * see the swallowed-exception landmine in CLAUDE.md, where a guarded write
     * that DID leave state behind seeded the failure it claimed to contain.
     */
    protected static function booted(): void
    {
        $project = static function (): void {
            app(\App\Services\Payments\Wallets::class)->project();
        };

        static::saved($project);
        static::deleted($project);
    }
}
