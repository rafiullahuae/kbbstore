#!/usr/bin/env bash
# Lane GST: server ms (median of 15 warm requests) and query count of the
# homepage (and /ar/) on the running gst preview. Usage: tools/gst-measure.sh <label>
set -e
APP=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
DIR=$APP/storage/framework/testing/lane-gst-preview
PORT=$(cat "$DIR/port")
for path in / /ar/; do
  for i in 1 2 3; do curl -s -o /dev/null "http://127.0.0.1:$PORT$path"; done
  ms=$(for i in $(seq 15); do curl -s -o /dev/null -w '%{time_starttransfer}\n' "http://127.0.0.1:$PORT$path"; done | sort -n | awk '{a[NR]=$1} END {printf "%.1f", a[int((NR+1)/2)]*1000}')
  bytes=$(curl -s "http://127.0.0.1:$PORT$path" | wc -c)
  echo "$1 $path server_ms_median=$ms html_bytes=$bytes"
done
export APP_URL="http://127.0.0.1:$PORT" KBB_PUBLIC_PATH="$DIR/webroot" APP_ENV=local DB_CONNECTION=sqlite DB_DATABASE="$DIR/preview.sqlite" \
  SESSION_DRIVER=file CACHE_STORE=file MAIL_MAILER=log APP_CONFIG_CACHE="$DIR/compiled/config.php" APP_ROUTES_CACHE="$DIR/compiled/routes.php" \
  APP_EVENTS_CACHE="$DIR/compiled/events.php" APP_SERVICES_CACHE="$DIR/compiled/services.php" APP_PACKAGES_CACHE="$DIR/compiled/packages.php"
php "$APP/artisan" tinker --execute='
$k = app(Illuminate\Contracts\Http\Kernel::class);
foreach (["/", "/ar/"] as $p) {
  $k->handle(Illuminate\Http\Request::create($p));
  App\Services\SettingsService::forgetMemo();
  $n = 0; $s = 0;
  Illuminate\Support\Facades\DB::listen(function ($q) use (&$n, &$s) { $n++; if (str_contains($q->sql, "settings")) $s++; });
  $r = $k->handle(Illuminate\Http\Request::create($p));
  echo "'"$1"' $p queries=$n settings_queries=$s status=".$r->getStatusCode()."\n";
  $n = -100000;
}'
