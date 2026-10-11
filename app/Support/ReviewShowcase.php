<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Review;
use Illuminate\Support\Facades\DB;

/**
 * The data behind the "customer reviews" section DESIGN PREVIEWS (Lane RD,
 * resources/views/partials/review-showcase/). PREVIEW ONLY: nothing in the
 * shop calls this class, no route renders those partials, and the owner picks
 * a letter before any of it is wired in. tools/rdr-render.php is the only
 * caller.
 *
 * WHAT IT READS, AND HOW OFTEN. Four statements, whatever the size of the
 * shop -- measured flat in tools/rdr-render.php at 40 and at 80 approved rows:
 *
 *   1. ReviewWall::summary() -- one grouped COUNT, the statement /reviews/
 *      already prints its headline from, so a section and the page its "See
 *      all reviews" link opens cannot disagree about the score.
 *   2. the reviews, LIMITed, named columns only (never author_email or ip)
 *   3. their products, one eager load (id, name, slug, image)
 *   4. the reviewers' default-address city, one whereIn on `addresses`
 *
 * plus the translation map on an Arabic page, which TranslationStore already
 * memoises per request.
 *
 * WHAT IT SHOWS. Approved, real-provenance (->real(), so demo seeds never),
 * 4 stars and up, with text. The score above the cards is computed from every
 * approved review, so the 1-3 star rows are in the number and the bars even
 * though the cards feature the better ones -- the honest arrangement, and the
 * one ReviewWall already uses for its headline.
 *
 * NAMES. "Aisha K." -- first name and last initial, never a surname; an Arabic
 * name prints its first word only, since a lone Arabic letter with a period
 * reads as a typo rather than an initial.
 *
 * CITY. From the reviewer's default address when the review is tied to a
 * customer, else nothing. A decision for the owner before wiring: it is
 * accurate and it is what makes the cards feel local, but customers never
 * agreed to it being printed. See the report.
 */
final class ReviewShowcase
{
    /** Letter => [name, is a carousel]. */
    public const DESIGNS = [
        'a' => ['Card carousel', true],
        'b' => ['Spotlight quote', true],
        'c' => ['Marquee ticker', true],
        'd' => ['Photo reviews carousel', true],
        'e' => ['Score + featured', false],
        'f' => ['Masonry wall', false],
        'g' => ['Split hero quote', false],
        'h' => ['Compact trust strip', false],
    ];

    /** Cards the preview reads. Every design takes a slice of the same list. */
    public const LIMIT = 16;

    /** The lowest star rating a featured card may carry. */
    public const MIN_STARS = 4;

    /** The columns the cards print, and the two they join on. */
    private const COLUMNS = [
        'id', 'product_id', 'customer_id', 'author_name', 'rating', 'title',
        'content', 'images', 'verified', 'created_at',
    ];

    /**
     * New interface words, English and Arabic. Kept here while these are
     * previews; wiring moves them into InterfaceStrings / ArabicInterfaceDrafts
     * beside the store.home.reviews_* keys the designs already reuse.
     */
    private const WORDS = [
        'eyebrow' => ['Customer reviews', 'آراء العملاء'],
        'see_all' => ['See all reviews', 'عرض كل التقييمات'],
        'verified' => ['Verified buyer', 'مشترٍ موثّق'],
        'out_of' => ['out of 5', 'من 5'],
        'rated' => ['Rated :n out of 5', 'التقييم :n من 5'],
        'prev' => ['Previous review', 'التقييم السابق'],
        'next' => ['Next review', 'التقييم التالي'],
        'go' => ['Show review :n', 'عرض التقييم :n'],
        'pause' => ['Pause', 'إيقاف مؤقت'],
        'play' => ['Play', 'تشغيل'],
        'shop' => ['Shop now', 'تسوّق الآن'],
        'reviewed' => ['Reviewed', 'المنتج'],
        'store' => ['Review of our store', 'تقييم للمتجر'],
        'loved' => ['Loved by K-beauty fans across the UAE', 'محبوب من عشّاق الجمال الكوري في الإمارات'],
        'stars_n' => [':n stars', ':n نجوم'],
        'region' => ['Customer reviews, carousel', 'آراء العملاء، عرض متحرك'],
        'ticker' => ['Customer reviews, moving strip', 'آراء العملاء، شريط متحرك'],
    ];

    public static function w(string $key, array $replace = []): string
    {
        $pair = self::WORDS[$key] ?? [$key, $key];
        $text = Locale::current() === 'ar' ? $pair[1] : $pair[0];

        foreach ($replace as $k => $v) {
            $text = str_replace(':'.$k, (string) $v, $text);
        }

        return $text;
    }

    /**
     * @return array{summary: array, items: list<array>, photos: list<array>, all: string}
     */
    public static function data(int $limit = self::LIMIT): array
    {
        $summary = ReviewWall::summary();

        $rows = Review::query()->approved()->real()
            ->select(self::COLUMNS)
            ->whereNotNull('content')
            ->where('content', '<>', '')
            ->where('rating', '>=', self::MIN_STARS)
            ->with('product:id,name,slug,image')
            ->latest()
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $ids = $rows->pluck('customer_id')->filter()->unique()->values()->all();
        $cities = $ids === [] ? collect() : DB::table('addresses')
            ->whereIn('customer_id', $ids)
            ->where('is_default', true)
            ->whereNotNull('city')
            ->orderBy('id')
            ->pluck('city', 'customer_id');

        $locale = Locale::current();
        $items = [];

        foreach ($rows as $r) {
            $items[] = self::card($r, $cities, $locale);
        }

        /*
         * The page's own language first, then the rest, each keeping its
         * newest-first order: an English page leads with English reviews and
         * /ar/ with Arabic ones. A stable partition of rows already read, so it
         * costs no query and drops nothing.
         */
        $arabic = static fn (array $c): bool => (bool) preg_match('/\p{Arabic}/u', $c['text']);
        $mine = $locale === 'ar' ? $arabic : static fn (array $c): bool => ! $arabic($c);
        $items = array_merge(
            array_values(array_filter($items, $mine)),
            array_values(array_filter($items, static fn (array $c): bool => ! $mine($c))),
        );

        return [
            'summary' => $summary,
            'items' => $items,
            'photos' => array_values(array_filter($items, static fn (array $c): bool => $c['photo'] !== null)),
            'all' => Url::to('/reviews/'),
        ];
    }

    /** One review, as every design prints it. */
    private static function card(Review $r, $cities, string $locale): array
    {
        $product = null;

        if ($r->product) {
            $img = LostPictures::usable((string) ($r->product->image ?? '')) ?: null;
            $product = [
                'name' => (string) $r->product->t('name'),
                'url' => $r->product->url(),
                'img' => $img,
                'srcset' => $img ? ImageVariants::srcsetFor($img) : '',
            ];
        }

        $photo = null;

        foreach (is_array($r->images) ? $r->images : [] as $u) {
            $u = SafeUrl::src(is_string($u) ? $u : '');

            if ($u !== '') {
                $photo = ['src' => $u, 'srcset' => ImageVariants::srcsetFor($u)];
                break;
            }
        }

        $city = $r->customer_id ? trim((string) ($cities[$r->customer_id] ?? '')) : '';
        $date = $r->created_at?->copy()->locale($locale);

        return [
            'id' => (int) $r->id,
            'name' => self::displayName((string) $r->author_name),
            'initial' => mb_strtoupper(mb_substr(trim((string) $r->author_name) ?: '?', 0, 1)),
            'city' => $city,
            'rating' => max(1, min(5, (int) $r->rating)),
            'title' => trim((string) $r->title),
            'text' => trim(strip_tags((string) $r->content)),
            'verified' => (bool) $r->verified,
            'date' => $date ? $date->translatedFormat('j M Y') : '',
            'iso' => $date ? $date->toDateString() : '',
            'product' => $product,
            'photo' => $photo,
        ];
    }

    /** "Aisha Khan" -> "Aisha K."; "مريم المنصوري" -> "مريم"; "Joanna" -> "Joanna". */
    public static function displayName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $first = $parts[0] ?? '';

        if ($first === '') {
            return '';
        }

        $last = end($parts);

        if (count($parts) > 1 && preg_match('/^\p{Latin}/u', (string) $last)) {
            return $first.' '.mb_strtoupper(mb_substr((string) $last, 0, 1)).'.';
        }

        return $first;
    }
}
