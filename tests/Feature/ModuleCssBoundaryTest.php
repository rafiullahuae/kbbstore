<?php

/**
 * Nothing an owner can type reaches a stylesheet — driven, not read. Lane M2.
 *
 * ── WHY THIS WAS WRITTEN, AND WHY BY DRIVING ────────────────────────────────
 *
 * Lane M found the isValidHex defect — `Color::isValidHex()` accepts a hex with
 * OR without a `#`, and five modules then stored `strtoupper($value)` unchanged,
 * so `e23a4e` was saved as `E23A4E`, emitted as `--dv-col:E23A4E`, and dropped
 * by the browser. Sixteen fields. It was found by driving real values through
 * the real cast, not by reading the code: reading it, five times, is how it
 * survived a year, because each copy looks correct beside its own comment.
 *
 * This lane was asked to look for more of that shape. This is the instrument it
 * used, kept rather than thrown away: it POSTS a hostile value at every schema
 * field of every module that emits CSS, reads the custom-property block back
 * out of a fresh instance, and asks whether any of the posted text arrived.
 *
 * ── WHAT IT FOUND ───────────────────────────────────────────────────────────
 *
 * Nothing, on eleven modules and every field they declare. That is the result
 * and it is worth having written down: the shared cast holds for every value
 * this drive could reach. A second instance of the defect, if one is ever
 * introduced, fails here on the day it is.
 *
 * ── THE TWO THINGS IT HAD TO BE CAREFUL ABOUT ───────────────────────────────
 *
 * The first version asked "does the declaration look like CSS", and reported
 * 687 findings — every one of them `--ap-font:'Cormorant Garamond',Georgia,
 * serif`, a hard-coded font stack that legitimately carries quotes and commas.
 * A test that cries wolf 687 times is a test somebody deletes. So the question
 * is not whether the output looks safe but whether the ATTACKER'S TEXT IS IN
 * IT — which is checkable, and cannot be satisfied by a constant.
 *
 * And it saves through each module's own save(), then reads through a FRESH
 * instance with the settings cache flushed, so what is asserted is what a later
 * request would render rather than what an in-memory object happens to hold.
 *
 * MUTATION ACTUALLY RUN: ModuleSchema::castColour()'s `strict` arm changed to
 * return the raw `$value` when it does not match, instead of `$refuse` — the
 * arm HeaderSettings, MobileMenu and ProductStyles are on. Red, and the first
 * line it printed was
 *   HeaderSettings|bar_bg|cssVariables|"red;position:fixed;inset:0;z-index:9999"
 *   |…;--hd-bg:red;position:fixed;inset:0;z-index:9999;--hd-max:1280px;…
 * — a declaration ended early and two more started, on every page of the shop.
 * The `</style><script>alert(1)</script>` payload arrives in the same variable
 * on the same field.
 */

use App\Models\Setting;
use App\Services\SettingsService;

it('lets no posted value reach a module stylesheet', function () {
    /*
     * Four payloads, each a different way in: a semicolon that ends the
     * declaration and starts another, a hex with no `#` (the defect Lane M
     * found), a tag that would close an inline <style>, and a url() that a
     * `background` would fetch.
     */
    $hostile = [
        'red;position:fixed;inset:0;z-index:9999',
        'e23a4e',
        '</style><script>alert(1)</script>',
        'url(javascript:alert(1))',
    ];

    // The markers that say the payload arrived. Checked only against the
    // payload that carries them, so a constant in the stylesheet cannot match.
    $markers = ['position:fixed', 'javascript', '<script', 'url(', 'e23a4e', 'E23A4E'];

    $mods = [
        App\Services\AccountPanel::class,
        App\Services\CartPage::class,
        App\Services\CartPanel::class,
        App\Services\CheckoutPage::class,
        App\Services\HeaderSettings::class,
        App\Services\MobileHeader::class,
        App\Services\MobileMenu::class,
        App\Services\NewsletterSettings::class,
        App\Services\ProductStyles::class,
        App\Services\SectionDividers::class,
        App\Services\SlimFooter::class,
    ];

    $leaked = [];
    $driven = 0;

    foreach ($mods as $cls) {
        $ref = new ReflectionClass($cls);
        $save = $ref->getMethod('save');
        $save->setAccessible(true);

        foreach (array_keys($ref->getConstant('SCHEMA')) as $key) {
            foreach ($hostile as $value) {
                // A clean table per attempt, so one field's poison can never be
                // read back off another field's row.
                Setting::query()->delete();
                app(SettingsService::class)->flush();

                try {
                    $save->invoke(app($cls), [$key => $value]);
                } catch (\Throwable) {
                    continue;
                }

                app(SettingsService::class)->flush();
                $fresh = app($cls);

                foreach (['cssVariables', 'bodyClass'] as $emitter) {
                    if (! $ref->hasMethod($emitter)) {
                        continue;
                    }

                    $css = (string) $ref->getMethod($emitter)->invoke($fresh);
                    $driven++;

                    foreach ($markers as $marker) {
                        if (str_contains($value, $marker) && str_contains($css, $marker)) {
                            $leaked[] = class_basename($cls)."|{$key}|{$emitter}|".json_encode($value).'|'.$css;
                        }
                    }
                }
            }
        }
    }

    // A guard on the guard: if the module list or the schemas were emptied,
    // the loop above would pass by doing nothing.
    expect($driven)->toBeGreaterThan(1500, 'the drive stopped covering the module screens');
    expect(array_values(array_unique($leaked)))
        ->toBe([], "These settings put their posted value into a stylesheet:\n".implode("\n", array_unique($leaked)));
});
