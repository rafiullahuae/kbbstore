<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\ModuleSchema;
use App\Services\SetAppearance;
use App\Support\SetContents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Appearance → Set. (Lane SA)
 *
 * Three endpoints and no more:
 *
 *   GET  /admin-api/set-appearance          every field, grouped into the two
 *                                           tabs the owner asked for
 *   POST /admin-api/set-appearance          save
 *   POST /admin-api/set-appearance/preview  a live redraw from what he has
 *                                           TYPED, writing nothing
 *
 * The field/tab loop on `show` is the same fifteen lines every module API
 * controller in this project has (see CartPageApiController) and is kept in that
 * shape deliberately: the screen that draws it renders from `tabs`, and a
 * controller that answered in a different shape would need a renderer of its own.
 *
 * ── THE PREVIEW RENDERS THE REAL PARTIAL, WHICH IS THE WHOLE POINT ──────────
 *
 * resources/views/admin/previews/set-appearance.blade.php includes
 * `partials.set-row` — the same file the cart drawer, the cart page and the
 * checkout summary include — with the same `$contents` shape. A second copy of
 * that markup here would disagree with the shop the first time either was
 * touched, which is the fault HomepageLayouts::summaries() shipped and the
 * reason the Banners screen's preview is an iframe over the real partial too.
 *
 * It also carries App\Services\CartPanel's own custom properties on the
 * wrapper, because the box is sized in PERCENTAGES of `--cp-thumb` and
 * `--cp-name`. A preview drawn without them would show the fallbacks (42px and
 * 12.5px) rather than this shop's cart, so a shop whose cart thumbnail is 56px
 * would be shown a smaller fan than it has. The preview reads them; it does not
 * restate them.
 *
 * ── AND IT WRITES NOTHING ──────────────────────────────────────────────────
 *
 * `preview` runs the payload through SetAppearance::preview(), which is the
 * same cast, the same clamp and the same colour repair a save uses, and then
 * through the same css(). It never calls save(). A preview built from raw POST
 * data is a preview that can show something the save would never store.
 *
 * ── NOTHING HERE REACHES /api/* ────────────────────────────────────────────
 *
 * Every path is under /admin-api, inside the group that already carries `web`,
 * `auth:admin` and NoStoreAdminApi, and behind `setappearance.manage`. The
 * preview returns a fragment of a SET a shopper can already see on the cart
 * page; it still lives behind the capability, because an endpoint that renders
 * a Blade from a POST body is not one to leave open on an argument about how
 * uninteresting its output is.
 */
class SetAppearanceApiController extends Controller
{
    public function __construct(private SetAppearance $set) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'tabs' => ModuleSchema::tabs(
                SetAppearance::SCHEMA,
                SetAppearance::TABS,
                $this->set->all(),
                SetAppearance::POLICY,
            ),
            // What "ships at today's value" means, handed to the screen so it
            // can say "3 changed from shipped" without a second copy of the
            // defaults in JavaScript that could drift from the schema.
            'defaults' => SetAppearance::defaults(),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => ['required', 'array']]);

        $unknown = array_diff(array_keys($data['settings']), array_keys(SetAppearance::SCHEMA));

        if ($unknown !== []) {
            return response()->json(['ok' => false, 'error' => 'Unknown setting: '.implode(', ', $unknown)], 422);
        }

        $this->set->save($data['settings']);

        return response()->json(['ok' => true]);
    }

    /**
     * The live preview: a whole little HTML document, for an iframe.
     *
     * AN IFRAME AND NOT AN INJECTED DIV, for the reason the Banners screen's
     * own note gives: the set box responds with a MEDIA QUERY, and a media
     * query asks the VIEWPORT how wide it is, not the box it is drawn in. A
     * preview injected straight into the console would resolve the desktop
     * branch inside a 700px panel and show the owner a row the shop never
     * draws. Inside a frame the query resolves against the frame's own width,
     * so the Phone and Desktop buttons show what those widths really produce —
     * which on a screen whose entire subject is "desktop and mobile separately"
     * is not a nicety.
     */
    public function preview(Request $request): Response
    {
        $data = $request->validate(['settings' => ['nullable', 'array']]);

        $values = $this->set->preview($data['settings'] ?? []);

        return response(
            view('admin.previews.set-appearance', [
                'setValues' => $values,
                'setCss' => SetAppearance::css($values),
                'setContents' => $this->contents(),
                'cartVars' => app(\App\Services\CartPanel::class)->cssVariables(),
            ])->render()
        )->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * What the preview draws — this shop's own first set where it has one.
     *
     * A REAL SET, because the owner is judging photographs in circles 26px
     * across and a row of grey gradients would not tell him whether his own
     * pictures read at that size. `SetContents::fromProduct()` is the same
     * array every surface reads, so the preview cannot describe a set
     * differently from the cart.
     *
     * The fallback is not decoration either: a shop that has not built a set
     * yet still has to be able to open this screen and see what the controls
     * do. It is five plausible members with no picture, which is exactly what
     * the box draws for a pictureless member — the gradient, never
     * Gradient::initials(), because a label on a circle is the thing the owner
     * asked not to see.
     *
     * @return array<string, mixed>
     */
    private function contents(): array
    {
        $set = Product::query()
            ->where('type', 'set')
            ->where('status', 'publish')
            ->orderBy('id')
            ->first();

        if ($set !== null) {
            $contents = SetContents::fromProduct($set);

            if ($contents['members'] !== []) {
                return $contents;
            }
        }

        $members = [];

        foreach ([
            ['COSRX', 'Advanced Snail 96 Mucin Power Essence', '100ml', 1],
            ['Beauty of Joseon', 'Relief Sun Rice + Probiotics', 'SPF50+', 2],
            ['Anua', 'Heartleaf 77% Soothing Toner', '250ml', 1],
            ['Round Lab', '1025 Dokdo Cleanser', '150ml', 1],
            ['Skin1004', 'Centella Ampoule', '55ml', 1],
        ] as [$brand, $name, $variant, $qty]) {
            $members[] = [
                'name' => $name,
                'brand' => $brand,
                'variant' => $variant,
                'quantity' => $qty,
                'image' => null,
                'url' => null,
                'visible' => false,
            ];
        }

        return [
            'members' => $members,
            'count' => count($members),
            'partsTotal' => 48900,
            'setPrice' => 39900,
            'saving' => 9000,
        ];
    }
}
