<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Mail\QuizPlanEmail;
use App\Models\QuizSubmission;
use App\Rules\StorefrontEmail;
use App\Services\Mail\MailConfigurator;
use App\Services\Mail\MailLog;
use App\Support\Locale;
use App\Support\QuizRoutineLink;
use App\Support\Url;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

use function Illuminate\Support\defer;
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

        // The email the contact step promises, sent after the response has
        // gone. See dispatchPlan(): until this lane the shop asked for an
        // address under "We'll save your results & email your plan" and sent
        // nothing at all.
        $this->dispatchPlan($sub, $request);

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

        /*
         * A STRING OF CONCERNS IS STILL A LIST OF CONCERNS.
         *
         * The page posts an array and always has, but this endpoint accepted
         * `concerns` as a bare 'nullable' for its whole life, so a caller
         * posting "Hydration,Acne" got a 201 and a row. Tightening the rule to
         * `array` without this would turn that caller into a 422 — a contract
         * narrowed on a public endpoint to no benefit, since the column is a
         * comma-joined string either way. Split here, so one rule set governs
         * both spellings of this field as well.
         */
        $concerns = $body['concerns'] ?? null;

        if (is_string($concerns)) {
            $concerns = array_values(array_filter(array_map('trim', explode(',', $concerns)), fn ($c) => $c !== ''));
        }

        $flat['concerns'] = $concerns;
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
            /*
             * StorefrontEmail, not `email`, AND IT IS THIS LANE THAT OWES IT.
             *
             * CLAUDE.md records CRLF injection in the framework's own email
             * rule as one of three open advisories on this install, unfixable
             * short of a 12.x upgrade. Rules\StorefrontEmail's header names the
             * condition that makes it reachable: "an unauthenticated stranger
             * hands us an address and we put an address into a message". Until
             * this lane the second half was not true here — the quiz stored the
             * address and nothing ever mailed it — and dispatchPlan() below is
             * what makes it true. The rule moves in the same commit as the
             * send, not after it.
             *
             * `max:160` stays: the rule's own ceiling is RFC 5321's 254 and
             * this column is a string(160).
             */
            'email'         => ['nullable', 'string', 'max:160', new StorefrontEmail],
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
     * Send the shopper the plan the contact step promised them — Lane FT.
     *
     * ── THE PROMISE THIS KEEPS ─────────────────────────────────────────────
     *
     * "We'll save your results & email your plan. No spam, ever." is printed
     * directly under the box this endpoint's `email` comes from, and the
     * results screen repeats it ("emailed to :email"). Nothing in app/Mail or
     * app/Services/Mail referenced the quiz, so the shop saved the results and
     * emailed nobody. Established by running rather than by reading: an
     * unmodified POST /api/quiz passes Mail::assertNothingOutgoing().
     *
     * IT COULD BE BUILT, and the reason the risk register gave for thinking it
     * could not is out of date. Sending here does not wait on SMTP credentials
     * the owner has never supplied: MailSettings' default transport is
     * TRANSPORT_SERVER — the host's own mail — MailConfigurator stopped falling
     * back to the `log` transport for an unconfigured shop, and this shop
     * already sends order confirmations, password resets and newsletter
     * confirmations through exactly this mailer. Measured on a clean database:
     * transport() is 'server' with nothing filled in.
     *
     * ── DEFERRED, FOR THE TWO REASONS SubscribeController GIVES ────────────
     *
     * The first is the shopper: an SMTP handshake takes far longer than the
     * rest of this request and varies wildly, and nobody may sit watching a
     * spinner at the end of a quiz while this shop negotiates TLS. The row is
     * written before this is scheduled — the email is a consequence of the
     * capture, never a precondition for it, and a dead mail server must not
     * turn a captured lead into a 500.
     *
     * The second is that this endpoint is public and its answer must not vary:
     * an inline send would make "this address got a plan" and "it did not"
     * distinguishable with a stopwatch. `defer()` and not
     * `app()->terminating()`, and NAMED — terminating callbacks are never
     * cleared from the Application, which is harmless under PHP-FPM and a
     * duplicate send under anything serving two requests in one process.
     *
     * ── THE LANGUAGE IS CAPTURED HERE AND APPLIED THERE ────────────────────
     *
     * `quiz_submissions` has no `locale` column, so unlike an order this row
     * cannot say later which language it was filled in. It does not have to:
     * this send happens in the request that captured it, so the language is
     * simply the one this request is in. It is read now and pinned onto the
     * Mailable with ->locale() rather than left to the ambient locale, which is
     * the framework's own version of what OrderLocale::render() does for
     * everything sent afterwards — and it keeps the trap that helper's header
     * warns about out of reach, because nothing here renders a View back
     * through a controller once the language has been put back.
     *
     * ── WHAT IS NOT SENT ───────────────────────────────────────────────────
     *
     * Nothing, if there is no address, if the address is not one this shop is
     * willing to put in a header (see the rule on `email` above), or if the
     * page worked out no routine at all. An email whose entire body is a
     * greeting is not the plan that was promised, and sending it would make
     * the delivery log say a plan went out.
     */
    /**
     * The language the quiz was filled in, as far as this request can tell.
     *
     * THE POST CARRIES NO LANGUAGE, and that is not an oversight in the page —
     * it is what the page does. The quiz's script posts to a literal
     * '/api/quiz' with `const API=''`, so an Arabic shopper reading
     * /ar/skin-quiz still submits to the UNPREFIXED endpoint, the locale
     * middleware sees no /ar/ segment, and app()->getLocale() is English for a
     * shopper who has been reading Arabic for a minute and a half. Sending them
     * an English plan is precisely the defect OrderLocale exists to stop for
     * orders, arriving by a different route.
     *
     * The Referer is what does know, and this row already trusts it: the
     * `source_url` column is written from the same header. It is a hint and is
     * treated as one — an absent, foreign or forged Referer falls back to the
     * request's own locale, and the worst a forged one can do is choose the
     * language of the email going to the address in the same forged request.
     * Locale::fromSegment() refuses a language this shop has not switched on,
     * so nothing here can select a locale the shop does not serve.
     *
     * WHAT THIS IS NOT. It is not a `locale` column on `quiz_submissions`, and
     * a later lane that wants to re-send a plan will need one — this answer is
     * only available in the request that captured the row. It is not read from
     * the BODY either: a public endpoint should not take instructions from an
     * anonymous caller that it cannot check, and the Referer at least describes
     * the page the browser says it was on.
     */
    private function localeFromReferer(Request $request): string
    {
        $path = (string) (parse_url((string) $request->headers->get('referer'), PHP_URL_PATH) ?: '');

        // The deployment prefix is outermost — /kbb-upgrade/ar/skin-quiz/ — so
        // it comes off before Locale is asked, which is the order
        // Locale::splitPath()'s own header sets out.
        $base = rtrim(Url::base(), '/');

        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        [$locale] = Locale::splitPath($path);

        return $locale !== null && Locale::isSupported($locale) ? $locale : Locale::current();
    }

    private function dispatchPlan(QuizSubmission $sub, Request $request): void
    {
        $email = trim((string) $sub->email);

        /*
         * Checked again, against the STORED value, and not because validation
         * is doubted. It is the second of the two layers Rules\StorefrontEmail's
         * header describes: what reaches the mail header is read back off the
         * row, so even a row written by an import or by a future caller that
         * skipped the rule cannot put a carriage return into an envelope.
         */
        if ($email === '' || ! StorefrontEmail::passes($email)) {
            return;
        }

        $routines = $sub->recommended_routines;

        if (! is_array($routines) || $routines === []) {
            return;
        }

        $id = (int) $sub->id;
        $locale = $this->localeFromReferer($request);
        $name = (string) ($sub->name ?? '');
        $skinType = (string) ($sub->skin_type ?? '');

        $concerns = array_values(array_filter(
            array_map('trim', explode(',', (string) ($sub->concerns ?? ''))),
            static fn (string $c): bool => $c !== ''
        ));

        $shopUrl = Url::to('/shop/');

        defer(function () use ($id, $email, $locale, $name, $skinType, $concerns, $routines, $shopUrl): void {
            try {
                /*
                 * The routine link is resolved HERE rather than above, so the
                 * queries behind it (Build my routine reads `products`) land
                 * after the response has gone, and so that a shop with the
                 * module off pays nothing for it at all. Null when the module
                 * is off, when the routes are not in the compiled table, or
                 * when this shop stocks nothing for that concern — in which
                 * case the message links to the shop, which always exists.
                 */
                $routineUrl = QuizRoutineLink::forConcerns($concerns);

                /*
                 * And the fall-back destination that does NOT need the module —
                 * Lane Q. /concern/{slug}/ answers the moment that concern has
                 * copy and enough live tagged products, which is the job the
                 * owner does on Catalog -> Build my routine. Resolved only when
                 * there is no routine to send them to, so a shop with the module
                 * on pays nothing for it; both null means the message keeps the
                 * link to /shop/ it has always had.
                 */
                $concernUrl = $routineUrl === null
                    ? QuizRoutineLink::concernUrlForConcerns($concerns)
                    : null;

                app(MailLog::class)->labelNext('quiz.plan');

                Mail::mailer(MailConfigurator::MAILER)
                    ->to($email)
                    ->send(
                        (new QuizPlanEmail($name, $skinType, $concerns, $routines, $shopUrl, $routineUrl, $concernUrl))
                            ->locale($locale)
                    );
            } catch (\Throwable $e) {
                /*
                 * Swallowed, and recorded twice: once in the delivery log the
                 * owner can actually read on Store → Sent mail, and once in the
                 * application log. The lead id and the exception CLASS only —
                 * never the message, which a mail transport fills with the
                 * recipient and sometimes the body. SubscribeController and
                 * OutboundSender both make exactly this rule.
                 */
                try {
                    app(MailLog::class)->recordFailure($e);
                } catch (\Throwable) {
                    // A recorder that cannot record must not become the failure.
                }

                Log::warning('A skin-quiz plan could not be emailed.', [
                    'lead_id' => $id,
                    'exception' => $e::class,
                ]);
            }
        }, 'kbb-quiz-plan-' . $id);
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
