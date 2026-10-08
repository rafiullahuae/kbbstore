<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Brand;
use App\Models\Category;

/**
 * The brand page's PANEL header (Lane BR2): the banner as the background, and
 * on it a panel holding the logo, the brand name beside it and the description
 * under them. On a phone the logo and name sit in a pill on the banner and the
 * description moves below it, into a light card.
 *
 * The owner: "logo will be on th background image, beside logo, brand name,
 * and downside brand description. and put a nice background of the content to
 * not merge with the background, and content should should not full width,
 * almost 60% of the page width, and in mobile the description will come under
 * banner, not on the banner." Then: "in mobile logo and name with capsule type
 * or rectangle background. and content will come downside the header area."
 *
 * Lane BR3, the owner: "with logo circle or rectangle to choose from, and all
 * controls options like box spacings, positioning etc and to adjust the height
 * of overal header also, and font sizes etc. ... allow controls for desktop and
 * mobile both on the front-end." So the panel's padding, inset and gap, its
 * place on the banner, the phone capsule's place, the card below the banner,
 * and the name, description and logo sizes -- laptop and phone apart.
 *
 * ── WHERE EACH VALUE COMES FROM ─────────────────────────────────────────────
 *
 * The brand's own choice (`brands.header_layout`, set in the "Edit brand
 * header" pop-up on the brand page), else the shop's (Appearance → Site layout
 * → Brand page). A blank or missing own value follows the shop.
 *
 * ── WHAT REACHES THE PAGE ───────────────────────────────────────────────────
 *
 * `class` is built only from option keys checked against the lists below, and
 * `style` only from integers clamped to RANGES under constant property names
 * plus colours that went through BrandLogo::clean(). Nothing typed is printed.
 */
final class BrandPanel
{
    public const LOGO_SHAPES = ['circle', 'rect'];

    public const PANELS = ['frost', 'brand'];

    public const PILLS = ['capsule', 'rect'];

    public const POSITIONS = ['left', 'center', 'right'];

    /** Lane BR3: where the laptop panel sits on the banner, across and up/down. */
    public const PANEL_X = ['left', 'center', 'right'];

    public const PANEL_Y = ['middle', 'top', 'bottom'];

    /** Lane BR3: where the phone's capsule sits on the banner. */
    /** Lane BR4: Bottom centre first -- "centered align" -- so it prints no class. */
    public const PILL_AT = ['bottom-center', 'bottom-left', 'bottom-right', 'top-left', 'top-center', 'top-right'];

    /**
     * Lane BR4: the name's and the description's alignment. CENTRE FIRST, so
     * the owner's "centered align" is the stylesheet's own layout and prints
     * no class; Left and Right print `brw-ph--na-left` and the like.
     */
    public const ALIGNS = ['center', 'left', 'right'];

    /**
     * Lane BR4: "Show the brand logo", per device. OFF FIRST: off is the
     * owner's default and the stylesheet's own layout, so it prints no class,
     * and On prints `brw-ph--lg-on` / `brw-ph--lgm-on`. The shop's value is a
     * switch (SiteLayout `bool`); shop() says it in these words.
     */
    public const LOGO_SHOWS = ['off', 'on'];

    /** The choices whose shop setting is a switch: true is 'on', false 'off'. */
    public const SWITCHES = ['logo_show', 'logo_show_m'];

    /**
     * Lane BR4: about how many characters of the description fill one line of
     * the panel at its shipped type size and width -- laptop at 15px in a 60%
     * panel, phone at 14px in the card on a 360-390px screen. A description
     * longer than its lines' worth is cut there by CSS and gets "Read more";
     * a shorter one is printed whole with no button. COUNTED HERE, ON THE
     * SERVER, from the text: nothing on the page is measured. Scaled by the
     * type size and (laptop) the panel width actually chosen, so a bigger font
     * reaches the button sooner. An estimate errs the safe way: text that is
     * not cut never hides anything, so a slightly long two-line description
     * shows as three lines rather than losing its end with no way to open it.
     */
    public const LINE_CHARS = ['laptop' => 80, 'phone' => 44];

    /**
     * own key => [shop setting, min, max, CSS property, unit]. The shop's
     * setting has the same bounds (SiteLayout::SCHEMA), so a value is held to
     * one range whichever end it came from.
     *
     * @var array<string, array{0:string, 1:int, 2:int, 3:string, 4:string}>
     */
    public const RANGES = [
        'width' => ['brand_header_w', 60, 100, '--brw-ph-w', '%'],
        'height' => ['brand_banner_h', 160, 460, '--brw-ph-h', 'px'],
        'height_m' => ['brand_banner_h_m', 100, 300, '--brw-ph-hm', 'px'],
        'content' => ['brand_content_w', 40, 85, '--brw-ph-cw', '%'],
        // Lane BR3 -- laptop: the panel's box and type.
        'pad' => ['brand_panel_pad', 8, 60, '--brw-ph-pad', 'px'],
        'inset' => ['brand_panel_inset', 0, 120, '--brw-ph-in', 'px'],
        'gap' => ['brand_desc_gap', 0, 40, '--brw-ph-gap', 'px'],
        'name' => ['brand_name_fs', 18, 56, '--brw-ph-fn', 'px'],
        'desc' => ['brand_desc_fs', 12, 22, '--brw-ph-fd', 'px'],
        'logo_size' => ['brand_logo_size', 40, 120, '--brw-ph-lg', 'px'],
        // Lane BR3 -- phone: the capsule, the card below the banner, the type.
        'inset_m' => ['brand_pill_inset_m', 0, 40, '--brw-ph-im', 'px'],
        'gap_m' => ['brand_card_gap_m', 0, 40, '--brw-ph-gm', 'px'],
        'card_pad_m' => ['brand_card_pad_m', 6, 32, '--brw-ph-cp', 'px'],
        'name_m' => ['brand_name_fs_m', 14, 36, '--brw-ph-fnm', 'px'],
        'desc_m' => ['brand_desc_fs_m', 12, 20, '--brw-ph-fdm', 'px'],
        'logo_size_m' => ['brand_logo_size_m', 28, 80, '--brw-ph-lgm', 'px'],
        // Lane BR4: how many lines of the description show before "Read more".
        'lines' => ['brand_desc_lines', 1, 6, '--brw-ph-dl', ''],
        'lines_m' => ['brand_desc_lines_m', 1, 6, '--brw-ph-dlm', ''],
        // 6 Oct: the header's outer spacing (kbb-brand-header.css, "OUTER SPACING").
        'space_top' => ['brand_space_top', 0, 80, '--brw-ph-st', 'px'],
        'space_x' => ['brand_space_x', 0, 80, '--brw-ph-sx', 'px'],
        'space_top_m' => ['brand_space_top_m', 0, 60, '--brw-ph-stm', 'px'],
        'space_x_m' => ['brand_space_x_m', 0, 40, '--brw-ph-sxm', 'px'],
    ];

    /**
     * Lane BR3. The sizes the stylesheet already draws when its property is
     * absent -- each `var(--x, <this>)` fallback in kbb-brand-header.css, and
     * each the shop's shipped value (SiteLayout::SCHEMA). A size at this value
     * is NOT printed, so a brand nobody has touched keeps its markup byte for
     * byte, and the laptop's name and inset keep their narrow-screen clamp().
     * BrandPanelControlsTest pins the three places to one another.
     *
     * @var array<string, int>
     */
    public const QUIET = [
        'pad' => 26, 'inset' => 36, 'gap' => 12, 'name' => 34, 'desc' => 15, 'logo_size' => 72,
        'inset_m' => 12, 'gap_m' => 12, 'card_pad_m' => 14, 'name_m' => 22, 'desc_m' => 14, 'logo_size_m' => 52,
        'lines' => 2, 'lines_m' => 2,
        'space_top' => 22, 'space_x' => 22, 'space_top_m' => 22, 'space_x_m' => 22,
    ];

    /**
     * Lane BR3. Choices whose FIRST option is the stylesheet's own layout and
     * prints no class; any other prints `brw-ph--<prefix>-<option>`.
     *
     * @var array<string, string>
     */
    public const QUIET_CHOICES = ['panel_x' => 'px', 'panel_y' => 'py', 'pill_at' => 'at',
        // Lane BR4: the logo switches, and the alignments (centre first).
        'logo_show' => 'lg', 'logo_show_m' => 'lgm', 'name_align' => 'na', 'desc_align' => 'da', 'desc_align_m' => 'dam'];

    /**
     * own key => [shop setting, allowed values].
     *
     * @var array<string, array{0:string, 1:list<string>}>
     */
    public const CHOICES = [
        'logo' => ['brand_logo_shape', self::LOGO_SHAPES],
        'panel' => ['brand_panel_style', self::PANELS],
        'pill' => ['brand_pill', self::PILLS],
        'position' => ['brand_img_pos', self::POSITIONS],
        'panel_x' => ['brand_panel_x', self::PANEL_X],
        'panel_y' => ['brand_panel_y', self::PANEL_Y],
        'pill_at' => ['brand_pill_at', self::PILL_AT],
        'logo_show' => ['brand_logo_show', self::LOGO_SHOWS],
        'logo_show_m' => ['brand_logo_show_m', self::LOGO_SHOWS],
        'name_align' => ['brand_name_align', self::ALIGNS],
        'desc_align' => ['brand_desc_align', self::ALIGNS],
        'desc_align_m' => ['brand_desc_align_m', self::ALIGNS],
    ];

    /** Used when a brand has no colour of its own: the shop pink. */
    private const FALLBACK_COLOUR = '#e0567b';

    /**
     * Lane CB: the same header on a CATEGORY page -- the owner: "i need the
     * categories banners, exact same like brand banners. with exact same
     * controls and everything." Every setting above is the brand page's; a
     * category reads the same setting under this prefix instead of `brand_`
     * (Appearance -> Site layout -> Category banner), with the same bounds
     * and the same shipped values, so the two headers cannot drift apart.
     */
    public const CATEGORY_PREFIX = 'catb_';

    /**
     * Lane CB: the few rules the category banner needs beyond kbb-brand-
     * header.css -- the ones the brand page keeps in its own view (store/
     * brands: the logo circle, the description's paragraphs) -- scoped to the
     * category banner's wrapper, plus the wrapper's 22px, the brand page's
     * .brw top padding that "space above the header" counts from. A CONSTANT:
     * printed unescaped into a <style> by store/partials/category-panel-head
     * and handed to the category "Edit header" panel for its live preview.
     */
    public const CATEGORY_CSS = '.kbb-cbw{padding-top:22px}'
        .'.kbb-cbw .brw-logo{border-radius:50%;background:var(--cream);display:grid;place-items:center;overflow:hidden;flex:none}'
        .'.kbb-cbw .brw-logo img{width:100%;height:100%;object-fit:contain}'
        .'.kbb-cbw .brw-initial{font-size:34px;color:var(--pink)}'
        .'.kbb-cbw .brw-desc p{margin:0 0 6px}.kbb-cbw .brw-desc p:last-child{margin-bottom:0}';

    /** @var array<string, bool> table => whether its `header_layout` exists */
    private static array $column = [];

    /**
     * Whether `{table}.header_layout` exists yet. Asked by the WRITER only: a
     * package's files land before its migrations run. The storefront only
     * reads the attribute, which is null on a row without the column.
     */
    public static function columnReady(string $table = 'brands'): bool
    {
        if (! isset(self::$column[$table])) {
            try {
                self::$column[$table] = \Illuminate\Support\Facades\Schema::hasColumn($table, 'header_layout');
            } catch (\Throwable) {
                return false;
            }
        }

        return self::$column[$table];
    }

    public static function forgetColumn(): void
    {
        self::$column = [];
    }

    /**
     * The shop setting a key reads: the brand page's own name, or for a
     * category the same name under CATEGORY_PREFIX (`brand_banner_h` ->
     * `catb_banner_h`).
     */
    public static function settingKey(string $brandSetting, bool $category = false): string
    {
        return $category ? self::CATEGORY_PREFIX.substr($brandSetting, strlen('brand_')) : $brandSetting;
    }

    /**
     * Lane CB: the category banner's per-category controls, for Catalog ->
     * Categories -> Edit -> Category header -> Banner layout -- every own
     * key, labelled and bounded by its Site layout setting, so the screen
     * cannot offer an option or a range the server would refuse.
     *
     * @return list<array{key:string, setting:string, label:string, type:string, options?:array<string,string>, min?:int, max?:int, unit?:string}>
     */
    public static function categoryFields(): array
    {
        $schema = \App\Services\SiteLayout::SCHEMA;
        $label = static fn (string $setting): string => ucfirst((string) preg_replace('/^Banner · /u', '', (string) ($schema[$setting][1] ?? $setting)));
        $out = [];

        foreach (self::CHOICES as $key => [$brandSetting, $allowed]) {
            $setting = self::settingKey($brandSetting, true);
            $names = in_array($key, self::SWITCHES, true) ? ['off' => 'Off', 'on' => 'On'] : ($schema[$setting][4] ?? []);
            $options = [];

            foreach ($allowed as $value) {
                $options[$value] = (string) ($names[$value] ?? $value);
            }

            $out[] = ['key' => $key, 'setting' => $setting, 'label' => $label($setting), 'type' => 'choice', 'options' => $options];
        }

        foreach (self::RANGES as $key => [$brandSetting, $min, $max, , $unit]) {
            $setting = self::settingKey($brandSetting, true);
            $out[] = ['key' => $key, 'setting' => $setting, 'label' => $label($setting), 'type' => 'range', 'min' => $min, 'max' => $max, 'unit' => $unit];
        }

        return $out;
    }

    /**
     * A brand's own choices, cleaned: only known keys, a choice only when it is
     * one of its options, a size only as an integer inside its range. Anything
     * else is dropped, which means "follow the shop".
     *
     * @return array<string, string|int>
     */
    public static function sanitize(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach (self::CHOICES as $key => [, $allowed]) {
            $v = $raw[$key] ?? null;

            if (is_string($v) && in_array($v, $allowed, true)) {
                $out[$key] = $v;
            }
        }

        foreach (self::RANGES as $key => [, $min, $max]) {
            $v = $raw[$key] ?? null;

            if (is_int($v) || (is_string($v) && preg_match('/^\d{1,4}$/', $v) === 1) || (is_float($v) && is_finite($v))) {
                $out[$key] = max($min, min($max, (int) $v));
            }
        }

        return $out;
    }

    /**
     * The shop's values, as the pop-up shows them under "Shop setting".
     *
     * @param  array<string, mixed>  $layout  SiteLayout::all()
     * @return array<string, string|int>
     */
    public static function shop(array $layout, bool $category = false): array
    {
        $out = [];

        foreach (self::CHOICES as $key => [$setting, $allowed]) {
            $v = $layout[self::settingKey($setting, $category)] ?? null;

            if (in_array($key, self::SWITCHES, true)) {
                // A switch on Site layout; absent is its shipped Off.
                $out[$key] = $v === true || $v === 1 || $v === '1' ? 'on' : 'off';

                continue;
            }

            $out[$key] = is_string($v) && in_array($v, $allowed, true) ? $v : $allowed[0];
        }

        foreach (self::RANGES as $key => [$setting, $min, $max]) {
            $out[$key] = max($min, min($max, (int) ($layout[self::settingKey($setting, $category)] ?? $min)));
        }

        return $out;
    }

    /**
     * The header for one brand, resolved for drawing.
     *
     * ── LANE BR4: EVERY BRAND, BANNER OR NOT ────────────────────────────────
     *
     * $banner is PageBanner::forModel()'s answer for this brand. Until BR4 a
     * brand with the owner's own page banner never reached here at all
     * (BrandController::hero() kept it on the Compact row under the banner),
     * which is every brand he had given a banner -- the live Anua among them.
     * Now the Panel draws for it too, and the banner contributes its PICTURE:
     *
     *   picture      the page banner's picture when it has one, else the
     *                brand's header_image (the imported title-header picture),
     *                else the no-picture ground in the brand's own shades. The
     *                banner wins because it is the one the owner chose, and
     *                the one the pencil's "Banner picture" changes on such a
     *                brand (StorefrontAdminController::mode()), so an upload
     *                there is what the page shows. A brand has no phone-only
     *                picture (the banner has no such field; header_style's
     *                img_phone is a category's), so laptop and phone draw the
     *                same picture, the phone cropping it to cover.
     *   heading      not the banner's: the page's one <h1> is the brand name.
     *   description  the brand's own (TitleHeader::brandDescription); only when
     *                it has none, the banner's line under its heading, escaped
     *                -- printed once, here, and never by the banner as well.
     *
     * @param  array<string, mixed>  $layout  SiteLayout::all()
     * @param  array<string, mixed>|null  $banner  PageBanner::forModel()
     * @return array{image:?string, class:string, style:string, description:string, logo:bool, more:bool}
     */
    public static function forBrand(Brand $brand, array $layout, ?array $banner = null): array
    {
        $v = self::sanitize($brand->getAttribute('header_layout')) + self::shop($layout);
        $image = TitleHeader::safeImage($banner['image'] ?? null)
            ?? TitleHeader::safeImage($brand->getAttribute('header_image'));

        $description = TitleHeader::brandDescription($brand);

        if ($description === '' && is_string($banner['subheading'] ?? null) && trim($banner['subheading']) !== '') {
            $description = e(trim($banner['subheading']));
        }

        return self::draw($v, $image, $description, BrandLogo::ring($brand) ?? self::FALLBACK_COLOUR);
    }

    /**
     * Lane CB: the same header for one CATEGORY, or null -- and null is the
     * category page exactly as it was.
     *
     * ── WHICH PICTURE ───────────────────────────────────────────────────────
     *
     * The category's own Banner picture (Catalog -> Categories -> Edit ->
     * Category header -> Banner layout, `header_layout.image`) first; else
     * the picture its old header showed (oldPicture()) -- the owner: "yes if
     * there's banner, then the banner should be picked auto by new design".
     * Either one only when it is a file on this server (onServer()); one that
     * is not leaves the page exactly as it was, never a broken banner.
     * Appearance -> Site layout -> Category banner -> "Category header -- as
     * before" puts every category back on the title header.
     *
     * ── ONLY WITH A PICTURE ─────────────────────────────────────────────────
     *
     * A brand with no picture still draws the Panel, on a ground in its own
     * shades (`brw-ph--noimg`). A category does NOT: the owner asked for the
     * banner, and a category with no picture keeps today's header -- the light
     * box, the plain title or the Catalog banner's tint -- byte for byte. The
     * no-picture Panel is a decision for him, not a default this lane chose.
     *
     * ── WHERE EACH PART COMES FROM ──────────────────────────────────────────
     *
     *   picture      the category's own Banner picture; on "all", failing
     *                that, the Catalog banner's picture when it is on and has
     *                one, else the category's header picture (Catalog ->
     *                Categories -> Edit -> Category header -> Header picture,
     *                or the imported banner). Its PHONE picture is the one the category's
     *                "Edit header" panel already keeps (`header_style.
     *                img_phone`), offered to phones only.
     *   heading      the title header's own: the custom title in English, else
     *                the category's name, translated (TitleHeader::wordsOf).
     *   description  the title header's own, with its generic line for a
     *                category that has none.
     *   logo         a category has no logo; its own square picture
     *                (`categories.image`) stands in, behind the same "Show the
     *                logo" switches, which ship Off as the brand's do.
     *   colours      the shop pink, which is what a brand with no colour gets.
     *
     * Settings: the brand page's, under CATEGORY_PREFIX. Own choices:
     * `categories.header_layout`, through sanitize() exactly as a brand's.
     *
     * @param  array<string, mixed>  $layout  SiteLayout::all()
     * @param  array<string, mixed>|null  $banner  PageBanner::forModel()
     * @return array{image:string, image_phone:?string, srcset:string, srcset_phone:string, sizes:string, sizes_phone:string, heading:string, logo_image:?string, class:string, style:string, description:string, logo:bool, more:bool}|null
     */
    public static function forCategory(Category $category, array $layout, string $title, ?array $banner = null): ?array
    {
        if (($layout['catb_hero'] ?? 'panel') === 'header') {
            return null;
        }

        $own = $category->getAttribute('header_layout');

        // The category's own Banner picture first, else the picture its old
        // header showed -- oldPicture() -- each only if it is on this server.
        $image = null;
        $ratio = null;

        foreach ([self::categoryImage($own), self::oldPicture($category, $layout, $banner)] as $candidate) {
            if ($candidate !== null && ($ratio = self::onServer($candidate)) !== null) {
                $image = $candidate;

                break;
            }
        }

        if ($image === null) {
            return null;
        }

        $v = self::sanitize($own) + self::shop($layout, true);
        [$heading, $description] = TitleHeader::wordsOf($category, $title, $layout);

        if ($description === '' && is_string($banner['subheading'] ?? null) && trim($banner['subheading']) !== '') {
            $description = e(trim($banner['subheading']));
        }

        $style = TitleHeader::sanitizeStyle($category->getAttribute('header_style'));
        $phone = TitleHeader::safeImage($style['img_phone'] ?? null);
        $phoneRatio = $phone !== null && $phone !== $image ? self::onServer($phone) : null;
        // A phone picture that is not on this server is dropped, never drawn broken.
        $phone = $phoneRatio !== null ? $phone : null;

        return [
            'image_phone' => $phone,
            'srcset' => self::srcset($image),
            'srcset_phone' => $phone === null ? '' : self::srcset($phone),
            'sizes' => self::sizes($image, (int) $v['height'], (int) $v['height_m'], $ratio),
            'sizes_phone' => $phone === null ? '' : self::sizes($phone, (int) $v['height'], (int) $v['height_m'], $phoneRatio),
            'heading' => $heading,
            'logo_image' => TitleHeader::safeImage($category->getAttribute('image')),
        ] + self::draw($v, $image, $description, self::FALLBACK_COLOUR);
    }

    /**
     * Lane CB: the picture a category's OLD header showed, so the banner picks
     * it up by itself -- the owner: "yes if there's banner, then the banner
     * should be picked auto by new design". Exactly the old page's rule:
     * with the Catalog banner on, its picture (TitleHeader::forModel() steps
     * aside for it); otherwise, while Appearance -> Site layout -> Category
     * header -> "Show the header on category pages" is on, the header picture
     * (imported, or chosen in Category header), else -- only with "When no
     * banner was imported, use the category picture" on, which ships Off --
     * the category's own square picture.
     *
     * @param  array<string, mixed>  $layout
     * @param  array<string, mixed>|null  $banner
     */
    private static function oldPicture(Category $category, array $layout, ?array $banner): ?string
    {
        if ($banner !== null) {
            return TitleHeader::safeImage($banner['image'] ?? null);
        }

        if (empty($layout['cat_header'])) {
            return null;
        }

        return TitleHeader::safeImage($category->getAttribute('header_image'))
            ?? (! empty($layout['cat_header_fallback']) ? TitleHeader::safeImage($category->getAttribute('image')) : null);
    }

    /**
     * The picture's shape when it is a picture ON THIS SERVER, else null --
     * and null means the category keeps its old header, never a broken
     * banner. ImageVariants::aspectOf() opens the file's header under this
     * web root: a root-relative path or this host's own absolute URL that is
     * really an image answers; a missing file, a non-image, and an address on
     * another host (an old-domain WooCommerce URL the media rewrite has not
     * moved) do not. One header read, which sizes() needed anyway.
     */
    private static function onServer(string $image): ?float
    {
        try {
            $ratio = ImageVariants::aspectOf(ImageVariants::rootRelative($image));
        } catch (\Throwable) {
            return null;
        }

        return $ratio !== null && $ratio > 0 ? $ratio : null;
    }

    /**
     * Lane CB: a category's own Banner picture (`header_layout.image`), or
     * null. Through TitleHeader::safeImage(), the header picture's own check:
     * an uploaded path or an http(s) address, nothing that can leave the
     * attribute. Not in sanitize(), which a brand's layout shares.
     */
    public static function categoryImage(mixed $raw): ?string
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) ? TitleHeader::safeImage($raw['image'] ?? null) : null;
    }

    /**
     * The picture's img-cache copies, so a phone is not handed the 2400px
     * original. '' when there are none on disk (a picture still on the old
     * site, or no GD) -- read from the disk only, never a query.
     */
    private static function srcset(string $image): string
    {
        try {
            return ImageVariants::bannerSrcsetFor(ImageVariants::rootRelative($image));
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * The `sizes` for a picture that COVERS the banner. The banner is about
     * the page's width, but it is cropped to fill a fixed height, so a wide
     * picture in a short phone banner is drawn wider than the screen: a 3.2 : 1
     * picture in the 165px phone banner is 528 CSS px wide on a 390px phone.
     * `min(100vw, 2400px)` alone asked for the 400w copy there and the phone
     * upscaled it -- measured in Chromium. So the height times the picture's
     * own ratio is a floor, per device (the banner turns phone-shaped under
     * 600px). The ratio is read from the file's header, never a query; a
     * picture that is not on this server keeps the plain width.
     */
    private static function sizes(string $image, int $height, int $heightPhone, ?float $ratio = null): string
    {
        $plain = ImageVariants::bannerSliderSizesAttribute();
        $ratio ??= self::onServer($image);

        if ($ratio === null || $ratio <= 0) {
            return $plain;
        }

        $phone = (int) ceil($heightPhone * $ratio);
        $laptop = (int) ceil($height * $ratio);

        return '(max-width: 599px) max(100vw, '.min(2400, $phone).'px), min(max(100vw, '.min(2400, $laptop).'px), 2400px)';
    }

    /**
     * The class, the style and the switches, from resolved values -- one
     * body for the brand page and the category page.
     *
     * @param  array<string, string|int>  $v  own choices over the shop's
     * @return array{image:?string, class:string, style:string, description:string, logo:bool, more:bool}
     */
    private static function draw(array $v, ?string $image, string $description, string $base): array
    {
        $class = 'brw-ph brw-ph--'.$v['panel'].' brw-ph--pill-'.$v['pill'].' brw-ph--logo-'.$v['logo']
            .' brw-ph--pos-'.$v['position'].($image === null ? ' brw-ph--noimg' : '');

        foreach (self::QUIET_CHOICES as $key => $prefix) {
            if ($v[$key] !== self::CHOICES[$key][1][0]) {
                $class .= ' brw-ph--'.$prefix.'-'.$v[$key];
            }
        }

        /*
         * Lane BR4: "Read more" per device, from the text's length against the
         * lines that device shows (see LINE_CHARS). `brw-ph--more-l` / `-p`
         * turn the cut and the button on for that device; neither, and the
         * description is printed whole with no button at all.
         */
        $more = ['l' => false, 'p' => false];

        if ($description !== '') {
            $length = mb_strlen(trim(RichText::toText($description)));
            $laptop = (int) floor(self::LINE_CHARS['laptop'] * self::QUIET['desc'] / max(1, (int) $v['desc']) * (int) $v['content'] / 60);
            $phone = (int) floor(self::LINE_CHARS['phone'] * self::QUIET['desc_m'] / max(1, (int) $v['desc_m']));
            $more = ['l' => $length > $laptop * (int) $v['lines'], 'p' => $length > $phone * (int) $v['lines_m']];
        }

        foreach ($more as $device => $on) {
            $class .= $on ? ' brw-ph--more-'.$device : '';
        }

        $style = [];

        foreach (self::RANGES as $key => [, , , $property, $unit]) {
            if ((self::QUIET[$key] ?? null) !== (int) $v[$key]) {
                $style[] = $property.':'.(int) $v[$key].$unit;
            }
        }

        $style[] = '--brw-ph-dk:'.self::mix($base, '#000000', 30);
        $style[] = '--brw-ph-lt:'.self::mix($base, '#ffffff', 10);

        return [
            'image' => $image,
            'class' => $class,
            'style' => implode(';', $style),
            'description' => $description,
            // No logo markup at all when it is off on both devices: no empty
            // circle, no gap. On one device only, the class hides it on the other.
            'logo' => $v['logo_show'] === 'on' || $v['logo_show_m'] === 'on',
            'more' => $more['l'] || $more['p'],
        ];
    }

    /**
     * `$percent` of $hex over $with, as lower-case #rrggbb. Both arguments are
     * already #rrggbb (BrandLogo::clean() or a constant).
     */
    public static function mix(string $hex, string $with, int $percent): string
    {
        $p = max(0, min(100, $percent)) / 100;
        $out = '#';

        for ($i = 1; $i <= 5; $i += 2) {
            $a = hexdec(substr($hex, $i, 2));
            $b = hexdec(substr($with, $i, 2));
            $out .= sprintf('%02x', (int) round($a * $p + $b * (1 - $p)));
        }

        return $out;
    }
}
