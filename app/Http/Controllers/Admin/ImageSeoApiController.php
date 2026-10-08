<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\ImageSeo\AltText;
use App\Services\ImageSeo\ImageNamer;
use App\Services\ImageSeo\ImageScore;
use App\Services\ImageSeo\ImageSeo;
use App\Services\ImageSeo\ImageSeoJobs;
use App\Services\ImageSeo\ImageSeoPlanner;
use App\Services\ImageSeo\ImageSeoSelection;
use App\Services\SecurityModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Catalog → Image SEO. (Lane IR)
 *
 * Behind `media.image_seo` (owner and manager) through AdminCapabilities, which
 * fails closed: a path under admin-api/image-seo that the map does not name is
 * owner-only. Every write is a POST through the admin-api group's CSRF and
 * guard, every value is validated against a closed shape, and no request value
 * ever becomes a path: new names are generated server-side by ImageNamer,
 * checked against a strict slug pattern, and refused unless they stay in the
 * same upload folder (ImageRenamer::guard()).
 *
 * FLAT PATHS, NO ROUTE PARAMETERS — the rule urls-media-admin.php and
 * media-sideload-admin.php state: the job id arrives in the body or query and
 * is cast to an int.
 */
final class ImageSeoApiController extends Controller
{
    private const AUDIT = 'image_seo';

    /** Held while a Start is decided, so two Starts cannot both find the shop idle. */
    private const START_LOCK = 'kbb.image-seo.start';

    private const TOKEN = ['required', 'string', 'regex:/^[A-Za-z0-9-]{16,64}$/'];

    /** Opening the screen: the filters' lists, the rubric, the recent runs. */
    public function show(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name'])->map(fn ($b) => ['id' => (int) $b->id, 'name' => (string) $b->name])->all(),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['id' => (int) $c->id, 'name' => (string) $c->name])->all(),
            'rubric' => array_map(static fn ($r) => ['points' => $r[0], 'group' => $r[1], 'rule' => $r[2]], ImageScore::RUBRIC),
            'tick' => ImageScore::TICK,
            'unscored' => ImageSeo::unscored(),
            'jobs' => ImageSeoJobs::recent(),
            'running' => $this->running(),
            'resumable' => $this->resumable(),
            'strategies' => ImageNamer::STRATEGIES,
            'templates' => AltText::TEMPLATES,
            'per_page' => ImageSeo::PER_PAGE,
        ]);
    }

    /** The Find tab: one page, every picture planned. */
    public function find(Request $request): JsonResponse
    {
        $q = $this->filters($request);

        return response()->json(['ok' => true] + ImageSeo::find($q, $q['strategy'], $q['shared']));
    }

    /**
     * The selection bar's numbers, resolved here from the selection's shape
     * ("all matching F except these", or "these ids"): never the browser's
     * own count.
     */
    public function selection(Request $request): JsonResponse
    {
        $selection = ImageSeoSelection::from($request->validate(ImageSeoSelection::rules())['selection']);
        $ids = $selection->ids();

        if ($ids === null) {
            return response()->json(['ok' => false, 'message' => 'More than '.number_format(ImageSeoSelection::MAX).' products match. Narrow the search first.'], 422);
        }

        return response()->json(['ok' => true, 'products' => count($ids), 'matching' => $selection->matched, 'pictures' => $selection->pictures($ids)]);
    }

    /**
     * Rename tab, the preview (Rename files, or Rename files + ALT text): the
     * first call resolves the selection on the server and freezes it as a
     * run in status "preview"; each call plans the next 50 products and
     * answers the running totals. Writes nothing to the shop.
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => self::TOKEN,
            'kind' => ['required', 'in:rename,combo'],
            'offset' => ['required', 'integer', 'min:0', 'max:'.ImageSeoSelection::MAX],
            'strategy' => ['nullable', 'in:'.implode(',', ImageNamer::STRATEGIES)],
            'include_shared' => ['nullable', 'boolean'],
            'template' => ['nullable', 'in:'.implode(',', AltText::TEMPLATES)],
            'first' => ['nullable', 'string', 'max:200'],
            'rest' => ['nullable', 'string', 'max:200'],
            'keep' => ['nullable', 'boolean'],
        ] + ImageSeoSelection::rules());

        // Its own namespace: a browser's token can never name an undo ("undo-7").
        $token = 'p-'.$data['token'];
        $job = DB::table('image_seo_jobs')->where('token', $token)->first();

        if ($job === null) {
            if ((int) $data['offset'] !== 0) {
                return response()->json(['ok' => false, 'message' => 'That preview has expired. Press Preview again.'], 409);
            }

            $selection = ImageSeoSelection::from($data['selection']);
            $ids = $selection->ids();

            if ($ids === null) {
                return response()->json(['ok' => false, 'message' => 'More than '.number_format(ImageSeoSelection::MAX).' products are selected. Narrow the search first.'], 422);
            }

            if ($ids === []) {
                return response()->json(['ok' => false, 'message' => 'Nothing is selected. Tick products on the Find tab first.'], 422);
            }

            $job = ImageSeoJobs::prepare($data['kind'], $token, $selection->items($ids), [
                'strategy' => $data['strategy'] ?? ImageNamer::STRATEGY_VARIATIONS,
                'include_shared' => (bool) ($data['include_shared'] ?? false),
            ] + ($data['kind'] === 'combo' ? [
                'template' => $data['template'] ?? 'variations',
                'first' => (string) ($data['first'] ?? ''),
                'rest' => (string) ($data['rest'] ?? ''),
                'keep' => (bool) ($data['keep'] ?? true),
            ] : []), $request->user('admin'));
        }

        if ($job->status !== 'preview' || $job->kind !== $data['kind']) {
            return response()->json(['ok' => false, 'message' => 'That preview was already started as run #'.$job->id.'.', 'job' => ImageSeoJobs::view($job, false)], 409);
        }

        return response()->json(['ok' => true, 'job_id' => (int) $job->id] + ImageSeoJobs::previewChunk($job, (int) $data['offset']));
    }

    /**
     * Rename tab, Start: turns a finished preview into a run. Refused without
     * one, refused while another run is going, and decided under a lock so
     * two Starts pressed together cannot both find the shop idle.
     */
    public function start(Request $request): JsonResponse
    {
        $token = 'p-'.$request->validate(['token' => self::TOKEN])['token'];
        $lock = Cache::lock(self::START_LOCK, 15);

        if (! $lock->get()) {
            return response()->json(['ok' => false, 'message' => 'Another Start is being handled right now. Try again in a moment.'], 409);
        }

        try {
            $mine = DB::table('image_seo_jobs')->where('token', $token)->first();
            $busy = $this->running();

            if ($busy !== null && ($mine === null || (int) $mine->id !== $busy['id'])) {
                return response()->json(['ok' => false, 'message' => 'Another run is in progress (#'.$busy['id'].'). Resume or stop it first.', 'running' => $busy], 409);
            }

            $out = ImageSeoJobs::begin($token);
        } finally {
            $lock->release();
        }

        if ($out['error'] === 'preview') {
            return response()->json(['ok' => false, 'need_preview' => true, 'message' => 'Preview the changes first: Start runs exactly what the preview showed.'], 409);
        }

        if ($out['error'] === 'nothing') {
            return response()->json(['ok' => false, 'message' => 'The preview found nothing to change.'], 422);
        }

        $job = $out['job'];

        if ($out['started']) {
            $this->audit('Image SEO: '.($job->kind === 'combo' ? 'rename + ALT text' : 'rename').' started for '.$job->total.' product(s)', 'job '.$job->id);
        }

        return response()->json(['ok' => true, 'job' => ImageSeoJobs::view($job, false)]);
    }

    /** One bounded slice of a running job. */
    public function step(Request $request): JsonResponse
    {
        $data = $request->validate(['job' => ['required', 'integer', 'min:1'], 'resume' => ['nullable', 'boolean']]);
        $id = (int) $data['job'];

        if (! DB::table('image_seo_jobs')->where('id', $id)->exists()) {
            return response()->json(['ok' => false, 'message' => 'No such run.'], 404);
        }

        $out = ImageSeoJobs::step($id, $request->user('admin'), (bool) ($data['resume'] ?? false));

        if (isset($out['refused'])) {
            return response()->json(['ok' => false, 'message' => $out['refused'], 'job' => $out['job']], 409);
        }

        return response()->json(['ok' => true] + $out, empty($out['busy']) ? 200 : 202);
    }

    public function stop(Request $request): JsonResponse
    {
        $id = (int) $request->validate(['job' => ['required', 'integer', 'min:1']])['job'];
        $job = ImageSeoJobs::stop($id);

        if ($job === null) {
            return response()->json(['ok' => false, 'message' => 'No such run.'], 404);
        }

        $this->audit('Image SEO: run #'.$id.' stopped', '');

        return response()->json(['ok' => true, 'job' => ImageSeoJobs::view($job, false)]);
    }

    public function job(Request $request): JsonResponse
    {
        $data = $request->validate(['id' => ['required', 'integer', 'min:1'], 'brief' => ['nullable', 'boolean']]);
        $job = DB::table('image_seo_jobs')->where('id', (int) $data['id'])->where('status', '!=', 'preview')->first();

        return $job === null
            ? response()->json(['ok' => false, 'message' => 'No such run.'], 404)
            : response()->json(['ok' => true, 'job' => ImageSeoJobs::view($job, ! ($data['brief'] ?? false))]);
    }

    /** Undo a finished rename or alt run, from the ledger. */
    public function undo(Request $request): JsonResponse
    {
        $id = (int) $request->validate(['job' => ['required', 'integer', 'min:1'], 'confirm' => ['required', 'in:UNDO']])['job'];

        $original = DB::table('image_seo_jobs')->where('id', $id)->first();

        if ($original === null || ! in_array($original->kind, ['rename', 'alt', 'combo'], true) || $original->status === 'preview') {
            return response()->json(['ok' => false, 'message' => 'Only a rename, an alt-text or a rename + ALT text run can be undone.'], 422);
        }

        if (($busy = $this->running()) !== null) {
            return response()->json(['ok' => false, 'message' => 'Another run is in progress (#'.$busy['id'].'). Finish or stop it first.', 'running' => $busy], 409);
        }

        $job = ImageSeoJobs::undo($id, $request->user('admin'));

        if ($job === null) {
            return response()->json(['ok' => false, 'message' => 'That run cannot be undone.'], 422);
        }

        $this->audit('Image SEO: undo of run #'.$id.' started', 'job '.$job->id);

        return response()->json(['ok' => true, 'job' => ImageSeoJobs::view($job, false)]);
    }

    /**
     * ALT tab, dry run: proposals and the score each would give, for the
     * next 50 products of the selection (resolved here, as everywhere).
     */
    public function altPreview(Request $request): JsonResponse
    {
        $data = $request->validate(ImageSeoSelection::rules() + [
            'offset' => ['nullable', 'integer', 'min:0', 'max:'.ImageSeoSelection::MAX],
            'template' => ['nullable', 'in:'.implode(',', AltText::TEMPLATES)],
            'first' => ['nullable', 'string', 'max:200'],
            'rest' => ['nullable', 'string', 'max:200'],
            'only_missing' => ['nullable', 'boolean'],
        ]);

        $all = ImageSeoSelection::from($data['selection'])->ids();

        if ($all === null) {
            return response()->json(['ok' => false, 'message' => 'More than '.number_format(ImageSeoSelection::MAX).' products are selected. Narrow the search first.'], 422);
        }

        $offset = (int) ($data['offset'] ?? 0);
        $ids = array_slice($all, $offset, 50);
        $products = Product::query()->whereIn('id', $ids)->with(['brand:id,name', 'category:id,name'])
            ->get(['id', 'name', 'slug', 'sku', 'status', 'brand_id', 'category_id', 'image', 'images', 'image_alts'])
            ->sortBy(fn ($p) => array_search((int) $p->id, $ids, true))->values();

        $template = $data['template'] ?? 'variations';
        $onlyMissing = (bool) ($data['only_missing'] ?? false);
        $items = [];

        foreach ((new ImageSeoPlanner())->plan($products) as $plan) {
            $product = $products->firstWhere('id', $plan['id']);
            $count = count($plan['images']);
            $proposed = AltText::propose($plan['brand'], $plan['name'], $plan['category'], $count, $template, (string) ($data['first'] ?? ''), (string) ($data['rest'] ?? ''));
            $images = [];

            foreach ($plan['images'] as $i => $image) {
                $alt = $onlyMissing && $image['alt_written'] ? $image['alt'] : $proposed[$i];
                $others = array_values(array_diff_key($proposed, [$i => true]));
                $after = ImageScore::score($image['rel'] ?? $image['filename'], $plan['brand'], $plan['name'], $alt, true, $others);

                $images[] = [
                    'url' => $image['url'],
                    'thumb' => $image['thumb'],
                    'filename' => $image['filename'],
                    'role' => $image['role'],
                    'alt' => $image['alt'],
                    'alt_written' => $image['alt_written'],
                    'score' => $image['score'],
                    'ten' => $image['ten'],
                    'reasons' => $image['reasons'],
                    'proposed' => $alt,
                    'keep' => $onlyMissing && $image['alt_written'],
                    'score_after' => $after['score'],
                    'ten_after' => $after['ten'],
                    'reasons_after' => ImageScore::reasons($after['lost']),
                ];
            }

            $items[] = ['id' => $plan['id'], 'name' => $plan['name'], 'brand' => $plan['brand'], 'category' => $plan['category'], 'images' => $images];
            unset($product);
        }

        $next = $offset + count($ids);

        return response()->json(['ok' => true, 'items' => $items, 'total' => count($all), 'next' => $next, 'done' => $next >= count($all)]);
    }

    /** ALT tab, Apply: a job, so it is resumable and undoable like a rename. */
    public function altStart(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => self::TOKEN,
            'items' => ['required', 'array', 'min:1', 'max:'.ImageSeoSelection::MAX],
            'items.*.p' => ['required', 'integer', 'min:1'],
            'items.*.alts' => ['required', 'array', 'min:1', 'max:40'],
            'items.*.alts.*' => ['required', 'string', 'max:300'],
        ]);

        $items = [];

        foreach ($data['items'] as $item) {
            $alts = [];

            foreach ($item['alts'] as $url => $alt) {
                $alt = AltText::clean((string) $alt);

                if (is_string($url) && strlen($url) <= 600 && AltText::acceptable($alt)) {
                    $alts[$url] = $alt;
                }
            }

            if ($alts !== []) {
                $items[] = ['p' => (int) $item['p'], 'alts' => $alts];
            }
        }

        if ($items === []) {
            return response()->json(['ok' => false, 'message' => 'No alt text to apply: each must be 5–125 characters.'], 422);
        }

        $lock = Cache::lock(self::START_LOCK, 15);

        if (! $lock->get()) {
            return response()->json(['ok' => false, 'message' => 'Another Start is being handled right now. Try again in a moment.'], 409);
        }

        try {
            $mine = DB::table('image_seo_jobs')->where('token', 'a-'.$data['token'])->first();
            $busy = $this->running();

            if ($busy !== null && ($mine === null || (int) $mine->id !== $busy['id'])) {
                return response()->json(['ok' => false, 'message' => 'Another run is in progress (#'.$busy['id'].'). Resume or stop it first.', 'running' => $busy], 409);
            }

            $job = ImageSeoJobs::start('alt', 'a-'.$data['token'], $items, [], $request->user('admin'));
        } finally {
            $lock->release();
        }

        $this->audit('Image SEO: alt text started for '.count($items).' product(s)', 'job '.$job->id);

        return response()->json(['ok' => true, 'job' => ImageSeoJobs::view($job, false)]);
    }

    /** The one-time score backfill, one bounded batch. */
    public function score(Request $request): JsonResponse
    {
        $data = $request->validate(['after' => ['nullable', 'integer', 'min:0']]);

        return response()->json(['ok' => true] + ImageSeo::backfill((int) ($data['after'] ?? 0)));
    }

    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'brand' => ['nullable', 'integer', 'min:0'],
            'category' => ['nullable', 'integer', 'min:0'],
            'filter' => ['nullable', 'in:'.implode(',', array_filter(ImageSeo::FILTERS))],
            'sort' => ['nullable', 'in:'.implode(',', ImageSeo::SORTS)],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'strategy' => ['nullable', 'in:'.implode(',', ImageNamer::STRATEGIES)],
            'shared' => ['nullable', 'boolean'],
        ]);

        return [
            'q' => (string) ($data['q'] ?? ''),
            'brand' => (int) ($data['brand'] ?? 0),
            'category' => (int) ($data['category'] ?? 0),
            'filter' => (string) ($data['filter'] ?? ''),
            'sort' => (string) ($data['sort'] ?? 'name'),
            'page' => (int) ($data['page'] ?? 1),
            'strategy' => (string) ($data['strategy'] ?? ImageNamer::STRATEGY_VARIATIONS),
            'shared' => (bool) ($data['shared'] ?? false),
        ];
    }

    /**
     * The job a screen is actively stepping right now: running AND touched in
     * the last two minutes. A run whose tab was closed mid-way is not "busy"
     * for ever — it shows as resumable instead (resumable()).
     *
     * @return array<string, mixed>|null
     */
    private function running(): ?array
    {
        $job = DB::table('image_seo_jobs')->where('status', 'running')->where('updated_at', '>=', now()->subMinutes(2))->orderByDesc('id')->first();

        return $job === null ? null : ImageSeoJobs::view($job, false);
    }

    /** @return array<string, mixed>|null the newest unfinished run, to offer Resume */
    private function resumable(): ?array
    {
        $job = DB::table('image_seo_jobs')->whereIn('status', ['running', 'stopped'])->whereColumn('position', '<', 'total')->orderByDesc('id')->first();

        return $job === null ? null : ImageSeoJobs::view($job, false);
    }

    private function audit(string $summary, string $after): void
    {
        try {
            app(SecurityModule::class)->record(self::AUDIT, $summary, ['subject' => 'image_seo', 'after' => mb_substr($after, 0, 500), 'severity' => 'notice']);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
