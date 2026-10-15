<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Country names as the page's language says them (Lane AR, stage 2).
 *
 * Its own class, not two more members of App\Support\Countries, on purpose:
 * a package reaches the live server file by file while opcache may still hold
 * the previous Countries, and a call to a member that class did not have yet
 * is a fatal error on whatever page asked. A class that did not exist before
 * is always read from disk, whole. Countries::NAMES -- which every release has
 * -- stays the English and the list of codes.
 */
final class CountryNames
{
    /**
     * The same countries in Arabic (Lane AR stage 2), generated once from ICU's
     * Arabic region names and shipped as data, so the server needs no intl
     * extension. Shown only on /ar, through label().
     */
    public const ARABIC = [
        'DZ' => 'الجزائر',
        'AR' => 'الأرجنتين',
        'AM' => 'أرمينيا',
        'AU' => 'أستراليا',
        'AT' => 'النمسا',
        'AZ' => 'أذربيجان',
        'BH' => 'البحرين',
        'BD' => 'بنغلاديش',
        'BE' => 'بلجيكا',
        'BR' => 'البرازيل',
        'BG' => 'بلغاريا',
        'KH' => 'كمبوديا',
        'CA' => 'كندا',
        'CL' => 'تشيلي',
        'CN' => 'الصين',
        'CO' => 'كولومبيا',
        'HR' => 'كرواتيا',
        'CY' => 'قبرص',
        'CZ' => 'التشيك',
        'DK' => 'الدانمرك',
        'EG' => 'مصر',
        'EE' => 'إستونيا',
        'ET' => 'إثيوبيا',
        'FI' => 'فنلندا',
        'FR' => 'فرنسا',
        'GE' => 'جورجيا',
        'DE' => 'ألمانيا',
        'GH' => 'غانا',
        'GR' => 'اليونان',
        'HK' => 'هونغ كونغ الصينية (منطقة إدارية خاصة)',
        'HU' => 'هنغاريا',
        'IS' => 'آيسلندا',
        'IN' => 'الهند',
        'ID' => 'إندونيسيا',
        'IR' => 'إيران',
        'IQ' => 'العراق',
        'IE' => 'أيرلندا',
        'IL' => 'إسرائيل',
        'IT' => 'إيطاليا',
        'JP' => 'اليابان',
        'JO' => 'الأردن',
        'KZ' => 'كازاخستان',
        'KE' => 'كينيا',
        'KW' => 'الكويت',
        'LV' => 'لاتفيا',
        'LB' => 'لبنان',
        'LY' => 'ليبيا',
        'LT' => 'ليتوانيا',
        'LU' => 'لوكسمبورغ',
        'MY' => 'ماليزيا',
        'MV' => 'جزر المالديف',
        'MT' => 'مالطا',
        'MX' => 'المكسيك',
        'MA' => 'المغرب',
        'NP' => 'نيبال',
        'NL' => 'هولندا',
        'NZ' => 'نيوزيلندا',
        'NG' => 'نيجيريا',
        'NO' => 'النرويج',
        'OM' => 'عُمان',
        'PK' => 'باكستان',
        'PS' => 'فلسطين',
        'PH' => 'الفلبين',
        'PL' => 'بولندا',
        'PT' => 'البرتغال',
        'QA' => 'قطر',
        'RO' => 'رومانيا',
        'RU' => 'روسيا',
        'SA' => 'المملكة العربية السعودية',
        'RS' => 'صربيا',
        'SG' => 'سنغافورة',
        'SK' => 'سلوفاكيا',
        'SI' => 'سلوفينيا',
        'ZA' => 'جنوب أفريقيا',
        'KR' => 'كوريا الجنوبية',
        'ES' => 'إسبانيا',
        'LK' => 'سريلانكا',
        'SD' => 'السودان',
        'SE' => 'السويد',
        'CH' => 'سويسرا',
        'SY' => 'سوريا',
        'TW' => 'تايوان',
        'TZ' => 'تنزانيا',
        'TH' => 'تايلاند',
        'TN' => 'تونس',
        'TR' => 'تركيا',
        'UG' => 'أوغندا',
        'UA' => 'أوكرانيا',
        'AE' => 'الإمارات العربية المتحدة',
        'GB' => 'المملكة المتحدة',
        'US' => 'الولايات المتحدة',
        'UZ' => 'أوزبكستان',
        'VN' => 'فيتنام',
        'YE' => 'اليمن',
    ];

    /**
     * A country's name for the page being drawn: Arabic on /ar, the shop's
     * English everywhere else (byte for byte). An unknown code falls back to
     * the English name passed in, then to the code.
     */
    public static function label(string $code, ?string $english = null): string
    {
        $code = strtoupper(trim($code));
        $english ??= Countries::NAMES[$code] ?? $code;

        return Locale::current() === 'ar' ? (self::ARABIC[$code] ?? $english) : $english;
    }

}
