<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\CartTracking\CartTrail;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** POST /api/cart-trail: a batch of taps from the bag, cart page or checkout. Always 204. (Lane CJ) */
final class CartTrailController extends Controller
{
    public function store(Request $request, CartTrail $trail): Response
    {
        $raw = (string) $request->input('e', '');
        $events = strlen($raw) <= 4000 ? json_decode($raw, true) : null;

        $trail->client($request, $events);

        return response('', 204);
    }
}
