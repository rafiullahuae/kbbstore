<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\HumanCheck;
use Illuminate\Http\JsonResponse;

/** Supplies a fresh sum for the sign-up form. */
class AccountPanelController extends Controller
{
    public function __construct(private HumanCheck $check) {}

    public function question(): JsonResponse
    {
        return response()->json($this->check->issue());
    }
}
