<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\SettingsService;

/**
 * The checkout's legal notice — the `legal_notice` module, ported (Lane EH).
 *
 * The plugin describes this module as an "editable privacy / terms notice with
 * page links" and then implements none of it: its settings entry is a link
 * pointing at **kbb-theme**, which was never supplied. So the behaviour here is
 * reconstructed from the description and from what this app already does, not
 * copied.
 *
 * ── WHY THE DEFAULT IS EMPTY, AND THE MODULE IS STILL ON ────────────────────
 *
 * The module's registry default is ON, carried from the plugin. The TEXT's
 * default is the empty string, and with no text there is no element — not an
 * empty paragraph, not a bare pair of links.
 *
 * That combination is deliberate and it is the only safe one. This app ships by
 * signed zip onto a live store; a module that is on by default and carries a
 * sentence of its own would print a new line of legal wording above the Place
 * order button of a shop that never asked for it, the moment the package
 * applied. What a shop tells a customer about its terms at the moment of
 * payment is the owner's statement to make, not a default to inherit — the same
 * reasoning App\Support\TrustClaims records for the claims beside that button,
 * pointed the other way: TrustClaims defaults to the wording that was ALREADY
 * SHIPPED so that nothing changes, and this defaults to nothing for the same
 * reason, because nothing is what shipped.
 *
 * So switching the module on changes nothing until a sentence is written, and
 * writing a sentence changes nothing while the module is off. Both halves are
 * pinned in tests/Feature/ModuleLegalNoticeTest.php.
 *
 * ── THE PLACEHOLDERS ────────────────────────────────────────────────────────
 *
 * The owner writes ordinary prose and marks where the links go with {terms} and
 * {privacy}. Those expand to the two pages this app already links to from the
 * register form (store/account/login.blade.php) — /terms-and-conditions/ and
 * /privacy-policy/ — so the checkout and the sign-up form cannot come to point
 * at different pages.
 *
 * The owner's text is escaped FIRST and the anchors substituted after, so a
 * notice is prose with two links in it and never a way to put markup on the
 * checkout. Neither placeholder is required: a notice with no links is a
 * legitimate notice, and one that names only the privacy policy is too.
 */
final class CheckoutLegalNotice
{
    /** The settings key. One box, on Store → Ecommerce → Checkout. */
    public const KEY = 'checkout_legal_text';

    /**
     * Placeholder => [path, link text].
     *
     * Public so the admin help text and the test can name them from one place
     * rather than each spelling them out.
     */
    public const LINKS = [
        '{terms}' => ['/terms-and-conditions/', 'terms and conditions'],
        '{privacy}' => ['/privacy-policy/', 'privacy policy'],
    ];

    /**
     * The notice as HTML, or null when there is nothing to say.
     *
     * Returns null — not '' — for the same reason TrustClaims does: the caller
     * must be able to render NO ELEMENT, and an empty string tempts a template
     * into printing an empty box around it.
     */
    public static function html(SettingsService $settings): ?string
    {
        /*
         * `get()` hands back its default only when the ROW IS ABSENT. An owner
         * who clears the box stores '', a real value meaning "say nothing", and
         * the default here is '' as well — so both paths land in the same
         * place, which is the point. Trimmed, because a box containing a space
         * is a cleared box.
         */
        $text = trim((string) $settings->get(self::KEY, ''));

        if ($text === '') {
            return null;
        }

        $html = e($text);

        foreach (self::LINKS as $token => [$path, $label]) {
            // e() leaves {terms} and {privacy} untouched — neither contains a
            // character it escapes — so the token is still findable here, and
            // everything around it is already safe.
            $html = str_replace(
                $token,
                '<a href="'.e(Url::to($path)).'">'.e($label).'</a>',
                $html
            );
        }

        return $html;
    }
}
