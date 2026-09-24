<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One administrative act, or one thing the shop refused, with its evidence.
 *
 * Written only by App\Services\SecurityModule — nothing else in the
 * application constructs one — and read only by
 * App\Http\Controllers\Admin\SecurityController, which is behind the
 * `security.view` capability. There is no API surface on this model and there
 * must not be: every row carries an operator's email and an IP address, which
 * is the pair `/api/*` has leaked before (`reviews.author_email` and
 * `reviews.ip`, pinned in tests/Feature/ApiSecurityTest.php). It carries no
 * toApi() for the same reason Product::toApi() exists — an allowlist can only
 * protect a surface that has one, and the safest surface is none.
 *
 * `$timestamps = false`: the table names its own two times, occurred_at and
 * last_seen_at, and a created_at that always equals occurred_at would be a
 * third column saying the same thing.
 */
class AuditEvent extends Model
{
    protected $table = 'audit_events';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'hits' => 'int',
            'actor_id' => 'int',
        ];
    }

    /**
     * The window the Security screen reads, newest first.
     *
     * A scope rather than a helper on the controller so the screen's counts
     * and the screen's list cannot disagree about where the window starts.
     */
    public function scopeSince(Builder $query, \DateTimeInterface $from): Builder
    {
        return $query->where('occurred_at', '>=', $from);
    }
}
