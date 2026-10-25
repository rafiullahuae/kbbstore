<?php

declare(strict_types=1);

namespace App\Services\Translation;

/**
 * THE REDESIGNED ACCOUNT AREA'S WORDS, ENGLISH AND ARABIC TOGETHER (Lane ACC).
 *
 * The owner, 25 October: "I want to redesign the Account dashboard properly
 * like big ecommerce stores, also more easy to use on mobile devices ... top
 * beautiful menu ... each page will have proper go back button and dashboard
 * button ... search function or by date / month filter ... order detail page
 * ... with proper road-map / steps ... also keep easy to access, change /
 * update password option".
 *
 * One list, so the two languages cannot drift apart: InterfaceStrings reads the
 * English (group `store`, keys below), ArabicInterfaceDrafts the Arabic under
 * `store.` + key, and 2027_12_18_100000_account_area_arabic publishes the
 * Arabic as the owner's own (published, manual) so /ar reads Arabic on the day
 * the package lands. Feminine address, as the rest of the shop.
 */
final class AccountAreaStrings
{
    /** key (without `store.`) => [English, Arabic] */
    public const ROWS = [
        // The menu and the bar at the top of every account page.
        'account.menu_label' => ['Account menu', 'قائمة الحساب'],
        'account.nav_dashboard' => ['Dashboard', 'لوحة الحساب'],
        'account.nav_orders' => ['My orders', 'طلباتي'],
        'account.nav_wishlist' => ['Wishlist', 'المفضلة'],
        'account.nav_addresses' => ['Addresses', 'العناوين'],
        'account.nav_profile' => ['Profile & password', 'الملف الشخصي وكلمة المرور'],
        'account.nav_track' => ['Track an order', 'تتبع طلب'],
        'account.nav_sign_out' => ['Sign out', 'تسجيل الخروج'],
        'account.back' => ['Back', 'رجوع'],
        'account.back_to_shop' => ['Back to the shop', 'العودة إلى المتجر'],
        'account.to_dashboard' => ['Account dashboard', 'الذهاب إلى لوحة الحساب'],
        // The dashboard.
        'account.hello' => ['Hello, :name', 'مرحباً، :name'],
        'account.member_since' => ['Member since :year', 'عضوة منذ :year'],
        'account.stat_orders' => ['Orders', 'الطلبات'],
        'account.stat_in_progress' => ['In progress', 'قيد التنفيذ'],
        'account.stat_wishlist' => ['Wishlist', 'المفضلة'],
        'account.latest_order' => ['Latest order', 'آخر طلب'],
        'account.view_order' => ['View order', 'عرض الطلب'],
        'account.all_orders' => ['All orders', 'كل الطلبات'],
        'account.your_account' => ['Your account', 'حسابكِ'],
        'account.row_orders_note' => ['Every order and where it is', 'كل طلباتكِ وحالة كل طلب'],
        'account.row_wishlist_note' => ['Products you saved for later', 'منتجات حفظتِها لوقت لاحق'],
        'account.row_addresses_note' => ['Where we deliver your orders', 'عناوين توصيل طلباتكِ'],
        'account.row_profile_note' => ['Your name and mobile number', 'اسمكِ ورقم جوالكِ'],
        'account.row_password' => ['Change password', 'تغيير كلمة المرور'],
        'account.row_password_note' => ['Keep your account safe', 'حافظي على أمان حسابكِ'],
        'account.row_track_note' => ['Find any order by its number', 'ابحثي عن أي طلب برقمه'],
        'account.no_orders_yet' => ['No orders yet. Your first one will show here.', 'لا توجد طلبات بعد. سيظهر طلبكِ الأول هنا.'],
        'account.start_shopping' => ['Start shopping', 'ابدئي التسوق'],
        // The steps an order goes through.
        'account.steps_label' => ['Order progress', 'مراحل الطلب'],
        'account.step_placed' => ['Placed', 'تم الطلب'],
        'account.step_confirmed' => ['Confirmed', 'تم التأكيد'],
        'account.step_shipped' => ['Shipped', 'تم الشحن'],
        'account.step_delivered' => ['Delivered', 'تم التوصيل'],
        // The coloured strip on each order.
        'orders.band_pending' => ['Waiting for payment', 'بانتظار الدفع'],
        'orders.band_processing' => ['Being prepared', 'نجهّز طلبكِ'],
        'orders.band_onhold' => ['On hold, we will be in touch', 'معلّق، سنتواصل معكِ'],
        'orders.band_shipped' => ['On its way to you', 'في الطريق إليكِ'],
        'orders.band_completed' => ['Delivered', 'تم التوصيل'],
        'orders.band_cancelled' => ['Cancelled', 'ملغي'],
        'orders.band_refunded' => ['Refunded', 'تم الاسترداد'],
        'orders.band_failed' => ['Payment did not go through', 'لم تتم عملية الدفع'],
        // Search and dates on the orders list.
        'orders.filter_label' => ['Find an order', 'ابحثي عن طلب'],
        'orders.search_placeholder' => ['Order number or product name', 'رقم الطلب أو اسم المنتج'],
        'orders.search_button' => ['Search', 'بحث'],
        'orders.when_label' => ['When', 'الفترة'],
        'orders.when_any' => ['Any time', 'كل الأوقات'],
        'orders.when_month' => ['This month', 'هذا الشهر'],
        'orders.when_3m' => ['Last 3 months', 'آخر 3 أشهر'],
        'orders.no_match' => ['No orders match your search.', 'لا توجد طلبات تطابق بحثكِ.'],
        'orders.clear_filters' => ['Show all orders', 'إظهار كل الطلبات'],
        'orders.view_order' => ['View order', 'عرض الطلب'],
        // One order.
        'orders.track_this' => ['Track this order', 'تتبعي هذا الطلب'],
        'orders.continue_shopping' => ['Continue shopping', 'متابعة التسوق'],
        // Profile and password.
        'profile.page_title' => ['Profile & password · K-Beauty Bliss', 'الملف الشخصي وكلمة المرور · K-Beauty Bliss'],
        'profile.heading' => ['Profile & password', 'الملف الشخصي وكلمة المرور'],
        'profile.details_heading' => ['Your details', 'بياناتكِ'],
        'profile.first_name' => ['First name', 'الاسم الأول'],
        'profile.last_name' => ['Last name', 'اسم العائلة'],
        'profile.email' => ['Email', 'بريدكِ الإلكتروني'],
        'profile.phone' => ['Mobile number', 'رقم الجوال'],
        'profile.email_note' => ['To change your email, message us and we will update it for you.', 'لتغيير بريدكِ الإلكتروني، راسلينا وسنحدّثه لكِ.'],
        'profile.save' => ['Save details', 'حفظ البيانات'],
        'profile.saved' => ['Your details are saved.', 'تم حفظ بياناتكِ.'],
        'profile.phone_invalid' => ['Please enter a valid mobile number.', 'يرجى إدخال رقم جوال صحيح.'],
        'profile.name_required' => ['Please enter your first name.', 'يرجى إدخال اسمكِ الأول.'],
        'profile.password_heading' => ['Change password', 'تغيير كلمة المرور'],
        'profile.current_password' => ['Current password', 'كلمة المرور الحالية'],
        'profile.new_password' => ['New password', 'كلمة المرور الجديدة'],
        'profile.confirm_password' => ['Confirm new password', 'تأكيد كلمة المرور الجديدة'],
        'profile.password_hint' => ['At least 8 characters. Your other devices will be signed out.', '8 أحرف على الأقل. سيتم تسجيل خروجكِ من أجهزتكِ الأخرى.'],
        'profile.password_save' => ['Update password', 'تحديث كلمة المرور'],
        'profile.password_saved' => ['Your password is changed. Your other devices are signed out.', 'تم تغيير كلمة المرور وتسجيل خروجكِ من أجهزتكِ الأخرى.'],
        'profile.password_wrong' => ['That is not your current password.', 'كلمة المرور الحالية غير صحيحة.'],
        'profile.password_short' => ['The new password needs at least 8 characters.', 'يجب أن تتكون كلمة المرور الجديدة من 8 أحرف على الأقل.'],
        'profile.password_mismatch' => ['The two new passwords do not match.', 'كلمتا المرور الجديدتان غير متطابقتين.'],
        'profile.no_password' => ['Your account has no password yet. We can email you a link to set one.', 'لا توجد كلمة مرور لحسابكِ بعد. يمكننا إرسال رابط لإنشائها إلى بريدكِ.'],
        'profile.no_password_link' => ['Set a password by email', 'إنشاء كلمة مرور عبر البريد'],
    ];

    /** @return array<string, string> key => English, for InterfaceStrings' `store` group */
    public static function english(): array
    {
        return array_map(static fn (array $row): string => $row[0], self::ROWS);
    }

    /** @return array<string, string> `store.` + key => Arabic, for ArabicInterfaceDrafts */
    public static function arabic(): array
    {
        $out = [];

        foreach (self::ROWS as $key => [, $arabic]) {
            $out['store.' . $key] = $arabic;
        }

        return $out;
    }
}
