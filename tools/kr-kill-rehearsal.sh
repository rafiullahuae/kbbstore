#!/usr/bin/env bash
#
# Lane KR -- kill -9 the WooCommerce import part-way, resume it with the same
# command, and prove by measurement that the result is the database an
# uninterrupted import produces.
#
#   tools/kr-kill-rehearsal.sh            # every scenario
#   tools/kr-kill-rehearsal.sh prepare clean k-orders   # just these
#
# WHAT IT DOES
#   prepare   full-volume export (tools/woo-volume-fixture/generate.php: 671
#             products, 4,159 orders, 10,582 lines, 3,713 customers, 2,514
#             reviews) and one migrated file-SQLite template every run copies.
#   clean     two uninterrupted imports. Diffing them gives the NOISE FLOOR: the
#             columns that differ between two runs of the same command on the
#             same files (wall-clock stamps). A killed run is "identical" when
#             every table has the same row count as clean-a and every column
#             that differs is in that floor -- nothing is masked by hand.
#   k-*       start `php artisan kbb:import` in the background, wait for the
#             named progress line, kill -9 THAT PID (never a pattern -- see
#             CLAUDE.md), record what the kill left (hot journal = a write
#             transaction was open; every checkpoint), then run the SAME
#             command again to the end and compare.
#   f-changed kill mid-orders, change orders.csv, resume: must refuse and name
#             --restart; then --restart must converge on a clean import of the
#             CHANGED export.
#
# Everything is written under storage/kr-logs/ (gitignored), never the shared
# scratchpad. Results: storage/kr-logs/results.txt, one line per scenario.
#
set -u

ROOT=$(cd "$(dirname "$0")/.." && pwd)
OUT=${KR_OUT:-$ROOT/storage/kr-logs}
EXPORT=$OUT/export
TEMPLATE=$OUT/template.sqlite
CMP="php $ROOT/tools/kr-db-compare.php"
RESULTS=$OUT/results.txt

export APP_ENV=local
export APP_KEY=base64:a3ItcmVoZWFyc2FsLWtleS0zMi1ieXRlcy1sb25nISE=
export CACHE_STORE=array QUEUE_CONNECTION=sync MAIL_MAILER=array SESSION_DRIVER=array
export DB_CONNECTION=sqlite

mkdir -p "$OUT"

say() { printf '%s\n' "$*" | tee -a "$OUT/rehearsal.log"; }

prepare() {
    rm -rf "$EXPORT" "$TEMPLATE"
    mkdir -p "$EXPORT"
    php "$ROOT/tools/woo-volume-fixture/generate.php" "$EXPORT" >/dev/null
    touch "$TEMPLATE"
    DB_DATABASE=$TEMPLATE php "$ROOT/artisan" migrate --force >"$OUT/migrate.log" 2>&1 || { say "migrate FAILED"; exit 1; }
    say "prepared: export $(wc -l <"$EXPORT/orders.csv") order lines-incl-header, template migrated"
}

fresh() { rm -f "$1" "$1-journal" "$1-wal" "$1-shm"; cp "$TEMPLATE" "$1"; }

# import <db> <export-dir> <log> [extra args]  -- foreground, returns the command's exit code
import() {
    local db=$1 dir=$2 log=$3
    shift 3
    DB_DATABASE=$db php "$ROOT/artisan" kbb:import --dir="$dir" --adopt-by-slug "$@" >"$log" 2>&1
}

# measure <name> <db>  -- dump + checks
measure() {
    $CMP dump "$2" "$OUT/$1.dump"
    $CMP checks "$2" >"$OUT/$1.checks"
}

clean() {
    local t0 t1
    for n in clean-a clean-b; do
        fresh "$OUT/$n.sqlite"
        t0=$(date +%s.%N)
        import "$OUT/$n.sqlite" "$EXPORT" "$OUT/$n.log"
        echo "exit=$?" >>"$OUT/$n.log"
        t1=$(date +%s.%N)
        measure "$n" "$OUT/$n.sqlite"
        say "$n: $(tail -1 "$OUT/$n.log"), $(echo "$t1 - $t0" | bc) s"
    done

    $CMP diffcols "$OUT/clean-a.dump" "$OUT/clean-b.dump" >"$OUT/noise.txt"
    say "noise floor (clean-a vs clean-b): $(tr '\n' ' ' <"$OUT/noise.txt")"

    if grep -q '^rows\.' "$OUT/noise.txt"; then
        say "two clean runs disagree on a ROW COUNT -- the import is not deterministic; stopping"
        exit 1
    fi

    if ! diff -q "$OUT/clean-a.checks" "$OUT/clean-b.checks" >/dev/null; then
        say "two clean runs disagree on the invariants; stopping"
        exit 1
    fi
}

# verdict <name> <reference>  -- the comparison, one line into results.txt
verdict() {
    local name=$1 ref=${2:-clean-a} extra checks rows
    $CMP diffcols "$OUT/$ref.dump" "$OUT/$name.dump" >"$OUT/$name.diffcols"

    # Columns that differ and are NOT in the noise floor. Row-count differences are never noise.
    #
    # One deliberate exception, and it is checked another way rather than
    # ignored: import_checkpoints' created/updated/unchanged split. A resumed
    # run re-reads every FINISHED entity from row one (Checkpoint::open -- the
    # property a delta import depends on), so those rows are `unchanged` on that
    # pass where the clean run counted them `created`. `checks` compares their
    # SUM, plus processed, rejected and finished, per entity -- exactly.
    extra=$(while IFS= read -r line; do
            key=${line%%=*}
            case $key in
                col.import_checkpoints.created_rows|col.import_checkpoints.updated_rows|col.import_checkpoints.unchanged_rows) continue ;;
            esac
            grep -q "^$key=" "$OUT/noise.txt" || echo "$line"
        done <"$OUT/$name.diffcols")
    rows=$(grep -E '^#count' "$OUT/$name.dump" | md5sum | cut -c1-8)

    if diff -q "$OUT/$ref.checks" "$OUT/$name.checks" >/dev/null; then checks=same; else checks=DIFFERENT; fi

    if [ -z "$extra" ] && [ "$checks" = same ]; then
        say "RESULT $name: IDENTICAL to $ref (row counts per table equal, invariants equal, only noise-floor columns differ; table-count digest $rows)"
        echo "$name|identical|$(paste -sd' ' "$OUT/$name.killstate" 2>/dev/null)" >>"$RESULTS"
    else
        say "RESULT $name: DIFFERS from $ref -- checks $checks; columns beyond noise: $(echo "$extra" | tr '\n' ' ')"
        diff "$OUT/$ref.checks" "$OUT/$name.checks" | tee -a "$OUT/rehearsal.log"
        echo "$name|DIFFERS|$(echo "$extra" | tr '\n' ' ')|$(paste -sd' ' "$OUT/$name.killstate" 2>/dev/null)" >>"$RESULTS"
    fi
}

# killrun <name> <dir> <pattern> <expect-entity> <expect-min> <expect-max> <need-journal yes|any> <delays> [extra args]
#
# Start the import, wait for <pattern> in its output, sleep one of <delays>,
# kill -9 the PID this function started. The kill is ACCEPTED only when the
# state it left is the one the scenario is about -- <expect-entity>'s checkpoint
# OPEN with processed in [min, max], and a hot journal when asked -- otherwise
# the next delay is tried. So the kill point is measured, not hoped for.
killrun() {
    local name=$1 dir=$2 pattern=$3 entity=$4 min=$5 max=$6 journal=$7 delays=$8
    shift 8
    local db=$OUT/$name.sqlite log pid processed state ok=0 attempt=0

    for delay in $delays; do
        attempt=$((attempt + 1))
        fresh "$db"
        log=$OUT/$name.kill.log
        ( DB_DATABASE=$db exec php "$ROOT/artisan" kbb:import --dir="$dir" --adopt-by-slug "$@" >"$log" 2>&1 ) &
        pid=$!

        until grep -q -- "$pattern" "$log" 2>/dev/null; do
            if ! kill -0 "$pid" 2>/dev/null; then break; fi
            sleep 0.005
        done

        sleep "$delay"
        kill -9 "$pid" 2>/dev/null
        wait "$pid" 2>/dev/null

        # BEFORE anything else opens the file: the first open rolls the journal back.
        state=$($CMP state "$db")
        processed=$(echo "$state" | sed -n "s/^checkpoint\.$entity=\([0-9]*\) OPEN$/\1/p")

        if [ -n "$processed" ] && [ "$processed" -ge "$min" ] && [ "$processed" -le "$max" ] \
            && { [ "$journal" = any ] || echo "$state" | grep -q '^hot_journal=yes'; }; then
            ok=1
            break
        fi

        say "  $name attempt $attempt (delay $delay): kill landed elsewhere -- $(echo "$state" | grep -E 'hot_|OPEN' | tr '\n' ' ')"
    done

    echo "$state" >"$OUT/$name.killstate"
    echo "delay=$delay attempts=$attempt" >>"$OUT/$name.killstate"

    if [ "$ok" != 1 ]; then
        say "RESULT $name: could not land the kill in the intended window after $attempt attempts"
        echo "$name|NOT-LANDED" >>"$RESULTS"
        return 1
    fi

    say "$name: killed pid $pid -- $(echo "$state" | grep -E 'hot_journal|OPEN|^rows\.' | tr '\n' ' ')"
    return 0
}

resume_and_verdict() {
    local name=$1 dir=$2
    shift 2
    import "$OUT/$name.sqlite" "$dir" "$OUT/$name.resume.log" "$@"
    echo "exit=$?" >>"$OUT/$name.resume.log"
    say "$name: resumed -- $(grep -h 'resumed:' "$OUT/$name.resume.log" | head -3 | tr -s ' ' | tr '\n' ';') $(tail -1 "$OUT/$name.resume.log")"
    measure "$name" "$OUT/$name.sqlite"
    verdict "$name"
}

scenario() {
    case $1 in
        # Mid-products, batches of 100, so the kill lands with several committed and the entity open.
        k-products)
            killrun k-products "$EXPORT" 'products: 300 rows' products 300 600 any '0 0.02 0.05 0' --batch=100 \
                && resume_and_verdict k-products "$EXPORT" --batch=100 ;;
        # Mid-orders, the biggest single entity by cost, INSIDE an open batch (hot journal required).
        k-orders)
            killrun k-orders "$EXPORT" 'orders: 2,000 rows' orders 2000 2000 yes '0.8 0.4 1.2 0.2' \
                && resume_and_verdict k-orders "$EXPORT" ;;
        # Failure mode (a): the very first batch of an entity -- order-items open at 0 with a hot journal.
        k-first-batch)
            killrun k-first-batch "$EXPORT" 'orders: 4,159 rows' order-items 0 0 yes '0.3 0.15 0.45 0.08 0.6 0.25' \
                && resume_and_verdict k-first-batch "$EXPORT" ;;
        # Mid-reviews: 2,514 rows, so a default run commits several batches before the kill.
        k-reviews)
            killrun k-reviews "$EXPORT" 'reviews: 1,000 rows' reviews 1000 2000 any '0 0.1 0.2' \
                && resume_and_verdict k-reviews "$EXPORT" ;;
        # Mid-categories, batches of 10 -- the tree is linked in finalise().
        k-categories)
            killrun k-categories "$EXPORT" 'categories: 10 rows' categories 10 50 any '0 0 0 0.002 0 0' --batch=10 \
                && resume_and_verdict k-categories "$EXPORT" --batch=10 ;;
        # Mid-order-items, inside a batch.
        k-order-items)
            killrun k-order-items "$EXPORT" 'order-items: 5,000 rows' order-items 5000 5000 yes '0.15 0.08 0.25 0.04' \
                && resume_and_verdict k-order-items "$EXPORT" ;;
        # Killed three times in a row (customers, then orders, then order-items), resumed after each.
        k-thrice)
            local db=$OUT/k-thrice.sqlite
            killrun k-thrice "$EXPORT" 'customers: 1,500 rows' customers 1500 1500 yes '0.3 0.15 0.5' || return
            cp "$OUT/k-thrice.killstate" "$OUT/k-thrice.killstate.1"
            # Second and third kills continue the SAME database: no fresh copy.
            for spec in 'orders: 3,000 rows|orders|0.6' 'order-items: 8,000 rows|order-items|0.1'; do
                IFS='|' read -r pattern entity delay <<<"$spec"
                ( DB_DATABASE=$db exec php "$ROOT/artisan" kbb:import --dir="$EXPORT" --adopt-by-slug >"$OUT/k-thrice.kill2.log" 2>&1 ) &
                local pid=$!
                until grep -q -- "$pattern" "$OUT/k-thrice.kill2.log" 2>/dev/null; do kill -0 "$pid" 2>/dev/null || break; sleep 0.005; done
                sleep "$delay"; kill -9 "$pid" 2>/dev/null; wait "$pid" 2>/dev/null
                say "k-thrice: killed again at '$pattern' -- $($CMP state "$db" | grep -E 'hot_journal|OPEN' | tr '\n' ' ')"
            done
            resume_and_verdict k-thrice "$EXPORT" ;;
        # Failure mode (b): the file changes between the kill and the resume.
        f-changed)
            local v2=$OUT/export-v2
            rm -rf "$v2"; cp -r "$EXPORT" "$v2"
            killrun f-changed "$v2" 'orders: 1,000 rows' orders 1000 1000 any '0.5 0.2 0.8' || return
            # A re-export in which one EARLY order (row 3, already committed) has moved on: its status.
            php -r '
                $f = $argv[1]; $rows = array_map("str_getcsv", file($f, FILE_IGNORE_NEW_LINES));
                $h = array_flip($rows[0]); $old = $rows[3][$h["status"]];
                // A status that stays DIFFERENT after the importer folds wc- prefixes, or the
                // re-export would change the fingerprint and nothing else.
                $rows[3][$h["status"]] = str_contains($old, "cancelled") ? "wc-completed" : "wc-cancelled";
                // And WooCommerce moves date_modified when a status moves, so a real re-export does too.
                // (Without this the order is re-saved with the SAME updated_at value, which Eloquent
                // treats as clean and overwrites with now() -- see docs/KR-KILL-AND-RESUME.md §5.)
                $rows[3][$h["date_modified"]] = "2026-09-30 10:00:00";
                $out = fopen($f, "wb"); foreach ($rows as $r) fputcsv($out, $r, ",", "\"", ""); fclose($out);
                fwrite(STDERR, "order ".$rows[3][$h["order_id"] ?? $h["id"]]." status ".$old." -> ".$rows[3][$h["status"]]."\n");
            ' "$v2/orders.csv" 2>>"$OUT/f-changed.killstate"
            import "$OUT/f-changed.sqlite" "$v2" "$OUT/f-changed.refused.log"
            local code=$?
            if [ "$code" -ne 0 ] && grep -q -- '--restart' "$OUT/f-changed.refused.log"; then
                say "f-changed: resume REFUSED (exit $code): $(grep -m1 'has changed' "$OUT/f-changed.refused.log" | tr -s ' ')"
            else
                say "RESULT f-changed: resume was NOT refused (exit $code)"
                echo "f-changed|NOT-REFUSED" >>"$RESULTS"
                return
            fi
            # Reference: an uninterrupted import of the CHANGED export.
            fresh "$OUT/clean-v2.sqlite"
            import "$OUT/clean-v2.sqlite" "$v2" "$OUT/clean-v2.log"
            measure clean-v2 "$OUT/clean-v2.sqlite"
            # Proof the re-export is a real change and not only a new fingerprint.
            say "f-changed: clean import of the changed export differs from clean-a in: $($CMP diffcols "$OUT/clean-a.dump" "$OUT/clean-v2.dump" | grep -v -e '_at=' -e '^col.import_checkpoints' | tr '\n' ' ')"
            import "$OUT/f-changed.sqlite" "$v2" "$OUT/f-changed.resume.log" --restart
            echo "exit=$?" >>"$OUT/f-changed.resume.log"
            measure f-changed "$OUT/f-changed.sqlite"
            verdict f-changed clean-v2 ;;
        *) say "unknown scenario $1"; return 1 ;;
    esac
}

ALL="k-products k-orders k-first-batch k-reviews k-categories k-order-items k-thrice f-changed"

if [ $# -eq 0 ]; then
    set -- prepare clean $ALL
fi

for step in "$@"; do
    case $step in
        prepare) prepare ;;
        clean) clean ;;
        *) scenario "$step" ;;
    esac
done

[ -f "$RESULTS" ] && { echo; echo "== $RESULTS"; cat "$RESULTS"; }
