<?php

declare(strict_types=1);

/**
 * Propose the mechanical repairs, so a human only has to adjudicate. (Lane PLC)
 *
 *     ./tools/plc-needle-scan.sh
 *     php tools/plc-needle-propose.php            # print the proposals
 *     php tools/plc-needle-propose.php --apply    # write them, then run the tests
 *
 * ── WHICH REPAIRS ARE DETERMINISTIC ────────────────────────────────────────
 *
 * A hole is a needle whose copies sit in different elements: blanking the one
 * the test is about leaves the other standing and the assertion green. Most of
 * them have the same shape — ONE copy in something a shopper reads, and one in
 * a WRAPPER that repeats it for a machine:
 *
 *     @a[data-name]        the add-to-basket link's own copy of the name
 *     @button[aria-label]  the control's accessible name
 *     @meta[content]       the SEO surface
 *     script / «…"name":"  JSON-LD saying it again for a crawler
 *     title                the tab's copy of the heading
 *
 * When exactly one copy is in a wrapper and exactly one is in a plain element,
 * the repair is forced: prefix the needle with that element's opening tag. No
 * judgement is involved, which is what makes it safe to propose in bulk.
 *
 * ── AND THE PROPOSAL IS A PREFIX, NOT A WRAP ───────────────────────────────
 *
 * `<span class="kbb-card-nm">NAME` rather than `<span …>NAME</span>`, because
 * the closing tag is only correct when the needle is the element's WHOLE text.
 * A prefix holds whenever the needle merely STARTS it, which is the common case
 * and the one a wrap would break — and it pins the element just as well.
 *
 * Nothing here is trusted: --apply writes the proposals and the caller runs the
 * affected tests. A proposal that was wrong about where the text begins fails
 * loudly, immediately, in the file it changed.
 */
$apply = in_array('--apply', array_slice($argv, 1), true);

function kbbNewestRun(): string
{
    $base = __DIR__.'/../storage/plc-logs/needles';
    $runs = array_filter((array) glob($base.'/*'), 'is_dir');

    if ($runs === []) {
        return $base;
    }

    usort($runs, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

    return $runs[0];
}

/** Does this fingerprint name a wrapper — something that repeats text for a machine? */
function kbbIsWrapper(string $context): bool
{
    return str_starts_with($context, '@')          // an attribute value
        || str_starts_with($context, '«')          // raw text: a script's string table, JSON-LD
        || in_array($context, ['script', 'title', 'meta', 'style'], true)
        || str_starts_with($context, 'script')
        || str_starts_with($context, 'title');
}

/** `span.kbb-card-nm` -> `<span class="kbb-card-nm">`, or '' when it cannot be built. */
function kbbOpeningTag(string $context): string
{
    if (! preg_match('/^([a-z][a-z0-9-]*)((?:\.[A-Za-z0-9_-]+)*)$/', $context, $m)) {
        return '';
    }

    $classes = trim(str_replace('.', ' ', $m[2]));

    return $classes === '' ? '<'.$m[1].'>' : '<'.$m[1].' class="'.$classes.'">';
}

$rows = [];

foreach ((array) glob(kbbNewestRun().'/*.jsonl') as $file) {
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $row = json_decode($line, true);

        if (is_array($row) && isset($row['needle'], $row['count'])) {
            $rows[] = $row;
        }
    }
}

$sites = [];

foreach ($rows as $row) {
    $needle = (string) $row['needle'];

    if ((int) $row['count'] !== 2) {
        continue;                                   // a forced repair needs exactly two copies
    }

    if (str_contains($needle, '<') || str_contains($needle, '="')) {
        continue;                                   // already names markup
    }

    if (! str_contains($needle, ' ') || preg_match('/[a-z]{3}/', $needle) !== 1) {
        continue;                                   // not a sentence a shopper reads
    }

    /*
     * ▲ AND IT MUST NOT BE SOURCE CODE. Eight of the first twenty-five
     * proposals were lines of JavaScript asserted against a page — `var current
     * = tabs.filter(function (t) { … })` — where BOTH copies are inside script
     * tags and the "plain element" fingerprint is whatever tag happened to open
     * last before the script. Prefixing one of those with `<circle>` or
     * `<button class="chp-tab">` is nonsense, and a proposal a human has to
     * throw away is worse than no proposal: it is the tool asking to be trusted
     * where it has nothing to say.
     *
     * A needle carrying brackets, arrows or an identity test is code. Sentences
     * a shopper reads do not contain them.
     */
    if (preg_match('/[(){};]|===|=>|->|\|\|/', $needle) === 1) {
        continue;
    }

    $contexts = array_values(array_unique(array_filter((array) ($row['contexts'] ?? []))));

    if (count($contexts) !== 2) {
        continue;
    }

    $wrappers = array_values(array_filter($contexts, 'kbbIsWrapper'));
    $plain = array_values(array_filter($contexts, static fn (string $c): bool => ! kbbIsWrapper($c)));

    if (count($wrappers) !== 1 || count($plain) !== 1) {
        continue;                                   // not the forced shape
    }

    $tag = kbbOpeningTag($plain[0]);

    if ($tag === '') {
        continue;
    }

    $sites[$row['file'].':'.$row['line'].'|'.$needle] = [
        'file' => (string) $row['file'],
        'line' => (int) $row['line'],
        'needle' => $needle,
        'tag' => $tag,
        'wrapper' => $wrappers[0],
        'kind' => $row['kind'] ?? 'toContain',
    ];
}

/*
 * ── ADJUDICATED BY HAND AND REFUSED, WITH THE REASON ───────────────────────
 *
 * The tool proposes; a person decides. These three were proposed and are wrong,
 * and they are recorded here so a later run does not offer them again and so
 * the next person can disagree with the argument rather than re-derive it.
 */
$refused = [
    'AccountAreaTest.php:197' =>
        'the visible copy sits after a bare <br>, so the proposal names no element at all — '
        .'`<br>United Arab Emirates` pins document order, not a component, and would break the '
        .'day the address block gains a wrapper. The address lines need a container before this '
        .'assertion can name one.',
    'GfImportRefinementTest.php:1245' =>
        'the visible copy is built by JAVASCRIPT in admin/app.blade.php — `+\'<b style="font-'
        .'size:14px">What has been imported</b>\'` — so on the server-rendered page the only '
        .'copies are the <title> and that script\'s own source. There is no server-rendered '
        .'element to name, and the file that builds it is the integrator\'s.',
    'QuizScriptStringsAreKeyedTest.php:201' =>
        'the needle is a QUOTED JAVASCRIPT STRING LITERAL and both copies are inside script '
        .'tags — this case is asserting the quiz script\'s own string table, which is a source '
        .'assertion and not a claim about anything a shopper sees. <circle> and <path> are '
        .'whatever SVG happened to open last; there is no element to name.',
];

/* Lane SEC and Lane BG are working these files this round. */
$owned = [
    'AdminPathNeverLeaksTest.php', 'DownloadNavigationGateTest.php', 'ServerBuiltAdminUrlsTest.php',
    'OnePageBackgroundTest.php', 'StandaloneDocumentHeadTest.php',
];

$proposals = [];
$skipped = [];

$refusedCount = 0;

foreach ($sites as $site) {
    if (in_array(basename($site['file']), $owned, true)) {
        $skipped[] = $site;

        continue;
    }

    if (array_key_exists(basename($site['file']).':'.$site['line'], $refused)) {
        $refusedCount++;

        continue;
    }

    $proposals[] = $site;
}

usort($proposals, static fn (array $a, array $b): int => [$a['file'], $a['line']] <=> [$b['file'], $b['line']]);

echo 'forced-shape sites                     : '.count($sites)."\n";
echo 'in files Lane SEC / Lane BG own (left)  : '.count($skipped)."\n";
echo 'refused by hand, with the reason above : '.$refusedCount."\n";
echo 'proposals                              : '.count($proposals)."\n\n";

$applied = 0;
$unmatched = [];

foreach ($proposals as $site) {
    $quoted = "'".str_replace("'", "\\'", $site['needle'])."'";
    $repaired = "'".str_replace("'", "\\'", $site['tag'].$site['needle'])."'";

    printf("%s:%d  %s\n", basename($site['file']), $site['line'], $site['kind']);
    printf("    %s\n -> %s        (other copy in %s)\n\n", $quoted, $repaired, $site['wrapper']);

    if (! $apply) {
        continue;
    }

    $source = (string) file_get_contents($site['file']);
    $done = false;

    /*
     * ▲ assertSee ESCAPES ITS NEEDLE, SO A MARKUP REPAIR NEEDS `escape: false`.
     *
     * Found by applying the first batch: `assertSee('<span class="brw-name">T
     * Nologo')` looks for `&lt;span class=&quot;brw-name&quot;&gt;…` and fails
     * on a page that plainly contains the markup. The repair shape is therefore
     * NOT the same for the two forms, which is worth saying out loud — the
     * round-4 static guard reasoned that escaping "does not help here" and was
     * right only because the five strings it listed contain nothing that
     * escapes. The moment the needle carries a tag, it does.
     */
    /*
     * ▲ THE WHOLE CALL, CLOSING PAREN AND ALL, AND THE ESCAPE ARGUMENT
     * HANDLED SEPARATELY.
     *
     * The first version appended `, escape: false` to whatever it found and
     * stripped one trailing paren, which produced
     * `assertSee('…', escape: false, false)` — a PHP FATAL, "cannot use
     * positional argument after named argument", on a site that already passed
     * its own escape flag. A proposer that can leave a file unparseable is
     * worse than one that proposes nothing, so each form is now matched whole
     * and only its needle is rewritten.
     */
    $forms = [
        // toContain / toMatch: the needle is the only thing that changes.
        'toContain('.$quoted.')' => 'toContain('.$repaired.')',
        'toMatch('.$quoted.')' => 'toMatch('.$repaired.')',
        // assertSee already told NOT to escape: leave the flag exactly as it is.
        'assertSee('.$quoted.', escape: false)' => 'assertSee('.$repaired.', escape: false)',
        'assertSee('.$quoted.', false)' => 'assertSee('.$repaired.', false)',
        // assertSee with no flag ESCAPES, so a markup needle has to turn it off.
        'assertSee('.$quoted.')' => 'assertSee('.$repaired.', escape: false)',
    ];

    foreach ($forms as $call => $with) {
        if (substr_count($source, $call) !== 1) {
            continue;
        }

        $source = str_replace($call, $with, $source);
        $done = true;

        break;
    }

    if ($done) {
        file_put_contents($site['file'], $source);
        $applied++;
    } else {
        $unmatched[] = basename($site['file']).':'.$site['line'].' '.$quoted;
    }
}

if ($apply) {
    echo "applied   : {$applied}\n";
    echo 'unmatched : '.count($unmatched)."\n";

    foreach ($unmatched as $one) {
        echo '    '.$one."\n";
    }

    echo "\nNow run the affected test files. A proposal that was wrong about where the\n";
    echo "text begins fails in the file it changed; revert that one and do it by hand.\n";
}
