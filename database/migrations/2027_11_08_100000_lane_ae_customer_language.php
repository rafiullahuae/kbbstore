<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Lane AE: the customer's language, and the Arabic an Arabic order's paperwork
 * was still missing.
 *
 * The owner: "if users placed order from arabic language, everything need to
 * followed by arabic stuff for that specific users, and i will want a separate
 * list of customers who did from arabic version".
 *
 *   1. customers.locale (nullable) and customers.locale_admin (0/1): the
 *      language this shopper last used on the shop, and whether the owner
 *      fixed it by hand on Store -> Customers (then nothing automatic ever
 *      overwrites it).
 *   2. cart_recoveries.locale and stock_alerts.locale (nullable): the page's
 *      language when the address was given, for a reminder to a shopper who
 *      has no account.
 *   3. `customer_locale_schema` = '1' once the columns exist, so the code asks
 *      the settings map it already holds rather than the schema on every
 *      request (the CheckoutEmailCapture pattern; AdminCustomersTest budget).
 *   4. The Arabic for the strings this lane keyed (refund and invoice
 *      subjects, the reset/verify subjects, "Email us", the default
 *      signature, the invoice heading), PUBLISHED -- the owner's rule since
 *      Lane ARF: the Arabic shop is Arabic by default. A row that already
 *      exists, in any state, is left alone.
 *   5. Marketing Emails -> Customer groups gains the ready group "Arabic
 *      customers" (preset), unless a group with that key exists.
 *
 * LITERALS ONLY (the 2.60.512 rule): the updater can run this while the
 * previous release's classes are still in opcache, so it names no App\ class.
 * CustomerLanguageTest pins that, and that ROWS equals InterfaceStrings and
 * ArabicInterfaceDrafts word for word.
 */
return new class extends Migration
{
    /** field => [English, Arabic]. */
    private const ROWS = [
        'email.refund.subject' => ['Refund sent for your :store order :number', 'تم إرسال المبلغ المسترد لطلبك :number من :store'],
        'email.invoice.subject_ref' => ['Invoice :reference for your :store order :number', 'الفاتورة :reference لطلبك :number من :store'],
        'email.invoice.subject' => ['Invoice for your :store order :number', 'فاتورة طلبك :number من :store'],
        'email.reset.subject' => ['Reset your K Beauty Bliss password', 'إعادة تعيين كلمة المرور في كي بيوتي بليس'],
        'email.verify.subject' => ['Confirm your email address', 'أكّدي عنوان بريدكِ الإلكتروني'],
        'email.kit.help_email_label' => ['Email us', 'راسلينا'],
        'email.kit.signoff_love' => ['With love,', 'مع حبّنا،'],
        'email.kit.signoff_team' => ['the :store team', 'فريق :store'],
        'invoice.doctype_invoice' => ['Invoice', 'فاتورة'],
        'invoice.doctype_tax' => ['Tax Invoice', 'فاتورة ضريبية'],
    ];

    private const GROUP_RULES = '[{"field":"arabic","op":"yes","value":null}]';

    public function up(): void
    {
        if (Schema::hasTable('customers')) {
            if (! Schema::hasColumn('customers', 'locale')) {
                Schema::table('customers', function (Blueprint $t) {
                    $t->string('locale', 5)->nullable();
                });
            }

            if (! Schema::hasColumn('customers', 'locale_admin')) {
                Schema::table('customers', function (Blueprint $t) {
                    $t->unsignedTinyInteger('locale_admin')->default(0);
                });
            }
        }

        foreach (['cart_recoveries', 'stock_alerts'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'locale')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->string('locale', 5)->nullable();
                });
            }
        }

        if (Schema::hasTable('settings')
            && Schema::hasTable('customers')
            && Schema::hasColumn('customers', 'locale') && Schema::hasColumn('customers', 'locale_admin')) {
            DB::table('settings')->updateOrInsert(['key' => 'customer_locale_schema'], ['value' => '1', 'autoload' => true]);
            Cache::forget('kbb.settings');
        }

        $this->seedArabic();
        $this->seedGroup();
    }

    private function seedArabic(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        $existing = array_flip(DB::table('translations')
            ->where('locale', 'ar')->where('group', 'ui')->where('item_id', 0)
            ->whereIn('field', array_keys(self::ROWS))
            ->pluck('field')->all());

        $now = now();
        $insert = [];

        foreach (self::ROWS as $field => [$english, $arabic]) {
            if (isset($existing[$field])) {
                continue;
            }

            $insert[] = [
                'locale' => 'ar', 'group' => 'ui', 'item_id' => 0, 'field' => $field, 'value' => $arabic,
                'status' => 'published', 'source' => 'manual', 'source_hash' => sha1($english),
                'reviewed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ];
        }

        if ($insert !== []) {
            DB::table('translations')->insert($insert);
        }

        try {
            Cache::forget('kbb.translations.en');
            Cache::forget('kbb.translations.ar');
        } catch (\Throwable) {
            // The loader re-reads on the next request either way.
        }
    }

    private function seedGroup(): void
    {
        if (! Schema::hasTable('mkt_segments') || DB::table('mkt_segments')->where('key', 'arabic-customers')->exists()) {
            return;
        }

        $now = now();

        DB::table('mkt_segments')->insert([
            'key' => 'arabic-customers',
            'name' => 'Arabic customers',
            'audience' => 'customers',
            'match' => 'all',
            'rules' => self::GROUP_RULES,
            'preset' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        if (Schema::hasTable('mkt_segments')) {
            DB::table('mkt_segments')->where('key', 'arabic-customers')->where('preset', true)->delete();
        }

        if (Schema::hasTable('translations')) {
            foreach (self::ROWS as $field => [, $arabic]) {
                DB::table('translations')
                    ->where('locale', 'ar')->where('group', 'ui')->where('item_id', 0)
                    ->where('field', $field)->where('value', $arabic)
                    ->delete();
            }
        }

        if (Schema::hasTable('settings')) {
            DB::table('settings')->where('key', 'customer_locale_schema')->delete();
            Cache::forget('kbb.settings');
        }

        foreach (['cart_recoveries', 'stock_alerts'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'locale')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('locale'));
            }
        }

        if (Schema::hasTable('customers')) {
            foreach (['locale', 'locale_admin'] as $column) {
                if (Schema::hasColumn('customers', $column)) {
                    Schema::table('customers', fn (Blueprint $t) => $t->dropColumn($column));
                }
            }
        }
    }
};
