<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\BuildMyRoutine;
use App\Services\SettingsService;
use App\Support\RoutineConcerns;
use App\Support\RoutineRoles;
use App\Support\Url;
use Illuminate\Http\Request;

/**
 * The shopper's half of Phase 10 — /routines and /routines/{concern}.
 *
 * ── OFF MEANS OFF ───────────────────────────────────────────────────────────
 *
 * Both actions start at the same gate and 404 when the module is off, which is
 * how it ships. A 404 rather than a redirect, and rather than a page saying the
 * feature is not available: an address that exists and explains itself is still
 * a trace of a feature the owner has not turned on, it is still indexable, and
 * SeoFilesController builds the sitemap from what answers 200.
 *
 * Nothing else in the storefront changes when the module is on or off. There is
 * no nav entry, no card on the home page and no banner — see
 * docs/FM-ADMIN-APP-BLOCKS.md for where a link belongs and why this lane does
 * not add one: the header, the mega menu and the footer are menu_items the
 * owner edits, not templates, and editing those templates from here would be
 * exactly the trace this promises not to leave.
 *
 * ── WHY THE SWAPS ARE IN THE QUERY STRING ───────────────────────────────────
 *
 * "Manual selection" is `?cleanse=<product-slug>`, one optional parameter per
 * role, and the page is rendered from it on the server. No session, no cookie,
 * no local storage, no JavaScript.
 *
 * That is not minimalism for its own sake. A routine a shopper has spent five
 * swaps building is a thing they send to a friend or open on a laptop after
 * choosing it on a phone, and a choice kept in a session does neither. It also
 * means this page is readable by a crawler and testable by fetching it, which
 * is the standard the rest of this lane's evidence is held to.
 *
 * Unknown or sold-out slugs fall back to the default pick rather than 404ing —
 * see BuildMyRoutine::choose() for why that direction is the correct one.
 */
class RoutineController extends Controller
{
    public function __construct(
        private BuildMyRoutine $routines,
        private SettingsService $settings,
    ) {}

    /** GET /routines — every routine this shop can show. */
    public function index(Request $request)
    {
        abort_unless($this->routines->enabled(), 404);

        $all = $this->routines->routines();

        /*
         * A routine no step of which can be filled is not listed. It is not an
         * error and not an empty state — it is a routine this shop does not yet
         * stock anything for, and a card that opens onto five "we don't stock
         * this" rows is worse than no card. The owner sees exactly which ones
         * those are, and why, on the admin screen.
         */
        $listed = array_values(array_filter($all, static fn (array $r) => $r['filled'] > 0));

        return view('store.routines', [
            /*
             * $seoCtx, not a rendered $seo string: layouts/store.blade.php owns
             * the <head> for every page that extends it and merges this on top
             * of its own defaults. A page that rendered Seo itself would publish
             * two canonicals and two Open Graph blocks.
             */
            'seoCtx' => [
                'type' => 'website',
                'description' => $listed === []
                    ? 'Korean skincare routines built from what this shop stocks.'
                    : 'Pick the concern you want to work on and get a step-by-step Korean skincare routine, built from what this shop actually has in stock.',
                'breadcrumb' => [
                    ['name' => 'Home', 'url' => Url::to('/')],
                    ['name' => 'Build my routine', 'url' => Url::to('/routines/')],
                ],
            ],
            'settings' => $this->settings,
            'pageTitle' => __('store.routines.heading'),
            'routines' => $listed,
            'routine' => null,
            'offerScope' => $this->routines->offerScope(),
        ]);
    }

    /** GET /routines/{concern} — one routine, with the shopper's swaps applied. */
    public function show(Request $request, string $concern)
    {
        abort_unless($this->routines->enabled(), 404);

        $routine = $this->routines->routine($concern, $this->picks($request));

        abort_if($routine === null, 404);

        $title = $routine['own_title'] ?? __('store.routines.title_for', [
            'concern' => __(RoutineConcerns::labelKey($concern)),
        ]);

        return view('store.routines', [
            'seoCtx' => [
                'type' => 'website',
                'title' => $title,
                /*
                 * The description names the concern and says where the products
                 * came from, and states nothing else. It does not count the
                 * steps or name a product: a share card is cached by whoever
                 * scraped it, and a routine's steps change the moment something
                 * sells out.
                 */
                'description' => 'A step-by-step Korean skincare routine for '
                    .mb_strtolower(RoutineConcerns::adminLabel($concern))
                    .', built from products this shop has in stock.',
                'breadcrumb' => [
                    ['name' => 'Home', 'url' => Url::to('/')],
                    ['name' => 'Build my routine', 'url' => Url::to('/routines/')],
                    ['name' => $title, 'url' => Url::to('/routines/'.$concern.'/')],
                ],
            ],
            'settings' => $this->settings,
            'pageTitle' => $title,
            'routines' => [],
            'routine' => $routine,
            'offerScope' => $this->routines->offerScope(),
        ]);
    }

    /**
     * The shopper's swaps, read one role at a time.
     *
     * Reading named roles rather than `$request->query()` wholesale is the
     * point: the query string is attacker-controlled and this array is used as
     * a lookup key, so only the five words this build knows can get into it.
     * The VALUES are not validated here — BuildMyRoutine::choose() compares
     * them against the real candidate list and ignores anything that is not one
     * of them, which is a stronger check than any string rule.
     *
     * @return array<string, string>
     */
    private function picks(Request $request): array
    {
        $picks = [];

        foreach (RoutineRoles::ORDER as $role) {
            $value = $request->query($role);

            if (is_string($value) && $value !== '') {
                $picks[$role] = $value;
            }
        }

        return $picks;
    }
}
