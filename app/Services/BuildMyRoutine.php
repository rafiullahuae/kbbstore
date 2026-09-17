<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\Routine;
use App\Support\RoutineConcerns;
use App\Support\RoutineRoles;

/**
 * Phase 10 — Build my routine. The engine, the module's settings, and the two
 * open questions the Master Plan records against this phase.
 *
 * ── WHAT IT DOES ────────────────────────────────────────────────────────────
 *
 * A routine is a concern (App\Support\RoutineConcerns) crossed with an ordered
 * list of roles (App\Support\RoutineRoles), and each role is filled from the
 * catalogue: a product that is published, visible, in stock, and carries that
 * `routine_role`. Nothing here invents a product, a price, a discount or a
 * claim. A step nothing fills says so and links to the shop; it is never
 * silently dropped, because a four-step routine drawn as four steps is
 * indistinguishable from a four-step routine, and the owner would never find
 * out the fifth was missing.
 *
 * ── THE TWO OPEN QUESTIONS, AND WHAT THEY DEFAULT TO ────────────────────────
 *
 * KBB-Master-Plan.md, Phase 10:
 *
 *     "Open: fixed steps or a free list? One offer strip or one per routine?"
 *
 * Both are settings below rather than decisions in code, so either answer is a
 * change to one dropdown and not a rewrite. Both DEFAULT to the cheaper answer
 * for the owner, and the reasoning is written down so the default can be argued
 * with rather than guessed at:
 *
 *   steps_mode defaults to `fixed`. On the day this ships no product in the
 *   catalogue carries a role, so the owner's first job is tagging, not
 *   authoring. `fixed` means the eight routines are already correct the moment
 *   the tags exist and there is nothing to fill in; `custom` means eight step
 *   lists to write before anything renders. The free list is the RIGHT answer
 *   later — a barrier step for the sensitivity routine and none for the pores
 *   one is a real distinction — which is why `routines.steps` exists from the
 *   first migration and the switch costs nothing.
 *
 *   offer_scope defaults to `site`. The strip names a REAL coupon; a shop runs
 *   one promotion at a time, and eight per-routine strips are eight things to
 *   remember to take down when it ends. Seven stale ones quoting a dead code is
 *   the failure mode, and it is worse than no strip. `routine` is one dropdown
 *   away and the per-routine column is already there for it.
 *
 * ── AND WHAT THE STRIP MAY SAY ──────────────────────────────────────────────
 *
 * It prints a coupon that exists in `coupons`, by code, with that row's own
 * terms. It never states a saving nobody created. The previous lane deleted a
 * "15% bundle saving" from the quiz for exactly that reason, and the rule
 * generalises: the offer strip has no amount of its own to print, only a row's.
 *
 * The checks below — in date, not exhausted — are a strict subset of
 * CouponService::validate()'s. They have to be: the rest of validate() needs a
 * basket and there is no basket on this page. So the strip also PRINTS the
 * minimum-spend and expiry conditions off the row rather than hiding them, and
 * the checkout stays the place the code is really tested.
 */
class BuildMyRoutine
{
    /** The ModuleRegistry key, and the `module_settings.module` value. */
    public const MODULE = 'build_my_routine';

    public const SCHEMA = [
        'steps_mode' => [
            'type' => 'select',
            'label' => 'Routine steps',
            'default' => 'fixed',
            'options' => [
                'fixed' => 'The same five steps for every routine',
                'custom' => 'Each routine keeps its own list of steps',
            ],
            'help' => 'Fixed is cleanse, tone, treat, moisturise, SPF — in that order, for every concern. Choose “own list” to edit the steps routine by routine below.',
            'store' => ModuleSchema::STORE_MODULE,
        ],
        'offer_scope' => [
            'type' => 'select',
            'label' => 'Offer strip',
            'default' => 'site',
            'options' => [
                'site' => 'One strip, the same on every routine',
                'routine' => 'One strip per routine',
                'off' => 'No offer strip',
            ],
            'help' => 'The strip names a coupon you have already created, and prints that coupon’s own terms. It never invents a discount.',
            'store' => ModuleSchema::STORE_MODULE,
        ],
        'offer_coupon' => [
            'type' => 'text',
            'label' => 'Offer strip coupon code',
            'default' => '',
            'help' => 'Used when the strip is site-wide. Leave empty for no strip. A code that does not exist, has expired or is fully redeemed shows nothing at all.',
            'store' => ModuleSchema::STORE_MODULE,
        ],
    ];

    public const TABS = [
        'routines' => ['Routines', 'How a routine is put together.', ['steps_mode']],
        'offer' => ['Offer strip', 'The strip that names one of your real coupons on the routine pages.',
                    ['offer_scope', 'offer_coupon']],
    ];

    public function __construct(private SettingsService $settings) {}

    /**
     * Is the module on?
     *
     * THE KEY IS SPELLED OUT AND NOT self::MODULE, for the reason PayShipRules
     * already records against the same line: tests/Feature/
     * ModuleFrameworkGuardTest.php finds a module's readers by TOKENISING for
     * `moduleEnabled('<key>'` with a literal string, and a registry row marked
     * `live` whose only call passes a constant reads to that guard as a module
     * nothing switches.
     *
     * Default FALSE. This ships off; see the registry row.
     */
    public function enabled(): bool
    {
        return $this->settings->moduleEnabled('build_my_routine', false);
    }

    /**
     * The module's settings, read fresh every time — NO PER-INSTANCE MEMO.
     *
     * The sibling modules on this framework keep a `private ?array $cache`, and
     * this deliberately does not, for the reason CLAUDE.md already records
     * against Setting::map(): "memoises in a process-level static as well as the
     * cache. Within one long-lived process it will not see writes made after the
     * first call. Fine under PHP-FPM, a trap in tests and queue workers."
     *
     * It is not hypothetical here; it was measured. Illuminate\Routing\Route
     * caches the CONTROLLER INSTANCE on the Route object, and the route
     * collection outlives a test's HTTP call — so two `$this->get('/routines')`
     * calls in one test are served by the same controller and therefore the same
     * BuildMyRoutine. With a memo, a settings change made between them was
     * invisible: the page rendered the OLD answer while the database, the cache
     * and a freshly resolved instance all agreed on the new one. That is an
     * hour to find, and the same shape would bite a queue worker.
     *
     * Costing nothing is why it is safe to drop: every read below is an array
     * lookup into SettingsService's own cached snapshot of `module_settings`,
     * not a query.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return ModuleSchema::read($this->settings, self::MODULE, self::SCHEMA);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, string> rejected key => label
     */
    public function save(array $values): array
    {
        $result = ModuleSchema::write($this->settings, self::MODULE, self::SCHEMA, $values);

        return $result['rejected'];
    }

    // ---------------------------------------------------------------- routines

    /**
     * Every routine the shop can show, in order, each with its steps filled.
     *
     * ONE QUERY for the products, whatever the number of routines: every role
     * any routine names is fetched once and the rows are shared between them.
     * The naive shape — a query per step per routine — is 40 queries for the
     * list page on a shop with eight concerns, which is the same N+1 the module
     * settings snapshot already had to be rescued from.
     *
     * @param  array<string, string>  $picks  role => product slug, the shopper's swaps
     * @return list<array<string, mixed>>
     */
    public function routines(array $picks = []): array
    {
        $overrides = Routine::overrides();
        $plans = [];

        foreach (RoutineConcerns::slugs() as $concern) {
            $override = $overrides[$concern] ?? null;

            if ($override && ! $override->is_enabled) {
                continue;
            }

            $plans[$concern] = [
                'override' => $override,
                'roles' => $this->rolesFor($override),
                'position' => $override?->position ?? 0,
            ];
        }

        $byRole = $this->candidatesByRole(array_merge(...array_values(array_map(
            static fn (array $p) => $p['roles'],
            $plans
        )) ?: [[]]));

        $out = [];

        foreach ($plans as $concern => $plan) {
            $out[] = $this->assemble($concern, $plan['override'], $plan['roles'], $byRole, $picks);
        }

        /*
         * Sorted by the owner's position, then by the order RoutineConcerns
         * lists them — never by whatever the database returns. Two routines
         * both left at position 0 (which is every routine on a shop that has
         * not sorted them) must still come out the same way on every request,
         * or the list page reshuffles itself between refreshes.
         */
        usort($out, static function (array $a, array $b) {
            return [$a['position'], $a['order']] <=> [$b['position'], $b['order']];
        });

        return $out;
    }

    /**
     * One routine, or null when the concern is not one this shop has.
     *
     * @param  array<string, string>  $picks
     * @return array<string, mixed>|null
     */
    public function routine(string $concern, array $picks = []): ?array
    {
        if (! RoutineConcerns::exists($concern)) {
            return null;
        }

        $override = Routine::overrides()[$concern] ?? null;

        if ($override && ! $override->is_enabled) {
            return null;
        }

        $roles = $this->rolesFor($override);

        return $this->assemble($concern, $override, $roles, $this->candidatesByRole($roles), $picks);
    }

    /**
     * The roles this routine's steps are, honouring the steps_mode setting.
     *
     * @return list<string>
     */
    public function rolesFor(?Routine $override): array
    {
        if (($this->all()['steps_mode'] ?? 'fixed') === 'custom') {
            $own = $override?->stepRoles();

            if ($own !== null) {
                return $own;
            }
        }

        return RoutineRoles::ORDER;
    }

    /**
     * Every product that can fill each of these roles, grouped by role.
     *
     * `visible()` and `inStock()` are the model's own scopes, so this page
     * cannot show a draft, a product scheduled for next month, or something the
     * shop cannot actually send — the three states a recommendation must never
     * be in. The brand is eager-loaded because the card prints it and
     * Product::toApi() reads `relationLoaded('brand')`.
     *
     * @param  list<string>  $roles
     * @return array<string, list<Product>>
     */
    private function candidatesByRole(array $roles): array
    {
        $roles = array_values(array_unique(array_filter($roles, RoutineRoles::isRole(...))));

        if ($roles === []) {
            return [];
        }

        $rows = Product::query()
            ->visible()
            ->inStock()
            ->whereIn('routine_role', $roles)
            ->with('brand')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $out = array_fill_keys($roles, []);

        foreach ($rows as $row) {
            $role = RoutineRoles::normalise($row->routine_role);

            if ($role !== null) {
                $out[$role][] = $row;
            }
        }

        return $out;
    }

    /**
     * Turn a concern, its overrides and the candidate pool into a routine.
     *
     * @param  array<string, list<Product>>  $byRole
     * @param  array<string, string>  $picks
     * @return array<string, mixed>
     */
    private function assemble(string $concern, ?Routine $override, array $roles, array $byRole, array $picks): array
    {
        $steps = [];
        $total = 0;
        $filled = 0;

        foreach ($roles as $role) {
            $candidates = $this->forConcern($byRole[$role] ?? [], $concern);
            $chosen = $this->choose($candidates, $picks[$role] ?? null);

            if ($chosen !== null) {
                $total += $chosen->effectivePrice();
                $filled++;
            }

            $steps[] = [
                'role' => $role,
                'chosen' => $chosen,
                'candidates' => $candidates,
                // The shopper swapped this step away from the default. Used to
                // draw "reset", and to keep a swap out of the URL when it is
                // the default anyway — a shared link should carry the choices
                // that were made, not every step that was looked at.
                'swapped' => $chosen !== null && ($candidates[0] ?? null) !== null && $chosen->id !== $candidates[0]->id,
            ];
        }

        return [
            'concern' => $concern,
            // The concern's place in RoutineConcerns::LIST — the tie-break
            // under the owner's `position`, so an unsorted shop still has one
            // stable order rather than the database's.
            'order' => (int) array_search($concern, RoutineConcerns::slugs(), true),
            'position' => (int) ($override?->position ?? 0),
            'own_title' => $override?->ownTitle(),
            'own_blurb' => $override?->ownBlurb(),
            'steps' => $steps,
            'filled' => $filled,
            'total' => $total,
            'offer' => $this->offer($override),
        ];
    }

    /**
     * The candidates for one step of one routine, most relevant first.
     *
     * THE RULE, and it is the whole honesty of this feature in one sentence:
     * A PRODUCT THAT NAMES CONCERNS IS ONLY OFFERED IN THOSE ROUTINES; A
     * PRODUCT THAT NAMES NONE SUITS ANY.
     *
     * Both halves matter. Without the first, an acne routine recommends
     * whatever serum happens to sort first and the page has made a claim about
     * a product that nobody in this shop ever made. Without the second, the
     * owner has to tag every cleanser with all eight concerns before a single
     * routine fills — and a gentle cleanser really does suit all eight, so the
     * tagging would be busywork that encodes nothing.
     *
     * Concern-matched products sort ahead of untargeted ones, so the step's
     * DEFAULT pick is the most specific thing the shop stocks and the general
     * ones stay available as swaps.
     *
     * @param  list<Product>  $pool
     * @return list<Product>
     */
    private function forConcern(array $pool, string $concern): array
    {
        $matched = [];
        $general = [];

        foreach ($pool as $product) {
            $concerns = RoutineConcerns::clean($product->routine_concerns);

            if ($concerns === []) {
                $general[] = $product;
            } elseif (in_array($concern, $concerns, true)) {
                $matched[] = $product;
            }
        }

        return array_merge($matched, $general);
    }

    /**
     * The product this step shows: the shopper's swap if it is a real option,
     * otherwise the first candidate.
     *
     * An unrecognised slug falls back SILENTLY to the default rather than
     * 404ing. The slug arrives in a query string — from a shared link, a
     * bookmark, a shopper editing the URL — and the product behind it can sell
     * out or be unpublished between the link being sent and being opened. A
     * routine that 404s because one of its five steps went out of stock is a
     * broken page; a routine that quietly shows the next best cleanser is the
     * page working.
     *
     * @param  list<Product>  $candidates
     */
    private function choose(array $candidates, ?string $pick): ?Product
    {
        if ($candidates === []) {
            return null;
        }

        if (is_string($pick) && $pick !== '') {
            foreach ($candidates as $candidate) {
                if ($candidate->slug === $pick) {
                    return $candidate;
                }
            }
        }

        return $candidates[0];
    }

    // ------------------------------------------------------------ offer strip

    /**
     * The strip for this routine, or null.
     *
     * @return array<string, mixed>|null
     */
    public function offer(?Routine $override): ?array
    {
        $values = $this->all();
        $scope = (string) ($values['offer_scope'] ?? 'site');

        if ($scope === 'off') {
            return null;
        }

        $code = $scope === 'routine'
            ? $override?->ownCouponCode()
            : trim((string) ($values['offer_coupon'] ?? ''));

        if ($code === null || $code === '') {
            return null;
        }

        return $this->advertisable($code);
    }

    /**
     * A coupon worth printing on a page, or null.
     *
     * Silence on every failure, deliberately. The alternatives are worse: a
     * strip saying "this code has expired" advertises a dead promotion, and an
     * error tells the shopper about the owner's configuration.
     *
     * @return array<string, mixed>|null
     */
    public function advertisable(string $code): ?array
    {
        $coupon = Coupon::code($code)->first();

        if (! $coupon) {
            return null;
        }

        $now = now();

        if ($coupon->starts_at && $now->lt($coupon->starts_at)) {
            return null;
        }

        if ($coupon->expires_at && $now->gt($coupon->expires_at)) {
            return null;
        }

        if ($coupon->usage_limit !== null && (int) $coupon->usage_count >= (int) $coupon->usage_limit) {
            return null;
        }

        return [
            'code' => (string) $coupon->code,
            'type' => (string) $coupon->type,
            // percent ×100, or fils — whichever the type says. Both come
            // straight off the row; neither is computed here.
            'amount' => (int) $coupon->amount,
            'minimum' => $coupon->minimum_amount !== null ? (int) $coupon->minimum_amount : null,
            'expires_at' => $coupon->expires_at,
            // A code whose whole offer is the delivery. `amount` is 0 on those,
            // so without this the strip would print "0% off" — a sentence that
            // is arithmetically true and commercially a lie.
            'free_shipping' => (bool) $coupon->free_shipping,
        ];
    }

    /**
     * Which of the plan's two answers the offer strip is currently running.
     *
     * The storefront needs it to decide WHERE to draw the strip, which is the
     * visible difference between the two: site-wide is one strip above the list
     * of routines, per-routine is a strip inside each routine. Same data, same
     * code path, one dropdown apart.
     */
    public function offerScope(): string
    {
        $scope = (string) ($this->all()['offer_scope'] ?? 'site');

        return in_array($scope, ['site', 'routine', 'off'], true) ? $scope : 'site';
    }

    // ------------------------------------------------------------- the owner's view

    /**
     * What is tagged and what is not — the number the admin screen leads on.
     *
     * `total` counts the products a shopper could actually be shown (published,
     * visible, in stock), not every row in the table. A draft product with no
     * role is not a gap the owner has to close, and counting it would make the
     * untagged number permanently alarming and therefore ignorable.
     *
     * @return array<string, mixed>
     */
    public function coverage(): array
    {
        $rows = Product::query()
            ->visible()
            ->inStock()
            ->get(['id', 'routine_role', 'routine_concerns']);

        $byRole = array_fill_keys(RoutineRoles::ORDER, 0);
        $concernRole = [];
        $untagged = 0;

        foreach ($rows as $row) {
            $role = RoutineRoles::normalise($row->routine_role);

            if ($role === null) {
                $untagged++;

                continue;
            }

            $byRole[$role]++;

            $concerns = RoutineConcerns::clean($row->routine_concerns);

            // An untargeted product counts towards every concern, because that
            // is exactly what forConcern() does with it.
            foreach ($concerns === [] ? RoutineConcerns::slugs() : $concerns as $slug) {
                $concernRole[$slug][$role] = ($concernRole[$slug][$role] ?? 0) + 1;
            }
        }

        $routines = [];

        foreach (RoutineConcerns::slugs() as $slug) {
            $roles = $this->rolesFor(Routine::overrides()[$slug] ?? null);
            $empty = [];

            foreach ($roles as $role) {
                if (($concernRole[$slug][$role] ?? 0) === 0) {
                    $empty[] = $role;
                }
            }

            $routines[$slug] = [
                'steps' => count($roles),
                'empty' => $empty,
            ];
        }

        return [
            'total' => $rows->count(),
            'tagged' => $rows->count() - $untagged,
            'untagged' => $untagged,
            'by_role' => $byRole,
            'routines' => $routines,
        ];
    }
}
