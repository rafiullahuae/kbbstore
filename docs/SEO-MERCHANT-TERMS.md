# Returns, and what kind of business this is

Lane S5, round 5. Two of the items `docs/SEO-MODULE-ROUND-4.md` §6 left waiting
on you, both now answered in your own words.

---

## 1. "At the moment we don't offer returns" — a sentence the shop could not say

### What it did before, measured

`enable_merchant` on, `merchant_ship_country` AE, the returns box set to **0** and
again to **blank** (which `AdminController::SETTING_RULES` documents as the same
value). Read off a rendered product page:

```
shippingDetails            published
hasMerchantReturnPolicy    ABSENT
```

At **14** days it publishes `MerchantReturnFiniteReturnWindow` with
`merchantReturnDays: 14`, and that was the **only** returns statement the shop
could make. So:

> **"We do not accept returns" and "we have not told you" produced identical
> markup.** The shop published its shipping terms in full and said nothing at all
> about returns.

Those are different statements. schema.org has `MerchantReturnNotPermitted` for
the first, and in a Google merchant listing a stated policy is worth more than an
absent one — a missing returns policy is a warning against the listing, where a
stated refusal is simply a term of sale.

### What it does now

The single day-count box was answering two different questions — *do you take
returns* and *for how long* — so it has been split. The number stays; the
yes/no moves to a choice of its own.

| the choice | what the page publishes |
|---|---|
| **Not stated** *(shipped, and what every shop is on today)* | the days box alone decides, byte for byte as before: above zero a finite window, at zero or blank nothing |
| **No returns** | `MerchantReturnPolicy` / `MerchantReturnNotPermitted`, with `applicableCountry` and **nothing else** |
| **A return window** | `MerchantReturnFiniteReturnWindow` + `merchantReturnDays`, and **nothing at all** if the box is empty |

Three refusals are deliberate and each is a promise nobody can make:

- **No `merchantReturnDays` beside "No returns."** A window on a policy that has
  no window is markup that contradicts itself, and you can reach that state
  easily — by choosing "No returns" without clearing a number you typed earlier.
- **No `returnMethod` or `returnFees` beside it either.** The way a refused return
  travels, and the fee for it, are not facts. This block already had to take a
  hardcoded `ReturnByMail` / `FreeReturn` back out for exactly that reason; the
  same error in the other direction would be publishing "no returns" for a shop
  that has said nothing.
- **No guessed window.** Choosing "A return window" with the box empty publishes
  nothing rather than inventing a length.

**Applying the package changes no markup on any shop.** No row is seeded, so every
shop is on **Not stated**, which is the behaviour it already had.
`SeoMerchantReturnsTest` pins that first and hardest.

### Where it sits

**Store → SEO & Meta → Google Merchant → Returns policy**, immediately above the
existing **Return window** box, because the choice decides whether that box is
read at all.

The wording it needs, for somebody who does not know what schema.org is:

> **Returns policy**
> ○ **Not stated** — *say nothing about returns.*
> ○ **We do not accept returns** — *tells Google your terms plainly. Better than
>   saying nothing.*
> ○ **We accept returns within…** — *uses the Return window below.*

And, under it, the one sentence that stops a wrong state going out:

> This is published to Google as a term of sale. Only choose an answer your
> written terms actually say.

The key is `merchant_returns`. **It must not be posted by the SEO tab's ordinary
Save** — it is listed in `SETTING_RULES` only so an existing value survives one.

---

## 2. Online-only, open all hours, no shopfront — nothing needed building

Checked against the code rather than taken on trust, and the emitters were
already right:

- **`org_type` already offers `OnlineStore`**, and the screen already says
  *"OnlineStore is right for a shop with no shopfront."*
- **24/7 needs no setting at all.** `openingHoursSpecification` and `geo` are
  properties of a schema.org **Place**, and `BusinessAddress::PLACE_TYPES` is
  `['Store', 'LocalBusiness']` only — so an `OnlineStore` emits neither, which is
  correct rather than a gap. An online shop is not a place that opens.
- **A shop with no address emits no `address` key**, and a half-filled one is
  refused outright: a node saying `{"addressCountry":"AE"}` and nothing else tells
  Google the address has been published and that it is "the UAE", which is worse
  than the silence it replaces. "Enter the address later" is already the
  behaviour.

### The one thing that was missing

Those two screens can be set so they contradict each other, and **nothing
anywhere said so**:

| state | what it publishes | reachable today? |
|---|---|---|
| `Store` or `Local business` with no usable address | a business claiming premises it has not described. Google places a local business by its address; there is nothing to place. | yes |
| map coordinates filled in under `Organization` or `Online store` | nothing — they are silently dropped, because those types are not Places. You are looking at a latitude and longitude no page emits. | yes |

Both are now one finding on **Store → SEO & Meta → SEO audit**, drawn **last** so
no card already on that screen moves. It counts **at most one**, because a shop is
in one state, and it adds nothing to "N indexable URLs scanned" — it is a fact
about the shop, not about a URL.

**On your shop it reads 0.** Online-only with the type at `Online store` or at the
shipped default `Organization`, nothing typed: no finding. Pinned, because a check
that flags a correct shop is a check that gets ignored.

**Deliberately not a finding:** an address under `Online store`. `address` and
`telephone` are properties of Organization, so a company with a registered office
and no shopfront is a real and common shape — flagging it would be inventing a
rule schema.org does not have.

### Where to set it

**Store → SEO & Meta → Organization → Organization type** → `OnlineStore`, and
leave **Store → Business Details → Business** empty unless you want your trading
address published. Nothing else to do for 24/7.

---

## 3. What could not be checked from here

**Your live terms pages could not be read.** `kbeautybliss.com` is unreachable from
this container — the egress proxy answers `CONNECT tunnel failed, response 403` —
so nothing in this round is derived from what your written returns, delivery or
privacy pages say. Everything above is driven by your answer in your own words
and by what the code does. **Before you switch "We do not accept returns" on,
check that your written terms say the same thing**, because this publishes it to
Google as a term of sale.
