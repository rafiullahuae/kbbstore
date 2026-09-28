<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BannerCard;
use App\Models\BannerSet;
use App\Services\Banners;
use App\Services\ModuleSchema;
use App\Services\SettingsService;
use App\Support\MediaRegistrar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Appearance → Banners → Cards banner. Every endpoint the screen has.
 *
 * Phase 22, Lane BN.
 *
 * ── WHAT IS VALIDATED, AND WHERE ────────────────────────────────────────────
 *
 * Rule 5's "a select stores one of its own options or the default" is enforced
 * here with `Rule::in(array_keys(...))` over the enums BannerSet owns, and the
 * template enforces it a SECOND time by looking the stored token up in the same
 * constant and falling back to the default. Two doors for one value is not
 * belt-and-braces: the storefront is the one that matters and it is the one
 * furthest from this validator, so it does not get to assume the validator ran.
 *
 * Every numeric control is CLAMPED rather than refused — `between` on a slider
 * that the screen also clamps would turn a rounding difference into a 422 the
 * owner cannot act on — and the bounds are BannerSet::LIMITS, read here rather
 * than retyped.
 *
 * ── AND THE IMAGE GOES INTO THE MEDIA LIBRARY, ON WRITE ─────────────────────
 *
 * The owner's standing rule, in Lane MB's words: "whenever we upload any media,
 * it should go to Media also". The picker this screen uses (window.kbbPickMedia)
 * reaches images through POST /admin-api/media/upload, which already registers —
 * so the common path is covered before this controller is reached. This calls
 * `MediaRegistrar::record()` ANYWAY, on every card write, for the path that is
 * not covered: a path put into the box by an import, a duplicate, or a later
 * screen that writes a card without going through the picker. It is idempotent
 * by path and returns the existing row untouched, so the common case costs one
 * indexed SELECT and changes nothing.
 *
 * The same call is where `image_w`/`image_h` come from. Reading the file header
 * at RENDER time would put a disk read inside the homepage; reading it once here
 * puts the explicit width and height the LCP image needs on the row.
 */
class BannerApiController extends Controller
{
    public function __construct(private Banners $banners, private SettingsService $settings) {}

    /* ─────────────────────────────── the screen ─────────────────────────── */

    /**
     * The whole screen in one payload: the module switch, its one setting, the
     * sets, and the enums the controls are drawn from.
     *
     * The enums are SENT rather than repeated in the screen's JavaScript,
     * because they are facts about the application and a second copy of a fact
     * is a copy that goes stale — the same argument CartPanelApiController makes
     * for its touch targets.
     */
    public function show(): JsonResponse
    {
        return response()->json([
            'moduleOn' => $this->banners->enabled(),
            'tabs' => ModuleSchema::tabs(
                Banners::SCHEMA,
                Banners::TABS,
                $this->banners->all(),
                Banners::POLICY,
                Banners::overrides(),
            ),
            /*
             * ── THE PICKER'S OPTIONS ARE A TOP-LEVEL KEY, NOT THE FIELD'S ───
             *
             * `ModuleSchema::fields()` emits `options` from the module's OWN
             * SCHEMA and deliberately NOT from `overrides()` — its docblock says
             * so at length, and the two screens in the same position
             * (ProductStyles' card style, SectionDividers' section picker) each
             * draw their own picker from a separate top-level key for exactly
             * this reason.
             *
             * THE DEFECT THIS FIXES, AND IT WAS IN THE FIRST SCREENSHOT: the
             * screen drew its select straight from `tabs[].fields[].options`,
             * which held only the schema's literal `{'': 'None'}`. So a shop
             * with a published set offered "None" as the only choice, and the
             * set the homepage was ALREADY showing could not be seen in the
             * control that chooses it. The admin screenshot caught it — the row
             * above said "On the homepage" while the select said "None".
             */
            'setOptions' => self::overridesOptions(),
            'sets' => BannerSet::query()->withCount('cards')
                ->orderBy('position')->orderBy('id')->get()
                ->map(fn (BannerSet $s) => $this->setPayload($s))->all(),
            'enums' => [
                'ratios' => self::labels(BannerSet::RATIOS),
                'animations' => self::labels(BannerSet::ANIMATIONS),
                'shadows' => self::labels(BannerSet::SHADOWS),
                'statuses' => BannerSet::STATUSES,
                'limits' => BannerSet::LIMITS,
            ],
        ]);
    }

    /** Save the one module setting: which set the homepage draws. */
    public function save(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => ['required', 'array']]);

        $unknown = array_diff(array_keys($data['settings']), array_keys(Banners::SCHEMA));

        if ($unknown !== []) {
            return response()->json(['ok' => false, 'error' => 'Unknown setting: '.implode(', ', $unknown)], 422);
        }

        $result = $this->banners->save($data['settings']);

        return response()->json(['ok' => true] + $result);
    }

    /** The module's own on/off, which lives in `module_toggles`. */
    public function toggle(Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        $this->settings->setModule('cards_banner', (bool) $data['enabled']);

        return response()->json(['ok' => true, 'moduleOn' => (bool) $data['enabled']]);
    }

    /* ──────────────────────────────── the sets ──────────────────────────── */

    public function showSet(BannerSet $set): JsonResponse
    {
        return response()->json([
            'set' => $this->setPayload($set),
            'cards' => $set->cards()->get()->map(fn (BannerCard $c) => $this->cardPayload($c))->all(),
        ]);
    }

    public function createSet(Request $request): JsonResponse
    {
        $name = trim((string) $request->input('name', ''));
        $name = $name === '' ? 'Cards banner' : Str::limit($name, 180, '');

        $set = BannerSet::create([
            'name' => $name,
            'slug' => $this->uniqueSlug($name),
            'status' => 'draft',
            'position' => (int) (BannerSet::query()->max('position') ?? 0) + 1,
        ]);

        /*
         * refresh() and not the in-memory model: every control on this table
         * takes its shipped value from a DATABASE DEFAULT, so a model built by
         * create() carries null for each of them until it has been read back.
         * Sent unrefreshed, the screen drew a brand-new set with every switch
         * blank and every slider at zero — which is not what the shop would
         * draw, and is the first thing an owner sees.
         */
        return response()->json(['ok' => true, 'set' => $this->setPayload($set->refresh())], 201);
    }

    public function updateSet(Request $request, BannerSet $set): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:180'],
            'status' => ['sometimes', Rule::in(array_keys(BannerSet::STATUSES))],
            'animation' => ['sometimes', Rule::in(array_keys(BannerSet::ANIMATIONS))],
            'ratio' => ['sometimes', Rule::in(array_keys(BannerSet::RATIOS))],
            'shadow' => ['sometimes', Rule::in(array_keys(BannerSet::SHADOWS))],
            'autoplay' => ['sometimes', 'boolean'],
            'show_arrows' => ['sometimes', 'boolean'],
            'show_dots' => ['sometimes', 'boolean'],
            'pause_on_hover' => ['sometimes', 'boolean'],
            'show_text' => ['sometimes', 'boolean'],
            'show_button' => ['sometimes', 'boolean'],
            'speed_ms' => ['sometimes', 'integer'],
            'per_view' => ['sometimes', 'integer'],
            'peek' => ['sometimes', 'integer'],
            'gap' => ['sometimes', 'integer'],
            'card_radius' => ['sometimes', 'integer'],
            'position' => ['sometimes', 'integer'],
        ]);

        foreach (BannerSet::LIMITS as $column => [$min, $max]) {
            if (array_key_exists($column, $data)) {
                $data[$column] = max($min, min($max, (int) $data[$column]));
            }
        }

        if (array_key_exists('name', $data)) {
            $data['name'] = trim($data['name']) === '' ? $set->name : trim($data['name']);

            if ($data['name'] !== $set->name) {
                $data['slug'] = $this->uniqueSlug($data['name'], $set->id);
            }
        }

        $set->fill($data)->save();

        return response()->json(['ok' => true, 'set' => $this->setPayload($set->fresh())]);
    }

    /**
     * Copy a set and its cards.
     *
     * The copy is always a DRAFT whatever the original was, so duplicating the
     * set the homepage is showing cannot produce a second publishable row that
     * the owner then edits believing it is the live one.
     */
    public function duplicateSet(BannerSet $set): JsonResponse
    {
        $copy = BannerSet::create(
            collect($set->getAttributes())
                ->except(['id', 'created_at', 'updated_at', 'name', 'slug', 'status', 'position'])
                ->all()
            + [
                'name' => Str::limit($set->name.' copy', 180, ''),
                'slug' => $this->uniqueSlug($set->name.' copy'),
                'status' => 'draft',
                'position' => (int) (BannerSet::query()->max('position') ?? 0) + 1,
            ]
        );

        foreach ($set->cards()->get() as $card) {
            $attributes = collect($card->getAttributes())
                ->except(['id', 'created_at', 'updated_at', 'banner_set_id'])
                ->all();

            $copy->cards()->create($attributes + ['banner_set_id' => $copy->id]);
        }

        return response()->json(['ok' => true, 'set' => $this->setPayload($copy->fresh())], 201);
    }

    /**
     * Delete a set, its cards with it, and the homepage's pointer at it.
     *
     * ── THE POINTER IS THE HALF THAT IS EASY TO FORGET ──────────────────────
     *
     * `module_settings.set` would go on naming a row that no longer exists.
     * Banners::forHome() already draws nothing for a missing id — the join
     * returns no rows — so the SHOP is correct either way. The SCREEN is not:
     * the select would show "None" while the stored value said 7, and the next
     * save of an unrelated control would either resurrect the stale id or
     * silently rewrite it. Clearing it here makes the stored value and the drawn
     * value the same thing.
     */
    public function destroySet(BannerSet $set): JsonResponse
    {
        $chosen = (string) ($this->settings->moduleSetting(Banners::MODULE, 'set', '') ?? '');

        $set->delete();

        if ($chosen === (string) $set->id) {
            $this->settings->setModuleSetting(Banners::MODULE, 'set', '');
        }

        return response()->json(['ok' => true]);
    }

    /* ─────────────────────────────── the cards ──────────────────────────── */

    public function createCard(Request $request, BannerSet $set): JsonResponse
    {
        $card = $set->cards()->create([
            'position' => (int) ($set->cards()->max('position') ?? 0) + 1,
            'status' => 'publish',
        ]);

        return $this->applyCard($request, $card, 201);
    }

    public function updateCard(Request $request, BannerCard $card): JsonResponse
    {
        return $this->applyCard($request, $card);
    }

    public function destroyCard(BannerCard $card): JsonResponse
    {
        $card->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * The one writer for a card, used by create and by update.
     *
     * `image` is the only field that does more than store what arrived: it is
     * normalised through MediaRegistrar — which refuses a scheme, a host, a
     * traversal and anything outside the uploads roots — and the row it returns
     * supplies the dimensions. A path the registrar will not accept stores the
     * EMPTY STRING rather than the operator's text, so `banner_cards.image`
     * cannot hold a URL and the storefront's `<img src>` is always a path this
     * shop wrote.
     */
    private function applyCard(Request $request, BannerCard $card, int $status = 200): JsonResponse
    {
        $data = $request->validate([
            'image' => ['sometimes', 'nullable', 'string', 'max:400'],
            'alt' => ['sometimes', 'nullable', 'string', 'max:255'],
            'heading' => ['sometimes', 'nullable', 'string', 'max:190'],
            'body' => ['sometimes', 'nullable', 'string', 'max:255'],
            'button_label' => ['sometimes', 'nullable', 'string', 'max:80'],
            'button_url' => ['sometimes', 'nullable', 'string', 'max:400'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'status' => ['sometimes', Rule::in(array_keys(BannerCard::STATUSES))],
        ]);

        foreach (['alt', 'heading', 'body', 'button_label', 'button_url'] as $text) {
            if (array_key_exists($text, $data)) {
                $card->{$text} = trim((string) $data[$text]);
            }
        }

        if (array_key_exists('position', $data)) {
            $card->position = (int) $data['position'];
        }

        if (array_key_exists('status', $data)) {
            $card->status = (string) $data['status'];
        }

        if (array_key_exists('image', $data)) {
            $path = self::storedPath((string) $data['image']);

            if ($path === null) {
                $card->image = '';
                $card->image_w = null;
                $card->image_h = null;
            } else {
                $card->image = $path;

                $media = MediaRegistrar::record($path);

                $card->image_w = $media?->width === null ? null : (int) $media->width;
                $card->image_h = $media?->height === null ? null : (int) $media->height;
            }
        }

        $card->save();

        return response()->json(['ok' => true, 'card' => $this->cardPayload($card->fresh())], $status);
    }

    /**
     * The stored path a picker's answer names, or null.
     *
     * ── WHY THIS EXISTS AT ALL ──────────────────────────────────────────────
     *
     * `window.kbbPickMedia` hands its caller URLs — its own docblock says so in
     * as many words, "onPick receives an array of urls, always" — and every
     * other screen in this console stores exactly that string. This column does
     * not: it stores the root-relative path, because that is what
     * `MediaRegistrar` catalogues, what `media`.path holds, and what lets the
     * row carry the picture's real width and height for the LCP image.
     *
     * So the URL is cut down here, once, rather than in the screen's JavaScript
     * where a second copy of this rule would go stale — and the result is still
     * handed to `MediaRegistrar::normalise()`, which is the allowlist. THIS
     * METHOD WIDENS NOTHING: it only finds the root inside a longer string, and
     * every refusal normalise() makes — traversal, backslashes, NUL, a path
     * outside the two upload roots, a scheme that survived the cut — still
     * applies to what comes out of it.
     *
     * The cut is `strpos`-from-the-root, which is MediaSideloader::targetPath()'s
     * own move for the same job, and the roots are read out of MediaRegistrar
     * rather than retyped so the two cannot disagree. Longest first, because
     * `wp-content/uploads/` CONTAINS `uploads/` and cutting at the shorter one
     * first would store a path missing its own root.
     */
    private static function storedPath(string $raw): ?string
    {
        $value = trim($raw);

        if ($value === '') {
            return null;
        }

        // A URL: keep the path and drop the scheme, the host, the query and the
        // fragment. parse_url() returns false for something it cannot read at
        // all, which is refused rather than guessed at.
        if (preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $value) === 1) {
            $path = parse_url($value, PHP_URL_PATH);

            if (! is_string($path) || $path === '') {
                return null;
            }

            $value = rawurldecode($path);

            /*
             * ── THE SEARCH HAPPENS ONLY FOR A URL, AND THAT IS THE WHOLE CARE
             *    IN THIS METHOD ─────────────────────────────────────────────
             *
             * A URL's path carries the site's own base path in front of the
             * root — `/kbb-upgrade/uploads/banners/x.webp` — so the root has to
             * be found INSIDE the string rather than at the front of it.
             *
             * A value that is already a path does NOT get that treatment, and
             * must not: `MediaRegistrar::normalise()`'s own note explains why
             * it tests the prefix rather than searching — "anything that is not
             * AT the front is not a root, and `etc/wp-content/uploads/x.jpg` is
             * refused rather than silently cut down to the part that looks
             * safe". Searching a bare path here would undo exactly that, and it
             * did: that string round-tripped into the column until this branch
             * was narrowed to the URL case. CardsBannerAdminTest names it.
             */
            foreach (MediaRegistrar::ROOTS as $root) {
                $at = strpos($value, $root);

                if ($at !== false) {
                    $value = substr($value, $at);

                    break;
                }
            }
        }

        return MediaRegistrar::normalise($value);
    }

    /* ─────────────────────────────── the preview ────────────────────────── */

    /**
     * The row exactly as the homepage draws it, as HTML.
     *
     * ── THE SAME PARTIAL, NOT A SECOND DRAWING OF IT ────────────────────────
     *
     * Every other Appearance screen in this console has a live preview and the
     * owner uses them daily, so this one has to have one too — and the only
     * preview worth having is the template itself. A second copy of the markup
     * in the screen's JavaScript is a copy that disagrees with the shop the
     * first time either is touched, which is the fault
     * HomepageLayouts::summaries() shipped and settleKeys() was split out to
     * end.
     *
     * It reads through Banners::forPreview(), which is forHome()'s own query
     * with one difference — a DRAFT set is drawn, because a draft is precisely
     * what the owner is looking at while he builds it.
     */
    public function preview(BannerSet $set): JsonResponse
    {
        $loaded = $this->banners->forPreview($set->id);

        if ($loaded === null) {
            return response()->json(['ok' => true, 'html' => '', 'empty' => true]);
        }

        return response()->json([
            'ok' => true,
            'empty' => false,
            'html' => view('partials.home.cards-banner', ['set' => $loaded[0], 'cards' => $loaded[1]])->render(),
        ]);
    }

    /* ──────────────────────────────── helpers ───────────────────────────── */

    /**
     * What a set looks like on the wire.
     *
     * AN EXPLICIT LIST AND NOT `$set->toArray()`. This endpoint is behind
     * `auth:admin`, so this is not the /api/* leak CLAUDE.md catalogues — but
     * the habit is the point: a column added to `banner_sets` next year should
     * reach a screen because somebody put it here, not because a serialiser
     * swept it up.
     *
     * @return array<string, mixed>
     */
    private function setPayload(BannerSet $set): array
    {
        return [
            'id' => $set->id,
            'name' => $set->name,
            'slug' => $set->slug,
            'status' => $set->status,
            'position' => $set->position,
            'autoplay' => $set->autoplay,
            'speed_ms' => $set->speed_ms,
            'animation' => $set->animation,
            'per_view' => $set->per_view,
            'peek' => $set->peek,
            'gap' => $set->gap,
            'card_radius' => $set->card_radius,
            'show_arrows' => $set->show_arrows,
            'show_dots' => $set->show_dots,
            'pause_on_hover' => $set->pause_on_hover,
            'ratio' => $set->ratio,
            'show_text' => $set->show_text,
            'show_button' => $set->show_button,
            'shadow' => $set->shadow,
            'cards_count' => $set->cards_count ?? $set->cards()->count(),
        ];
    }

    /**
     * The sets the picker may offer, `id => label`.
     *
     * One call to Banners::overrides(), which is the same method the cast holds
     * the posted value to — so the screen cannot offer an option the save would
     * refuse, and cannot hide one the save would accept.
     *
     * @return array<string, string>
     */
    private static function overridesOptions(): array
    {
        return Banners::overrides()['set']['options'];
    }

    /** @return array<string, mixed> */
    private function cardPayload(BannerCard $card): array
    {
        return [
            'id' => $card->id,
            'banner_set_id' => $card->banner_set_id,
            'image' => $card->image,
            'image_url' => $card->image === '' ? '' : Banners::imageUrl($card->image),
            'image_w' => $card->image_w,
            'image_h' => $card->image_h,
            'alt' => $card->alt,
            'heading' => $card->heading,
            'body' => $card->body,
            'button_label' => $card->button_label,
            'button_url' => $card->button_url,
            /*
             * What the SHOP will do with the URL, computed by the same method
             * the template calls. A refused scheme draws no button at all, and
             * the owner should be told that on the screen rather than discover
             * it by looking at the homepage.
             */
            'button_url_safe' => Banners::safeUrl($card->button_url) !== '',
            'position' => $card->position,
            'status' => $card->status,
        ];
    }

    /**
     * `token => label` out of an enum whose values are `[label, css]`.
     *
     * @param  array<string, array{0: string, 1: string}>  $enum
     * @return array<string, string>
     */
    private static function labels(array $enum): array
    {
        return array_map(static fn (array $row): string => $row[0], $enum);
    }

    /**
     * A slug nothing else is using.
     *
     * Suffixed rather than refused: renaming two sets to the same words is a
     * thing an owner does and is not an error he should have to solve. The loop
     * is bounded by the number of rows that can share a base, which is the
     * number of sets — there is no unbounded retry here.
     */
    private function uniqueSlug(string $name, ?int $ignore = null): string
    {
        $base = Str::slug($name) ?: 'cards-banner';
        $slug = $base;
        $n = 1;

        while (BannerSet::query()
            ->where('slug', $slug)
            ->when($ignore !== null, fn ($q) => $q->where('id', '<>', $ignore))
            ->exists()) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }
}
