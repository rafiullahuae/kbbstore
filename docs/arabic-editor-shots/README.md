# T4b — the Arabic boxes, as they actually render

Captured in real Chromium (1280×900) against this branch, with the fifteen
blocks in `docs/T4B-ADMIN-APP-BLOCKS.md` applied to a scratch copy of
`resources/views/admin/app.blade.php`. That copy was reverted afterwards; the
branch itself does not touch that file.

JPEG at quality 72 rather than PNG: eleven full-size PNGs came to 1.2 MB and
this repo is the durable record of a site that ships as zip packages.

| File | What it shows |
| --- | --- |
| `1-product-editor-name.jpg` | The product editor on a saved product. The Arabic name sits directly under the English one, right-to-left, marked العربية · Arabic, inside the same Basics panel and saved by the same Save button. |
| `2-product-full-description-rich-arabic.jpg` | The full description. The Arabic counterpart is the **same rich-text control** as the English — same toolbar, same HTML view, same word count — carrying a real Arabic heading and bullet list. **No Translate button**, deliberately: long descriptions are typed, never machined. |
| `3-product-short-description.jpg` | The short description, with no API key configured. The Arabic box is there; the Translate button is not. |
| `4-product-create-form.jpg` | A **blank create form**. The Arabic boxes are present before the product exists — the requirement is Arabic *at the moment of creation*, not on a screen visited afterwards. |
| `5-categories-screen-editor.jpg` | Catalog → Categories (`admin/partials/category-tree-screen.blade.php`). |
| `6-brands-screen-editor.jpg` | Catalog → Brands (`admin/partials/brands-editor-screen.blade.php`). |
| `7-mega-menu-item-form.jpg` | The mega-menu item dialog. `label` gets a box; `url` does not, and that is the plan's decision — one address per item, language carried by the `/ar` prefix. |
| `8-category-typed-arabic-rtl.jpg` | **Real Arabic typed in**, in both the name and the description box, beside their English. This is the RTL rendering proof: the text runs right to left and the full stop lands on the left. |
| `9-translate-button-with-a-key.jpg` | The same dialog as 8 **with an API key saved**. Two Translate buttons appear. Compare with 8: with no key they are not rendered at all, and every manual path works regardless. |
| `10-catalog-tab-category-editor.jpg` | The *other* category editor, the Catalog → Categories tab in `app.blade.php` (blocks 11–15). |
| `11-catalog-tab-brand-editor.jpg` | The *other* brand editor, the Catalog → Brands tab in `app.blade.php` (blocks 6–10). |

## How they were produced

A file-SQLite copy of the demo catalogue, `php -S` over `public-web-root` with a
router that falls through to real files, and Playwright's bundled Chromium.
Product 1 was given a real Arabic name, short description and formatted
description first, so shots 1 and 2 show a prefill and not an empty box.
