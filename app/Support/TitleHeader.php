<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Import\Row;
use App\Services\SiteLayout;
use Illuminate\Database\Eloquent\Model;

/**
 * The category (and brand) TITLE HEADER: the title and description of a
 * category page, over the category's picture -- or, when it has none, in a
 * light box with a soft pattern of beauty-product icons. (Lanes PT and PY)
 *
 * THE OWNER, IN HIS WORDS. Lane PT: "We have a banner image on each category
 * on the old site. Need to bring that on the category pages as title
 * background, like on https://kbeautybliss.com/sunscreens/ -- and we also need
 * the same title, same description for each category if any have on our old
 * site."
 *
 * Lane PY, on PT's preview: "The title will be get from the category name
 * itself, if custom title or description not entered by me. also give optio
 * to center, left and for arabic ofcourse by default make the title name left
 * side as before, i just wanted the background image or light colored box
 * containing skincare makeup etc products icons. if no image. but i can be
 * able to update that background iamge, title font size etc and description
 * etc for each category. and will have control of overall section height
 * padding etc. and some type of shadow behind the name, so it will not merged
 * with the background. please give me multiple options to chooose from."
 *
 * This class is the ONE reader: the controllers ask forModel() and the Blade
 * component draws what it returns.
 *
 * WHAT DECIDES WHETHER IT DRAWS, in order:
 *
 *   1. The owner's own banner wins. Lane BW's `banner` is something he
 *      designed by hand; when it is on, this returns null and that banner
 *      carries the page's <h1> as before.
 *   2. Appearance -> Site layout -> Category header -> the main switch (ON),
 *      and for a brand "Brand pages too".
 *   3. A picture -- the category's header picture (imported, or chosen in
 *      Catalog -> Categories -> Edit), or with the fallback switch on its
 *      square category picture -- draws the PICTURE header.
 *   4. No picture: the LIGHT BOX, when "When a category has no picture, show a
 *      light box" is on (ON for categories, he asked) -- or, for a brand,
 *      "Light box on brand pages with no picture" (OFF: he did not ask about
 *      brands, so a brand with no picture stays exactly as 2.60.346 left it,
 *      on its own page and on /shop/?filter_brands=).
 *   5. Otherwise NULL, and the page renders the plain title it always did.
 *
 * WHICH WORDS. The title is the category's own custom header title when one
 * is set -- an imported old-shop title lands in the same field and is visible
 * and clearable in the category editor -- and otherwise the category NAME.
 * The description is the custom header description when set, otherwise the
 * category's own description. The custom title, subtitle and description are
 * English: an Arabic page keeps the category's translated name and
 * description rather than showing an English heading.
 *
 * HOW IT LOOKS is the shop's settings with the category's own overrides laid
 * over them (`header_style`, sanitised by sanitizeStyle() on the way in AND
 * on the way out): alignment, the text treatment, the box style, the title
 * size and the height. Anything the category leaves blank follows the shop.
 *
 * SECURE BY CONSTRUCTION. Every value here is either third-party data from an
 * export or typed in an admin box:
 *
 *   - the picture goes into an <img src>, so it is http(s) or a site-relative
 *     path with no `..`, or nothing -- PageBanner::safeImage()'s rule;
 *   - the title and subtitle are printed escaped;
 *   - the description is printed RAW, so it is RichText::forDisplay()'d here,
 *     on every render: the allowlist is the control, whatever wrote the row;
 *   - the `class` attribute is built only from words checked against the
 *     lists below; nothing typed is ever a class;
 *   - the `style` attribute carries custom properties whose NAMES are the
 *     constants in PX_VARS, each holding an integer clamped to its setting's
 *     own range, plus at most two colours that must match ^#[0-9A-F]{6}$ at
 *     the moment they are printed. Nothing from a setting is interpolated into
 *     CSS syntax.
 */
final class TitleHeader
{
    /** Plain-text length past which the description is clamped with "Read more". */
    public const LONG_DESCRIPTION = 180;

    /** Intrinsic size written on the <img>, for its ratio: the box is reserved before the bytes arrive. */
    public const IMG_WIDTH = 1600;

    public const IMG_HEIGHT = 500;

    /** Logical alignment: `start` is left in English and right in Arabic. */
    public const ALIGNS = ['start', 'center', 'end'];

    private const TONES = ['light', 'dark'];

    /** The box styles that carry the icon pattern; `plain` is the colour alone. */
    public const ICON_BOXES = ['blush', 'cream', 'mint', 'lilac', 'custom'];

    /** The box styles whose ground is the owner's own colour, written as --kbb-th-bg. */
    private const OWN_COLOUR_BOXES = ['plain', 'custom'];

    /**
     * Every pixel value on the header, as the CONSTANT custom property it is
     * written to and the setting it is read from. SiteLayoutDefaultsMatchCssTest
     * checks that every range on the Category header tabs is in here (or read
     * by name below), so a slider cannot save and move nothing.
     */
    public const PX_VARS = [
        '--kbb-th-h' => 'cat_header_h_phone',
        '--kbb-th-hd' => 'cat_header_h_desktop',
        '--kbb-th-ts' => 'cat_header_title_phone',
        '--kbb-th-tsd' => 'cat_header_title_desktop',
        '--kbb-th-ds' => 'cat_header_desc_phone',
        '--kbb-th-dsd' => 'cat_header_desc_desktop',
        '--kbb-th-py' => 'cat_header_pad_y_phone',
        '--kbb-th-pyd' => 'cat_header_pad_y_desktop',
        '--kbb-th-px' => 'cat_header_pad_x_phone',
        '--kbb-th-pxd' => 'cat_header_pad_x_desktop',
        '--kbb-th-r' => 'cat_header_radius',
        '--kbb-th-mt' => 'cat_header_mt_phone',
        '--kbb-th-mtd' => 'cat_header_mt_desktop',
        '--kbb-th-mb' => 'cat_header_mb_phone',
        '--kbb-th-mbd' => 'cat_header_mb_desktop',
        '--kbb-th-mw' => 'cat_header_maxw',
    ];

    /**
     * The per-category NUMBERS a category may override, and the shop setting
     * each one replaces -- whose range it is also clamped to.
     */
    public const STYLE_NUMBERS = [
        'title_phone' => 'cat_header_title_phone',
        'title_desktop' => 'cat_header_title_desktop',
        'h_phone' => 'cat_header_h_phone',
        'h_desktop' => 'cat_header_h_desktop',
    ];

    /** The per-category CHOICES, each checked against its own list. */
    public const STYLE_CHOICES = ['align', 'treatment', 'box'];

    /** The light boxes the random pick may use (2.60.352); F needs colours of its own. */
    public const RANDOM_POOL = ['blush', 'cream', 'mint', 'lilac', 'plain'];

    /**
     * The per-category, PER-DEVICE choices (Lane QC), and the list each is
     * checked against. A device's own key beats the both-devices key above,
     * which is what a category PY saved still carries -- so "Centred" saved
     * before phone and laptop were separate is still both devices' choice.
     */
    public const DEVICE_CHOICES = [
        'align_phone' => 'align', 'align_desktop' => 'align',
        'treatment_phone' => 'treatment', 'treatment_desktop' => 'treatment',
        'box_phone' => 'box', 'box_desktop' => 'box',
        'text_phone' => 'text', 'text_desktop' => 'text',
        'valign_phone' => 'valign', 'valign_desktop' => 'valign',
        'focus' => 'focus',
    ];

    /** A category's own box colours (Lane QC): #RRGGBB, over whichever box style it shows. */
    public const STYLE_COLOURS = ['bg', 'ic'];

    /** The text colour words, Automatic included. */
    public const TEXTS = ['auto', 'light', 'dark'];

    /** Where the words sit, top to bottom (2.60.350; per device since Lane QC). */
    public const VALIGNS = ['top', 'center', 'bottom'];

    /** Which part of a picture a phone keeps (Lane QC). */
    public const FOCUSES = ['left', 'center', 'right'];

    /**
     * The fine-tuning written onto the header (Lane QC): constant property
     * name => [setting, how it is printed]. Written only off its default.
     * kbb-title-header.css reads each with the drawn value as its fallback.
     */
    public const TWEAK_VARS = [
        '--kbb-th-io' => ['cat_header_icon_strength', 'factor'],
        '--kbb-th-isz' => ['cat_header_icon_size', 'factor'],
        '--kbb-th-shs' => ['cat_header_shadow_strength', 'factor'],
        '--kbb-th-shb' => ['cat_header_shadow_blur', 'factor'],
        '--kbb-th-fdd' => ['cat_header_fade_dark', 'factor'],
        '--kbb-th-fdr' => ['cat_header_fade_reach', 'factor'],
        '--kbb-th-fro' => ['cat_header_frost_opacity', 'factor'],
        '--kbb-th-frb' => ['cat_header_frost_blur', 'px'],
        '--kbb-th-frr' => ['cat_header_frost_radius', 'px'],
        '--kbb-th-ls' => ['cat_header_letter', 'em'],
    ];

    /** The colours among them; blank (Automatic) writes nothing. */
    public const TWEAK_COLOURS = [
        '--kbb-th-lbl-bg' => 'cat_header_label_bg',
        '--kbb-th-lbl-fg' => 'cat_header_label_fg',
        '--kbb-th-dc' => 'cat_header_desc_colour',
    ];

    /**
     * The header for a Category or a Brand, resolved for drawing, or null.
     *
     * @param  string  $title  the page's own heading -- the translated name
     * @param  array<string, mixed>|null  $banner  PageBanner::forModel()'s answer; when set, this returns null
     * @param  bool  $brand  a brand page, which has its own switches
     * @return array<string, mixed>|null
     */
    public static function forModel(?Model $model, string $title, ?array $banner = null, bool $brand = false): ?array
    {
        if ($model === null || $banner !== null) {
            return null;
        }

        $settings = self::settings();

        if (! $settings['cat_header'] || ($brand && ! $settings['cat_header_brands'])) {
            return null;
        }

        $image = self::safeImage($model->getAttribute('header_image'));

        if ($image === null && $settings['cat_header_fallback']) {
            $image = self::safeImage($model->getAttribute($brand ? 'logo' : 'image'));
        }

        if ($image === null && ! ($brand ? $settings['cat_header_box_brands'] : $settings['cat_header_box'])) {
            return null;
        }

        $kind = $image === null ? 'box' : 'img';

        // A brand has no per-brand style column; its page follows the shop.
        $own = $brand ? [] : self::sanitizeStyle($model->getAttribute('header_style'));

        $english = Locale::segment() === '';

        /*
         * "The title will be get from the category name itself, if custom
         * title ... not entered by me." The custom title -- which is also where
         * an imported old-shop title lands -- wins in English; the Arabic page
         * keeps the translated name, because the custom title has no Arabic.
         */
        $override = $english ? self::text($model->getAttribute('header_title'), 160) : '';

        /*
         * 2.60.350: "hide" on every header. The old shop's theme stored its
         * "hide the title" switch as a term-meta VALUE, exporter 1.11.0 read
         * that key as a title override, and the import wrote "hide" as the
         * title of every category. The import no longer writes these two
         * columns (importColumns), a migration cleared what it wrote, and a
         * value that is only a switch word is never a heading.
         */
        if (self::isSwitchWord($override)) {
            $override = '';
        }

        $heading = $override !== '' ? $override : self::text($title, 160);

        $subtitle = $english ? self::text($model->getAttribute('header_subtitle'), 300) : '';
        if (self::isSwitchWord($subtitle)) {
            $subtitle = '';
        }

        /*
         * "... if custom title or description not entered by me." The custom
         * header description wins in English; otherwise the category's own,
         * translated. Through the same allowlist either way.
         */
        $description = self::descriptionOf($model, $english);

        /*
         * "if no description set from backend, then generic line should come.
         * Find your favorite products in our wide range <category name>
         * category." English categories only: the sentence has no Arabic yet,
         * and a brand is not a category.
         */
        if ($description === '' && $english && ! $brand) {
            $generic = trim((string) ($settings['cat_header_generic'] ?? ''));

            if ($generic !== '') {
                $description = e(str_replace('{category}', self::text($title, 160), mb_substr($generic, 0, 300)));
            }
        }

        $more = (bool) ($settings['cat_header_more'] ?? false);

        /*
         * PHONE AND LAPTOP, EACH RESOLVED ON ITS OWN. (Lane QC)
         *
         * "... and for mobile also." Every choice -- alignment, the words'
         * treatment, the box style, the text colour and the box's colours --
         * is worked out once for the phone (under 900px) and once for the
         * laptop, each in the same order: the category's own per-device
         * choice, then its own both-devices choice (what PY stored), then the
         * shop's setting for that device, then the shipped default.
         */
        /*
         * The random light box (2.60.352): one pick for this page view, taken
         * from the styles ticked in the mix, standing in for the SHOP's box
         * style on both devices -- so the category's own choice still wins.
         */
        $random = null;

        if ($kind === 'box' && ! empty($settings['cat_header_box_random'])) {
            $pool = array_values(array_filter(self::RANDOM_POOL, fn (string $b): bool => ! empty($settings['cat_header_rand_'.$b])));
            $random = $pool === [] ? null : $pool[random_int(0, count($pool) - 1)];
        }

        $phone = self::device($settings, $own, $kind, '', $random);
        $laptop = self::device($settings, $own, $kind, '_desktop', $random);

        /*
         * THE SAME ON BOTH: the markup 2.60.349 wrote, byte for byte -- one
         * class per choice, and the box's own colours (when it has some) as
         * --kbb-th-bg / --kbb-th-ic on the element. A shop that has not given
         * its laptop a choice of its own is always here, which is what keeps
         * every category page identical at the defaults.
         *
         * DIFFERENT: `kbb-th--split`, and the same words under a `p-` and an
         * `l-` prefix. The stylesheet's generated section repeats every rule
         * that reads one of those words, once inside the phone's media query
         * with `p-` and once inside the laptop's with `l-` -- so the browser
         * picks, by width, with no script and nothing measured. A device whose
         * box has colours of its own carries `p-own` / `l-own`, and its two
         * colours ride as --kbb-th-p-bg/--kbb-th-p-ic or --kbb-th-l-bg/--kbb-th-l-ic.
         */
        $split = $phone !== $laptop;

        if (! $split) {
            $class = 'kbb-th kbb-th--'.$kind.' kbb-th--'.$phone['tone'].' kbb-th--a-'.$phone['align']
                .' kbb-th--v-'.$phone['valign']
                .' kbb-th--t-'.$phone['treatment'].($phone['box'] !== null ? ' kbb-th--box-'.$phone['box'] : '');
        } else {
            $class = 'kbb-th kbb-th--'.$kind.' kbb-th--split';

            foreach (['p' => $phone, 'l' => $laptop] as $p => $d) {
                $class .= ' kbb-th--'.$p.'-'.$d['tone'].' kbb-th--'.$p.'-a-'.$d['align'].' kbb-th--'.$p.'-v-'.$d['valign']
                    .' kbb-th--'.$p.'-t-'.$d['treatment']
                    .($d['box'] !== null ? ' kbb-th--'.$p.'-box-'.$d['box'] : '')
                    .($d['own'] !== null ? ' kbb-th--'.$p.'-own' : '');
            }
        }

        /*
         * Where a picture is cut on a phone (Lane QC). The phone's header is
         * about 1.8:1 and a banner about 4:1, so a phone shows the middle half
         * of it; a category may keep its left or right end instead. Centre is
         * the stylesheet's own object-fit centre and writes nothing.
         */
        $focus = self::pick($own['focus'] ?? null, self::FOCUSES);

        if ($kind === 'img' && ($focus === 'left' || $focus === 'right')) {
            $class .= ' kbb-th--fx-'.$focus;
        }

        // On a phone the header takes the picture's own shape, so nothing is
        // cut from its left or right (2.60.358, "should display full"). The
        // Phone crop above only matters when this is off.
        $whole = $kind === 'img' && ! empty($settings['cat_header_phone_whole']);

        if ($whole) {
            $class .= ' kbb-th--pw';
        }

        $icons = false;

        foreach ([$phone, $laptop] as $d) {
            $icons = $icons || ($d['box'] !== null && in_array($d['box'], self::ICON_BOXES, true));
        }

        return [
            'kind' => $kind,
            'image' => $image,
            'whole' => $whole,
            'icons' => $icons,
            'box' => $phone['box'],
            'heading' => $heading,
            'subtitle' => $subtitle,
            'description' => $description,
            // "Read more" only when the owner turns it on; otherwise the
            // description is simply cut at its line count. (2.60.350)
            'long' => $more && $description !== '' && mb_strlen(RichText::toText($description)) > self::LONG_DESCRIPTION,
            'clamp' => ! $more,
            'tone' => $phone['tone'],
            'align' => $phone['align'],
            'treatment' => $phone['treatment'],
            'devices' => ['phone' => $phone, 'laptop' => $laptop],
            'class' => $class,
            'style' => self::style($settings, $own, $phone, $laptop, $split),
        ];
    }

    /**
     * One device's choices. `$suffix` is '' for the phone and '_desktop' for
     * the laptop -- the shop's setting keys and the category's own keys are
     * spelled that way (SiteLayout::DEVICE_PAIRS; `align_phone` /
     * `align_desktop` in `header_style`).
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $own  sanitizeStyle()'s answer
     * @return array{align: string, valign: string, treatment: string, box: ?string, tone: string, own: ?array{0: string, 1: string}, drawn: ?array{0: string, 1: string}}
     */
    private static function device(array $settings, array $own, string $kind, string $suffix, ?string $random = null): array
    {
        $mine = $suffix === '' ? '_phone' : '_desktop';

        $align = self::pick($own['align'.$mine] ?? null, self::ALIGNS)
            ?? self::pick($own['align'] ?? null, self::ALIGNS)
            ?? self::pick($settings['cat_header_align'.$suffix] ?? null, self::ALIGNS)
            ?? 'start';

        $treatments = array_keys(SiteLayout::TREATMENTS);
        $treatment = self::pick($own['treatment'.$mine] ?? null, $treatments)
            ?? self::pick($own['treatment'] ?? null, $treatments)
            ?? self::pick($settings[($kind === 'img' ? 'cat_header_treatment' : 'cat_header_box_treatment').$suffix] ?? null, $treatments)
            ?? ($kind === 'img' ? 'shadow' : 'none');

        $box = null;
        $colours = null;
        $ownColours = null;
        $drawn = null;

        if ($kind === 'box') {
            $boxes = array_keys(SiteLayout::BOX_STYLES);
            $box = self::pick($own['box'.$mine] ?? null, $boxes)
                ?? self::pick($own['box'] ?? null, $boxes)
                ?? self::pick($random, $boxes)
                ?? self::pick($settings['cat_header_box_style'.$suffix] ?? null, $boxes)
                ?? 'blush';

            [$colours, $drawn] = self::boxColours($settings, $box);

            // The category's own colours, over whichever box this device has.
            $colours = [
                self::hex($own['bg'] ?? null, $colours[0]),
                self::hex($own['ic'] ?? null, $colours[1]),
            ];

            // Colours of its own: E and F always; A-D only once moved off the drawing.
            $ownColours = ($drawn === null || $colours !== $drawn) ? $colours : null;
        }

        $drawnColours = $drawn ?? null;

        $text = self::pick($own['text'.$mine] ?? null, self::TEXTS)
            ?? self::pick($settings['cat_header_text'.$suffix] ?? null, self::TEXTS)
            ?? 'auto';

        // 2.60.350's "Where the words sit", per device since Lane QC.
        $valign = self::pick($own['valign'.$mine] ?? null, self::VALIGNS)
            ?? self::pick($settings['cat_header_valign'.$suffix] ?? null, self::VALIGNS)
            ?? 'bottom';

        return [
            'align' => $align,
            'valign' => $valign,
            'treatment' => $treatment,
            'box' => $box,
            'tone' => self::tone($text, $kind, $colours[0] ?? null),
            'own' => $ownColours,
            'drawn' => $drawnColours,
        ];
    }

    /**
     * The description a category or brand page prints, as display-safe HTML,
     * or '' when there is none.
     *
     * The custom header description (typed on the page itself, quick edit →
     * Description) wins in English; otherwise the model's own, translated.
     * Through RichText's allowlist either way.
     */
    public static function descriptionOf(Model $model, ?bool $english = null): string
    {
        $english ??= Locale::segment() === '';
        $custom = $english ? $model->getAttribute('header_description') : null;
        $raw = is_string($custom) && ! RichText::isBlank($custom)
            ? $custom
            : (method_exists($model, 't') ? $model->t('description') : $model->getAttribute('description'));

        return RichText::isBlank(is_string($raw) ? $raw : '') ? '' : RichText::forDisplay((string) $raw);
    }

    /**
     * A brand page's description when no title header draws it. (2.60.376)
     *
     * THE DEFECT: the brand hero printed only `brands.description`, so a
     * description typed on the brand page itself (quick edit → Description,
     * stored in `header_description`) showed only when the brand's page also
     * had a title header -- a brand with no header picture dropped it. The
     * owner: "we have option to add description but that text is not showing
     * under the brand name". Same rule as the title header now, one method.
     */
    public static function brandDescription(Model $brand): string
    {
        return self::descriptionOf($brand);
    }

    /** Values the old theme used as SWITCHES, never as words to print. */
    private const SWITCH_WORDS = ['hide', 'hidden', 'show', 'yes', 'no', 'on', 'off', 'true', 'false',
        '0', '1', 'default', 'inherit', 'none', 'auto', 'disable', 'disabled', 'enable', 'enabled'];

    public static function isSwitchWord(string $value): bool
    {
        return in_array(mb_strtolower(trim($value)), self::SWITCH_WORDS, true);
    }

    /**
     * A box style's two colours as this shop has them, and as drawn.
     *
     * A-D: the shop's tuned colours (Appearance -> Site layout -> Category
     * header -> the style's own fine-tuning), drawn = the preset. E and F:
     * the shop's own two colours, with nothing "drawn" to compare against --
     * the stylesheet has no colour for them, so they are always written.
     *
     * @param  array<string, mixed>  $settings
     * @return array{0: array{0: string, 1: string}, 1: ?array{0: string, 1: string}}
     */
    private static function boxColours(array $settings, string $box): array
    {
        if (isset(SiteLayout::BOX_PRESETS[$box])) {
            [$bg, $ic] = SiteLayout::BOX_PRESETS[$box];

            return [[
                self::hex($settings['cat_header_'.$box.'_bg'] ?? null, $bg),
                self::hex($settings['cat_header_'.$box.'_ic'] ?? null, $ic),
            ], [$bg, $ic]];
        }

        return [[
            self::hex($settings['cat_header_box_bg'] ?? null, '#FFF4EE'),
            self::hex($settings['cat_header_box_icon'] ?? null, '#EFA889'),
        ], null];
    }

    /**
     * The `style` attribute: constant names, clamped integers, checked colours.
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $own  sanitizeStyle()'s answer
     * @param  array<string, mixed>  $phone  device()'s answer for the phone
     * @param  array<string, mixed>  $laptop  device()'s answer for the laptop
     * @param  bool  $split  the two differ, so the colours ride per device
     */
    private static function style(array $settings, array $own, array $phone, array $laptop, bool $split): string
    {
        $values = [];

        foreach (self::PX_VARS as $var => $key) {
            $values[$key] = (int) $settings[$key];
        }

        foreach (self::STYLE_NUMBERS as $mine => $key) {
            if (isset($own[$mine])) {
                $values[$key] = (int) $own[$mine];
            }
        }

        $out = [];

        foreach (self::PX_VARS as $var => $key) {
            $out[] = $var.':'.self::clampTo($key, $values[$key]).'px';
        }

        $out[] = '--kbb-th-ov:'.round(max(0, min(85, (int) $settings['cat_header_overlay'])) / 100, 2);
        $out[] = '--kbb-th-lines:'.max(1, min(10, (int) $settings['cat_header_lines']));
        $out[] = '--kbb-th-tw:'.(in_array((string) $settings['cat_header_weight'], ['500', '600', '700', '800'], true)
            ? (int) $settings['cat_header_weight'] : 700);

        if (! $split) {
            // 2.60.349's two, spelled as it spelled them: E writes its ground,
            // F its ground and its icons. A-D now do too, once tuned.
            //
            // A tuned A-D writes only the colour that moved, so each tweak
            // moves only its own property.
            if ($phone['own'] !== null) {
                $drawn = $phone['drawn'];

                if ($drawn === null || $phone['own'][0] !== $drawn[0]) {
                    $out[] = '--kbb-th-bg:'.$phone['own'][0];
                }

                if ($phone['box'] !== 'plain' && ($drawn === null || $phone['own'][1] !== $drawn[1])) {
                    $out[] = '--kbb-th-ic:'.$phone['own'][1];
                }
            }
        } else {
            foreach (['p' => $phone, 'l' => $laptop] as $p => $d) {
                if ($d['own'] !== null) {
                    $out[] = '--kbb-th-'.$p.'-bg:'.$d['own'][0];
                    $out[] = '--kbb-th-'.$p.'-ic:'.$d['own'][1];
                }
            }
        }

        /*
         * THE FINE-TUNING (Lane QC), each written only when it is not the
         * design as drawn -- so nothing is added to a header nobody tuned.
         * Every name is a constant in TWEAK_VARS; every value is the setting's
         * own integer, clamped to its slider, printed as a factor (100 -> 1),
         * a pixel or an em; or a colour checked against ^#[0-9A-F]{6}$ here.
         */
        foreach (self::TWEAK_VARS as $var => [$key, $as]) {
            $n = self::clampTo($key, (int) ($settings[$key] ?? SiteLayout::SCHEMA[$key][2]));

            if ($n === (int) SiteLayout::SCHEMA[$key][2]) {
                continue;
            }

            $out[] = $var.':'.match ($as) {
                'factor' => self::factor($n),
                'px' => $n.'px',
                'em' => self::factor($n).'em',
            };
        }

        foreach (self::TWEAK_COLOURS as $var => $key) {
            $hex = self::hex($settings[$key] ?? null, '');

            if ($hex !== '') {
                $out[] = $var.':'.$hex;
            }
        }

        return implode(';', $out);
    }

    /** 100 -> "1", 60 -> "0.6", -2 -> "-0.02": a percentage as a plain factor. */
    private static function factor(int $percent): string
    {
        $v = rtrim(rtrim(number_format($percent / 100, 2, '.', ''), '0'), '.');

        return $v === '-0' ? '0' : $v;
    }

    /**
     * White or dark words. "Automatic" is white over a picture and dark on a
     * light box -- and white on a box whose OWN colour is dark, worked out
     * here from that colour's luminance so the owner's own navy box does not
     * get navy words.
     */
    private static function tone(string $setting, string $kind, ?string $ground): string
    {
        if (in_array($setting, self::TONES, true)) {
            return $setting;
        }

        if ($kind === 'img' || $ground === null) {
            return 'light';
        }

        /*
         * The box's ACTUAL colour decides, for every style. 2.60.349 did this
         * for E and F only, because A-D could not change colour; now they can
         * (Lane QC), and a Cream box tuned to espresso needs white words. The
         * four presets as drawn are all well above 0.4, so they still get
         * dark words, as before.
         */
        return self::luminance($ground) < 0.4 ? 'light' : 'dark';
    }

    /** WCAG relative luminance of a #RRGGBB colour, 0 (black) to 1 (white). */
    public static function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $c = [];

        foreach ([0, 2, 4] as $i) {
            $v = hexdec(substr($hex, $i, 2)) / 255;
            $c[] = $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    /**
     * A category's own `header_style`, reduced to what it may say.
     *
     * Run on the way IN (Admin\CategoriesApiController) and on the way OUT
     * (forModel()), so a row written by anything -- an import, a console, a
     * hand-edited database -- still cannot put a word that is not on a list
     * into a class, or a number outside its slider's range into a style.
     *
     *   align / treatment / box   one of their own lists, or absent
     *   title_* / h_*             a whole number, clamped to the matching
     *                             shop setting's range, or absent
     *
     * Absent means "follow the shop", which is what a blank box in the editor
     * sends. An empty answer is stored as NULL by the controller.
     *
     * @return array<string, int|string>
     */
    public static function sanitizeStyle(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        if (! is_array($raw)) {
            return [];
        }

        $lists = [
            'align' => self::ALIGNS,
            'treatment' => array_keys(SiteLayout::TREATMENTS),
            'box' => array_keys(SiteLayout::BOX_STYLES),
        ];

        $out = [];

        foreach (self::STYLE_CHOICES as $key) {
            $picked = self::pick($raw[$key] ?? null, $lists[$key]);

            if ($picked !== null) {
                $out[$key] = $picked;
            }
        }

        $lists += ['text' => self::TEXTS, 'focus' => self::FOCUSES, 'valign' => self::VALIGNS];

        foreach (self::DEVICE_CHOICES as $key => $list) {
            $picked = self::pick($raw[$key] ?? null, $lists[$list]);

            if ($picked !== null) {
                $out[$key] = $picked;
            }
        }

        foreach (self::STYLE_COLOURS as $key) {
            $hex = self::hex(is_string($raw[$key] ?? null) ? self::expandHex($raw[$key]) : null, '');

            if ($hex !== '') {
                $out[$key] = $hex;
            }
        }

        foreach (self::STYLE_NUMBERS as $key => $setting) {
            $v = $raw[$key] ?? null;

            if (is_int($v) || (is_float($v) && is_finite($v))) {
                $out[$key] = self::clampTo($setting, (int) $v);
            } elseif (is_string($v) && preg_match('/^\s*(\d{1,5})\s*$/', $v, $m) === 1) {
                $out[$key] = self::clampTo($setting, (int) $m[1]);
            }
        }

        return $out;
    }

    /** An integer held to one Site layout slider's own min and max. */
    public static function clampTo(string $setting, int $value): int
    {
        $bounds = SiteLayout::SCHEMA[$setting][4] ?? [];

        return max((int) ($bounds['min'] ?? 0), min((int) ($bounds['max'] ?? 2000), $value));
    }

    /** $value when it is one of $allowed, else null. */
    private static function pick(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    /** #rgb as #RRGGBB; anything else as it came, for hex() to judge. */
    private static function expandHex(string $v): string
    {
        $v = strtoupper(trim($v));

        return preg_match('/^#([0-9A-F])([0-9A-F])([0-9A-F])$/', $v, $m) === 1
            ? '#'.$m[1].$m[1].$m[2].$m[2].$m[3].$m[3]
            : $v;
    }

    /** A colour as #RRGGBB, checked again at the moment it is printed. */
    private static function hex(mixed $value, string $fallback): string
    {
        return is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1 ? strtoupper($value) : $fallback;
    }

    /** @return array<string, mixed> */
    private static function settings(): array
    {
        $all = app(SiteLayout::class)->all();
        $out = [];

        foreach (SiteLayout::HEADER_KEYS as $key) {
            $out[$key] = $all[$key] ?? SiteLayout::SCHEMA[$key][2] ?? null;
        }

        return $out;
    }

    /* ─────────────────────────────────────────────────── the import side ── */

    /**
     * A category or brand description as the importer stores it.
     *
     * HTML is cleaned (RichText::clean) -- it is third-party markup. PLAIN TEXT
     * IS STORED AS IT CAME, deliberately: clean() serialises through
     * DOMDocument, which writes `&` as `&amp;`, and the plain category title
     * block (`<p class="psub">{{ $sub }}</p>`, unchanged by this lane) escapes
     * what it prints -- so cleaning "Masks & Peels" would put "Masks &amp;amp;
     * Peels" on every category page that has no header. Plain text has nothing
     * in it to clean, and everything that prints it raw goes through
     * RichText::forDisplay() on the way out regardless.
     */
    public static function importDescription(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        return str_contains($raw, '<') ? RichText::clean($raw) : $raw;
    }

    /**
     * The `header_*` columns from a categories.csv / brands.csv row.
     *
     * ONLY THE COLUMNS THE FILE ACTUALLY HAS. An export from a plugin older than
     * 1.11.0 has none of them, and writing null for each would erase a header
     * the last 1.11.0 import brought in -- so an older file leaves them alone.
     *
     * @return array<string, string|null>
     */
    public static function importColumns(Row $row): array
    {
        $out = [];

        if ($row->has('banner_image')) {
            $out['header_image'] = self::safeImage($row->text('banner_image'));
        }

        if ($row->has('banner_source_key')) {
            $source = self::text($row->text('banner_source_key'), 255);
            $out['header_source'] = $source === '' ? null : $source;
        }

        /*
         * `title_override` and `subtitle` are NOT imported (2.60.350). The
         * exporter finds them by key NAME, and on the owner's shop the key it
         * found held the theme's "hide" switch, which became the title of
         * every category. The owner wants the category's name; a custom title
         * is typed on this shop, in Catalog → Categories → Edit.
         */

        return $out;
    }

    /**
     * http(s), or a site-relative path with no `..` -- or nothing. This value
     * lands in an <img src>; in particular no `data:` and no `javascript:`.
     */
    public static function safeImage(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : '';

        if ($v === '' || strlen($v) > 2048 || preg_match('/[\s"<>\\\\]/', $v) === 1) {
            return null;
        }

        if (preg_match('#^https?://[^/]#i', $v) === 1) {
            return $v;
        }

        if (str_starts_with($v, '/') && ! str_starts_with($v, '//') && ! str_contains($v, '..')) {
            return $v;
        }

        return null;
    }

    /** One line of plain text, tags removed, at most $max characters. */
    private static function text(mixed $v, int $max): string
    {
        if (! is_string($v)) {
            return '';
        }

        $v = html_entity_decode(strip_tags($v), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $v)), 0, $max);
    }
}
