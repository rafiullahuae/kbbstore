<?php

declare(strict_types=1);

namespace App\Http\Controllers\AutopilotApi;

use App\Http\Controllers\Controller;
use App\Services\Autopilot\ShopData;
use App\Support\StoreTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /autopilot-api/v1/* -- read-only, behind AutopilotApiAuth. (Lane AP-API)
 *
 *   ping            scope orders|products  who am I, the shop's currency and day
 *   orders          scope orders   ?from&to (YYYY-MM-DD, shop days) &after &limit
 *   orders/daily    scope orders   ?from&to  one row per day, every day present
 *   refunds         scope orders   ?from&to  refunds that moved money + cancellations
 *   products        scope products ?after&limit
 *
 * Every answer carries `count`, which the door logs. Errors are 422 with a
 * sentence. Nothing here writes.
 */
final class ShopDataController extends Controller
{
    public const VERSION = 1;

    public function __construct(private ShopData $data) {}

    public function ping(Request $request): JsonResponse
    {
        return new JsonResponse([
            'ok' => true,
            'api' => self::VERSION,
            'shop' => 'kbeautybliss.com',
            'currency' => 'AED',
            'timezone' => StoreTime::zone(),
            'today' => StoreTime::now()->format('Y-m-d'),
            'count' => 0,
        ]);
    }

    public function orders(Request $request): JsonResponse
    {
        $range = ShopData::range(self::q($request, 'from'), self::q($request, 'to'));
        if (is_string($range)) {
            return self::bad($range);
        }

        $page = $this->data->orders($range[0], $range[1], max(0, (int) $request->query('after', 0)), (int) $request->query('limit', 100));

        return new JsonResponse(['ok' => true, 'from' => $range[2], 'to' => $range[3], 'count' => count($page['orders'])] + $page);
    }

    public function daily(Request $request): JsonResponse
    {
        $range = ShopData::range(self::q($request, 'from'), self::q($request, 'to'));
        if (is_string($range)) {
            return self::bad($range);
        }

        $days = $this->data->daily($range[0], $range[1], $range[2], $range[3]);

        return new JsonResponse(['ok' => true, 'from' => $range[2], 'to' => $range[3], 'currency' => 'AED', 'count' => count($days), 'days' => $days]);
    }

    public function refunds(Request $request): JsonResponse
    {
        $range = ShopData::range(self::q($request, 'from'), self::q($request, 'to'));
        if (is_string($range)) {
            return self::bad($range);
        }

        $out = $this->data->refunds($range[0], $range[1]);

        return new JsonResponse(['ok' => true, 'from' => $range[2], 'to' => $range[3], 'count' => count($out['refunds']) + count($out['cancellations'])] + $out);
    }

    public function products(Request $request): JsonResponse
    {
        $page = $this->data->products(max(0, (int) $request->query('after', 0)), (int) $request->query('limit', 100));

        return new JsonResponse(['ok' => true, 'count' => count($page['products'])] + $page);
    }

    private static function q(Request $request, string $key): ?string
    {
        $v = $request->query($key);

        return is_string($v) ? mb_substr(trim($v), 0, 20) : null;
    }

    private static function bad(string $message): JsonResponse
    {
        return new JsonResponse(['ok' => false, 'error' => 'invalid', 'message' => $message, 'count' => 0], 422);
    }
}
