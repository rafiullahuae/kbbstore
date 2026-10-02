<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\CustomerAccountInvite;
use App\Models\Customer;
use App\Services\CustomerInvites\CustomerInviter;
use App\Services\CustomerInvites\InviteTemplate;
use App\Support\Url;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Store → Customers → Send account invite. (Lane PQ)
 *
 * The owner's ask, in his words: "for all guests orders, i want an option to
 * send their account logins with temporary password with reset link etc, but
 * manually i need to select all guests press on send email i will need to see
 * the email template and can be edited."
 *
 * So: he selects (rows, or every customer matching the filter), this answers
 * how many would actually get one and why the rest would not, shows him the
 * real email for the first of them, lets him edit and save the wording, and
 * then sends in batches the console drives one step at a time.
 *
 * Every route here is under /admin-api/customers/invites, inside the admin-api
 * group (auth:admin) and mapped to `customers.invite` — owner and manager —
 * in AdminCapabilities::RULES. A support account can read customers and cannot
 * send mail to all of them. See CustomerInviter for the security decision
 * (a one-time link, never a password).
 */
class CustomerInvitesController extends Controller
{
    public function __construct(
        private CustomerInviter $inviter,
        private InviteTemplate $template,
    ) {}

    /* --------------------------------------------------------------- template */

    public function template(): JsonResponse
    {
        return response()->json($this->templatePayload());
    }

    public function saveTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:2000'],
            'body' => ['required', 'string', 'max:20000'],
            'expiry_days' => ['nullable', 'integer', 'min:' . InviteTemplate::MIN_EXPIRY_DAYS, 'max:' . InviteTemplate::MAX_EXPIRY_DAYS],
        ]);

        $problems = InviteTemplate::problems($data['subject'], $data['body']);

        if ($problems !== []) {
            return response()->json(['ok' => false, 'message' => reset($problems), 'errors' => $problems], 422);
        }

        $this->template->save($data['subject'], $data['body'], (int) ($data['expiry_days'] ?? InviteTemplate::DEFAULT_EXPIRY_DAYS));

        return response()->json(['ok' => true] + $this->templatePayload());
    }

    /* -------------------------------------------------------------- selection */

    /**
     * Who in this selection would be sent an invite, and who would not.
     */
    public function prepare(Request $request): JsonResponse
    {
        $ids = $this->selection($request);

        if ($ids instanceof JsonResponse) {
            return $ids;
        }

        $split = $this->inviter->classify($ids, false);
        $first = $split['eligible'][0] ?? ($split['recent_ids'][0] ?? null);

        return response()->json([
            'selected' => count($ids),
            'eligible' => count($split['eligible']),
            'has_password' => $split['has_password'],
            'invalid_email' => $split['invalid_email'],
            'recent' => $split['recent'],
            'missing' => $split['missing'],
            'recent_minutes' => CustomerInviter::RECENT_MINUTES,
            'preview_customer' => $first === null ? null : $this->customerSummary((int) $first),
            'unfinished_run' => $this->unfinished(),
        ] + $this->templatePayload());
    }

    /**
     * The real email for one customer, rendered by the same Mailable and views
     * that send it. The link is a dummy that does not work, and says so.
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'integer'],
            'subject' => ['required', 'string', 'max:2000'],
            'body' => ['required', 'string', 'max:20000'],
            'expiry_days' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $customer = isset($data['customer_id'])
            ? Customer::query()->find((int) $data['customer_id'])
            : null;

        // A stand-in, never persisted, when nothing is selected yet.
        $customer ??= new Customer(['name' => 'Sara Ahmed', 'first_name' => 'Sara', 'email' => 'sara@example.com']);

        $expires = CarbonImmutable::now()->addDays(InviteTemplate::clampExpiry((int) ($data['expiry_days'] ?? InviteTemplate::DEFAULT_EXPIRY_DAYS)));
        $link = Url::external('/my-account/welcome/preview-link-is-created-when-sent/');
        $values = $this->template->values($customer, $link, $expires);

        $mailable = new CustomerAccountInvite(
            InviteTemplate::subject($data['subject'], $values),
            InviteTemplate::segments($data['body'], $values),
            InviteTemplate::text($data['body'], $values),
            $link,
            $values['shop_name'],
        );

        return response()->json([
            'subject' => InviteTemplate::subject($data['subject'], $values),
            'html' => (string) $mailable->render(),
            'text' => InviteTemplate::text($data['body'], $values),
            'to' => (string) $customer->email,
            'problems' => InviteTemplate::problems($data['subject'], $data['body']),
        ]);
    }

    /* ------------------------------------------------------------------ send */

    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:2000'],
            'body' => ['required', 'string', 'max:20000'],
            'expiry_days' => ['required', 'integer', 'min:' . InviteTemplate::MIN_EXPIRY_DAYS, 'max:' . InviteTemplate::MAX_EXPIRY_DAYS],
            'include_recent' => ['nullable', 'boolean'],
            // The count the owner confirmed. If the selection has moved since
            // (a guest set a password, a filter now matches more), he is asked
            // again rather than sent something he did not see a number for.
            'expected' => ['required', 'integer', 'min:0'],
        ]);

        $problems = InviteTemplate::problems($data['subject'], $data['body']);

        if ($problems !== []) {
            return response()->json(['ok' => false, 'message' => reset($problems), 'errors' => $problems], 422);
        }

        if (($running = $this->inviter->unfinishedRunId()) !== null) {
            return response()->json([
                'ok' => false,
                'message' => 'Another invite send has not finished yet. Resume or cancel it first.',
                'unfinished_run' => $this->inviter->progress($running),
            ], 409);
        }

        $ids = $this->selection($request);

        if ($ids instanceof JsonResponse) {
            return $ids;
        }

        $includeRecent = (bool) ($data['include_recent'] ?? false);
        $split = $this->inviter->classify($ids, $includeRecent);

        if (count($split['eligible']) !== (int) $data['expected']) {
            return response()->json([
                'ok' => false,
                'needs_confirmation' => true,
                'eligible' => count($split['eligible']),
                'message' => 'The selection changed since you looked: ' . count($split['eligible'])
                    . ' customers would now be sent an invite, not ' . (int) $data['expected'] . '. Nothing was sent.',
            ], 409);
        }

        if ($split['eligible'] === []) {
            return response()->json(['ok' => false, 'message' => 'Nobody in this selection can be sent an invite.'], 422);
        }

        $runId = $this->inviter->start(
            $ids,
            $data['subject'],
            $data['body'],
            (int) $data['expiry_days'],
            $includeRecent,
            ($id = auth('admin')->id()) === null ? null : (int) $id,
        );

        return response()->json(['ok' => true, 'run' => $this->inviter->progress($runId)]);
    }

    public function current(): JsonResponse
    {
        return response()->json(['run' => $this->unfinished()]);
    }

    public function show(int $run): JsonResponse
    {
        $progress = $this->inviter->progress($run, 500);

        return $progress === null
            ? response()->json(['ok' => false, 'message' => 'No such send.'], 404)
            : response()->json(['run' => $progress]);
    }

    public function step(int $run): JsonResponse
    {
        $progress = $this->inviter->step($run);

        return $progress === null
            ? response()->json(['ok' => false, 'message' => 'No such send.'], 404)
            : response()->json(['run' => $progress]);
    }

    public function cancel(int $run): JsonResponse
    {
        $progress = $this->inviter->cancel($run);

        return $progress === null
            ? response()->json(['ok' => false, 'message' => 'No such send.'], 404)
            : response()->json(['run' => $progress]);
    }

    /* --------------------------------------------------------------- helpers */

    /**
     * The ids the owner selected: an explicit list, or every customer matching
     * the list's filters ("select all N matching this view").
     *
     * @return list<int>|JsonResponse
     */
    private function selection(Request $request): array|JsonResponse
    {
        $data = $request->validate([
            'all_matching' => ['nullable', 'boolean'],
            'filters' => ['nullable', 'array'],
            'filters.*' => ['nullable', 'string', 'max:200'],
            'ids' => ['nullable', 'array', 'max:' . CustomerInviter::RUN_MAX],
            'ids.*' => ['integer'],
        ]);

        if ((bool) ($data['all_matching'] ?? false)) {
            return app(CustomersApiController::class)->matchingIds((array) ($data['filters'] ?? []), CustomerInviter::RUN_MAX);
        }

        $ids = array_values(array_unique(array_map('intval', (array) ($data['ids'] ?? []))));

        if ($ids === []) {
            return response()->json(['ok' => false, 'message' => 'Select at least one customer.'], 422);
        }

        return $ids;
    }

    private function unfinished(): ?array
    {
        $id = $this->inviter->unfinishedRunId();

        return $id === null ? null : $this->inviter->progress($id);
    }

    /** @return array<string, mixed> */
    private function templatePayload(): array
    {
        $current = $this->template->current();

        return [
            'template' => $current,
            'defaults' => [
                'subject' => InviteTemplate::DEFAULT_SUBJECT,
                'body' => InviteTemplate::DEFAULT_BODY,
                'expiry_days' => InviteTemplate::DEFAULT_EXPIRY_DAYS,
            ],
            'placeholders' => InviteTemplate::PLACEHOLDERS,
            'expiry_range' => [InviteTemplate::MIN_EXPIRY_DAYS, InviteTemplate::MAX_EXPIRY_DAYS],
        ];
    }

    /** An allowlist, like every other customer payload on this screen. */
    private function customerSummary(int $id): ?array
    {
        $c = Customer::query()->find($id, ['id', 'name', 'first_name', 'last_name', 'email']);

        return $c === null ? null : [
            'id' => (int) $c->id,
            'name' => (string) $c->displayName(),
            'email' => (string) $c->email,
        ];
    }
}
