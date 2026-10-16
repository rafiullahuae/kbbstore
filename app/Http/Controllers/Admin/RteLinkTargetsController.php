<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\UrlScheme;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GET /admin-api/media/link-targets — what a gallery picture can link to.
 *
 * The owner, 16 October 2026: "each image should have a separate custom link
 * ... and the alt text ... when i enter the link, the alt text should be copied
 * from the linked product/page title." The picture panel
 * (admin/partials/rte-media) asks for this ONCE per admin visit and filters in
 * the browser as the owner types: no request per keystroke.
 *
 * Four queries, each selecting only name + slug (+ the category's path), live
 * rows only, so the cost is flat in the number of screens and linear only in
 * the catalogue's size, which is the answer itself. Under admin-api/media/**,
 * so EnforceAdminCapability asks content.manage of it, like the Media Library.
 * Answers [type, name, url] triples -- nothing else about any row leaves.
 */
final class RteLinkTargetsController extends Controller
{
    public function index(): JsonResponse
    {
        $out = [];

        if (Schema::hasTable('products')) {
            foreach (DB::table('products')->where('status', 'publish')->where('is_visible', true)
                ->orderBy('name')->get(['name', 'slug']) as $p) {
                if ($p->slug !== null && $p->slug !== '') {
                    $out[] = ['product', (string) $p->name, '/product/' . $p->slug . '/'];
                }
            }
        }

        if (Schema::hasTable('categories')) {
            foreach (DB::table('categories')->orderBy('name')->get(['name', 'slug', 'path']) as $c) {
                $path = (string) ($c->path ?: '');
                if ($path !== '') {
                    $out[] = ['category', (string) $c->name, UrlScheme::collection($path)];
                }
            }
        }

        if (Schema::hasTable('brands')) {
            foreach (DB::table('brands')->orderBy('name')->get(['name', 'slug']) as $b) {
                if ($b->slug !== null && $b->slug !== '') {
                    $out[] = ['brand', (string) $b->name, UrlScheme::brand((string) $b->slug)];
                }
            }
        }

        if (Schema::hasTable('posts')) {
            foreach (DB::table('posts')->where('status', 'published')->orderByDesc('published_at')->get(['title', 'slug']) as $p) {
                if ($p->slug !== null && $p->slug !== '') {
                    $out[] = ['article', (string) $p->title, '/blog/' . $p->slug . '/'];
                }
            }
        }

        return response()->json(['items' => $out]);
    }
}
