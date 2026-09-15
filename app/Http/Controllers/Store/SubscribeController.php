<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\NewsletterSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Newsletter signup.
 *
 * Answers JSON to the fetch on the homepage and a redirect to a plain form post,
 * because the form has to keep working with scripts blocked — before this it
 * returned JSON either way, so anyone without the script running got a page of
 * {"ok":true} where the homepage had been.
 */
class SubscribeController extends Controller
{
    public function __construct(private NewsletterSettings $newsletter) {}

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        try {
            $data = $request->validate([
                'email' => ['required', 'string', 'max:160', new \App\Rules\StorefrontEmail],
            ]);
        } catch (ValidationException $e) {
            return $this->fail($request, (string) $this->newsletter->get('nl_error'));
        }

        $email = mb_strtolower(trim($data['email']));

        // Checked before the write so the shopper can be told they are already on
        // the list rather than thanked twice.
        $existing = DB::table('subscribers')->where('email', $email)->exists();

        $row = ['status' => 'subscribed', 'updated_at' => now(), 'created_at' => now()];

        if ($this->newsletter->get('nl_source_tag')) {
            $row['source'] = substr((string) $request->input('source', 'homepage'), 0, 40);
        }

        // Unique on email, so signing up twice is not an error and does not
        // create a second row. Only the timestamp moves.
        DB::table('subscribers')->updateOrInsert(['email' => $email], $row);

        $message = (string) $this->newsletter->get($existing ? 'nl_duplicate' : 'nl_success');

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'message' => $message])
            : back()->with('kbb_subscribed', $message);
    }

    private function fail(Request $request, string $message): JsonResponse|RedirectResponse
    {
        return $request->expectsJson()
            ? response()->json(['ok' => false, 'error' => $message], 422)
            : back()->withInput()->with('kbb_subscribe_error', $message);
    }
}
