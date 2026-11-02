<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Hide cash on delivery from every customer with a cancelled COD order in the
 * shop's history (2.60.636). The owner, 2 November 2026: "apply this feature
 * for all cancelled orders in history. but keep turned off for those cancelled
 * customers who has atleast one completed order ... give me a seperate toggle
 * button for those customers".
 *
 * `source` tells the two kinds of row apart:
 *   refused   the order went Shipped -> Cancelled (2.60.635, and live from now)
 *   history   any other order cancelled before this package was installed
 * Each has its own switch (CodRefusals::SETTING / ::HISTORY_SETTING), so the
 * owner can turn the past-cancelled group off without touching the refusers.
 *
 * Reads no App\ class (ArabicShippableTest's rule since 2.60.512): the two
 * normalisers are copies of CodRefusals::email()/phone(), and
 * CodRefusalsTest runs both copies over the same inputs. Running it twice
 * adds nothing the second time: an order already in the table is skipped.
 */
return new class extends Migration
{
    public static function email(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return ($email !== '' && str_contains($email, '@') && mb_strlen($email) <= 190) ? $email : null;
    }

    public static function phone(?string $phone): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }

        if (strlen($d) === 10 && $d[0] === '0') {
            $d = '971' . substr($d, 1);
        } elseif (strlen($d) === 9 && $d[0] === '5') {
            $d = '971' . $d;
        }

        return (strlen($d) >= 9 && strlen($d) <= 20) ? $d : null;
    }

    public function up(): void
    {
        if (! Schema::hasColumn('cod_refusals', 'source')) {
            Schema::table('cod_refusals', function (Blueprint $t) {
                $t->string('source', 12)->default('refused')->index();
            });
        }

        // Cash on delivery orders only (the owner, 2 November: "ignore if any
        // customer's order failed with tabby, tamara or card payment"). Rows
        // 2.60.635 may already have written for another method go first.
        $notCod = DB::table('orders')->whereRaw("LOWER(TRIM(COALESCE(payment_method, ''))) <> 'cod'")->select('id');
        DB::table('cod_refusals')->whereIn('order_id', $notCod)->delete();

        $doneEmail = [];
        $donePhone = [];

        DB::table('orders')->where('status', 'completed')->whereNull('deleted_at')
            ->select(['id', 'email', 'phone'])
            ->chunkById(1000, function ($rows) use (&$doneEmail, &$donePhone) {
                foreach ($rows as $r) {
                    if (($e = self::email($r->email)) !== null) {
                        $doneEmail[$e] = true;
                    }
                    if (($p = self::phone($r->phone)) !== null) {
                        $donePhone[$p] = true;
                    }
                }
            });

        $added = 0;
        $now = now();

        DB::table('orders')->where('status', 'cancelled')->whereNull('deleted_at')
            ->whereRaw("LOWER(TRIM(payment_method)) = 'cod'")
            ->select(['id', 'order_number', 'email', 'phone', 'updated_at'])
            ->chunkById(500, function ($rows) use (&$added, $doneEmail, $donePhone, $now) {
                $have = DB::table('cod_refusals')->whereIn('order_id', $rows->pluck('id')->all())
                    ->pluck('order_id')->flip()->all();

                $insert = [];
                foreach ($rows as $r) {
                    if (isset($have[$r->id])) {
                        continue;
                    }

                    $email = self::email($r->email);
                    $phone = self::phone($r->phone);

                    if (($email === null && $phone === null)
                        || ($email !== null && isset($doneEmail[$email]))
                        || ($phone !== null && isset($donePhone[$phone]))) {
                        continue;
                    }

                    $insert[] = [
                        'email' => $email,
                        'phone' => $phone,
                        'order_id' => (int) $r->id,
                        'order_number' => (string) $r->order_number,
                        'refused_at' => $r->updated_at ?? $now,
                        'lifted_at' => null,
                        'lifted_by' => null,
                        'source' => 'history',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($insert !== []) {
                    DB::table('cod_refusals')->insert($insert);
                    $added += count($insert);
                }
            }, 'id');

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            echo "COD refusals: {$added} past cancelled orders recorded.\n";
        }
    }

    public function down(): void
    {
        DB::table('cod_refusals')->where('source', 'history')->delete();
    }
};
