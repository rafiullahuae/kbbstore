<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\QuizSubmission;
use Illuminate\Http\Request;
class QuizController extends Controller
{
    /** POST /api/quiz — capture a skin-quiz lead */
    public function store(Request $request)
    {
        $data = $request->validate([
            'skin_type'     => 'nullable|string|max:60',
            'concerns'      => 'nullable',
            'age'           => 'nullable|string|max:30',
            'routine_depth' => 'nullable|string|max:40',
            'budget'        => 'nullable|string|max:40',
            'name'          => 'nullable|string|max:120',
            'phone'         => 'nullable|string|max:40',
            'email'         => 'nullable|email|max:160',
            'consent'       => 'nullable|boolean',
        ]);

        $sub = QuizSubmission::create([
            'created_at'    => now()->toISOString(),
            'status'        => 'new',
            'skin_type'     => $data['skin_type'] ?? null,
            'concerns'      => is_array($data['concerns'] ?? null) ? implode(',', $data['concerns']) : ($data['concerns'] ?? null),
            'age'           => $data['age'] ?? null,
            'routine_depth' => $data['routine_depth'] ?? null,
            'budget'        => $data['budget'] ?? null,
            'name'          => $data['name'] ?? null,
            'phone'         => $data['phone'] ?? null,
            'email'         => $data['email'] ?? null,
            'consent'       => !empty($data['consent']) ? 1 : 0,
            'consent_at'    => !empty($data['consent']) ? now()->toISOString() : null,
            'source_url'    => $request->headers->get('referer'),
        ]);

        // A signed handle, not the bare row id. The storefront reads this as
        // `id` and puts it straight back in the expert-request URL, so the
        // shape of the flow is unchanged -- what changed is that the value is
        // now unguessable. See QuizSubmission::publicToken().
        return response()->json(['ok' => true, 'id' => $sub->publicToken()], 201);
    }

    /**
     * POST /api/quiz/{id}/expert-request — attach an expert callback request to a lead
     *
     * {id} is the signed token issued by store(), not the primary key. With a
     * bare key here this endpoint had no ownership check of any kind: counting
     * upwards from 1 let anyone attach a message to, and move the status of,
     * every lead in the table -- and the 404-vs-200 split told the counter
     * exactly which ids were real. Both halves are the same fix: an id you
     * were not given resolves to nothing, and resolves to nothing in the same
     * way a lead that does not exist does.
     */
    public function expertRequest(Request $request, string $id)
    {
        $data = $request->validate([
            'message' => 'nullable|string|max:2000',
        ]);

        $sub = QuizSubmission::findByPublicToken($id);
        if (!$sub) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $sub->update([
            'expert_requested'    => 1,
            'expert_message'      => $data['message'] ?? null,
            'expert_requested_at' => now()->toISOString(),
            'status'              => 'expert_requested',
        ]);

        return response()->json(['ok' => true], 200);
    }
}
