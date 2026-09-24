# Operator-authored HTML: what is in those columns, what an allowlist would cost, and the decision that is the owner's

Lane F, round 2. **Nothing in this document was built.** It exists so the
decision it describes can be taken on numbers instead of on a blank page, and
the branch it is on changes no behaviour on any of the surfaces below.

Every figure was produced by booting this application against a freshly
migrated database and by loading real pages in headless Chromium against a real
HTTP server. The scripts are throwaway; the method is written out at each table
so any of it can be re-run.

---

## 0. The question

Three columns are printed on the storefront with `{!! !!}` and are not
allowlisted:

```
pages.content    resources/views/store/page.blade.php   (via @shortcodes)
posts.body       resources/views/store/post.blade.php   (via @shortcodes)
blocks.content   injected into both by [kbb_block slug="…"]
```

`App\Support\RichText` exists and is applied to the four product columns that
are printed the same way. It is **not** applied to these three, in either
language.

> **Allowlist them, and lose whatever the allowlist strips — or leave them, and
> accept that an admin account is a stored-XSS vector.**

That is a decision about what an admin may author. It is the owner's. What
follows is the cost of each answer, measured.

---

## 1. A correction to Lane FP's note, which named the wrong two files

`docs/fp-storefront-reads-translations.md` recorded this as
"`PagesApiController` and `PostsApiController` sanitise nothing". That is true
and it is beside the point: **neither controller writes anything at all.**

```
routes/web.php, every route that reaches either class
    GET  /admin-api/posts         PostsApiController::index
    GET  /admin-api/pages/store   PagesApiController::store
    GET  /admin-api/pages/user    PagesApiController::user
```

Three GETs. There is no POST, PUT or PATCH anywhere in this application that
writes `pages.content` or `posts.body`. `PostsApiController`'s own header says
so — "Read-only for now" — and the Pages screen lists pages and links to them.

So the columns are filled by:

| writer | column | sanitised? |
| --- | --- | --- |
| `2026_08_29_140000_seed_policy_pages` | `pages.content` | constant in a migration |
| `2026_11_06_000000_seed_footer_content_pages` | `pages.content` | constant in a migration |
| `DemoContentController::seedPages/seedPosts` | both | constants in the controller |
| `Import\Entities\PostImporter` | `posts.body`, `posts.excerpt` | **yes** — `RichText::clean()`, via `cleanBodyReported()` |

`PostImporter` also **refuses** a WordPress `page` row outright, with a stated
reason, so a third-party export cannot reach `pages.content` at all.

**Every existing writer of those two columns is either a constant in this
repository or already allowlisted.** The hole Lane FP pointed at is real, but it
is not where that note put it, and the two files it named are the wrong place to
look.

### Where it actually is

Two endpoints, both live, both reachable by a signed-in admin today:

| endpoint | writes | sanitised? |
| --- | --- | --- |
| `POST` / `PUT /admin-api/blocks` — Content → HTML Blocks | `blocks.content` | **no** — `['nullable','string','max:200000']`, a length check |
| `POST /admin-api/translations` — Content → Translations | `translations.value` for `pages.content` and `posts.body` | **no** — Lane FP's fix is scoped to `TranslationStore::RICH_GROUP`, which is `products` |

A block is rendered into any page or post that names it, so its HTML reaches
the storefront through a column nobody edits. And the Translations screen
accepts `pages(title,content)` and `posts(title,excerpt,body)` — read straight
off `TranslationEstimate::CONTENT` — and publishes immediately, with no draft
step.

---

## 2. What is in the columns today

Freshly migrated database, so this is the corpus the shop ships with and the
repository's durable record of what is on the server.

### `pages.content` — 7 rows, 11,368 bytes

Tag census, counted by parsing the markup rather than by eye:

```
a(16)  em(5)  h3(24)  li(15)  ol(1)  p(40)  strong(10)  ul(1)
```

Attribute census, every attribute on every tag:

```
a@href(16)
```

**That is the whole list.** Sixteen hrefs and nothing else — no `style`, no
`id`, no `class`, no `data-*`, no event handler, and none of `script`, `style`,
`iframe`, `object`, `embed`, `form`, `svg` or `math`.

Every tag above is on `RichText`'s allowlist already.

### `posts.body` — 0 rows

The `posts` table ships empty. Articles arrive through `PostImporter`, which
cleans. Demo articles are a `<p>` built in `DemoContentController`.

### `blocks.content` — 0 rows

`2026_10_09_000000_create_blocks_table` seeds nothing.

---

## 3. What `RichText::clean()` would do to that corpus

Run over all seven rows. Per row, before and after, comparing byte length, the
full tag sequence, and the rendered text with entities decoded:

| page | bytes | rendered text identical | tag sequence identical |
| --- | ---: | :---: | :---: |
| `privacy-policy` | 4015 → 4003 | **yes** | **yes** |
| `terms-and-conditions` | 2980 → 2976 | **yes** | **yes** |
| `delivery` | 1069 → 1066 | **yes** | **yes** |
| `refund_returns` | 1020 → 1017 | **yes** | **yes** |
| `faqs` | 894 → 891 | **yes** | **yes** |
| `contact-us` | 731 → 728 | **yes** | **yes** |
| `about` | 659 → 656 | **yes** | **yes** |

Every byte-level substitution it makes, across all seven rows — the complete
list, not a sample:

```
5 ×   &rarr;               =>  →
2 ×   &rsquo;              =>  ’
1 ×   &ldquo;…&rdquo;      =>  “…”
```

**Eight named HTML entities become the characters they already rendered as.**
`RichText::clean()` parses its input as HTML and writes it back out, so a named
entity comes back as its literal UTF-8 codepoint. Nothing is stripped. No tag,
no attribute and no word is lost from any of the seven pages.

**The cost of allowlisting `pages.content` today is 34 bytes and no visible
change.**

---

## 4. What it would cost in general — the capability list

The corpus is small and tame, so the honest cost is not "what happens to these
seven rows" but "what could the owner no longer write". Measured by running
`RichText::clean()` over each thing a page or a block is actually for,
including the three uses `BlocksApiController`'s own header names:

| what | verdict | what survives |
| --- | --- | --- |
| a shipping note — `<p><strong>…</strong> …</p>` | **unchanged** | all of it |
| a payment-logo strip — `<div class>` + `<img>` | kept, `style` dropped | layout class and images survive; inline CSS does not |
| a seasonal banner — `<div class>`, `<h3>`, `<p>`, `<a class href>` | kept, `style` dropped | all structure, all classes, the link |
| text pasted from Word | kept | `<div class="WordSection1">`, `<p class="MsoNormal">`, `<span>`; the inline `font-family` goes |
| a table with inline styling | kept, `style` dropped | the whole table |
| in-page jump links (`<h3 id="delivery">`) | **`id` dropped** | the heading; the anchor target is lost |
| a YouTube or Maps embed (`<iframe>`) | **dropped whole** | nothing |
| `<video>`, `<audio>`, `<svg>`, `<form>`, `<input>`, `<button>` | **dropped whole** | nothing |
| `<h1>` in body copy | unwrapped | the words, not the tag (an SEO rule, deliberate) |

`RichText`'s allowlist is more generous than its header suggests: `div`, `span`,
`section`, `article`, `figure` and `figcaption` all survive **with their
`class`**, added because the owner asked to paste real markup and keep it. So a
laid-out block stays laid out, as long as its styling lives in a named class
rather than in a `style` attribute.

**So the real losses, stated plainly, are four:**

1. **inline `style=`** — everywhere. `RichText`'s own reasoning: an inline
   `position:fixed` with a large `z-index` is an invisible layer over the whole
   page, which is a clickjacking primitive rather than a formatting choice.
2. **`id=`** — everywhere. Costs in-page anchor links on a long FAQ.
3. **`<iframe>`** — so no embedded video, no embedded map.
4. **`<video>` / `<audio>` / `<svg>` / `<form>`** — no self-hosted media, no
   embedded form.

None of the four is used by anything in the shop today.

---

## 5. What leaving them costs — driven, not argued

A real HTTP server, a real browser, the payload written into each column and
the public page loaded. Dialogs counted by the browser, not inferred.

```
payload:  <p>ZZSENTINEL…</p><img src=x onerror=alert(1)><script>alert(2)</script>

/about/        HTTP 200   dialogs fired: ["1","2","3"]   text seen: ZZSENTINELBODY, ZZSENTINELBLOCK
/zz-xss-post/  HTTP 200   dialogs fired: ["1","2"]       text seen: ZZSENTINELBODY
```

The third dialog on `/about/` is the one that matters most: it came from
`blocks.content`, reached by the page merely containing
`[kbb_block slug="…"]`. A block is the one of the three with a save button.

And the Arabic half, with the **English left clean** so the two can be told
apart — written through `TranslationStore::put()`, which is exactly what
Content → Translations does with the owner's typing:

```
/about/        HTTP 200   dialogs fired: []          text seen: ZZENGLISHCLEAN
/ar/about/     HTTP 200   dialogs fired: ["9","8"]   text seen: ZZSENTINELARABIC
```

**So an admin account is a stored-XSS vector on this shop today, through two
live endpoints**, and a session hijacked or a password reused is enough to use
it. Nothing here is a hypothetical about a future editor.

---

## 6. The two options

### Option A — allowlist both languages

`RichText::clean()` on the way in, at:

- `BlocksApiController::validated()`, for `blocks.content`;
- `TranslationStore::put()`, by widening `RICH_GROUP`/`RICH_FIELDS` to
  `pages.content` and `posts.body` — the one function all writers go through,
  which is where Lane FP put the products rule for the same reason;
- and, when an editor for pages or posts is eventually built, it inherits the
  rule instead of reopening the hole.

**Cost:** the four capabilities in §4 — inline `style`, `id`, `iframe`,
media/form tags. **On today's data: 34 bytes across 7 rows and no visible
change**, and nothing at all on the other two columns, which are empty.

**What it does NOT cost:** the existing pages keep every tag, every link and
every word. There is no migration and no backfill implied — the rule runs on
the way in, so stored rows are only cleaned if somebody chooses to re-save
them.

**The asymmetry Lane FP stopped for is gone under this option**, because it
cleans both languages at once, so one document cannot render differently in its
two.

### Option B — leave them

**Cost:** §5, unchanged. An admin account is a stored-XSS vector. That is a
defensible position for a one-operator shop with a strong password and no
second admin — it is the same position `BlocksApiController`'s header already
takes in as many words ("A block's HTML is trusted (it is authored by a
signed-in admin and rendered unescaped)") — but it should be a position taken
on purpose rather than one inherited.

**If Option B is chosen, one thing is still worth doing and is much smaller:**
the Arabic half currently goes in through a screen that publishes immediately
with no draft step, while the English half has no editor at all. Whatever the
answer for English, the two halves should follow the same rule, because a
document that is allowlisted in one language and not the other is the one
outcome neither option wants.

---

## 7. A middle option, stated because it is cheap and was measured

Allowlist `blocks.content` and the two translated fields — the two things an
admin can actually write today — and leave `pages.content` and `posts.body`
alone, since **no endpoint writes them** and every writer that exists is either
a constant in this repository or already cleaned.

**Cost: nothing at all on today's data.** Both empty tables, and the seven pages
are untouched because nothing re-saves them. It closes both live endpoints in
§1 and costs the four capabilities only for HTML written *inside a block*, which
is the one place a laid-out banner would want them — so it is the option that
trades most directly.

This is offered as a third answer, not as a recommendation. **Nothing was
built for any of the three.**

---

## 8. What this lane did not do, and why

No sanitiser was added, no column was changed, no endpoint was altered, and
`ProductEditorApiController` was not touched. The branch this document is on
changes behaviour on none of these surfaces. Lane FP stopped here for the right
reason and this round did the measuring rather than overruling it.
