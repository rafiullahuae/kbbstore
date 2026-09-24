# Package signing — Ed25519, and the order it has to happen in

**Read §1 before anything else.** The cryptography here is the easy part and
nothing in it can hurt you. The *order* can: a shop that has learned to require
signatures before it has been shown it can check one is a shop that refuses
every package, including the package that would fix it. That is the shape that
bricked this updater on 24 September 2026 — new code, old data, no way in — and
it is the only real risk in this work.

**What this buys, stated plainly.** A signature proves **origin**, never
**correctness**. Every one of the five packages behind the 24 September outage
would have been signed by this key, verified, and applied: they were built by
the wrong script, not by the wrong person. `UpdatePackage::checkMigrations-
AreDeclared()` and `ClassDependencyScan` are what stand between this shop and
that class of fault, and they already exist. What signing adds is that a package
which **did not come from the build machine cannot be applied at all** — the
difference between a mistake and an attack.

---

## 1 · The sequence

Numbered because the order is the whole thing. Do not merge two steps.

**Step 0 — where you are now.** Shop on 2.60.266 or later. `KBB_UPDATE_SECRET`
unset. Store → Core Updates reads *"unsigned packages accepted"*. Every package
this project has ever built carries `"signature": ""`.

**Step 1 — apply this package.** It ships `permissive`, which is what makes it
**inert**: a package with no signature installs exactly as it did yesterday, so
applying it changes nothing about which packages this shop accepts. What it adds
is the ability to *check* a signature that is there, and to say on screen which
mode the shop is in. Nothing is required of you yet.

**Step 2 — make the key pair, on the build machine only.**

```
php artisan kbb:signing-key
```

Writes the private key to `~/.config/kbb/package-signing.key`, mode 0600,
outside the repository. **Back it up offline now**, somewhere that is not this
machine and not this repository. Losing it today costs one config edit; losing
it once there are fifty installs costs fifty hand edits. It prints the public
key and a fingerprint.

**Step 3 — trust the public key.** Paste the printed line into
`config/kbb.php` → `update_public_keys`, and commit it. Only the public half
goes in; there is nothing secret in that file and there never will be.

**Step 4 — build a signed package and apply it. This is the proof step and it
is the reason this cannot be done in one go.**

```
php artisan kbb:package 2.60.2xx --since=<the last APPLIED release>
```

The build finds the key, signs, and then re-reads `update.json` out of the
finished zip and verifies it the way the server will. Apply that package on the
shop. It carries the config from step 3, so after it the shop holds the public
key *and* is running the verifier from step 1.

**Step 5 — build one more signed package and apply it.** This one is the actual
proof: the verifier now on the shop sees an `ed25519:` signature and checks it
against the key the shop holds. **If it applies, the chain works end to end on
the live shop.** If it is refused, nothing has been enforced yet — the shop is
still permissive, an unsigned rebuild gets straight back in, and the fingerprint
on Store → Core Updates against the one the build printed says why.

Steps 4 and 5 can be two ordinary releases of other work. Signing costs them
nothing.

**Step 6 — only now, require signatures.** Ship `'update_signing' => 'required'`
in a package that is itself **signed**, or set `KBB_UPDATE_SIGNING=required` in
`.env` over SSH followed by `php artisan config:clear` (`.env` alone does
nothing while the config cache exists — see §5). From here an unsigned package
is refused.

### Why it cannot be shorter

The package that teaches a shop to require signatures is applied by the verifier
that came *before* it. So the shop must be able to check a signature before it
can be told to demand one, and it must have been *seen* to check one before that
demand is safe. Step 5 is not ceremony; it is the only moment where a wrong key
is free to discover.

---

## 2 · What is signed, and why that covers the files

The signature covers **the manifest** — every key of `update.json` except
`signature` — and not the file bytes directly. That is sound because of a chain:

```
signature   binds the manifest, including the `files` map
`files`     binds every payload file by SHA-256
checkChecksums()  binds the bytes on disk to that map, IN BOTH DIRECTIONS
```

The both-directions part is the link people drop, and it is what stops the
obvious attack. `UpdatePackage::checkChecksums()` refuses a file the manifest
declares and the package lacks, **and** refuses a file the package carries and
the manifest does not declare. Without the second half you take a genuinely
signed package, add one PHP file nobody declared, and the signature still
verifies — because nothing you touched is in the signed payload.

`migrations` is inside the signed payload too, and that is not incidental: it is
the only thing that decides whether migrations run at all.

**What breaks the chain**, each pinned by a test in
`tests/Feature/PackageSigningTest.php`:

- `checkChecksums()` losing either direction;
- `verify()` dropping `checkChecksums()` from its chain;
- signing a payload with some manifest key left out — that key then becomes
  attacker-controlled;
- the builder and the server canonicalising differently. There is one
  `PackageSignature::canonical()` and no second copy of it, both sides call it,
  and the build round-trips its own zip to catch drift before the package
  exists for anyone to apply.

**Not covered, deliberately:** the zip container, entry order and timestamps.
None of them reach the server — files are read out of the extracted tree and
matched against the manifest — so a re-zipped package with identical contents is
identical as far as this updater is concerned.

---

## 3 · The key

| | where it lives |
|---|---|
| **private** | `~/.config/kbb/package-signing.key` on the build machine, mode 0600. Never in this repository, never in a package, never in any shop's `.env`. |
| **public** | `config/kbb.php` → `update_public_keys`, committed, shipped, publishable. |

A build finds the private key by `--key=`, then `$KBB_UPDATE_SIGNING_KEY`, then
the default path. It is a **path**, never the key itself: an environment
variable holding a private key leaks into `ps`, into crash dumps, into
`phpinfo()` and into anything that logs its environment.

`SigningKeyFile` refuses to read a key that is inside the repository, that is
group- or world-readable, or that is a symlink. Refusals, not warnings: getting
this wrong is worse than not signing at all, because an unsigned package at
least tells the truth about how much it has been checked, while a leaked key
produces forgeries every shop accepts as genuine.

A key file cannot reach a shop even if one were committed by accident: `.key` is
not on `UpdateGuard::ALLOWED_EXTENSIONS` and a home directory is not on
`ALLOWED_PREFIXES`, so two independent rules reject it.

**Rotation.** `update_public_keys` is a list. Ship the new key alongside the old,
apply, switch the build to the new key, then drop the old one in a later
release. No flag day.

---

## 4 · The two modes

| mode | a signature that is present | no signature |
|---|---|---|
| `permissive` *(shipped)* | verified; refused if it does not match | accepted |
| `required` | verified; refused if it does not match | refused |

There is no "off". Checking a signature that is there costs a millisecond and
can never be why a shop is stuck, because a stuck shop gets out by applying an
**unsigned** package, which permissive accepts. An "off" mode could only ever
exist to ignore a signature that *failed*, and that is the one thing this code
must not do.

Anything other than those two words in configuration resolves to `permissive`.
A typo must not be able to lock a shop out of the updater that would fix the
typo.

A package carrying a signature in a form the server does not recognise is
**refused**, not ignored — a field that looks like proof to anyone reading
`update.json` and means nothing to the code is exactly the theatre this replaces.

---

## 5 · The escape hatch, and what it costs

```
storage/app/kbb-accept-unsigned
```

Create it — empty is fine — and the shop drops back to `permissive` whatever it
is configured to require. Delete it when you are done. The Core Updates screen
says **EMERGENCY** in red for as long as it exists, on both the console and the
fallback page.

**It is a file and not a setting, for three reasons this project has already
paid for:**

1. **`.env` is not read at all while the config cache exists.** Laravel's
   `LoadEnvironmentVariables` returns early on `configurationIsCached()`, the
   cache always exists on this host, and every package ships a `clear_caches`
   migration that rebuilds it. That is exactly how `KBB_NOINDEX` read `false` on
   the live server for its entire life. An escape hatch that needs a cache clear
   needs the thing that is broken.
2. **A database setting is reachable only through the admin panel**, which is
   reachable only if the app boots.
3. **No package can create or delete it.** `storage/` is on
   `UpdateGuard::FORBIDDEN_PREFIXES`, so an update cannot damage its own way out
   — the same reason `public/kbb-recover.php` is forbidden there.

It needs SFTP, cPanel's file manager or SSH, and no shell, no migration, no
cache clear and no database.

**What it costs.** While it exists, an admin who can reach the update screen can
install an unsigned package. That is the whole point and it is also the whole
risk: it is an emergency lever, not a setting to leave on. The red banner exists
so leaving it on is not something that can happen quietly.

**The two situations it is for:**

- *The private key is lost.* No new package can be signed. Create the hatch,
  build with `php artisan kbb:package … --unsigned`, apply, generate a new key
  pair, ship the new public key, remove the hatch.
- *The shop's trusted key is wrong.* Signed packages are refused and permissive
  will not save you, because a signature that is present and does not verify is
  refused in both modes. Same route: hatch, `--unsigned`, fix
  `update_public_keys`, remove the hatch.

---

## 6 · Retiring `KBB_UPDATE_SECRET`

The old scheme was a symmetric HMAC. Symmetric is the wrong shape the moment a
second install exists: verifying needs the same secret that signs, so every
customer would hold, in a file they can read, the key to forge a package this
updater accepts as genuine — and if the same mechanism were reused for licences,
to forge a licence too.

It is unset on every install that exists, so its branch in
`UpdatePackage::checkSignature()` is unreachable today. It is kept, unchanged and
character-for-character on its original canonicalisation, for one reason only: a
shop that *did* set the secret must behave the day this lands exactly as it
behaved the day before. A retiring scheme that verifies something slightly
different from what it verified yesterday is not a compatibility path, it is a
second outage.

An `ed25519:` signature wins over the legacy branch, so a shop with the secret
set migrates by taking one signed package.

**Remove the branch, and `update_secret` with it, once no install has it set.**
Nothing else in the codebase reads it.

---

## 7 · Where it sits in the admin

**Store → Core Updates**, in the header line under the page title: the mode, in
words, plus the fingerprint of each trusted key. The same line appears on the
standalone fallback page at **Store → Core Updates → (the plain page that still
works when the admin bundle does not)**.

The fingerprint printed there is the same one `php artisan kbb:package` prints
for the key it signed with. Comparing those two by eye is the answer to "why was
my signed package refused", and it is why the fingerprint is on the screen at
all.
