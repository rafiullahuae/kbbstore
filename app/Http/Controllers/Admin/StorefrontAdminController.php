<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Store\ShopController;
use App\Models\AdminUser;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Services\AdminPathService;
use App\Support\BrandLogo;
use App\Support\BrandPanel;
use App\Support\CategoryPath;
use App\Support\Locale;
use App\Support\PageBanner;
use App\Support\RichText;
use App\Support\StorefrontAdminHint;
use App\Support\TitleHeader;
use App\Support\TitleHeaderInput;
use App\Support\Url;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Vite;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The storefront's admin layer: the thin bar and the quick-edit pencil. (Lane RA)
 *
 * The owner, verbatim:
 *
 *   "on any category, brand page. i want a pencil icon + edit minimal button,
 *    only administrator for now ... this button will open a beautiful popup
 *    having all the options for this specific page (category or brand) to
 *    upload/change background image, title, description etc etc. just basic
 *    things ... the save button on poupup will save everything smooth and
 *    update the page without being refreshed and close the popup auto."
 *
 *   "plus i need site thin top bar, will show only for administrators. to have
 *    multiple imporant links to go directly to them ... also administrator
 *    name, along with logout button. but it should not disturb anything"
 *
 * ── NONE OF THIS IS IN THE SHOP'S HTML ─────────────────────────────────────
 *
 * Every storefront page is rendered identically for everybody. The page's own
 * JavaScript checks for StorefrontAdminHint's cookie and, only when it is
 * there, asks context() below what to draw. Everything an administrator sees
 * -- the console's address, his name, the order count, the editable values --
 * arrives in THIS response, which sits behind auth:admin and NoStoreAdminApi
 * (`Cache-Control: no-store`) and is never a page anybody else can be served.
 *
 * ── HOW IT KNOWS WHICH PAGE IT IS ON ───────────────────────────────────────
 *
 * The loader sends `location.pathname`, and this class asks the ROUTER which
 * storefront route that path is, then resolves the record the same way that
 * route's controller does (CategoryPath::resolve() for a collection, the slug
 * for a brand or product). So the page needs no marker of its own -- not a
 * byte of the shop's HTML had to change for the pencil to know it is on
 * "Sunscreens" -- and a path that names nothing simply gets no pencil.
 *
 * ── ONE VALIDATOR ──────────────────────────────────────────────────────────
 *
 * The save is validated by TitleHeaderInput::rules() and ::clean(), which are
 * the rules Catalog -> Categories -> Edit uses (moved out of
 * CategoriesApiController verbatim for exactly this). The picture arrives from
 * /admin-api/media/upload, the media library's own pipeline, and the URL is
 * checked again here by TitleHeader::safeImage(). A key the pencil does not
 * own is REFUSED rather than ignored, so this endpoint cannot be used to write
 * a category's name, slug or parent -- the things that move URLs.
 */
class StorefrontAdminController extends Controller
{
    /** What the pencil may change on a category. */
    public const CATEGORY_KEYS = ['header_image', 'header_title', 'header_subtitle', 'header_description', 'focus'];

    /**
     * And on a brand, which has no style column and so no phone crop -- but
     * does have a logo (Lane BH: "provide facility to upload the brand logo",
     * from the page where he types the brand's description).
     */
    public const BRAND_KEYS = ['header_image', 'header_title', 'header_subtitle', 'header_description', 'logo', 'layout'];

    /** Keys that ride along with every write and are not fields. */
    private const ENVELOPE = ['_token', 'path'];

    /** Lane QC's recommendation, from the category editor's own note. */
    public const RECOMMENDED = '2400 × 600 px';

    /** MediaUploadController::MAX_BYTES -- 5 MB. Repeated, and pinned by a test. */
    public const UPLOAD_MAX_BYTES = 5 * 1024 * 1024;

    public const UPLOAD_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /** Per admin, per minute. See limit(). */
    public const LIMITS = ['context' => 120, 'preview' => 90, 'save' => 20];

    /**
     * GET /admin-api/storefront/context?path=/collections/skincare/
     *
     * 401 for anybody not signed in (auth:admin answers that before this runs).
     * 403 for an account that holds neither storefront capability. Otherwise
     * the bar (with storefront.adminbar) and the page's editable fields (with
     * storefront.quick_edit), each allowlisted field by field.
     */
    public function context(Request $request): JsonResponse
    {
        $admin = Auth::guard('admin')->user();

        if (! $admin instanceof AdminUser) {
            return response()->json(['ok' => false, 'error' => 'unauthenticated'], 401);
        }

        if ($limited = $this->limit('context', $admin)) {
            return $limited;
        }

        $bar = StorefrontAdminHint::can($admin, 'storefront.adminbar');
        $edit = StorefrontAdminHint::can($admin, 'storefront.quick_edit');
        // Pages → Page header's "Edit header" panel (Lane PH): owner, manager
        // and editor, so a manager or editor gets the panel without the bar.
        $header = StorefrontAdminHint::can($admin, 'pageheader.manage');

        if (! $bar && ! $edit && ! $header) {
            return response()->json([
                'ok' => false,
                'error' => 'forbidden',
                'message' => 'This account cannot use the storefront tools.',
            ], 403)->withCookie(StorefrontAdminHint::forget());
        }

        $page = $this->resolvePage((string) $request->query('path', '/'));

        return response()->json([
            'ok' => true,
            'csrf' => csrf_token(),
            'admin' => [
                'name' => $this->displayName($admin),
                'initials' => $this->initials($this->displayName($admin)),
            ],
            'bar' => $bar ? $this->bar($admin, $page) : null,
            'edit' => $edit ? $this->editable($page) : null,
            'pageheader' => $header ? $this->headerEditable($page) : null,
        ])->withCookie(StorefrontAdminHint::refresh($request));
    }

    /**
     * POST /admin-api/storefront/quick-edit/{type}/{id}/preview
     *
     * The same validation as the save, applied to an UNSAVED copy, and the
     * header the shop would draw from it -- through the same Blade component
     * the page uses, so what the owner sees in the pop-up is the shop.
     */
    public function preview(Request $request, string $type, string $id): JsonResponse
    {
        if ($limited = $this->limit('preview', Auth::guard('admin')->user())) {
            return $limited;
        }

        [$model, $isBrand] = $this->record($type, (int) $id);
        $this->useLocaleOf($request);

        $data = $this->validatedFor($request, $model, $isBrand);
        $copy = clone $model;
        $this->apply($copy, $data, $isBrand);

        return response()->json(['ok' => true] + $this->rendered($copy, $isBrand));
    }

    /**
     * POST /admin-api/storefront/quick-edit/{type}/{id}
     *
     * Validate, write, clear the caches that list categories and brands, and
     * hand back the header exactly as the page will now draw it, so the loader
     * can swap it in place without a reload.
     */
    public function save(Request $request, string $type, string $id): JsonResponse
    {
        if ($limited = $this->limit('save', Auth::guard('admin')->user())) {
            return $limited;
        }

        [$model, $isBrand] = $this->record($type, (int) $id);
        $this->useLocaleOf($request);

        $data = $this->validatedFor($request, $model, $isBrand);
        $this->apply($model, $data, $isBrand);
        $model->save();

        // The sidebar and home tiles cache category and brand rows; the header
        // itself is read fresh on every request, so this is belt and braces.
        ShopController::flushSidebarCache();
        Cache::forget('kbb.home.cats');
        Cache::forget('kbb.home.rails');

        $fresh = $model->fresh();

        return response()->json([
            'ok' => true,
            'message' => 'Saved',
            'fields' => $this->fields($fresh, $isBrand),
        ] + $this->rendered($fresh, $isBrand));
    }

    // ------------------------------------------------------------------ pages

    /**
     * Which storefront page is this path, and what record does it show?
     *
     * @return array{kind:string, model:?Model, locale:string}
     */
    private function resolvePage(string $path): array
    {
        $none = ['kind' => 'other', 'model' => null, 'locale' => Locale::DEFAULT];

        $path = '/' . ltrim(trim($path), '/');

        if (strlen($path) > 600 || str_contains($path, "\0")) {
            return $none;
        }

        $base = Url::base();

        if ($base !== '' && str_starts_with($path, $base . '/')) {
            $path = substr($path, strlen($base));
        }

        [$locale, $rest] = Locale::splitPath($path);
        $locale ??= Locale::DEFAULT;
        $none['locale'] = $locale;

        try {
            $route = app('router')->getRoutes()->match(Request::create($rest === '' ? '/' : $rest, 'GET'));
        } catch (\Throwable) {
            return $none;
        }

        $action = (string) $route->getActionName();
        $param = static fn (string $name): string => (string) ($route->parameter($name) ?? '');

        return match (true) {
            str_ends_with($action, 'Store\HomeController') => ['kind' => 'home', 'model' => null, 'locale' => $locale],
            str_ends_with($action, 'Store\CategoryArchiveController@collection') => [
                'kind' => 'category',
                'model' => ($v = CategoryPath::resolve($param('path')))['status'] === 'ok' ? $v['category'] : null,
                'locale' => $locale,
            ],
            str_ends_with($action, 'Store\BrandController@show') => [
                'kind' => 'brand',
                'model' => Brand::query()->where('slug', $param('slug'))->first(),
                'locale' => $locale,
            ],
            // The custom pages Pages → Page header covers (Lane PH).
            str_ends_with($action, 'Store\CollectionController@show') => [
                'kind' => 'collection', 'model' => null, 'locale' => $locale, 'key' => 'collection:' . $param('key'),
            ],
            str_ends_with($action, 'Store\PageController@show') => [
                'kind' => 'custompage', 'model' => null, 'locale' => $locale, 'key' => 'page:' . $param('slug'),
            ],
            str_ends_with($action, 'Store\ProductController@show') => [
                'kind' => 'product',
                'model' => Product::query()->select('id', 'slug', 'name')->where('slug', $param('slug'))->first(),
                'locale' => $locale,
            ],
            default => $none,
        };
    }

    // -------------------------------------------------------------------- bar

    /** @param array{kind:string, model:?Model, locale:string} $page */
    private function bar(AdminUser $admin, array $page): array
    {
        $console = $this->consoleUrl();

        $links = [
            ['key' => 'dash', 'label' => 'Dashboard', 'href' => $console . '#dash'],
            [
                'key' => 'orders',
                'label' => 'Orders',
                'href' => $console . '#orders',
                'badge' => $this->can($admin, 'orders.view') ? $this->ordersToday() : null,
            ],
            ['key' => 'products', 'label' => 'Products', 'href' => $console . '#catalog/products'],
            ['key' => 'customers', 'label' => 'Customers', 'href' => $console . '#customers'],
            ['key' => 'appearance', 'label' => 'Appearance', 'href' => $console . '#sitelayout'],
        ];

        $model = $page['model'];

        $context = match ($page['kind']) {
            'category' => $model ? [
                'label' => 'Edit category',
                'href' => $console . '?kbb-open=category:' . (int) $model->getKey() . '#catalog/categories',
            ] : null,
            'brand' => $model ? [
                'label' => 'Edit brand',
                'href' => $console . '?kbb-open=brand:' . (int) $model->getKey() . '#catalog/brands',
            ] : null,
            'product' => $model ? [
                'label' => 'Edit product',
                'href' => $console . '?kbb-open=product:' . (int) $model->getKey() . '#catalog/products',
            ] : null,
            'home' => ['label' => 'Homepage', 'href' => $console . '#homepage'],
            'collection', 'custompage' => ['label' => 'Page header', 'href' => $console . '#pageheader'],
            default => null,
        };

        return [
            'title' => 'K-Beauty Bliss · Admin',
            'console' => $console,
            'links' => $links,
            'context' => $context,
            'logout' => $this->pathOf(route('admin.logout')),
            // Platform -> Cache's own endpoint, and only for an account that
            // may already press that button there.
            'clear_cache' => $this->can($admin, 'cache.manage') ? Url::to('/admin-api/cache/clear') : null,
        ];
    }

    /**
     * Orders placed today that are real orders -- one count, on the
     * (status, created_at) index, and only ever for a signed-in admin.
     */
    private function ordersToday(): int
    {
        try {
            return (int) Order::query()
                ->whereIn('status', Order::REAL_STATUSES)
                ->where('created_at', '>=', now()->startOfDay())
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    // ------------------------------------------------------------------- edit

    /** @param array{kind:string, model:?Model, locale:string} $page */
    private function editable(array $page): ?array
    {
        $model = $page['model'];

        if (! in_array($page['kind'], ['category', 'brand'], true) || $model === null) {
            return null;
        }

        $isBrand = $page['kind'] === 'brand';
        $console = $this->consoleUrl();
        $id = (int) $model->getKey();

        return [
            'type' => $page['kind'],
            'id' => $id,
            'name' => (string) $model->getAttribute('name'),
            'mode' => $this->mode($model, $isBrand),
            'fields' => $this->fields($model, $isBrand),
            'placeholders' => [
                'title' => (string) $model->getAttribute('name'),
                'description' => $this->descriptionHint($model),
            ],
            'keys' => $isBrand ? self::BRAND_KEYS : self::CATEGORY_KEYS,
            'endpoints' => [
                'save' => Url::to('/admin-api/storefront/quick-edit/' . $page['kind'] . '/' . $id),
                'preview' => Url::to('/admin-api/storefront/quick-edit/' . $page['kind'] . '/' . $id . '/preview'),
                'upload' => Url::to('/admin-api/media/upload'),
            ],
            'upload' => [
                'folder' => $isBrand ? 'brands' : 'categories',
                'max_bytes' => self::UPLOAD_MAX_BYTES,
                'types' => self::UPLOAD_TYPES,
                'recommended' => self::RECOMMENDED,
            ],
            'css' => $this->stylesheets(),
            'more' => [
                [
                    'label' => $isBrand ? 'Catalog → Brands → Edit' : 'Catalog → Categories → Edit',
                    'href' => $console . '?kbb-open=' . $page['kind'] . ':' . $id . '#catalog/' . ($isBrand ? 'brands' : 'categories'),
                ],
                ['label' => $isBrand ? 'Appearance → Site layout → Brand page' : 'Appearance → Site layout → Category header', 'href' => $console . '#sitelayout'],
            ],
        ]
        // Lane BR2: the Panel header's controls, brands only. `shop` is what
        // "Shop" means for each, so the bars start where the page is.
        + ($isBrand ? ['panel' => $this->panelContext($model)] : []);
    }

    /**
     * The "Edit header" panel's data for a custom page, or null on any other.
     * (Lane PH) Only a key Pages → Page header knows is offered: a concern
     * listing renders the same view but is not a custom page.
     *
     * @param  array{kind:string, model:?Model, locale:string, key?:string}  $page
     */
    private function headerEditable(array $page): ?array
    {
        if (! in_array($page['kind'], ['collection', 'custompage'], true)) {
            return null;
        }

        $key = (string) ($page['key'] ?? '');
        $known = \App\Services\PageBanners::pageKeys();

        if (! isset($known[$key])) {
            return null;
        }

        $label = $known[$key][0];
        if ($page['kind'] === 'custompage') {
            $title = (string) \App\Models\Page::query()->where('slug', substr($key, 5))->value('title');
            $title = trim(html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $label = $title !== '' ? $title : $label;
        }

        return PageHeaderApiController::editorContext($key, $page['kind'] === 'collection' ? 'collection' : 'page', $label);
    }

    /**
     * Which of the two headers this page draws. A page banner the owner has
     * switched on wins over the title header (TitleHeader::forModel() returns
     * null when there is one), so the pencil edits the banner's picture, heading
     * and line in that case -- the thing he is looking at.
     */
    private function mode(Model $model, bool $isBrand): string
    {
        return PageBanner::forModel($model, (string) $model->getAttribute('name')) !== null ? 'banner' : 'header';
    }

    /** The current values, as the pop-up's boxes show them. */
    private function fields(Model $model, bool $isBrand): array
    {
        if ($this->mode($model, $isBrand) === 'banner') {
            $banner = $this->bannerRaw($model);

            return [
                'header_image' => (string) ($banner['image'] ?? ''),
                'header_title' => (string) ($banner['heading'] ?? ''),
                'header_subtitle' => (string) ($banner['subheading'] ?? ''),
                'header_description' => (string) ($model->getAttribute('header_description') ?? ''),
                'focus' => '',
            ] + ($isBrand ? ['logo' => (string) ($model->getAttribute('logo') ?? ''), 'layout' => (object) BrandPanel::sanitize($model->getAttribute('header_layout'))] : []);
        }

        $style = $isBrand ? [] : TitleHeader::sanitizeStyle($model->getAttribute('header_style'));

        return [
            'header_image' => (string) ($model->getAttribute('header_image') ?? ''),
            'header_title' => (string) ($model->getAttribute('header_title') ?? ''),
            'header_subtitle' => (string) ($model->getAttribute('header_subtitle') ?? ''),
            'header_description' => (string) ($model->getAttribute('header_description') ?? ''),
            'focus' => (string) ($style['focus'] ?? ''),
        ] + ($isBrand ? ['logo' => (string) ($model->getAttribute('logo') ?? ''), 'layout' => (object) BrandPanel::sanitize($model->getAttribute('header_layout'))] : []);
    }

    private function descriptionHint(Model $model): string
    {
        $own = $model->getAttribute('description');
        $text = is_string($own) && ! RichText::isBlank($own) ? trim(RichText::toText(RichText::forDisplay($own))) : '';

        return mb_substr($text, 0, 200);
    }

    // ------------------------------------------------------------------ write

    /** @return array{0: Model, 1: bool} */
    private function record(string $type, int $id): array
    {
        return match ($type) {
            'category' => [Category::query()->findOrFail($id), false],
            'brand' => [Brand::query()->findOrFail($id), true],
            default => abort(404),
        };
    }

    /**
     * The one validator, plus the refusal of anything it does not own.
     *
     * @return array<string, mixed>
     */
    private function validatedFor(Request $request, Model $model, bool $isBrand): array
    {
        $allowed = $isBrand ? self::BRAND_KEYS : self::CATEGORY_KEYS;
        $input = $request->except(self::ENVELOPE);

        $unknown = array_values(array_diff(array_keys($input), $allowed));

        if ($unknown !== []) {
            throw ValidationException::withMessages([
                $unknown[0] => 'The quick editor cannot change "' . mb_substr((string) $unknown[0], 0, 40) . '". Use the full editor for that.',
            ]);
        }

        $rules = $isBrand ? TitleHeaderInput::brandRules() : array_intersect_key(
            TitleHeaderInput::rules(),
            array_flip(['header_image', 'header_title', 'header_subtitle', 'header_description'])
        );

        // The phone crop is the one style choice the pencil offers. It is
        // validated by the SAME rule, in the shape the full editor sends it.
        $shaped = $input;

        if (! $isBrand && array_key_exists('focus', $shaped)) {
            $shaped['header_style'] = ['focus' => $shaped['focus']];
            unset($shaped['focus']);
            $rules['header_style'] = TitleHeaderInput::rules()['header_style'];
            $rules['header_style.focus'] = TitleHeaderInput::rules()['header_style.focus'];
        }

        if ($isBrand) {
            $rules['logo'] = ['nullable', 'string', 'max:2048'];
            // Lane BR2: the Panel header's own choices. Each is one of its
            // options or a whole number inside its range; blank follows the shop.
            $rules['layout'] = ['nullable', 'array'];

            foreach (BrandPanel::CHOICES as $key => [, $allowed]) {
                $rules['layout.' . $key] = ['nullable', 'string', Rule::in($allowed)];
            }

            foreach (BrandPanel::RANGES as $key => [, $min, $max]) {
                $rules['layout.' . $key] = ['nullable', 'integer', 'between:' . $min . ',' . $max];
            }
        }

        $data = Validator::make($shaped, $rules)->validate();

        // The logo: Catalog → Brands' own check, so the two editors refuse the
        // same addresses (`data:`, `javascript:`, a protocol-relative host).
        if ($isBrand && array_key_exists('logo', $data)) {
            $data['logo'] = BrandLogo::safeUrl($data['logo']);
        }

        if (array_key_exists('header_style', $data)) {
            /*
             * MERGED, NOT REPLACED. The pencil sends only the crop; the
             * category's alignment, treatment, box and colours from the full
             * editor are kept exactly as they are. Sending {focus} alone through
             * clean() as the whole style would have wiped every one of them.
             */
            $current = TitleHeader::sanitizeStyle($model->getAttribute('header_style'));
            $focus = $data['header_style']['focus'] ?? null;
            unset($current['focus']);

            if (is_string($focus) && $focus !== '') {
                $current['focus'] = $focus;
            }

            $data['header_style'] = $current;
        }

        /*
         * Read off the INPUT, which the rules above have just checked key by
         * key: the validator's own answer leaves out an empty `layout` (the
         * "use the shop settings" reset) and every key it has no rule for.
         */
        if ($isBrand && array_key_exists('layout', $input)) {
            $data['layout'] = is_array($input['layout']) ? $input['layout'] : [];
            $unknown = array_diff(array_keys($data['layout']), array_keys(BrandPanel::CHOICES + BrandPanel::RANGES));

            if ($unknown !== []) {
                throw ValidationException::withMessages(['layout' => 'The header layout has no setting called "' . mb_substr((string) reset($unknown), 0, 40) . '".']);
            }

            if (! BrandPanel::columnReady()) {
                throw ValidationException::withMessages(['layout' => 'The header layout needs this update\'s database step. Run the update again from Store → Core Updates.']);
            }

            $clean = BrandPanel::sanitize($data['layout']);
            unset($data['layout']);
            $data['header_layout'] = $clean === [] ? null : $clean;
        }

        return TitleHeaderInput::clean($data);
    }

    /** Put validated values on a model, in the column the page reads them from. */
    private function apply(Model $model, array $data, bool $isBrand): void
    {
        if ($this->mode($model, $isBrand) === 'banner') {
            $banner = $this->bannerRaw($model);

            foreach (['header_image' => 'image', 'header_title' => 'heading', 'header_subtitle' => 'subheading'] as $from => $to) {
                if (array_key_exists($from, $data)) {
                    $banner[$to] = $data[$from];
                    unset($data[$from]);
                }
            }

            // Through the banner's own definition, the one the full editor
            // and the storefront both read through.
            $model->setAttribute('banner', PageBanner::sanitize($banner));
        }

        foreach ($data as $key => $value) {
            $model->setAttribute($key, $value);
        }

        // A new logo is a new colour for its ring, read from the file here
        // (never fetched) -- for the preview's unsaved copy as well, so the
        // pop-up shows the ring the page will get.
        if ($isBrand && array_key_exists('logo', $data) && BrandLogo::columnsReady()) {
            $model->setAttribute('logo_color', BrandLogo::colourOf($data['logo']));
        }
    }

    private function bannerRaw(Model $model): array
    {
        $raw = $model->getAttribute('banner');

        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        return is_array($raw) ? $raw : [];
    }

    // ----------------------------------------------------------------- render

    /**
     * The header as the page draws it: the same component, the same inputs as
     * ShopController (category) and BrandController::show (brand).
     *
     * @return array{html:string, kind:string, note:string}
     */
    private function rendered(Model $model, bool $isBrand): array
    {
        return $this->header($model, $isBrand) + ($isBrand && $model instanceof Brand ? ['hero' => $this->brandHero($model)] : []);
    }

    /**
     * The brand page's logo circle and description, as the page draws them
     * (Lane BH), so the pop-up can preview the ring and Save can put both on
     * the page without a reload. The same partial and the same inputs as
     * Store\BrandController::show().
     *
     * @return array{logo:string, desc:string}
     */
    private function brandHero(Brand $brand): array
    {
        $layout = app(\App\Services\SiteLayout::class);

        return [
            'logo' => trim(view('store.partials.brand-logo', [
                'brand' => $brand,
                'ring' => (bool) $layout->get('brand_ring'),
                'ringHex' => BrandLogo::ring($brand),
            ])->render()),
            'desc' => TitleHeader::brandDescription($brand),
        ];
    }

    /** @return array{html:string, kind:string, note:string} */
    private function header(Model $model, bool $isBrand): array
    {
        $title = (string) (method_exists($model, 't') ? $model->t('name') : $model->getAttribute('name'));
        $banner = PageBanner::forModel($model, $title);

        if ($banner !== null) {
            return [
                'kind' => 'banner',
                'html' => trim(Blade::render('<x-kbb-banner :banner="$banner" :contained="$contained" />', [
                    'banner' => $banner,
                    'contained' => ! $isBrand,
                ])),
                'note' => '',
            ];
        }

        if ($isBrand && $model instanceof Brand
            && \App\Http\Controllers\Store\BrandController::hero(app(\App\Services\SiteLayout::class)->get('brand_hero'), null) === 'panel') {
            return ['kind' => 'panel', 'html' => $this->panelHtml($model), 'note' => ''];
        }

        $header = TitleHeader::forModel($model, $title, null, $isBrand);

        if ($header === null) {
            return [
                'kind' => 'none',
                'html' => '',
                'note' => $isBrand
                    ? 'A brand page draws its header once it has a picture. Drop one above -- or turn on "Light box on brand pages" in Appearance → Site layout → Category header.'
                    : 'The category header is switched off in Appearance → Site layout → Category header, so nothing is drawn here. Your words are saved and show when it is on.',
            ];
        }

        return [
            'kind' => 'header',
            'html' => trim(Blade::render('<x-kbb-title-header :header="$header" :contained="$contained" />', [
                'header' => $header,
                'contained' => ! $isBrand,
            ])),
            'note' => '',
        ];
    }

    /**
     * The Panel header as Store\BrandController::show() draws it: the same
     * partial, the same inputs. (Lane BR2)
     */
    private function panelHtml(Brand $brand): string
    {
        $layout = app(\App\Services\SiteLayout::class);
        $all = $layout->all();

        return trim(view('store.partials.brand-panel', [
            'brand' => $brand,
            'panel' => BrandPanel::forBrand($brand, $all),
            'ring' => (bool) $all['brand_ring'],
            'ringHex' => BrandLogo::ring($brand),
            'cta' => (bool) $all['brand_cta'],
        ])->render());
    }

    /**
     * The pop-up's Panel controls (Lane BR2): whether this brand's page draws
     * the Panel header, the shop's value for each choice, and each bar's range.
     *
     * @return array<string, mixed>
     */
    private function panelContext(Model $model): array
    {
        $all = app(\App\Services\SiteLayout::class)->all();
        $ranges = [];

        foreach (BrandPanel::RANGES as $key => [, $min, $max, , $unit]) {
            $ranges[$key] = ['min' => $min, 'max' => $max, 'unit' => $unit];
        }

        return [
            'on' => \App\Http\Controllers\Store\BrandController::hero($all['brand_hero'] ?? null, PageBanner::forModel($model, (string) $model->getAttribute('name'))) === 'panel',
            'shop' => BrandPanel::shop($all),
            'ranges' => $ranges,
            // The admin path in words, sent rather than written into the
            // script, where DirectionalGlyphsTest keeps every arrow out.
            'hint' => 'For this brand only. "Shop" follows Appearance → Site layout → Brand page.',
        ];
    }

    /** The two stylesheets a header needs, for a page that did not load them. */
    private function stylesheets(): array
    {
        $out = [];

        foreach (['header' => 'resources/css/kbb/kbb-title-header.css', 'banner' => 'resources/css/kbb/kbb-banner.css', 'panel' => 'resources/css/kbb/kbb-brand-header.css'] as $key => $entry) {
            try {
                $out[$key] = $this->pathOf(Vite::asset($entry));
            } catch (\Throwable) {
                $out[$key] = null;
            }
        }

        return $out;
    }

    /** Render in the language of the page the owner is on. */
    private function useLocaleOf(Request $request): void
    {
        $path = '/' . ltrim((string) $request->input('path', '/'), '/');
        $base = Url::base();

        if ($base !== '' && str_starts_with($path, $base . '/')) {
            $path = substr($path, strlen($base));
        }

        [$locale] = Locale::splitPath($path);

        // splitPath() answers null for an unprefixed (English) path.
        app()->setLocale(is_string($locale) && Locale::isSupported($locale) && Locale::enabled($locale)
            ? $locale
            : Locale::DEFAULT);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * A rate limit keyed on THIS admin and THIS action.
     *
     * Not `throttle:` middleware: that keys every throttled route on the same
     * domain|ip signature when the default guard has no user -- and the admin
     * guard is not the default -- so the context reads and the previews spent
     * the save's twenty. Measured in the preview: the owner typed for a minute
     * and Save answered "Too Many Attempts".
     */
    private function limit(string $action, mixed $admin): ?JsonResponse
    {
        $who = $admin instanceof AdminUser ? 'a' . $admin->getKey() : 'ip' . request()->ip();
        $key = 'kbb-storefront-admin:' . $action . ':' . $who;

        if (RateLimiter::tooManyAttempts($key, self::LIMITS[$action])) {
            return response()->json([
                'ok' => false,
                'error' => 'rate_limited',
                'message' => $action === 'save'
                    ? 'Too many saves in a minute. Wait a moment and press Save again.'
                    : 'Too many requests in a minute. Wait a moment.',
            ], 429);
        }

        RateLimiter::hit($key, 60);

        return null;
    }

    private function consoleUrl(): string
    {
        return $this->pathOf(route('admin'));
    }

    /** Same-origin path only: never hand the script an absolute URL to follow. */
    private function pathOf(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '/');

        return $path === '' ? '/' : $path;
    }

    private function can(AdminUser $admin, string $capability): bool
    {
        return StorefrontAdminHint::can($admin, $capability);
    }

    private function displayName(AdminUser $admin): string
    {
        $name = trim((string) $admin->name);

        if ($name === '') {
            $name = (string) strtok((string) $admin->email, '@');
        }

        return mb_substr($name, 0, 60);
    }

    private function initials(string $name): string
    {
        $out = '';

        foreach (preg_split('/\s+/u', trim($name)) ?: [] as $word) {
            if ($word !== '') {
                $out .= mb_strtoupper(mb_substr($word, 0, 1));
            }

            if (mb_strlen($out) >= 2) {
                break;
            }
        }

        return $out === '' ? 'A' : $out;
    }
}
