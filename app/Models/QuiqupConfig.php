<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per Quiqup environment: 'sandbox' or 'live' (QUIQUP-PLAN §4.1).
 *
 * `secrets` is encrypted:array -- {api_key, webhook_secret, webhook_secret_prev,
 * webhook_secret_rotated_at, url_secret} -- and is never serialised: $hidden
 * keeps it out of toArray()/toJson(), and no /api route reads this model. The
 * admin sees {filled, last4} only (App\Services\Quiqup\QuiqupSettings).
 */
class QuiqupConfig extends Model
{
    protected $table = 'quiqup_config';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $hidden = ['secrets'];

    protected function casts(): array
    {
        return [
            'active' => 'bool',
            'secrets' => 'encrypted:array',
            'options' => 'array',
            'last_webhook_at' => 'datetime',
            'last_poll_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }
}
