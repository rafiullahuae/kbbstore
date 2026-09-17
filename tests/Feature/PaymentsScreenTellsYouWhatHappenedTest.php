<?php

declare(strict_types=1);

/**
 * =============================================================================
 * THE PAYMENTS SCREEN ANSWERS THE THREE QUESTIONS IT WAS SILENT ON
 * =============================================================================
 *
 * All three reported by the owner while setting Stripe up, and all three real:
 *
 *   "each keys fields etc should have green tick icon when key submitted and
 *    accepted. for now there's nothing and very confusing"
 *
 *   "re-check connection button just resetting everything instead of checking"
 *
 *   "the save connection button also not showing any successful message"
 *
 * 1. THE TICK. A secret field carried a `stored` pill and a plain field carried
 *    nothing, so a screen of boxes gave no answer to the only question being
 *    asked while filling it in — which of these have I done. "Accepted" is the
 *    honest word and the tick means exactly it: the server took the value and
 *    stored it. It is not a claim that Stripe likes the key; Connected, and the
 *    account name beside it, report that.
 *
 * 2. RE-CHECK CHECKED NOTHING. It called renderPayments(), which rebuilds the
 *    WHOLE screen from the server — every tab back to its default, every
 *    unsaved box on every gateway discarded. "Resetting everything instead of
 *    checking" is precisely what it did. It re-reads the connection status and
 *    repaints that pane alone now, and carries the two client-id boxes across
 *    the repaint so a half-typed id is not lost either. The two SECRET boxes
 *    are deliberately NOT carried: a password field repopulated from the DOM is
 *    a secret key sitting in the DOM.
 *
 * 3. THE SAVE WAS MUTE BY CONSTRUCTION. It set the message to '' on success and
 *    then repainted the pane, which destroys the span the message lives in — so
 *    even a message written first would have been wiped a moment later. It is a
 *    flash carried across the repaint and printed by the renderer now, and
 *    spent by being shown so it cannot report "Saved." over the next thing the
 *    owner does.
 *
 * Driven in Chromium against a real console: 5 ticks on exactly the filled
 * fields, Re-check keeping both the gateway tab and a typed `ca_…`, "Checked
 * just now." and "Saved." appearing, and a sixth tick once the live id is
 * stored.
 */

function paymentsConsoleJs(): string
{
    $raw = file_get_contents(base_path('resources/views/admin/app.blade.php'));

    // Comments are the hazard here: this file explains its own bugs at length,
    // and a search for "renderPayments" finds the prose describing the defect.
    return preg_replace('#/\*.*?\*/#s', ' ', $raw) ?? '';
}

it('marks a credential the server has accepted', function () {
    $js = paymentsConsoleJs();

    expect(str_contains($js, 'function payTick()'))->toBeTrue('the tick helper is gone');

    // Both kinds of field ask the same question from two different sources: a
    // secret is has_value because the server never sends one back, a plain
    // field is judged on the value it was given.
    expect(str_contains($js, "f.type==='secret' ? !!f.has_value : String(f.value||'').trim()!==''"))
        ->toBeTrue('payField no longer decides "filled" for both kinds of field');

    expect(substr_count($js, 'payTick()'))->toBeGreaterThanOrEqual(
        5,
        'the tick is no longer drawn on the gateway credentials and the four Connect application fields'
    );
});

it('re-checks the connection instead of rebuilding the screen', function () {
    $js = paymentsConsoleJs();

    $at = strpos($js, "querySelectorAll('[data-payrecheck]')");
    expect($at)->not->toBeFalse('the Re-check button is no longer bound');

    $handler = substr($js, (int) $at, 420);

    expect(str_contains($handler, 'payStripeStatus()'))->toBeTrue('Re-check no longer re-reads the connection')
        ->and(str_contains($handler, 'renderPayments()'))->toBeFalse(
            'Re-check rebuilds the whole Payments screen again, which is the reset the owner reported'
        );
});

it('says so when the Connect application is saved, and does not say it twice', function () {
    $js = paymentsConsoleJs();

    expect(str_contains($js, 'var PAY_APP_FLASH'))->toBeTrue('the flash that survives the repaint is gone');

    // Set on the way out of a successful save...
    expect(preg_match("/PAY_APP_FLASH\s*=\s*clear \?/", $js))
        ->toBe(1, 'a successful save no longer sets a message');

    // ...printed by the renderer, because the span it would be written into is
    // destroyed by the repaint that follows the save.
    expect(str_contains($js, "id=\"pay_capp_msg\""))->toBeTrue('the message span is gone')
        ->and(str_contains($js, 'sesc(PAY_APP_FLASH)'))->toBeTrue('the renderer no longer prints the flash');

    // ...and cleared once drawn.
    expect(substr_count($js, "PAY_APP_FLASH='';"))->toBeGreaterThanOrEqual(
        1,
        'the flash is never spent, so it would report "Saved." over the next thing the owner does'
    );
});

it('carries a typed client id across the repaint but never a secret', function () {
    $js = paymentsConsoleJs();

    expect(str_contains($js, "['pay_capp_id_test','pay_capp_id_live']"))
        ->toBeTrue('the client-id boxes are no longer carried across a re-check');

    // The secret boxes must NOT be in that list. If they ever are, a password
    // field is being repopulated from the DOM.
    $at = strpos($js, "['pay_capp_id_test','pay_capp_id_live']");
    $block = substr($js, (int) $at, 600);

    foreach (['pay_capp_sec_test', 'pay_capp_sec_live'] as $secret) {
        expect(str_contains($block, $secret))->toBeFalse(
            $secret.' is carried across the repaint, which puts a stored secret key back into the DOM'
        );
    }
});

/**
 * A FOURTH THING THE OWNER ASKED FOR: "give facility on backend to turned off
 * LINK on stripe fields."
 *
 * `link_enabled` is the first entry in any gateway's schema that is a SETTING
 * rather than a credential, and payField() had one shape for everything: a text
 * box. A switch you turn off by clearing a box and typing nothing into it is not
 * a switch, and nothing on the screen would have said what to type.
 *
 * A SELECT AND NOT A CHECKBOX, which is the part worth pinning. Every control on
 * this screen is read by paySnapshot(), written by payRestorePending() and
 * collected by paySave() through `el.value` — and a checkbox's `.value` does not
 * change with its checked state. A checkbox here would report the same string
 * whether it was ticked or not, so the unsaved-change dot would never light and
 * the save would post the same value both ways: a switch that looks like it
 * works and does nothing.
 */
it('draws a gateway setting as a switch, and reads it the way everything else on the screen is read', function () {
    $js = paymentsConsoleJs();

    /*
     * INSIDE payField(), and located that way rather than by searching the whole
     * script. This console draws `bool` fields on a dozen other screens and
     * `if(f.type==='bool')` appears fourteen times in it; a bare search would go
     * green on any one of them and this guard would be pinning the Modules
     * screen's toggle instead of the Payments screen's.
     */
    $payField = substr($js, (int) strpos($js, 'function payField(gid,f){'), 1800);

    expect(str_contains($payField, "if(f.type==='bool'){"))
        ->toBeTrue('payField no longer has a branch for a bool field, so the Link switch is a text box again');

    // The half that makes it work with the rest of the screen unchanged: the
    // select is the element carrying the two data attributes those three
    // functions find their controls by. Located as ONE substring of the branch
    // rather than three separate searches, so a select drawn somewhere else on
    // this screen cannot satisfy it on the bool branch's behalf.
    $branch = substr($payField, (int) strpos($payField, "if(f.type==='bool'){"), 600);

    foreach (['<select class="inp"', 'data-payg=', 'data-payf='] as $needle) {
        expect(str_contains($branch, $needle))->toBeTrue(
            "the switch is not drawn as a select carrying {$needle} — paySnapshot, payRestorePending and paySave find their controls by those attributes and would not see it"
        );
    }

    foreach (['>Off</option>', '>On</option>'] as $option) {
        expect(str_contains($js, $option))->toBeTrue("the switch has lost its {$option} position");
    }

    /*
     * AND NO TICK ON IT. The tick means "the server took this value and stored
     * it". A switch left Off is stored exactly as surely as one turned On, so a
     * tick that appeared only in the On position would be read as "this is on" —
     * which is the one thing the select beside it already says. A tick meaning
     * two different things on two field types is worse than no tick.
     */
    expect(str_contains($js, "var filled = f.type==='bool' ? false"))
        ->toBeTrue('a bool field is being judged "filled" again, so a switch turned On wears a tick that means something else');
});
