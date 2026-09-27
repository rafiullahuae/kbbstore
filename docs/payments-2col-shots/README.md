# Store → Payments, in two columns

The owner, with a screenshot of the Tabby tab and a red arrow drawn up the empty
right-hand side:

> "on tabby and tamara setting page, i want two columns, on left all keys fields
> and on right setting things. also make the sections prominent and don't give me
> onwards any classic throw away looks"

## What was wrong

Every row was an `.ecopt.wide` — `display:block`, a 520px control under a
full-width label. On a 1670px card that leaves the right two thirds of every
single row empty, and Tamara's twelve fields ran down one column with the API
tokens interleaved with the basket limits and the product exclusions.

## Measured, Chromium 141 at DPR 2, both tabs, both widths

| | Tabby 1280 | Tamara 1280 | Tabby 390 | Tamara 390 |
|---|---|---|---|---|
| card height **before** | 1318 | 2174 | 1482 | 2425 |
| card height **after** | **905** | **1426** | 1467 | 2656 |
| grid columns | `473.094px 473.094px` | `473.094px 473.094px` | `328px` | `328px` |
| `scrollWidth − clientWidth` | 0 | 0 | 0 | 0 |
| page errors | none | none | none | none |

**Tamara's card is 748px shorter at 1280** — a third of it gone, with nothing
removed. At 390 the grid collapses to one column and the card is a little taller
than before, which is the honest trade: the section heads and their subtitles are
new content, and a phone has no second column to put them beside.

Every one of the four gateways was walked, not just the two asked for:

| tab | columns at 1280 | sections |
|---|---|---|
| Cash on delivery | one, by design | *How this shop uses it* — "no account with anyone is needed, so there is nothing to paste in" |
| Tabby | two | Keys (0 of 3) · How this shop uses it |
| Tamara | two | Keys (0 of 4) · How this shop uses it |
| Credit / Debit Card | two | Keys (0 of 3) · How this shop uses it |

Cash on delivery gets ONE section and says why, rather than an empty box headed
"Keys" beside a full one — an empty panel reads as a screen that failed to load.

## Which column a field lands in

Not this screen's decision. `PaymentsGatewayTabsTest` forbids the console naming
a gateway at all, because a page that hardcodes one stops following the registry
the moment a gateway is added or renamed — and that guard caught this work twice:
once on the code and once on a comment that quoted a gateway id as an example of
what not to write.

So the answer is the fourth element of each gateway's own `configSchema()`:
`'keys'` for a value the provider issues and the owner pastes in, plus the
plumbing that connects the two shops (tokens, merchant code, the webhook secret
behind the webhook URL, the registered webhook id); `'settings'` for a decision
this shop makes (capture window, basket limits, exclusions, payment type,
whether to share order history).

`PaymentsFieldGroupsTest` fails a schema entry that leaves it out, so a field
added later cannot silently pick a column.

**Mode sits with the keys**, deliberately: it is the question "which set of keys
are these", which is why every gateway's own help text for it talks about the
keys.

## Files

`{before,after}-{tabby,tamara}-{390,1280}.png`, `after-{cod,stripe}-{390,1280}.png`,
and `{before,after}-measurements.json` with the full readings.
