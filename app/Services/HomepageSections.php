<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\GridSkins;

/**
 * Which homepage sections render, and how.
 *
 * Each section can be switched off independently for desktop and for mobile,
 * and product sections carry their own grid skin.
 *
 * Visibility is applied with CSS classes rather than by sniffing the user
 * agent, so a cached page stays correct on every device. A section switched
 * off for both is not rendered at all, which also skips its queries.
 */
class HomepageSections
{
    /** key => [label, description, has a product grid, default skin] */
    public const REGISTRY = [
        'hero'        => ['Hero slider', 'The rotating banners at the top.', false, null],
        'delivery'    => ['Delivery strip', '1-3 days delivery, free over AED 199.', false, null],
        'ticker'      => ['Promo ticker', 'The scrolling discount-code line.', false, null],
        'categories'  => ['Category circles', 'Shop by category, scrollable.', false, null],
        'bundles'     => ['Big savings bundles', 'Skincare sets and routines.', true, 'classic'],
        'recommended' => ['Recommended for you', 'Handpicked essentials.', true, 'soft'],
        'routine'     => ['Build your routine', 'The six-step routine.', false, null],
        'quiz'        => ['Skin quiz', 'The two-minute routine finder.', false, null],
        'brands'      => ['Top brands', 'Brand tiles with product counts.', false, null],
        'spotted'     => ['#KBeautyBliss spotted', 'Shoppable community photos.', false, null],
        'bestsellers' => ['Best sellers', 'Ranked by sales this month.', true, 'luxe'],
        'flash'       => ['Flash sale', 'Discounted, with stock remaining.', true, 'ribbon'],
        'blog'        => ['Skincare guide', 'Latest journal articles.', false, null],
        'about'       => ['About us', 'Story and proof numbers.', false, null],
        'reviews'     => ['Customer reviews', 'Score summary and review cards.', false, null],
        'trust'       => ['Trust row', 'Shipping, payments, authenticity, support.', false, null],
        'newsletter'  => ['Newsletter', 'Ten percent off the first order.', false, null],
    ];

    /**
     * Sections whose markup is drawn INSIDE another section, and the host they
     * travel with.
     *
     * ── WHY THIS CONSTANT HAS TO EXIST ──────────────────────────────────────
     *
     * Ordering is applied with CSS `order`, which moves FLEX CHILDREN. Fifteen
     * of the seventeen sections are direct children of `.kbb-home` and move.
     * `delivery` and `ticker` are not: store/home.blade.php draws both inside
     * the hero's own `<section>`, under its `.wrap`, because the hero band is
     * one visual unit and the delivery strip and the promo ticker are the two
     * lines beneath its slider. `order` on a non-flex-child is INERT — the
     * declaration is accepted and does nothing at all.
     *
     * So this is not a limitation that can be hidden: an ↑ on those two rows
     * would be a button that moves a row on a screen and nothing on the shop,
     * which is the exact defect docs/FO-HOMEPAGE-INVENTORY.md was written to
     * catalogue. It is named here instead, carried into the payload the console
     * paints from, and — the half that matters — ENFORCED in all(), which keeps
     * each nested section pinned directly behind its host. The row order the
     * owner is shown is therefore the order the shopper gets, for all
     * seventeen, with no row that lies.
     *
     * Lifting the two out of the hero would make them movable and is the right
     * eventual shape, but it moves rendered bytes for every shop on the shipped
     * layout — see docs/FR-HOMEPAGE-ORDER.md, which costs it.
     */
    public const NESTED = [
        'delivery' => 'hero',
        'ticker' => 'hero',
    ];

    /**
     * Said on the row, in the owner's words, instead of an arrow that lies.
     *
     * THE THIRD CLAUSE USED TO BE ABOUT THE SWITCHES, AND IS NOT ANY MORE —
     * Lane FW. It read "and is hidden on any device the hero itself is
     * switched off for", which was true when Lane FR wrote it and is the
     * defect that lane measured and costed rather than fixed: the band is one
     * `<section>` carrying the HERO's visibility classes, so `d-off` on the
     * hero set `display:none` on the element these two are drawn inside and
     * their own Desktop/Mobile switches were overridden with nothing said.
     *
     * The band now takes bandClassFor(), the UNION of the three rows, and the
     * slider takes the hero's own — so these two rows' switches decide these
     * two rows, which is what the screen has always implied they do. What
     * remains true of them, and is all this sentence now claims, is that they
     * travel with the hero's POSITION: they are drawn inside its markup, so
     * CSS `order` cannot move them away from it.
     *
     * The clause is not replaced by a reassurance. A row that behaves the way
     * the screen's own controls say it does needs no sentence about it, and one
     * would only go stale in the other direction.
     */
    public const NESTED_NOTE = 'Drawn inside the hero band, so it moves with the hero and cannot be placed elsewhere on the page. Its own Desktop and Mobile switches still decide whether it shows.';

    /**
     * The three controls a section row carries, as ModuleSchema fields.
     *
     * ── WHY THE ROW GETS A SCHEMA AT ALL ────────────────────────────────────
     *
     * This screen is older than ModuleSchema and grew its own coercion: a pair
     * of `(bool)` casts in all(), the same pair again in save(), and
     * `GridSkins::exists($skin) ? $skin : $defaultSkin` written out twice. Four
     * copies of three rules, which is exactly the arrangement
     * docs/M-PHASE3-SETTINGS-SCHEMA.md §1 measured the cost of: the isValidHex
     * defect survived in four modules because there were four copies of the
     * same three lines and nothing tied them together.
     *
     * It matters more here than it did there, because a THIRD reader has just
     * arrived. `proposing()` below renders the homepage from a configuration
     * nobody has saved, and the only thing that makes such a preview worth
     * looking at is that it answers the same way the save would. Two copies of
     * the rules make that a promise; one cast makes it a fact — the preview and
     * the save are literally the same three lines of ModuleSchema::cast().
     *
     * `order` IS NOT IN HERE, and that is deliberate rather than an omission.
     * It is not a control: nothing on the screen types it, the console posts a
     * SEQUENCE and the server numbers it by position, and settle() rewrites it
     * on every read. A schema field is something an owner sets; a position is
     * something the list has.
     */
    public const SECTION_SCHEMA = [
        'desktop' => [
            'type' => 'bool',
            'label' => 'Show on desktop',
            'default' => true,
            'help' => 'Hidden above the mobile breakpoint when off. A section off for both is not rendered at all, so it costs no queries either.',
        ],
        'mobile' => [
            'type' => 'bool',
            'label' => 'Show on mobile',
            'default' => true,
            'help' => 'Hidden at or below the mobile breakpoint when off.',
        ],
        'skin' => [
            'type' => 'skin',
            'label' => 'Grid style',
            'default' => '',
            'help' => 'One of the product-grid card templates. Only the four sections that draw a product grid carry one.',
        ],
    ];

    /**
     * The point this screen has always occupied on the policy axes, declared.
     *
     * `bool => cast` is the plain `(bool)` both readers already did — NOT the
     * word-aware dialect, which would read the string "off" as false where this
     * screen has always read it as true. `invalid => default` is
     * `GridSkins::exists($skin) ? $skin : $defaultSkin` restated: an unknown
     * skin falls back to the section's own default rather than being refused,
     * because a refusal on a read has no channel to report through and the page
     * still has to draw a grid.
     *
     * Both were established by running the old code over an adversarial corpus
     * before a line moved, not by reading it — see
     * tests/Feature/HomepageSectionSchemaTest.php, which keeps the old bodies
     * as literal expectations and drives several thousand calls through both — the
     * read case alone asserts it made more than 4,000.
     */
    public const SECTION_POLICY = ['bool' => 'cast', 'invalid' => 'default'];

    /**
     * A configuration this instance answers from INSTEAD of the stored one.
     *
     * Null on every instance the storefront and the console build, which is
     * every instance but the one preview() makes, so the shop reads the
     * settings table exactly as it did before this existed.
     *
     * @var array<string, mixed>|null
     */
    private ?array $proposed = null;

    public function __construct(private SettingsService $settings) {}

    /**
     * A reader for an arrangement NOBODY HAS SAVED.
     *
     * ── WHAT THIS IS FOR ────────────────────────────────────────────────────
     *
     * Both homepage screens publish straight to the live shop: the only way to
     * see what moving a section does was to save it and then go and look, on
     * the shop real visitors are on. That is the whole of what "live editing"
     * was missing here — not a control, a LOOK. This instance is the seam: the
     * admin posts the arrangement currently on screen, the homepage is rendered
     * through this reader instead of the stored one, and nothing is written.
     *
     * IT ANSWERS THROUGH THE SAME all(), WHICH IS THE POINT AND NOT A SHORTCUT.
     * The proposal is merged over the registry, cast through the same
     * SECTION_SCHEMA, sorted and settle()d by the same code the shop reads
     * through — so a preview cannot show an order the page would not draw
     * (settle() puts a nested row back behind its host here too) and cannot
     * show a skin the save would refuse. A second, simpler reader written for
     * the preview would be a third dialect, and this project has paid for every
     * dialect it has.
     *
     * @param  array<string, mixed>  $proposed  the saved-payload shape:
     *         key => [desktop, mobile, skin, order]
     */
    public static function proposing(SettingsService $settings, array $proposed): self
    {
        $reader = new self($settings);
        $reader->proposed = $proposed;

        return $reader;
    }

    /** True when this instance is answering from a proposal rather than the shop. */
    public function isProposal(): bool
    {
        return $this->proposed !== null;
    }

    /**
     * The saved configuration, merged over the defaults.
     *
     * Merging rather than replacing means a section added in a later release
     * appears immediately and switched on, instead of vanishing because an
     * older saved payload never mentioned it.
     */
    public function all(): array
    {
        $saved = $this->proposed ?? $this->settings->get('homepage_sections');
        $saved = is_array($saved) ? $saved : [];

        $out = [];
        $order = 0;

        foreach (self::REGISTRY as $key => [$label, $desc, $hasGrid, $defaultSkin]) {
            $row = is_array($saved[$key] ?? null) ? $saved[$key] : [];
            $cast = self::castRow($key, $row);

            $out[$key] = [
                'key' => $key,
                'label' => $label,
                'description' => $desc,
                'has_grid' => $hasGrid,
                'skin' => $cast['skin'],
                'desktop' => $cast['desktop'],
                'mobile' => $cast['mobile'],
                'order' => (int) ($row['order'] ?? $order),
                // What the console needs to draw the row honestly. Both are
                // derived from NESTED rather than stored, so a saved payload
                // cannot disagree with the template.
                'nested_in' => self::NESTED[$key] ?? null,
                'movable' => ! isset(self::NESTED[$key]),
                'note' => isset(self::NESTED[$key]) ? self::NESTED_NOTE : null,
            ];

            $order++;
        }

        uasort($out, fn ($a, $b) => $a['order'] <=> $b['order']);

        return self::settle($out);
    }

    /**
     * Put each nested section back behind its host, then renumber.
     *
     * ── WHAT THIS IS FOR ────────────────────────────────────────────────────
     *
     * A saved payload can place `ticker` at position 16 — the Editorial preset
     * does exactly that, and did before this change. The template cannot honour
     * it: the ticker is drawn inside the hero's `<section>` and renders wherever
     * the hero renders. Left alone, all() would hand the console a list in which
     * two rows sit somewhere the shopper will never see them, the screen would
     * paint that list, and the owner would be told a position the page does not
     * have. That is the same "saved and never read" fault one level along.
     *
     * So the constraint is applied HERE, in the one reader both the console and
     * the storefront go through, rather than being described on the screen and
     * hoped for. A nested section always follows its host immediately; several
     * sharing a host keep REGISTRY order between them, which is the order the
     * hero's own markup draws them in and therefore the only order that is
     * true.
     *
     * `order` is then rewritten to the EFFECTIVE position, 0..n-1. That makes
     * the value idempotent: the console posts the key sequence back, save()
     * numbers it by position, and a second read returns the same list. A
     * preset that scattered the nested rows is normalised on the way out rather
     * than being re-saved behind the owner's back.
     *
     * @param  array<string, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private static function settle(array $rows): array
    {
        $out = [];

        foreach (self::settleKeys(array_keys($rows)) as $key) {
            $out[$key] = $rows[$key];
        }

        $i = 0;

        foreach ($out as $key => $row) {
            $out[$key]['order'] = $i++;
        }

        return $out;
    }

    /**
     * The sequence a saved key order REALLY produces on the page.
     *
     * Split out of settle() so that a caller holding a bare list of keys can
     * ask the same question without inventing rows to ask it with — which is
     * what HomepageLayouts::summaries() was doing wrong. Its wire-frame preview
     * drew each preset's STORED sequence, and two of the four presets store a
     * sequence this method rewrites: Conversion puts the ticker before the
     * delivery strip and Boutique puts the delivery strip tenth. Applying
     * either produced a different page from the one the preview drew, which is
     * the same "the screen said one order and the shop rendered another" fault
     * settle() exists to end, one screen along.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public static function settleKeys(array $keys): array
    {
        $registry = array_keys(self::REGISTRY);

        // Hosts in their saved order; nested keys dropped out of the sequence.
        $hosts = array_values(array_filter($keys, fn ($k) => ! isset(self::NESTED[$k])));

        $out = [];

        foreach ($hosts as $host) {
            $out[] = $host;

            foreach ($registry as $key) {
                if ((self::NESTED[$key] ?? null) === $host && in_array($key, $keys, true)) {
                    $out[] = $key;
                }
            }
        }

        // A nested section whose host is not in the list at all would otherwise
        // be dropped from the page's own inventory. Nothing writes that today;
        // the fallback keeps a hand-edited settings row visible rather than
        // silently short.
        foreach ($keys as $key) {
            if (! in_array($key, $out, true)) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * True when the page renders in the order its template is written in.
     *
     * The whole ordering mechanism is gated on this being false. A shop that
     * has never opened Appearance → Homepage — and one that has opened it and
     * changed only the switches — emits not one extra byte: no style element,
     * no extra class, no attribute. "Unchanged means unchanged" is then a
     * property of the code rather than a claim in a test.
     */
    public function orderIsDefault(): bool
    {
        return self::isDefaultOrder($this->all());
    }

    /**
     * Taken over a list that has ALREADY been read, deliberately.
     *
     * classFor() runs once per section and all() is not cheap — it reads the
     * saved payload, merges the registry over it, sorts and settles. The one
     * call it already makes answers this too, so ordering costs the page no
     * extra read at all.
     *
     * @param  array<string, array<string, mixed>>  $rows
     */
    private static function isDefaultOrder(array $rows): bool
    {
        return array_keys($rows) === array_keys(self::REGISTRY);
    }

    /**
     * The <style> element that applies the saved order, or '' for a default one.
     *
     * ── WHY INLINE CSS AND NOT resources/css ────────────────────────────────
     *
     * The storefront serves BUILT css: `@vite()` resolves to a hashed file
     * under a web root that is a different directory from the application, and
     * building it is a manual step nobody runs during an update. A rule added
     * to kbb.css therefore ships INERT until somebody rebuilds the bundle, and
     * an ordering feature that silently does nothing is what this change exists
     * to remove. Emitted here it is part of the page and cannot be stale.
     *
     * It is also the reason `.kbb-home{display:flex}` is safe. That class is on
     * four templates — home, collection, page and wishlist — and a stylesheet
     * rule would turn all four into flex containers to serve one. This element
     * is pushed by store/home.blade.php alone, so nothing else on the shop can
     * be reached by it.
     *
     * Only the fifteen movable sections get a rule. `delivery` and `ticker` are
     * not children of `.kbb-home`, so `order` on them would be a declaration
     * the browser accepts and ignores — see NESTED.
     */
    public function orderStyle(): string
    {
        $all = $this->all();

        if (self::isDefaultOrder($all)) {
            return '';
        }

        $rules = '';

        foreach ($all as $key => $row) {
            if (isset(self::NESTED[$key])) {
                continue;
            }

            $rules .= '.kbb-home>.kbb-ord-' . $row['order'] . '{order:' . $row['order'] . '}';
        }

        return '<style>.kbb-home{display:flex;flex-direction:column}' . $rules . '</style>';
    }

    /** True when the section is off on both, so it need not render at all. */
    public function hidden(string $key): bool
    {
        $s = $this->all()[$key] ?? null;

        return $s !== null && ! $s['desktop'] && ! $s['mobile'];
    }

    /**
     * True when the WRAPPER a section is drawn in need not render at all.
     *
     * For fifteen of the seventeen this is hidden() itself. For a HOST it is
     * not: the hero's `<section>` is also the element the delivery strip and
     * the promo ticker are drawn inside, so it has to survive the hero being
     * switched off on both devices whenever either of those two is still on.
     * Dropping it would take two sections the owner has switched ON off the
     * page with it — which is what this file did until Lane FW.
     */
    public function bandHidden(string $key): bool
    {
        [$desktop, $mobile] = $this->bandVisibility($key);

        return ! $desktop && ! $mobile;
    }

    /**
     * The visibility of a host's wrapper: the UNION of its own and every
     * section drawn inside it.
     *
     * ── WHY A UNION AND NOT THE HOST'S OWN ──────────────────────────────────
     *
     * `.d-off{display:none !important}` is applied to the wrapper, and
     * `display:none` takes the subtree with it. A nested section's own `d-off`
     * can therefore only ever SUBTRACT from what its host shows; it can never
     * add. So the wrapper has to be visible on a device if ANY of the sections
     * it carries is on for that device, and each of them then subtracts its own
     * switch from that inside. Any other rule makes the nested rows' switches
     * decorative, which is what they were.
     *
     * A section with nothing nested in it returns its own two flags unchanged,
     * so this is the general case and classFor() is not a special one.
     *
     * @return array{0: bool, 1: bool}
     */
    private function bandVisibility(string $key): array
    {
        $all = $this->all();
        $s = $all[$key] ?? null;

        if ($s === null) {
            return [false, false];
        }

        $desktop = (bool) $s['desktop'];
        $mobile = (bool) $s['mobile'];

        foreach (self::NESTED as $child => $host) {
            if ($host !== $key || ! isset($all[$child])) {
                continue;
            }

            $desktop = $desktop || (bool) $all[$child]['desktop'];
            $mobile = $mobile || (bool) $all[$child]['mobile'];
        }

        return [$desktop, $mobile];
    }

    /**
     * The visibility class for a section wrapper.
     * d-off hides it above the mobile breakpoint, m-off at or below it.
     */
    public function classFor(string $key): string
    {
        $all = $this->all();
        $s = $all[$key] ?? null;

        if ($s === null) {
            return '';
        }

        return $this->frameClass($key, $all, (bool) $s['desktop'], (bool) $s['mobile']);
    }

    /**
     * The class for a HOST's wrapper — the hero's `<section>`.
     *
     * Same order class and same divider mark as classFor(), and the union
     * visibility instead of the host's own. For a shop with the three rows on
     * it returns exactly what classFor() returns, byte for byte, which is every
     * shop that has not used those switches.
     */
    public function bandClassFor(string $key): string
    {
        $all = $this->all();

        if (! isset($all[$key])) {
            return '';
        }

        [$desktop, $mobile] = $this->bandVisibility($key);

        return $this->frameClass($key, $all, $desktop, $mobile);
    }

    /**
     * A section's OWN d-off/m-off, with no order class and no divider mark.
     *
     * For the element that carries a host's own content — the hero's slider —
     * which sits inside a wrapper that is now showing on a device for somebody
     * else's sake. Without this the hero would be dragged back on by its own
     * lodgers, which is the same defect in the other direction.
     *
     * No order class: the slider is not a child of `.kbb-home`. No divider
     * mark: the mark belongs above the wrapper, and a second one inside it
     * would draw the separator twice.
     */
    public function deviceClassFor(string $key): string
    {
        $s = $this->all()[$key] ?? null;

        if ($s === null) {
            return '';
        }

        return trim(($s['desktop'] ? '' : 'd-off ') . ($s['mobile'] ? '' : 'm-off '));
    }

    /**
     * @param  array<string, array<string, mixed>>  $all
     */
    private function frameClass(string $key, array $all, bool $desktop, bool $mobile): string
    {
        $s = $all[$key];

        // The divider class is added here rather than in the template: all
        // seventeen sections already call this, so none can be missed and none
        // of them had to change. The ORDER class rides the same argument, which
        // is why store/home.blade.php needed no per-section edit for it.
        $divider = app(SectionDividers::class);

        // "The first section" is the first one in the SAVED order, not the
        // first one in the registry. With the order left alone the two are the
        // same key and this renders identically; once the hero has been moved
        // down the page, `first => off` has to mean the section that is now at
        // the top, or the setting names a position rather than a section.
        $mark = $key === array_key_first($all) && ! $divider->showAboveFirst()
            ? ''
            : $divider->classFor($key);

        // Nothing is emitted while the order is the template's own, so a shop
        // that has not touched the screen renders the same bytes it did before
        // this feature existed. `delivery` and `ticker` never get one: they are
        // not children of `.kbb-home` and the declaration would be inert on
        // them — see NESTED.
        $ord = ! isset(self::NESTED[$key]) && ! self::isDefaultOrder($all)
            ? 'kbb-ord-' . $s['order'] . ' '
            : '';

        return trim($ord . ($desktop ? '' : 'd-off ') . ($mobile ? '' : 'm-off ') . $mark);
    }

    public function skinFor(string $key): ?string
    {
        return $this->all()[$key]['skin'] ?? null;
    }

    /**
     * The fields one section's row is cast through.
     *
     * Memoised by the DEFAULT SKIN rather than by the section, because that is
     * the only thing that differs between them: seventeen sections share five
     * field sets. The key is this class plus that default, so it cannot collide
     * with another module's entry in the shared memo.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function fieldsFor(string $key): array
    {
        $defaultSkin = (string) (self::REGISTRY[$key][3] ?? '');

        return ModuleSchema::normalised(
            self::class.':'.$defaultSkin,
            self::SECTION_SCHEMA,
            self::SECTION_POLICY,
            // The option set lives in another registry, which is what the
            // `overrides` channel is for, and the default is the section's own.
            ['skin' => ['options' => GridSkins::ALL, 'default' => $defaultSkin]],
        );
    }

    /**
     * One row, re-derived: the single boundary all(), save() and the preview
     * share.
     *
     * A section with no product grid stores `skin => null` — that is the shape
     * this screen has always written and the console draws no picker for it, so
     * the cast's answer is discarded rather than stored. Nulling it here rather
     * than declaring a second schema keeps one schema for seventeen rows.
     *
     * @param  array<string, mixed>  $row
     * @return array{desktop: bool, mobile: bool, skin: string|null}
     */
    private static function castRow(string $key, array $row): array
    {
        $fields = self::fieldsFor($key);
        $out = [];

        foreach ($fields as $name => $field) {
            /*
             * `??` AND NOT array_key_exists(), AND THE CORPUS IS WHY.
             *
             * Both readers this replaces wrote `$row['desktop'] ?? true`, which
             * treats a row whose value IS NULL exactly like a row that has no
             * such key — so a stored null has always meant "shown". The obvious
             * migration, array_key_exists() plus the field default, hands
             * cast() a literal null instead and `(bool) null` is FALSE: every
             * section carrying a null would have gone dark on a shop that had
             * them on. Caught by HomepageSectionSchemaTest's corpus, which is
             * the only reason it is written this way rather than the other.
             */
            $out[$name] = ModuleSchema::cast($field, $row[$name] ?? $field['default']);
        }

        return [
            'desktop' => (bool) $out['desktop'],
            'mobile' => (bool) $out['mobile'],
            'skin' => self::REGISTRY[$key][2] ? (string) $out['skin'] : null,
        ];
    }

    /**
     * Persist a validated payload.
     *
     * REFUSES OUTRIGHT ON A PROPOSAL INSTANCE. proposing() exists so that a
     * configuration can be RENDERED without being stored; an instance carrying
     * one that could also write would be a preview that publishes, which is the
     * one failure this feature must not have. It throws rather than returning
     * quietly, because a silent no-op here looks to the caller exactly like a
     * successful save.
     */
    public function save(array $sections): void
    {
        if ($this->proposed !== null) {
            throw new \LogicException('A homepage preview reader may not write. See HomepageSections::proposing().');
        }

        $clean = [];
        $order = 0;

        foreach ($sections as $key => $row) {
            if (! isset(self::REGISTRY[$key])) {
                continue;
            }

            $cast = self::castRow($key, is_array($row) ? $row : []);

            // The key order is the one this screen has always stored. Nothing
            // reads the blob positionally, but a settings row that rewrites
            // itself on every save is a diff nobody can review.
            $clean[$key] = [
                'desktop' => $cast['desktop'],
                'mobile' => $cast['mobile'],
                'order' => (int) (is_array($row) ? ($row['order'] ?? $order) : $order),
                'skin' => $cast['skin'],
            ];

            $order++;
        }

        $this->settings->set('homepage_sections', $clean);
    }
}
