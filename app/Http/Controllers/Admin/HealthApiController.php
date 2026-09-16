<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\CapturingExceptionHandler;
use App\Support\Url;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
 *
 * ---------------------------------------------------------------------------
 * LANE DH — WHAT THIS COSTS, AND WHY THE ROUTE IS THROTTLED
 * ---------------------------------------------------------------------------
 *
 * One call renders EIGHT complete pages inside one PHP worker, on top of the
 * admin request that asked for it. Measured on a 24-product SQLite demo
 * catalogue: 276ms for the first call in a process, 69ms for a second in the
 * same process (opcode cache and the settings memo are already warm), and
 * about 6MB of resident memory on top of the console's own. The live shop has
 * ~2,400 products on MySQL over a socket, so the real figure is larger and the
 * shape is the same: a single click is eight page renders' worth of work.
 *
 * That is affordable to press and not affordable to hold down, on a shared
 * host with a handful of PHP workers — a held Enter key is the whole worker
 * pool rendering the shop at itself. routes/health-admin.php therefore carries
 * `throttle:6,1`, and the console runs this ONLY on an explicit click; nothing
 * polls it and the dashboard does not run it on load.
 *
 * ---------------------------------------------------------------------------
 * WHAT A SUB-REQUEST DISTURBS, AND WHAT IS PUT BACK
 * ---------------------------------------------------------------------------
 *
 * Kernel::sendRequestThroughRouter() opens with `$this->app->instance('request',
 * $request)` and clears the resolved `request` facade. Nothing puts them back,
 * so before this note the container was left pointing at the LAST page probed:
 * after a health run, `request()` inside the admin request answered /reviews/.
 * restoreRequest() below returns both.
 *
 * The cookies are copied deliberately — a cart page checked without the
 * shopper's cart is not the page that breaks — but they make each probe resolve
 * the SAME session, and StartSession stores the URL it just served as the
 * session's previous URL. Left alone, one health run makes `back()` in the
 * admin mean "the review wall". The previous URL is captured and put back.
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

        // Captured before the first probe, restored after the last one.
        $originalRequest = app()->bound('request') ? app('request') : $request;
        $session = $request->hasSession() ? $request->session() : null;
        $previousUrl = $session?->previousUrl();

        try {
            foreach ($targets as $label => $path) {
                $results[] = $this->probe($label, $path, $request);
            }
        } finally {
            $this->restoreRequest($originalRequest);

            if ($session !== null) {
                // setPreviousUrl(null) is not the same as leaving it alone: it
                // would remove the key. Only write back what was there.
                if ($previousUrl !== null) {
                    $session->setPreviousUrl($previousUrl);
                }
            }
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

        $real = app(ExceptionHandler::class);
        $listener = new CapturingExceptionHandler($real);
        app()->instance(ExceptionHandler::class, $listener);

        try {
            $sub = Request::create($url, 'GET');
            $sub->headers->replace($original->headers->all());
            $sub->cookies->replace($original->cookies->all());

            $response = app()->handle($sub);
            $status = $response->getStatusCode();
        } catch (Throwable $e) {
            // Kernel::handle() catches everything it dispatches, so reaching
            // here takes a failure in the kernel itself rather than in a page.
            // Kept so that such a failure is reported rather than 500ing the
            // whole health screen.
            $status = 500;
            $listener->report($e);
            $response = null;
        } finally {
            app()->instance(ExceptionHandler::class, $real);
        }

        $thrown = $listener->captured();
        $ok = $status < 400 && $thrown === null;

        return [
            'label' => $label,
            'path' => $path,
            'status' => $status,
            'ok' => $ok,
            'error' => $ok ? null : ($thrown?->getMessage() ?: 'HTTP ' . $status),
            'where' => $thrown === null ? null : $this->where($thrown),
        ];
    }

    /**
     * Where in this application the page went wrong.
     *
     * THE THROW SITE FIRST, then the trace. The original of this method walked
     * the trace for the first non-vendor frame and never looked at the
     * exception's own file, which gets the answer right for a failure raised
     * inside the framework (a query exception, say — the useful frame is the
     * app code that ran the query) and wrong for everything raised in app code,
     * because a probe's trace runs back through THIS controller. A page that
     * threw from its own controller reported HealthApiController's line number:
     * an address in the tool rather than in the fault.
     *
     * This file is skipped either way. It is on the stack of every probe and is
     * never the answer.
     */
    private function where(Throwable $e): string
    {
        if ($this->isAppFile($e->getFile())) {
            return $this->relative($e->getFile()) . ':' . $e->getLine();
        }

        foreach ($e->getTrace() as $frame) {
            $file = $frame['file'] ?? '';

            if ($this->isAppFile($file)) {
                return $this->relative($file) . ':' . ($frame['line'] ?? '?');
            }
        }

        return $this->relative($e->getFile()) . ':' . $e->getLine();
    }

    private function isAppFile(string $file): bool
    {
        return $file !== ''
            && ! str_contains($file, '/vendor/')
            && $file !== __FILE__;
    }

    private function relative(string $file): string
    {
        return str_replace(base_path() . '/', '', $file);
    }

    /**
     * Put the container's `request` back where the admin request left it.
     *
     * Both halves are needed. The container binding is what `app('request')`
     * and every injected Request resolve through; the facade keeps its own
     * resolved instance, which Kernel clears on the way in and never restores.
     */
    private function restoreRequest(Request $original): void
    {
        app()->instance('request', $original);
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('request');
    }
}
