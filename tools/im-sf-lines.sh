#!/bin/sh
# Prove the two lines Lane IM asks the integrator to put in Lane SF's
# resources/views/store/product.blade.php actually render, without touching it.
#
# CLAUDE.md makes a file another lane owns off limits, and a patch handed over
# as "apply this line" that nobody has run is a patch handed over untested. So
# the lines go into the THROWAWAY COPY tools/im-preview.sh already builds under
# storage/, the product page is fetched, and what is checked is that both
# squares now name a 400px copy and the page is still a 200. The tracked file is
# never opened for writing -- tools/im-sf-apply.php holds both substitutions and
# both assertions.
#
#   sh tools/im-preview.sh 8977
#   sh tools/im-sf-lines.sh 8977
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/lane-im-preview
FILE=$DIR/app/resources/views/store/product.blade.php
PORT=${1:-8977}

test -f "$FILE" || { echo "no preview: run tools/im-preview.sh first" >&2; exit 1; }

php "$APP/tools/im-sf-apply.php" apply "$FILE"

# The same environment the preview boots with, or artisan looks for a database
# that is not there.
export KBB_PUBLIC_PATH="$DIR/webroot" APP_ENV=local APP_DEBUG=true \
  DB_CONNECTION=sqlite DB_DATABASE="$DIR/preview.sqlite" SESSION_DRIVER=file CACHE_STORE=file \
  APP_CONFIG_CACHE="$DIR/compiled/config.php" APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" APP_SERVICES_CACHE="$DIR/compiled/services.php" \
  APP_PACKAGES_CACHE="$DIR/compiled/packages.php"

# The sticky bar ships OFF (sticky_show), and it is the element the first line
# feeds. Switched on in the copy's own database so there is markup to check.
php "$DIR/app/artisan" tinker --execute="\App\Models\Setting::updateOrCreate(['key' => 'sticky_show'], ['value' => '1']);" >/dev/null 2>&1

# AND A VARIANT WITH A PICTURE, which the seed deliberately does not carry: the
# second line feeds the option swatch, and a page with no options renders no
# swatch, so without this the check would pass by having nothing to check. Its
# photograph is one the batch has already sized.
php "$DIR/app/artisan" tinker --execute='
  $p = \App\Models\Product::where("slug", "im-full-gallery")->firstOrFail();
  \Illuminate\Support\Facades\DB::table("product_variants")->insert([
    "product_id" => $p->id, "price" => 9900, "stock_status" => "instock",
    "image" => "/uploads/im/shot-3.jpg",
  ]);
' >/dev/null 2>&1

# Blade caches compiled views, and Setting::map() caches itself; the copy has
# its own storage, so both are cleared rather than waited out.
rm -rf "$DIR/app/storage/framework/views"/*.php "$DIR/app/storage/framework/cache/data"/*

curl -sS -o "$DIR/sf.html" -w 'product page: HTTP %{http_code}\n' \
  "http://127.0.0.1:$PORT/product/im-full-gallery"

php "$APP/tools/im-sf-apply.php" check "$DIR/sf.html"
