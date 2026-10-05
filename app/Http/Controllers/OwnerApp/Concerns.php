<?php

declare(strict_types=1);

namespace App\Http\Controllers\OwnerApp;

use App\Models\AdminUser;
use App\Support\AdminRoles;
use App\Support\StoreTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared by every owner-app endpoint: the capability check, which fails
 * closed, and the two formatters every list uses.
 */
trait Concerns
{
    protected function admin(Request $request): ?AdminUser
    {
        $a = $request->attributes->get('oa.admin');

        return $a instanceof AdminUser ? $a : null;
    }

    /** Null when the member holds $capability; the 403 to return when not. */
    protected function refuse(Request $request, string $capability): ?JsonResponse
    {
        if (AdminRoles::can($this->admin($request), $capability)) {
            return null;
        }

        return response()->json([
            'ok' => false,
            'code' => 'forbidden',
            'capability' => $capability,
            'message' => 'Your role does not include this. Ask the owner to add it in Users & Roles.',
        ], 403);
    }

    protected function may(Request $request, string $capability): bool
    {
        return AdminRoles::can($this->admin($request), $capability);
    }

    protected static function iso(mixed $at): ?string
    {
        return $at === null ? null : StoreTime::iso($at);
    }

    /** Wrap a delegated admin response: keep its status, never its body shape. */
    protected static function statusOf(\Symfony\Component\HttpFoundation\Response $r): int
    {
        return $r->getStatusCode();
    }
}
