<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\Analytics\Online;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /api/online -- the visible tab's heartbeat and the leave beacon for
 * Analytics' "Online now" (Lane AN2). Stateless: the api group, no session,
 * no CSRF, no cookie written; throttled per address; reads `p` (the page),
 * `t` (its title) and `x` (hb | left) and nothing else. Always 204: there is
 * nothing a shopper's page needs to read back. App\Services\Analytics\Online.
 */
final class OnlineController extends Controller
{
    public function store(Request $request): Response
    {
        Online::ping($request);

        return response('', 204);
    }
}
