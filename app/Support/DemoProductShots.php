<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The five gallery shots of a DEMO product, drawn on the server. (Lane GAL)
 *
 * ── WHY THIS EXISTS AT ALL ──────────────────────────────────────────────────
 *
 * The owner, looking at his product page:
 *
 *   "also i can not see the product gallery thumnails, add some demo thumnails
 *    so i can see in action."
 *
 * He never had. Store\ProductController::gallery() builds the strip from
 * `products`.image merged with `products`.images, and DemoCatalogueSeeder sets
 * NEITHER — it writes name, price, stock and the three detail columns and stops
 * there. One shot comes back, partials/product-gallery.blade.php draws the
 * `.gthumbs` strip only `@if ($shotCount > 1)`, and so there was no strip on
 * any of the 24 demo products. gallery() does pad the list to six labelled
 * shots, but only `when DemoContent::enabled()`, and demo content is
 * `settings->get('demo_content', false)` — off, and shipped off.
 *
 * ── WHY NOT THE `demo_content` SWITCH ───────────────────────────────────────
 *
 * Turning that switch on would have drawn the strip too, and it was the wrong
 * lever. `demo_content` is read by App\Services\DemoContent, whose top-ups
 * reach related products, reviews, the tab bodies and more: flipping it changes
 * pages the owner did not ask about, which is CLAUDE.md rule 1. What is below
 * changes exactly one thing — the gallery of the 24 demo products — and leaves
 * the switch at the value he set.
 *
 * ▲ ── AND WHY NOT `products`.images EITHER, WHICH IS A REVERSAL ─────────────
 *
 * This lane's brief asked for the five URLs to be written into each demo row's
 * own `images` column, with a migration for the running shop the way
 * 2027_06_15_000000 backfilled the detail tabs. That was built, it worked, and
 * it was MEASURED to be the wrong lever. The full suite went from 3 failures —
 * all three already red on the merge tip — to 26:
 *
 *   ImportRepointsWhatItFetchesTest      7 cases
 *   ImageSizesBatchTest                  3   GalleryThumbnailVariantsTest   3
 *   MediaUsagesTest                      3   GbUrlsAndMediaTest             2
 *   GdMediaSideloaderTest                2   ReviewPhotoVariantsTest        2
 *   MediaLibraryBlurbNamesEverySourceTest 1
 *
 * Not one of them is about galleries. `products`.images is CATALOGUE data, and
 * five subsystems walk it: MediaAudit ("missing: 120"), MediaUsageWriter::
 * rebuild() (added 124, not 4), the image-variants backlog (total 124, not 4),
 * the importer's re-pointer, and the media library's attachment counts. Those
 * tests were not wrong; they were reporting what the shop would do. On the
 * owner's own shop, applying that package would have put 120 placeholder
 * pictures into Content → Media Library, 120 items into the Image sizes
 * backlog — 360 more generated files once he pressed the button — and 120
 * placeholder URLs into his image sitemap and his products' schema.org. He
 * asked to see a thumbnail strip.
 *
 * So the pictures are DRAWN and the gallery is told where they are, and no
 * catalogue column moves. Store\ProductController::gallery() tops up a demo
 * product that has NO shots of its own, using the same three marks the backfill
 * would have matched on, and the moment a row stops being a demo product —
 * because the WordPress import gave it a wc_id, or because somebody gave it a
 * real photograph — it stops being topped up, with nothing left behind to
 * clean.
 *
 * ── THE LABELS ARE POSITIONS ────────────────────────────────────────────────
 *
 * gallery() labels shots BY INDEX out of its own fixed list — 0 Front,
 * 1 Texture, 2 Ingredients, 3 On skin, 4 Box, 5 Video — so these five land on
 * Front..Box and a still image can never take the caption "Video", which that
 * list reserves for the frame its own demo padding puts a play badge on.
 *
 * ── THE PICTURES ARE DRAWN HERE AND SHIPPED NOWHERE ─────────────────────────
 *
 * 120 PNG files do not travel in an update package. `BuildPackage::NEVER_SHIP`
 * already carries `public/img-cache/` for the reason CLAUDE.md records under
 * 2.60.102–.106: a package that carries generated images can DELETE them on the
 * next install. So the bytes are made on the server, inside the migration, with
 * GD — exactly as tools/release-330-banner-seed.php makes the banner pictures —
 * written under uploads/ and registered through MediaRegistrar so each one is a
 * Media Library row like any upload.
 *
 * REGISTERING THEM IS ALSO THE UNDO, and that is why it survived the reversal
 * above. Nothing points at these files from `media_usages` — no catalogue
 * column holds them — so MediaLibraryApiController::destroy()'s "still in use"
 * guard does not stand in the way, and deleting a shot in Content → Media
 * Library makes it stop appearing: urlsFor() below hands back only the files
 * that are really on disk. A change with no way to undo it is worse than no
 * change.
 *
 * ── AND THEY ARE DRAWN, NOT PHOTOGRAPHED, AND SAY SO ────────────────────────
 *
 * Same rule App\Services\DemoContent and App\Support\DemoProductDetails state:
 * a stand-in is LAYOUT, not a CLAIM. Nothing below is a photograph of a real
 * product, nothing carries a figure, an ingredient strength or a certification
 * mark, and every shot is stamped with the word DEMO so it cannot be mistaken
 * for catalogue photography by anyone looking at the page.
 */
final class DemoProductShots
{
    /** Under public_path(), and under MediaRegistrar::ROOTS' `uploads/`. */
    public const DIRECTORY = 'uploads/demo-shots';

    /**
     * The SKU prefix DemoCatalogueSeeder writes and nothing else does.
     *
     * Re-exported from DemoProductDetails rather than re-spelt, so the backfill
     * that filled the detail tabs and the migration that draws these pictures
     * can never come to disagree about which rows are demo rows.
     */
    public const SKU_PREFIX = DemoProductDetails::SKU_PREFIX;

    /**
     * The five shots, in gallery() label order.
     *
     * FIVE AND NOT SIX: gallery()'s sixth label is 'Video', and its own demo
     * padding sets `video => true` on it so the strip draws a play badge over a
     * frame with no clip behind it. A still image at index 5 would take that
     * caption and none of that meaning.
     */
    public const LABELS = ['Front', 'Texture', 'Ingredients', 'On skin', 'Box'];

    /**
     * The canvas, square.
     *
     * The main frame is `aspect-ratio:1` and the blade states width="1000"
     * height="1000" for the BOX, not for the file — see its header. 900 is
     * comfortably above the 562 CSS pixels the frame occupies at the 1180px
     * layout and above ratio-2 on a 390px phone, and every doubling of this
     * number is 120 files bigger on the owner's disk.
     */
    private const SIZE = 900;

    /* ── the shop's own palette, from resources/css/kbb/kbb.css :root ───────── */

    private const CREAM = [0xFF, 0xF8, 0xF5];      // --cream
    private const PINK_SOFT = [0xFF, 0xF0, 0xF4];  // --pink-soft
    private const BLUSH = [0xFC, 0xE0, 0xE8];      // --blush
    private const PINK = [0xE0, 0x56, 0x7B];       // --pink
    private const PINK_DEEP = [0xC1, 0x3E, 0x63];  // --pink-deep
    private const INK = [0x2A, 0x22, 0x28];        // --ink
    private const GREEN = [0x2E, 0x9E, 0x6B];      // --green
    private const GOLD = [0xBE, 0x8E, 0x2E];       // --gold
    private const WHITE = [0xFF, 0xFF, 0xFF];

    /**
     * The accent one product's five shots share, so a set reads as one product
     * and two products read as two.
     *
     * crc32 of the NAME, the way App\Support\Gradient::for() seeds the
     * placeholder a product with no picture gets today — same seed, same
     * arithmetic, so a demo product's shots sit in the family of the gradient
     * it used to show rather than introducing a second look.
     */
    private const ACCENTS = [
        self::PINK,
        self::PINK_DEEP,
        self::GOLD,
        self::GREEN,
        /* TWO THAT ARE NOT IN :root, AND THEY ARE THERE ON PURPOSE. The shop's
           own tokens give four usable accents — pink, pink-deep, gold, green —
           and 24 demo products divided by four is six products to a colour,
           which is enough repetition to read as one product photographed six
           times. These two are mixed to the same weight and saturation as the
           four above so a set drawn in either still looks like this shop's,
           and they are used for nothing but these placeholders. */
        [0x7F, 0x6B, 0xC4],   // a muted violet
        [0x4A, 0x8F, 0xBF],   // a dusty blue
    ];

    /**
     * Is this row one of DemoCatalogueSeeder's, wearing all three of its marks?
     *
     * THREE MARKS, REQUIRED TOGETHER — the same conjunction
     * 2027_06_15_000000_backfill_demo_product_details matches on, and
     * DemoProductDetails' header sets out why each alone is not enough:
     *
     *   wc_id IS NULL             the seeder's own header names this as the
     *                             mark the Migrator keys on — it upserts on
     *                             wc_id, so a seeded row can never be mistaken
     *                             for an imported one. NOT ENOUGH ON ITS OWN:
     *                             a product typed into the admin has it too.
     *   sku LIKE 'DEMO-%'         'DEMO-0001' … 'DEMO-0024', written by the
     *                             seeder and by nothing else.
     *   short_description = …     the exact sentence on all 24 rows and on
     *                             nothing an owner would write.
     *
     * A real product would have to carry a DEMO- SKU, no WooCommerce id AND
     * that sentence to be caught, and if it did it would be a demo product.
     * When the WordPress import lands, every row it writes has a real `wc_id`
     * and fails the first mark before the other two are asked.
     *
     * @param  object  $row  a Product model, or a query-builder row carrying
     *                       wc_id, sku and short_description
     */
    public static function isDemo(object $row): bool
    {
        return ($row->wc_id ?? null) === null
            && is_string($row->sku ?? null)
            && str_starts_with($row->sku, self::SKU_PREFIX)
            && (string) ($row->short_description ?? '') === DemoProductDetails::SEEDED_SHORT_DESCRIPTION;
    }

    /**
     * The shots this demo product really has on disk, in label order.
     *
     * READ-ONLY, AND DELIBERATELY SO: this is called while a product page is
     * being rendered, and a request that draws 900x900 PNGs is a request that
     * takes six seconds. The drawing is the migration's job. Five `is_file()`
     * calls is what a page pays, and a shop whose migration could not write
     * gets the page it gets today rather than five broken thumbnails.
     *
     * CONTIGUOUS, stopping at the first file that is not there, for the reason
     * ensureFor() states: gallery() labels by POSITION, so a list with a hole
     * in it does not lose one caption, it moves every caption after the hole.
     * Deleting the "Texture" shot in the Media Library leaves Front alone
     * rather than captioning the ingredients flat-lay "Texture".
     *
     * @return list<string> root-absolute URLs, `products`.image's own shape
     */
    public static function urlsFor(string $slug): array
    {
        $urls = [];

        foreach (array_keys(self::LABELS) as $i) {
            $path = self::pathFor($slug, $i);

            if (! is_file(public_path($path))) {
                break;
            }

            $urls[] = '/'.$path;
        }

        return $urls;
    }

    /**
     * The stored path of one shot, root-relative with no leading slash —
     * MediaRegistrar::normalise()'s shape.
     */
    public static function pathFor(string $slug, int $index): string
    {
        $label = strtolower(str_replace(' ', '-', self::LABELS[$index] ?? 'view'));

        // The slug is a slug already; bounded and re-cleaned anyway, because
        // this becomes a filename under public_path() and a column is only as
        // trustworthy as everything that has ever written to it.
        $safe = preg_replace('/[^a-z0-9-]+/', '-', strtolower($slug)) ?: 'demo';

        return self::DIRECTORY.'/'.mb_substr(trim($safe, '-'), 0, 60).'-'.($index + 1).'-'.$label.'.png';
    }

    /**
     * Draw the five shots of one demo product, and say which ones landed.
     *
     * Files that already exist are NOT redrawn — this is called from a
     * migration on a host where an update is a zip that may be applied twice,
     * and from a seeder that runs on every fresh install and every test
     * database build. Returns only the shots whose bytes are really on disk, so
     * a GD-less host or a read-only uploads directory yields a shorter list or
     * an empty one rather than a column full of 404s.
     *
     * ▲ AND IT STOPS AT THE FIRST FAILURE RATHER THAN SKIPPING IT, which is not
     * fastidiousness. gallery() labels shots BY POSITION, so a list with a hole
     * in it does not lose one caption — it moves every caption after the hole:
     * lose Texture and the ingredients flat-lay is captioned "Texture", the
     * skin swatch "Ingredients" and the carton "On skin". A shorter list is
     * four correct captions; a list with a hole is four wrong ones. So the
     * answer is always a contiguous run from the first shot.
     *
     * @return list<string> root-absolute URLs of the shots that landed
     */
    public static function ensureFor(string $slug, string $name): array
    {
        if (! \extension_loaded('gd') || ! \function_exists('imagecreatetruecolor')) {
            return [];
        }

        $urls = [];

        for ($i = 0; $i < count(self::LABELS); $i++) {
            $path = self::pathFor($slug, $i);
            $absolute = public_path($path);

            if (! is_file($absolute)) {
                $directory = \dirname($absolute);

                if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
                    break;
                }

                $image = self::draw($i, $name);

                if ($image === null) {
                    break;
                }

                @imagepng($image, $absolute);
                imagedestroy($image);
            }

            // Asked again after the write, because imagepng() reports a failure
            // through a warning this has silenced. The file being there is the
            // only answer worth having.
            if (! is_file($absolute)) {
                break;
            }

            // Catalogued exactly as an upload is: MediaRegistrar is the one
            // door into the library and it is idempotent by path, so a second
            // application of the package returns the existing row untouched.
            MediaRegistrar::record($path, basename($path), 'image/png');

            $urls[] = '/'.$path;
        }

        return $urls;
    }

    /** The accent this product's whole set is drawn in. */
    private static function accent(string $name): array
    {
        return self::ACCENTS[abs(crc32($name)) % count(self::ACCENTS)];
    }

    /**
     * One shot.
     *
     * EVERY SHOT HAS A DIFFERENT DOMINANT COLOUR, which is the whole point of
     * the exercise: the strip is 66px squares, and a shopper — or the owner
     * checking that a click did something — reads them by their overall colour
     * long before any shape in them. Cream, the product's own accent, white,
     * skin and blush, in that order: four light grounds no accent can collide
     * with, and the accent itself in the middle of them.
     */
    private static function draw(int $index, string $name): ?\GdImage
    {
        $s = self::SIZE;
        $img = imagecreatetruecolor($s, $s);

        if ($img === false) {
            return null;
        }

        imagealphablending($img, true);

        $accent = self::accent($name);

        /* EACH DRAWER SAYS WHAT COLOUR ITS OWN CAPTION MUST BE, because the
           backgrounds are not all light: Texture is painted in the product's
           accent and an ink caption on it is unreadable, which the first
           contact sheet showed. The alternative — measuring the pixel under
           the text — is a read per shot to answer a question the drawer
           already knows. */
        $caption = match ($index) {
            0 => self::drawFront($img, $accent),
            1 => self::drawTexture($img, $accent),
            2 => self::drawIngredients($img, $accent),
            3 => self::drawOnSkin($img, $accent),
            default => self::drawBox($img, $accent),
        };

        /* TOP-LEFT, AND THAT IS NOT A TASTE DECISION. The strip OVERLAPS the
           bottom of the main frame on a phone — measured at 390: .gmain runs
           y 241–631 and #gthumbs y 601–659, so the last 30 CSS pixels of every
           photograph sit behind the thumbnails. A caption at the foot of the
           canvas is cut in half by them, which the first run of
           tools/gal-shots.cjs photographed. Nothing overlaps the top at either
           width.

           AND CLEAR OF THE DISCOUNT BADGE, which is the second thing that run
           photographed: .lbl is positioned top:14px left:14px INSIDE .gmain, so
           it sits over the first ~88 canvas pixels on a 390px phone (where 611
           canvas pixels are one CSS pixel wide) and the first ~56 at 1280. 112
           clears the taller of the two with room to spare. */
        self::stamp($img, strtoupper(self::LABELS[$index] ?? 'VIEW'), 52, 112, 3, $caption);
        self::stamp($img, 'DEMO', 52, 160, 2, $caption);

        // A palette PNG rather than a truecolour one. These are flat shapes in
        // a handful of colours, and quantising to 64 of them is what keeps a
        // 900x900 file in single-figure kilobytes — measured at roughly a
        // seventh of the truecolour size, with no visible difference on
        // artwork that has no photograph in it.
        imagetruecolortopalette($img, true, 64);

        return $img;
    }

    /** Shot 1 — a bottle on cream. @return array the caption colour */
    private static function drawFront(\GdImage $img, array $accent): array
    {
        $s = self::SIZE;
        self::fill($img, self::CREAM);

        // The surface the bottle stands on, so the frame has a horizon.
        imagefilledrectangle($img, 0, (int) ($s * 0.72), $s, $s, self::rgb($img, self::PINK_SOFT));

        // Its shadow, before the bottle so the bottle sits on top of it.
        imagefilledellipse($img, (int) ($s * 0.5), (int) ($s * 0.735), (int) ($s * 0.44), (int) ($s * 0.07),
            self::rgba($img, self::BLUSH, 40));

        $w = (int) ($s * 0.26);
        $left = (int) ($s * 0.5 - $w / 2);
        $top = (int) ($s * 0.24);
        $bottom = (int) ($s * 0.74);

        imagefilledrectangle($img, $left, $top, $left + $w, $bottom, self::rgb($img, $accent));
        // The cap, a shade of the ink rather than a seventh colour.
        imagefilledrectangle($img, (int) ($s * 0.5 - $w * 0.28), (int) ($s * 0.16),
            (int) ($s * 0.5 + $w * 0.28), $top + 8, self::rgb($img, self::INK));
        // A label band, which is where a real pack shot carries its wordmark.
        imagefilledrectangle($img, $left, (int) ($s * 0.45), $left + $w, (int) ($s * 0.60),
            self::rgba($img, self::WHITE, 30));
        // The highlight down one side, so it reads as a cylinder.
        imagefilledrectangle($img, $left + 14, $top + 20, $left + 38, $bottom - 20,
            self::rgba($img, self::WHITE, 70));

        return self::INK;
    }

    /**
     * Shot 2 — a dollop of cream, on the product's own accent.
     *
     * THIS IS THE ONE SHOT PAINTED IN THE ACCENT RATHER THAN MERELY TRIMMED
     * WITH IT. Every other frame is a light ground with an accent object on it,
     * so two products' sets differ by a bottle and a carton; at 66px that is a
     * small difference. A full-bleed accent here makes the strip of one product
     * distinguishable from another's at a glance, and the four grounds it has
     * to stay distinct FROM — cream, white, skin and blush — are all light, so
     * no accent in the list can collide with one.
     *
     * @return array the caption colour
     */
    private static function drawTexture(\GdImage $img, array $accent): array
    {
        $s = self::SIZE;
        self::fill($img, $accent);

        // Swirls, largest first, each one lighter — a cream worked with a
        // spatula rather than a flat swatch.
        imagefilledellipse($img, (int) ($s * 0.48), (int) ($s * 0.46), (int) ($s * 0.72), (int) ($s * 0.62),
            self::rgb($img, self::BLUSH));
        imagefilledellipse($img, (int) ($s * 0.62), (int) ($s * 0.40), (int) ($s * 0.40), (int) ($s * 0.34),
            self::rgb($img, self::PINK_SOFT));
        imagefilledellipse($img, (int) ($s * 0.36), (int) ($s * 0.56), (int) ($s * 0.26), (int) ($s * 0.22),
            self::rgb($img, self::WHITE));
        imagefilledellipse($img, (int) ($s * 0.70), (int) ($s * 0.62), (int) ($s * 0.16), (int) ($s * 0.13),
            self::rgba($img, self::PINK_SOFT, 30));
        // The peak a dollop is pulled up into.
        imagefilledellipse($img, (int) ($s * 0.50), (int) ($s * 0.28), (int) ($s * 0.14), (int) ($s * 0.20),
            self::rgb($img, self::WHITE));

        return self::WHITE;
    }

    /** Shot 3 — a flat-lay of botanicals, on white. @return array the caption colour */
    private static function drawIngredients(\GdImage $img, array $accent): array
    {
        $s = self::SIZE;
        self::fill($img, self::WHITE);

        // The stem.
        imagesetthickness($img, 10);
        imageline($img, (int) ($s * 0.22), (int) ($s * 0.78), (int) ($s * 0.74), (int) ($s * 0.24),
            self::rgb($img, self::GREEN));
        imagesetthickness($img, 1);

        // Leaves off it, alternating sides, each an ellipse rotated by being
        // drawn as an arc pair — GD has no rotated ellipse, and a filled arc is
        // the cheapest leaf that still reads as one.
        foreach ([[0.32, 0.66], [0.44, 0.54], [0.56, 0.42], [0.66, 0.32]] as $k => [$x, $y]) {
            $side = $k % 2 === 0 ? -1 : 1;
            imagefilledellipse($img, (int) ($s * ($x + $side * 0.10)), (int) ($s * ($y + $side * 0.06)),
                (int) ($s * 0.20), (int) ($s * 0.11), self::rgb($img, self::GREEN));
        }

        // Two seed heads in the accent, so the set stays one product's.
        imagefilledellipse($img, (int) ($s * 0.78), (int) ($s * 0.20), (int) ($s * 0.13), (int) ($s * 0.13),
            self::rgb($img, $accent));
        imagefilledellipse($img, (int) ($s * 0.24), (int) ($s * 0.30), (int) ($s * 0.08), (int) ($s * 0.08),
            self::rgba($img, $accent, 50));

        // A drop of oil, which is what an ingredients flat-lay always has.
        imagefilledellipse($img, (int) ($s * 0.30), (int) ($s * 0.50), (int) ($s * 0.10), (int) ($s * 0.10),
            self::rgba($img, self::GOLD, 40));

        return self::INK;
    }

    /** Shot 4 — a swatch on skin. @return array the caption colour */
    private static function drawOnSkin(\GdImage $img, array $accent): array
    {
        $s = self::SIZE;
        // A warm mid skin tone, which is the one colour in this file that is
        // NOT in the shop's CSS palette — the shop has no skin token, and a
        // pink one would not read as skin at 66px.
        self::fill($img, [0xE3, 0xC1, 0xA6]);

        // Two bands of shade, so the field is a forearm rather than a swatch.
        imagefilledrectangle($img, 0, 0, $s, (int) ($s * 0.18), self::rgba($img, self::INK, 110));
        imagefilledrectangle($img, 0, (int) ($s * 0.86), $s, $s, self::rgba($img, self::INK, 115));

        // The stripe of product, half worked in.
        imagefilledellipse($img, (int) ($s * 0.40), (int) ($s * 0.46), (int) ($s * 0.46), (int) ($s * 0.30),
            self::rgba($img, self::WHITE, 25));
        imagefilledellipse($img, (int) ($s * 0.66), (int) ($s * 0.56), (int) ($s * 0.34), (int) ($s * 0.22),
            self::rgba($img, self::WHITE, 80));
        // The sheen it leaves.
        imagefilledellipse($img, (int) ($s * 0.52), (int) ($s * 0.40), (int) ($s * 0.18), (int) ($s * 0.09),
            self::rgba($img, $accent, 95));

        return self::INK;
    }

    /**
     * Shot 5 — the carton, on blush.
     *
     * ISOMETRIC, on four points that share one centre line, rather than the
     * two-vanishing-point shape this was drawn as first. That one put the top
     * face's far corner BELOW the front face's near one, so the lid folded
     * through the box and the result read as a stack of bands rather than as a
     * carton — visible in the very first contact sheet this lane rendered. Four
     * vertices, three faces off them, and no face can cross another.
     *
     * @return array the caption colour
     */
    private static function drawBox(\GdImage $img, array $accent): array
    {
        $s = self::SIZE;
        self::fill($img, self::BLUSH);

        $p = static fn (float $x, float $y): array => [(int) ($s * $x), (int) ($s * $y)];

        // The top rhombus, and the depth the two side faces drop by.
        [$tx, $ty] = $p(0.50, 0.18);   // top
        [$rx, $ry] = $p(0.80, 0.35);   // right
        [$bx, $by] = $p(0.50, 0.52);   // the near corner, where both sides meet
        [$lx, $ly] = $p(0.20, 0.35);   // left
        $drop = (int) ($s * 0.30);

        // Left face — the accent, which is the product's own colour.
        imagefilledpolygon($img, [$lx, $ly, $bx, $by, $bx, $by + $drop, $lx, $ly + $drop],
            self::rgb($img, $accent));

        // Right face — the same colour in shade, so the corner is a corner
        // rather than a seam between two hues.
        imagefilledpolygon($img, [$bx, $by, $rx, $ry, $rx, $ry + $drop, $bx, $by + $drop],
            self::rgb($img, $accent));
        imagefilledpolygon($img, [$bx, $by, $rx, $ry, $rx, $ry + $drop, $bx, $by + $drop],
            self::rgba($img, self::INK, 95));

        // The lid, lit.
        imagefilledpolygon($img, [$tx, $ty, $rx, $ry, $bx, $by, $lx, $ly],
            self::rgba($img, self::WHITE, 55));

        // The label band across the left face, where a carton carries its
        // wordmark — drawn on the face's own slope so it lies on it.
        $band = (int) ($s * 0.10);
        $top = (int) ($s * 0.10);
        imagefilledpolygon($img, [
            $lx, $ly + $top, $bx, $by + $top,
            $bx, $by + $top + $band, $lx, $ly + $top + $band,
        ], self::rgba($img, self::WHITE, 35));

        return self::INK;
    }

    /* ── the small mechanics ────────────────────────────────────────────────── */

    private static function fill(\GdImage $img, array $colour): void
    {
        imagefilledrectangle($img, 0, 0, self::SIZE, self::SIZE, self::rgb($img, $colour));
    }

    private static function rgb(\GdImage $img, array $c): int
    {
        return (int) imagecolorallocate($img, $c[0], $c[1], $c[2]);
    }

    private static function rgba(\GdImage $img, array $c, int $alpha): int
    {
        return (int) imagecolorallocatealpha($img, $c[0], $c[1], $c[2], $alpha);
    }

    /**
     * A word, in the built-in font, scaled up.
     *
     * NO TTF, DELIBERATELY. There is not a font file in this repository and
     * imagettftext() needs one on disk — a path that is right on this container
     * and a guess on Cloudways. The built-in font tops out at 9x15 pixels,
     * which is invisible on a 900px canvas, so it is drawn once at that size
     * onto a transparent scratch image and resampled up. Blocky, and legible,
     * and it cannot fail for want of a file.
     */
    private static function stamp(\GdImage $img, string $text, int $x, int $y, int $scale, array $colour): void
    {
        $font = 5;
        $w = imagefontwidth($font) * max(1, strlen($text));
        $h = imagefontheight($font);

        $tmp = imagecreatetruecolor($w, $h);

        if ($tmp === false) {
            return;
        }

        imagealphablending($tmp, false);
        imagesavealpha($tmp, true);
        imagefilledrectangle($tmp, 0, 0, $w, $h, (int) imagecolorallocatealpha($tmp, 0, 0, 0, 127));
        imagestring($tmp, $font, 0, 0, $text, self::rgb($tmp, $colour));

        imagealphablending($img, true);
        imagecopyresampled($img, $tmp, $x, $y, 0, 0, $w * $scale, $h * $scale, $w, $h);
        imagedestroy($tmp);
    }
}
