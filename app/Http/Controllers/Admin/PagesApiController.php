<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Services\AdminPathService;
use App\Support\Url;
use Illuminate\Http\JsonResponse;

/**
 * Splits Pages into the two kinds that behave completely differently.
 *
 * STORE pages are fixed routes the application owns — shop, cart, checkout,
 * account. They cannot be created or deleted, only inspected. Listing them
 * alongside editable content is what leads someone to try deleting /checkout.
 *
 * USER pages are rows in the pages table: created, edited and removed freely.
 */
class PagesApiController extends Controller
{
    /**
     * The routes the storefront owns. Declared, not discovered — route
     * introspection would also return every API endpoint and admin screen.
     */
    private const STORE_PAGES = [
        ['key' => 'home',      'name' => 'Home',            'path' => '/',              'note' => 'Hero, rails, brands, routine builder'],
        ['key' => 'shop',      'name' => 'Shop',            'path' => '/shop/',         'note' => 'Server-side filters, indexable URLs'],
        ['key' => 'product',   'name' => 'Product',         'path' => '/product/{slug}/','note' => 'Gallery, variants, reviews'],
        ['key' => 'category',  'name' => 'Category',        'path' => '/product-category/{path}/', 'note' => 'Nested to four levels'],
        ['key' => 'cart',      'name' => 'Cart',            'path' => '/cart/',         'note' => 'Page and mini-cart drawer'],
        ['key' => 'checkout',  'name' => 'Checkout',        'path' => '/checkout/',     'note' => 'Contact, delivery, payment'],
        ['key' => 'account',   'name' => 'My Account',      'path' => '/my-account/',   'note' => 'Orders, addresses, details'],
        ['key' => 'quiz',      'name' => 'Skin Quiz',       'path' => '/skin-quiz/',    'note' => 'Funnel and lead capture'],
        ['key' => 'reviews',   'name' => 'Review Wall',     'path' => '/reviews/',      'note' => 'All approved reviews'],
        ['key' => 'blog',      'name' => 'Journal',         'path' => '/skincare-guide/','note' => 'Blog index'],
    ];

    public function store(): JsonResponse
    {
        $base = Url::base();

        return response()->json([
            'pages' => array_map(static fn ($p) => $p + [
                'url' => $base . $p['path'],
                'system' => true,
            ], self::STORE_PAGES),
        ]);
    }

    public function user(): JsonResponse
    {
        return response()->json([
            'pages' => Page::query()
                ->select('id', 'slug', 'title', 'status', 'updated_at')
                ->orderBy('title')
                ->get()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->title,
                    'path' => '/' . $p->slug . '/',
                    'url' => Url::to('/' . $p->slug . '/'),
                    'status' => $p->status,
                    'updated' => $p->updated_at?->diffForHumans(),
                    'system' => false,
                ]),
            'admin_path' => AdminPathService::current(),
        ]);
    }
}
