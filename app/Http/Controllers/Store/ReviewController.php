<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * The review submission form's backend — the one piece the plugin's own
 * markup (reviews.blade.php, ported verbatim) never had: no plugin source
 * for the submit handler was available to port, so this is a fresh,
 * deliberately conservative implementation built to the contract the form
 * itself already defines (its field names, its honeypot, its captcha slot).
 */
class ReviewController extends Controller
{
    private const MAX_PHOTOS = 6;
    private const CAPTCHA_TTL_MINUTES = 15;

    /**
     * A simple arithmetic question, answer carried in a signed, encrypted
     * token rather than server-side session state — one less table, and it
     * works the same whether the shopper is signed in or not.
     */
    public function captcha(): JsonResponse
    {
        $a = random_int(1, 9);
        $b = random_int(1, 9);

        $token = Crypt::encrypt([
            'answer' => $a + $b,
            'expires' => now()->addMinutes(self::CAPTCHA_TTL_MINUTES)->timestamp,
        ]);

        return response()->json(['question' => "{$a} + {$b}", 'token' => $token]);
    }

    public function submit(Request $request): JsonResponse
    {
        // Rate-limited by IP before anything else runs — five submissions an
        // hour is generous for a genuine shopper and a real ceiling for a
        // script. Keyed on the IP alone, not the product, so someone cannot
        // route around it by reviewing a different product each time.
        $key = 'review-submit:' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json([
                'ok' => false,
                'error' => 'Too many reviews submitted — please try again later.',
            ], 429);
        }

        // The honeypot field is never shown to a real visitor (hidden via
        // tabindex + autocomplete in the markup, not display:none, which
        // some scanners skip past). A filled value means a bot filled every
        // field it could find. Answered with the same success shape a real
        // submission gets — telling a bot its submission was rejected only
        // teaches it to look for the next tell.
        if ((string) $request->input('sr_website', '') !== '') {
            RateLimiter::hit($key, 3600);

            return response()->json(['ok' => true, 'message' => 'Thank you for your review!']);
        }

        $captchaError = $this->verifyCaptcha($request);

        if ($captchaError !== null) {
            RateLimiter::hit($key, 3600);

            return response()->json(['ok' => false, 'error' => $captchaError], 422);
        }

        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'author_name' => ['required', 'string', 'max:100'],
            'author_email' => ['required', 'email', 'max:120'],
            'title' => ['nullable', 'string', 'max:120'],
            'content' => ['required', 'string', 'max:5000'],
            'sr_photos' => ['nullable', 'array', 'max:' . self::MAX_PHOTOS],
            'sr_photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'], // 5MB each
        ]);

        RateLimiter::hit($key, 3600);

        $images = [];

        foreach ($request->file('sr_photos', []) as $photo) {
            if (! $photo->isValid()) {
                continue;
            }

            // Written directly under public/, not the storage/ disk + symlink
            // pattern — this app deploys via a signed ZIP through a custom
            // updater, not `php artisan storage:link`, so a required symlink
            // is a real, silent failure risk: the upload would succeed and
            // the photo would 404 forever with nothing in any log to explain
            // why. A random name, not the uploaded filename — the filename is
            // visitor-controlled text and never touches the filesystem path.
            $name = Str::uuid() . '.' . $photo->extension();
            $dir = public_path('uploads/reviews');

            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $photo->move($dir, $name);
            $images[] = '/uploads/reviews/' . $name;
        }

        $review = Review::create([
            'source' => 'kbb',
            'product_id' => $validated['product_id'],
            'customer_id' => auth('customer')->id(),
            'author_name' => strip_tags($validated['author_name']),
            'author_email' => $validated['author_email'],
            'rating' => $validated['rating'],
            'title' => strip_tags($validated['title'] ?? ''),
            'content' => strip_tags($validated['content']),
            'images' => $images,
            'status' => 'pending',
            'ip' => (string) $request->ip(),
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Thank you! Your review is awaiting approval.',
            'id' => $review->id,
        ]);
    }

    /**
     * One vote per browser, not per click — a cookie carrying the set of
     * review IDs already voted on, so the count cannot be inflated by
     * clicking the same button repeatedly.
     */
    public function helpful(Request $request, Review $review): JsonResponse
    {
        $voted = array_filter(explode(',', (string) $request->cookie('kbb_sr_voted', '')));

        if (in_array((string) $review->id, $voted, true)) {
            return response()->json(['ok' => true, 'helpful' => $review->helpful, 'already' => true]);
        }

        $review->increment('helpful');
        $voted[] = (string) $review->id;

        return response()
            ->json(['ok' => true, 'helpful' => $review->fresh()->helpful])
            ->cookie('kbb_sr_voted', implode(',', $voted), 60 * 24 * 365);
    }

    /** @return string|null An error message, or null if the captcha is correct. */
    private function verifyCaptcha(Request $request): ?string
    {
        $token = (string) $request->input('captcha_token', '');
        $answer = (string) $request->input('captcha', '');

        if ($token === '' || $answer === '') {
            return 'Please answer the quick check.';
        }

        try {
            $payload = Crypt::decrypt($token);
        } catch (\Throwable) {
            return 'That check expired — please try again.';
        }

        if (! is_array($payload) || ($payload['expires'] ?? 0) < now()->timestamp) {
            return 'That check expired — please try again.';
        }

        if ((int) $answer !== (int) ($payload['answer'] ?? null)) {
            return 'That answer doesn\'t look right — please try again.';
        }

        return null;
    }
}
