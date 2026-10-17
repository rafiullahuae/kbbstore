<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The language switch: THIS page's own address in each language, and the
 * header's two ways of offering it. (Lane HLB, 17 October 2026)
 *
 * links() is the logic the footer switch (partials/lang-switch) has carried
 * since 16 October, moved here so the header asks the SAME question rather
 * than a copy of it: Seo::readerAlternatePaths(), the pair the page's hreflang
 * links name, so the switch and Google agree. While Arabic is OFF the
 * alternates are empty, links() is [] and nothing anywhere prints -- not a
 * space -- so a shop without Arabic is byte for byte what it was.
 *
 * header() is the design the owner approved in the round-3 live preview
 * (tools/hl3-live.cjs, docs/lane-hl/round3): "ok proceed to the patch, but
 * with super care without distubing anything else".
 *
 *   computer  an "EN | ع" pill, the current half filled pink, the other half
 *             a link -- round 1's D, a little wider, inside the icon group
 *             before the account icon;
 *   phone     a thin capsule naming the language you switch TO ("العربية" on
 *             English pages, "English" on Arabic ones), in the slot the
 *             wishlist heart left, wearing `.ib` so it takes the heart's exact
 *             box and the logo cannot move.
 *
 * Both are printed BEFORE the account icon, so the switch can never come
 * between the account and cart icons (the owner: "the language switcher MUST
 * not come in between the cart and user icons").
 *
 * Every byte is a constant or e()-escaped. The Arabic words on an English page
 * are drawn shapes (LangSwitchGlyphs) because Cairo is not loaded there; on an
 * Arabic page they are text, in the Cairo the page already has.
 */
final class LangSwitch
{
    /** Each language named in its own script. */
    public const NAMES = ['en' => 'English', 'ar' => 'العربية'];

    /** Appearance → Header → Icons → Language switch. */
    public const MODES = ['off', 'phone', 'computer', 'both'];

    private const MEMO = 'kbb.langswitch.links';

    /**
     * code => href for this page, in the order the alternates come, or [] when
     * the page has only one language. Asked once per request: the header and
     * the footer both print it.
     *
     * @return array<string, string>
     */
    public static function links(): array
    {
        $request = request();
        $memo = $request->attributes->get(self::MEMO);

        if (is_array($memo)) {
            return $memo;
        }

        $path = '/' . ltrim((string) $request->path(), '/');
        if ($path !== '/' && ! str_contains(basename($path), '.')) {
            $path = rtrim($path, '/') . '/';
        }

        try {
            $alts = Seo::readerAlternatePaths($path);
        } catch (\Throwable) {
            $alts = [];
        }

        $out = [];
        if (count($alts) > 1) {
            foreach ($alts as $code => $alt) {
                if (isset(self::NAMES[$code])) {
                    $out[$code] = Url::raw($alt);
                }
            }
        }

        $request->attributes->set(self::MEMO, $out);

        return $out;
    }

    /**
     * What the header prints for a mode: ['html' => markup, 'computer' => bool,
     * 'phone' => bool]. '' and two falses when the mode is off, when the page
     * has one language, or when the page's own language is not one of the pair.
     *
     * @return array{html: string, computer: bool, phone: bool}
     */
    public static function header(string $mode): array
    {
        $none = ['html' => '', 'computer' => false, 'phone' => false];
        $computer = $mode === 'computer' || $mode === 'both';
        $phone = $mode === 'phone' || $mode === 'both';

        if (! $computer && ! $phone) {
            return $none;
        }

        $links = self::links();
        $here = Locale::current();

        if (! isset($links[$here])) {
            return $none;
        }

        $there = null;
        foreach (array_keys($links) as $code) {
            if ($code !== $here) {
                $there = $code;
                break;
            }
        }

        if ($there === null) {
            return $none;
        }

        $html = '';

        if ($computer) {
            $pair = '';
            foreach (['en', 'ar'] as $code) {
                if (! isset($links[$code])) {
                    continue;
                }
                $label = $code === 'en' ? 'EN' : ($here === 'ar' ? 'ع' : LangSwitchGlyphs::AIN);
                $pair .= $code === $here
                    ? '<span lang="' . $code . '" aria-current="true">' . $label . '</span>'
                    : '<a href="' . e($links[$code]) . '" lang="' . $code . '" hreflang="' . $code . '" aria-label="' . e(self::NAMES[$code]) . '">' . $label . '</a>';
            }
            $html .= '<nav class="lsw lsw-d" aria-label="' . ($here === 'ar' ? 'اللغة' : 'Language') . '">' . $pair . '</nav>';
        }

        if ($phone) {
            // Switching TO Arabic means this page is not Arabic: no Cairo here,
            // so the word is drawn. "English" is Outfit, which every page has.
            $word = $there === 'ar' ? LangSwitchGlyphs::ARABIC : e(self::NAMES[$there]);
            $html .= '<a class="ib lsw lsw-m" href="' . e($links[$there]) . '" lang="' . e($there) . '" hreflang="' . e($there) . '" aria-label="' . e(self::NAMES[$there]) . '"><span>' . $word . '</span></a>';
        }

        return ['html' => $html, 'computer' => $computer, 'phone' => $phone];
    }
}
