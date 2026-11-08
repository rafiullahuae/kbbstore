<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\FreeGiftTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Free gifts -> Ranges & gifts -> Templates (Lane GT). Routes in
 * routes/free-gift-templates-admin.php, all under admin-api/free-gifts/**, so
 * AdminCapabilities maps GET to freegifts.view and every other verb to
 * freegifts.manage, failing closed. The work is FreeGiftTemplates'.
 *
 * Every write answers with the fresh list; making one live also answers with
 * the whole Free gifts payload (FreeGiftsController::show()), so the screen
 * redraws in place from that one answer, with no second request.
 */
class FreeGiftTemplatesController extends Controller
{
    public function __construct(private FreeGiftTemplates $templates) {}

    /** GET /admin-api/free-gifts/templates */
    public function index(): JsonResponse
    {
        return response()->json(['templates' => $this->templates->list()]);
    }

    /** POST /admin-api/free-gifts/templates {name} -- save the live ranges as a new template. */
    public function store(Request $request): JsonResponse
    {
        $name = $this->name($request);

        return $this->write(fn () => $this->templates->save($name), 201);
    }

    /** PUT /admin-api/free-gifts/templates/{id} -- save the live ranges over this template. */
    public function update(int $id): JsonResponse
    {
        if (! DB::table('free_gift_templates')->where('id', $id)->exists()) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return $this->write(fn () => $this->templates->save('', $id));
    }

    /** POST /admin-api/free-gifts/templates/{id}/rename {name} -- a renamed automatic copy is kept for good. */
    public function rename(Request $request, int $id): JsonResponse
    {
        if (! DB::table('free_gift_templates')->where('id', $id)->exists()) {
            return response()->json(['error' => 'not_found'], 404);
        }
        $name = $this->name($request, $id);
        DB::table('free_gift_templates')->where('id', $id)->update(['name' => $name, 'auto' => false, 'updated_at' => now()]);

        return response()->json(['templates' => $this->templates->list()]);
    }

    /** DELETE /admin-api/free-gifts/templates/{id} -- the live ranges are not touched. */
    public function destroy(int $id): JsonResponse
    {
        DB::table('free_gift_templates')->where('id', $id)->delete();

        return response()->json(['templates' => $this->templates->list()]);
    }

    /** POST /admin-api/free-gifts/templates/{id}/apply -- make it the live ranges. */
    public function apply(int $id): JsonResponse
    {
        try {
            $done = $this->templates->apply($id);
        } catch (\OutOfBoundsException) {
            return response()->json(['error' => 'not_found'], 404);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($done + [
            'templates' => $this->templates->list(),
            'data' => app(FreeGiftsController::class)->show()->getData(true),
        ]);
    }

    private function write(\Closure $fn, int $status = 200): JsonResponse
    {
        try {
            $done = $fn();
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json($done + ['templates' => $this->templates->list()], $status);
    }

    /** Required, 1-60 characters after trimming, unique whatever the letter case. */
    private function name(Request $request, ?int $except = null): string
    {
        $request->validate(['name' => ['required', 'string', 'max:' . FreeGiftTemplates::NAME_MAX]]);
        $name = trim(preg_replace('/\s+/u', ' ', (string) $request->input('name')) ?? '');

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Give the template a name.']);
        }

        $taken = DB::table('free_gift_templates')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($except !== null, fn ($q) => $q->where('id', '!=', $except))
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages(['name' => 'A template called "' . $name . '" already exists. Choose another name, or use Save on that row to save over it.']);
        }

        return $name;
    }
}
