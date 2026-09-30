<?php

declare(strict_types=1);

/**
 * Read what tools/plc-needle-scan.php recorded and print the count. (Lane PLC)
 *
 *     ./tools/plc-needle-scan.sh && php tools/plc-needle-report.php
 *
 * ── WHAT COUNTS AS AMBIGUOUS, AND WHY THE NEGATIVE FORM NEEDS NO FILTER ────
 *
 * An assertion is ambiguous when its needle occurs MORE THAN ONCE in the
 * haystack it ran against: it cannot then distinguish the thing it names from
 * the thing it does not, and it will survive the deletion of the thing it was
 * written to prove.
 *
 * `->not->toContain(...)` needs no separate handling, and this is a property of
 * the measurement rather than a choice. A negative assertion on a GREEN suite
 * has a count of exactly 0, because a single occurrence is what makes it fail.
 * So every row with count >= 1 came from a positive expectation, and every row
 * with count >= 2 is the shape this is looking for. The survey is only valid on
 * a green run for that reason, and the run's own summary is printed with it.
 *
 * ── AND MARKUP IS EXCLUDED, BECAUSE MARKUP IS THE CURE ─────────────────────
 *
 * The repair for an ambiguous needle is to name markup instead —
 * `class="co-note err" role="alert">Your bag is empty.` rather than
 * `Your bag is empty.`. A needle that already carries a tag or an attribute is
 * either specific enough by construction or is a deliberate count of repeated
 * elements, so those are listed separately and not in the headline figure.
 */
/**
 * The newest run's rows, or the directory named on the command line.
 *
 * Each scan writes into a directory of its own (see tools/plc-needle-scan.sh —
 * two runs sharing one directory cost a full fifteen-minute survey), so the
 * readers take the most recent rather than a fixed path.
 */
function kbbNewestRun(?string $given): string
{
    if ($given !== null && $given !== '') {
        return $given;
    }

    $base = __DIR__.'/../storage/plc-logs/needles';
    $runs = array_filter((array) glob($base.'/*'), 'is_dir');

    if ($runs === []) {
        return $base;
    }

    usort($runs, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

    return $runs[0];
}

$dir = kbbNewestRun($argv[1] ?? null);
echo '# rows from '.$dir."\n\n";
$files = glob($dir.'/*.jsonl') ?: [];

if ($files === []) {
    fwrite(STDERR, "no rows in {$dir} — run tools/plc-needle-scan.sh first\n");
    exit(1);
}

$rows = [];

foreach ($files as $file) {
    foreach (file($file, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $row = json_decode($line, true);

        if (is_array($row) && isset($row['needle'], $row['count'])) {
            $rows[] = $row;
        }
    }
}

/** Does this needle already name markup? Then it is the cure, not the disease. */
$isMarkup = static fn (string $n): bool => str_contains($n, '<')
    || str_contains($n, '="')
    || str_contains($n, '/>');

$total = count($rows);
$ambiguous = [];
$markupAmbiguous = 0;

foreach ($rows as $row) {
    if ((int) $row['count'] < 2) {
        continue;
    }

    if ($isMarkup((string) $row['needle'])) {
        $markupAmbiguous++;

        continue;
    }

    /* One entry per assertion SITE, carrying the worst count seen there: the
       same line runs in several cases and the counts differ per fixture. */
    $key = $row['file'].':'.$row['line'].'|'.$row['needle'];

    if (! isset($ambiguous[$key]) || $row['count'] > $ambiguous[$key]['count']) {
        $ambiguous[$key] = $row;
    }
}

uasort($ambiguous, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

$byFile = [];

foreach ($ambiguous as $row) {
    $byFile[basename((string) $row['file'])][] = $row;
}

krsort($byFile);
uasort($byFile, static fn (array $a, array $b): int => count($b) <=> count($a));

echo "toContain assertions recorded over a string haystack : {$total}\n";
echo 'assertion SITES whose needle is prose and occurs 2+   : '.count($ambiguous)."\n";
echo "sites excluded because the needle already names markup: {$markupAmbiguous}\n";
echo 'files involved                                        : '.count($byFile)."\n\n";

foreach ($byFile as $file => $hits) {
    echo $file.'  ('.count($hits)." sites)\n";

    foreach ($hits as $row) {
        printf("    x%-3d line %-5d %s\n", $row['count'], $row['line'], var_export($row['needle'], true));
    }

    echo "\n";
}
