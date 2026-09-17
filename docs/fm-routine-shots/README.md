# Build my routine — what was actually on screen

Taken in real Chromium (`/opt/pw-browsers/chromium-1194/chrome-linux/chrome`,
`browser.newContext({viewport})`) against a running preview of this branch: the
fall-through router, `SESSION_DRIVER=file`, the repo's own demo catalogue, and
one real coupon created through `POST /admin-api/coupons/manage`. Every product
name, brand and price below is a row in `products`; nothing on any of these
pages was written by the template.

The two files this lane may not edit — `routes/web.php` and
`resources/views/admin/app.blade.php` — were edited on **scratch copies** for
the duration, using the exact blocks in `docs/FM-ADMIN-APP-BLOCKS.md`, and
reverted afterwards. Nothing in this branch touches either.

## The shopper

| file | what it shows |
| --- | --- |
| `01-list-desktop.png` | `/routines` — eight routines, the site-wide offer strip naming the real coupon `ROUTINE10` with that coupon's own 10% and its AED 150 minimum |
| `02-routine-desktop.png` | `/routines/acne` with the owner's own title ("Clear-skin routine"). Five steps, each a real in-stock row through the shop's own product card, sale prices honoured, total **AED 667** = 217 + 148 + 81 + 134 + 87 |
| `03-routine-swapped.png` | the same routine at `?cleanse=gentle-foaming-cleanser` — step 1 is now the other cleanser, and the swap is in the URL |
| `04-routine-with-a-gap.png` | `/routines/ageing`. Nothing in this shop is tagged as a treatment for fine lines, so step 3 says **"Not stocked yet"** and links to the shop. It is not dropped, and the total says "the 4 steps shown" |
| `06-list-phone.png`, `07-routine-phone.png` | both at 390px |

## The owner

| file | what it shows |
| --- | --- |
| `11-admin-screen.png` | Catalog → Build my routine. The headline figures are **21 on the storefront / 17 given a step / 4 still untagged**, then what each step can draw from, then the tagging list |
| `12-admin-untagged-only.png` | the same screen filtered to `role=none` — the question the screen exists to answer |
| `13-admin-after-tagging.png` | after giving one of them a step through the real control: **4 → 3**. The counter is read back off the save's own response, not guessed |
| `14-admin-phone.png` | 390px. `#content` measured `scrollWidth 390 / clientWidth 390` — no horizontal overflow |

No `pageerror` fired on any page in any of these runs.

## Off means off

Not a screenshot, because the evidence is that there is nothing to photograph.
With the module switched off through the shop's own `POST /admin-api/modules`:

* `/routines` and `/routines/acne` answer **404**; with it on, both answer 200.
* `/`, `/shop`, `/cart`, `/skin-quiz` and `/korean-skincare-brands` are
  **byte-identical** with the module on and off once the CSRF token is
  normalised — diffed, not reasoned about.
* No page of the shop contains the string `/routines` in either state. This
  lane adds no link; see `docs/FM-ADMIN-APP-BLOCKS.md` for where the menu item
  belongs and why it is the owner's to add.
