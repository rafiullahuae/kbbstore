<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Marketing\Blocks;
use App\Services\Marketing\CampaignRenderer;
use App\Support\StoreTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Marketing Emails → Templates (Lane MK): the library of READY templates the
 * shop ships (read-only presets) and the owner's own ("My templates").
 *
 *   Use        a campaign draft from a copy (MktCampaignsController::store)
 *   Duplicate  an editable copy under My templates
 *   Save as my template   from the builder (store, with blocks)
 *
 * A preset cannot be changed or deleted — so a later package can improve the
 * ready ones without touching anything the owner typed — and every block list
 * passes through Blocks::clean() on the way in.
 */
final class MktTemplatesController extends Controller
{
    public function __construct(private CampaignRenderer $renderer) {}

    public function index(): JsonResponse
    {
        $rows = DB::table('mkt_templates')->orderByDesc('preset')->orderBy('sort')->orderByDesc('updated_at')->orderBy('id')
            ->limit(300)->get(['id', 'key', 'name', 'category', 'description', 'subject', 'preset', 'blocks', 'theme', 'locale', 'updated_at']);

        return response()->json(['templates' => $rows->map(fn ($t) => $this->row($t))->all()]);
    }

    /** @return array<string, mixed> */
    private function row(object $t): array
    {
        $blocks = json_decode((string) $t->blocks, true) ?: [];

        return [
            'id' => (int) $t->id,
            'key' => $t->key,
            'name' => (string) $t->name,
            'category' => (string) $t->category,
            'description' => (string) ($t->description ?? ''),
            'subject' => (string) $t->subject,
            'preset' => (bool) $t->preset,
            ...CampaignRenderer::look($t),
            'subject_ideas' => \App\Services\Marketing\TemplateLibrary::subjectIdeas(is_string($t->key) ? $t->key : null),
            'blocks' => count($blocks),
            'fills' => array_values(array_unique(array_filter(array_map(fn ($b) => in_array($b['type'] ?? '', ['product_row', 'product_grid'], true) ? (Blocks::FILLS[$b['props']['fill'] ?? ''] ?? null) : null, $blocks)))),
            'updated_at' => StoreTime::iso($t->updated_at),
        ];
    }

    public function show(int $id): JsonResponse
    {
        $t = DB::table('mkt_templates')->where('id', $id)->first();

        if ($t === null) {
            return response()->json(['error' => 'No such template.'], 404);
        }

        $warnings = [];
        $errors = [];

        return response()->json(['template' => $this->row($t) + [
            'preheader' => (string) $t->preheader,
            'blocks_list' => Blocks::clean(json_decode((string) $t->blocks, true), $errors, $warnings),
            'warnings' => array_merge($errors, $warnings),
        ]]);
    }

    /**
     * The template rendered, for the library's live thumbnail (and a full
     * look before Use). Framed by the screen with a sandbox and no script.
     */
    public function preview(Request $request, int $id): Response
    {
        $t = DB::table('mkt_templates')->where('id', $id)->first();

        if ($t === null) {
            abort(404);
        }

        $blocks = Blocks::clean(json_decode((string) $t->blocks, true));
        $frozen = $this->renderer->materialize($blocks);
        $out = $this->renderer->render($frozen, [
            'audience' => 'customers', 'first_name' => 'Aisha', 'subject' => $t->subject, 'preheader' => $t->preheader,
            'unsubscribe' => \App\Support\Url::external('/email/u/0-' . str_repeat('0', 32)),
        ] + CampaignRenderer::look($t));

        return response($out['html'], 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        $id = DB::table('mkt_templates')->insertGetId($data + [
            'category' => 'mine',
            'preset' => false,
            'created_by' => $request->user('admin')?->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'template' => $this->row(DB::table('mkt_templates')->where('id', $id)->first())]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $t = DB::table('mkt_templates')->where('id', $id)->first();

        if ($t === null) {
            return response()->json(['ok' => false, 'error' => 'No such template.'], 404);
        }

        if ($t->preset) {
            return response()->json(['ok' => false, 'error' => 'A ready template cannot be changed. Duplicate it, and edit the copy under My templates.'], 409);
        }

        $data = $this->validated($request, false);

        if ($data instanceof JsonResponse) {
            return $data;
        }

        DB::table('mkt_templates')->where('id', $id)->update($data + ['updated_at' => now()]);

        return response()->json(['ok' => true, 'template' => $this->row(DB::table('mkt_templates')->where('id', $id)->first())]);
    }

    public function destroy(int $id): JsonResponse
    {
        $deleted = DB::table('mkt_templates')->where('id', $id)->where('preset', false)->delete();

        return $deleted === 1
            ? response()->json(['ok' => true])
            : response()->json(['ok' => false, 'error' => 'A ready template cannot be deleted.'], 409);
    }

    public function duplicate(Request $request, int $id): JsonResponse
    {
        $t = DB::table('mkt_templates')->where('id', $id)->first();

        if ($t === null) {
            return response()->json(['ok' => false, 'error' => 'No such template.'], 404);
        }

        $new = DB::table('mkt_templates')->insertGetId([
            'name' => mb_substr(($t->preset ? '' : 'Copy of ') . $t->name, 0, 120),
            'category' => 'mine',
            'description' => $t->preset ? 'Your copy of the ready template "' . $t->name . '".' : $t->description,
            'subject' => $t->subject,
            'preheader' => $t->preheader,
            'blocks' => $t->blocks,
            ...CampaignRenderer::look($t),
            'preset' => false,
            'created_by' => $request->user('admin')?->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'template' => $this->row(DB::table('mkt_templates')->where('id', $new)->first())]);
    }

    /** @return array<string, mixed>|JsonResponse */
    private function validated(Request $request, bool $creating): array|JsonResponse
    {
        $line = ['string', 'regex:/^[^\r\n]*$/u'];
        $data = $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120', ...$line],
            'description' => ['sometimes', 'nullable', 'string', 'max:300', ...$line],
            'subject' => ['sometimes', 'nullable', 'string', 'max:200', ...$line],
            'preheader' => ['sometimes', 'nullable', 'string', 'max:200', ...$line],
            'blocks' => [$creating ? 'required' : 'sometimes', 'array'],
            // Lane EC: a select stores one of its own options.
            'theme' => ['sometimes', 'string', 'in:' . implode(',', array_keys(\App\Services\Marketing\EmailTheme::THEMES))],
            'locale' => ['sometimes', 'string', 'in:' . implode(',', array_keys(\App\Services\Marketing\EmailTheme::LOCALES))],
        ], ['regex' => 'Line breaks are not allowed here.']);

        $out = [];

        foreach (['name', 'description', 'subject', 'preheader', 'theme', 'locale'] as $k) {
            if (array_key_exists($k, $data)) {
                $out[$k] = trim((string) $data[$k]);
            }
        }

        if (array_key_exists('blocks', $data)) {
            $errors = [];
            $blocks = Blocks::clean($request->input('blocks'), $errors);

            if ($errors !== []) {
                return response()->json(['ok' => false, 'error' => $errors[0], 'errors' => $errors], 422);
            }

            $out['blocks'] = json_encode($blocks);
        }

        return $out;
    }
}
