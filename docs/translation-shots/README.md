# The Translation screens, in real Chromium

Driven against a live console with the four blocks in
`docs/T1B-ADMIN-APP-BLOCKS.md` applied to a scratch copy of
`resources/views/admin/app.blade.php`. The scratch copy was reverted
afterwards; nothing in this branch touches that file.

Chromium 1194, `newContext({viewport: {width: 1280, height: 900}})`, full page.

| Prefix | State |
| --- | --- |
| `off-nokey-` | **As the package ships.** Both switches off, no API key, signed in as the owner. |
| `on-rtloff-nokey-` | Arabic on, right-to-left off — the awkward combination, with the server's warning printed under the switches. |
| `on-withkey-` | Both switches on and an API key saved. |
| `editor-withkey-` | The same shop, signed in as an **editor**, who holds neither owner-only capability. |

`-run-card` and `-run-confirm` are the batch-run panel before and after the
first press. There is no `editor-withkey-run-confirm`: the editor has no button
to press, which is the point of that pair.

## What to look for

- **`off-nokey-settings`** — the shipped state. Both switches off, and the shop
  is unchanged: `/ar` does not exist. The sentence saying so is the server's
  (`root_serves`), not the screen's.
- **`on-rtloff-nokey-settings`** — Arabic on with the layout still left-to-right.
  The state is reachable, reported and not prevented; the warning text comes
  from the settings endpoint.
- **`off-nokey-machine`** and **`off-nokey-run-card`** — no key. The screen reads
  as a feature that is off rather than one that is broken, and it says the
  manual path needs no key and costs nothing. No control is drawn that would
  fail when pressed.
- **`on-withkey-run-confirm`** — the two-press spend. The figure on the button is
  the server's count of the exact pending set, quoted in **USD** and named as
  such, and it is the number posted as `confirm_characters`.
- **`editor-withkey-settings`** and **`editor-withkey-run-card`** — the honest
  degrade. The switches are readable and disabled, the API key box is gone, the
  run button is gone, and the sentence explaining why is the server's, built
  from the capabilities the endpoints actually enforce.
- **`off-nokey-progress`** — the counted figures, and the percentage that now
  reserves 100 for actually complete. An area with nothing in it says so in
  words rather than showing a full green bar.
