<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per mailer, holding its secrets. See the create_mail_credentials
 * migration for why these are not in the `settings` table.
 *
 * `encrypted:array` is the same cast PaymentProvider uses for gateway keys --
 * the column is ciphertext, so a database dump or a nightly backup does not
 * contain the SMTP password.
 */
class MailCredential extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['config' => 'encrypted:array'];
    }
}
