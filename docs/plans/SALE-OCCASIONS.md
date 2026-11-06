# Sale page header: occasions (11.11 Mega Sale, UAE National Day, Super Sale)

The owner, 6 November 2026: turn the custom Super Sale header into occasion
designs, "select and switch the heading layout as per the occasion from the
list". Now: 11.11 Mega Sale and UAE National Day Sale (Eid Al Etihad, 2 Dec,
55th); Super Sale stays as it is. Desktop and mobile.

**Status: previews sent, waiting for the owner to pick one letter per occasion.**

- `sale-occasions/sale-occasions-preview.html`: live previews (the real live
  banner markup and CSS from SuperSaleBanner, with each theme's CSS on top).
- `sale-occasions/all-designs.jpg`: all six at 1240 and 390.
- `sale-occasions/build.py`: how the previews are built; every theme's CSS is
  in `THEMES` and is what the build ports into SuperSaleBanner.

| Occasion | A | B | C |
|---|---|---|---|
| 11.11 Mega Sale | Midnight Neon | Pink Burst | Blush & Gold Ticket |
| UAE National Day | Flag Ribbon (+55 badge) | Desert Gold (skyline) | Green & Gold Night |

Build plan once picked: an "Occasion" select in the banner editor (Super Sale /
11.11 / National Day), each occasion its own default words (EN + AR) and
palette, the same banner markup (one extra span for 11.11's number, the 55
badge), pure CSS, no new request, no new script, Arabic RTL drawn the same.
