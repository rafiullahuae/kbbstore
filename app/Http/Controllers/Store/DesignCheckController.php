<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\View\View;

/**
 * One page that exercises every piece of Phase 1 chrome, so display parity can be
 * checked against the WordPress design before 85 page items are built on top of
 * it. Renders with or without products, so it is useful before the catalogue
 * import. Delete once Phase 2 lands.
 */
class DesignCheckController extends Controller
{
    public function __invoke(): View
    {
        return view('design-check', [
            'products' => Product::visible()->latest('id')->limit(8)->get(),
        ]);
    }
}
