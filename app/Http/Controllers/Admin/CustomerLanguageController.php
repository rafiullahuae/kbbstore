<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\CustomerLocale;
use App\Support\Locale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Store -> Customers -> a customer -> Language (Lane AE).
 *
 *   POST /admin-api/customers/{id}/language   {"locale": "ar" | "en" | "auto"}
 *
 * "ar" / "en" is the owner's choice and is never overwritten by a sign-in or
 * an order afterwards (`locale_admin` = 1). "auto" hands the field back to the
 * shop: the flag is cleared and the language becomes that of their latest
 * order placed here (or stays as recorded when there is none), until their
 * next sign-in or order records a new one.
 *
 * Its own capability, `customers.language` (AdminCapabilities), owner and
 * manager, and it fails closed: a role without it gets 403 from
 * EnforceAdminCapability before this runs. The value is one of a closed list;
 * nothing the request carries reaches SQL except the id, as a binding.
 */
class CustomerLanguageController extends Controller
{
    public const CHOICES = ['ar', 'en', 'auto'];

    public function update(Request $request, int $id): JsonResponse
    {
        if (! CustomerLocale::ready()) {
            return response()->json(['ok' => false, 'error' => 'The customer-language update has not finished installing. Apply the package again.'], 409);
        }

        $choice = (string) $request->input('locale', '');

        if (! in_array($choice, self::CHOICES, true) || ($choice !== 'auto' && ! Locale::isSupported($choice))) {
            return response()->json(['ok' => false, 'error' => 'Choose Arabic, English or Automatic.'], 422);
        }

        $row = DB::table('customers')->where('id', $id)->first(['id', 'locale']);

        if ($row === null) {
            return response()->json(['ok' => false, 'error' => 'Customer not found.'], 404);
        }

        if ($choice === 'auto') {
            // Back to the shop's own reading: the language of their latest
            // order placed here (not an imported one), else what was recorded.
            $latest = DB::table('orders')->where('customer_id', $id)->whereNull('wc_order_id')
                ->orderByDesc('id')->value('locale');
            $locale = is_string($latest) && Locale::isSupported($latest) ? $latest : ($row->locale ?: null);

            DB::table('customers')->where('id', $id)->update(['locale' => $locale, 'locale_admin' => 0]);
        } else {
            $locale = $choice;
            DB::table('customers')->where('id', $id)->update(['locale' => $choice, 'locale_admin' => 1]);
        }

        return response()->json([
            'ok' => true,
            'locale' => $locale,
            'locale_admin' => $choice !== 'auto',
        ]);
    }
}
