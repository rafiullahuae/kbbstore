<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Services\SettingsService;

/**
 * Editable content pages: privacy policy, terms, delivery and so on.
 *
 * Content lives in the pages table rather than in templates, so it can be
 * changed in the admin without a release.
 */
class PageController extends Controller
{
    public function __construct(private SettingsService $settings) {}

    public function show(string $slug)
    {
        $page = Page::query()
            ->where('slug', $slug)
            ->where('status', 'published')
            ->firstOrFail();

        return view('store.page', [
            'page' => $page,
            'settings' => $this->settings,
        ]);
    }
}
