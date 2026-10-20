<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * 2.60.562 -- the UAE number email ("Ask for UAE number" on the order screen)
 * and its "Update my number" page, in Arabic, published live: the drafts in
 * ArabicInterfaceDrafts::uaeNumberRequest() are the same words, and the owner
 * approved them side by side with the English (Option H). Only rows that do
 * not exist yet are written, so a wording he has changed is never touched.
 */
return new class extends Migration
{
    private const ROWS = [
        'email.phone_request.subject' => ['Update your number in one tap ✨', 'تحديث رقمك بضغطة واحدة ✨'],
        'email.phone_request.preheader' => ['Your UAE phone number is missing. Add it in one tap so our courier can reach you.', 'رقم هاتفك الإماراتي غير موجود. أضفه بضغطة واحدة ليتمكن المندوب من الوصول إليك.'],
        'email.phone_request.eyebrow' => ['Your UAE phone number is missing', 'رقم هاتفك الإماراتي غير موجود'],
        'email.phone_request.heading' => ['Update your number in one tap ✨', 'تحديث رقمك بضغطة واحدة ✨'],
        'email.phone_request.body_foreign_named' => ['Hi :name, thank you for your order! Our courier calls UAE local numbers only, and the one on your order is from another country. Tap below to add your UAE mobile number. It takes just a moment.', 'مرحباً :name، شكراً لطلبك! مندوبنا يتصل بالأرقام المحلية الإماراتية فقط، والرقم المسجّل في طلبك من دولة أخرى. يكفي الضغط على الزر أدناه لإضافة رقم جوالك الإماراتي، فالأمر لا يستغرق سوى لحظة.'],
        'email.phone_request.body_foreign_anon' => ['Hi, thank you for your order! Our courier calls UAE local numbers only, and the one on your order is from another country. Tap below to add your UAE mobile number. It takes just a moment.', 'مرحباً، شكراً لطلبك! مندوبنا يتصل بالأرقام المحلية الإماراتية فقط، والرقم المسجّل في طلبك من دولة أخرى. يكفي الضغط على الزر أدناه لإضافة رقم جوالك الإماراتي، فالأمر لا يستغرق سوى لحظة.'],
        'email.phone_request.body_unreached_named' => ['Hi :name, thank you for your order! Our courier calls UAE local numbers only, and we couldn\'t reach you on the number on your order. Tap below to add your UAE mobile number. It takes just a moment.', 'مرحباً :name، شكراً لطلبك! مندوبنا يتصل بالأرقام المحلية الإماراتية فقط، ولم نتمكن من الوصول إليك على الرقم المسجّل في طلبك. يكفي الضغط على الزر أدناه لإضافة رقم جوالك الإماراتي، فالأمر لا يستغرق سوى لحظة.'],
        'email.phone_request.body_unreached_anon' => ['Hi, thank you for your order! Our courier calls UAE local numbers only, and we couldn\'t reach you on the number on your order. Tap below to add your UAE mobile number. It takes just a moment.', 'مرحباً، شكراً لطلبك! مندوبنا يتصل بالأرقام المحلية الإماراتية فقط، ولم نتمكن من الوصول إليك على الرقم المسجّل في طلبك. يكفي الضغط على الزر أدناه لإضافة رقم جوالك الإماراتي، فالأمر لا يستغرق سوى لحظة.'],
        'email.phone_request.row_have' => ['Number on your order', 'الرقم المسجّل في طلبك'],
        'email.phone_request.row_need' => ['UAE number needed', 'الرقم الإماراتي المطلوب'],
        'email.phone_request.button' => ['Update my number', 'تحديث رقمي'],
        'email.phone_request.note' => ['Or reply to this email with your number. Thank you! 💕', 'أو يمكنك الرد على هذه الرسالة برقمك. شكراً لك! 💕'],
        'store.order_phone.page_title' => ['Your UAE mobile number', 'رقم جوالك الإماراتي'],
        'store.order_phone.heading' => ['Where can our courier reach you?', 'على أي رقم يتواصل معك المندوب؟'],
        'store.order_phone.lead' => ['Share a UAE mobile number and we\'ll make sure your order finds you. 💕', 'شاركونا رقم جوال إماراتي وسنحرص على أن يصلك طلبك بكل سهولة. 💕'],
        'store.order_phone.order_label' => ['Order :number', 'الطلب :number'],
        'store.order_phone.field_label' => ['UAE mobile number', 'رقم الجوال الإماراتي'],
        'store.order_phone.hint' => ['UAE mobile numbers start with 05 (or 5 after +971).', 'تبدأ أرقام الجوال الإماراتية بـ 05 (أو 5 بعد ‎+971).'],
        'store.order_phone.save' => ['Save my number', 'حفظ رقمي'],
        'store.order_phone.invalid' => ['Please enter a UAE mobile number, for example 050 123 4567.', 'يرجى إدخال رقم جوال إماراتي، مثل 050 123 4567.'],
        'store.order_phone.done_named' => ['Thank you, :name! We\'ve added :phone to your order. Your order will be on its way soon.', 'شكراً لك يا :name! أضفنا الرقم :phone إلى طلبك، وسيصلك طلبك قريباً.'],
        'store.order_phone.done' => ['Thank you! We\'ve added :phone to your order. Your order will be on its way soon.', 'شكراً لك! أضفنا الرقم :phone إلى طلبك، وسيصلك طلبك قريباً.'],
        'store.order_phone.closed' => ['This order can\'t be changed here any more. Message us on WhatsApp and we\'ll gladly help.', 'لم يعد بالإمكان تعديل هذا الطلب من هنا. راسلنا عبر واتساب وسنسعد بمساعدتك.'],
        'store.order_phone.not_found' => ['This link has expired or is not valid. Message us on WhatsApp with your order number and we\'ll gladly help.', 'انتهت صلاحية هذا الرابط أو أنه غير صالح. راسلنا عبر واتساب برقم طلبك وسنسعد بمساعدتك.'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        $have = DB::table('translations')->where('locale', 'ar')->where('group', 'ui')->where('item_id', 0)
            ->whereIn('field', array_keys(self::ROWS))->pluck('field')->all();
        $now = now();
        $insert = [];

        foreach (self::ROWS as $field => [$english, $arabic]) {
            if (in_array($field, $have, true)) {
                continue;
            }

            $insert[] = [
                'locale' => 'ar', 'group' => 'ui', 'item_id' => 0, 'field' => $field, 'value' => $arabic,
                'status' => 'published', 'source' => 'manual', 'source_hash' => sha1($english),
                'reviewed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ];
        }

        if ($insert === []) {
            return;
        }

        DB::table('translations')->insert($insert);

        try {
            foreach (['kbb.translations.ar', 'kbb.translations.en'] as $key) {
                Cache::forget($key);
            }
        } catch (\Throwable) {
            // A cache that cannot forget expires on its own.
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        foreach (self::ROWS as $field => [, $arabic]) {
            DB::table('translations')->where('locale', 'ar')->where('group', 'ui')->where('item_id', 0)
                ->where('field', $field)->where('value', $arabic)->delete();
        }
    }
};
