<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "Here is the plan we said we would email you." — Lane FT.
 *
 * ── WHY THIS EXISTS ────────────────────────────────────────────────────────
 *
 * The skin quiz's contact step says, in the sentence directly under the box it
 * asks for an address in: "We'll save your results & email your plan. No spam,
 * ever." The results screen says it a second time — "emailed to :email". The
 * shop saved the results and emailed nothing: before this class there was no
 * reference to the quiz anywhere in app/Mail or app/Services/Mail, which Lane
 * FJ reported and this lane re-established by running (Mail::assertNothingOutgoing
 * passes against an unmodified POST /api/quiz). Asking a stranger for their
 * address on a promise and then not keeping it is the defect; this is the half
 * of the fix that keeps it rather than the half that waters the sentence down.
 *
 * ── WHAT IS IN IT, AND WHAT CANNOT BE ──────────────────────────────────────
 *
 * The lead's own answers and the steps the page worked out, and nothing else.
 * `$routines` comes from `quiz_submissions.recommended_routines`, which
 * Api\QuizController::routinesFrom() has already reduced to a name and a list
 * of step names — so a product, a price or a bundle total cannot ride into this
 * email however they are posted. That reduction is Lane FJ's and this depends
 * on it rather than repeating it.
 *
 * NO PRODUCT IS NAMED AND NO SAVING IS QUOTED. The page this email describes
 * used to recommend seventeen products the shop does not sell, at prices nobody
 * set, under a 15% bundle discount that never existed; Lane FB deleted all of
 * it and left the steps, which are skincare rather than catalogue. An email is
 * the worst possible place to reintroduce that, because it is kept, forwarded
 * and quoted back. The one link that names merchandise is the shop's own
 * address, where the prices are the shop's.
 *
 * ── NOT BUILT ON emails/layout.blade.php ───────────────────────────────────
 *
 * Same choice as emails/back-in-stock.blade.php and
 * emails/newsletter-confirm.blade.php, and for the same reason: that layout is
 * the ORDER-email masthead, carrying the shop's marketing footer. This goes to
 * somebody who has bought nothing, has agreed to exactly one message, and was
 * told "no spam, ever" while agreeing to it. Dressing it as a campaign is how
 * one promise turns into a list nobody joined.
 *
 * ── NO UNSUBSCRIBE LINK, AND THAT IS NOT AN OMISSION ───────────────────────
 *
 * The two outbound senders carry `List-Unsubscribe` because they are the start
 * of a series: a stock alert has a row that can be asked for again, a basket
 * reminder has a schedule with three stages in it. This has neither. One
 * submission produces exactly one message, sent in the request that created the
 * row, and nothing in this application can ever send a second — there is no
 * list to come off, so an unsubscribe control would be a button that does
 * nothing. What the message does instead is SAY that, in `quiz_plan.why`,
 * which is the true version of the same reassurance. If a later lane gives the
 * quiz a series, that lane owes this an opt-out and an OutboundOptOut purpose
 * to hang it on.
 */
class QuizPlanEmail extends Mailable
{
    use BrandedSubject;

    /** The masthead, the wordmark and the sign-off, as every other email builds them. */
    public array $brand;

    /**
     * @param  list<array{name: string, steps: list<string>}>  $routines
     * @param  list<string>  $concerns
     */
    public function __construct(
        private string $name,
        private string $skinType,
        private array $concerns,
        private array $routines,
        private string $shopUrl,
        private ?string $routineUrl,
        /*
         * The concern COLLECTION page for the same shopper, or null — Lane Q.
         *
         * Defaulted, so every existing caller and every test that builds this
         * mailable by hand keeps working unchanged. The three-way fall is
         * routine, then collection, then the shop: /routines/{concern} needs a
         * module that ships OFF, /concern/{slug}/ does not, and the shop always
         * exists. It is a separate parameter rather than a second value in
         * $routineUrl because the BUTTON'S WORDING differs — "Build my routine"
         * over a collection URL would describe a page the reader is not about
         * to open.
         */
        private ?string $concernUrl = null,
    ) {
        $this->brand = \App\Services\Mail\EmailBranding::forMailable(true, self::class);
    }

    /**
     * The subject, keyed like the rest of the quiz.
     *
     * No name and no answer in it. A subject line is quoted in notification
     * previews, in shared screenshots and in every mail server's log along the
     * way — OrderConfirmation's header makes the rule and "Aisha, here is your
     * acne plan" is a far worse thing to leak than an order number.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: (string) __('email.quiz_plan.subject', ['store' => $this->brandName()]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.quiz-plan',
            text: 'emails.quiz-plan-text',
            with: [
                'name' => $this->name,
                'skinType' => $this->skinType,
                'concerns' => $this->concerns,
                'routines' => $this->routines,
                'shopUrl' => $this->shopUrl,
                'routineUrl' => $this->routineUrl,
                'concernUrl' => $this->concernUrl,
                'brand' => $this->brand,
            ],
        );
    }
}
