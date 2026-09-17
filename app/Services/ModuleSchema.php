<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Controllers\Admin\AdminController;

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

    public const STORES = [self::STORE_MODULE, self::STORE_SETTING, self::STORE_ADMIN];

    /**
     * The field types, and the SETTING_RULES type each one maps to.
     *
     * The vocabulary is not invented: these are the types the admin console
     * already draws (`bool`, `text`, `textarea`, `select`, `colour`, `money`,
     * `int`, `range`), counted off the existing service schemas rather than
     * chosen. A type this does not know is a field the renderer cannot draw,
     * so normalise() refuses it rather than emitting a control that renders as
     * a blank box.
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
    ];

    /**
     * One field, widened to its canonical form.
     *
     * Accepts the positional `[type, label, default, help]` both migrated
     * modules were already written in, so their constants did not have to be
     * rewritten to be adopted.
     *
     * @param  array<int|string, mixed>  $def
     * @return array<string, mixed>
     */
    public static function field(string $key, array $def): array
    {
        // Positional => associative. A list whose first element is a known type
        // is the old shape; anything with string keys is already the new one.
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

        $store = (string) ($def['store'] ?? self::STORE_MODULE);

        if (! in_array($store, self::STORES, true)) {
            throw new \InvalidArgumentException("Module setting “{$key}” has unknown store “{$store}”.");
        }

        if ($type === 'select' && ! is_array($def['options'] ?? null)) {
            throw new \InvalidArgumentException("Module setting “{$key}” is a select with no options.");
        }

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
        ];
    }

    /**
     * @param  array<string, array<int|string, mixed>>  $schema
     * @return array<string, array<string, mixed>>
     */
    public static function normalise(array $schema): array
    {
        $out = [];

        foreach ($schema as $key => $def) {
            $out[$key] = self::field((string) $key, (array) $def);
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
     * @param  array<string, array<int|string, mixed>>  $schema
     * @param  array<string, mixed>  $values
     * @return array{written: list<string>, rejected: array<string, string>}
     */
    public static function write(SettingsService $settings, string $module, array $schema, array $values): array
    {
        $written = [];
        $rejected = [];

        foreach (self::normalise($schema) as $key => $f) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $cast = self::cast($f, $values[$key]);

            if ($cast === null) {
                $rejected[$key] = $f['label'];

                continue;
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
     * Deliberately the same shape as EcommerceApiController::cast(), including
     * its two hard-won refusals — a money field is a whole number of fils, and
     * "12.50" in one is a hundredfold error that reads back as a plausible
     * number, so it is refused rather than truncated.
     *
     * @param  array<string, mixed>  $field  a normalise()d field
     */
    public static function cast(array $field, mixed $raw): mixed
    {
        return match ($field['type']) {
            'bool' => self::castBool($raw),
            'money', 'int', 'range' => self::castInt($raw),
            'select' => is_array($field['options']) && array_key_exists((string) $raw, $field['options'])
                ? (string) $raw
                : null,
            'colour' => preg_match('/^#[0-9a-f]{6}$/i', (string) $raw) === 1 ? (string) $raw : null,
            default => is_scalar($raw) && mb_strlen((string) $raw) <= 5000 ? (string) $raw : null,
        };
    }

    /**
     * A checkbox posts many things and means two.
     *
     * Never null: there is no invalid boolean, and returning null for one would
     * make an unchecked box look like a rejected value.
     */
    private static function castBool(mixed $raw): bool
    {
        if (is_string($raw)) {
            return ! in_array(strtolower(trim($raw)), ['', '0', 'false', 'off', 'no'], true);
        }

        return (bool) $raw;
    }

    /** A non-negative whole number, small enough for a 32-bit money column. */
    private static function castInt(mixed $raw): ?int
    {
        $value = trim((string) $raw);

        if (preg_match('/^\d+$/', $value) !== 1 || strlen(ltrim($value, '0')) > 10) {
            return null;
        }

        return (int) $value;
    }

    /** Stored values come back as strings from both tables; give them their type back. */
    private static function coerceRead(array $field, mixed $raw): mixed
    {
        return match ($field['type']) {
            'bool' => self::castBool($raw),
            'money', 'int', 'range' => (int) $raw,
            default => is_scalar($raw) ? (string) $raw : $field['default'],
        };
    }

    /**
     * The render payload — what every module API controller was building by
     * hand, once, here.
     *
     * @param  array<string, array<int|string, mixed>>  $schema
     * @param  array<string, mixed>  $values
     * @return array<string, array<string, mixed>>
     */
    public static function fields(array $schema, array $values): array
    {
        $out = [];

        foreach (self::normalise($schema) as $key => $f) {
            $out[$key] = [
                'key' => $key,
                'name' => $key,
                'type' => $f['type'],
                'label' => $f['label'],
                'help' => $f['help'],
                'options' => $f['options'],
                'default' => $f['default'],
                'value' => $values[$key] ?? $f['default'],
            ];
        }

        return $out;
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
    public static function tabs(array $schema, array $tabs, array $values): array
    {
        $fields = self::fields($schema, $values);
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
            'bool' => self::castBool($value) ? 'on' : 'off',
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
    public static function settingRules(array $schema): array
    {
        $out = [];

        foreach (self::normalise($schema) as $f) {
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
    public static function missingRules(array $schema): array
    {
        return array_values(array_diff(
            array_keys(self::settingRules($schema)),
            array_keys(AdminController::SETTING_RULES)
        ));
    }
}
