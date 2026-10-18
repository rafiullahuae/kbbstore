<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A Journal post's tag ("News", "Acne Treatments"), as the page prints it.
 *
 * 18 October 2026, the owner on /ar/blog/: "why the few things are not
 * translated on the blog page". The tag on every cover photo and the filter
 * chips above the grid printed the post's English tag on the Arabic page --
 * tags are free text from WordPress, so no interface string covered them.
 *
 * English pages: the tag exactly as stored. Arabic pages: the owner's own
 * Arabic for that tag (Translation -> Language settings -> Blog tags in
 * Arabic), else the shipped Arabic below, else the shop's own Arabic for a
 * shipped English phrase, else the English. The tag itself stays the filter
 * VALUE in both languages (blog.blade.php keys the chips by it); only the
 * label is translated.
 *
 * No query: the owner's map is one setting in the map the request already
 * holds (SettingsRequestMemo).
 */
final class BlogTags
{
    public const SETTING = 'blog_tags_ar';

    /** Longest tag or Arabic label kept, in characters. */
    public const MAX = 60;

    /** Shipped Arabic, keyed by the lower-cased English tag. */
    public const ARABIC = [
        'news' => 'الأخبار',
        'blog' => 'المدونة',
        'acne treatments' => 'علاج حب الشباب',
        'acne' => 'حب الشباب',
        'routine' => 'الروتين',
        'skincare routine' => 'روتين العناية بالبشرة',
        'ingredients' => 'المكوّنات',
        'spf' => 'الحماية من الشمس',
        'sun care' => 'الحماية من الشمس',
        'skincare' => 'العناية بالبشرة',
        'skin care' => 'العناية بالبشرة',
        'skincare tips' => 'نصائح العناية بالبشرة',
        'tips' => 'نصائح',
        'reviews' => 'مراجعات',
        'review' => 'مراجعة',
        'k-beauty' => 'الجمال الكوري',
        'korean skincare' => 'العناية الكورية بالبشرة',
        'makeup' => 'المكياج',
        'hair care' => 'العناية بالشعر',
        'body care' => 'العناية بالجسم',
        'guides' => 'أدلة',
        'how to' => 'كيف',
        'anti-aging' => 'مكافحة الشيخوخة',
        'anti-ageing' => 'مكافحة الشيخوخة',
        'hydration' => 'الترطيب',
        'dark spots' => 'البقع الداكنة',
        'sensitive skin' => 'البشرة الحساسة',
        'oily skin' => 'البشرة الدهنية',
        'dry skin' => 'البشرة الجافة',
    ];

    /** The label to print for $tag on the page being drawn. */
    public static function label(?string $tag): string
    {
        $tag = (string) $tag;

        if ($tag === '' || Locale::current() !== 'ar') {
            return $tag;
        }

        $key = self::key($tag);
        $own = self::owner()[$key] ?? '';

        if ($own !== '') {
            return $own;
        }

        return self::ARABIC[$key] ?? ArabicWording::of($tag);
    }

    /**
     * The owner's map, lower-cased English => Arabic, from the stored JSON.
     * Anything that is not a short string pair is dropped.
     *
     * @return array<string, string>
     */
    public static function owner(): array
    {
        $raw = app(\App\Services\SettingsService::class)->get(self::SETTING, '');
        $map = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return is_array($map) ? self::clean($map) : [];
    }

    /**
     * Keep only short, plain string pairs, keyed by the lower-cased English.
     *
     * @param  array<mixed, mixed>  $map
     * @return array<string, string>
     */
    public static function clean(array $map): array
    {
        $out = [];

        foreach ($map as $en => $ar) {
            if (! is_string($en) || ! is_string($ar)) {
                continue;
            }

            $en = self::key($en);
            $ar = trim(strip_tags($ar));

            if ($en === '' || $ar === '' || mb_strlen($en) > self::MAX || mb_strlen($ar) > self::MAX) {
                continue;
            }

            $out[$en] = $ar;
        }

        return $out;
    }

    /** The shipped Arabic for a tag, '' when there is none (the admin box's placeholder). */
    public static function shipped(string $tag): string
    {
        return self::ARABIC[self::key($tag)] ?? '';
    }

    private static function key(string $tag): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $tag)));
    }
}
