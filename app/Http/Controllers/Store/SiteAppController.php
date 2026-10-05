<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\SiteApp;
use App\Support\Url;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The shop's Home Screen app files (Lane PW): manifest, service worker,
 * registration script, icons and offline page. routes/site-app.php mounts
 * them in the stateless group, so none of them ever carries a session cookie.
 *
 * Every response states its own Cache-Control (CacheHeaders leaves a response
 * that has one alone) and nosniff.
 */
final class SiteAppController extends Controller
{
    /** A versioned URL never changes its bytes. */
    private const IMMUTABLE = 'public, max-age=31536000, immutable';

    public function __construct(private SiteApp $app) {}

    public function manifest(Request $request): JsonResponse|Response
    {
        if (! $this->app->on()) {
            return $this->gone();
        }

        $lang = $request->query('lang');

        return response()->json($this->app->manifest(is_string($lang) ? $lang : null), 200, [
            'Content-Type' => 'application/manifest+json',
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Always 200, on or off: the off answer is the worker that removes
     * itself, which is how a phone that installed the app is cleaned up.
     * no-cache, so the browser's update check always reaches this.
     */
    public function worker(): Response
    {
        return response($this->app->worker(), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function script(Request $request): Response
    {
        if (! $this->app->on()) {
            return $this->gone();
        }

        return response((string) file_get_contents(SiteApp::scriptPath()), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => $this->cacheFor($request, SiteApp::scriptPath()),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** One of the four icons, by name from SiteApp::ICONS, nothing else. */
    public function icon(Request $request, string $name): BinaryFileResponse|Response
    {
        if (! array_key_exists($name, SiteApp::ICONS) || ! is_file(SiteApp::iconPath($name))) {
            return $this->gone();
        }

        return response()->file(SiteApp::iconPath($name), [
            'Content-Type' => 'image/png',
            'Cache-Control' => $this->cacheFor($request, SiteApp::iconPath($name)),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function offline(): Response
    {
        if (! $this->app->on()) {
            return $this->gone();
        }

        return response()->view('site-app.offline', [
            'name' => $this->app->all()['name'],
            'icon' => SiteApp::iconUrl('icon-192'),
            'home' => Url::to('/'),
        ], 200, [
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /** Immutable only when the URL names the current bytes. */
    private function cacheFor(Request $request, string $path): string
    {
        return $request->query('v') === SiteApp::fileHash($path) ? self::IMMUTABLE : 'public, max-age=300';
    }

    private function gone(): Response
    {
        return response('', 404, ['Cache-Control' => 'no-cache', 'X-Content-Type-Options' => 'nosniff']);
    }
}
