# Ed25519 package signing — the pictures

Chromium, deviceScaleFactor 2, at 390 and 1280. `document.documentElement.scrollWidth`
equalled the viewport in every shot (390 and 1280), so nothing added here widens a page.
The header line is 13px, which is the size it already was.

Store → Core Updates, the header line under the page title:

| shot | what it shows | the line it prints |
|---|---|---|
| `console-default-*` | **AS SHIPPED.** No key, permissive. | `Version 1.0.0 · unsigned packages accepted` — byte for byte what it printed before this patch |
| `console-key-trusted-*` | after the owner's public key is in `config/kbb.php` | `signatures verified · unsigned packages still accepted · trusted key 179B65319A2E491D` |
| `console-required-*` | after step 6 of the rollout | `signed packages only · trusted key …` |
| `console-emergency-*` | `storage/app/kbb-accept-unsigned` present | `EMERGENCY: unsigned packages accepted — delete storage/app/kbb-accept-unsigned`, in red |

The standalone fallback page (`/admin/updates?fallback=1`) carries the same four:
`fallback-asshipped-*`, `fallback-default-*` (key trusted), `fallback-emergency-*`.

A real package through the real screen, built with `php artisan kbb:package`:

| shot | package | what the screen said |
|---|---|---|
| `accepted-signed-*` | signed with the trusted key | *Package verified. Review the file list, then apply.* |
| `refused-forged-*` | signed with a DIFFERENT key (fingerprint 754DEA53100926D2) | *Signature does not match. This package was not produced by the build machine this site trusts…* |
| `refused-tampered-*` | the accepted package, one line added to `config/kbb.php` after signing | *Checksum mismatch on config/kbb.php* |
| `refused-unsigned-required-*` | unsigned, shop on `required` | *…create an empty file at storage/app/kbb-accept-unsigned…* |
| `hatch-accepts-unsigned-*` | the same unsigned package, hatch present | *Package verified.* |
| `hatch-still-refuses-forged-*` | the forged package, hatch present | still refused — the hatch relaxes "must be signed", never "must be genuine" |

The key pair in these shots was generated for the shots and discarded. No real
signing key exists yet; making one is step 2 of `docs/PACKAGE-SIGNING.md`.
