<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Store\ShopController;
use App\Models\AdminUser;
use App\Models\Category;
use App\Services\CategoryHeaders;
use App\Services\PageBanners;
use App\Services\SiteLayout;
use App\Support\Locale;
use App\Support\PageBanner;
use App\Support\RichText;
use App\Support\TitleHeader;
use App\Support\TitleHeaderInput;
use App\Support\Url;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Vite;
use Illuminate\Validation\ValidationException;

/**
 * The category page's "Edit header" panel on the shop. (Lane CH)
 *
 * The owner: "for categories we will have category name, description. and
 * background image, but everything can be controlled from the front-end with
 * edit button. and including header area size, spacings etc. AND in the same
 * edit panel, we will have option to choose this page with custom header area,
 * so in that tab we will see the same features as we have done for super sale
 * page."
 *
 * routes/category-header-admin.php mounts the two endpoints inside the guarded
 * admin-api group; AdminCapabilities maps both to `categoryheader.manage`
 * (owner, manager, editor), and a path missing from that map is owner-only,
 * so a mistake fails closed. StorefrontAdminController::context() hands the
 * panel its data through editorContext(), for that capability only.
 *
 * ONE VALIDATOR. The title header's fields are checked by TitleHeaderInput's
 * rules and clean() -- Catalog → Categories' own -- and the header_style the
 * panel sends is MERGED onto the stored one, so keys it does not draw (the
 * box colours) are kept. The custom header area goes through
 * CategoryHeaders::clean(), which is PageHeaders::bag() and
 * PageBanners::sanitize(). A key the panel does not own is refused, so this
 * cannot write a category's name, slug or parent.
 */
class CategoryHeaderApiController extends Controller
{
    /** The title header fields the panel may send. */
    public const FIELDS = ['header_title', 'header_description', 'header_image', 'header_style'];

    /** The header_style keys the panel draws. Anything else in the column is kept as it is. */
    public const STYLE_KEYS = [
        'h_phone', 'h_desktop', 'title_phone', 'title_desktop',
        'mt_phone', 'mt_desktop', 'mb_phone', 'mb_desktop', 'py_phone', 'py_desktop', 'px_phone', 'px_desktop', 'maxw',
        'align_phone', 'align_desktop', 'valign_phone', 'valign_desktop', 'treatment_phone', 'treatment_desktop',
        'text_phone', 'text_desktop', 'box_phone', 'box_desktop', 'focus', 'focus_desktop', 'img_phone',
    ];

    /** The longest name and description the panel takes, in characters. */
    public const MAX_TITLE = 160;

    public const MAX_DESCRIPTION = 1000;

    /** Per admin, per minute. */
    public const LIMITS = ['preview' => 90, 'save' => 20];

    /**
     * The number controls: [key, label, setting, device]. The setting gives
     * the range (its slider's own) and the value shown when the category has
     * none of its own; TitleHeader::PX_VARS gives the property it moves.
     */
    public const NUMBERS = [
        ['h_desktop', 'Height', 'cat_header_h_desktop', 'd', 'size'],
        ['h_phone', 'Height', 'cat_header_h_phone', 'm', 'size'],
        ['maxw', 'Text width', 'cat_header_maxw', 'both', 'size'],
        ['title_desktop', 'Title size', 'cat_header_title_desktop', 'd', 'text'],
        ['title_phone', 'Title size', 'cat_header_title_phone', 'm', 'text'],
        ['mt_desktop', 'Space above', 'cat_header_mt_desktop', 'd', 'space'],
        ['mt_phone', 'Space above', 'cat_header_mt_phone', 'm', 'space'],
        ['mb_desktop', 'Space below', 'cat_header_mb_desktop', 'd', 'space'],
        ['mb_phone', 'Space below', 'cat_header_mb_phone', 'm', 'space'],
        ['py_desktop', 'Inner padding, top and bottom', 'cat_header_pad_y_desktop', 'd', 'space'],
        ['py_phone', 'Inner padding, top and bottom', 'cat_header_pad_y_phone', 'm', 'space'],
        ['px_desktop', 'Inner padding, sides', 'cat_header_pad_x_desktop', 'd', 'space'],
        ['px_phone', 'Inner padding, sides', 'cat_header_pad_x_phone', 'm', 'space'],
    ];

    // ------------------------------------------------------------- endpoints

    /** POST /admin-api/category-header/{id}/preview -- the title header from unsaved values. */
    public function preview(Request $request, string $id): JsonResponse
    {
        if ($limited = $this->limit('preview')) {
            return $limited;
        }

        $category = Category::query()->findOrFail((int) $id);
        $this->useLocaleOf($request);

        $copy = clone $category;
        foreach ($this->validatedFields($request, $category) as $key => $value) {
            $copy->setAttribute($key, $value);
        }

        return response()->json(['ok' => true] + self::rendered($copy));
    }

    /**
     * POST /admin-api/category-header/{id}
     *
     *   mode    title | custom      which header the page draws
     *   fields  the title header's  (optional; only the keys sent change)
     *   custom  the custom area     (optional; header, banner, banner_on, banner_at)
     *
     * Everything is checked before anything is written.
     */
    public function save(Request $request, string $id): JsonResponse
    {
        if ($limited = $this->limit('save')) {
            return $limited;
        }

        $category = Category::query()->findOrFail((int) $id);
        $this->useLocaleOf($request);

        $unknown = array_values(array_diff(array_keys($request->except(['_token', 'path'])), ['mode', 'fields', 'custom']));
        if ($unknown !== []) {
            throw ValidationException::withMessages([$unknown[0] => 'The header panel cannot change that.']);
        }

        $request->validate([
            'mode' => ['required', 'string', 'in:title,custom'],
            'fields' => ['nullable', 'array'],
            'custom' => ['nullable', 'array'],
        ]);

        $data = $this->validatedFields($request, $category);

        // The switch rides in the same column, so "back to the title header"
        // keeps every custom-area setting where it is.
        $style = TitleHeader::sanitizeStyle($data['header_style'] ?? $category->getAttribute('header_style'));
        unset($style['mode']);
        if ($request->input('mode') === 'custom') {
            $style['mode'] = 'custom';
        }
        $data['header_style'] = $style === [] ? null : $style;

        $headers = app(CategoryHeaders::class);
        $custom = $request->input('custom');

        if (is_array($custom)) {
            [, $rejected] = CategoryHeaders::clean($custom, (int) $category->getKey(), (string) $category->name, true);
            if ($rejected !== []) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Not saved — check: '.implode(', ', array_unique(array_values($rejected))),
                    'rejected' => array_keys($rejected),
                ], 422);
            }
        }

        foreach ($data as $key => $value) {
            $category->setAttribute($key, $value);
        }
        $category->save();

        if (is_array($custom)) {
            $headers->save((int) $category->getKey(), (string) $category->name, $custom);
        }

        ShopController::flushSidebarCache();
        Cache::forget('kbb.home.cats');
        Cache::forget('kbb.home.rails');

        $fresh = $category->fresh();
        $headers->forget();

        return response()->json([
            'ok' => true,
            'message' => $request->input('mode') === 'custom' ? 'Saved — this page shows the custom header area' : 'Saved',
            'mode' => self::modeOf($fresh),
            'fields' => self::fields($fresh),
            'custom' => self::customOf($fresh),
        ] + self::rendered($fresh));
    }

    // --------------------------------------------------------------- context

    /**
     * What the panel needs for one category. Read by
     * StorefrontAdminController::context(), only for an admin who holds
     * categoryheader.manage, and only on a category page. Every value is
     * listed here by name; nothing is a model dump.
     */
    public static function editorContext(Category $category): array
    {
        $id = (int) $category->getKey();
        $name = (string) $category->getAttribute('name');
        $shop = app(SiteLayout::class)->all();

        $numbers = [];
        foreach (self::NUMBERS as [$key, $label, $setting, $dev, $group]) {
            $bounds = SiteLayout::SCHEMA[$setting][4] ?? [];
            $numbers[] = [
                'key' => $key,
                'label' => $label,
                'dev' => $dev,
                'group' => $group,
                'var' => (string) array_search($setting, TitleHeader::PX_VARS, true),
                'min' => (int) ($bounds['min'] ?? 0),
                'max' => (int) ($bounds['max'] ?? 1000),
                'step' => (int) ($bounds['step'] ?? 1),
                'shop' => TitleHeader::clampTo($setting, (int) ($shop[$setting] ?? SiteLayout::SCHEMA[$setting][2] ?? 0)),
            ];
        }

        $options = static function (array $map): array {
            $out = [];
            foreach ($map as $value => $label) {
                $out[] = ['value' => (string) $value, 'label' => (string) $label];
            }

            return $out;
        };

        $total = (int) $category->products()->count();

        return [
            'id' => $id,
            'key' => CategoryHeaders::key($id),
            'name' => $name,
            'mode' => self::modeOf($category),
            'legacy_banner' => PageBanner::forModel($category, $name) !== null,
            'fields' => self::fields($category),
            'custom' => self::customOf($category),
            'placeholders' => [
                'title' => $name,
                'description' => mb_substr(trim(RichText::toText(RichText::forDisplay((string) $category->getAttribute('description')))), 0, 200),
            ],
            'limits' => ['title' => self::MAX_TITLE, 'description' => self::MAX_DESCRIPTION],
            'spec' => [
                'numbers' => $numbers,
                'choices' => [
                    ['key' => 'align', 'label' => 'Alignment', 'options' => $options(['start' => 'Left', 'center' => 'Centre', 'end' => 'Right'])],
                    ['key' => 'valign', 'label' => 'Where the words sit', 'options' => $options(['top' => 'Top', 'center' => 'Middle', 'bottom' => 'Bottom'])],
                    ['key' => 'treatment', 'label' => 'Behind the words', 'options' => $options(array_map(static fn ($l) => preg_replace('/^\d+ · /u', '', $l), SiteLayout::TREATMENTS))],
                    ['key' => 'text', 'label' => 'Text colour', 'options' => $options(['auto' => 'Automatic', 'light' => 'White', 'dark' => 'Dark'])],
                    ['key' => 'box', 'label' => 'Light box (no picture)', 'options' => $options(array_map(static fn ($l) => preg_replace('/^[A-F] · /u', '', $l), SiteLayout::BOX_STYLES))],
                ],
                'focus' => $options(['left' => 'Left', 'center' => 'Centre', 'right' => 'Right']),
                'breakpoint' => 900,
                'banner_at' => $options(CategoryHeaders::BANNER_AT),
                'banner' => [
                    'numbers' => array_map(static fn ($k, $v) => ['key' => $k, 'min' => $v[0], 'max' => $v[1], 'label' => $v[3]], array_keys(PageBanners::NUMBERS), PageBanners::NUMBERS),
                    'colours' => array_map(static fn ($k, $v) => ['key' => $k, 'label' => $v[1]], array_keys(PageBanners::COLOURS), PageBanners::COLOURS),
                    'devices' => $options(PageBanners::DEVICES),
                    'max_items' => PageBanners::MAX_ITEMS,
                    'max_text' => PageBanners::MAX_TEXT,
                    'css' => PageBanners::CSS,
                    'breakpoint' => PageBanners::BREAKPOINT,
                ],
                'area_css' => CategoryHeaders::CSS,
            ],
            'pageheader' => PageHeaderApiController::spec(),
            'sample' => [
                'home' => __('store.breadcrumb.home'),
                'crumb' => __('store.shop.crumb_category'),
                'title' => CategoryHeaders::heading($category, $name),
                'count' => trans_choice('store.collection.product_count', $total, ['formatted' => number_format($total)]),
                'intro' => CategoryHeaders::intro($category),
                'button' => __('store.collection.all_products'),
                'home_href' => Url::to('/'),
                'button_href' => Url::to('/shop/'),
            ],
            'endpoints' => [
                'save' => Url::to('/admin-api/category-header/'.$id),
                'preview' => Url::to('/admin-api/category-header/'.$id.'/preview'),
                'media' => Url::to('/admin-api/media'),
                'upload' => Url::to('/admin-api/media/upload'),
            ],
            'upload' => [
                'folder' => 'categories',
                'max_bytes' => StorefrontAdminController::UPLOAD_MAX_BYTES,
                'types' => StorefrontAdminController::UPLOAD_TYPES,
                'recommended' => StorefrontAdminController::RECOMMENDED,
            ],
            'css' => self::stylesheet(),
            // Lane CB: what the category banner needs when the preview draws
            // it on a page that did not (its first picture, just uploaded).
            'panel_css' => self::stylesheet('resources/css/kbb/kbb-brand-header.css'),
            'panel_inline' => \App\Support\BrandPanel::CATEGORY_CSS,
            'console' => (string) (parse_url(route('admin'), PHP_URL_PATH) ?: '/').'?kbb-open=category:'.$id.'#catalog/categories',
        ];
    }

    // --------------------------------------------------------------- helpers

    public static function modeOf(Category $category): string
    {
        return (TitleHeader::sanitizeStyle($category->getAttribute('header_style'))['mode'] ?? null) === 'custom' ? 'custom' : 'title';
    }

    /** The title header's values as the panel's boxes show them. */
    public static function fields(Category $category): array
    {
        $style = TitleHeader::sanitizeStyle($category->getAttribute('header_style'));
        $desc = (string) ($category->getAttribute('header_description') ?? '');

        return [
            'header_title' => trim(strip_tags((string) ($category->getAttribute('header_title') ?? ''))),
            'header_description' => RichText::isBlank($desc) ? '' : trim(RichText::toText(RichText::forDisplay($desc))),
            'header_image' => (string) (TitleHeader::safeImage($category->getAttribute('header_image')) ?? ''),
            'header_style' => (object) array_intersect_key($style, array_flip(self::STYLE_KEYS)),
        ];
    }

    /** The custom area as the panel edits it. */
    public static function customOf(Category $category): array
    {
        return app(CategoryHeaders::class)->entryFor((int) $category->getKey(), (string) $category->getAttribute('name'));
    }

    /**
     * The title header as the page draws it, from the same component and the
     * same inputs as ShopController.
     *
     * @return array{html: string, note: string}
     */
    public static function rendered(Category $category): array
    {
        $title = (string) $category->t('name');

        /*
         * Lane CB: a category with a picture draws the category banner -- the
         * brand page's Panel -- on its page (ShopController), so the live
         * preview and the swap after Save draw that too: the same partial,
         * the same inputs, in the same wrapper.
         */
        $all = app(SiteLayout::class)->all();
        $panel = \App\Support\BrandPanel::forCategory($category, $all, $title, PageBanner::forModel($category, $title));

        if ($panel !== null) {
            return [
                'html' => '<div class="wrap kbb-cbw">'.trim(view('store.partials.brand-panel', ['panel' => $panel, 'panelCategory' => $category])->render()).'</div>',
                'note' => 'This category has a picture, so it shows the category banner. Its look is Appearance → Site layout → Category banner, and this category\'s own in Catalog → Categories → Edit → Category header → Banner layout.',
            ];
        }

        $header = TitleHeader::forModel($category, $title, PageBanner::forModel($category, $title));

        if ($header === null) {
            return [
                'html' => '',
                'note' => PageBanner::forModel($category, $title) !== null
                    ? 'This category shows its own banner (Catalog → Categories → Banner), so the title header is not drawn.'
                    : 'The category header is switched off in Appearance → Site layout → Category header, or this category has no picture and the light box is off.',
            ];
        }

        return [
            'html' => trim(Blade::render('<x-kbb-title-header :header="$header" />', ['header' => $header])),
            'note' => '',
        ];
    }

    /**
     * The title header fields from the request, checked by Catalog →
     * Categories' own rules and merged onto the stored style.
     *
     * @return array<string, mixed>
     */
    private function validatedFields(Request $request, Category $category): array
    {
        $fields = $request->input('fields');
        $fields = is_array($fields) ? $fields : [];

        $unknown = array_values(array_diff(array_keys($fields), self::FIELDS));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['fields.'.$unknown[0] => 'The header panel cannot change "'.mb_substr((string) $unknown[0], 0, 40).'".']);
        }

        if (is_array($fields['header_style'] ?? null)) {
            $badStyle = array_values(array_diff(array_keys($fields['header_style']), self::STYLE_KEYS));
            if ($badStyle !== []) {
                throw ValidationException::withMessages(['fields.header_style.'.$badStyle[0] => 'The header panel cannot change that setting.']);
            }
        }

        $rules = array_intersect_key(TitleHeaderInput::rules(), array_flip(['header_image', 'header_title', 'header_description']));
        $rules['header_title'][] = 'max:'.self::MAX_TITLE;
        $rules['header_description'][] = 'max:'.self::MAX_DESCRIPTION;
        foreach (TitleHeaderInput::rules() as $key => $rule) {
            if (str_starts_with($key, 'header_style')) {
                $rules[$key] = $rule;
            }
        }

        // The description is PLAIN TEXT here: escaped before it is stored, so
        // the page's allowlist prints it as the words typed, never as markup.
        $shaped = $fields;
        $data = Validator::make($shaped, $rules)->validate();

        if (array_key_exists('header_description', $data) && $data['header_description'] !== null) {
            $plain = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', (string) $data['header_description']));
            $data['header_description'] = $plain === '' ? null : e($plain, false);
        }
        if (array_key_exists('header_title', $data) && $data['header_title'] !== null) {
            $data['header_title'] = trim(strip_tags((string) $data['header_title']));
        }

        if (array_key_exists('header_style', $data)) {
            // MERGED, NOT REPLACED: a key the panel sends wins (blank clears
            // it); a key it does not draw stays as it is.
            $current = TitleHeader::sanitizeStyle($category->getAttribute('header_style'));
            foreach ((array) $data['header_style'] as $key => $value) {
                if ($value === null || $value === '') {
                    unset($current[$key]);
                } else {
                    $current[$key] = $value;
                }
            }
            $data['header_style'] = $current;
        }

        return TitleHeaderInput::clean($data);
    }

    private static function stylesheet(string $entry = 'resources/css/kbb/kbb-title-header.css'): ?string
    {
        try {
            $path = (string) (parse_url(Vite::asset($entry), PHP_URL_PATH) ?? '');
        } catch (\Throwable) {
            return null;
        }

        return str_starts_with($path, '/') && ! str_starts_with($path, '//') ? $path : null;
    }

    /** Render in the language of the page the owner is on. */
    private function useLocaleOf(Request $request): void
    {
        $path = '/'.ltrim((string) $request->input('path', '/'), '/');
        $base = Url::base();

        if ($base !== '' && str_starts_with($path, $base.'/')) {
            $path = substr($path, strlen($base));
        }

        [$locale] = Locale::splitPath($path);

        app()->setLocale(is_string($locale) && Locale::isSupported($locale) && Locale::enabled($locale) ? $locale : Locale::DEFAULT);
    }

    /** Keyed on THIS admin and THIS action (StorefrontAdminController::limit() says why not `throttle:`). */
    private function limit(string $action): ?JsonResponse
    {
        $admin = Auth::guard('admin')->user();
        $key = 'kbb-category-header:'.$action.':'.($admin instanceof AdminUser ? 'a'.$admin->getKey() : 'ip'.request()->ip());

        if (RateLimiter::tooManyAttempts($key, self::LIMITS[$action])) {
            return response()->json([
                'ok' => false,
                'message' => $action === 'save' ? 'Too many saves in a minute. Wait a moment and press Save again.' : 'Too many previews in a minute. Wait a moment.',
            ], 429);
        }

        RateLimiter::hit($key, 60);

        return null;
    }
}
