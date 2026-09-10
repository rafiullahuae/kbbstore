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

        return response()->json(['ok' => true, 'id' => $sub->id], 201);
    }

    /** POST /api/quiz/{id}/expert-request — attach an expert callback request to a lead */
    public function expertRequest(Request $request, int $id)
    {
        $data = $request->validate([
            'message' => 'nullable|string|max:2000',
        ]);

        $sub = QuizSubmission::find($id);
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
