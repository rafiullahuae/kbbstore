<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PageBanners;
use App\Services\PageHeaders;
use App\Support\Url;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pages → Page header, and the storefront's "Edit header" panel. (Lane PH)
 *
 * routes/page-header-admin.php mounts the three endpoints inside the guarded
 * admin-api group; every one is `pageheader.manage`. Everything stored goes
 * through PageHeaders::sanitize(), which refuses rather than coerces: a select
 * outside its options, a number outside its range, an order that is not the
 * five elements, a picture address SafeUrl will not print — each is named back
 * to the screen and nothing is written.
 */
class PageHeaderApiController extends Controller
{
    /** Element labels, for the position list. */
    public const ELEMENT_LABELS = [
        'crumb' => 'Breadcrumb',
        'image' => 'Picture',
        'title' => 'Title and count',
        'intro' => 'Intro line',
        'button' => '“All products” button',
    ];

    public function __construct(private PageHeaders $headers) {}

    /** GET /admin-api/page-header */
    public function show(): JsonResponse
    {
        return response()->json($this->payload());
    }

    /** POST /admin-api/page-header — the whole setting, all or nothing. */
    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'global' => ['present', 'array'],
            'pages' => ['present', 'array'],
        ]);

        $rejected = $this->headers->save(['global' => $data['global'], 'pages' => $data['pages']]);

        if ($rejected !== []) {
            return $this->refused($rejected);
        }

        return response()->json(['ok' => true] + $this->payload());
    }

    /** POST /admin-api/page-header/apply — one page, from the storefront panel. */
    public function apply(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:80'],
            'scope' => ['required', 'string', 'in:page,global,inherit'],
            'bag' => ['nullable', 'array'],
        ]);

        $rejected = $this->headers->apply($data['key'], $data['scope'], $data['bag'] ?? null);

        if ($rejected !== []) {
            return $this->refused($rejected);
        }

        $this->headers->forget();
        $all = $this->headers->all();

        return response()->json([
            'ok' => true,
            'message' => match ($data['scope']) {
                'page' => 'Saved for this page',
                'global' => 'Saved for every custom page',
                default => 'This page follows the global header again',
            },
            'own' => isset($all['pages'][$data['key']]),
            'bag' => $this->headers->bagFor($data['key']),
            'global' => $all['global'],
        ]);
    }

    /**
     * What the storefront panel needs for one page. Read by
     * StorefrontAdminController::context(), only for an admin who holds
     * pageheader.manage, and only on a custom page.
     */
    public static function editorContext(string $key, string $kind, string $label): array
    {
        $headers = app(PageHeaders::class);
        $all = $headers->all();

        return [
            'key' => $key,
            'kind' => $kind,
            'label' => $label,
            'own' => isset($all['pages'][$key]),
            'bag' => $headers->bagFor($key),
            'global' => $all['global'],
            'spec' => self::spec(),
            'endpoints' => [
                'apply' => Url::to('/admin-api/page-header/apply'),
                'media' => Url::to('/admin-api/media'),
                'upload' => Url::to('/admin-api/media/upload'),
            ],
            'upload' => [
                'folder' => 'appearance',
                'max_bytes' => StorefrontAdminController::UPLOAD_MAX_BYTES,
                'types' => StorefrontAdminController::UPLOAD_TYPES,
            ],
            'console' => (string) (parse_url(route('admin'), PHP_URL_PATH) ?: '/').'#pageheader',
        ];
    }

    /** The controls, described once for both the console and the storefront panel. */
    public static function spec(): array
    {
        $shows = [];
        foreach (PageHeaders::SHOWS as $k => [$label, $kinds]) {
            $shows[] = ['key' => $k, 'label' => $label, 'kinds' => $kinds];
        }
        $selects = [];
        foreach (PageHeaders::SELECTS as $k => [$options, $label]) {
            $opts = [];
            foreach ($options as $v => $l) {
                $opts[] = ['value' => $v, 'label' => $l];
            }
            $selects[] = ['key' => $k, 'label' => $label, 'options' => $opts];
        }
        $numbers = [];
        foreach (PageHeaders::NUMBERS as $k => [$min, $max, $d, $m, $label]) {
            $numbers[] = ['key' => $k, 'label' => $label, 'min' => $min, 'max' => $max, 'd' => $d, 'm' => $m];
        }
        $elements = [];
        foreach (PageHeaders::ELEMENTS as $k => $_) {
            $elements[] = ['key' => $k, 'label' => self::ELEMENT_LABELS[$k]];
        }

        return [
            'shows' => $shows,
            'selects' => $selects,
            'numbers' => $numbers,
            'elements' => $elements,
            'blank' => PageHeaders::blank(),
            'breakpoint' => PageHeaders::BREAKPOINT,
            'css' => PageHeaders::CSS,
            // Appearance → Header → Breadcrumbs, per device: a trail that
            // switch hides takes no row in the header either.
            'crumb' => PageHeaders::siteCrumb(),
        ];
    }

    /**
     * The panel's module, which the console imports to draw the same controls
     * the storefront panel draws. Null when the build has not been run; the
     * screen says so rather than half-working.
     */
    public static function editorModule(): ?string
    {
        try {
            $url = \Illuminate\Support\Facades\Vite::asset('resources/js/kbb/admin/page-header-editor.js');
        } catch (\Throwable) {
            return null;
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

        return $path !== '' && str_starts_with($path, '/') && ! str_starts_with($path, '//') ? $path : null;
    }

    private function refused(array $rejected): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error' => 'Not saved — check: '.implode(', ', array_unique(array_values($rejected))),
            'message' => 'Not saved — check: '.implode(', ', array_unique(array_values($rejected))),
            'rejected' => array_keys($rejected),
        ], 422);
    }

    private function payload(): array
    {
        $this->headers->forget();
        $all = $this->headers->all();

        // A content page by its own title, where it has one: one query.
        $keys = PageBanners::pageKeys();
        $titles = \App\Models\Page::query()
            ->whereIn('slug', array_map(static fn ($k) => substr($k, 5), array_filter(array_keys($keys), static fn ($k) => str_starts_with($k, 'page:'))))
            ->pluck('title', 'slug');

        $pages = [];
        foreach ($keys as $key => [$label, $path]) {
            $title = str_starts_with($key, 'page:') ? trim(html_entity_decode(strip_tags((string) ($titles[substr($key, 5)] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
            $pages[] = [
                'key' => $key,
                'kind' => str_starts_with($key, 'collection:') ? 'collection' : 'page',
                'label' => $title !== '' ? $title : $label,
                'path' => $path,
                'url' => Url::to($path),
            ];
        }

        return [
            'global' => $all['global'],
            'pages' => (object) $all['pages'],
            'list' => $pages,
            'spec' => self::spec(),
            'editor' => self::editorModule(),
        ];
    }
}
