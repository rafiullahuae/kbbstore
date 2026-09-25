<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Controllers\Admin\AdminController;
use App\Support\WholeDirhams;

/**
 * The per-module settings schema — Phase 3's last open item.
 *
 * WHAT THIS IS FOR, and it is one thing rather than three.
 *
 * Before this, a module that owned its settings wrote the same list out three
 * times and nothing checked that the three agreed:
 *
 *   1. A `SCHEMA` constant, to RENDER the control. `MarketingPixels::SCHEMA`
 *      and `PayShipRules::SCHEMA` already had the same positional shape,
 *      `[type, label, default, help]`, with a `TABS` constant grouping the keys
 *      — and each module's API controller re-implemented the loop that turns
 *      the pair into the JSON the admin renders.
 *   2. A cast/validate step, to accept the WRITE. Each controller wrote its
 *      own, or in two cases wrote none and cast with `(int)`.
 *   3. `AdminController::SETTING_RULES`, the per-key list the generic settings
 *      endpoint validates against — and, crucially, **the endpoint silently
 *      drops any key not on it**. A control whose key never reached that list
 *      reported "Saved" and wrote nothing.
 *
 * That third one is not hypothetical here. `reassure_auth_text` looked
 * settings-driven for its whole life and was not: no seeder, no screen, no
 * SETTING_RULES entry, so the shipped default was the only value that ever
 * printed — at the moment of payment. `single_name` was the same fault from the
 * other end: the toggle saved and nothing read it.
 *
 * So one shape serves all three jobs, and the guard
 * (tests/Feature/ModuleFrameworkGuardTest.php) fails when they come apart:
 * a value with no control, a control with no value, or a control whose key
 * cannot survive the endpoint it is saved through.
 *
 * THE SHAPE. A field is an array; every key is optional but `type` and `label`:
 *
 *   'meta_id' => [
 *       'type'    => 'text',      // how to draw it, how to cast it
 *       'label'   => 'Meta Pixel ID',
 *       'default' => '',          // what a store that has saved nothing gets
 *       'help'    => '...',       // the sentence under the control
 *       'store'   => 'module',    // WHERE the value lives — see below
 *       'options' => [...],       // 'select' only: value => label
 *   ]
 *
 * The positional `[type, label, default, help]` form both existing modules
 * already use is accepted unchanged — normalise() widens it — so migrating a
 * module is adding a `store` key, not rewriting its constant. That is the
 * documented path for the rest.
 *
 * `store` IS THE PART THAT IS NEW, and it exists because "where does this
 * value live" was the question nothing recorded and everything depended on:
 *
 *   'module'  — `module_settings`, through SettingsService::setModuleSetting().
 *               The module's own endpoint saves it. PayShipRules' cod_min.
 *   'setting' — the global `settings` table, written by the module's OWN
 *               endpoint through SettingsService::set(). No SETTING_RULES entry
 *               is needed because AdminController::updateSettings() is not in
 *               the path. PayShipRules' hide_paid_when_free, which is a
 *               pre-existing key ShippingService reads.
 *   'admin'   — the global `settings` table, written by the GENERIC endpoint,
 *               AdminController::updateSettings(). This one **requires** a
 *               SETTING_RULES entry, and the guard enforces exactly that,
 *               because without it the save is silently dropped.
 *
 * `alias` covers the case PayShipRules already had: the stored key is not the
 * schema key, because the value pre-dates the module and something else already
 * reads it. Naming it here keeps one value with one control instead of growing
 * a rival key beside it.
 *
 * ── MIGRATING THE REST ──────────────────────────────────────────────────────
 *
 * Two modules are on this already — PayShipRules and MarketingPixels — and they
 * were picked as the two ENDS of the range rather than the two easiest: one
 * keeps its values in `module_settings` and the global settings table at once,
 * the other keeps none of its own at all (Analytics holds them). Anything else
 * in this app sits between those.
 *
 * For a module with a `SCHEMA` and `TABS` already — ProductLabels, CartPanel,
 * AccountPanel, HeaderSettings, MobileHeader, SectionDividers, ProductStyles,
 * NewsletterSettings, SiteSearch and the rest all carry the same pair — the
 * steps are:
 *
 *   1. Add `'store' => ...` to each field, and `'alias'` where the stored key
 *      differs from the schema key. Nothing else about the constant needs to
 *      change: the positional form keeps working, so this can be done field by
 *      field. This is the only step that requires a decision.
 *   2. Replace the module's hand-written all()/save() with read()/write(), if
 *      its values live in one of the two tables. A module like MarketingPixels,
 *      whose values live behind another service, keeps its own and uses cast()
 *      for the typed half.
 *   3. Replace its API controller's field/tab loop — every one of them is the
 *      same fifteen lines — with fields() or tabs().
 *   4. Add it to `ehSchemaModules()` in tests/Feature/ModuleFrameworkGuardTest.php.
 *      That is what makes the guarantee real for it: from then on it cannot
 *      ship a value with no control, a control with no value, or a field the
 *      generic settings endpoint would drop in silence.
 *
 * Step 4 is the point of the other three. A module that skips it is merely
 * tidier; a module that takes it cannot repeat the defect.
 */
final class ModuleSchema
{
    /** Where a value lives, and therefore what has to be true for it to save. */
    public const STORE_MODULE = 'module';

    public const STORE_SETTING = 'setting';

    public const STORE_ADMIN = 'admin';

    /**
     * Not a table. A `secret` field's value goes to a SecretStore — an
     * encrypted credential row — and this store value is what says so.
     *
     * It is checked against the type in both directions in field(): a `secret`
     * that is not `vault` throws, and a non-secret that IS `vault` throws. The
     * second half is the one that matters, because it is the way a credential
     * would otherwise come to be declared with a type the render loop prints.
     */
    public const STORE_VAULT = 'vault';

    public const STORES = [self::STORE_MODULE, self::STORE_SETTING, self::STORE_ADMIN, self::STORE_VAULT];

    /**
     * The third state a `secret` can be written in.
     *
     * A secret box renders EMPTY — there is nothing to render, the stored value
     * never leaves the server — so blank already means "leave the stored one
     * alone". "Remove the stored password" therefore cannot be expressed as a
     * value, and this literal carries it instead. It is Store → Mail's own
     * sentinel, lifted verbatim rather than reinvented, because an install that
     * has been told "type a - to forget it" must keep working.
     *
     * IT IS COMPARED BEFORE ANY CAST RUNS, and that is not an implementation
     * detail. A `-` handed to castText() with `blank => 'default'` comes back as
     * the shipped default; handed to one with a `markup => 'strip'` it survives
     * today and would not if the strip ever grew a punctuation rule. So
     * cast() REFUSES a secret outright (it throws), and write() compares the
     * trimmed literal itself. MailSecretTypeTest is the test that goes red if a
     * future cast is ever put back in front of it.
     */
    public const SECRET_FORGET = '-';

    /**
     * The field types, and the SETTING_RULES type each one maps to.
     *
     * The vocabulary is not invented: these are the types the admin console
     * already draws, counted off the existing service schemas rather than
     * chosen. A type this does not know is a field the renderer cannot draw,
     * so normalise() refuses it rather than emitting a control that renders as
     * a blank box.
     *
     * ── THE FOUR THAT WERE MISSING, AND WHY THAT MATTERED ───────────────────
     *
     * That paragraph was true of the two modules migrated when it was written
     * and false of the app. Counted across all seventeen schemas, the screens
     * draw four more types than this list held — `tags` (HeaderSettings'
     * trending words), `ids` (CartPage's recommendation rail), `skin`
     * (ProductStyles' card style) and `sections` (SectionDividers' section
     * picker) — one use each, which is exactly how they were missed.
     *
     * The consequence was not cosmetic. normalise() THROWS on an unknown type,
     * so `ModuleSchema::normalise(HeaderSettings::SCHEMA)` was a fatal error,
     * and those four modules could not be migrated onto the shared shape at
     * all. A schema that cannot express a control the console renders is the
     * same lie as one that can express a control it does not — pointed the
     * other way, and harder to see, because it fails at adoption time rather
     * than at render time.
     */
    public const TYPES = [
        'text'     => 'text',
        'textarea' => 'text',
        'bool'     => 'bool',
        'int'      => 'int',
        'range'    => 'int',
        'money'    => 'optint',
        'select'   => 'enum',
        'colour'   => 'text',
        // Stored as a comma-joined string in one column, each with its own
        // membership rule. All four are `text` to SETTING_RULES because that is
        // what the column holds; the rule that matters is applied in cast().
        'tags'     => 'text',
        'ids'      => 'text',
        'skin'     => 'text',
        'sections' => 'text',
        /*
         * ── `secret`: THE FIRST TYPE WHOSE CONTRACT IS WHAT MAY NOT COME BACK ─
         *
         * Every other type here answers "what may be stored". This one answers
         * "what may be READ", and the answer is nothing. It is mapped to `text`
         * only because SETTING_RULES needs a word; a secret can never be a
         * `store: admin` field — field() refuses it — so settingRules() never
         * emits one and that mapping is never used.
         *
         * The three methods every migrated module goes through each grew a
         * branch for it, and each branch is the absence of something:
         *
         *   read()    does not emit the key AT ALL. Not '' — absent. An empty
         *             string is a value a caller can carry around, log, and
         *             eventually render; a missing key is one that fails where
         *             somebody reaches for it.
         *   fields()  emits the field with no `value` and no `default`, and a
         *             `has_value` boolean instead. `fields()` used to emit
         *             `'value' => $values[$key] ?? $f['default']` for EVERY
         *             field unconditionally, which round 3 §5 named as the
         *             reason this type could not exist yet.
         *   write()   routes to a SecretStore and never to `settings` or
         *             `module_settings`, both of which are plain text and both
         *             of which `GET /admin-api/settings` returns wholesale.
         *   cast()    throws. A secret has no cast; see SECRET_FORGET.
         */
        'secret'   => 'text',
    ];

    /** The types whose stored value must be one of a supplied option set. */
    public const OPTION_TYPES = ['select', 'skin', 'sections'];

    /**
     * ── POLICY: what a module does with a value it will not store ───────────
     *
     * The seventeen schemas did not merely describe their fields differently,
     * they ANSWERED differently, and a single shared cast() would have changed
     * what every one of them stores. Measured across 4,653 cast calls (the
     * corpus in tests/Fixtures/module-cast-baseline.txt), the live behaviour
     * splits on four axes and no two modules pick the same point on all four:
     *
     *   `max`     the text cap. Really five different caps — 40 on
     *             ProductLabels, 60 on ProductStyles and AccountPanel, 120 on
     *             HeaderSettings, CartPanel, MobileMenu and MobileHeader, 160
     *             on CartPage and SlimFooter, 240 on NewsletterSettings. Each
     *             is a judgement about one screen's wording and none is wrong.
     *
     *   `blank`   what an emptied box means. `keep` stores the empty string
     *             (CartPage, CartPanel, NewsletterSettings, ProductLabels,
     *             SlimFooter); `default` puts the shipped wording back
     *             (AccountPanel, HeaderSettings, MobileMenu, ProductStyles),
     *             because those strings are labels the page cannot render
     *             without.
     *
     *   `invalid` what an unusable value gets. `default` falls back silently,
     *             which is what fourteen modules do; `reject` refuses and lets
     *             the caller report it, which is what PayShipRules and
     *             MarketingPixels do and what money must do — a mistyped
     *             "12.50" quietly stored as 12 fils is a hundredfold error
     *             that reads back as a plausible number.
     *
     *   `clamp`   whether a number outside its range is pulled to the nearest
     *             bound (every `range` control on every screen: the slider
     *             cannot emit an out-of-range value, so a POST that does is
     *             not worth a 422) or refused (`money`, which is typed).
     *
     * SO POLICY IS DECLARED, NOT DEFAULTED. A module passes its own point on
     * these four axes to normalise(); the field may override any of them. The
     * defaults here are the STRICT end, so a module that declares nothing gets
     * refusal rather than silent substitution — a new module has to ask for
     * leniency in writing, and the fourteen that need it say so at the call
     * site where the reason is visible.
     */
    public const POLICY_KEYS = ['max', 'blank', 'invalid', 'clamp', 'hex', 'bool', 'markup'];

    public const DEFAULT_POLICY = [
        'max' => 5000,
        'blank' => 'keep',
        'invalid' => 'reject',
        'clamp' => false,
        'hex' => 'repair',
        'bool' => 'words',
        'markup' => 'keep',
    ];

    /**
     * The values each axis may take — and why a typo here had to start failing.
     *
     * Every axis but `max` and `clamp` is compared with `===` against a literal
     * inside cast(), and every comparison has an `else`. So `'bool' => 'Cast'`
     * did not fail: it missed `=== 'cast'` and fell through to the word-aware
     * arm, which is a module silently getting the OTHER dialect. That is the
     * same shape as rule 5's "a select stores one of its own options or the
     * default", pointed at the policy rather than at the value, and it stopped
     * being theoretical the moment this class grew a THIRD value on two of the
     * axes: with two values a typo lands on the one you did not want, with
     * three it lands on one of two and the reader cannot tell which by reading.
     *
     * Checked in field(), so a bad policy throws where the module declares it.
     */
    public const POLICY_VALUES = [
        'blank' => ['keep', 'default'],
        'invalid' => ['reject', 'default'],
        'hex' => ['strict', 'repair', 'expand'],
        'bool' => ['cast', 'words', 'words+null'],
        'markup' => ['keep', 'strip'],
    ];

    /*
     * ── TWO MORE AXES, BOTH FOUND BY MEASUREMENT RATHER THAN BY READING ─────
     *
     * The first draft of this class had four axes and a single `colour` and
     * `bool` arm. Running the 4,653-call corpus through it turned up 322
     * answers that moved, in two clusters, and both were changes to behaviour
     * that already worked:
     *
     *   `hex`   Three modules — HeaderSettings, MobileMenu, ProductStyles —
     *           test with /^#[0-9a-fA-F]{6}$/ and store the value UNCHANGED,
     *           so `#e23a4e` stays lower case and `#abc` is refused. The other
     *           five test with Color::isValidHex(), which also admits 3-digit
     *           and hash-less forms. Folding the two would have re-cased every
     *           stored colour on three screens and started accepting `#abc`
     *           where it had been refused — neither asked for.
     *           `strict` keeps the value; `repair` returns `#` + upper case,
     *           which is the arm ProductLabels already shipped and the one the
     *           four broken modules needed.
     *
     *   `bool`  Ten modules cast with a plain `(bool)`, so the STRING "off"
     *           is true — a non-empty string. The three migrated earlier use
     *           the word-aware form, where "off", "no" and "false" are false.
     *           Both are defensible and they disagree on exactly those words.
     *           `cast` is the plain one, `words` the word-aware one.
     *
     * Neither cluster is a bug being fixed here, so neither is folded. The
     * modules keep what they had and say which in their own POLICY, and the
     * equivalence fixture is the proof that saying so was enough.
     *
     * ── AND A THIRD VALUE ON EACH OF THOSE TWO AXES, LANE M3 ───────────────
     *
     * The three App\Support\*Settings classes were held back by round 2 for a
     * "third boolean dialect". Driven over their own corpus
     * (tests/Fixtures/module-settings-baseline.txt, 347 calls recorded off the
     * parent revision) there turned out to be a third value on TWO axes, not
     * one, and the second was not in anybody's notes:
     *
     *   `bool` => 'words+null'   the word-aware list plus the literal four
     *           letters `null`. That string is what a value that was SQL NULL,
     *           or PHP null, comes back as once something has exported it —
     *           json_encode(null) is "null" — and all three classes fold it to
     *           false where `words` answers true. It is one word of difference
     *           and it is the difference between a review screen's switches
     *           reading off and reading on after an import.
     *
     *   `hex` => 'expand'   ReviewBadgeSettings requires the `#` (so `e23a4e`
     *           is refused, which `repair` accepts), EXPANDS `#abc` to
     *           `#AABBCC` (which neither other arm does), and upper-cases
     *           (which `strict` does not). Folding it into `repair` would have
     *           stored `#ABC` where `#AABBCC` was stored before — the same
     *           colour to a browser and a DIFFERENT STRING to
     *           ReviewBadgeSettings::activeTheme(), which compares the stored
     *           six digits against each preset's. A shop on the Classic theme
     *           would have read back as "Custom".
     *
     * That second one is the argument for measuring restated: `#ABC` and
     * `#AABBCC` are the same colour, the fold looks free, and it breaks a
     * screen two files away.
     */

    /**
     * One field, widened to its canonical form.
     *
     * Accepts the positional `[type, label, default, help, options]` that every
     * schema in this app is written in, so migrating a module is a call-site
     * change rather than a rewrite of its constant. That matters more than
     * tidiness: those constants are the shipped defaults of 560 live controls,
     * and a schema migration that retypes them by hand is a migration that
     * moves the shop.
     *
     * `$policy` is the module's answer to the four questions in DEFAULT_POLICY;
     * anything the field itself names wins over it.
     *
     * @param  array<int|string, mixed>  $def
     * @param  array<string, mixed>  $policy
     * @return array<string, mixed>
     */
    public static function field(string $key, array $def, array $policy = []): array
    {
        // Positional => associative. A list is the old shape; anything with
        // string keys is already the new one.
        if (array_is_list($def)) {
            $def = array_filter([
                'type' => $def[0] ?? 'text',
                'label' => $def[1] ?? '',
                'default' => $def[2] ?? '',
                'help' => $def[3] ?? '',
                'options' => $def[4] ?? null,
            ], static fn ($v) => $v !== null);
        }

        $type = (string) ($def['type'] ?? 'text');

        if (! isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException("Module setting “{$key}” has unknown type “{$type}”.");
        }

        $store = (string) ($def['store'] ?? ($type === 'secret' ? self::STORE_VAULT : self::STORE_MODULE));

        if (! in_array($store, self::STORES, true)) {
            throw new \InvalidArgumentException("Module setting “{$key}” has unknown store “{$store}”.");
        }

        /*
         * A SECRET AND A VAULT ARE THE SAME DECLARATION, CHECKED BOTH WAYS.
         *
         * Forwards: a `secret` that is not stored in a vault would be written
         * into `settings` or `module_settings` by write()'s ordinary arms, and
         * `GET /admin-api/settings` returns `Setting::map()` entire, with no
         * allowlist — so a credential put there is a credential handed to the
         * admin bundle on every page load and to every database backup. That is
         * the exact trap MailSecretsTest was written for and the reason the SMTP
         * password lives in `mail_credentials`.
         *
         * Backwards, and this is the half that catches the likelier mistake: a
         * `text` field declared `store: vault` would be routed to the credential
         * store by write() and then emitted WITH ITS VALUE by fields(), because
         * fields() only withholds a value from a `secret`. The two halves of the
         * contract have to travel together or each one is a way to lose the
         * other.
         */
        if (($type === 'secret') !== ($store === self::STORE_VAULT)) {
            throw new \InvalidArgumentException(
                $type === 'secret'
                    ? "Module setting “{$key}” is a secret and must be stored in the “".self::STORE_VAULT."” store, not “{$store}”."
                    : "Module setting “{$key}” is a {$type} and may not be stored in the “".self::STORE_VAULT."” store; only a secret may."
            );
        }

        /*
         * A CONTROL THAT PICKS FROM A SET MUST CARRY THE SET.
         *
         * Rule 5 of the project notes — "a select stores one of its own options
         * or the default" — is only checkable if the options are here. `skin`
         * and `sections` are in this list beside `select` because both are
         * exactly that shape: ProductStyles' card style is one of GridSkins,
         * SectionDividers' picker is a subset of the homepage sections. Both
         * validated against a set the SCHEMA never named, which is why neither
         * could be checked from here until now.
         *
         * A `range` carries `['min'=>..,'max'=>..]` in the same slot and is not
         * an option set, so it is not on this list.
         */
        if (in_array($type, self::OPTION_TYPES, true) && ! is_array($def['options'] ?? null)) {
            throw new \InvalidArgumentException("Module setting “{$key}” is a {$type} with no options.");
        }

        $resolved = [];

        foreach (self::POLICY_KEYS as $k) {
            $resolved[$k] = $def[$k] ?? $policy[$k] ?? self::DEFAULT_POLICY[$k];

            // A policy value cast() would not recognise is a module getting the
            // other dialect in silence. See POLICY_VALUES.
            if (isset(self::POLICY_VALUES[$k]) && ! in_array($resolved[$k], self::POLICY_VALUES[$k], true)) {
                throw new \InvalidArgumentException(
                    "Module setting “{$key}” declares {$k} “".(is_scalar($resolved[$k]) ? (string) $resolved[$k] : gettype($resolved[$k]))
                    ."”, which is not one of: ".implode(', ', self::POLICY_VALUES[$k]).'.'
                );
            }
        }

        $rule = self::rule($key, $type, $def['rule'] ?? null);

        return [
            'key' => $key,
            'type' => $type,
            'label' => (string) ($def['label'] ?? ''),
            'help' => (string) ($def['help'] ?? ''),
            'default' => $def['default'] ?? self::blank($type),
            'options' => $def['options'] ?? null,
            'store' => $store,
            // The key the value is stored under, which is the schema key unless
            // the module is editing something that already existed.
            'alias' => (string) ($def['alias'] ?? $key),
            'max' => (int) $resolved['max'],
            'blank' => (string) $resolved['blank'],
            'invalid' => (string) $resolved['invalid'],
            'clamp' => (bool) $resolved['clamp'],
            'hex' => (string) $resolved['hex'],
            'bool' => (string) $resolved['bool'],
            'markup' => (string) $resolved['markup'],
            // A constraint peculiar to this one setting, which no policy axis
            // can carry. Null for all but a handful of fields. See rule().
            'rule' => $rule,
        ];
    }

    /**
     * ── `rule`: A CONSTRAINT THAT IS NOT A POLICY POINT ─────────────────────
     *
     * The six axes above exist because seventeen modules DISAGREED about the
     * same question — how long is too long, what does an emptied box mean —
     * and every one of those answers was defensible. A rule is the other kind
     * of difference: a constraint that belongs to one setting and would be
     * wrong applied to any other. Two of them were the stated reason CartPage
     * and CheckoutPage were held back from the first migration, and flattening
     * either into a policy would have been the quiet loss that a migration is
     * dangerous for:
     *
     *   CheckoutPage `rating_text` — a wording template that may carry NO DIGIT
     *   of its own, because its two tokens are replaced with figures read from
     *   the reviews table and a digit anywhere else is a rating the owner
     *   invented. Over-length is REFUSED to the shipped wording, not truncated,
     *   which is why it cannot be `max` either: `max` cuts, and half a sentence
     *   beside the pay button is its own defect.
     *
     *   CartPage `sum_express` / `sum_service` — money that CLAMPS AT ZERO
     *   rather than refusing. The shared `money` arm refuses anything that is
     *   not a plain run of digits, on purpose (see castInt); this screen has
     *   always stored `max(0, (int) $value)`, so a refusal here would reject a
     *   save that has always worked.
     *
     * A rule REPLACES the type arm — it does not run after it, because
     * cleanTemplate has to see the untruncated value to refuse it. That makes
     * the rule itself the boundary for that field, so the two arms rule 5 of
     * the project notes names by hand are closed to rules: a `colour` must
     * reach a stylesheet as `#` + six digits and a `select`/`skin`/`sections`
     * must hold one of its own options, and neither guarantee may be handed to
     * a module-local function. Declaring one there throws here rather than at
     * render time.
     *
     * The rule lives in the module it belongs to, as a public static method
     * named in that module's own SCHEMA, so reading the field tells you the
     * constraint exists and where it is.
     */
    private static function rule(string $key, string $type, mixed $rule): ?callable
    {
        if ($rule === null) {
            return null;
        }

        if (in_array($type, [...self::OPTION_TYPES, 'colour', 'secret'], true)) {
            throw new \InvalidArgumentException(
                "Module setting “{$key}” is a {$type}, whose validation may not be replaced by a rule."
            );
        }

        if (! is_callable($rule)) {
            throw new \InvalidArgumentException("Module setting “{$key}” names a rule that cannot be called.");
        }

        return $rule;
    }

    /**
     * The normalised schema for one module, built once per process.
     *
     * ── WHY THIS IS HERE AND NOT IN EACH MODULE ─────────────────────────────
     *
     * A module's cast() needs one field and normalise() builds them all, so a
     * screen with 106 keys pays 11,236 field() calls for one all() — which is
     * what CartPage does on a cart render. The obvious fix is a `static $fields`
     * in each module, and three of them had one.
     *
     * StaticMemoIsolationTest failed on exactly that, correctly and by design:
     * "these classes hold process-level state that survives a test and are
     * neither reset nor exempt". Three statics would have meant three
     * exemptions, each a separate claim to be believed. One memo means one
     * registered RESET — Tests\Support\StaticMemos calls forgetNormalised()
     * before every test — which is a fact rather than a claim, and every module
     * gets the saving instead of the three that happened to ask.
     *
     * `$key` IDENTIFIES THE SCHEMA, so callers pass `self::class`. Two callers
     * sharing a key would share an answer; the class name cannot collide, and
     * nothing else is an acceptable key.
     *
     * @param  array<string, array<int|string, mixed>>  $schema
     * @param  array<string, mixed>  $policy
     * @param  array<string, array<string, mixed>>  $overrides
     * @return array<string, array<string, mixed>>
     */
    public static function normalised(string $key, array $schema, array $policy = [], array $overrides = []): array
    {
        return self::$normalised[$key] ??= self::normalise($schema, $policy, $overrides);
    }

    /**
     * Drop every memoised schema.
     *
     * Registered in Tests\Support\StaticMemos. Nothing in the application
     * calls it: the memo is built from class constants, which cannot change
     * inside a process — this exists so the isolation guard has a reset to run
     * rather than an exemption to take on trust.
     */
    public static function forgetNormalised(): void
    {
        self::$normalised = [];
    }

    /** @var array<string, array<string, array<string, mixed>>> */
    private static array $normalised = [];

    /**
     * @param  array<string, array<int|string, mixed>>  $schema
     * @param  array<string, mixed>  $policy
     * @param  array<string, array<string, mixed>>  $overrides  per-field extras
     *         the positional constant has no slot for — an option set a module
     *         validates against an external map, say.
     * @return array<string, array<string, mixed>>
     */
    public static function normalise(array $schema, array $policy = [], array $overrides = []): array
    {
        $out = [];

        foreach ($schema as $key => $def) {
            $def = (array) $def;

            if (isset($overrides[$key])) {
                // The positional form has no room for these, so they are merged
                // in before widening rather than bolted on after.
                if (array_is_list($def)) {
                    $def = [
                        'type' => $def[0] ?? 'text',
                        'label' => $def[1] ?? '',
                        'default' => $def[2] ?? '',
                        'help' => $def[3] ?? '',
                        'options' => $def[4] ?? null,
                    ];
                }

                $def = array_merge($def, $overrides[$key]);
            }

            $out[(string) $key] = self::field((string) $key, $def, $policy);
        }

        return $out;
    }

    /** What an unset value of this type is, when the module did not say. */
    private static function blank(string $type): mixed
    {
        return match ($type) {
            'bool' => false,
            'int', 'range', 'money' => 0,
            default => '',
        };
    }

    /**
     * Every value, read from wherever its `store` says it lives.
     *
     * @param  array<string, array<int|string, mixed>>  $schema
     * @return array<string, mixed>
     */
    public static function read(SettingsService $settings, string $module, array $schema): array
    {
        $out = [];

        foreach (self::normalise($schema) as $key => $f) {
            /*
             * A SECRET IS NOT READ. Not as '', not as the default — the key is
             * ABSENT from the map this returns.
             *
             * An empty string is a value: a caller can carry it, put it in a
             * payload, log it, and eventually render it, and the day the store
             * behind it starts answering something other than '' none of those
             * call sites changes. A missing key fails at the place somebody
             * reaches for it, which is the only place a mistake here can be
             * fixed. It is also exactly what MailSettings::all() already did —
             * `if ($type === 'secret') { continue; }` — so this arm is that
             * class's behaviour lifted into the schema rather than a new rule.
             */
            if ($f['type'] === 'secret') {
                continue;
            }

            $raw = $f['store'] === self::STORE_MODULE
                ? $settings->moduleSetting($module, $f['alias'], $f['default'])
                : $settings->get($f['alias'], $f['default']);

            $out[$key] = self::coerceRead($f, $raw);
        }

        return $out;
    }

    /**
     * Writes the values a payload actually carries, each to its own store.
     *
     * Only keys present in the payload are written, so a screen that posts one
     * tab does not blank the others — and only keys in the schema, so an
     * unknown key cannot ride in.
     *
     * Returns the keys it refused, for the caller to report. A save that
     * quietly dropped a bad value would be the same silence this class exists
     * to remove.
     *
     * ── THE THIRD WRITE STATE, WHICH ONLY A SECRET HAS ─────────────────────
     *
     * Every other field has two: the key is in the payload (write it) or it is
     * not (leave it). A secret has FOUR, because its box is rendered empty and
     * therefore posts back empty on every save of the screen:
     *
     *   absent          leave the stored credential alone
     *   ''  (or blank)  leave it alone — the box was rendered empty and the
     *                   owner edited the SMTP host, not the password
     *   SECRET_FORGET   remove it. The one thing the empty box cannot say.
     *   anything else   store it, verbatim, bytes and all
     *
     * The `array_key_exists` / `cast() === null` pair the other arms use has no
     * room for the middle two, which is why this branch is before the cast and
     * not inside it.
     *
     * @param  array<string, array<int|string, mixed>>  $schema
     * @param  array<string, mixed>  $values
     * @param  SecretStore|null  $vault  required if the schema declares a secret
     * @return array{written: list<string>, rejected: array<string, string>}
     */
    public static function write(SettingsService $settings, string $module, array $schema, array $values, ?SecretStore $vault = null): array
    {
        $written = [];
        $rejected = [];

        foreach (self::normalise($schema) as $key => $f) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            if ($f['type'] === 'secret') {
                /*
                 * FAILS CLOSED. A schema that declares a credential and a
                 * caller that forgot to hand over the store is a save that
                 * would otherwise report success and write the password
                 * nowhere — or, worse under a later refactor, into `settings`.
                 * An exception at the call site is the cheap version of finding
                 * that out.
                 */
                if ($vault === null) {
                    throw new \LogicException(
                        "Module setting “{$key}” is a secret and this write was given no SecretStore to put it in."
                    );
                }

                $posted = is_string($values[$key]) ? trim($values[$key]) : '';

                if ($posted === '') {
                    continue;   // blank box = unchanged, and nothing is written
                }

                $vault->put($f['alias'], $posted === self::SECRET_FORGET ? null : $posted);

                $written[] = $key;

                continue;
            }

            $cast = self::cast($f, $values[$key]);

            if ($cast === null) {
                $rejected[$key] = $f['label'];

                continue;
            }

            /*
             * WHOLE DIRHAMS on every `money` field any module declares —
             * Lane FA.
             *
             * Today that is PayShipRules' cod_min and cod_max, the window
             * outside which Cash on delivery is hidden. They are bounds, not
             * prices, but they are compared against an order total that is now
             * always a whole dirham, so a floor of 9,950 fils is a rule whose
             * boundary falls between two totals that can exist — its own
             * preview screen already had to explain that ("a rule whose floor
             * is 9950 fils previewed as AED 100 — Cash on delivery hidden",
             * which reads as a contradiction). At whole dirhams the boundary
             * is where the owner can see it.
             *
             * By TYPE and not by key, so a `money` field added to any module
             * later gets the rule without anyone remembering to ask for it.
             * This is the one place every module's money passes through.
             *
             * REJECTED, not adjusted — a bound is typed. Compared against what
             * is stored, through the same store the value would be written to,
             * so a module carrying a legacy value can still have its other
             * fields saved. `rejected` is the caller's existing channel for
             * saying so on screen; nothing here is silent.
             */
            if ($f['type'] === 'money' && ! WholeDirhams::isWhole((int) $cast)) {
                $current = $f['store'] === self::STORE_MODULE
                    ? $settings->moduleSetting($module, $f['alias'], $f['default'])
                    : $settings->get($f['alias'], $f['default']);

                if ((int) $current !== (int) $cast) {
                    $rejected[$key] = $f['label'];

                    continue;
                }
            }

            if ($f['store'] === self::STORE_MODULE) {
                $settings->setModuleSetting($module, $f['alias'], $cast);
            } else {
                $settings->set($f['alias'], $cast);
            }

            $written[] = $key;
        }

        return ['written' => $written, 'rejected' => $rejected];
    }

    /**
     * The typed value, or null when the input is not acceptable.
     *
     * THIS IS THE SECURITY BOUNDARY, and it is the only one. Every value a
     * module stores passes through here, so the rules that used to be written
     * out fourteen times — once per module, each slightly differently — are
     * written once and are checkable once. The three from rule 5 of the project
     * notes hold for every field of every migrated module by construction:
     *
     *   - a select (and `skin`, and each member of `sections`) stores one of
     *     its own options or the default, never what arrived;
     *   - a colour is a `#` and six hex digits before it can reach a stylesheet;
     *   - text is trimmed and capped, so nothing printed into a page is
     *     unbounded.
     *
     * `null` means REFUSED, and the caller reports it. A field whose policy is
     * `invalid => 'default'` never refuses: it answers the shipped default,
     * which is what fourteen of these screens have always done and what their
     * sliders and colour pickers rely on.
     *
     * ── THE COLOUR BUG THIS CLOSES ──────────────────────────────────────────
     *
     * `Color::isValidHex()` accepts a hex WITH OR WITHOUT the leading `#`
     * (`/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i`). Four modules tested with it and
     * then stored `strtoupper($value)` unchanged:
     *
     *     CartPanel  accent, checkout_bg, checkout_fg
     *     MobileHeader  acct_in, acct_out, dv_colour, search_bg, search_icon,
     *                   search_ph, search_text
     *     NewsletterSettings  nl_bg_from, nl_bg_to, nl_btn_bg, nl_btn_fg,
     *                         nl_note_colour
     *     SectionDividers  colour
     *
     * Sixteen fields. A value posted as `e23a4e` passed the check and was
     * stored as `E23A4E`, which is not a colour — SectionDividers::cssVariables()
     * emits `--dv-col:E23A4E`, CartPanel writes it into a `background:`, and the
     * declaration is dropped by the browser, so the control moves and the shop
     * does not. ProductLabels hit this, diagnosed it in its own comment
     * ("went into style=\"background:E23A4E\" — not a colour, so the badge drew
     * with no background and its white text vanished") and fixed it THERE, in
     * 2.60.x. The other four never got the fix, because there were four other
     * copies of the same three lines to find.
     *
     * Repaired rather than refused, which is ProductLabels' choice and the one
     * that keeps rule 1: a value that was already a working colour is returned
     * byte-identical, and only a value that was already broken changes. The
     * equivalence test asserts exactly that and nothing weaker.
     *
     * @param  array<string, mixed>  $field  a normalise()d field
     */
    public static function cast(array $field, mixed $raw): mixed
    {
        $refuse = $field['invalid'] === 'reject' ? null : $field['default'];

        /*
         * The field's own rule, where it has one, INSTEAD of the type arm — see
         * rule(). It is reached before every arm because a rule that only got
         * the already-capped value could not refuse an over-length one, which
         * is exactly what CheckoutPage's rating_text does.
         */
        if (($field['rule'] ?? null) !== null) {
            return ($field['rule'])($raw, $field);
        }

        /*
         * A SECRET HAS NO CAST, AND THE THROW IS THE POINT.
         *
         * Every arm below normalises: it trims, it caps, it substitutes a
         * default for something it will not store. All three are wrong for a
         * credential. A password is bytes the owner was given by a mail host
         * and `  hunter2  ` may genuinely be the password; a cap silently
         * stores a prefix that will never authenticate; and `blank =>
         * 'default'` would turn SECRET_FORGET's `-` into the shipped default
         * rather than forgetting anything.
         *
         * That last one is the failure this throw exists for. `-` is a
         * SENTINEL, not a value, and a sentinel is exactly what a normalising
         * layer swallows — silently, and on the one screen whose failure mode
         * is a shop that stops sending order email. So write() compares the
         * literal itself, before any of this, and anything that routes a secret
         * through here instead gets an exception rather than a quietly
         * different password. ModuleSecretTypeTest names the mutations.
         */
        if ($field['type'] === 'secret') {
            throw new \LogicException(
                "Module setting “{$field['key']}” is a secret; it is written to a SecretStore and never cast."
            );
        }

        return match ($field['type']) {
            'bool' => $field['bool'] === 'cast' ? (bool) $raw : self::castBool($raw, $field['bool']),
            'money' => self::castInt($raw, $field, false),
            'int', 'range' => self::castInt($raw, $field, (bool) $field['clamp']),
            'select', 'skin' => is_array($field['options']) && array_key_exists((string) $raw, $field['options'])
                ? (string) $raw
                : $refuse,
            'colour' => self::castColour($raw, $refuse, (string) $field['hex']),
            'tags' => self::castTags($raw, $field),
            'ids' => self::castIds($raw, $field),
            'sections' => self::castSections($raw, $field),
            default => self::castText($raw, $field),
        };
    }

    /**
     * A hex colour, normalised to something a stylesheet can use.
     *
     * The `#` is put back rather than trusted: the colour picker on every one of
     * these screens always sends one, a POST to the endpoint need not, and the
     * value is printed into a CSS declaration at the other end. Uppercased
     * because that is what all five colour-carrying modules already stored.
     */
    private static function castColour(mixed $raw, mixed $refuse, string $mode): mixed
    {
        $value = is_scalar($raw) ? (string) $raw : '';

        if ($mode === 'strict') {
            // Six digits and a hash, stored exactly as it arrived — including
            // its case, which three screens' stored colours are already in.
            return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? $value : $refuse;
        }

        if ($mode === 'expand') {
            /*
             * ReviewBadgeSettings' dialect, and it is a third one rather than a
             * corner of the other two. The `#` is REQUIRED (so `e23a4e` is
             * refused, where `repair` accepts it), three-digit shorthand is
             * EXPANDED rather than kept (so `#abc` stores `#AABBCC`, where
             * `repair` stores `#ABC`), and the result is upper case (where
             * `strict` keeps whatever case arrived).
             *
             * The expansion is the half that cannot be folded away. `#ABC` and
             * `#AABBCC` are the same colour to a browser, so dropping it looks
             * free — and ReviewBadgeSettings::activeTheme() decides which
             * preset a shop is on by comparing the STORED SIX DIGITS against
             * each theme's, so a shop on Classic would have started reading
             * back as "Custom". One canonical form is what makes that a string
             * compare, which is what that constant's own note says.
             */
            $clean = strtoupper(trim($value));

            if (preg_match('/^#([0-9A-F]{3})$/', $clean, $m) === 1) {
                return '#'.$m[1][0].$m[1][0].$m[1][1].$m[1][1].$m[1][2].$m[1][2];
            }

            return preg_match('/^#[0-9A-F]{6}$/', $clean) === 1 ? $clean : $refuse;
        }

        if (preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i', trim($value)) !== 1) {
            return $refuse;
        }

        return '#'.strtoupper(ltrim(trim($value), '#'));
    }

    /**
     * Free text; trimmed, capped, and emptied according to the module's policy.
     *
     * ── THE null THIS FOLDS, which is not the same bug as the colour one ────
     *
     * Laravel's global ConvertEmptyStringsToNull turns a box the owner has
     * cleared into NULL before any of this runs, `is_scalar(null)` is false, and
     * the text arm used to answer null — which every caller reads as "not
     * acceptable". Measured on Store → Marketing Pixels:
     * {"settings":{"meta_id":""}} answered 422 “Meta Pixel ID” is not a valid
     * value, while the field's own help says "Leave any of them blank to skip
     * it". Clearing a pixel ID was the one thing that screen could not do.
     *
     * The fold is in the TEXT arm alone. `money`, `int` and `range` still refuse
     * null, because an emptied number box holds no number.
     */
    private static function castText(mixed $raw, array $field): mixed
    {
        if ($raw === null) {
            $raw = '';
        }

        if (! is_scalar($raw)) {
            return $field['invalid'] === 'reject' ? null : $field['default'];
        }

        $value = (string) $raw;

        /*
         * ── `markup`: whether tags are text or are removed ──────────────────
         *
         * The seventh axis, and like the other six it exists because the
         * modules ANSWER differently rather than because somebody preferred
         * one. Thirteen migrated modules store what was typed; the two text
         * fields in App\Support (ReviewSettings' empty-state line,
         * ReviewBadgeSettings' count wording) have always run strip_tags first,
         * and both are printed into a page where a tag would be furniture
         * rather than wording.
         *
         * STRIPPING IS NOT WHAT MAKES A VALUE SAFE TO PRINT and must never be
         * read as though it were: every one of these strings reaches the page
         * through Blade's escaping, which is what makes it safe, and a field
         * that is printed unescaped is a defect at the print site whatever this
         * axis says (rule 5 — anything printed unescaped is a constant, never a
         * setting). This is a wording rule, kept because it is the behaviour
         * those two fields already had.
         *
         * Before the trim and the cap, so an input that is nothing but tags
         * empties and then meets the `blank` policy — which is the order both
         * classes already used.
         */
        if ($field['markup'] === 'strip') {
            $value = strip_tags($value);
        }

        $value = mb_substr(trim($value), 0, $field['max']);

        // An emptied box either means the empty string or means "put the
        // shipped wording back" — see POLICY_KEYS. Both are real answers; the
        // module says which, because only the module knows whether its page can
        // render without the string.
        if ($value === '' && $field['blank'] === 'default') {
            return $field['default'];
        }

        return $value;
    }

    /**
     * A checkbox posts many things and means two. Never null.
     *
     * `words` is the list the three modules migrated first already used.
     * `words+null` is the same list plus the literal four letters `null`, which
     * is what a value that was NULL comes back as once anything has exported
     * it — json_encode(null) is the string "null" — and is the dialect the
     * three App\Support\*Settings classes have always spoken. One word apart,
     * and the word decides whether a switch reads on or off after an import,
     * so the module says which rather than this method guessing.
     */
    private static function castBool(mixed $raw, string $mode = 'words'): bool
    {
        if (is_string($raw)) {
            $words = $mode === 'words+null'
                ? ['', '0', 'false', 'off', 'no', 'null']
                : ['', '0', 'false', 'off', 'no'];

            return ! in_array(mb_strtolower(trim($raw)), $words, true);
        }

        /*
         * A bool arrives as a bool from a checkbox and as a string from a
         * column, and the three *Settings classes stringify FIRST — `(string)
         * (is_bool($v) ? ($v ? '1' : '0') : $v)` — so an INT 0 and a FLOAT 0.0
         * reach their word list as "0" and are false there, exactly as (bool)
         * makes them false here. Measured over the recorded corpus: every
         * non-string input answers identically under both, which is why this
         * arm needs no mode.
         */
        return (bool) $raw;
    }

    /**
     * A whole number.
     *
     * Clamped into the control's own bounds when the module says so (a slider
     * cannot emit an out-of-range value, so a POST that does is not worth a
     * 422), and otherwise refused when it is not a plain run of digits — which
     * is what `money` needs, because "12.50" truncated to 12 fils is a
     * hundredfold error that reads back as a plausible number.
     */
    private static function castInt(mixed $raw, array $field, bool $clamp): ?int
    {
        if ($clamp) {
            $bounds = is_array($field['options']) ? $field['options'] : [];
            $value = (int) $raw;

            if (isset($bounds['min'])) {
                $value = max((int) $bounds['min'], $value);
            }

            if (isset($bounds['max'])) {
                $value = min((int) $bounds['max'], $value);
            }

            return $value;
        }

        $value = trim((string) $raw);

        if (preg_match('/^\d+$/', $value) !== 1 || strlen(ltrim($value, '0')) > 10) {
            return null;
        }

        return (int) $value;
    }

    /** A comma list of free words, de-duplicated case-insensitively. */
    private static function castTags(mixed $raw, array $field): string
    {
        $items = is_array($raw) ? $raw : explode(',', (string) $raw);
        $clean = [];

        foreach ($items as $item) {
            $item = trim((string) preg_replace('/\s+/', ' ', (string) $item));

            if ($item === '' || mb_strlen($item) > 40) {
                continue;
            }

            if (! in_array(mb_strtolower($item), array_map('mb_strtolower', $clean), true)) {
                $clean[] = $item;
            }
        }

        return $clean === [] ? (string) $field['default'] : implode(', ', array_slice($clean, 0, 20));
    }

    /** A comma list of positive integer ids, de-duplicated and capped. */
    private static function castIds(mixed $raw, array $field): string
    {
        $items = is_array($raw) ? $raw : explode(',', (string) $raw);
        $ids = [];

        foreach ($items as $one) {
            $id = (int) trim((string) $one);

            if ($id > 0 && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        $cap = is_array($field['options']) && isset($field['options']['cap'])
            ? (int) $field['options']['cap']
            : 24;

        return implode(',', array_slice($ids, 0, $cap));
    }

    /** A comma list filtered to keys the supplied option set actually has. */
    private static function castSections(mixed $raw, array $field): string
    {
        $known = is_array($field['options']) ? array_keys($field['options']) : [];

        $items = is_array($raw) ? $raw : explode(',', (string) $raw);

        return implode(',', array_values(array_intersect(
            array_filter(array_map(static fn ($v) => trim((string) $v), $items)),
            $known,
        )));
    }

    /**
     * A stored value, re-derived through the same cast() that let it be stored.
     *
     * ── WHAT THIS USED TO DO, AND WHY IT WAS CHANGED (Lane M3, task 2) ──────
     *
     * It used to hand the value its PHP type back and TRUST IT — `(int) $raw`
     * for a number, `(string) $raw` for everything else — on the reasoning that
     * a value in the table got there through write(), which had already cast
     * it. Round 2 left that standing as "a decision, not a cleanup", and named
     * the disagreement it created: App\Support\ReviewSettings' own docblock
     * says the opposite in as many words — the clamp is "applied on READ as
     * well as on write, so a row hand-edited in the database … cannot put the
     * storefront outside the range the screen would allow".
     *
     * Two files, one schema, two policies. This settles it by re-deriving, and
     * the argument is not symmetry — it is what was measured. Rows planted
     * straight into the two tables, by the path a WordPress import, a restored
     * backup, an older build or a hand-edit takes, and read back through each
     * module's own public reader:
     *
     *   BuildMyRoutine  steps_mode  'evil-not-an-option' READ BACK VERBATIM
     *                   offer_scope '<script>alert(1)</script>' read back
     *                               verbatim — neither is one of its options
     *                   offer_coupon 9,000 characters, past a 5,000 cap
     *   PayShipRules    cod_min    -50000 — negative money
     *                   cod_max    '12.50' read back as 12, which is the
     *                              hundredfold error castInt() was written to
     *                              REFUSE, reintroduced on the way out
     *
     * Every one of those is a line of rule 5 of the project notes, failing:
     * "a select stores one of its own options or the default". The old
     * coerceRead could not deliver that guarantee for any row it had not
     * itself seen written, and a guarantee that holds only for values that
     * arrived the expected way is not a boundary, it is a habit.
     *
     * ── WHAT THIS COSTS, AND WHY IT IS NOT A BEHAVIOUR CHANGE FOR A SHOP ────
     *
     * cast() is idempotent on everything it will store: for every field of
     * every module on this schema, over the whole adversarial corpus, casting
     * the stored value again returns it unchanged — proved by round-tripping
     * each field through the real write() and read() rather than asserted
     * (ModuleReadDerivationTest). So a shop whose values were saved from its
     * own screens reads back byte-identical, and the only rows that move are
     * the ones that could not have been saved from a screen at all.
     *
     * ── NULL IS THE DEFAULT HERE, NOT A REFUSAL ─────────────────────────────
     *
     * cast() answers null for "will not store this", which write() turns into a
     * reported rejection. A reader has no such channel and a page must render,
     * so a refusal on the way out is the module's declared default — the same
     * value a shop that has saved nothing already gets, which is the one answer
     * that cannot itself be a surprise.
     */
    private static function coerceRead(array $field, mixed $raw): mixed
    {
        $value = self::cast($field, $raw);

        return $value === null ? $field['default'] : $value;
    }

    /**
     * The render payload — what every module API controller was building by
     * hand, once, here.
     *
     * @param  array<string, array<int|string, mixed>>  $schema
     * @param  array<string, mixed>  $values
     * @param  array<string, bool>  $secrets  presence flags for `secret` fields, and
     *         nothing else — see the secret arm below for why it is its own
     *         parameter rather than another entry in $values.
     * @return array<string, array<string, mixed>>
     */
    public static function fields(array $schema, array $values, array $policy = [], array $overrides = [], array $secrets = []): array
    {
        $out = [];

        foreach (self::normalise($schema, $policy, $overrides) as $key => $f) {
            /*
             * A SECRET IS EMITTED WITHOUT A `value` KEY AT ALL.
             *
             * Not `'value' => ''`. The loop below ends in
             * `'value' => $values[$key] ?? $f['default']`, and round 3 §5 named
             * that line as the reason this type could not exist: a schema that
             * can express "encrypted credential" and still runs every field
             * through it has been asked to print one and has answered.
             *
             * `has_value` is the only fact a screen may learn, and it arrives
             * through its own parameter rather than through `$values` — so
             * there is no path by which a stored credential is a candidate for
             * this key. The `(bool)` is not decoration: an implementation that
             * answered the password itself would emit `true`.
             *
             * No `default` either. A secret has no shipped value to fall back
             * to, and a `default` on the payload is a string the console would
             * be entitled to prefill the box with.
             */
            if ($f['type'] === 'secret') {
                $out[$key] = [
                    'key' => $key,
                    'name' => $key,
                    'type' => $f['type'],
                    'label' => $f['label'],
                    'help' => $f['help'],
                    'options' => null,
                    'has_value' => (bool) ($secrets[$key] ?? false),
                ];

                continue;
            }

            $out[$key] = [
                'key' => $key,
                'name' => $key,
                'type' => $f['type'],
                'label' => $f['label'],
                'help' => $f['help'],
                /*
                 * WHAT THE MODULE DECLARED, NOT WHAT THE CAST CHECKS AGAINST.
                 *
                 * `$overrides` exists so ModuleSchema::cast() can hold a control
                 * to an option set that lives in another registry —
                 * ProductStyles' card style is one of GridSkins::ALL,
                 * SectionDividers' picker is a subset of the homepage sections.
                 * Neither screen has ever received those sets through this key:
                 * both draw their own picker from a separate top-level key on
                 * the payload (`skins`, `sections`), and the divider registry's
                 * rows are not even the `value => label` shape a select needs.
                 *
                 * So the render payload keeps emitting exactly what the
                 * module's own SCHEMA carries, which is what the nine
                 * hand-written copies of this loop emitted (`$def[4] ?? null`).
                 * Putting the override here instead moved two payloads and was
                 * caught by the snapshot diff, not by reading.
                 */
                'options' => self::declaredOptions($schema[$key] ?? null),
                'default' => $f['default'],
                'value' => $values[$key] ?? $f['default'],
            ];
        }

        return $out;
    }

    /** The option set the module's own constant names, in either shape. */
    private static function declaredOptions(mixed $def): mixed
    {
        if (! is_array($def)) {
            return null;
        }

        return array_is_list($def) ? ($def[4] ?? null) : ($def['options'] ?? null);
    }

    /**
     * The tabbed payload, from the `TABS` constant both modules already carry:
     * `tab => [label, description, [keys]]`.
     *
     * A key naming a field that is not in the schema is DROPPED and reported by
     * the guard rather than rendered — a control for a value nothing stores is
     * the defect, not a cosmetic slip.
     *
     * @param  array<string, array<int|string, mixed>>  $schema
     * @param  array<string, array{0: string, 1: string, 2: list<string>}>  $tabs
     * @param  array<string, mixed>  $values
     * @return list<array<string, mixed>>
     */
    public static function tabs(array $schema, array $tabs, array $values, array $policy = [], array $overrides = [], array $secrets = []): array
    {
        $fields = self::fields($schema, $values, $policy, $overrides, $secrets);
        $out = [];

        foreach ($tabs as $key => [$label, $description, $keys]) {
            $out[] = [
                'key' => $key,
                'label' => $label,
                'description' => $description,
                'fields' => array_values(array_filter(array_map(
                    static fn ($k) => $fields[$k] ?? null,
                    $keys
                ))),
            ];
        }

        return $out;
    }

    /**
     * The third job: describe the value in the words the owner would use.
     *
     * Used by the guard to prove a field is describable, and available to any
     * screen that wants to summarise what a module is currently doing without
     * re-deriving "on"/"off"/"not set" per type.
     *
     * @param  array<string, mixed>  $field  a normalise()d field
     */
    public static function describe(array $field, mixed $value): string
    {
        return match ($field['type']) {
            /*
             * A CONSTANT, AND IT DOES NOT LOOK AT $value.
             *
             * describe()'s whole job is to say what a module is currently
             * doing, in words an owner reads, and every other arm answers from
             * the value it was handed. For a credential the honest answer that
             * is also a safe one is a fixed string: the value is not this
             * helper's to quote, and the `default` arm below would have printed
             * it in quotes.
             */
            'secret' => 'stored, and not shown',
            /*
             * THROUGH THE MODULE'S OWN BOOL DIALECT, not through `words` for
             * everybody (Lane M3). This used to call castBool($value) flat,
             * which described a stored 'off' as "off" for the ten modules whose
             * cast reads it as TRUE, and — once `words+null` existed — would
             * have described a stored 'null' as "on" for the three whose cast
             * reads it as false. A helper whose whole job is to say what a
             * module is currently doing must answer in that module's language
             * or it is a second opinion about the same row.
             */
            'bool' => ($field['bool'] === 'cast' ? (bool) $value : self::castBool($value, $field['bool'])) ? 'on' : 'off',
            'select' => (string) (($field['options'][(string) $value] ?? null) ?? 'not set'),
            'money' => ((int) $value) === 0 ? 'no limit' : \App\Support\Money::format((int) $value),
            'int', 'range' => (string) (int) $value,
            default => trim((string) $value) === '' ? 'not set' : '“'.$value.'”',
        };
    }

    /**
     * The SETTING_RULES entries a schema needs to be saveable through the
     * generic endpoint — `key => [type, label]`, that list's own shape.
     *
     * Only `store: 'admin'` fields appear: those are the ones
     * AdminController::updateSettings() would otherwise drop in silence. The
     * guard compares this against the real constant, so a module that declares
     * an `admin` field and forgets the rule fails there rather than on the
     * owner's screen.
     *
     * @param  array<string, array<int|string, mixed>>  $schema
     * @return array<string, array{0: string, 1: string}>
     */
    public static function settingRules(array $schema, array $policy = [], array $overrides = []): array
    {
        $out = [];

        foreach (self::normalise($schema, $policy, $overrides) as $f) {
            if ($f['store'] !== self::STORE_ADMIN) {
                continue;
            }

            $out[$f['alias']] = [self::TYPES[$f['type']], $f['label']];
        }

        return $out;
    }

    /**
     * Which `admin`-stored keys of this schema the generic endpoint would drop.
     *
     * @param  array<string, array<int|string, mixed>>  $schema
     * @return list<string>
     */
    public static function missingRules(array $schema, array $policy = [], array $overrides = []): array
    {
        return array_values(array_diff(
            array_keys(self::settingRules($schema, $policy, $overrides)),
            array_keys(AdminController::SETTING_RULES)
        ));
    }
}
