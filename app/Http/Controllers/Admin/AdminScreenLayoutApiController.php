<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminScreenLayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Read, write and reset one operator's panel arrangement for a screen.
 * (Lane AS)
 *
 * -------------------------------------------------------------------------
 * ONE ADMIN CANNOT WRITE ANOTHER'S ROW, AND NOT BECAUSE THE CLIENT BEHAVES
 * -------------------------------------------------------------------------
 *
 * The owning admin id is read from the guard -- auth('admin')->id() -- and is
 * the ONLY thing that ever scopes a query here. No request field names an
 * owner, `admin_user_id` is not in the model's $fillable, and every read and
 * write below goes through owned(), which puts the id in the WHERE clause.
 * A caller who posts `admin_user_id` gets it ignored twice over: once because
 * the validator does not accept it, and once because mass assignment drops it.
 *
 * That matters more than it looks. This is a WRITE endpoint reachable by every
 * signed-in operator, and the console has three roles. Without the scope, the
 * cheapest possible bug -- taking an id from the body -- would let a staff
 * account rewrite the owner's screen.
 *
 * -------------------------------------------------------------------------
 * WHAT IS VALIDATED, AND WHY THE SERVER DOES NOT KNOW THE PANEL LIST
 * -------------------------------------------------------------------------
 *
 * The server validates the SHAPE of the document and nothing about its
 * meaning: the screen is on an allowlist, the column names are on that
 * screen's allowlist, the panel keys are short slugs, and the counts are
 * bounded so the column cannot be used as a text dump.
 *
 * It deliberately does NOT hold the list of real panels. That list lives in
 * the Blade screen, is edited by whoever adds or removes a panel, and a copy
 * here would be a second list to keep in step -- which is exactly how a saved
 * layout comes to disagree with the build. Instead the client reconciles what
 * it reads against the panels it actually has: unknown names are dropped and
 * panels the document never mentions are appended to their default column, so
 * a layout saved against an older or newer build always renders in full. The
 * next save writes the reconciled document back, so stale names age out on
 * their own.
 *
 * Duplicates ARE resolved here, because that one is a shape question and not a
 * meaning question: a key appearing in two columns would render the same panel
 * twice whatever the registry says, so the first occurrence wins and the rest
 * are dropped before the row is written.
 *
 * -------------------------------------------------------------------------
 * WHY RESET IS ITS OWN FLAT PATH
 * -------------------------------------------------------------------------
 *
 * POST /admin-api/editor-layout-reset, not DELETE /admin-api/editor-layout.
 * Reset deletes the row so the screen falls back to the build's default order,
 * which means "the default" is never a stored document that can itself go
 * stale -- it is always whatever the current build ships. A flat sibling path
 * carries no wildcard, so it cannot be shadowed by, or shadow, anything
 * already registered however the requires in routes/web.php end up ordered.
 */
class AdminScreenLayoutApiController extends Controller
{
    /**
     * Screens that may store an arrangement, and the columns each one has.
     *
     * An allowlist rather than a free string: without it this table is an
     * anonymous key/value store that any signed-in operator can write
     * unbounded rows into, one per invented screen name.
     */
    public const SCREENS = [
        'product-editor' => ['main', 'side'],
    ];

    /** Bounds. Generous for a screen, far below "usable as storage". */
    private const MAX_PANELS_PER_COLUMN = 64;
    private const MAX_KEY_LENGTH = 40;

    public function show(Request $request): JsonResponse
    {
        $screen = $this->screen($request->query('screen'));

        $row = $this->owned($screen)->first();

        return response()->json([
            'ok' => true,
            'screen' => $screen,
            'columns' => self::SCREENS[$screen],
            'layout' => $row ? $row->layout : null,
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $screen = $this->screen($request->input('screen'));
        $columns = self::SCREENS[$screen];

        $request->validate([
            'layout' => ['required', 'array'],

            // Only this screen's own column names. An unexpected key is a
            // rejection rather than something quietly dropped, so a client
            // that has drifted finds out rather than half-saving.
            'layout.*' => ['array', 'max:'.self::MAX_PANELS_PER_COLUMN],
            'layout.*.*' => ['string', 'max:'.self::MAX_KEY_LENGTH, 'regex:/^[a-z0-9_]+$/'],
        ]);

        $layout = $request->input('layout');

        foreach (array_keys($layout) as $column) {
            if (! in_array($column, $columns, true)) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Unknown column "'.$column.'" for screen "'.$screen.'".',
                ], 422);
            }
        }

        $clean = $this->dedupe($layout, $columns);

        /*
         * updateOrCreate against the OWNED scope. The unique index on
         * (admin_user_id, screen) is what makes a double-tap on the reorder
         * button an update rather than a second row.
         */
        $row = $this->owned($screen)->first();

        if ($row) {
            $row->fill(['layout' => $clean])->save();
        } else {
            $row = new AdminScreenLayout(['screen' => $screen, 'layout' => $clean]);
            $row->admin_user_id = $this->adminId();
            $row->save();
        }

        return response()->json([
            'ok' => true,
            'screen' => $screen,
            'layout' => $clean,
        ]);
    }

    /**
     * Put it back the way the build ships it.
     *
     * Deleting the row rather than writing a "default" document is what makes
     * this survive a build that adds a panel: there is no stored default to be
     * out of date, so reset always means the CURRENT default.
     */
    public function reset(Request $request): JsonResponse
    {
        $screen = $this->screen($request->input('screen'));

        $this->owned($screen)->delete();

        return response()->json([
            'ok' => true,
            'screen' => $screen,
            'layout' => null,
        ]);
    }

    /* ------------------------------------------------------------ helpers */

    /** The signed-in operator's id. The guard, never the request body. */
    private function adminId(): int
    {
        return (int) auth('admin')->id();
    }

    /**
     * Every query in this controller starts here, so there is exactly one
     * place the ownership scope can be forgotten.
     *
     * @return \Illuminate\Database\Eloquent\Builder<AdminScreenLayout>
     */
    private function owned(string $screen)
    {
        return AdminScreenLayout::query()
            ->where('admin_user_id', $this->adminId())
            ->where('screen', $screen);
    }

    /** Validate the screen name against the allowlist, or 422. */
    private function screen(mixed $value): string
    {
        $screen = is_string($value) ? $value : '';

        validator(
            ['screen' => $screen],
            ['screen' => ['required', 'string', Rule::in(array_keys(self::SCREENS))]]
        )->validate();

        return $screen;
    }

    /**
     * One panel key may appear once across the whole document.
     *
     * A key in two columns renders the same panel twice no matter what the
     * client's registry says, so it is resolved before storage: first
     * occurrence wins, in column order. Also normalises the document to carry
     * every column of the screen, so a client reading it back never has to
     * guess whether a missing column means "empty" or "absent".
     *
     * @param  array<string,mixed>  $layout
     * @param  list<string>  $columns
     * @return array<string,list<string>>
     */
    private function dedupe(array $layout, array $columns): array
    {
        $seen = [];
        $clean = [];

        foreach ($columns as $column) {
            $clean[$column] = [];

            foreach ((array) ($layout[$column] ?? []) as $key) {
                if (! is_string($key) || isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $clean[$column][] = $key;
            }
        }

        return $clean;
    }
}
