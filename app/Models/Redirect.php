<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Redirect extends Model
{

    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'bool', 'last_hit_at' => 'datetime', 'hits' => 'int', 'status_code' => 'int', 'auto_created' => 'bool'];
    }

    /*
     * Every write to this table drops CheckRedirects' cached source index, and
     * those hooks are registered in AppServiceProvider::boot() rather than in a
     * booted() method here.
     *
     * NOT A STYLE CHOICE — booted() was tried first and does not hold. Eloquent
     * runs it ONCE PER PROCESS (Model::$booted is static and keyed by class),
     * while the event dispatcher is replaced with every application instance.
     * This model is first booted by the Phase 9 seed migration, so in any
     * process that outlives one application — the test suite, a queue worker,
     * `artisan migrate` — the listener booted() registered belongs to a
     * dispatcher nothing dispatches to any more, and the index silently stops
     * being evicted. Measured: a row created after a page had been rendered was
     * not in the index, and the address it named kept serving its page.
     *
     * Under PHP-FPM it would have worked, which is what makes it the wrong kind
     * of bug — invisible exactly where it is checked. AppServiceProvider::boot()
     * runs per application instance, which is what the shipping hooks beside it
     * are doing there for the same reason.
     */

}
