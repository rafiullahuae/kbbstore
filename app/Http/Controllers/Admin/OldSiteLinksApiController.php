<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Import\OldSiteLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Store -> Store Import / Export -> Addresses & pictures -> "Links to the old
 * site". (Lane PT)
 *
 *     POST /admin-api/urls-media/old-links   {action: preview|apply|restore}
 *
 * The owner: "Internal links in the end of anua foam and other cleansers -- on
 * all articles and products/sets descriptions, internal links are going to
 * still old site." The import now fixes them itself at the end of every file
 * (ImportRunner::keepLinksLocal()); this is the same sweep for copy that was
 * imported BEFORE that existed -- which is everything on the live shop today --
 * plus a preview and an Undo.
 *
 * ITS OWN CAPABILITY, `data.old_links`, owner-only. Not `data.import` beside
 * the rest of the screen, because this one REWRITES the shop's own copy -- every
 * product description, set, article and block that links out -- rather than
 * loading rows in; a role trusted to run an import is not thereby a role
 * trusted to edit every description at once. AdminCapabilities::RULES names
 * the path above the `urls-media/**` wildcard; an account without the
 * capability gets the 403 EnforceAdminCapability gives every refused call.
 *
 * NOTHING IS TAKEN FROM THE BODY BUT THE VERB. Which links change and where to
 * is re-derived from the database on every request (OldSiteLinks::propose()),
 * so the endpoint cannot be asked to point a link anywhere it would not have
 * chosen itself.
 */
final class OldSiteLinksApiController extends Controller
{
    /** How many sample rows the preview returns. */
    private const SAMPLES = 12;

    public function handle(Request $request): JsonResponse
    {
        $request->validate([
            'action' => ['required', 'string', 'in:preview,apply,restore'],
        ]);

        $links = new OldSiteLinks;
        $action = $request->string('action')->toString();

        if ($action === 'restore') {
            $done = $links->restore();

            return response()->json(['ok' => true] + $done + ['applied' => OldSiteLinks::applied()]);
        }

        if ($action === 'apply') {
            $done = $links->apply();

            return response()->json([
                'ok' => true,
                'documents' => $done['documents'],
                'links' => $done['links'],
                'remaining' => OldSiteLinks::summarise($links->propose(), 0),
                'applied' => OldSiteLinks::applied(),
            ]);
        }

        return response()->json([
            'ok' => true,
            'hosts' => OldSiteLinks::hosts(),
            'summary' => OldSiteLinks::summarise($links->propose(), self::SAMPLES),
            'applied' => OldSiteLinks::applied(),
        ]);
    }
}
