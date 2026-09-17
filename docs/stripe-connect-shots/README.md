# One-click Stripe Connect, in real Chromium

Driven against a live console with the six blocks in
`docs/FG-ADMIN-APP-BLOCKS.md` applied to a scratch copy of
`resources/views/admin/app.blade.php`, and
`routes/payments-connect-platform.php` mounted in a scratch copy of
`routes/web.php`. Both scratch copies were reverted afterwards; nothing in this
branch touches either file. The document reproduces the screenshotted file
byte for byte — the blocks were parsed back out of the committed markdown and
re-applied to check it.

Chromium 1194, `newContext({viewport: {width: 1280, height: 950}})`,
`SESSION_DRIVER=file`, a fall-through router in front of
`public-web-root/index.php`. Shot 02 is taken at a taller viewport because the
guide is longer than 950px and an element screenshot is clipped to the window.

**No Stripe account, no real key and no call to Stripe.** Every value in these
pictures is invented (`ca_EXAMPLEDEVELOPMENT1`, `sk_test_EXAMPLEPLATFORM01`)
and the one shot that needs Stripe to answer — 06, the success page — was
produced by faking the HTTP client in-process, exactly as the test suite does,
and then dispatching the real callback. The page, its payload and its
`postMessage` are the shipped ones.

| # | File | State |
| --- | --- | --- |
| 01 | `01-not-set-up` | **As the package ships.** No Connect application. The one-click button is absent and the panel says why, without making the paste path read as a failure. |
| 02 | `02-setup-guide` | The setup guide open. Seven steps, menu paths, **not one link** — see below. |
| 03 | `03-half-set-up-button-held-back` | A client id saved and no platform secret. The button is deliberately still absent, and the sentence says which half is missing. |
| 04 | `04-one-click-ready` | Both halves saved. **Connect with Stripe (one click)** appears beside Connect Stripe. |
| 05 | `05-popup-blocked-fallback` | `window.open` returning null. A link that works, and a Re-check button. |
| 06 | `06-popup-connected` | The popup's own page on success. It reports to the opener and closes itself. |
| 07 | `07-popup-declined` | He pressed Cancel at Stripe. A **real state was minted through the real `start` endpoint** first, so this is the page he actually sees — the state matched and Stripe's own words are what is read back. |
| 08 | `08-popup-state-refused` | A callback whose state does not match. Nothing was exchanged and nothing was written. |
| 09 | `09-connected` | Connected. The Connect application panel stays, because a disconnect leaves the platform values in place and this is what he will use next time. |
| 10 | `10-payments-screen-full` | The whole payments screen, for context. |

## What to look for

- **`01` vs `03`.** Two different states that used to read the same. `01` is
  "you have not set this up"; `03` is "you set it up and half of it is
  missing". Only the second is a mistake, and only the second is worth
  interrupting him about.

- **`03` — the button is not there, on purpose.** A client id alone is enough
  to open Stripe's authorize screen and not enough to redeem the code that
  comes back. Drawing the button would mean the flow fails AFTER he has granted
  this shop access to his Stripe account, leaving him with a live authorisation
  to go and revoke and nothing connected. The panel refuses earlier and says so.

- **`02` — no links.** Every step names a menu path and carries no URL. That is
  not laziness: outbound access from the machine this was built on is proxied
  and `dashboard.stripe.com` and `docs.stripe.com` are both blocked by it, so
  no Stripe page could be loaded and confirmed to be what it is described as. A
  wrong link in a credential-setup guide is the shape of a phishing page — an
  owner already holding a secret key, already expecting to be asked for it,
  following a link his own admin panel gave him. A test
  (`it prints no URL it could not verify`) stops a later edit adding a guessed
  one.

- **`02`, last paragraph.** The guide ends by saying the manual path loses
  nothing. It is true and it is the most important sentence on the panel: if
  Stripe declines to enable Connect for him, or the platform profile asks for
  something that does not describe this shop, he stops there and pastes a key.

- **`05` — the fallback is a link, not an apology.** A user clicking an anchor
  is a gesture no popup blocker stops. `rel="opener"` is load-bearing:
  `target="_blank"` implies `rel=noopener` in every current browser, the
  callback page would find `window.opener` null, and the console would never be
  told the outcome — a tab that says "connected" over a screen that still says
  "not connected". The Re-check button covers even that.

- **`06`, `07`, `08` — three popup outcomes, one page.** It never sits there
  showing a JSON body, and it carries no credential: the payload is the account
  report and the warnings. The webhook URL is absent too — its random tail is
  what makes this shop's endpoint unguessable and this page can end up in
  history.

- **`09` — the panel does not disappear once connected.** `disconnect()`
  deliberately keeps the platform values, so the one-click button is there the
  *next* time too. The sentence changes to say so rather than telling him to
  press a button that is not on the screen.
