<?php

declare(strict_types=1);

/**
 * Which of the ambiguous sites are actually HIDING something. (Lane PLC)
 *
 *     ./tools/plc-needle-scan.sh && php tools/plc-needle-holes.php
 *
 * ── THE TEST THE COORDINATOR ASKED FOR, MADE MECHANICAL ────────────────────
 *
 * "The cheap test is whether blanking the thing under test still leaves the
 * assertion green." That cannot be run 157 times by hand, but it can be
 * DECIDED from where the copies sit, which the scan now records:
 *
 *   · every copy inside the SAME kind of element — two rows of one list, three
 *     cards in one grid — means blanking that component removes every copy, so
 *     the assertion goes red and is hiding nothing. The repeat is the page
 *     doing its job.
 *
 *   · copies in DIFFERENT elements mean at least one of them is not the thing
 *     the test is about. Blanking the element under test leaves the other
 *     standing and the assertion green. THAT is the hole.
 *
 * So the headline number is sites whose contexts disagree. It is a proxy and it
 * is named as one; the sites it picks out are then read and mutated, and the
 * ones that turn out to be fine are recorded with the reason rather than
 * silently dropped.
 */
$dir = $argv[1] ?? __DIR__.'/../storage/plc-logs/needles';
$files = glob($dir.'/*.jsonl') ?: [];

if ($files === []) {
    fwrite(STDERR, "no rows in {$dir} — run tools/plc-needle-scan.sh first\n");
    exit(1);
}

$sites = [];
$kinds = [];

foreach ($files as $file) {
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $row = json_decode($line, true);

        if (! is_array($row) || ! isset($row['needle'], $row['count'])) {
            continue;
        }

        $kinds[$row['kind'] ?? 'toContain'] = ($kinds[$row['kind'] ?? 'toContain'] ?? 0) + 1;

        if ((int) $row['count'] < 2) {
            continue;
        }

        $needle = (string) $row['needle'];

        // Markup needles are the CURE, not the disease.
        if (str_contains($needle, '<') || str_contains($needle, '="') || str_contains($needle, '/>')) {
            continue;
        }

        $key = $row['file'].':'.$row['line'].'|'.$needle;

        if (! isset($sites[$key]) || $row['count'] > $sites[$key]['count']) {
            $sites[$key] = $row;
        }
    }
}

/** Does this needle read like a sentence a shopper is shown? */
$isSentence = static fn (string $n): bool => str_contains($n, ' ')
    && preg_match('/[a-z]{3}/', $n) === 1
    && ! str_starts_with(trim($n), '/')          // a regex
    && ! str_contains($n, '=>')                  // php source being asserted
    && ! str_contains($n, '&&')
    && ! str_contains($n, '();');

$sentences = array_filter($sites, static fn (array $r): bool => $isSentence((string) $r['needle']));

$disagree = [];
$agree = [];

foreach ($sentences as $key => $row) {
    $contexts = array_values(array_filter((array) ($row['contexts'] ?? [])));

    if ($contexts === []) {
        // Too many copies to fingerprint, or none captured. Cannot decide.
        continue;
    }

    if (count(array_unique($contexts)) > 1) {
        $disagree[$key] = $row;
    } else {
        $agree[$key] = $row;
    }
}

uasort($disagree, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

echo "assertions recorded, by kind:\n";

foreach ($kinds as $kind => $n) {
    printf("    %-12s %d\n", $kind, $n);
}

echo "\nambiguous sites (prose needle, 2+ occurrences)      : ".count($sites)."\n";
echo 'of which read as a sentence a shopper is shown      : '.count($sentences)."\n";
echo "  copies all in the SAME element (repeat, not hole) : ".count($agree)."\n";
echo '  copies in DIFFERENT elements (candidate HOLE)     : '.count($disagree)."\n\n";

$byFile = [];

foreach ($disagree as $row) {
    $byFile[basename((string) $row['file'])][] = $row;
}

uasort($byFile, static fn (array $a, array $b): int => count($b) <=> count($a));

foreach ($byFile as $file => $hits) {
    echo $file.'  ('.count($hits)." candidates)\n";

    foreach ($hits as $row) {
        printf("    x%-3d line %-5d %s\n", $row['count'], $row['line'], var_export($row['needle'], true));
        echo '              in: '.implode(' | ', array_unique((array) $row['contexts']))."\n";
    }

    echo "\n";
}

/* ─────────────────────────────────────────────────────────────────────────────
 | GROUPED BY THE PAIR OF PLACES THE COPIES SIT.
 |
 | 119 sites is not 119 decisions. The same two elements account for dozens of
 | them — a product name in `span.kbb-card-nm` and again in the add-to-basket
 | link's `data-name`, over and over — so the adjudication is per PAIR, and the
 | repair for a pair is one shape applied everywhere it occurs.
 └────────────────────────────────────────────────────────────────────────── */
$pairs = [];

foreach ($disagree as $row) {
    $signature = array_unique((array) $row['contexts']);
    sort($signature);
    $pairs[implode(' | ', $signature)][] = $row;
}

uasort($pairs, static fn (array $a, array $b): int => count($b) <=> count($a));

echo "\n════════ candidates grouped by where the copies sit ════════\n\n";

foreach ($pairs as $signature => $hits) {
    $files = array_unique(array_map(static fn (array $r): string => basename((string) $r['file']), $hits));
    printf("%3d sites  %s\n", count($hits), $signature);
    echo '           files: '.implode(', ', array_slice($files, 0, 6)).(count($files) > 6 ? ' +'.(count($files) - 6) : '')."\n";
    echo '           e.g.   '.var_export($hits[0]['needle'], true).' at '.basename((string) $hits[0]['file']).':'.$hits[0]['line']."\n\n";
}
