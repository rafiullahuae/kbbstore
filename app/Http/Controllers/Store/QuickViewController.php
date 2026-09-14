<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;

/**
 * Quick view -- the product summary shown in a modal from a grid card, so a
 * shopper can read the price, stock state and short description without
 * leaving a filtered listing they may have spent several clicks assembling.
 *
 * Deliberately server-rendered and returned as an HTML fragment rather than
 * JSON the browser assembles. The card markup, money formatting and sale
 * rules already live in Blade and in Money::format(); duplicating any of that
 * in JavaScript is how the two drift apart. This is the same reasoning behind
 * the cart panel returning fragments.
 *
 * scopeVisible() is applied, so an unpublished product 404s here exactly as it
 * would on its own page -- the modal is not a side door into hidden catalogue.
 */
class QuickViewController extends Controller
{
    public function __construct(private SettingsService $settings) {}

    public function show(int $id): JsonResponse
    {
        // Off at Store -> Modules means gone, not merely hidden: the button is
        // not rendered, and this endpoint stops answering too. A module switch
        // that leaves its endpoint live is not really a switch.
        abort_unless($this->settings->moduleEnabled('quick_view', true), 404);

        $product = Product::visible()
            ->with('brand')
            ->findOrFail($id);

        return response()->json([
            'ok' => true,
            'title' => $product->name,
            'html' => view('partials.quick-view', ['product' => $product])->render(),
        ]);
    }
}
