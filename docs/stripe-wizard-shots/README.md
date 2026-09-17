# Set up Stripe — driven in real Chromium

Chromium 1194, `newContext({viewport:{width:1280,height:1050}, deviceScaleFactor:2})`,
against a seeded install served through `public-web-root`, signed in as a real
owner. Every state below was reached by clicking, not by posing markup.

**No Stripe account and no real key.** Every value is invented
(`ca_EXAMPLEDEVELOPMENT1`, `sk_test_EXAMPLEPLATFORM01`), and no request reached
Stripe — this box's egress proxy blocks every Stripe host, which is also why the
written guide still prints menu paths and no links.

| # | File | What it proves |
| --- | --- | --- |
| 01 | `01-panel-set-up-stripe.png` | **As the package ships.** One primary button. The paste box is kept, demoted into a details block for somebody who already holds the key. |
| 02 | `02-wizard-choose-mode.png` | Step 1 — the selection. Test is described as the safe one to do first; Live says "real cards, real money". |
| 03 | `03-wizard-test-steps.png` | Step 2 for Test mode. Three steps, and a button that opens `dashboard.stripe.com/test/apikeys` in a new tab. |
| 04 | `04-wizard-live-steps.png` | The same screen for Live: the link becomes `/apikeys` and the placeholder becomes `sk_live_…`. Two links exist in the whole console and this is the other one. |
| 05 | `05-wizard-server-says-why.png` | A refusal. **This is the picture that matters most.** Before this package every attempt came back "Stripe refused the connection" — the console's generic line over a 419 the request never survived. Here the server's own words arrive: "That is the publishable key." |
| 06 | `06-one-click-now-appears.png` | A Connect application saved **through the screen**, which was impossible before: the pill reads *one click ready* and **Connect with Stripe (one click)** is drawn beside Set up Stripe. |
| 07 | `07-wizard-offers-one-click.png` | With that saved, the wizard's step 2 becomes the one-click button instead of a key to fetch — with `or paste a key instead` as the way back. |

## What to look for

- **01 vs 06.** Nothing about the button changed between them. What changed is
  that `Save Connect application` can now write, so `oauth_ready` can become
  true, so the button can be drawn. The feature was never missing; the screen
  could not reach it.

- **05 — the generic sentence is gone.** Every write on this panel sent
  `X-Requested-With` and no CSRF token while every other write in the console
  sends `X-XSRF-TOKEN`. Laravel answers that with 419 and the panel's own
  handler renders any non-ok response as one line. Three Stripe writes and two
  others elsewhere in the console were failing that way, silently, since they
  shipped.

- **03/04 — the one-click is withheld per MODE, not globally.** A client id
  alone opens Stripe's authorize screen and cannot redeem the code that comes
  back, so drawing the button on a half-set-up mode would fail *after* the owner
  had granted this shop access to his Stripe account.

- **It is a modal, not a second browser window.** A window this page opens onto
  itself is the one kind a popup blocker stops for no benefit, and it would
  leave the owner copying a key out of one window and into another. The windows
  that do open point at Stripe.
