<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row of `media_usages`: this media file is on that product/brand/category.
 *
 * Named ...Record rather than MediaUsage because App\Support\MediaUsage already
 * holds the derivation this table indexes, and the two being one word apart in
 * the same tree is how the wrong one gets imported.
 *
 * Writing goes through App\Support\MediaUsageWriter, never through here
 * directly: the writer is what keeps the recorded answer and the derived one
 * the same statement.
 */
class MediaUsageRecord extends Model
{
    protected $table = 'media_usages';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['media_id' => 'int', 'owner_id' => 'int'];
    }
}
