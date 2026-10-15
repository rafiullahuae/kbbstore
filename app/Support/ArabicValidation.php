<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The framework's validation messages, in Arabic (Lane AR, stage 2).
 *
 * Laravel writes these from its own `validation` group, which ships in English
 * only, so on /ar a form refused with "The email field is required." -- the
 * one English sentence a shopper filling in an Arabic form is certain to see.
 * App\Services\Translation\DatabaseTranslationLoader lays this under the
 * Arabic `validation` group (a published row in the translations table still
 * wins), and only for Arabic: English keeps the framework's own words, byte
 * for byte.
 *
 * Only the rules the storefront's forms actually use (counted from the
 * controllers' rule arrays) and the field names those forms post. Impersonal,
 * as validation copy is in Arabic shops; Modern Standard Arabic, UAE wording.
 */
final class ArabicValidation
{
    /** @var array<string, string|array<string, string>> */
    public const LINES = [
        'required' => 'حقل :attribute مطلوب.',
        'required_if' => 'حقل :attribute مطلوب.',
        'required_with' => 'حقل :attribute مطلوب.',
        'required_without' => 'حقل :attribute مطلوب.',
        'filled' => 'حقل :attribute مطلوب.',
        'email' => 'يُرجى إدخال بريد إلكتروني صحيح في حقل :attribute.',
        'string' => 'يجب أن يكون :attribute نصًا.',
        'integer' => 'يجب أن يكون :attribute رقمًا صحيحًا.',
        'numeric' => 'يجب أن يكون :attribute رقمًا.',
        'boolean' => 'قيمة :attribute غير صحيحة.',
        'array' => 'قيمة :attribute غير صحيحة.',
        'in' => 'القيمة المختارة في :attribute غير صحيحة.',
        'exists' => 'القيمة المختارة في :attribute غير صحيحة.',
        'unique' => ':attribute مستخدم من قبل.',
        'confirmed' => 'تأكيد :attribute غير مطابق.',
        'regex' => 'صيغة :attribute غير صحيحة.',
        'url' => 'يجب أن يكون :attribute رابطًا صحيحًا.',
        'date' => ':attribute ليس تاريخًا صحيحًا.',
        'before' => 'يجب أن يكون :attribute تاريخًا قبل :date.',
        'after' => 'يجب أن يكون :attribute تاريخًا بعد :date.',
        'ip' => 'يجب أن يكون :attribute عنوان IP صحيحًا.',
        'image' => 'يجب أن يكون :attribute صورة.',
        'file' => 'يجب أن يكون :attribute ملفًا.',
        'mimes' => 'يجب أن يكون :attribute ملفًا من نوع: :values.',
        'uploaded' => 'تعذّر رفع :attribute.',
        'accepted' => 'يجب الموافقة على :attribute.',
        'min' => [
            'numeric' => 'يجب ألا يقل :attribute عن :min.',
            'string' => 'يجب ألا يقل :attribute عن :min أحرف.',
            'array' => 'يجب أن يحتوي :attribute على :min عناصر على الأقل.',
            'file' => 'يجب ألا يقل حجم :attribute عن :min كيلوبايت.',
        ],
        'max' => [
            'numeric' => 'يجب ألا يزيد :attribute على :max.',
            'string' => 'يجب ألا يزيد :attribute على :max حرفًا.',
            'array' => 'يجب ألا يحتوي :attribute على أكثر من :max عناصر.',
            'file' => 'يجب ألا يزيد حجم :attribute على :max كيلوبايت.',
        ],
        'size' => [
            'numeric' => 'يجب أن يكون :attribute :size.',
            'string' => 'يجب أن يتكون :attribute من :size أحرف.',
            'array' => 'يجب أن يحتوي :attribute على :size عناصر.',
            'file' => 'يجب أن يكون حجم :attribute :size كيلوبايت.',
        ],
        'between' => [
            'numeric' => 'يجب أن يكون :attribute بين :min و:max.',
            'string' => 'يجب أن يكون طول :attribute بين :min و:max حرفًا.',
        ],

        'attributes' => [
            'email' => 'البريد الإلكتروني',
            'billing_email' => 'البريد الإلكتروني',
            'author_email' => 'البريد الإلكتروني',
            'customer.email' => 'البريد الإلكتروني',
            'password' => 'كلمة المرور',
            'account_password' => 'كلمة المرور',
            'name' => 'الاسم',
            'author' => 'الاسم',
            'author_name' => 'الاسم',
            'customer.name' => 'الاسم الكامل',
            'first_name' => 'الاسم الأول',
            'billing_first_name' => 'الاسم الكامل',
            'last_name' => 'اسم العائلة',
            'phone' => 'رقم الهاتف',
            'billing_phone' => 'رقم الهاتف',
            'customer.phone' => 'رقم الهاتف',
            'billing_kbb_whatsapp' => 'رقم واتساب',
            'company' => 'اسم الشركة',
            'line1' => 'المبنى / الشقة أو الفيلا',
            'billing_address_1' => 'المبنى / الشقة أو الفيلا',
            'customer.address' => 'العنوان',
            'line2' => 'المنطقة / الشارع',
            'apartment' => 'الشقة',
            'area' => 'المنطقة',
            'city' => 'المدينة',
            'state' => 'الإمارة',
            'billing_state' => 'الإمارة',
            'customer.emirate' => 'الإمارة',
            'country' => 'الدولة',
            'billing_country' => 'الدولة',
            'customer.country' => 'الدولة',
            'postcode' => 'الرمز البريدي',
            'customer_note' => 'ملاحظات الطلب',
            'gift_note' => 'رسالة الهدية',
            'payment_method' => 'طريقة الدفع',
            'shipping_method' => 'طريقة التوصيل',
            'ship_method' => 'طريقة التوصيل',
            'coupon_code' => 'كود الخصم',
            'code' => 'الكود',
            'rating' => 'التقييم',
            'title' => 'العنوان',
            'content' => 'نص التقييم',
            'body' => 'الرسالة',
            'message' => 'الرسالة',
            'topic' => 'الموضوع',
            'photos' => 'الصور',
            'photos.*' => 'الصورة',
            'sr_photos' => 'الصور',
            'quantity' => 'الكمية',
            'consent' => 'الموافقة',
            'token' => 'الرمز',
            'age' => 'العمر',
            'skin_type' => 'نوع البشرة',
            'concerns' => 'مشكلات البشرة',
        ],
    ];
}
