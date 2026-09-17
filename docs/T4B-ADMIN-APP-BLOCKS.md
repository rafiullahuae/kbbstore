# T4b · the blocks for `resources/views/admin/app.blade.php`

Lane EX may not edit that file. Everything else in T4b is implemented; these
fifteen blocks are the remainder, written so they can be applied mechanically.

**Each block is an exact ANCHOR and an exact REPLACEMENT.** Every anchor occurs
**exactly once** in `resources/views/admin/app.blade.php` at the tip this branch
was cut from — verified by count, not by eye. Apply in any order; no anchor
overlaps another's replacement.

All fifteen were applied to a scratch copy of the file, the console was driven
in real Chromium, and the result is the screenshots in
`docs/arabic-editor-shots/`. The scratch copy was then reverted; nothing in this
branch touches `app.blade.php`.

## What they do, in one line each

| # | Where | What |
| --- | --- | --- |
| 1 | the includes | loads the shared helper, `admin/partials/arabic-boxes.blade.php` |
| 2–5 | Mega Menu item form | an Arabic **Label** box, wired and sent with the save |
| 6–10 | Catalog → **Brands** tab | Arabic **Name** and **Description** boxes |
| 11–15 | Catalog → **Categories** tab | Arabic **Name** and **Description** boxes |

Blocks 6–15 exist because the console has **two** brand editors and **two**
category editors. The Brands and Categories screens in
`admin/partials/*-screen.blade.php` are Lane EX's and already carry their boxes;
the older Catalog-tab editors live in `app.blade.php` and write the same
endpoints. An Arabic box present on one screen and absent on the other is how a
translation gets silently dropped by whichever screen the operator happened to
open, so both get boxes or the feature is a coin toss.

Nothing here fails closed in a bad way: every call site tests `window.KBBArabic`
first, so applying block 1 alone, or blocks 2–15 alone, leaves a console that
works — it simply draws no Arabic boxes until both halves are in.

---

## Block 1 · include the shared Arabic-box helper

**Anchor** (occurs once):

```
@include('admin.partials.media-picker')
```

**Replacement:**

```
{{-- T4b · the Arabic boxes (Lane EX). FIRST, for the same reason the media
     picker below it is first: it defines exactly one global,
     window.KBBArabic, and every screen that draws an Arabic box — the product
     editor, the categories tree, the brands editor and the mega-menu form —
     calls it later. Every one of those screens checks for the global before
     using it, so a build that has them and not this include simply draws no
     Arabic boxes rather than throwing. --}}
@include('admin.partials.arabic-boxes')

@include('admin.partials.media-picker')
```

---

## Block 2 · mega menu, the Arabic label helper

**Anchor** (occurs once):

```
function mgmOpenForm(parentId, depth, editing){
```

**Replacement:**

```
/* T4b — the Arabic label box for a menu item.  (Lane EX)

   WHICH FIELDS GET A BOX IS THE SERVER'S ANSWER, NOT THIS SCREEN'S.
   MenuItem::$translatable is `label` and nothing else: `url` is deliberately
   left off it, because one menu item points at one page and the /ar prefix is
   what makes that page Arabic — a translated URL would be a second address to
   keep in step by hand. So this asks the payload rather than listing fields,
   and a column added to that allowlist grows a box here with no edit.

   MGM.translatable is the EMPTY shape, which is what lets the "Add item"
   dialog draw a box for an item that does not exist yet. Without it a menu
   could only be translated on a second visit, which is the "somewhere else,
   afterwards" the plan rules out. */
function mgmArabicLabel(editing){
  if(!window.KBBArabic) return '';

  const shape = (editing && editing.translations) || (typeof MGM !== 'undefined' && MGM && MGM.translatable);

  if(!shape || !shape[KBBArabic.locale]
     || !Object.prototype.hasOwnProperty.call(shape[KBBArabic.locale], 'label')) return '';

  return `<div style="padding:2px 0 11px;border-bottom:1px solid #f2f5f8">${KBBArabic.box({
    field: 'label',
    label: 'Label',
    prefill: (editing && editing.translations) || null,
    maxlength: 60,
    from: '#mgmfLabel'
  })}</div>`;
}

function mgmOpenForm(parentId, depth, editing){
```

---

## Block 3 · mega menu, draw the box under the English label

**Anchor** (occurs once):

```
    <div class="mmrow"><div class="mmlbl"><b>Label</b></div>
      <input type="text" id="mgmfLabel" maxlength="60" value="${escAttr(editing?.label || '')}" placeholder="e.g. Skincare"></div>
```

**Replacement:**

```
    <div class="mmrow"><div class="mmlbl"><b>Label</b></div>
      <input type="text" id="mgmfLabel" maxlength="60" value="${escAttr(editing?.label || '')}" placeholder="e.g. Skincare"></div>
    ${mgmArabicLabel(editing)}
```

---

## Block 4 · mega menu, wire the Translate button

**Anchor** (occurs once):

```
  const colorInput = $('#mgmfColor');
```

**Replacement:**

```
  /* Attaches the Translate button and reveals it ONLY if an API key is
     configured. With no key it is never shown, and typing the Arabic by hand
     works with no account and no bill — which is how the menu is actually
     going to be translated. */
  if(window.KBBArabic) KBBArabic.wire(document);

  const colorInput = $('#mgmfColor');
```

---

## Block 5 · mega menu, send the Arabic in the same request

**Anchor** (occurs once):

```
    const payload = {
      label,
      url: $('#mgmfUrl').value.trim() || null,
```

**Replacement:**

```
    const payload = {
      label,
      /* T4b — the Arabic travels with the ordinary save, in the same request
         as the English label and stored by the same button. A blank box is
         SENT rather than omitted: blank means "not translated yet" and has to
         reach the server to delete the row, which is the only reason the
         Translation screen's progress figure can be counted. */
      translations: window.KBBArabic ? KBBArabic.collect(document) : {},
      url: $('#mgmfUrl').value.trim() || null,
```

---

## Block 6 · Catalog tab brand editor, remember the shape

**Anchor** (occurs once):

```
  var BRANDS=[];
```

**Replacement:**

```
  var BRANDS=[];

  /* T4b · the empty shape of the Arabic boxes, for this tab's "Add brand"
     dialog. /admin-api/brands hands it down beside the rows, so this screen
     never carries its own copy of Brand::$translatable.

     THIS IS THE SECOND BRAND EDITOR IN THE CONSOLE. The other one is the
     Brands screen in admin/partials/brands-editor-screen.blade.php, and it
     already has its boxes. Both write the same endpoint, so a brand edited
     here and a brand edited there have to offer the same fields — an Arabic
     box present on one and absent on the other is how a translation gets
     silently dropped by whichever screen the operator happened to open. */
  var BRANDS_ARABIC=null;
```

---

## Block 7 · Catalog tab brand editor, keep the shape from the list

**Anchor** (occurs once):

```
      var d=await brandWrite('','GET',null); BRANDS=d.brands||[];
```

**Replacement:**

```
      var d=await brandWrite('','GET',null); BRANDS=d.brands||[];
      BRANDS_ARABIC=d.translatable||BRANDS_ARABIC;
```

---

## Block 8 · Catalog tab brand editor, the two Arabic boxes

**Anchor** (occurs once):

```
      '<div class="fld"><label>Name</label><input id="brd_name" value="'+sesc(brand.name)+'"></div>'+
```

**Replacement:**

```
      '<div class="fld"><label>Name</label><input id="brd_name" value="'+sesc(brand.name)+'">'+
        (window.KBBArabic ? KBBArabic.boxIf((brand&&brand.translations)||BRANDS_ARABIC, {
          field:'name', label:'Name', prefill:(brand&&brand.translations)||null,
          maxlength:255, from:'#brd_name'
        }) : '')+'</div>'+
```

---

## Block 9 · Catalog tab brand editor, the Arabic description

**Anchor** (occurs once):

```
      '<div class="fld"><label>Description</label><textarea id="brd_desc" class="inp" rows="3">'+sesc(brand.description)+'</textarea></div>'+
```

**Replacement:**

```
      '<div class="fld"><label>Description</label><textarea id="brd_desc" class="inp" rows="3">'+sesc(brand.description)+'</textarea>'+
        (window.KBBArabic ? KBBArabic.boxIf((brand&&brand.translations)||BRANDS_ARABIC, {
          field:'description', label:'Description', prefill:(brand&&brand.translations)||null,
          type:'textarea', rows:3, maxlength:5000, from:'#brd_desc'
        }) : '')+'</div>'+
```

---

## Block 10 · Catalog tab brand editor, send the Arabic and wire the button

**Anchor** (occurs once):

```
    wireImgUpload('brd_logo','brands');
    document.getElementById('brd_save').onclick=async function(){
      var payload={
        name: sval('brd_name'), slug: sval('brd_slug'), logo: sval('brd_logo'),
        description: sval('brd_desc'), position: parseInt(sval('brd_pos'),10)||0
      };
```

**Replacement:**

```
    wireImgUpload('brd_logo','brands');

    /* Attaches the Translate buttons and reveals them ONLY if an API key is
       configured. With no key they are never shown and typing by hand works. */
    if(window.KBBArabic) KBBArabic.wire(document);

    document.getElementById('brd_save').onclick=async function(){
      var payload={
        name: sval('brd_name'), slug: sval('brd_slug'), logo: sval('brd_logo'),
        description: sval('brd_desc'), position: parseInt(sval('brd_pos'),10)||0,
        /* The Arabic goes up in the SAME request as the English. A blank box
           is SENT rather than omitted — blank means "not translated yet" and
           has to reach the server to delete the row. */
        translations: window.KBBArabic ? KBBArabic.collect(document) : {}
      };
```

---

## Block 11 · Catalog tab category editor, keep the shape from the list

**Anchor** (occurs once):

```
      var d=await catalogWrite('/categories','GET',null); CATEGORIES=d.categories||[];
```

**Replacement:**

```
      var d=await catalogWrite('/categories','GET',null); CATEGORIES=d.categories||[];
      /* T4b · the empty shape of the Arabic boxes, for this tab's "Add
         category" dialog. THIS IS THE SECOND CATEGORY EDITOR in the console —
         the other is admin/partials/category-tree-screen.blade.php, which
         already has its boxes. Both write the same endpoint, so both have to
         offer the same fields. */
      CATEGORIES_ARABIC=d.translatable||CATEGORIES_ARABIC;
```

---

## Block 12 · Catalog tab category editor, declare the shape

**Anchor** (occurs once):

```
  window.catCategories = async function(){
```

**Replacement:**

```
  var CATEGORIES_ARABIC=null;

  window.catCategories = async function(){
```

---

## Block 13 · Catalog tab category editor, the two Arabic boxes

**Anchor** (occurs once):

```
      '<div class="fld"><label>Name</label><input id="cat_name" value="'+sesc(cat.name)+'"></div>'+
```

**Replacement:**

```
      '<div class="fld"><label>Name</label><input id="cat_name" value="'+sesc(cat.name)+'">'+
        (window.KBBArabic ? KBBArabic.boxIf((cat&&cat.translations)||CATEGORIES_ARABIC, {
          field:'name', label:'Name', prefill:(cat&&cat.translations)||null,
          maxlength:255, from:'#cat_name'
        }) : '')+'</div>'+
```

---

## Block 14 · Catalog tab category editor, the Arabic description

**Anchor** (occurs once):

```
      '<div class="fld"><label>Description</label><textarea id="cat_desc" class="inp" rows="3">'+sesc(cat.description)+'</textarea></div>'+
```

**Replacement:**

```
      '<div class="fld"><label>Description</label><textarea id="cat_desc" class="inp" rows="3">'+sesc(cat.description)+'</textarea>'+
        (window.KBBArabic ? KBBArabic.boxIf((cat&&cat.translations)||CATEGORIES_ARABIC, {
          field:'description', label:'Description', prefill:(cat&&cat.translations)||null,
          type:'textarea', rows:3, maxlength:5000, from:'#cat_desc'
        }) : '')+'</div>'+
```

---

## Block 15 · Catalog tab category editor, send the Arabic and wire the button

**Anchor** (occurs once):

```
    wireImgUpload('cat_image','categories');

    document.getElementById('cat_save').onclick=async function(){
      var parent=sval('cat_parent');
      var payload={
        name: sval('cat_name'), slug: sval('cat_slug'),
        parent_id: parent===''?null:parseInt(parent,10),
        description: sval('cat_desc'), image: sval('cat_image'),
        position: parseInt(sval('cat_pos'),10)||0
      };
```

**Replacement:**

```
    wireImgUpload('cat_image','categories');

    /* Attaches the Translate buttons and reveals them ONLY if an API key is
       configured. With no key they are never shown and typing by hand works. */
    if(window.KBBArabic) KBBArabic.wire(document);

    document.getElementById('cat_save').onclick=async function(){
      var parent=sval('cat_parent');
      var payload={
        name: sval('cat_name'), slug: sval('cat_slug'),
        parent_id: parent===''?null:parseInt(parent,10),
        description: sval('cat_desc'), image: sval('cat_image'),
        position: parseInt(sval('cat_pos'),10)||0,
        /* The Arabic goes up in the SAME request as the English. */
        translations: window.KBBArabic ? KBBArabic.collect(document) : {}
      };
```

---
