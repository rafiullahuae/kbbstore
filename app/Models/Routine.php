<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\RoutineConcerns;
use App\Support\RoutineRoles;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of `routines`: what the owner has CHANGED about a routine.
 *
 * Not the routine itself. The eight routines are App\Support\RoutineConcerns
 * crossed with App\Support\RoutineRoles, and they work on a shop that has never
 * opened the admin screen. See the migration for why it is that way round.
 *
 * Every accessor here answers "what did the owner say", and every one of them
 * can answer "nothing". App\Services\BuildMyRoutine is the only thing that
 * turns that into a routine, because the fallbacks are keyed strings and a
 * model is not where a translation belongs.
 */
class Routine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'is_enabled' => 'bool',
            'position' => 'int',
        ];
    }

    /**
     * The step list the owner saved, cleaned, or null if they saved none.
     *
     * Cleaned here rather than at the call site because the column is JSON and
     * JSON survives a role being renamed or dropped between packages: a stored
     * `['cleanse', 'essence', 'protect']` on a build that has no `essence` must
     * render as a two-step routine, not as a page with a blank step on it.
     *
     * An empty result reads as null — "the owner has not chosen" — rather than
     * as a zero-step routine. A routine with no steps is not a thing anybody
     * meant to save, and it would render as an empty page with a title on it.
     *
     * @return list<string>|null
     */
    public function stepRoles(): ?array
    {
        if (! is_array($this->steps)) {
            return null;
        }

        $roles = [];

        foreach ($this->steps as $role) {
            $role = RoutineRoles::normalise($role);

            if ($role !== null && ! in_array($role, $roles, true)) {
                $roles[] = $role;
            }
        }

        return $roles === [] ? null : $roles;
    }

    /** The owner's title, or null for the keyed default. */
    public function ownTitle(): ?string
    {
        $title = trim((string) $this->title);

        return $title === '' ? null : $title;
    }

    /** The owner's blurb, or null for the keyed default. */
    public function ownBlurb(): ?string
    {
        $blurb = trim((string) $this->blurb);

        return $blurb === '' ? null : $blurb;
    }

    /** The coupon code the owner attached to THIS routine, or null. */
    public function ownCouponCode(): ?string
    {
        $code = trim((string) $this->coupon_code);

        return $code === '' ? null : $code;
    }

    /**
     * The overrides, keyed by concern, for the concerns that have one.
     *
     * One query for the whole feature. Rows whose concern is no longer in
     * RoutineConcerns::LIST are dropped on the way out: the column is not a
     * foreign key — the concern list is code, not a table — so a slug that has
     * been retired leaves a row behind, and that row must not become a ninth
     * routine on the list page.
     *
     * @return array<string, self>
     */
    public static function overrides(): array
    {
        $out = [];

        foreach (self::query()->get() as $row) {
            if (RoutineConcerns::exists($row->concern)) {
                $out[(string) $row->concern] = $row;
            }
        }

        return $out;
    }
}
