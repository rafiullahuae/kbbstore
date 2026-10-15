"""Lane ARC: build the Arabic content migrations from the translated data.
# Lane ARC generator, kept for the record. It ran from storage/arc-logs/tools/ (gitignored)
# with the hand translations in storage/arc-logs/tr/. The three 2027_11_03_1000xx
# migrations embed every string, so they -- not this script -- are the source of truth.

Inputs (storage/arc-logs): live-products.json (the live public catalogue, 15 Oct),
ar-product-names.json, tr/small.json, tr/brand-desc-*.json, tr/short-*.tsv.
Outputs: database/migrations/2027_11_03_1000*_*.php
"""
import glob, json, os, re, sys

D = os.path.dirname(os.path.abspath(__file__)) + '/..'
ROOT = os.path.abspath(D + '/../..')
MIG = ROOT + '/database/migrations'

live = json.load(open(f'{D}/live-products.json'))
guard = sorted(x['slug'] for x in live)
small = json.load(open(f'{D}/tr/small.json'))
names = json.load(open(f'{D}/ar-product-names.json'))
bdesc = {}
for f in sorted(glob.glob(f'{D}/tr/brand-desc-*.json')):
    bdesc.update(json.load(open(f)))

LATIN_OK = re.compile(r'#KBeautyBliss')
ALLOWED_TAGS = {'p', 'br', 'strong', 'b', 'em', 'i', 'ul', 'ol', 'li', 'h3', 'h4', 'span'}


def check(label, value):
    tags = set(t.lower() for t in re.findall(r'</?([a-zA-Z0-9]+)', value))
    bad = tags - ALLOWED_TAGS
    if bad:
        sys.exit(f'{label}: tags {bad}')
    if re.search(r'<[^>]+\s(on\w+|style|href|src)=', value):
        sys.exit(f'{label}: attribute')
    if 'KBBAR' in value:
        sys.exit(f'{label}: heredoc marker')


def body(name):
    return open(f'{D}/tools/{name}').read()


def write(fname, header, data, body_name):
    blob = json.dumps(data, ensure_ascii=False, indent=0, sort_keys=True)
    for g, fields in data.get('groups', {}).items():
        for fld, m in fields.items():
            for k, v in m.items():
                check(f'{g}.{fld}.{k}', v)
    marker = 'KBBARC'
    php = ("<?php\n\ndeclare(strict_types=1);\n\n"
           "use Illuminate\\Database\\Migrations\\Migration;\n"
           "use Illuminate\\Support\\Facades\\Cache;\n"
           "use Illuminate\\Support\\Facades\\DB;\n"
           "use Illuminate\\Support\\Facades\\Schema;\n\n"
           f"{header}\nreturn new class extends Migration\n{{\n"
           f"    private const DATA = <<<'{marker}'\n{blob}\n{marker};\n\n"
           + body(body_name))
    open(f'{MIG}/{fname}', 'w').write(php)
    print(fname, len(php.encode()), 'bytes')


catalogue = {
    'guard': guard,
    'groups': {
        'products': {'name': names},
        'brands': {'name': small['brand_names'], 'description': bdesc},
        'categories': {'name': small['category_names'], 'description': small['category_descriptions']},
        'pages': {'title': small['page_titles']},
        'posts': {'title': small['post_titles'], 'excerpt': small['post_excerpts']},
        'menu_items': {'label': small['menu_labels']},
    },
}

HEADER_A = '''/*
 * Lane ARC: the Arabic CONTENT of the live catalogue, as data.
 *
 * 2.60.519 shipped the Arabic shop with its interface drafts; every product,
 * brand, category, menu label, page and post title on /ar still printed its
 * English, because their Arabic existed only in the preview fixture
 * (tools/arr-seed.php). This writes it into `translations`, PUBLISHED.
 *
 *   - products.name ......... every live product (kbeautyarabia spellings for
 *                             the brand at the front, the glossary for terms)
 *   - brands.name/description, categories.name/description
 *   - menu_items.label ...... by the English label, so every menu that holds it
 *   - pages.title, posts.title/excerpt
 *
 * Keyed by SLUG (labels for menu items), resolved at migration time: live ids
 * are not test ids. Source: the live public catalogue on 15 October 2026
 * (/api/products, the sitemap, each brand and collection page), recorded in
 * docs/arabic-content/.
 *
 * ONLY WHERE NO ROW EXISTS for that locale, group, item and field: a draft or a
 * translation the owner typed is never touched. Runs only on the live
 * catalogue (half the listed products present), so a fresh install and the
 * test database are untouched; running it twice writes nothing more.
 *
 * LITERALS AND FRAMEWORK CALLS ONLY (the 2.60.512 rule): the updater can run
 * this while the previous release's classes sit in opcache.
 * PUBLISHED, not drafts: these are written and reviewed by hand, and the owner
 * asked for every word on /ar in Arabic ("nothing should be in english").
 * Each stays editable under Translation (or the item's own Arabic box).
 */'''

write('2027_11_03_100000_arabic_content_catalogue.php', HEADER_A, catalogue, 'catalogue_body.php.txt')

# Phase 2: short descriptions (as far as translated)
shorts = {}
order = json.load(open(f'{D}/order.json'))
for f in sorted(glob.glob(f'{D}/tr/short-*.json')):
    for idx, val in json.load(open(f)).items():
        slug = order[int(idx)]
        if (val or '').strip():
            shorts[slug] = val.strip()
if shorts:
    HEADER_C = '''/*
 * Lane ARC, phase 2: each live product's SHORT DESCRIPTION in Arabic, as data.
 * Same rules as 2027_11_03_100000: keyed by slug, published, only where no
 * Arabic row exists, live catalogue only, literals and framework calls only.
 * The HTML is the English field's own simple markup (p, ul/li, strong, br);
 * INCI names, SPF/PA figures and sizes are kept readable.
 */'''
    write('2027_11_03_100200_arabic_content_short_descriptions.php', HEADER_C,
          {'guard': guard, 'groups': {'products': {'short_description': shorts}}}, 'catalogue_body.php.txt')
    print('short descriptions', len(shorts), 'of', len(order))

phrases = json.load(open(f'{D}/tr/phrases.json'))
for k, v in phrases.items():
    check('phrase ' + k, v)
HEADER_B = '''/*
 * Lane ARC: the homepage's OWN wording in Arabic -- the headings, lines and
 * buttons the owner typed in English (Appearance -> Homepage -> Homepage
 * content; Appearance -> Banners -> Cards banner; the Spotted section).
 *
 * 2.60.519 shipped the Arabic of the SHIPPED English for each heading
 * (HomeArabic::TEXT). The live shop's headings are his own words ("Best-
 * Sellers", "Good skin starts with the right favourites", "Glow on the Go!"),
 * so /ar printed them in English. For each such field whose stored English is
 * one of the sentences below, this writes its Arabic twin (`<key>_ar`, or the
 * card's `*_ar` column) -- ONLY where that twin is still empty, so an Arabic
 * line he typed is never touched, and only on the live catalogue.
 * Literals and framework calls only.
 */'''
write('2027_11_03_100100_arabic_content_homepage_wording.php', HEADER_B,
      {'guard': guard, 'phrases': phrases}, 'homepage_body.php.txt')
