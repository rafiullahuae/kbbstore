<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Where a `secret` field's value goes, and the one thing this interface is for:
 * IT HAS NO GETTER.
 *
 * ── WHY AN INTERFACE AND NOT A CALLBACK ─────────────────────────────────────
 *
 * ModuleSchema is the single security boundary every migrated module's values
 * pass through, and `secret` is the first type whose contract is about what may
 * NOT come back. Round 3 §5 put it exactly:
 *
 *   > `fields()` emits `'value'` for every field unconditionally — so a schema
 *   > that can express "encrypted credential" and still uses that loop has
 *   > already been asked to print one and answered.
 *
 * The answer taken here is not "remember not to print it". A rule that lives in
 * somebody's memory is the rule that produced the sixteen-field colour defect
 * round 1 found, five copies of three lines with the fix in only one of them.
 * So the schema is handed a thing it CANNOT READ: `put()` and `has()`, and no
 * method that returns a stored value at any signature. `ModuleSchema::write()`
 * types its parameter as this interface, so the only way for a stored
 * credential to reach the schema is for somebody to widen this file — a change
 * that shows up in a diff as what it is, rather than as one more `?? ''`.
 *
 * `MailCredentials` implements it and keeps its own `get()`, which is how
 * `MailConfigurator` still builds a transport. The narrowing is at the seam,
 * not at the store: the credential is still readable by the one caller whose
 * job is to read it, and unreadable through the schema.
 *
 * ── has() IS THE ONLY FACT A SCREEN MAY LEARN ───────────────────────────────
 *
 * Store → Mail draws the password box empty with a placeholder saying whether
 * one is stored. "Stored" is a boolean; a mask is not, because a row of
 * asterisks discloses the length. `ModuleSchema::fields()` casts this answer
 * with `(bool)` before it reaches a payload, so even an implementation that
 * returned the password from `has()` would emit `true`.
 */
interface SecretStore
{
    /**
     * Store a credential, or forget it.
     *
     * `null` means FORGET — the key is removed, not blanked. That is the third
     * write state `ModuleSchema::SECRET_FORGET` exists to carry: a screen that
     * renders the box empty cannot express "remove the stored password" with a
     * value, because the empty box already means "leave it alone".
     *
     * An empty string never reaches here from the schema: `write()` treats a
     * blank box as "unchanged" and writes nothing at all.
     */
    public function put(string $key, ?string $value): void;

    /** Is a credential stored under this key? The one question a screen may ask. */
    public function has(string $key): bool;
}
