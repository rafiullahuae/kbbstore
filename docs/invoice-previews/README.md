# Invoice previews

What the owner reviews before an invoice package ships.

## The files

| File | What it is |
|---|---|
| `invoice.html` | The printable tax invoice, exactly as `GET /admin-api/orders/{id}/invoice` serves it. Open it in a browser and press ⌘P / Ctrl+P: the toolbar disappears, the page shell disappears, and what the dialog shows is what the owner gets. |
| `packing-slip.html` | The same order as `GET /admin-api/orders/{id}/packing-slip` serves it — no prices anywhere, so it is safe in the parcel, including for a gift. |
| `invoice-email.html` | The HTML part of the emailed invoice (`App\Mail\OrderInvoice`). |
| `invoice-email.txt` | Its plain-text part, exactly as a text-only client receives it. |
| `invoice-print.png` | `invoice.html` rendered at A4 with the print stylesheet applied. |
| `packing-slip-print.png` | The same for the packing slip. |
| `invoice-email.png` | The emailed invoice as a mail client lays it out. |

## Which of these regenerate themselves

**The four text files do.** `tests/Feature/InvoicePreviewsTest.php` writes them
on every suite run, from the same routes and the same Mailable the store uses,
so the thing reviewed is the thing that goes out. That is the whole reason they
are produced by a test and not by a script — a preview made by hand goes stale
the first time somebody is in a hurry, and the file and the page stop being the
same thing without anyone noticing.

**The three images do not.** They are a rasterisation taken by hand at the time
of the commit, because nothing in CI has a browser. The HTML is the source of
truth; if an image and its HTML disagree, the image is the one that is wrong.
Regenerate them with:

```bash
vendor/bin/pest tests/Feature/InvoicePreviewsTest.php   # refresh the HTML first

# wkhtmltopdf --print-media-type applies the @media print rules, so the PDF is
# what a printer gets rather than a picture of a web page.
for f in invoice packing-slip; do
  wkhtmltopdf --print-media-type --enable-local-file-access \
      -s A4 -T 14mm -B 16mm -L 14mm -R 14mm \
      docs/invoice-previews/$f.html /tmp/$f.pdf
  pdftoppm -png -r 110 -f 1 -l 1 /tmp/$f.pdf /tmp/$f
  mv /tmp/$f-1.png docs/invoice-previews/$f-print.png
done
```

Note that wkhtmltopdf is Qt WebKit, not the browser the owner prints from. It
is close enough to review a layout against and it is not the thing being
shipped: the shipped artefact is the HTML, and the owner's own browser draws it.

## The order behind them

Deliberately awkward rather than tidy: two lines, one with a variant, a coupon
discount, gift wrapping, a cash-on-delivery surcharge, a gift message, an order
note, and a delivery address belonging to somebody other than the buyer. A
document reviewed only against the simplest possible order is a document whose
discount row nobody has ever seen.

The total is **47350 fils — AED 473.50 — on purpose.** `Money::displayDecimals()`
is 0 on this store, so the storefront renders that same total as "AED 474". An
invoice may not: it is filed against a bank statement. A preview showing 474
anywhere is a preview of a bug, and the test refuses to write one.
