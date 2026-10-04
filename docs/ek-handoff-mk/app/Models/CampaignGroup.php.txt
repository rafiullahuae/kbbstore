<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A saved audience: one list (customers OR subscribers — never both, the
 * owner's rule) and rules from App\Services\Marketing\CampaignAudience::FIELDS.
 * Lane EK. The rules are evaluated when they are used, so a group "updates
 * itself as people order" (the approved mock).
 */
class CampaignGroup extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['rules' => 'array'];
    }
}
