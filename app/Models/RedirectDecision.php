<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One answer the owner gave to one question from `RedirectMap`.
 *
 * Deliberately thin. Everything that decides what an answer MEANS lives in
 * `App\Services\Import\RedirectDecisions` — including the staleness rule, which
 * is the one piece of logic here that can quietly do the wrong thing and so is
 * kept next to the code that reads the map rather than on the model where a
 * second caller could skip it.
 *
 * NOT MASS-ASSIGNED FROM A REQUEST anywhere. `RedirectDecisions::record()`
 * builds every column itself out of the proposal the server derived — the
 * target in particular is never taken from the browser, or an admin-api caller
 * could approve `/shop/` → anywhere at all.
 */
class RedirectDecision extends Model
{
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
