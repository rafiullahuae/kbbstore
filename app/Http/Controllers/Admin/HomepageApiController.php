<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Store\HomeController;
use App\Http\Controllers\Store\ShopController;
use App\Services\HomepageContent;
use App\Services\HomepageLayouts;
use App\Services\HomepageSections;
use App\Services\SettingsService;
use App\Support\GridSkins;
use App\Support\Shortcodes;
use App\Support\Url;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Homepage section visibility, order and grid skins. */
class HomepageApiController extends Controller
{
    public function __construct(
        private HomepageSections $sections,
        private HomepageLayouts $layouts,
        private HomepageContent $content,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'sections' => array_values($this->sections->all()),
            // The two rows the arrows cannot move, and the sentence the screen
            // puts on them instead. Named from the service rather than repeated
            // in the console's javascript, so the screen cannot come to say
            // something the template does not do — each row already carries
            // `movable` and `note`; this is here for a screen that wants to
            // explain the group once.
            'nested_note' => HomepageSections::NESTED_NOTE,
            'skins' => collect(GridSkins::ALL)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            /*
             * The two option lists, NAMED FROM THE SERVICE. (Lane BG)
             *
             * Appearance → Homepage draws its own row rather than going through
             * ModuleSchema::tabs() — it is older than that framework — so it
             * needs the options as data. Sending them rather than writing them
             * into the console's JavaScript is what stops the screen coming to
             * offer a token the storefront no longer draws, which is the same
             * argument `nested_note` and `select_class` are sent on.
             */
            'backgrounds' => collect(HomepageSections::BACKGROUNDS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'widths' => collect(HomepageSections::WIDTHS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'layouts' => $this->layouts->summaries(),
            'layout' => $this->layouts->current(),
        ]);
    }

    /**
     * The validation rules the console's section list is read through.
     *
     * ONE COPY, because save() and preview() must not disagree about what a
     * posted arrangement is. A preview that accepted a shape the save refuses
     * would show the owner a page they cannot have; a preview that refused one
     * the save accepts would send them looking for a fault that is not there.
     *
     * @return array<string, list<string>>
     */
    private static function sectionRules(): array
    {
        return [
            'sections' => ['required', 'array', 'min:1'],
            'sections.*.key' => ['required', 'string', 'max:40'],
            'sections.*.desktop' => ['required', 'boolean'],
            'sections.*.mobile' => ['required', 'boolean'],
            'sections.*.skin' => ['nullable', 'string', 'max:40'],
            /*
             * NULLABLE AND BOUNDED, NOT `Rule::in()` — Lane BG, and it is the
             * same decision the `skin` line above already made.
             *
             * The values are checked ONCE, in HomepageSections::SECTION_SCHEMA,
             * where SECTION_POLICY's `invalid => default` replaces anything
             * that is not a key of BACKGROUNDS / WIDTHS. Listing the tokens
             * here as well would be a second vocabulary for the same question,
             * which is exactly what the paragraph over payloadFor() says is how
             * the two come to disagree — and this one would disagree LOUDLY: an
             * `in:` rule 422s the whole save, where the schema quietly falls
             * back, so a token added to WIDTHS and forgotten here would refuse
             * to store a page the shop can draw.
             *
             * `nullable` because a nested row's value IS null — castRow()
             * returns null for `delivery` and `ticker`, show() hands those rows
             * to the console, and the console posts back what it was given.
             */
            'sections.*.background' => ['nullable', 'string', 'max:20'],
            'sections.*.width' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * The console's list of rows as the saved payload shape, numbered by
     * position — or a 422 naming the section it could not place.
     *
     * The keys are checked against REGISTRY here and the VALUES are not: every
     * value goes through HomepageSections::SECTION_SCHEMA on the way in, which
     * is the one place a skin is checked against GridSkins and a switch is
     * cast. Checking either twice, in two vocabularies, is how the two come to
     * disagree.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{0: array<string, mixed>|null, 1: JsonResponse|null}
     */
    private static function payloadFor(array $rows): array
    {
        $payload = [];
        $order = 0;

        foreach ($rows as $row) {
            /*
             * `registry()` AND NOT THE CONST — Lane GS, and it is the one line
             * on this side of that change.
             *
             * The owner's reusable product grid puts one row per built instance
             * into HomepageSections::registry(). Appearance → Homepage paints
             * those rows, so the console posts them back — and read against the
             * CONST every one of them is an "Unknown section", which 422s the
             * whole save. The screen would show an instance, let the owner
             * reorder it or switch it off, and refuse to store any of it.
             *
             * Pinned by GridSectionHomepageOrderTest's "it saves a reordered
             * payload that contains a built grid instance": put REGISTRY back
             * and that test goes red with the 422 in its message.
             */
            if (! isset(HomepageSections::registry()[$row['key']])) {
                return [null, response()->json(['ok' => false, 'error' => "Unknown section: {$row['key']}."], 422)];
            }

            $payload[$row['key']] = [
                'desktop' => $row['desktop'],
                'mobile' => $row['mobile'],
                'skin' => $row['skin'] ?? null,
                'background' => $row['background'] ?? null,
                'width' => $row['width'] ?? null,
                'order' => $order++,
            ];
        }

        return [$payload, null];
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(self::sectionRules());

        [$payload, $error] = self::payloadFor($data['sections']);

        if ($error !== null) {
            return $error;
        }

        $this->sections->save($payload);

        // The homepage and its rails are cached; without this a change appears
        // only after the TTL and looks as though it did not save.
        Cache::forget('kbb.home.rails');
        Cache::forget('kbb.home.brands');
        Shortcodes::flush();
        ShopController::flushSidebarCache();

        return response()->json([
            'ok' => true,
            'saved' => count($payload),
            'sections' => array_values($this->sections->all()),
        ]);
    }

    /**
     * Appearance → Homepage → Preview: THE REAL HOMEPAGE, from an arrangement
     * nobody has saved.
     *
     * ── THE GAP THIS CLOSES ─────────────────────────────────────────────────
     *
     * Both homepage screens publish straight to the live shop. The section list
     * has arrows, two switches a row and a skin picker, and no way whatsoever
     * to see what any of them does short of pressing Save and opening the shop
     * — on the shop real visitors are on. Phase 15 calls the missing half "live
     * editing"; the controls were never what was missing, the LOOK was.
     *
     * ── WHY IT RENDERS THE PAGE AND NOT A DIAGRAM ──────────────────────────
     *
     * A wire-frame of the order is a fourth thing that can go stale — this
     * console has already had one, `hpWire()`, drawing each preset's stored
     * sequence rather than the one applying it produces, which is the fault
     * docs/FR-HOMEPAGE-ORDER.md named and Lane FW fixed. So this renders the
     * homepage itself, through HomeController, through store/home.blade.php,
     * with the same CSS the shop serves. There is nothing here that can
     * disagree with the shop, because there is no second drawing of it.
     *
     * ── AND IT WRITES NOTHING ───────────────────────────────────────────────
     *
     * The proposal reaches the page as a HomepageSections instance built by
     * proposing(), bound for the length of one render and dropped. That
     * instance refuses save() outright rather than merely not being asked to,
     * and none of the four cache keys the two writing endpoints forget is
     * touched here: a preview that evicted the homepage's rails would be a
     * read that costs every shopper a cold page.
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate(self::sectionRules());

        [$payload, $error] = self::payloadFor($data['sections']);

        if ($error !== null) {
            return $error;
        }

        $reader = HomepageSections::proposing(app(SettingsService::class), $payload);

        return response()->json([
            'ok' => true,
            'html' => $this->renderHome($reader),
            // What the proposal really produces once settle() has put every
            // nested row back behind its host — the same list the save would
            // hand back, so the screen can repaint from it and show the order
            // the picture above it is of.
            'sections' => array_values($reader->all()),
        ]);
    }

    /**
     * Which sections have WORDS, and where those words are edited.
     *
     * ── WHY THIS MAP EXISTS AND WHY IT IS NOT A SECOND SET OF BOXES ─────────
     *
     * A section on the homepage is two questions: WHERE it appears, which
     * HomepageSections::SECTION_SCHEMA owns, and WHAT IT SAYS, which
     * HomepageContent owns. The live panel answers the first with the section's
     * own schema, drawn through ModuleSchema exactly as every other screen in
     * this console draws a control.
     *
     * It answers the second with a LINK to the box that already exists, and
     * that is a decision rather than a shortcut. copyTab() on that same screen
     * states the rule it follows: "one sentence with two boxes is a sentence
     * that changes depending on which box you touched last." The hero slides,
     * the promo chip and the About paragraph each have exactly one control and
     * exactly one writer; this map says which tab of THAT SAME SCREEN the
     * control is on, so selecting a section in the picture is one click from
     * its wording without growing a rival box for it.
     *
     * `key` is the HomepageContent::SCHEMA key the link should focus, or null
     * where the words are a repeater rather than a field (the hero's slides).
     * `tab` is a tab of that screen: 'hero' is the slide repeater, and anything
     * else must be a key of HomepageContent::TABS.
     *
     * HomepageLiveEditTest holds all of that to the two constants in both
     * directions — a section key that is not in REGISTRY, a setting key that is
     * not in SCHEMA, a tab that does not carry it, or a SCHEMA key no section
     * claims, each fails by name. That is what stops this map becoming the
     * fourth thing on this screen that can go stale.
     *
     * @var array<string, array{tab: string, key: string|null, label: string}>
     */
    public const WORDS = [
        'hero' => ['tab' => 'hero', 'key' => null, 'label' => 'the slides, their colours and their links'],
        'ticker' => ['tab' => 'copy', 'key' => 'home_ticker', 'label' => 'the chip it scrolls'],
        // Row 55 (Lane HA): About us owns its own tab now — the paragraphs,
        // the heading and the spacing.
        'about' => ['tab' => 'about', 'key' => 'about_text', 'label' => 'the heading and the paragraphs', 'whole_tab' => true],
        // (2.60.370) The whole "Big savings bundles" tab is this section's: the
        // carousel, the heading and the All sets button, laptop and phone.
        'bundles' => ['tab' => 'bundles', 'key' => 'home_hb_title', 'label' => 'the carousel, the heading and the All sets button', 'whole_tab' => true],
        // Row 55 (Lane HA): each new section owns its tab, in page order.
        'bestselling' => ['tab' => 'bestselling', 'key' => 'home_bs_title', 'label' => 'the heading, the products, the button and the spacing', 'whole_tab' => true],
        'brands' => ['tab' => 'brands', 'key' => 'home_br_title', 'label' => 'which brands, the heading and the button', 'whole_tab' => true],
        'trending' => ['tab' => 'trending', 'key' => 'home_tr_title', 'label' => 'the heading, the products and the spacing', 'whole_tab' => true],
        'blog' => ['tab' => 'blog', 'key' => 'home_bl_title', 'label' => 'which articles and the heading', 'whole_tab' => true],
        'under54' => ['tab' => 'under54', 'key' => 'home_u54_title', 'label' => 'the price ceiling, the products and the heading', 'whole_tab' => true],
        'feature' => ['tab' => 'feature', 'key' => 'home_ft_l_title', 'label' => 'the two photos, titles, texts and links', 'whole_tab' => true],
    ];

    /**
     * Appearance → Homepage content → Live preview: THE REAL HOMEPAGE, with
     * every section in it selectable, and each section's own controls beside it.
     *
     * ── THE GAP THIS CLOSES ─────────────────────────────────────────────────
     *
     * Phase 15's last unticked line is "Live editing of homepage sections,
     * reusing the settings schemas". Both halves already existed and nothing
     * joined them: preview() above renders the real page from an unsaved
     * arrangement, and HomepageSections::SECTION_SCHEMA is the ModuleSchema
     * description of the three controls a section carries. What was missing was
     * the seam — pointing at a section in the picture and getting THAT
     * section's controls.
     *
     * ── WHAT IS DIFFERENT FROM preview(), AND IT IS ONE THING ───────────────
     *
     * The reader is built with `annotate: true`, so every section wrapper
     * carries HomepageSections::SELECT_CLASS and a class naming its key. That
     * is the whole mechanism: the screen selects on a CLASS, which is what rule
     * 4 asks for, rather than measuring anything. The document is otherwise the
     * same document — the same renderHome(), the same storefront controller and
     * template — which is why the picture cannot drift from the shop, and
     * HomepageLiveEditTest asserts exactly that by stripping the hooks and
     * diffing this answer against the plain preview's.
     *
     * preview() keeps its own promise unchanged: its document is byte-identical
     * to GET /, and HomepagePreviewTest §1 still asserts it, because annotation
     * is opt-in and nothing but this method opts in.
     *
     * ── AND IT WRITES NOTHING ───────────────────────────────────────────────
     *
     * Same guarantee as preview(), by the same construction: the proposal
     * reaches the page as a HomepageSections instance bound for the length of
     * one render and dropped, that instance refuses save() outright, and none
     * of the four cache keys the two writing endpoints forget is touched here.
     * The SAVE is the endpoint that has always written these three values —
     * POST /admin-api/homepage, above — so every control this screen draws has
     * the writer it has always had and this route adds none.
     */
    public function live(Request $request): JsonResponse
    {
        $data = $request->validate(self::sectionRules());

        [$payload, $error] = self::payloadFor($data['sections']);

        if ($error !== null) {
            return $error;
        }

        $reader = HomepageSections::proposing(app(SettingsService::class), $payload, true);

        $rows = [];

        foreach ($reader->all() as $row) {
            $rows[] = $row + [
                /*
                 * THE CONTROLS, AS THE MODULE FRAMEWORK EMITS THEM.
                 *
                 * Not a list this controller writes out: HomepageSections
                 * builds it from its own SECTION_SCHEMA, SECTION_TABS and
                 * SECTION_POLICY through ModuleSchema::tabs(), which is the
                 * call PayShipRules and MarketingPixels are drawn with. The
                 * console switches on `f.type` and names not one setting, so a
                 * field added to SECTION_SCHEMA appears on the screen without a
                 * line of the screen changing — which is the difference between
                 * reusing the schema and copying it.
                 */
                'tabs' => HomepageSections::sectionTabs($row['key'], $row),
                'words' => self::WORDS[$row['key']] ?? null,
            ];
        }

        return response()->json([
            'ok' => true,
            'html' => $this->renderHome($reader),
            // Named from the service rather than repeated in the console's
            // JavaScript, so the screen cannot come to select on a class the
            // renderer has stopped emitting.
            'select_class' => HomepageSections::SELECT_CLASS,
            'sections' => $rows,
        ]);
    }

    /**
     * store/home.blade.php, rendered through the storefront's own controller.
     *
     * ── THE REQUEST IS SWAPPED, AND THAT IS THE WHOLE TRICK ─────────────────
     *
     * layouts/store.blade.php reads `request()->getPathInfo()` to decide
     * whether it is on the home page: the canonical URL, the SEO type and the
     * noindex decision all hang off it, and partials/mobile-chrome marks its
     * home tab from `request()->path()`. Rendered from an admin-api URL without
     * this, the preview would differ from the shop in its <head> and in one
     * highlighted tab on the phone bar — small, invisible in a picture, and
     * exactly the kind of drift that makes a preview not worth trusting.
     *
     * Swapped rather than patched, so the difference cannot exist rather than
     * being enumerated. The session and the user resolver are carried across
     * (the storefront layout reads both), the container's rebinding on
     * `request` re-points the URL generator by itself, and both are put back in
     * a finally — an admin request that rendered a preview and then answered
     * from the wrong Request object would be a defect in every other endpoint
     * on this controller.
     *
     * HomepagePreviewTest pins the outcome rather than the mechanism: a
     * preview of the CURRENT configuration is byte-identical to GET /, CSRF
     * token aside. If the swap ever stops being right, that is what goes red.
     */
    private function renderHome(HomepageSections $reader): string
    {
        $app = app();
        $live = $app->make('request');

        /*
         * THE SCHEME AND HOST ARE CARRIED ACROSS, AND THE DEFECT THAT MADE
         * THAT A LINE OF ITS OWN IS WORTH RECORDING.
         *
         * Url::to('/') answers a ROOT-RELATIVE url — that is what it is for,
         * and every internal link on the storefront uses it. Handed to
         * Request::create() on its own it produces a request for
         * `http://localhost/`, whatever host the console is really being served
         * from, and @vite then writes its <link> and <script> tags against THAT
         * root. Measured in Chromium against a shop on 127.0.0.1:8977: three
         * ERR_CONNECTION_REFUSED, `document.styleSheets` three short, and the
         * preview drew the homepage in Times New Roman on a white page. It is
         * invisible to a suite whose APP_URL and request host are both
         * `localhost`, which is why the test for it names a host of its own.
         */
        $asHome = Request::create($live->getSchemeAndHttpHost().Url::to('/'), 'GET');
        $asHome->setLaravelSession($live->session());
        $asHome->setUserResolver($live->getUserResolver());

        $app->instance(HomepageSections::class, $reader);
        $app->instance('request', $asHome);

        try {
            return $app->make(HomeController::class)()->render();
        } finally {
            $app->instance('request', $live);
            $app->forgetInstance(HomepageSections::class);
        }
    }

    /** Apply a layout preset, then hand back the resulting sections. */
    public function applyLayout(Request $request): JsonResponse
    {
        $data = $request->validate(['layout' => ['required', 'string', 'max:40']]);

        if (! $this->layouts->exists($data['layout'])) {
            return response()->json(['ok' => false, 'error' => 'Unknown layout.'], 422);
        }

        $this->layouts->apply($data['layout'], $this->sections);

        Cache::forget('kbb.home.rails');
        Cache::forget('kbb.home.brands');
        Shortcodes::flush();
        ShopController::flushSidebarCache();

        return response()->json([
            'ok' => true,
            'layout' => $this->layouts->current(),
            'sections' => array_values($this->sections->all()),
        ]);
    }

    /**
     * Appearance → Homepage content: the words, as opposed to the switches.
     *
     * A sibling endpoint under the SAME prefix rather than a new top-level one,
     * so `['*', 'admin-api/homepage/**', 'content.manage']` — already in
     * AdminCapabilities::RULES — governs it. A new prefix would need a new rule
     * and that map fails closed, which is a screen that 403s on a host with no
     * shell to fix it from.
     */
    public function content(): JsonResponse
    {
        return response()->json($this->content->payload());
    }

    public function saveContent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slides' => ['present', 'array', 'max:' . HomepageContent::MAX_SLIDES],
            'slides.*' => ['array'],
            'copy' => ['sometimes', 'array'],
        ]);

        $rejected = $this->content->saveSlides($data['slides']);

        $copy = $this->content->saveCopy($data['copy'] ?? []);

        foreach ($copy['rejected'] as $label) {
            $rejected[$label] = 'not a valid value';
        }

        /*
         * The homepage is cached, and so are its rails. Without this the owner
         * saves, reloads the shop, sees the old hero and concludes the screen
         * does not work — which is what the section endpoint above already
         * learned, in the comment beside its own forget() calls.
         */
        Cache::forget('kbb.home.rails');
        Cache::forget('kbb.home.brands');
        Shortcodes::flush();
        ShopController::flushSidebarCache();

        $payload = $this->content->payload();

        /*
         * `saved` counts what is STORED, read back off the payload, not what
         * was posted. A row that was not an array at all is skipped rather than
         * saved, and reporting the submitted count would tell the caller a
         * number the shop does not hold.
         */
        return response()->json([
            'ok' => true,
            'rejected' => $rejected,
            'saved' => count($payload['slides']),
        ] + $payload);
    }
}
