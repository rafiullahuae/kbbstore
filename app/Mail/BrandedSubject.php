<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * The store's name, for a subject line — Lane DI.
 *
 * WHAT THIS ENDS. Five subjects across four mailables spelled the shop's name
 * out as a literal:
 *
 *   OrderConfirmation     'Your K Beauty Bliss order %s'
 *   OrderInvoice          'Invoice %s for your K Beauty Bliss order %s'  (x2)
 *   OrderRefunded         'Refund sent for your K Beauty Bliss order %s'
 *   OrderStatusChanged    'Your K Beauty Bliss order %s is on its way'
 *                         'Your K Beauty Bliss order %s has been cancelled'
 *
 * Everything else in these emails — the masthead, the wordmark, the sign-off,
 * the support block — already follows Store → Business Details → Store name
 * through App\Services\Mail\EmailBranding. The subject did not, and the subject
 * is the only part of an email a customer reads before deciding whether to open
 * it. A shop that renamed itself sent mail whose envelope still claimed the old
 * name and whose body did not.
 *
 * WHY A TRAIT AND NOT A METHOD ON OrderMail. OrderInvoice extends Mailable
 * directly rather than OrderMail — deliberately, per its own header, because it
 * is built from an InvoiceDocument rather than an OrderEmailPresenter. It still
 * carries the same `$brand` array from the same EmailBranding::forMailable()
 * call, so a trait lets all five subjects read one value without inventing a
 * common base class for two things that are not the same kind of email.
 *
 * NO SECOND READ OF THE SETTINGS TABLE. The name is taken from the `$brand`
 * array the constructor already built, so a subject cannot disagree with the
 * masthead printed underneath it, and a subject costs no query. The fallback is
 * the one EmailBranding::forMailable() itself falls back to when branding could
 * not be read at all, so the two agree about the last resort as well.
 */
trait BrandedSubject
{
    /** The shop's name as this email is about to print it. */
    protected function brandName(): string
    {
        $name = trim((string) ($this->brand['storeName'] ?? ''));

        return $name !== '' ? $name : (string) config('app.name', 'K Beauty Bliss');
    }
}
