<?php

declare(strict_types=1);

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Services\Reviews\ReviewRequests;
use App\Services\SettingsService;
use App\Support\ReviewPhotos;
use App\Support\ReviewSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * The page the review request email's stars open (Lane RV).
 *
 *   GET  /review-order/{token}/          the email's link: checks the token, puts it in
 *                                        this browser's session, 303 to /review-order/
 *   GET  /review-order/                  the form: every product the email asked
 *                                        about, ?p=&r= preselects a rating
 *   POST /review-order/                  store them, as PENDING reviews
 *   GET  /review-order/{token}/stop/     "stop these emails?" — changes nothing
 *   POST /review-order/{token}/stop/     the press, and RFC 8058 one-click
 *
 * THE TOKEN IS THE CREDENTIAL. ReviewRequests::find() answers null for a forged
 * token, one never issued and an expired one, after the same work, and every
 * null renders the same page with the same 404 — nothing tells a prober which
 * it hit. No order id is ever in a URL.
 *
 * WHAT IT STORES are real rows in `reviews`, exactly the shape the product
 * page's own form writes (Store\ReviewController), plus what this page knows
 * that one cannot: `verified` (the address bought it — the token proves it),
 * `source` = ReviewRequests::SOURCE and `source_id` = order × KEY_BASE +
 * product, so the admin Reviews list marks them as from the email and
 * reviews_source_unique makes one product of one order exactly one review. Always `pending`: the owner approves them in Reviews like every
 * other, and nothing reaches the shop until he does.
 *
 * ONE REVIEW PER PRODUCT PER ORDER. Submitting again EDITS a review still
 * pending; one already approved (or rejected) is left alone and the page says
 * so. The request row is locked for the write, so two tabs cannot make two.
 *
 * NO SCRIPT. The stars are radio buttons drawn as stars by CSS; the rating the
 * email's star chose is a `checked` attribute. Nothing measures anything.
 *
 * NOT CACHEABLE, NOT INDEXED, AND THE CREDENTIAL NEVER STAYS IN THE ADDRESS
 * BAR: Cache-Control no-store (the page carries a customer's name),
 * X-Robots-Tag noindex, and the token URL 303s to /review-order/ after handing
 * the token to the session — so no canonical, og:url, pixel or Referer ever
 * carries it. And it is outside every InstantNav prefix, so nothing prefetches it.
 */
class ReviewRequestController extends Controller
{
    private const MAX_PHOTOS = 6;

    public function __construct(private ReviewRequests $requests, private SettingsService $settings) {}

    /** Session key holding the token of the request this browser opened. */
    public const SESSION_KEY = 'kbb.review_request.token';

    public const STOP_KEY = 'kbb.review_request.stop';

    /**
     * The email's link. Checks the token, hands it to this browser's session
     * and moves to /review-order/ WITHOUT it, so the address bar — and with it
     * the canonical, og:url, any analytics or pixel that reads the location,
     * and a Referer — never carries the credential. ?p=&r= (two integers) go
     * along, so the star that was tapped stays chosen.
     */
    public function open(Request $request, string $token): BaseResponse
    {
        $row = $this->requests->find($token);

        if ($row === null) {
            return $this->invalid();
        }

        $request->session()->put(self::SESSION_KEY, $token);

        if ($row->opened_at === null) {
            DB::table(ReviewRequests::TABLE)->where('id', $row->id)->whereNull('opened_at')->update(['opened_at' => now()]);
        }

        $query = array_filter([
            'p' => max(0, (int) $request->query('p', 0)),
            'r' => min(5, max(0, (int) $request->query('r', 0))),
        ]);

        return $this->private(redirect()->to(\App\Support\Url::to(ReviewRequests::PATH) . ($query !== [] ? '?' . http_build_query($query) : ''), 303));
    }

    public function show(Request $request): BaseResponse
    {
        $token = (string) $request->session()->get(self::SESSION_KEY, '');
        $row = $this->requests->find($token);

        if ($row === null) {
            return $this->invalid();
        }

        return $this->page($request, $token, $row);
    }

    public function submit(Request $request): BaseResponse
    {
        $token = (string) $request->session()->get(self::SESSION_KEY, '');

        if (! ReviewSettings::get($this->settings, 'sr_allow_submit')) {
            return $this->private(response()->view('store.review-request.message', [
                'title' => __('store.review_request.title'),
                'lead' => __('store.review_request.closed_lead'),
            ], 403));
        }

        // The SAME bucket the product page's form spends (Store\ReviewController),
        // so the hourly ceiling per address is one number, not two.
        $key = 'review-submit:' . $request->ip();
        $perHour = (int) ReviewSettings::get($this->settings, 'sr_rate_limit');

        if (RateLimiter::tooManyAttempts($key, $perHour)) {
            return $this->private(response()->view('store.review-request.message', [
                'title' => __('store.review_request.title'),
                'lead' => __('store.review_request.too_many'),
            ], 429));
        }

        // Honeypot: answered like a success, stores nothing (ReviewController's reasoning).
        if ((string) $request->input('sr_website', '') !== '') {
            RateLimiter::hit($key, 3600);

            return $this->private(response()->view('store.review-request.message', [
                'title' => __('store.review_request.thanks_title'),
                'lead' => __('store.review_request.thanks_lead'),
            ]));
        }

        $row = $this->requests->find($token);

        if ($row === null) {
            return $this->invalid();
        }

        $ids = $this->productIds($row);
        $photosAllowed = (bool) ReviewSettings::get($this->settings, 'sr_allow_photos');
        $photoMax = $photosAllowed ? min(self::MAX_PHOTOS, (int) ReviewSettings::get($this->settings, 'sr_max_photos')) : 0;

        $validator = Validator::make($request->all(), [
            'author_name' => ['required', 'string', 'max:100'],
            'reviews' => ['nullable', 'array', 'max:' . max(1, count($ids))],
            'reviews.*.rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'reviews.*.title' => ['nullable', 'string', 'max:120'],
            'reviews.*.content' => ['nullable', 'string', 'max:5000'],
            'photos' => ['nullable', 'array'],
            'photos.*' => ['nullable', 'array', 'max:' . $photoMax],
            'photos.*.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $products = Product::query()->whereIn('id', $ids)->get(['id', 'name'])->keyBy('id');
        $input = (array) $request->input('reviews', []);
        $chosen = [];

        $validator->after(function ($v) use ($ids, $input, $products, &$chosen): void {
            foreach ($ids as $id) {
                $one = is_array($input[$id] ?? null) ? $input[$id] : [];
                $rating = (int) ($one['rating'] ?? 0);

                if ($rating < 1) {
                    continue;
                }

                if (trim(strip_tags((string) ($one['content'] ?? ''))) === '') {
                    $v->errors()->add('reviews.' . $id . '.content', __('store.review_request.err_text', ['product' => (string) ($products[$id]->name ?? '')]));
                }

                $chosen[] = $id;
            }

            if ($chosen === []) {
                $v->errors()->add('reviews', __('store.review_request.err_none'));
            }
        });

        if ($validator->fails()) {
            return redirect()->to(\App\Support\Url::to(ReviewRequests::PATH) . '#rr-form')->withErrors($validator)->withInput($request->except('photos'));
        }

        RateLimiter::hit($key, 3600);

        $order = Order::query()->find((int) $row->order_id);

        if ($order === null) {
            return $this->invalid();
        }

        $name = trim(strip_tags((string) $request->input('author_name')));
        $files = (array) $request->file('photos', []);

        DB::transaction(function () use ($row, $chosen, $input, $files, $order, $name, $photoMax, $request): void {
            DB::table(ReviewRequests::TABLE)->where('id', $row->id)->lockForUpdate()->first();

            $existing = ReviewRequests::reviewsOf(Review::query(), (int) $row->order_id)
                ->whereIn('product_id', $chosen)
                ->get()
                ->keyBy('product_id');

            foreach ($chosen as $id) {
                $one = $input[$id];
                $review = $existing[$id] ?? null;

                // Approved or rejected: the owner has decided it. Left alone.
                if ($review !== null && (string) $review->status !== 'pending') {
                    continue;
                }

                $images = $review !== null && is_array($review->images) ? $review->images : [];

                foreach (array_slice((array) ($files[$id] ?? []), 0, max(0, $photoMax - count($images))) as $photo) {
                    $stored = $photo instanceof \Illuminate\Http\UploadedFile ? ReviewPhotos::store($photo) : null;

                    if ($stored !== null) {
                        $images[] = $stored;
                    }
                }

                $fields = [
                    'author_name' => $name,
                    'rating' => (int) $one['rating'],
                    'title' => strip_tags((string) ($one['title'] ?? '')),
                    'content' => strip_tags((string) $one['content']),
                    'images' => $images,
                    'ip' => (string) $request->ip(),
                ];

                if ($review !== null) {
                    $review->update($fields);

                    continue;
                }

                Review::create($fields + [
                    'source' => ReviewRequests::SOURCE,
                    // One per product per order, enforced by reviews_source_unique.
                    'source_id' => ReviewRequests::sourceId((int) $row->order_id, $id),
                    'product_id' => $id,
                    'customer_id' => $order->customer_id,
                    'author_email' => (string) $order->email,
                    'status' => 'pending',
                    'verified' => true,
                ]);
            }

            DB::table(ReviewRequests::TABLE)->where('id', $row->id)->update([
                'reviews_count' => ReviewRequests::reviewsOf(Review::query(), (int) $row->order_id)->count(),
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return redirect()->to(\App\Support\Url::to(ReviewRequests::PATH) . '?thanks=1');
    }

    /**
     * The unsubscribe link in the email. Changes nothing and checks nothing —
     * so a valid and a forged token cost the same and look the same — it
     * only hands the token to this browser's session and moves to
     * /review-order/stop/, so the credential leaves the address bar.
     */
    public function stopLink(Request $request, string $token): BaseResponse
    {
        $request->session()->put(self::STOP_KEY, $token);

        return $this->private(redirect()->to(\App\Support\Url::to(ReviewRequests::PATH . 'stop/'), 303));
    }

    /** "Stop review request emails?" — one button. */
    public function stopForm(): BaseResponse
    {
        return $this->private(response()->view('store.review-request.stop', ['done' => false]));
    }

    /** The button: the token from this browser's session. */
    public function stopPress(Request $request): BaseResponse
    {
        return $this->stop((string) $request->session()->pull(self::STOP_KEY, ''));
    }

    /**
     * The press, and the mail client's RFC 8058 one-click POST (no session, no
     * CSRF token — the token in the path is the credential). The same page for
     * every token, valid or not, and an expired link still unsubscribes: an
     * unsubscribe that stops working is how a shop collects spam complaints.
     */
    public function stop(string $token): BaseResponse
    {
        $row = $this->requests->find($token, true);

        if ($row !== null) {
            ReviewRequests::optOut((string) $row->email);
        }

        return $this->private(response()->view('store.review-request.stop', ['done' => true]));
    }

    /* ------------------------------------------------------------ internals */

    private function page(Request $request, string $token, object $row): BaseResponse
    {
        $ids = $this->productIds($row);
        $order = Order::query()->with('items')->find((int) $row->order_id);

        if ($order === null) {
            return $this->invalid();
        }

        $products = Product::query()->with('brand')->whereIn('id', $ids)->get()->keyBy('id');
        $reviews = ReviewRequests::reviewsOf(Review::query(), (int) $row->order_id)
            ->get(['id', 'product_id', 'rating', 'title', 'content', 'status', 'author_name', 'images'])
            ->keyBy('product_id');

        $items = [];

        foreach ($order->items as $item) {
            $id = (int) ($item->product_id ?? 0);

            if ($id > 0 && ! isset($items[$id])) {
                $items[$id] = $item;
            }
        }

        $pickP = (int) $request->query('p', 0);
        $pickR = (int) $request->query('r', 0);
        $cards = [];

        foreach ($ids as $id) {
            $product = $products[$id] ?? null;
            $item = $items[$id] ?? null;

            if ($product === null) {
                continue;
            }

            $review = $reviews[$id] ?? null;
            // The card's own rule (components/product-card): a picture known to be
            // missing is no picture, and every one offers its img-cache copies.
            $image = \App\Support\LostPictures::usable($product->image);
            $image = is_string($image) && $image !== '' ? $image : null;

            $cards[] = [
                'id' => $id,
                'name' => $item !== null && trim((string) ($item->name_localised ?? '')) !== '' ? (string) $item->name_localised : (string) $product->name,
                'brand' => trim((string) ($item?->brand ?: ($product->brand?->name ?? ''))),
                // (Lane VPA) "Shade: #13 Bright Beige", in the order's language.
                'variant' => $item !== null ? \App\Support\VariantText::forOrderItem($item, true, ', ') : '',
                'image' => $image,
                'srcset' => $image === null ? '' : \App\Support\ImageVariants::srcsetFor($image),
                'url' => \App\Support\Url::to('/product/' . $product->slug . '/'),
                'rating' => $review !== null ? (int) $review->rating : ($pickP === $id && $pickR >= 1 && $pickR <= 5 ? $pickR : 0),
                'title' => (string) ($review->title ?? ''),
                'content' => (string) ($review->content ?? ''),
                'status' => $review !== null ? (string) $review->status : null,
                'photos' => $review !== null && is_array($review->images) ? count($review->images) : 0,
            ];
        }

        $billing = is_array($order->billing_address) ? $order->billing_address : [];
        $first = trim((string) ($billing['first_name'] ?? ''));
        $last = trim((string) ($billing['last_name'] ?? ''));
        $defaultName = trim($first . ($last !== '' ? ' ' . mb_strtoupper(mb_substr($last, 0, 1)) . '.' : ''));
        $firstReview = $reviews->first();

        $photosAllowed = (bool) ReviewSettings::get($this->settings, 'sr_allow_photos');

        return $this->private(response()->view('store.review-request.page', [
            'token' => $token,
            'cards' => $cards,
            'firstName' => $first,
            'authorName' => $firstReview !== null ? (string) $firstReview->author_name : $defaultName,
            'thanks' => $request->query('thanks') === '1',
            'photoMax' => $photosAllowed ? min(self::MAX_PHOTOS, (int) ReviewSettings::get($this->settings, 'sr_max_photos')) : 0,
            'open' => (bool) ReviewSettings::get($this->settings, 'sr_allow_submit'),
        ]));
    }

    /** @return list<int> */
    private function productIds(object $row): array
    {
        $ids = json_decode((string) ($row->products ?? '[]'), true);

        return is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : [];
    }

    private function invalid(): BaseResponse
    {
        return $this->private(response()->view('store.review-request.message', [
            'title' => __('store.review_request.invalid_title'),
            'lead' => __('store.review_request.invalid_lead'),
        ], 404));
    }

    private function private(BaseResponse $response): BaseResponse
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, max-age=0, must-revalidate');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
