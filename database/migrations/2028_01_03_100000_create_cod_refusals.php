<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lane CODR: cash on delivery hidden from shoppers who refused a dispatched
 * order (the owner, 1 November 2026).
 *
 * The table, then the past filled in ONCE: every order whose status is
 * `cancelled` today AND whose notes record the move shipped -> cancelled --
 * this shop writes "Status changed from shipped to cancelled.", WooCommerce
 * wrote "Order status changed from Shipped to Cancelled." -- is a refusal,
 * unless the same email or phone has a completed order. An order cancelled
 * without having been shipped never counts.
 *
 * LITERALS ONLY (the 2.60.512 rule): no App\ class, because the updater may
 * still run the previous release's classes from opcache. The two normalisers
 * below are copies of App\Services\Payments\CodRefusals::email()/phone(), and
 * CodRefusalsTest runs both over the same inputs, so a backfilled row and a
 * live one can never be spelled differently. Chunked by id; idempotent (an
 * order already in the table is left as it is), so running it twice adds
 * nothing.
 */
return new class extends Migration
{
    /** Copy of CodRefusals::email(). */
    public static function email(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return ($email !== '' && str_contains($email, '@') && mb_strlen($email) <= 190) ? $email : null;
    }

    /** Copy of CodRefusals::phone(). */
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
        if (! Schema::hasTable('cod_refusals')) {
            Schema::create('cod_refusals', function (Blueprint $t) {
                $t->id();
                $t->string('email', 190)->nullable()->index();
                $t->string('phone', 32)->nullable()->index();
                $t->unsignedBigInteger('order_id')->unique();
                $t->string('order_number')->nullable();
                $t->timestamp('refused_at')->nullable();
                $t->timestamp('lifted_at')->nullable();
                $t->string('lifted_by', 190)->nullable();
                $t->timestamps();
            });
        }

        // Everyone with a completed order, as normalised emails and phones.
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
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))->from('order_notes')
                    ->whereColumn('order_notes.order_id', 'orders.id')
                    ->whereRaw('LOWER(order_notes.content) LIKE ?', ['%from shipped to cancelled%']);
            })
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
            echo "COD refusals: {$added} refused deliveries recorded.\n";
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cod_refusals');
    }
};
