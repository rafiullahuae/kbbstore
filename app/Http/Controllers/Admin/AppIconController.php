<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\OwnerApp\AppController;
use App\Services\AppIcons;
use App\Services\SiteApp;
use App\Support\Url;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The "App icon" card on App → Site App and App → Owner App (Lane IC).
 *
 * The scope is fixed per METHOD, never read from the request: the capability
 * is decided by the path (admin-api/site-app/** is siteapp.manage, owner and
 * manager; admin-api/owner-app/** is ownerapp.manage, Full Admin only), so a
 * method that served both from a parameter would let a manager reach the
 * owner app through the site app's door.
 *
 *     POST /admin-api/site-app/icon            multipart {kind: app|favicon, file}
 *     POST /admin-api/site-app/icon/reset      {kind}
 *     GET  /admin-api/owner-app/icon           what is uploaded, preview addresses
 *     GET  /admin-api/owner-app/icon/{name}.png  a preview image (uploaded or shipped)
 *     POST /admin-api/owner-app/icon           multipart {kind, file}
 *     POST /admin-api/owner-app/icon/reset     {kind}
 *
 * CSRF as every admin-api POST (the web group's token check; the card sends
 * X-XSRF-TOKEN). The site app's GET is the screen's existing one, which now
 * carries `icon` (SiteAppApiController).
 */
final class AppIconController extends Controller
{
    public function siteUpload(Request $request): JsonResponse
    {
        return $this->upload('site', $request);
    }

    public function siteReset(Request $request): JsonResponse
    {
        return $this->reset('site', $request);
    }

    public function ownerShow(): JsonResponse
    {
        return response()->json(['ok' => true, 'icon' => self::ownerPayload()]);
    }

    public function ownerUpload(Request $request): JsonResponse
    {
        return $this->upload('owner', $request);
    }

    public function ownerReset(Request $request): JsonResponse
    {
        return $this->reset('owner', $request);
    }

    /** A preview for the admin card: the uploaded file, else the shipped one. Allowlisted names only. */
    public function ownerPreview(string $name): BinaryFileResponse|Response
    {
        $path = self::ownerFile($name);
        if ($path === null) {
            return response('', 404);
        }

        return response()->file($path, ['Content-Type' => 'image/png', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** @return array<string,mixed> */
    public static function sitePayload(): array
    {
        $fav = SiteApp::faviconPath('favicon-48');

        return AppIcons::describe('site') + [
            'name' => app(SiteApp::class)->all()['name'],
            'previews' => [
                'apple' => SiteApp::iconUrl('apple-180'),
                'maskable' => SiteApp::iconUrl('maskable-512'),
                'icon' => SiteApp::iconUrl('icon-512'),
                'favicon' => $fav !== null ? Url::raw('/site-app/icons/favicon-48.png').'?v='.SiteApp::fileHash($fav) : null,
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function ownerPayload(): array
    {
        $url = static function (string $key): ?string {
            $path = self::ownerFile($key);

            // Relative to the card's own address (admin-api/owner-app/icon): the
            // console builds the URL, so no admin address is built here
            // (ServerBuiltAdminUrlsTest).
            return $path === null ? null : $key.'.png?v='.SiteApp::fileHash($path);
        };

        return AppIcons::describe('owner') + [
            // The Home Screen label is the manifest's short_name; not editable (not asked).
            'name' => 'KBB Owner',
            'previews' => [
                'apple' => $url('apple-180'),
                'maskable' => $url('maskable-512'),
                'icon' => $url('icon-512'),
                // The shell's tab icon today is its 192 icon; a favicon replaces it once uploaded.
                'favicon' => $url('favicon-48') ?? $url('icon-192'),
            ],
        ];
    }

    private static function ownerFile(string $key): ?string
    {
        if (isset(AppIcons::APP_SET[$key])) {
            return AppIcons::file('owner', $key) ?? (is_file($p = base_path(AppController::ICONS[$key])) ? $p : null);
        }

        return isset(AppIcons::FAVICON_SET[$key]) ? AppIcons::file('owner', $key) : null;
    }

    private function upload(string $scope, Request $request): JsonResponse
    {
        $kind = $request->input('kind');
        if (! is_string($kind) || ! in_array($kind, AppIcons::KINDS, true)) {
            return $this->answer($scope, false, 'Choose which icon this is: the app icon or the favicon.', 422);
        }

        $file = $request->file('file');
        if (! $file instanceof \Illuminate\Http\UploadedFile) {
            return $this->answer($scope, false, 'No file arrived. Choose an image and try again.', 422);
        }
        if (! $file->isValid()) {
            // Most often PHP's own upload_max_filesize, which is smaller than ours on some hosts.
            return $this->answer($scope, false, 'The file did not arrive whole ('.$file->getErrorMessage().'). Save it smaller, 1024 × 1024 PNG under 1 MB, and try again.', 422);
        }

        $r = AppIcons::store($scope, $kind, (string) $file->getRealPath());

        return $this->answer($scope, $r['ok'], $r['message'], $r['ok'] ? 200 : 422);
    }

    private function reset(string $scope, Request $request): JsonResponse
    {
        $kind = $request->input('kind');
        if (! is_string($kind) || ! in_array($kind, AppIcons::KINDS, true)) {
            return $this->answer($scope, false, 'Choose which icon to reset.', 422);
        }
        AppIcons::reset($scope, $kind);

        return $this->answer($scope, true, $kind === 'app' ? 'Back to the shipped icon.' : 'The favicon is your app icon again.', 200);
    }

    private function answer(string $scope, bool $ok, string $message, int $status): JsonResponse
    {
        return response()->json(['ok' => $ok, 'message' => $message,
            'icon' => $scope === 'site' ? self::sitePayload() : self::ownerPayload()], $status);
    }
}
