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
use App\Services\SecurityModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    /** "Select every match": the ids, capped. */
    public function ids(Request $request): JsonResponse
    {
        $q = $this->filters($request);
        $ids = ImageSeo::ids($q);

        return response()->json(['ok' => true, 'ids' => $ids, 'capped' => count($ids) >= 5000]);
    }

    /** Rename tab, dry run: up to 50 products per call. Writes nothing. */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate($this->selectionRules(50) + [
            'strategy' => ['nullable', 'in:'.implode(',', ImageNamer::STRATEGIES)],
            'include_shared' => ['nullable', 'boolean'],
        ]);

        [$ids, $only] = $this->selection($data['products']);
        $products = Product::query()->whereIn('id', $ids)->with(['brand:id,name', 'category:id,name'])
            ->get(['id', 'name', 'slug', 'sku', 'status', 'brand_id', 'category_id', 'image', 'images', 'image_alts'])
            ->sortBy(fn ($p) => array_search((int) $p->id, $ids, true))->values();

        $plans = (new ImageSeoPlanner($data['strategy'] ?? ImageNamer::STRATEGY_VARIATIONS, (bool) ($data['include_shared'] ?? false)))->plan($products, $only);

        return response()->json(['ok' => true, 'items' => $plans]);
    }

    /** Rename tab, Start: one job per preview token. */
    public function start(Request $request): JsonResponse
    {
        if (($busy = $this->running()) !== null) {
            return response()->json(['ok' => false, 'message' => 'Another run is in progress (#'.$busy['id'].'). Resume or stop it first.', 'running' => $busy], 409);
        }

        $data = $request->validate($this->selectionRules(5000) + [
            'token' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{16,64}$/'],
            'strategy' => ['nullable', 'in:'.implode(',', ImageNamer::STRATEGIES)],
            'include_shared' => ['nullable', 'boolean'],
        ]);

        [$ids, $only] = $this->selection($data['products']);
        $known = Product::query()->whereIn('id', $ids)->pluck('id')->map(fn ($v) => (int) $v)->all();
        $items = [];

        foreach ($ids as $id) {
            if (in_array($id, $known, true)) {
                $items[] = isset($only[$id]) ? ['p' => $id, 'only' => $only[$id]] : ['p' => $id];
            }
        }

        if ($items === []) {
            return response()->json(['ok' => false, 'message' => 'Nothing selected.'], 422);
        }

        $job = ImageSeoJobs::start('rename', 'r-'.$data['token'], $items, [
            'strategy' => $data['strategy'] ?? ImageNamer::STRATEGY_VARIATIONS,
            'include_shared' => (bool) ($data['include_shared'] ?? false),
        ], $request->user('admin'));

        $this->audit('Image SEO: rename started for '.count($items).' product(s)', 'job '.$job->id);

        return response()->json(['ok' => true, 'job' => ImageSeoJobs::view($job)]);
    }

    /** One bounded slice of a running job. */
    public function step(Request $request): JsonResponse
    {
        $id = (int) $request->validate(['job' => ['required', 'integer', 'min:1']])['job'];

        if (! DB::table('image_seo_jobs')->where('id', $id)->exists()) {
            return response()->json(['ok' => false, 'message' => 'No such run.'], 404);
        }

        $out = ImageSeoJobs::step($id, $request->user('admin'));

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

        return response()->json(['ok' => true, 'job' => ImageSeoJobs::view($job)]);
    }

    public function job(Request $request): JsonResponse
    {
        $id = (int) $request->validate(['id' => ['required', 'integer', 'min:1']])['id'];
        $job = DB::table('image_seo_jobs')->where('id', $id)->first();

        return $job === null
            ? response()->json(['ok' => false, 'message' => 'No such run.'], 404)
            : response()->json(['ok' => true, 'job' => ImageSeoJobs::view($job)]);
    }

    /** Undo a finished rename or alt run, from the ledger. */
    public function undo(Request $request): JsonResponse
    {
        $id = (int) $request->validate(['job' => ['required', 'integer', 'min:1'], 'confirm' => ['required', 'in:UNDO']])['job'];

        $original = DB::table('image_seo_jobs')->where('id', $id)->first();

        if ($original === null || ! in_array($original->kind, ['rename', 'alt'], true)) {
            return response()->json(['ok' => false, 'message' => 'Only a rename or an alt-text run can be undone.'], 422);
        }

        if (($busy = $this->running()) !== null) {
            return response()->json(['ok' => false, 'message' => 'Another run is in progress (#'.$busy['id'].'). Finish or stop it first.', 'running' => $busy], 409);
        }

        $job = ImageSeoJobs::undo($id, $request->user('admin'));

        if ($job === null) {
            return response()->json(['ok' => false, 'message' => 'That run cannot be undone.'], 422);
        }

        $this->audit('Image SEO: undo of run #'.$id.' started', 'job '.$job->id);

        return response()->json(['ok' => true, 'job' => ImageSeoJobs::view($job)]);
    }

    /** ALT tab, dry run: proposals and the score each would give. */
    public function altPreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'products' => ['required', 'array', 'min:1', 'max:50'],
            'products.*' => ['integer', 'min:1'],
            'template' => ['nullable', 'in:'.implode(',', AltText::TEMPLATES)],
            'first' => ['nullable', 'string', 'max:200'],
            'rest' => ['nullable', 'string', 'max:200'],
            'only_missing' => ['nullable', 'boolean'],
        ]);

        $ids = array_values(array_unique(array_map('intval', $data['products'])));
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

        return response()->json(['ok' => true, 'items' => $items]);
    }

    /** ALT tab, Apply: a job, so it is resumable and undoable like a rename. */
    public function altStart(Request $request): JsonResponse
    {
        if (($busy = $this->running()) !== null) {
            return response()->json(['ok' => false, 'message' => 'Another run is in progress (#'.$busy['id'].'). Resume or stop it first.', 'running' => $busy], 409);
        }

        $data = $request->validate([
            'token' => ['required', 'string', 'regex:/^[A-Za-z0-9-]{16,64}$/'],
            'items' => ['required', 'array', 'min:1', 'max:2000'],
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

        $job = ImageSeoJobs::start('alt', 'a-'.$data['token'], $items, [], $request->user('admin'));
        $this->audit('Image SEO: alt text started for '.count($items).' product(s)', 'job '.$job->id);

        return response()->json(['ok' => true, 'job' => ImageSeoJobs::view($job)]);
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

    /** @return array<string, array<int, mixed>> */
    private function selectionRules(int $max): array
    {
        return [
            'products' => ['required', 'array', 'min:1', 'max:'.$max],
            'products.*.p' => ['required', 'integer', 'min:1'],
            'products.*.only' => ['nullable', 'array', 'max:60'],
            'products.*.only.*' => ['string', 'max:600'],
        ];
    }

    /** @return array{0: list<int>, 1: array<int, list<string>>} */
    private function selection(array $products): array
    {
        $ids = [];
        $only = [];

        foreach ($products as $entry) {
            $id = (int) $entry['p'];

            if (! in_array($id, $ids, true)) {
                $ids[] = $id;
            }

            if (isset($entry['only']) && is_array($entry['only'])) {
                $only[$id] = array_values(array_map('strval', $entry['only']));
            }
        }

        return [$ids, $only];
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

        return $job === null ? null : ImageSeoJobs::view($job);
    }

    /** @return array<string, mixed>|null the newest unfinished run, to offer Resume */
    private function resumable(): ?array
    {
        $job = DB::table('image_seo_jobs')->whereIn('status', ['running', 'stopped'])->whereColumn('position', '<', 'total')->orderByDesc('id')->first();

        return $job === null ? null : ImageSeoJobs::view($job);
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
