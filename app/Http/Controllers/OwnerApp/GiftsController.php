<?php

declare(strict_types=1);

namespace App\Http\Controllers\OwnerApp;

use App\Http\Controllers\Admin\FreeGiftsController;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\FreeGifts;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Response;

/**
 * Free gifts on the phone (Lane OAG). The owner: "bring the gifts
 * functionality to the owner app too ... the same functionality which i have
 * on desktop admin" -- Marketing -> Free gifts -> Ranges & gifts.
 *
 * ONE RULE. Every read and write here is the console's own
 * Admin\FreeGiftsController method, called with a copy of the request that
 * carries only the fields that method reads (the OrdersController::payLink
 * pattern). Validation, the variation check, the kind memory, compile() and
 * the revision guard (409 on a stale save) all run there, once, for both
 * screens -- so a range saved from the phone and one saved from the desk store
 * exactly the same rows. Nothing about gifts is decided in this file.
 *
 * Capabilities are the console's: freegifts.view to read and search,
 * freegifts.manage to change anything. Both fail closed (Concerns::refuse).
 *
 * The phone gets LESS than the console: no Testing IP list, no texts, no
 * "my_ip", no gift-product catalogue -- an allowlist, row by row.
 */
class GiftsController extends Controller
{
    use Concerns;

    /** The keys a gift's product carries to the phone. */
    public const PRODUCT_KEYS = ['id', 'name', 'brand', 'thumb', 'type', 'status', 'in_stock', 'stock', 'gift_only', 'gift_kind', 'price', 'product_name', 'variant_id', 'variant_label'];

    /** The fields of a range the console's saveRange() reads. */
    private const RANGE_FIELDS = ['min', 'max', 'label_en', 'label_ar', 'enabled', 'starts_on', 'ends_on', 'gifts', 'needs', 'revision'];

    public function __construct(private FreeGifts $gifts) {}

    /** GET /{app}/api/gifts */
    public function show(Request $request): JsonResponse
    {
        if ($r = $this->refuse($request, 'freegifts.view')) {
            return $r;
        }

        return $this->answer($this->delegate(fn () => $this->desk()->show()));
    }

    /** POST /{app}/api/gifts/mode {mode} -- Off / Testing / Live, the console's switch(). */
    public function mode(Request $request): JsonResponse
    {
        if ($r = $this->refuse($request, 'freegifts.manage')) {
            return $r;
        }

        /*
         * THE APP'S PIN, ONCE, FOR THIS SWITCH ONLY (2.60.615, the owner: Off /
         * Testing / Live "lock these options in owner app ... it will require
         * the app password once to apply the change ... this security is only
         * for the free gifts functionality OFF, TESTING and LIVE status").
         * Checked here, on the server, before anything is written -- the
         * phone's yes/no sheet and PIN pad are only the way in. The check is
         * the lock screen's own (OwnerAppAuth::confirmPin): the same lockout
         * ladder and attempt limits, so this box is no softer a door.
         */
        $device = $request->attributes->get('oa.device');
        if (! $device instanceof \App\Models\OwnerAppDevice) {
            return response()->json(['ok' => false, 'code' => 'pin', 'message' => 'Enter your app PIN to change the mode.'], 403);
        }
        $pin = $request->input('pin');
        $refused = \App\Services\OwnerApp\OwnerAppAuth::confirmPin($request, $device, is_string($pin) ? mb_substr($pin, 0, 16) : '');
        if ($refused !== null) {
            return response()->json($refused['body'] + ['pin_refused' => true], $refused['status']);
        }

        $sub = $this->sub($request, ['mode' => $request->input('mode')]);

        return $this->answer($this->delegate(fn () => $this->desk()->switch($sub)));
    }

    /** POST /{app}/api/gifts/ranges and /{app}/api/gifts/ranges/{id} -- the console's saveRange(). */
    public function save(Request $request, ?int $id = null): JsonResponse
    {
        if ($r = $this->refuse($request, 'freegifts.manage')) {
            return $r;
        }

        $input = [];
        foreach (self::RANGE_FIELDS as $k) {
            if ($request->exists($k)) {
                $input[$k] = $request->input($k);
            }
        }

        /*
         * The console sends the counts' sentence as the wording when a range
         * has counts (rangeBody() there). Here the SERVER writes it, from the
         * same FreeGifts::criteriaWording() the console's words are pinned to
         * (FreeGiftsKindsTest), so the phone carries no copy of the table.
         */
        if (is_array($input['needs'] ?? null)) {
            $w = FreeGifts::criteriaWording(array_map(static fn ($v) => is_numeric($v) ? (int) $v : 0, $input['needs']));
            if ($w['en'] !== '') {
                $input['label_en'] = $w['en'];
                $input['label_ar'] = $w['ar'];
            }
        }

        $sub = $this->sub($request, $input);

        return $this->answer($this->delegate(fn () => $this->desk()->saveRange($sub, $id)));
    }

    /** POST /{app}/api/gifts/ranges/{id}/delete {revision} -- the console's deleteRange(). */
    public function delete(Request $request, int $id): JsonResponse
    {
        if ($r = $this->refuse($request, 'freegifts.manage')) {
            return $r;
        }

        $sub = $this->sub($request, $request->exists('revision') ? ['revision' => $request->input('revision')] : []);

        return $this->answer($this->delegate(fn () => $this->desk()->deleteRange($sub, $id)));
    }

    /** GET /{app}/api/gifts/products?q=&filter=&kind= -- the console's picker, its own rules. */
    public function products(Request $request): JsonResponse
    {
        if ($r = $this->refuse($request, 'freegifts.view')) {
            return $r;
        }

        $sub = Request::create($request->getRequestUri(), 'GET', array_intersect_key($request->query(), array_flip(['q', 'filter', 'kind'])));
        $res = $this->delegate(fn () => $this->desk()->products($sub));
        $d = $res instanceof JsonResponse ? (array) $res->getData(true) : [];

        if ($res->getStatusCode() !== 200) {
            return $this->failed($res, $d);
        }

        return response()->json(['ok' => true, 'products' => array_map(fn ($p) => self::product($p) + (isset($p['variants']) ? ['variants' => array_map([self::class, 'product'], (array) $p['variants'])] : []), (array) ($d['products'] ?? []))]);
    }

    /* -------------------------------------------------------------- helpers */

    private function desk(): FreeGiftsController
    {
        return app(FreeGiftsController::class);
    }

    /** A copy of the request carrying only $input: the member's other fields never reach the console. */
    private function sub(Request $request, array $input): Request
    {
        $sub = Request::create($request->getRequestUri(), 'POST', [], [], [], $request->server->all());
        $sub->headers->set('Accept', 'application/json');
        $sub->setJson(new InputBag($input));
        $sub->request = new InputBag($input);
        $sub->setUserResolver(fn () => auth('admin')->user());

        return $sub;
    }

    private function delegate(\Closure $call): Response
    {
        try {
            $res = $call();
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'message' => collect($e->errors())->flatten()->first() ?? 'Check the form.', 'errors' => $e->errors()], 422);
        } catch (HttpResponseException $e) {
            // The revision guard's 409 (FreeGiftsController::guard): passed on as it is.
            return $e->getResponse();
        }

        return $res instanceof Response ? $res : response()->json(['ok' => false], 500);
    }

    private function failed(Response $res, array $d): JsonResponse
    {
        $status = $res->getStatusCode() === 200 ? 422 : $res->getStatusCode();

        return response()->json(array_filter([
            'ok' => false,
            'code' => (string) ($d['code'] ?? ($status === 409 ? 'stale' : ($d['error'] ?? 'refused'))),
            'message' => (string) ($d['message'] ?? $d['error'] ?? 'That did not go through.'),
            'errors' => $d['errors'] ?? null,
            'revision' => $d['revision'] ?? null,
        ], static fn ($v) => $v !== null), $status);
    }

    /** The console's payload, allowlisted for the phone, plus what a shopper gets now from each saved range. */
    private function answer(Response $res): JsonResponse
    {
        $d = $res instanceof JsonResponse ? (array) $res->getData(true) : [];

        if ($res->getStatusCode() !== 200 || ! isset($d['ranges'])) {
            return $this->failed($res, $d);
        }

        $gives = $this->gives();

        return response()->json([
            'ok' => true,
            'mode' => FreeGifts::modeOf((array) ($d['config'] ?? [])),
            'testers' => count((array) ($d['config']['testers'] ?? [])),
            'revision' => (string) ($d['revision'] ?? ''),
            'low_stock' => (int) ($d['low_stock'] ?? FreeGiftsController::LOW_STOCK),
            'max_qty' => FreeGifts::MAX_QTY,
            'max_need' => FreeGifts::MAX_NEED,
            'can_manage' => (bool) ($d['can_manage'] ?? false),
            'gaps' => array_map(static fn ($g) => ['from' => (int) $g['from'], 'to' => (int) $g['to']], (array) ($d['gaps'] ?? [])),
            'checklist' => array_map(static fn ($c) => ['level' => (string) $c['level'], 'text' => (string) $c['text'], 'range' => $c['range'] === null ? null : (int) $c['range']], (array) ($d['checklist'] ?? [])),
            'ranges' => array_map(fn ($r) => [
                'id' => (int) $r['id'],
                'min' => (int) $r['min'],
                'max' => $r['max'] === null ? null : (int) $r['max'],
                'label_en' => (string) $r['label_en'],
                'label_ar' => (string) $r['label_ar'],
                'enabled' => (bool) $r['enabled'],
                'starts_on' => $r['starts_on'],
                'ends_on' => $r['ends_on'],
                'needs' => $r['needs'],
                'gifts' => array_map(fn ($g) => [
                    'product_id' => (int) $g['product_id'],
                    'variant_id' => isset($g['variant_id']) ? (int) $g['variant_id'] : null,
                    'quantity' => (int) $g['quantity'],
                    'kind' => $g['kind'],
                    'role' => $g['role'],
                    'product' => is_array($g['product'] ?? null) ? self::product($g['product']) : null,
                ], (array) $r['gifts']),
                'gives' => $gives[(int) $r['id']] ?? null,
            ], (array) $d['ranges']),
        ]);
    }

    /** @return array<string,mixed> */
    private static function product(array $p): array
    {
        $out = array_intersect_key($p, array_flip(self::PRODUCT_KEYS));

        return array_replace(array_fill_keys(['id', 'name', 'brand', 'thumb', 'type', 'status', 'in_stock', 'stock', 'gift_only', 'gift_kind', 'price'], null), $out);
    }

    /**
     * What a shopper gets from each switched-on range RIGHT NOW: the shop's own
     * walk (FreeGifts::linesFor), over the compiled copy, with every gift's
     * product read in ONE query and the variations in one more (memoised by
     * FreeGifts::variants for the request). Flat whatever the number of gifts.
     *
     * @return array<int, array{lines:list<array{name:string,quantity:int,kind:?string,backup:bool}>, sentence:string, units:int}>
     */
    private function gives(): array
    {
        $ranges = $this->gifts->ranges();
        $ids = [];
        foreach ($ranges as $r) {
            foreach ($r['gifts'] as $g) {
                $ids[] = (int) $g[0];
            }
        }

        if ($ids === []) {
            return [];
        }

        $products = Product::query()->whereIn('id', array_values(array_unique($ids)))->get()->keyBy('id')->all();
        $variants = $this->gifts->variantsFor($ranges);
        $out = [];

        foreach ($ranges as $r) {
            $lines = $this->gifts->linesFor($r, $products, $variants);
            $out[(int) $r['id']] = [
                'lines' => array_map(static fn ($l) => [
                    'name' => FreeGifts::giftName((string) $l['product']->name, $l['variant'] ?? null),
                    'quantity' => (int) $l['quantity'],
                    'kind' => $l['kind'] ?? null,
                    'backup' => (bool) ($l['backup'] ?? false),
                ], $lines),
                'sentence' => FreeGifts::sentence($lines)['en'],
                'units' => FreeGifts::units($lines),
            ];
        }

        return $out;
    }
}
