<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Support\EditPresence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The edit-presence heartbeat (Lane RL). See App\Support\EditPresence.
 *
 *   POST /admin-api/presence/beat     presence.view
 *   POST /admin-api/presence/take     presence.takeover
 *   POST /admin-api/presence/release  presence.view
 *
 * Inside the admin-api group, so auth:admin and CSRF (X-XSRF-TOKEN) apply, and
 * each path has its own capability in AdminCapabilities::RULES. The answer is
 * a display name and four facts -- never an email, an id or another admin's
 * token (EditPresenceTest reads every key).
 */
final class EditPresenceController extends Controller
{
    public function beat(Request $request): JsonResponse
    {
        $data = $this->input($request, false);
        if ($data instanceof JsonResponse) {
            return $data;
        }

        $me = $this->me($request);

        return $this->run(function () use ($me, $data) {
            $answer = EditPresence::beat($me, $data['type'], $data['id'], $data['token']);
            // Whether THIS admin may press Take over: a fact about the caller,
            // asked only when somebody else holds the record.
            if (! $answer['you_hold'] && $answer['holder'] !== null && $answer['taken_over_by'] === null) {
                $answer['can_take'] = \App\Support\AdminRoles::can($me, 'presence.takeover');
            }

            return $this->ok($answer);
        });
    }

    public function take(Request $request): JsonResponse
    {
        $data = $this->input($request, true);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $me = $this->me($request);

        return $this->run(fn () => $this->ok(EditPresence::take($me, $data['type'], $data['id'])
            ?? EditPresence::beat($me, $data['type'], $data['id'], null)));
    }

    public function release(Request $request): JsonResponse
    {
        $data = $this->input($request, true);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        return $this->run(function () use ($request, $data) {
            if ($data['token'] !== null) {
                EditPresence::release($this->me($request), $data['type'], $data['id'], $data['token']);
            }

            return response()->json(['ok' => true]);
        });
    }

    /** Bounded input: a known type, a short id, a hex token. Anything else is a 422. */
    private function input(Request $request, bool $needsRecord): array|JsonResponse
    {
        $type = $request->input('type');
        $id = $request->input('id');
        $token = $request->input('token');

        $type = is_string($type) && $type !== '' ? $type : null;
        $id = is_scalar($id) && (string) $id !== '' ? (string) $id : null;
        $token = is_string($token) && $token !== '' ? $token : null;

        if (($type === null) !== ($id === null) || ($needsRecord && $type === null)
            || ($type !== null && ! isset(EditPresence::TYPES[$type]))
            || ($id !== null && ! preg_match('/^[a-z0-9_]{1,64}$/', $id))
            || ($token !== null && ! preg_match('/^[a-f0-9]{32}$/', $token))) {
            return response()->json(['error' => 'invalid', 'message' => 'Not a record this console can hold.'], 422);
        }

        return ['type' => $type, 'id' => $id, 'token' => $token];
    }

    /**
     * The code can be on the server before its migration has run. Say so in
     * one 503 the console reads as "stop beating", rather than a 500 every 15s.
     */
    private function run(\Closure $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (\Illuminate\Database\QueryException $e) {
            if (\Illuminate\Support\Facades\Schema::hasTable(EditPresence::TABLE)) {
                throw $e;
            }

            return response()->json(['error' => 'not_migrated'], 503);
        }
    }

    private function me(Request $request): AdminUser
    {
        /** @var AdminUser $me */
        $me = $request->user('admin');

        return $me;
    }

    private function ok(array $answer): JsonResponse
    {
        // Small, private, never cached by anything between here and the tab.
        return response()->json($answer)->header('Cache-Control', 'no-store');
    }
}
