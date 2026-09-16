<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Review;
use App\Services\SettingsService;
use App\Support\ReviewSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The review submission form's backend — the one piece the plugin's own
 * markup (reviews.blade.php, ported verbatim) never had: no plugin source
 * for the submit handler was available to port, so this is a fresh,
 * deliberately conservative implementation built to the contract the form
 * itself already defines (its field names, its honeypot, its captcha slot).
 */
class ReviewController extends Controller
{
    /**
     * The HARD ceiling on uploads per submission. Not a default and not
     * settable: `sr_max_photos` may only tighten it, so the owner cannot turn
     * an upload form into free disk space by typing a big number into a box.
     * App\Support\ReviewSettings::PHOTO_CEILING mirrors this value.
     */
    private const MAX_PHOTOS = 6;

    private const CAPTCHA_TTL_MINUTES = 15;

    private const MAX_VOTES_PER_HOUR = 60;

    public function __construct(private SettingsService $settings) {}

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
        /*
         * `sr_allow_submit` is now enforced HERE and not only in the markup.
         *
         * reviews.blade.php has consulted this key since the section was
         * ported: false hides the "Write a Review" button and the whole submit
         * sheet. Nothing enforced it on the way in, so the endpoint went on
         * accepting anything posted straight at it — an owner who turned
         * submissions off got a page that looked closed and a queue that kept
         * filling. The setting had a reader and no teeth; this is the teeth.
         *
         * 403 before the rate limiter, the honeypot and the captcha, because
         * when submissions are off there is nothing to measure, spend or check.
         */
        if (! ReviewSettings::get($this->settings, 'sr_allow_submit')) {
            return response()->json([
                'ok' => false,
                'error' => 'Reviews are not being accepted at the moment.',
            ], 403);
        }

        // Rate-limited by IP before anything else runs — five submissions an
        // hour is generous for a genuine shopper and a real ceiling for a
        // script. Keyed on the IP alone, not the product, so someone cannot
        // route around it by reviewing a different product each time. The
        // number is `sr_rate_limit`, whose default IS five, clamped to 1..50
        // by the schema so the control cannot be used to remove the limit.
        $key = 'review-submit:' . $request->ip();
        $perHour = (int) ReviewSettings::get($this->settings, 'sr_rate_limit');

        if (RateLimiter::tooManyAttempts($key, $perHour)) {
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

        /*
         * `sr_allow_photos` and `sr_max_photos`, the other two keys the view
         * consulted and the server ignored.
         *
         * Photos off meant the file input was not rendered — and the upload
         * loop below still moved anything posted as sr_photos[] into
         * public/uploads/reviews. `sr_max_photos` was worse than inert: the
         * form printed "up to {n}" from it while validation allowed
         * self::MAX_PHOTOS regardless, so the page said 4 and the server took
         * 6. Both are now the same number the shopper was shown.
         *
         * Off is `max:0`, not a skipped rule, so a submission carrying photos
         * is REFUSED with the ordinary 422 rather than quietly accepted with
         * the files dropped. Silently discarding what someone uploaded is how
         * a shopper concludes the site ate their review.
         */
        $photosAllowed = (bool) ReviewSettings::get($this->settings, 'sr_allow_photos');
        $photoMax = $photosAllowed
            ? min(self::MAX_PHOTOS, (int) ReviewSettings::get($this->settings, 'sr_max_photos'))
            : 0;

        $validated = $request->validate([
            // Visible products only, not `exists:products,id`. A bare exists
            // accepted any row in the table, so a draft or hidden product --
            // one with no page and no review form -- could be reviewed by
            // posting its id here, and the accept/reject split told the caller
            // which unpublished ids were real. The refusal is the ordinary 422
            // either way, so a product that does not exist and one that is not
            // published are answered the same.
            'product_id' => [
                'required',
                'integer',
                // Through the shared predicate so a product that is scheduled
                // but not yet launched cannot be reviewed. It is not on the
                // storefront, so nobody has legitimately seen it to review it,
                // and a review dated before the product existed is a thing the
                // owner would have to explain.
                Rule::exists('products', 'id')->where(
                    fn ($q) => \App\Support\ProductVisibility::raw($q, '')
                ),
            ],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'author_name' => ['required', 'string', 'max:100'],
            'author_email' => ['required', 'string', 'max:120', new \App\Rules\StorefrontEmail],
            'title' => ['nullable', 'string', 'max:120'],
            'content' => ['required', 'string', 'max:5000'],
            'sr_photos' => ['nullable', 'array', 'max:' . $photoMax],
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
     *
     * The cookie is the whole of that protection and it is the caller's to
     * throw away, so it is a convenience, not a limit: a script that simply
     * does not send it could increment any counter as often as it liked. The
     * per-IP ceiling below is the limit.
     *
     * Approved rows only, looked up by hand rather than route-model bound.
     * Bound, this took any Review: a pending or spam row answered 200 with its
     * vote count while an id that did not exist answered 404, so the endpoint
     * doubled as a directory of unmoderated reviews — and let anyone vote them
     * up while they waited for a moderator. Not approved and not there are now
     * the same 404, byte for byte, because they are the same return.
     *
     * Typed string, not int. The route puts no numeric constraint on {review}
     * and this file declares strict_types, so an int parameter turns
     * /reviews/abc/helpful into a TypeError and a 500 — where the route-model
     * binding it replaces would have answered 404. Cast here instead, so
     * anything that is not a row is the one 404 above.
     */
    public function helpful(Request $request, string $review): JsonResponse
    {
        $key = 'review-helpful:' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_VOTES_PER_HOUR)) {
            return response()->json([
                'ok' => false,
                'error' => 'Too many votes — please try again later.',
            ], 429);
        }

        $row = Review::query()->approved()->whereKey((int) $review)->first();

        if ($row === null) {
            return response()->json(['ok' => false, 'error' => 'not_found'], 404);
        }

        $voted = array_filter(explode(',', (string) $request->cookie('kbb_sr_voted', '')));

        if (in_array((string) $row->id, $voted, true)) {
            return response()->json(['ok' => true, 'helpful' => $row->helpful, 'already' => true]);
        }

        // Counted against the budget only when it actually writes, so a
        // shopper reloading a page of reviews they have already voted on does
        // not spend the allowance.
        RateLimiter::hit($key, 3600);

        $row->increment('helpful');
        $voted[] = (string) $row->id;

        return response()
            ->json(['ok' => true, 'helpful' => $row->fresh()->helpful])
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
