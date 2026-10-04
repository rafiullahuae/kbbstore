<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One marketing email — Growth & Marketing → Email Marketing (Lane EK).
 *
 * `blocks` is the builder's list of kit blocks (App\Services\Mail\Kit\KitBlocks
 * cleans it on the way in and again on the way out); `links` is the list of
 * URLs the email carries, fixed when the send starts, which is the ONLY place a
 * tracked click may redirect to. Never returned whole to anything public: the
 * pixel, the click and the unsubscribe routes read single columns.
 */
class Campaign extends Model
{
    public const STATUSES = ['draft', 'scheduled', 'sending', 'sent', 'cancelled'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'blocks' => 'array',
            'links' => 'array',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'test_sent_at' => 'datetime',
            'total_recipients' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
            'group_id' => 'integer',
        ];
    }

    public function group()
    {
        return $this->belongsTo(CampaignGroup::class, 'group_id');
    }
}
