<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\Translation;
use App\Services\SettingsService;
use App\Services\Translation\InterfaceStrings;
use App\Services\Translation\MachineTranslationRunner;
use App\Services\Translation\TranslationCredentials;
use App\Services\Translation\TranslationEstimate;
use App\Services\Translation\TranslationProvider;
use App\Services\Translation\TranslationStore;
use App\Support\Locale;
use App\Support\TranslationConsole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Everything behind the admin's Translation menu.
 *
 * Inside the admin-api group, which already carries auth:admin and
 * NoStoreAdminApi. That is not a formality here: /translations/machine/run
 * SPENDS THE OWNER'S MONEY on a third-party API, and /settings writes an API
 * key. Mounted unauthenticated, the first is a way to bill a stranger's Google
 * account and the second is a way to read the key back.
 *
 * The screen these endpoints serve is built by the integrator against
 * resources/views/admin/app.blade.php, which no lane may edit. The shapes
 * below are the contract; docs/BILINGUAL-PLAN.md restates them in the order the
 * screen uses them.
 */
class TranslationsApiController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * GET /admin-api/translations/settings
     *
     * The two switches, the provider state, and the one-line summary the screen
     * opens with. Never returns the API key — only whether one is saved, the
     * same shape MailApiController uses for the SMTP password and for the same
     * reason.
     */
    public function settings(): JsonResponse
    {
        /** @var TranslationProvider $provider */
        $provider = app(TranslationProvider::class);

        $can = TranslationConsole::capabilities();

        return response()->json([
            'arabic_enabled' => (bool) $this->settings->get(Locale::SETTING_ENABLED, false),
            'rtl_enabled' => (bool) $this->settings->get(Locale::SETTING_RTL, false),
            'highlight_missing' => (bool) $this->settings->get('translation_highlight_missing', false),
            'provider' => $provider->name(),
            'provider_available' => $provider->available(),
            'has_api_key' => TranslationCredentials::hasKey(),
            'locales' => collect(Locale::codes())->map(fn (string $c): array => [
                'code' => $c,
                'name' => Locale::LOCALES[$c]['name'],
                'native' => Locale::LOCALES[$c]['native'],
                'dir' => Locale::LOCALES[$c]['dir'],
                'enabled' => Locale::enabled($c),
            ])->values()->all(),
            /*
             * The combination the owner asked to be allowed to reach, reported
             * rather than prevented. The screen prints this sentence under the
             * switches when it is true. He asked for the control; a control
             * that refuses the state you asked for is not a control.
             */
            'warning' => $this->warning(),

            /*
             * Everything below is said BY THE SERVER because the screen's job
             * is explaining state, and a sentence typed into the console is a
             * second copy of an answer that goes stale silently. See
             * App\Support\TranslationConsole.
             */
            'default_locale' => Locale::DEFAULT,
            'default_locale_name' => Locale::LOCALES[Locale::DEFAULT]['name'],
            'default_locale_note' => TranslationConsole::defaultLocaleNote(),
            'root_serves' => TranslationConsole::rootServes(
                (bool) $this->settings->get(Locale::SETTING_ENABLED, false),
            ),
            'provider_note' => TranslationConsole::providerNote($provider),

            /*
             * What THIS admin may do, so the screen can show a lever it cannot
             * move as a reading rather than as a button that will 403. Looked
             * up from AdminCapabilities by the endpoint's own method and path,
             * so it cannot drift from the rules that actually guard them.
             */
            'can' => $can,
            'capability_note' => TranslationConsole::capabilityNote($can),
        ]);
    }

    private function warning(): ?string
    {
        $arabic = (bool) $this->settings->get(Locale::SETTING_ENABLED, false);
        $rtl = (bool) $this->settings->get(Locale::SETTING_RTL, false);

        if ($arabic && ! $rtl) {
            return 'Arabic is live but the layout is still left-to-right. Arabic readers will see '
                .'their language in a layout that runs the wrong way. That is fine while the '
                .'mirrored layout is being finished and wrong once it is.';
        }

        if (! $arabic && $rtl) {
            return 'Right-to-left is on but Arabic is off, so nothing uses it. Nothing on the shop '
                .'changes until Arabic is switched on.';
        }

        return null;
    }

    /**
     * POST /admin-api/translations/settings
     *
     * {arabic_enabled?: bool, rtl_enabled?: bool, highlight_missing?: bool, api_key?: string}
     *
     * Each field is optional and only what is sent is written, so the screen
     * can flip one switch without resending the key — and a blank key means
     * "unchanged", not "delete", exactly as MailApiController treats the SMTP
     * password. The literal "-" clears it.
     */
    public function saveSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'arabic_enabled' => ['sometimes', 'boolean'],
            'rtl_enabled' => ['sometimes', 'boolean'],
            'highlight_missing' => ['sometimes', 'boolean'],
            'api_key' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        foreach ([
            'arabic_enabled' => Locale::SETTING_ENABLED,
            'rtl_enabled' => Locale::SETTING_RTL,
            'highlight_missing' => 'translation_highlight_missing',
        ] as $field => $key) {
            if (array_key_exists($field, $data)) {
                Setting::query()->updateOrCreate(['key' => $key], [
                    'value' => $data[$field] ? '1' : '0',
                    'autoload' => true,
                ]);
            }
        }

        if (array_key_exists('api_key', $data)) {
            $key = trim((string) ($data['api_key'] ?? ''));

            if ($key === '-') {
                TranslationCredentials::saveApiKey(null);
            } elseif ($key !== '') {
                TranslationCredentials::saveApiKey($key);
            }
        }

        Setting::flushMap();
        $this->settings->flush();
        TranslationStore::flush();

        return $this->settings();
    }

    /**
     * GET /admin-api/translations/progress?locale=ar
     *
     * Per area, counted rather than claimed. See TranslationEstimate::progress.
     */
    public function progress(Request $request): JsonResponse
    {
        return response()->json(TranslationEstimate::progress($this->locale($request)));
    }

    /**
     * GET /admin-api/translations/estimate?locale=ar&limit=&group=
     *
     * The character count and the cost, taken BEFORE anything is spent. This
     * endpoint calls no external service and costs nothing to run.
     *
     * ── THE `run` BLOCK, AND WHY THE WHOLE-SHOP FIGURE IS NOT ENOUGH ───────
     *
     * The totals above describe THE WHOLE SHOP. The button on the screen
     * translates a batch: one group, at most `limit` fields, and only the
     * fields a machine should be sent at all — isMachineSafe() drops every
     * product description, because they are HTML and both of the provider's
     * format options mangle it. Those are three different reasons for the two
     * numbers to differ, and they differ by most of the catalogue.
     *
     * machineRun() requires `confirm_characters` to match what it is about to
     * send, within a tolerance, so that "I saw the number before I pressed it"
     * is true rather than claimed. Showing the whole-shop total beside that
     * button and posting it would fail that check on every press — and the
     * screen would be showing a figure that was never the price of the thing
     * the button does. `run.confirm_characters` below is counted from the same
     * pending set machineRun() will re-count, through the same method, so the
     * number on the screen is the number that gets authorised.
     *
     * It costs no money: pending() reads the database and never touches the
     * provider, which is why it is safe to call here with no key configured.
     * It costs some time — a cursor over the content tables — but it stops the
     * moment it has `limit` fields, which on a shop that has barely started is
     * a few hundred interface strings and no catalogue read at all. The screen
     * asks for it when it is opened and when a selector changes, and never on a
     * timer; the polled figures are progress(), which is seven aggregates.
     */
    public function estimate(Request $request): JsonResponse
    {
        /** @var TranslationProvider $provider */
        $provider = app(TranslationProvider::class);

        $locale = $this->locale($request);
        $estimate = TranslationEstimate::forLocale($locale);

        $limit = min(2000, max(1, (int) $request->query('limit', 100)));
        $group = trim((string) $request->query('group', ''));
        $group = $group === '' ? null : TranslationStore::normaliseKey($group);

        $pending = (new MachineTranslationRunner($provider))->pending($locale, $limit, $group);

        $runCharacters = array_sum(array_map(
            static fn (array $slot): int => mb_strlen($slot['english'], 'UTF-8'),
            $pending,
        ));

        $runUsd = round($runCharacters / 1_000_000 * TranslationEstimate::USD_PER_MILLION, 2);

        return response()->json($estimate + [
            'provider' => $provider->name(),
            'provider_available' => $provider->available(),
            'provider_note' => TranslationConsole::providerNote($provider),
            'usd_per_million' => TranslationEstimate::USD_PER_MILLION,
            /*
             * THE CURRENCY, SPELLED OUT, and rendered here rather than by the
             * screen. This figure is Google's USD list price; this shop's own
             * money is dirhams, and whole dirhams from this cycle onward. A
             * console that formatted it would sooner or later format it the way
             * it formats every other number on the site and print a dirham sign
             * on a dollar amount. App\Support\Money is deliberately not
             * involved — see TranslationConsole::costDisplay().
             */
            'currency' => TranslationConsole::COST_CURRENCY,
            'usd_display' => TranslationConsole::costDisplay((float) $estimate['usd']),
            'free_tier_note' => TranslationConsole::freeTierNote(),
            'can' => TranslationConsole::capabilities(),
            'note' => 'An estimate. Your Google Cloud console is the authority on what you are '
                .'actually billed and on how much of this month\'s free allowance is left.',

            /*
             * What the BUTTON will do, as opposed to what the shop still needs.
             * confirm_characters is the value machineRun() checks against, so
             * the figure printed beside the button is the figure that is
             * authorised by pressing it.
             */
            'run' => [
                'limit' => $limit,
                'group' => $group,
                'fields' => count($pending),
                'characters' => $runCharacters,
                'confirm_characters' => $runCharacters,
                'usd' => $runUsd,
                'usd_display' => TranslationConsole::costDisplay($runUsd),
            ],
        ]);
    }

    /**
     * GET /admin-api/translations?locale=ar&group=ui&status=&missing=1&q=&limit=
     *
     * The editing list. Interface strings come from code plus rows; content
     * rows come from the table. Both carry their English source, because
     * translating a string you cannot see the original of is guesswork.
     */
    public function index(Request $request): JsonResponse
    {
        $locale = $this->locale($request);
        $group = TranslationStore::normaliseKey((string) $request->query('group', Translation::GROUP_UI));
        $limit = min(500, max(1, (int) $request->query('limit', 200)));
        $needle = trim((string) $request->query('q', ''));
        $missingOnly = $request->boolean('missing');

        $rows = [];

        if ($group === Translation::GROUP_UI) {
            $existing = Translation::query()
                ->where('locale', $locale)
                ->where('group', Translation::GROUP_UI)
                ->get()
                ->keyBy(fn (Translation $t): string => (string) $t->field);

            foreach (InterfaceStrings::flat() as $key => $english) {
                $row = $existing->get($key);

                if ($missingOnly && $row !== null && (string) $row->value !== '') {
                    continue;
                }

                if ($needle !== '' && ! str_contains(mb_strtolower($key.' '.$english), mb_strtolower($needle))) {
                    continue;
                }

                $rows[] = [
                    'group' => Translation::GROUP_UI,
                    'item_id' => 0,
                    'field' => $key,
                    'english' => $english,
                    'value' => (string) ($row->value ?? ''),
                    'status' => (string) ($row->status ?? ''),
                    'source' => (string) ($row->source ?? ''),
                    'stale' => $row !== null && $row->isStaleAgainst($english),
                ];

                if (count($rows) >= $limit) {
                    break;
                }
            }

            return response()->json(['locale' => $locale, 'group' => $group, 'rows' => $rows]);
        }

        $class = $this->contentClassFor($group);

        if ($class === null) {
            return response()->json(['message' => 'Unknown translation group.'], 422);
        }

        /** @var \Illuminate\Database\Eloquent\Model $prototype */
        $prototype = new $class;
        $columns = TranslationEstimate::CONTENT[$class];

        $existing = Translation::query()
            ->where('locale', $locale)
            ->where('group', $group)
            ->get()
            ->groupBy('item_id');

        $query = $class::query()->orderBy($prototype->getKeyName());

        if ($needle !== '') {
            $query->where($columns[0], 'like', '%'.$needle.'%');
        }

        foreach ($query->limit($limit)->get() as $model) {
            $forRow = $existing->get((int) $model->getKey()) ?? collect();

            foreach ($columns as $column) {
                $english = $model->getAttribute($column);

                if (! is_string($english) || trim($english) === '') {
                    continue;
                }

                $row = $forRow->firstWhere('field', $column);

                if ($missingOnly && $row !== null && (string) $row->value !== '') {
                    continue;
                }

                $rows[] = [
                    'group' => $group,
                    'item_id' => (int) $model->getKey(),
                    'field' => $column,
                    'english' => $english,
                    'value' => (string) ($row->value ?? ''),
                    'status' => (string) ($row->status ?? ''),
                    'source' => (string) ($row->source ?? ''),
                    'stale' => $row !== null && $row->isStaleAgainst($english),
                    'machine_safe' => MachineTranslationRunner::isMachineSafe($english),
                ];
            }
        }

        return response()->json(['locale' => $locale, 'group' => $group, 'rows' => $rows]);
    }

    /**
     * POST /admin-api/translations
     *
     * {locale, group, item_id, field, value}
     *
     * One string, typed by the owner, published immediately. A BLANK VALUE
     * DELETES THE ROW — blank means "not translated yet" and never means "same
     * as English", which is the only reason the progress figures can be
     * trusted. See App\Support\HasTranslations::saveTranslations.
     *
     * This endpoint is the standalone screen's writer. The product, post,
     * category, brand, page and menu editors do NOT come through here: they
     * carry their Arabic boxes in their own forms and save through
     * saveTranslations() in the same request as the English row.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', 'in:'.implode(',', Locale::codes())],
            'group' => ['required', 'string', 'max:32'],
            'item_id' => ['sometimes', 'integer', 'min:0'],
            'field' => ['required', 'string', 'max:64'],
            'value' => ['nullable', 'string', 'max:65000'],
        ]);

        $group = TranslationStore::normaliseKey($data['group']);
        $itemId = (int) ($data['item_id'] ?? 0);
        $field = TranslationStore::normaliseKey($data['field']);
        $value = trim((string) ($data['value'] ?? ''));

        $english = $this->englishFor($group, $itemId, $field);

        if ($english === null) {
            return response()->json(['message' => 'There is no English string at that key.'], 422);
        }

        if ($value === '') {
            foreach (Translation::query()
                ->where('locale', $data['locale'])->where('group', $group)
                ->where('item_id', $itemId)->where('field', $field)->get() as $row) {
                $row->delete();
            }

            return response()->json(['ok' => true, 'value' => '', 'status' => '']);
        }

        $row = TranslationStore::put(
            $data['locale'], $group, $itemId, $field, $value,
            Translation::STATUS_PUBLISHED, Translation::SOURCE_MANUAL, $english,
        );

        return response()->json([
            'ok' => true,
            'value' => (string) $row->value,
            'status' => (string) $row->status,
        ]);
    }

    /**
     * POST /admin-api/translations/publish
     *
     * {locale, group?, item_id?, field?}  — one draft, or every draft in a group.
     *
     * The approve half of the draft/approve cycle: nothing a machine wrote is
     * visible to a shopper until this has been called on it.
     */
    public function publish(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', 'in:'.implode(',', Locale::codes())],
            'group' => ['sometimes', 'string', 'max:32'],
            'item_id' => ['sometimes', 'integer', 'min:0'],
            'field' => ['sometimes', 'string', 'max:64'],
        ]);

        $query = Translation::query()
            ->where('locale', $data['locale'])
            ->where('status', Translation::STATUS_DRAFT);

        if (isset($data['group'])) {
            $query->where('group', TranslationStore::normaliseKey($data['group']));
        }

        if (isset($data['item_id'])) {
            $query->where('item_id', (int) $data['item_id']);
        }

        if (isset($data['field'])) {
            $query->where('field', TranslationStore::normaliseKey($data['field']));
        }

        $published = 0;

        // One at a time so each model's saved hook fires and the cache is
        // evicted. A builder-level update() fires no model events, and the
        // approved translations would stay invisible for the cache's lifetime.
        foreach ($query->get() as $row) {
            $row->status = Translation::STATUS_PUBLISHED;
            $row->reviewed_at = now();
            $row->save();
            $published++;
        }

        return response()->json(['ok' => true, 'published' => $published]);
    }

    /**
     * POST /admin-api/translations/machine/field
     *
     * {text, locale} → {translation}
     *
     * The per-field Translate button beside each Arabic box. Stores NOTHING:
     * the text lands in the box, the owner reads it and edits it, and it is
     * saved by the same ordinary form save as everything else on the screen.
     */
    public function translateField(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:65000'],
            'locale' => ['required', 'string', 'in:'.implode(',', Locale::codes())],
        ]);

        /** @var TranslationProvider $provider */
        $provider = app(TranslationProvider::class);

        if (! $provider->available()) {
            return response()->json([
                'message' => 'No translation service is connected. Add your own API key in '
                    .'Translation → Language settings, or type the Arabic in by hand — that never needs a key.',
            ], 409);
        }

        try {
            $translation = (new MachineTranslationRunner($provider))->one($data['text'], $data['locale']);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json([
            'translation' => $translation,
            'characters' => mb_strlen($data['text'], 'UTF-8'),
        ]);
    }

    /**
     * POST /admin-api/translations/machine/run
     *
     * {locale, limit?, group?, confirm_characters}
     *
     * THE ONLY ENDPOINT IN THIS APPLICATION THAT SPENDS MONEY.
     *
     * confirm_characters is required and must match what the estimate most
     * recently reported, within a tolerance. That is not ceremony: it is what
     * makes "I saw the number before I pressed it" true rather than claimed. A
     * stale tab that opened the estimate an hour ago, when there were 40,000
     * characters outstanding, must not be able to authorise a 400,000-character
     * run because somebody imported a catalogue in between.
     */
    public function machineRun(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', 'in:'.implode(',', Locale::codes())],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:2000'],
            'group' => ['sometimes', 'nullable', 'string', 'max:32'],
            'confirm_characters' => ['required', 'integer', 'min:0'],
        ]);

        /** @var TranslationProvider $provider */
        $provider = app(TranslationProvider::class);

        if (! $provider->available()) {
            return response()->json([
                'message' => 'No translation service is connected. Add your own API key in '
                    .'Translation → Language settings. Typing the Arabic in by hand needs no key and costs nothing.',
            ], 409);
        }

        $runner = new MachineTranslationRunner($provider);

        $limit = (int) ($data['limit'] ?? 100);
        $group = isset($data['group']) && $data['group'] !== null
            ? TranslationStore::normaliseKey((string) $data['group'])
            : null;

        $pending = $runner->pending($data['locale'], $limit, $group);

        $characters = array_sum(array_map(
            static fn (array $slot): int => mb_strlen($slot['english'], 'UTF-8'),
            $pending,
        ));

        if (abs($characters - (int) $data['confirm_characters']) > max(50, (int) round($characters * 0.02))) {
            return response()->json([
                'message' => 'The amount of work changed since you were shown the estimate — it is now '
                    .number_format($characters).' characters, not '
                    .number_format((int) $data['confirm_characters'])
                    .'. Look at the new figure and press again.',
                'characters' => $characters,
            ], 409);
        }

        try {
            $result = $runner->run($data['locale'], $limit, $group);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json($result + [
            'note' => 'Everything translated is a DRAFT. Nothing above is visible to a shopper '
                .'until you read it and press Approve.',
        ]);
    }

    private function locale(Request $request): string
    {
        $locale = (string) $request->query('locale', 'ar');

        return Locale::isSupported($locale) ? $locale : Locale::DEFAULT;
    }

    /** @return class-string|null */
    private function contentClassFor(string $group): ?string
    {
        foreach (TranslationEstimate::CONTENT as $class => $columns) {
            if ((new $class)->getTable() === $group) {
                return $class;
            }
        }

        return null;
    }

    /**
     * The English a translation claims to be of, or null if there is none.
     *
     * A guard and not a convenience: without it this endpoint writes a row at
     * any (group, item_id, field) a caller names — an unbounded, admin-writable
     * key-value store that the storefront reads and renders. Requiring the
     * English to exist keeps the table a translation OF something.
     */
    private function englishFor(string $group, int $itemId, string $field): ?string
    {
        if ($group === Translation::GROUP_UI) {
            return InterfaceStrings::english($field);
        }

        $class = $this->contentClassFor($group);

        if ($class === null || ! in_array($field, TranslationEstimate::CONTENT[$class], true)) {
            return null;
        }

        $model = $class::query()->find($itemId);
        $english = $model?->getAttribute($field);

        return is_string($english) && trim($english) !== '' ? $english : null;
    }
}
