<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fields an import is KNOWINGLY not carrying, on the live progress page.
 *
 * Lane PX. `import_checkpoints` already answers "how far did this entity get"
 * and "how many rows were created, changed, left alone and refused", and the
 * progress page shows all five per entity. Every one of them counts ROWS.
 *
 * WHY A ROW COUNT IS NOT THE WHOLE ANSWER. A migration can bring every row
 * across and still lose a column out of each of them, and that failure has no
 * row-count symptom at all: the bar reaches 100%, the tally reads 671 of 671
 * refused 0, and the parcel weights are gone. The owner's sentence was
 * "everything must be compatible without anything skipping or losing", and until
 * this column existed the page he watches could only answer the first half.
 *
 * NAMES AND NOT A NUMBER, and that is the whole design of this column:
 *
 *   A COUNT CANNOT BE MERGED ACROSS SLICES. A background import is many HTTP
 *   requests over the same file, and each one reports the fields IT saw. Adding
 *   the counts would count `weight` once per batch and print "340 fields
 *   skipped" for a shop that has twenty. The truthful unit is the distinct
 *   field, so the set has to be unioned, and a set cannot be unioned from a
 *   number.
 *
 *   IT IS ALSO THE ONLY THING WORTH READING. "20 fields skipped" sends the owner
 *   looking; "weight, length, width, ..." tells him whether he cares. The count
 *   the page prints is derived from this list rather than stored beside it, so
 *   the two cannot disagree.
 *
 * Stored as a comma-separated list of field names. A text column and not JSON:
 * this is read by a screen and by one query, never filtered on, and the import
 * console already treats these tables as narrative rather than as structure.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('import_checkpoints')) {
            return;
        }

        if (Schema::hasColumn('import_checkpoints', 'dropped_fields')) {
            return;
        }

        Schema::table('import_checkpoints', function (Blueprint $t): void {
            $t->text('dropped_fields')->nullable();
        });
    }

    /**
     * No destructive down(), the same as import_checkpoints' own migration: this
     * table is the only record of where a part-finished import got to, and a
     * rollback that drops it turns a resumable run into one that starts again.
     */
    public function down(): void {}
};
