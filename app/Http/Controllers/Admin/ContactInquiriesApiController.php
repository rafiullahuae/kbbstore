<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactInquiry;
use App\Support\ContactPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Store → Inquiries: the contact page's messages, and the contact page's own
 * settings.                                                        (Lane CT)
 *
 * LIGHT. One GET draws a page of PER_PAGE rows with the whole message — the
 * detail view is drawn in the browser from data it already has, so opening an
 * inquiry costs no read, only the one POST that marks it read. Two queries a
 * page (the rows and the unread count) whatever the table holds: the count is
 * an indexed COUNT, the page a LIMIT on the primary key.
 *
 * Every row leaves through ContactInquiry::toAdmin(), an allowlist; the screen
 * builds its markup through esc().
 */
class ContactInquiriesApiController extends Controller
{
    public const PER_PAGE = 30;

    public function index(Request $request): JsonResponse
    {
        $unreadOnly = $request->query('filter') === 'unread';
        $page = max(1, min(10000, (int) $request->query('page', 1)));

        $query = ContactInquiry::query()->orderByDesc('id');

        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        // One more than a page, so "is there a next page" needs no COUNT.
        $rows = $query->forPage($page, self::PER_PAGE)->limit(self::PER_PAGE + 1)->get();

        return response()->json([
            'rows' => $rows->take(self::PER_PAGE)->map(fn (ContactInquiry $q) => $q->toAdmin())->values(),
            'page' => $page,
            'more' => $rows->count() > self::PER_PAGE,
            'unread' => ContactInquiry::query()->whereNull('read_at')->count(),
            'filter' => $unreadOnly ? 'unread' : 'all',
        ]);
    }

    public function read(Request $request, int $id): JsonResponse
    {
        $read = filter_var($request->json('read', true), FILTER_VALIDATE_BOOL);

        $n = ContactInquiry::query()->whereKey($id)->update(['read_at' => $read ? now() : null]);

        if ($n === 0) {
            return response()->json(['ok' => false, 'error' => 'That inquiry no longer exists.'], 404);
        }

        return response()->json(['ok' => true, 'read' => $read, 'unread' => ContactInquiry::query()->whereNull('read_at')->count()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $n = ContactInquiry::query()->whereKey($id)->delete();

        if ($n === 0) {
            return response()->json(['ok' => false, 'error' => 'That inquiry no longer exists.'], 404);
        }

        return response()->json(['ok' => true, 'unread' => ContactInquiry::query()->whereNull('read_at')->count()]);
    }

    public function settings(): JsonResponse
    {
        return response()->json($this->settingsPayload());
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $config = $request->json('config');

        if (! is_array($config)) {
            return response()->json(['ok' => false, 'error' => 'Not saved — nothing to save.'], 422);
        }

        $errors = ContactPage::save($config);

        if ($errors !== []) {
            return response()->json([
                'ok' => false,
                'error' => 'Not saved — '.implode(' ', array_values(array_unique($errors))),
                'fields' => $errors,
            ], 422);
        }

        return response()->json(['ok' => true] + $this->settingsPayload());
    }

    private function settingsPayload(): array
    {
        $config = ContactPage::config();

        return [
            'config' => $config,
            'defaults' => ContactPage::defaults(),
            // What each card would show today, so the screen can say "hidden:
            // no address" instead of offering a switch that draws nothing.
            // Lane CT2: what the card prints, the page editor's override
            // included, so the two screens describe the same card.
            'values' => array_column(array_filter(ContactPage::editor()['cards'], static fn (array $c): bool => ! $c['custom']), 'value', 'k'),
            // The address an inquiry goes to while the box is blank.
            'fallback' => ($fb = ContactPage::recipientWithSource(['recipient' => ''] + $config))[0],
            'fallbackFrom' => $fb[1],
            'limits' => ['topics' => ContactPage::MAX_TOPICS, 'topic' => ContactPage::TOPIC_MAX],
        ];
    }
}
