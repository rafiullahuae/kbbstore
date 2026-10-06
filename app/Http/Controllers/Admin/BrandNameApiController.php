<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\Seo\BrandRename;
use App\Services\Seo\SeoSettings;
use App\Services\SettingsService;
use App\Support\BrandName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Store → SEO Keywords → Brand. Lane BR.
 *
 *   GET  /admin-api/seo-brand            seo_brand.view    owner, manager
 *        the brand names in use, the three switches, and a DRY RUN of the
 *        rename: what would change, where, and what is left on purpose.
 *   POST /admin-api/seo-brand/replace    seo_brand.manage  owner
 *        runs it — only when `expect` equals the dry run's count right now, so
 *        a click can never replace more than the owner was shown.
 *   PUT  /admin-api/seo-brand/switches   seo_brand.manage  owner
 *
 * Mapped in AdminCapabilities ahead of nothing else on this prefix, so an
 * unmapped verb here is owner-only (EnforceAdminCapability fails closed). CSRF
 * is the admin-api group's. Every string the screen prints goes through esc().
 */
class BrandNameApiController extends Controller
{
    private const SWITCHES = ['titles' => BrandName::TITLES, 'alternates' => BrandName::ALTERNATES_KEY, 'kbeauty_one' => BrandName::KBEAUTY_ONE];

    public function show(): JsonResponse
    {
        return response()->json($this->state(BrandRename::scan()));
    }

    public function replace(Request $request): JsonResponse
    {
        $d = $request->validate(['expect' => ['required', 'integer', 'min:1', 'max:1000000']]);

        $now = BrandRename::scan()['total'];
        if ($now !== (int) $d['expect']) {
            return response()->json([
                'message' => 'The count changed since you looked ('.$now.' now, not '.$d['expect'].'). Check again, then replace.',
            ] + $this->state(BrandRename::scan()), 409);
        }

        $done = BrandRename::apply();

        return response()->json(['replaced' => $done['total'], 'errors' => $done['errors']] + $this->state(BrandRename::scan()));
    }

    public function switches(Request $request): JsonResponse
    {
        $d = $request->validate(array_fill_keys(array_keys(self::SWITCHES), ['sometimes', 'boolean']));

        foreach (self::SWITCHES as $field => $key) {
            if (array_key_exists($field, $d)) {
                Setting::query()->updateOrCreate(['key' => $key], ['value' => $d[$field] ? '1' : '0']);
            }
        }
        Setting::flushMap();
        SettingsService::forgetMemo();

        return response()->json($this->state(BrandRename::scan()));
    }

    private function state(array $scan): array
    {
        $s = SeoSettings::map();
        $switches = [];
        foreach (self::SWITCHES as $field => $key) {
            $switches[$field] = BrandName::on($s, $key);
        }

        $app = trim((string) config('app.name'));

        return [
            'name' => BrandName::NAME,
            'alternates' => BrandName::ALTERNATES,
            'home_title' => [BrandName::homeTitle('en'), BrandName::homeTitle('ar')],
            'switches' => $switches,
            'in_use' => [
                'store_name' => (string) ($s['store_name'] ?? ''),
                'seo_site_name' => (string) ($s['seo_site_name'] ?? ''),
                'org_name' => (string) ($s['org_name'] ?? ''),
                'mail_from_name' => (string) ($s['mail_from_name'] ?? ''),
            ],
            // APP_NAME is in .env, which no package rewrites. It is already
            // ignored when it names the old shop; this tells the owner to tidy it.
            'app_name_stale' => BrandName::anyCount($app) > 0,
            'scan' => [
                'total' => $scan['total'],
                'rows' => array_slice($scan['rows'], 0, 200),
                'left' => $scan['left'],
                'errors' => $scan['errors'],
                // Every area the check reads, with what it found and what is
                // left on purpose — the "0 left" the owner looks for after
                // applying. Labels are constants (BrandRename::AREAS).
                'areas' => $scan['areas'] ?? [],
            ],
        ];
    }
}
