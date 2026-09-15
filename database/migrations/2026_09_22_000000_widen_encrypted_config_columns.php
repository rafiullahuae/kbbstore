<?php

/*
 * `payment_providers.config` and `mail_credentials.config` were declared with
 * $t->json(). Both hold an `encrypted:array` cast, which is CIPHERTEXT, and
 * ciphertext is not JSON.
 *
 * On SQLite `json` is just `text`, so the suite never noticed. On MySQL and
 * MariaDB a JSON column is validated on every write:
 *
 *   MariaDB 10.x  longtext ... CHECK (json_valid(`config`))
 *                 SQLSTATE[23000] 4025 CONSTRAINT `payment_providers.config`
 *                 failed for `kbb_test`.`payment_providers`
 *   MySQL 8.x     a native JSON column
 *                 SQLSTATE[22032] 3140 Invalid JSON text
 *
 * Laravel's `encrypted:array` serialises to JSON, encrypts, and stores
 * base64(json_encode(['iv' => ..., 'value' => ..., 'mac' => ...])) — a base64
 * blob with no JSON structure at all. So on the production host EVERY save of a
 * gateway key or an SMTP password failed, and no test could see it: 110 of the
 * 558 tests in this suite fail against a real MySQL for this one reason.
 *
 * The column becomes longText, which is what json() already compiled to on
 * MySQL minus the validation, and what it already was on SQLite. No data
 * conversion is needed in either direction: the write never succeeded on MySQL,
 * so there is nothing stored there to migrate, and on SQLite the storage class
 * does not change.
 *
 * Not a rewrite of 0001_01_01_000000_create_kbb_schema or of
 * 2026_09_15_030000_create_mail_credentials: both have already run on the live
 * database and will not run again.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** table => column, every column carrying an `encrypted:` cast. */
    private const ENCRYPTED = [
        'payment_providers' => 'config',
        'mail_credentials' => 'config',
    ];

    public function up(): void
    {
        foreach (self::ENCRYPTED as $table => $column) {
            // Guarded because mail_credentials arrived in a later package than
            // payment_providers; a server part-way through the sequence has one
            // table and not the other.
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($column) {
                $t->longText($column)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Deliberately not reversed. Putting the JSON validation back would
        // restore a column the application cannot write to.
    }
};
