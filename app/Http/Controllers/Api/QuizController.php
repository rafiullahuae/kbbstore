<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Models\QuizSubmission;
use Illuminate\Http\Request;
class QuizController extends Controller
{
    /**
     * The shape the skin quiz posts, mapped onto the columns it fills.
     *
     * WHY THIS TABLE EXISTS. store() validated flat snake_case
     * (`skin_type`, `name`, `phone`, `email`, `age`, `routine_depth`,
     * `budget`) and resources/views/store/skin-quiz.blade.php has always
     * posted camelCase under nested objects (`skinType`, `contact.name`,
     * `answers.routineDepth`, ...). Laravel's validate() drops every key it
     * was not asked about, so nine fields were accepted, two of them were
     * ever sent, and a shopper who typed their name, WhatsApp number and
     * email into a gated form had all three written NULL. Measured, not read:
     * posting buildPayload() verbatim stored `concerns` and `consent` and
     * nothing else.
     *
     * THE NESTED SPELLING IS CANONICAL, because the page is the published
     * contract: a shopper part-way through the quiz right now is posting that
     * body, and a fix that made the page match the endpoint would throw away
     * every lead captured between the package being built and the browser
     * being reloaded. The flat spelling stays accepted as an alias -- it is
     * what the endpoint has answered 201 to for its whole life, and removing
     * it would break callers this repository cannot see.
     *
     * Left of the arrow is the path in the posted body, in the order it is
     * tried; right of it is the column.
     */
    private const CAPTURE = [
        'skin_type'     => ['skinType', 'skin_type'],
        'age'           => ['answers.age', 'age'],
        'routine_depth' => ['answers.routineDepth', 'routine_depth'],
        'budget'        => ['answers.budget', 'budget'],
        'name'          => ['contact.name', 'name'],
        'phone'         => ['contact.phone', 'phone'],
        'email'         => ['contact.email', 'email'],
    ];

    /**
     * What the page sends that is deliberately NOT kept, and why.
     *
     * `answers.allergies` and `answers.allergyNote` are health data. The
     * allergy step's own words are "So we steer clear of ingredients that
     * don't agree with you" -- a purpose served while the quiz is on screen,
     * not a statement that the answer is kept on file -- and the free-text box
     * beside it is where a shopper types "pregnant". There is no column for
     * either, adding one would be a new category of record about a person, and
     * the form does not ask for permission to hold it. So they are read by
     * recommend() in the browser and go no further. Widening this needs the
     * form to say so first.
     *
     * `status` and `expertRequest` are sent by the page and must not be
     * honoured from the body at all: this endpoint is public and
     * unauthenticated, so accepting them would let any caller file a lead
     * already marked 'converted', or flip expert_requested without holding the
     * signed handle that /api/quiz/{token}/expert-request requires.
     *
     * `submittedAt` and `source_url` are client-controlled. The row is stamped
     * from the server clock, and source_url keeps coming from the Referer
     * header rather than from the body.
     */
    private const NOT_CAPTURED = ['answers.allergies', 'answers.allergyNote', 'status', 'expertRequest', 'submittedAt'];

    /** POST /api/quiz — capture a skin-quiz lead */
    public function store(Request $request)
    {
        $data = $this->validateCapture($request);

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
            // The routine NAMES and STEP names the page worked out, and only
            // those. See routinesFrom(): the column is json and the admin
            // leads screen reads it, so what may go in it is decided here
            // rather than by whatever an anonymous caller posts.
            'recommended_routines' => $this->routinesFrom($data),
            'consent'       => !empty($data['consent']) ? 1 : 0,
            'consent_at'    => !empty($data['consent']) ? now()->toISOString() : null,
            'source_url'    => $request->headers->get('referer'),
        ]);

        // A signed handle, not the bare row id. The storefront reads this as
        // `id` and puts it straight back in the expert-request URL, so the
        // shape of the flow is unchanged -- what changed is that the value is
        // now unguessable. See QuizSubmission::publicToken().
        //
        // Nothing about the captured lead is echoed back. This row now carries
        // a name, a WhatsApp number and an email address on an endpoint with
        // no authentication in front of it, so the response says only that the
        // lead exists and what to call it.
        return response()->json(['ok' => true, 'id' => $sub->publicToken()], 201);
    }

    /**
     * Flatten the posted body onto the column names, then validate.
     *
     * Normalising BEFORE validation rather than after is the point: one rule
     * set governs both spellings, so a length or type rule cannot be true of
     * the flat key and absent from the nested one. Whichever spelling arrives,
     * what comes back out of here is keyed by column.
     */
    private function validateCapture(Request $request): array
    {
        $body = $request->all();
        $flat = [];

        foreach (self::CAPTURE as $column => $paths) {
            foreach ($paths as $path) {
                $value = data_get($body, $path);
                if (is_string($value) || is_numeric($value)) {
                    $flat[$column] = (string) $value;
                    break;
                }
            }
        }

        $flat['concerns'] = $body['concerns'] ?? null;
        $flat['consent']  = $body['consent'] ?? null;
        $flat['recommended_routines'] = data_get($body, 'recommendedRoutines')
            ?? data_get($body, 'recommended_routines');

        return validator($flat, [
            'skin_type'     => 'nullable|string|max:60',
            // `concerns` was 'nullable' with no type, no length and no content
            // rule, on a public endpoint, into a column the owner's leads
            // screen renders. The screen escapes it now; this stops the table
            // being free storage regardless.
            'concerns'      => 'nullable|array|max:20',
            'concerns.*'    => 'string|max:80',
            'age'           => 'nullable|string|max:30',
            'routine_depth' => 'nullable|string|max:40',
            'budget'        => 'nullable|string|max:40',
            'name'          => 'nullable|string|max:120',
            'phone'         => 'nullable|string|max:40',
            'email'         => 'nullable|email|max:160',
            'consent'       => 'nullable|boolean',
            'recommended_routines'           => 'nullable|array|max:6',
            'recommended_routines.*.name'    => 'nullable|string|max:120',
            'recommended_routines.*.steps'   => 'nullable|array|max:12',
            'recommended_routines.*.steps.*' => 'string|max:120',
        ])->validate();
    }

    /**
     * The recommended routines, reduced to a name and a list of step names.
     *
     * A product list and a bundle total used to ride along in this key --
     * products the shop does not sell, and a total nobody set. They were never
     * stored, because the column was simply not written; now that it is, the
     * reduction is what keeps them out. Each routine contributes two things
     * and the rest of the object is discarded, so a caller posting
     * `{name, steps, products, price}` stores `{name, steps}`.
     */
    private function routinesFrom(array $data): ?array
    {
        $routines = [];

        foreach ($data['recommended_routines'] ?? [] as $routine) {
            if (! is_array($routine)) {
                continue;
            }

            $name = trim((string) ($routine['name'] ?? ''));
            $steps = array_values(array_filter(array_map(
                fn ($s) => trim((string) $s),
                is_array($routine['steps'] ?? null) ? $routine['steps'] : []
            ), fn ($s) => $s !== ''));

            if ($name === '' && $steps === []) {
                continue;
            }

            $routines[] = ['name' => $name, 'steps' => $steps];
        }

        return $routines === [] ? null : $routines;
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
     *
     * That mattered before and matters more now: the rows this addresses hold
     * a shopper's name, WhatsApp number and email, where before the fix above
     * they held a concern list and a blank contact.
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
