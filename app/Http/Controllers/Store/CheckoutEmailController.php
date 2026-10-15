<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Support\CheckoutEmailCapture;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /api/checkout-contact: the address a shopper typed at checkout, sent once
 * when the field is left. (Lane CAP)
 *
 * ALWAYS THE SAME EMPTY 204. Recorded, ignored, a new address or one with an
 * account behind it: the answer is identical, so this endpoint can never be
 * asked whether an address is known here. Only `email` is read from the body;
 * the cart is the requester's own, from its cookie (CheckoutEmailCapture).
 */
final class CheckoutEmailController extends Controller
{
    public function store(Request $request, CheckoutEmailCapture $capture): Response
    {
        $capture->capture($request, $request->input('email'));

        return response('', 204);
    }
}
