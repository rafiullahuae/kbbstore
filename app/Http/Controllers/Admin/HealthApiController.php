<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\Url;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Storefront page health check.
 *
 * Renders every public page inside this process and reports the status plus,
 * on failure, the exception message with the app file and line.
 *
 * This exists because "the product page is 500ing" has cost several rounds of
 * screenshots and log-reading. Now the answer is one click, before a customer
 * ever sees it. Run it after every update.
 */
class HealthApiController extends Controller
{
    public function run(Request $request): JsonResponse
    {
        $product = Product::query()->visible()->value('slug');
        $category = \App\Models\Category::query()->value('slug');

        $targets = array_filter([
            'Home' => '/',
            'Shop' => '/shop/',
            'Product' => $product ? "/product/{$product}/" : null,
            'Category' => $category ? "/product-category/{$category}/" : null,
            'Cart' => '/cart/',
            'Checkout' => '/checkout/',
            'Journal' => '/skincare-guide/',
            'Reviews' => '/reviews/',
        ]);

        $results = [];

        foreach ($targets as $label => $path) {
            $results[] = $this->probe($label, $path, $request);
        }

        $failed = count(array_filter($results, fn ($r) => ! $r['ok']));

        return response()->json([
            'checked' => count($results),
            'failed' => $failed,
            'results' => $results,
        ]);
    }

    /**
     * Dispatch the route through the kernel with the current cookies, so the
     * page is rendered exactly as a visitor would get it — same session, same
     * cart, same settings.
     */
    private function probe(string $label, string $path, Request $original): array
    {
        $url = Url::to($path);

        try {
            $sub = Request::create($url, 'GET');
            $sub->headers->replace($original->headers->all());
            $sub->cookies->replace($original->cookies->all());

            $response = app()->handle($sub);
            $status = $response->getStatusCode();

            return [
                'label' => $label,
                'path' => $path,
                'status' => $status,
                'ok' => $status < 400,
                'error' => $status < 400 ? null : 'HTTP ' . $status,
                'where' => null,
            ];
        } catch (Throwable $e) {
            // The first application frame is the one that matters; the rest is
            // framework plumbing.
            $where = null;

            foreach ($e->getTrace() as $frame) {
                $file = $frame['file'] ?? '';
                if ($file && ! str_contains($file, '/vendor/')) {
                    $where = str_replace(base_path() . '/', '', $file) . ':' . ($frame['line'] ?? '?');
                    break;
                }
            }

            $where ??= str_replace(base_path() . '/', '', $e->getFile()) . ':' . $e->getLine();

            return [
                'label' => $label,
                'path' => $path,
                'status' => 500,
                'ok' => false,
                'error' => $e->getMessage(),
                'where' => $where,
            ];
        }
    }
}
