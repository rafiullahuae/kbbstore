<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Store\ProductController as StoreProductController;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * FIVE DRAWINGS OF THE PRODUCT PAGE, ON THE REAL CATALOGUE, BEHIND THE ADMIN
 * LOGIN.  (Lane PDP)
 *
 *     "give me 5 different ideas surrounding related to the attachment to
 *      choose from. for desktop and mobile, both 5 each designs."
 *
 * Four of these five are going to be thrown away, so the whole point of this
 * controller is that throwing them away is `git rm` of one directory and one
 * route file. Nothing it does reaches the shop.
 *
 * ▲ THERE IS NO `?layout=` SWITCH ON THE REAL PRODUCT PAGE, AND THERE WILL NOT
 *   BE ONE. That is exactly what the previous round did: Lane PP put a
 *   three-entry map keyed off the query string into
 *   resources/views/store/product.blade.php, the owner chose none of them, and
 *   Lane PP2 then had to delete ~240 lines of `.pp-lay*` CSS and the map behind
 *   it. docs/PP-PRODUCT-PAGE-PROPOSALS.md carries the SUPERSEDED banner that
 *   records it. So `resources/views/store/product.blade.php` is not touched by
 *   this lane at all: the candidates are their OWN templates, reached only from
 *   here, and `/product/{slug}/` renders the shipped page to the byte whether
 *   this controller exists or not.
 *
 * ▲ AND IT IS GATED, RATHER THAN MERELY OBSCURE. The route file mounts under
 *   `admin-api/catalog/`, which App\Support\AdminCapabilities::RULES already
 *   governs with
 *
 *       ['GET', 'admin-api/catalog/**', 'catalog.view'],
 *
 *   so a signed-out visitor gets the admin guard's refusal and a signed-in role
 *   without `catalog.view` gets a 403. A prefix of this lane's own would fall
 *   through to the closed owner-only default, which AdminCapabilityMapTest
 *   fails on by name — the same argument routes/import-articles-page.php makes.
 *
 * ▲ THE DATA IS THE REAL PAGE'S DATA, ASKED FOR IN THE REAL PAGE'S WORDS.
 *   `Store\ProductController::show()` is called and its View's data is handed
 *   to a candidate template unchanged. Rebuilding that payload here would be a
 *   second answer to "what is on a product page" — the class of duplication
 *   this codebase has twice had to merge back together (see VariantPricing's
 *   header). It also means the candidates carry the real gallery, the real
 *   bundle bars from BundleService, the real per-product tabs from
 *   App\Support\ProductTabs, the real trust claims and the real payment chips,
 *   and it means the query count of a preview is the query count of the page —
 *   no N+1 can hide in here, because there is no query in here.
 */
class PdpPreviewController extends Controller
{
    /**
     * The five candidates, and the ONLY five strings this controller will act
     * on.
     *
     * Rule 5 of CLAUDE.md: "A select stores one of its own options or the
     * default." The candidate arrives in the URL, so it is checked against this
     * map before it is used for anything — it names a view file, and a view
     * name built from unchecked input is a path the caller chose.
     *
     * @var array<string, array{letter: string, name: string, idea: string}>
     */
    public const CANDIDATES = [
        'ledger' => [
            'letter' => 'a',
            'name' => 'A · Ledger',
            'idea' => 'No boxes anywhere. A full-bleed square photograph, then hairline rules. Tabs: an underline row with the panel inline beneath it; the row bleeds off the screen edge under a fade.',
        ],
        'dossier' => [
            'letter' => 'b',
            'name' => 'B · Dossier',
            'idea' => 'One lifted white card, overlapping the bottom of the photograph, holding the whole buying decision. Tabs: a pill row that pins under the header while you read, with the next pill peeking at the edge.',
        ],
        'counter' => [
            'letter' => 'c',
            'name' => 'C · Counter',
            'idea' => 'The price is the loudest thing on the page, on a tinted full-width band. Desktop is three columns with a sticky price rail. Tabs: a segmented control whose fill slides to the open tab.',
        ],
        'deck' => [
            'letter' => 'd',
            'name' => 'D · Deck',
            'idea' => 'The tab row IS the panel — a snapping deck of cards, the open one full width and readable, its neighbours narrow and faded. Page is unboxed with a buy bar that docks after you pass it.',
        ],
        'marquee' => [
            'letter' => 'e',
            'name' => 'E · Marquee',
            'idea' => 'A dark full-bleed tab band, white on the open tab, the panel a white sheet below it. Desktop turns the band into a vertical rail beside the panel and stands the thumbnails up the side of the photograph.',
        ],
    ];

    public function __construct(private StoreProductController $store) {}

    /**
     * The chooser: every candidate against every product the shop can show.
     *
     * No query of its own beyond the product list, and the list is the narrow
     * select the shop's own grid uses, not `Product::all()`.
     */
    public function index(): View
    {
        $products = \App\Models\Product::query()
            ->visible()
            ->select('id', 'slug', 'name', 'type', 'image')
            ->orderBy('name')
            ->limit(60)
            ->get();

        return view('store.pdp-preview.index', [
            'candidates' => self::CANDIDATES,
            'products' => $products,
        ]);
    }

    /**
     * One candidate, drawn on one real product.
     *
     * `abort(404)` and not a fallback to the first candidate: a typo in this
     * URL should say so rather than quietly photograph the wrong design, and
     * four of these five are about to be deleted — a URL naming a deleted one
     * must stop working the day it is deleted.
     */
    public function show(Request $request, string $candidate, string $slug): View
    {
        if (! array_key_exists($candidate, self::CANDIDATES)) {
            abort(404);
        }

        /*
         * The real controller, run for real. firstOrFail() inside it is what
         * answers a slug that is not a product, so an unknown slug 404s here
         * exactly as it does on the shop.
         */
        $data = $this->store->show($request, $slug)->getData();

        $data += $this->derived($data);
        $data['pvCandidate'] = $candidate;
        $data['pvLetter'] = self::CANDIDATES[$candidate]['letter'];
        $data['pvName'] = self::CANDIDATES[$candidate]['name'];
        $data['pvIdea'] = self::CANDIDATES[$candidate]['idea'];
        $data['pvAll'] = self::CANDIDATES;
        $data['pvLinks'] = $this->links($request, $slug);

        return view('store.pdp-preview.'.$candidate, $data);
    }

    /**
     * The handful of facts resources/views/store/product.blade.php works out in
     * its own @php block, worked out once here instead.
     *
     * WHY HERE AND NOT IN A LAYOUT. Blade renders a child's sections BEFORE the
     * layout that will hold them, so a `@php` block in the preview layout runs
     * too late for anything a candidate template @includes. Five copies of the
     * same block, one per candidate, is the alternative — and five copies of
     * "what does this product cost" is precisely the duplication this codebase
     * has twice had to merge back together.
     *
     * EVERY LINE IS THE SHIPPED LINE, and the two that are not obvious are the
     * two that were defects:
     *
     *   · the headline of a VARIABLE product. `Product::effectivePrice()` ends
     *     `return (int) $this->price` and a variable parent carries no price of
     *     its own, so the shipped page rendered `AED 0` on every variable
     *     product until App\Services\VariantPricing was asked instead. A
     *     preview that printed AED 0 would be showing him a bug he has already
     *     paid to have fixed.
     *
     *   · `$out`. A variable product whose every option is sold out is sold
     *     out, whatever the parent row says — without the second half of that
     *     line the stock line reads "In stock · ready to ship" above a list
     *     where every row is tagged Sold out.
     *
     * NO QUERY IS ADDED. `variants` was eager-loaded by the controller this
     * method's caller just ran, so every read below is memory.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function derived(array $data): array
    {
        /** @var \App\Models\Product $product */
        $product = $data['product'];
        $summary = $data['summary'];

        $variants = $product->variants;
        $isVar = $variants->isNotEmpty();
        $buyable = $isVar ? $variants->first(fn ($v) => $v->inStock()) : null;

        $range = app(\App\Services\VariantPricing::class)->range($product);
        $headline = null;

        if ($range !== null) {
            $dp = \App\Support\Money::decimalsToDistinguish($range[0], $range[1]);

            $headline = \App\Services\VariantPricing::isSpread($range)
                ? __('store.product_card.price_range', [
                    'low' => \App\Support\Money::format($range[0], $dp),
                    'high' => \App\Support\Money::format($range[1], $dp),
                ])
                : \App\Support\Money::format($range[0]);
        }

        $onSale = $product->isOnSale();

        return [
            'brand' => $product->brand?->t('name') ?? '',
            'name' => $product->t('name'),
            'rating' => (float) $summary['average'],
            'rcount' => (int) $summary['total'],
            'onSale' => $onSale,
            'off' => $onSale ? $product->discountPercent() : 0,
            'price' => $product->effectivePrice(),
            'kbbHeadline' => $headline,
            'out' => $product->stock_status !== 'instock' || ($isVar && $buyable === null),
        ];
    }

    /**
     * The other four drawings of THIS product, so the owner can flick between
     * them without going back to the chooser.
     *
     * DERIVED FROM THE REQUEST'S OWN PATH, never from a hard-coded '/admin-api'.
     * routes/web.php is the integrator's file and this route file says where it
     * expects to be mounted — but "expects" is not "is", and a switcher built
     * from a prefix this controller assumed would send him to a 404 the day the
     * mount moves. Chopping the last two segments off the path that reached
     * here is true wherever it is mounted, including under a KBB_BASE_PATH.
     *
     * @return array<string, string>
     */
    private function links(Request $request, string $slug): array
    {
        $segments = explode('/', trim($request->path(), '/'));
        array_splice($segments, -2);
        $base = implode('/', $segments);

        $links = [];

        foreach (array_keys(self::CANDIDATES) as $key) {
            $links[$key] = url($base.'/'.$key.'/'.rawurlencode($slug));
        }

        return $links;
    }
}
