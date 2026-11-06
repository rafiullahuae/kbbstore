# Homepage occasion hero (before the picture slider)

The owner, 6 Nov 2026: the first banner on the homepage should be the
occasion (11.11 / National Day / New Year / Super Sale) when switched on,
then the existing picture slider; exact current sizes; more content for SEO
and offers; super light; editable; background colours moving continuously;
multiple designs, desktop and mobile.

**Status: previews sent; waiting for the owner to pick a layout.**

- Sizes measured on kbeautybliss.com: computer full width x 600px (548 at
  1024, 411 at 768), phone full width x 500px.
- `home-hero/home-hero-preview.html`: live previews, 3 layouts x 4 occasions.
- `home-hero/all-designs.jpg`: all twelve at 1280 and 390.
- `home-hero/build.py`: the CSS and markup (layouts A Spotlight, B Offer
  Stack, C Product Showcase; occasion palettes in OCC).

Moving colour: two oversized gradient layers animated with transform and
opacity only (composited, no repaint of the box), stopped under
prefers-reduced-motion. Real text (H2, offer, description, CTA) for SEO.
Build plan once picked: a Homepage → Occasion banner screen (on/off,
occasion, layout, every word EN + AR, CTA link, products/photo), drawn
before the slider in BannerSet's homepage slot, CSS for the chosen layout
only, no new script, LCP image preloaded only when on.
