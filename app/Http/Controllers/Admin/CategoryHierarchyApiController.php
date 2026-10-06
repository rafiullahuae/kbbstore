<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CategoryHierarchy\HierarchyPlanner;
use App\Services\CategoryHierarchy\HierarchySource;
use App\Services\CategoryHierarchy\HierarchySourceError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Catalog → Categories → "Copy hierarchy from kbeautybliss.com". (Lane CH)
 *
 *   POST /admin-api/categories/hierarchy/preview   {source: "site"} or a file
 *   POST /admin-api/categories/hierarchy/apply     {token}
 *
 * Both sit under `admin-api/categories/**`, catalog.manage, in the guarded
 * admin group -- an account without it gets 403 and nothing is fetched.
 *
 * The preview is a dry run: it fetches (or reads the upload), plans, writes
 * nothing, and keeps the source list for 30 minutes under a random token tied
 * to the admin who asked. Apply takes only that token, so what is written is
 * planned again from exactly the list the dry run showed -- never from
 * anything the browser sends back, and without a second fetch.
 */
class CategoryHierarchyApiController extends Controller
{
    private const TTL = 1800;

    public function preview(Request $request, HierarchySource $source, HierarchyPlanner $planner): JsonResponse
    {
        try {
            if ($request->hasFile('file')) {
                $files = $request->file('file');
                $list = $source->fromUploads(is_array($files) ? array_values($files) : [$files]);
            } elseif ($request->input('source') === 'site') {
                $list = $source->fetch();
            } else {
                return response()->json(['ok' => false, 'error' => 'Choose the site or a file.'], 422);
            }
        } catch (HierarchySourceError $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage(), 'host' => HierarchySource::host()], 422);
        }

        $token = Str::random(40);
        Cache::put(self::key($token), [
            'admin' => (int) auth('admin')->id(),
            'nodes' => $list['nodes'],
            'source' => $list['source'],
        ], self::TTL);

        $plan = $planner->plan($list['nodes']);
        unset($plan['changes']);

        return response()->json(['ok' => true, 'token' => $token, 'source' => $list['source']] + $plan);
    }

    public function apply(Request $request, HierarchyPlanner $planner): JsonResponse
    {
        $token = (string) $request->input('token', '');
        $held = preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1 ? Cache::get(self::key($token)) : null;

        if (! is_array($held) || ($held['admin'] ?? null) !== (int) auth('admin')->id()) {
            return response()->json(['ok' => false, 'error' => 'That dry run has expired. Run it again.'], 422);
        }

        $result = $planner->apply($held['nodes']);
        $plan = $result['plan'];
        unset($plan['changes']);

        return response()->json([
            'ok' => true,
            'moved' => $result['moved'],
            'redirects' => $result['redirects'],
            'source' => $held['source'],
        ] + $plan);
    }

    private static function key(string $token): string
    {
        return 'kbb.cathier.'.hash('sha256', $token);
    }
}
