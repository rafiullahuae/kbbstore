<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CheckoutPage;
use App\Services\ModuleSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Appearance → Checkout page.
 *
 * The field/tab loop is the one every module API controller in this project
 * has (see CartPageApiController, CartPanelApiController) and is kept in that
 * shape deliberately: the admin screen that draws it is generic, and a
 * controller answering in a different shape would need a renderer of its own.
 *
 * Two endpoints and no third. Every value that crosses this boundary is
 * from a schema both sides already know; the one list read from a model is
 * the coupon select's (Lane QK6), and it carries id, code and a label only.
 */
class CheckoutPageApiController extends Controller
{
    public function __construct(private CheckoutPage $page) {}

    public function show(): JsonResponse
    {
        // One schema, drawn by one renderer. This was the same fifteen lines
        // that nine other controllers carried, and the reason the three
        // constants a screen depends on could disagree without anything saying
        // so. `POLICY` travels with the schema because ModuleSchema's cast is
        // strict by default and this screen is not — see CheckoutPage::POLICY.
        $tabs = ModuleSchema::tabs(
            CheckoutPage::SCHEMA,
            CheckoutPage::TABS,
            $this->page->all(),
            CheckoutPage::POLICY,
        );

        return response()->json([
            'tabs' => $tabs,
            // The screen draws its own preview and has to know where the page
            // changes surface, so it is told rather than left to hard-code 900
            // a third time.
            'mobileMax' => CheckoutPage::MOBILE_MAX,
            // The keys "Squeeze everything" drives to their minimum. Sent
            // rather than repeated in the screen's script: the list belongs
            // beside the schema it names, and a second copy would be a second
            // thing to forget when a control is added.
            'squeeze' => CheckoutPage::SQUEEZE,
            // Coupon line → Coupon: the shop's coupons, usable first, the same
            // list Appearance → Cart panel draws (Lane QK6). Ids and codes and
            // a label; nothing else of a coupon row crosses here.
            'coupons' => CartPanelApiController::couponList((int) $this->page->get('cline_coupon')),
            // 2.60.495: which way the 13 October layout tab's buttons point.
            'layout' => app(\App\Services\CheckoutLayout::class)->state(),
            // Lane CK: the Text sizes tabs' Arabic values and the live
            // preview's map of what each control prints.
            'type' => CheckoutPage::typeDescriptor(),
            'ar' => (object) $this->page->arValues(),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => ['required', 'array'], 'ar' => ['sometimes', 'array']]);

        $unknown = array_diff(array_keys($data['settings']), array_keys(CheckoutPage::SCHEMA));

        if ($unknown !== []) {
            return response()->json(['ok' => false, 'error' => 'Unknown setting: '.implode(', ', $unknown)], 422);
        }

        // Lane CK: an Arabic value may only be one of the text sizes.
        $unknownAr = array_diff(array_keys($data['ar'] ?? []), CheckoutPage::arKeys());

        if ($unknownAr !== []) {
            return response()->json(['ok' => false, 'error' => 'Unknown Arabic size: '.implode(', ', $unknownAr)], 422);
        }

        $this->page->save($data['settings']);

        // Sent by the screen on every Save; a request without it (an older
        // console, a script) leaves the Arabic sizes as they were.
        if ($request->has('ar')) {
            $this->page->saveAr($data['ar'] ?? []);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * 2.60.495: put the cart and checkout back as they were before the 13
     * October layout, or bring the layout back. One allowlisted word in; the
     * settings it writes are CheckoutLayout's own list, never the request's.
     */
    public function layout(Request $request): JsonResponse
    {
        $data = $request->validate(['action' => ['required', 'string', 'in:restore,apply']]);
        $layout = app(\App\Services\CheckoutLayout::class);

        $data['action'] === 'restore' ? $layout->restore() : $layout->apply();

        return response()->json(['ok' => true, 'layout' => $layout->state()]);
    }
}
