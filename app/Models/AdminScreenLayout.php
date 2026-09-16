<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One operator's panel arrangement for one back-office screen.  (Lane AS)
 *
 * `admin_user_id` IS NOT FILLABLE, AND THAT IS THE SECURITY CONTROL.
 *
 * Every write to this table is scoped by the authenticated admin's id taken
 * from the guard, never from the request. Leaving the column out of $fillable
 * means that even a controller that forwarded the whole request body into
 * create() or update() could not retarget a row at another operator: mass
 * assignment silently drops it and the row keeps the id the query scoped it
 * to. The controller does not do that, and this is the belt that survives
 * somebody later deciding it should.
 *
 * See ProductEditorLayoutTest, which posts an explicit `admin_user_id` for a
 * different operator and asserts that operator's row is untouched.
 */
class AdminScreenLayout extends Model
{
    protected $table = 'admin_screen_layouts';

    /** Deliberately without admin_user_id. See the class docblock. */
    protected $fillable = ['screen', 'layout'];

    protected function casts(): array
    {
        return ['layout' => 'array'];
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }
}
