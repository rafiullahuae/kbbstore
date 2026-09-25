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
            if (! isset(HomepageSections::REGISTRY[$row['key']])) {
                return [null, response()->json(['ok' => false, 'error' => "Unknown section: {$row['key']}."], 422)];
            }

            $payload[$row['key']] = [
                'desktop' => $row['desktop'],
                'mobile' => $row['mobile'],
                'skin' => $row['skin'] ?? null,
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
