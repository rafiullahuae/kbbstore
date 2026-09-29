# Lane SG — the evidence

**A Set showed one price on every grid in the shop and a different one on its
own page — and the basket charged a third figure, higher than either.**

Chromium 1194, `deviceScaleFactor: 2`, full page, at **390** and **1280**.
Reproduce with:

```sh
sh tools/sg-preview.sh 8710      # migrate + seed a throwaway sqlite preview
node tools/sg-shots.cjs > docs/lane-sg-shots/measurements.json
```

and, for the `-before` pass, the same run with the three set columns deleted
from `Store\ShopController::CARD_COLUMNS` and `Store\CartController::LINE_COLUMNS`
and `SG_BEFORE=1` set — which is the defect, photographed rather than described.

`document.documentElement.scrollWidth` equals the viewport on **all sixteen
shots**, before and after, at both widths. Nothing overflows.

## The fixture, in integer fils

`tools/sg-seed.php` prints these on the way past. Three sets, one per pricing
mode, all built when the box was worth **20000** fils; `products.price` written
on each the way `Admin\ProductEditorApiController` writes it at save time; and
then the toner marked down from 12000 to **10500** with nothing saved on any
set, so the box is worth **18500** today.

```
sg-night-repair-set    column price 18000  parts 18500  charged 16650  compare 18000 fils
sg-barrier-rescue-set  column price 17500  parts 18500  charged 16000  compare 17500 fils
sg-glow-starter-set    column price 18000  parts 18500  charged 14500  compare 16500 fils
```

## What moved

Read off the rendered pages, not asserted. Identical at 390 and at 1280.

| | mode | **tile — before** | **tile — after** | its own product page |
|---|---|---|---|---|
| Night Repair Set | `discount_percent`, 10.00% | AED 180, no badge | ~~AED 180~~ **AED 167** · −7% | ~~AED 180~~ **AED 167** · −7% |
| Barrier Rescue Set | `discount_amount`, AED 25 | AED 175, no badge | ~~AED 175~~ **AED 160** · −9% | ~~AED 175~~ **AED 160** · −9% |
| Glow Starter Set | `fixed` + anchor 20000 | ~~AED 180~~ AED 160 · −11% | ~~AED 165~~ **AED 145** · −12% | ~~AED 165~~ **AED 145** · −12% |

The product-page column **did not move**: it was right before and it is right
after. The tile is what came to agree with it.

## And the basket was overcharging

Two of the three added through the shop's own `POST /api/cart/add`:

| | before | after | the page offered |
|---|---|---|---|
| Night Repair Set | AED 180 | **AED 167** | AED 167 |
| Glow Starter Set | AED 160 | **AED 145** | AED 145 |
| **Cart total** | **AED 340** | **AED 311.50** | |

`Store\CartController::LINE_COLUMNS` hydrated the row that
`CartService::add()` snapshots into `cart_items.unit_price`, and it did not
carry the set columns either — so the shop advertised the derived price and the
basket took `products.price`. **AED 28.50 on two lines.**

## What it costs

`/shop/`, measured with `DB::listen` around the request, warm:

| catalogue | before this lane | after |
|---|---|---|
| 6 plain products, **no set** | 3 | **3** |
| one set | 4 | **4** |
| six sets | 9 | **4** |
| twenty-five sets (24 on the page) | 16 | **4** |

Flat in the number of sets, and **not one extra statement on a page that holds
none** — `SetPricing::prime()` looks first. The "after" column with the
`prime()` call deleted is the "before" column, which is the mutation note in
`tests/Feature/SetPriceOnGridsTest.php`.

## Where it sits in the admin

Nothing new to find. The rule itself is still
**Catalog → Product editor → What is in the box**, and this lane changes no
control — only which price the rest of the shop reads off it.

## The shots

| | 390 | 1280 |
|---|---|---|
| shop grid, three sets beside four ordinary products | `shop-grid-three-sets-390.png` | `shop-grid-three-sets-1280.png` |
| the same grid with the defect | `shop-grid-three-sets-before-390.png` | `shop-grid-three-sets-before-1280.png` |
| each set's own product page | `product-page-sg-*-390.png` | `product-page-sg-*-1280.png` |
| the basket, two sets in it | `cart-two-sets-390.png` | `cart-two-sets-1280.png` |
| the basket, overcharging | `cart-two-sets-before-390.png` | `cart-two-sets-before-1280.png` |

`measurements.json` and `measurements-before.json` are what the two runs
printed: every price as TEXT, every badge, the cart lines and total, and
`scrollWidth` at both widths.
