<?php

declare(strict_types=1);

/*
 * Build KBB-Progress-Dashboard.html FROM KBB-Master-Plan.md.
 *
 * WHY THIS IS A GENERATOR AND NOT A FILE SOMEBODY EDITS.
 *
 * The dashboard was last written by hand at 2.60.107 and was still claiming
 * that state 113 packages later. A hand-kept progress page does not drift
 * gradually -- it is either updated in the same breath as the work or it is
 * wrong, and "or it is wrong" is what happens. Every number on this page is
 * now counted out of the plan, so the two cannot disagree: if a bar is wrong,
 * the plan is wrong, and the plan is the thing people actually maintain.
 *
 * The checkbox vocabulary, which is the plan's own and not invented here:
 *
 *   [x]  done
 *   [~]  part done -- counted as half, because counting it as done overstates
 *        and counting it as open hides work that is genuinely on the site
 *   [ ]  open
 *   [-]  struck: decided against, or answered by the owner as not wanted.
 *        NOT counted in the denominator at all -- a thing nobody wants is not
 *        outstanding work, and leaving it in makes a finished phase look
 *        permanently unfinished.
 *
 * Run:  php tools/progress-dashboard/build.php
 */

$root = dirname(__DIR__, 2);
$plan = file_get_contents($root . '/KBB-Master-Plan.md');
$version = trim((string) file_get_contents($root . '/VERSION'));

if ($plan === false) {
    fwrite(STDERR, "Cannot read KBB-Master-Plan.md\n");
    exit(1);
}

/** Sections, in the order the plan states them. */
$sections = [];
$current = null;

foreach (explode("\n", $plan) as $line) {
    if (str_starts_with($line, '## ')) {
        $title = trim(substr($line, 3));
        $current = $title;
        $sections[$current] ??= ['x' => 0, '~' => 0, ' ' => 0, '-' => 0];

        continue;
    }

    if ($current === null) {
        continue;
    }

    // Only a top-level item counts. An indented checkbox is a sub-point of the
    // line above it and counting it would weight one item several times.
    if (preg_match('/^- \[(.)\]/u', $line, $m) === 1) {
        $mark = $m[1];
        $mark = ($mark === '–' || $mark === '—') ? '-' : $mark;

        if (isset($sections[$current][$mark])) {
            $sections[$current][$mark]++;
        }
    }
}

/* Sections that are prose rather than work -- they carry no checkboxes, or
   their checkboxes are a register rather than a plan. Listed by name so a new
   phase is never silently dropped from the page. */
$notWork = [
    'Progress',
    'In flight right now',
    'The plugin, inventoried — *v2.39.0, verified 2.44.0*',
    'Approximate timeline',
    'Decisions on record',
    'Risk register',
    'Questions still unanswered',
];

/*
 * "In flight right now" -- what is being worked on, which this plan did not
 * record until the owner asked for it. Rendered from the SAME file as
 * everything else so it cannot become a second opinion; a lane that lands is
 * deleted there and written into its phase, so a stale entry here is a bug in
 * the plan rather than a disagreement with it.
 */
$inflight = [];
$waiting = [];

if (preg_match('/^## In flight right now\n(.*?)^## /ms', $plan, $m) === 1) {
    $bucket = 'inflight';

    foreach (explode("\n", $m[1]) as $line) {
        if (str_starts_with($line, '### ')) {
            $bucket = 'waiting';

            continue;
        }

        if (preg_match('/^- \*\*(.+?)\*\*\s*(.*)$/u', $line, $mm) === 1) {
            ${$bucket}[] = ['t' => $mm[1], 'd' => rtrim($mm[2])];
        } elseif (preg_match('/^  (\S.*)$/u', $line, $mm) === 1 && ${$bucket} !== []) {
            ${$bucket}[count(${$bucket}) - 1]['d'] .= ' ' . trim($mm[1]);
        }
    }
}

$rows = [];
$totDone = 0.0;
$totAll = 0;

foreach ($sections as $title => $c) {
    if (in_array($title, $notWork, true)) {
        continue;
    }

    $counted = $c['x'] + $c['~'] + $c[' '];

    if ($counted === 0) {
        continue;
    }

    $done = $c['x'] + ($c['~'] * 0.5);
    $pct = (int) round($done / $counted * 100);

    $totDone += $done;
    $totAll += $counted;

    // "Phase 8c — Telling the truth  *(2.60.126 → .185)*" -> name + note
    /* "Phase 3 — Module framework ← **in progress**" is a title with a marker
       welded on. Cut at the arrow FIRST -- stripping the arrow and the
       asterisks instead leaves "Module framework in progress", which reads as
       a phase nobody named. */
    $clean = preg_replace('/\s*\*\(.*?\)\*\s*$/u', '', $title);
    $clean = preg_split('/\s*←\s*/u', (string) $clean)[0];
    $clean = str_replace('**', '', $clean);
    $note = '';

    if (preg_match('/\*\((.*?)\)\*/u', $title, $m) === 1) {
        $note = $m[1];
    }

    $rows[] = [
        'name' => trim((string) $clean),
        'note' => $note,
        'done' => $c['x'],
        'part' => $c['~'],
        'open' => $c[' '],
        'struck' => $c['-'],
        'total' => $counted,
        'pct' => $pct,
    ];
}

$overall = $totAll > 0 ? (int) round($totDone / $totAll * 100) : 0;

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$bars = '';

foreach ($rows as $r) {
    $state = $r['pct'] === 100 ? 'done' : ($r['pct'] >= 60 ? 'most' : ($r['pct'] > 0 ? 'some' : 'none'));
    $struck = $r['struck'] > 0
        ? '<span class="chip struck">' . $r['struck'] . ' not wanted</span>'
        : '';
    $part = $r['part'] > 0 ? '<span class="chip part">' . $r['part'] . ' part done</span>' : '';
    $open = $r['open'] > 0 ? '<span class="chip open">' . $r['open'] . ' open</span>' : '';

    $bars .= <<<HTML
        <article class="row">
          <div class="rowhead">
            <h3>{$e($r['name'])}</h3>
            <span class="pct">{$r['pct']}%</span>
          </div>
          <div class="track"><div class="fill {$state}" style="width:{$r['pct']}%"></div></div>
          <div class="chips">
            <span class="chip done">{$r['done']} done</span>{$part}{$open}{$struck}
            <span class="chip of">of {$r['total']}</span>
          </div>
        </article>

    HTML;
}

$md = static function (string $t) use ($e): string {
    $t = $e($t);
    $t = preg_replace('/`(.+?)`/u', '<code>$1</code>', $t);

    return (string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', (string) $t);
};

$panel = static function (string $heading, array $items, string $cls) use ($e, $md): string {
    if ($items === []) {
        return '';
    }

    $li = '';

    foreach ($items as $it) {
        $li .= '<li><b>' . $e($it['t']) . '</b> ' . $md($it['d']) . '</li>';
    }

    return '<section class="panel ' . $cls . '"><h2>' . $e($heading) . '</h2><ul>' . $li . '</ul></section>';
};

$flightHtml = $panel('In flight right now', $inflight, 'flight')
    . $panel('Waiting on you, not on us', $waiting, 'waiting');

$generated = date('j F Y, H:i');
$phaseCount = count($rows);

$html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>KBB Build Progress</title>
<style>
  :root{
    --ink:#1b1417; --soft:#6d6168; --line:#e8dfe3; --bg:#fdfafb; --card:#fff;
    --done:#2e9e6b; --most:#5aa9e6; --some:#e0a93b; --none:#cbc2c6;
    --accent:#d8456b;
  }
  @media (prefers-color-scheme: dark){
    :root:not([data-theme="light"]){
      --ink:#f2ecee; --soft:#a89ba1; --line:#332a2e; --bg:#141013; --card:#1c171a;
      --none:#4a4045;
    }
  }
  :root[data-theme="dark"]{
    --ink:#f2ecee; --soft:#a89ba1; --line:#332a2e; --bg:#141013; --card:#1c171a;
    --none:#4a4045;
  }
  *{box-sizing:border-box}
  body{
    margin:0; background:var(--bg); color:var(--ink);
    font:15px/1.5 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
    padding-block:32px; padding-inline:16px;
  }
  .wrap{max-width:820px;margin:0 auto}
  header{margin-bottom:26px}
  h1{font-size:26px;margin:0 0 6px;letter-spacing:-.02em}
  .sub{color:var(--soft);font-size:14px;margin:0}
  .total{
    background:var(--card);border:1px solid var(--line);border-radius:12px;
    padding:20px;margin:22px 0 26px;
  }
  .totrow{display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin-bottom:12px}
  .totnum{font-size:38px;font-weight:700;letter-spacing:-.03em;font-variant-numeric:tabular-nums}
  .totlab{color:var(--soft);font-size:13px;text-align:right}
  .track{height:9px;background:var(--none);border-radius:99px;overflow:hidden}
  .fill{height:100%;border-radius:99px}
  .fill.done{background:var(--done)} .fill.most{background:var(--most)}
  .fill.some{background:var(--some)} .fill.none{background:transparent}
  .rows{display:flex;flex-direction:column;gap:14px}
  .row{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:14px 16px}
  .rowhead{display:flex;align-items:baseline;justify-content:space-between;gap:10px;margin-bottom:8px}
  h3{font-size:14.5px;margin:0;font-weight:600}
  .pct{font-size:13px;color:var(--soft);font-variant-numeric:tabular-nums;flex-shrink:0}
  .chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:9px}
  .chip{font-size:11.5px;padding:2px 8px;border-radius:99px;border:1px solid var(--line);color:var(--soft)}
  .chip.done{color:var(--done);border-color:color-mix(in srgb,var(--done) 35%,transparent)}
  .chip.part{color:var(--most);border-color:color-mix(in srgb,var(--most) 35%,transparent)}
  .chip.open{color:var(--some);border-color:color-mix(in srgb,var(--some) 35%,transparent)}
  .chip.struck{text-decoration:line-through}
  .chip.of{border-color:transparent}
  .panel{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:16px 18px;margin-bottom:16px}
  .panel h2{font-size:13px;margin:0 0 10px;letter-spacing:.06em;text-transform:uppercase;color:var(--soft)}
  .panel.flight{border-left:3px solid var(--most)}
  .panel.waiting{border-left:3px solid var(--accent)}
  .panel ul{margin:0;padding-left:18px;display:flex;flex-direction:column;gap:8px}
  .panel li{font-size:13.5px;color:var(--soft)}
  .panel li b{color:var(--ink);font-weight:600}
  footer{margin-top:30px;padding-top:18px;border-top:1px solid var(--line);color:var(--soft);font-size:12.5px}
  footer p{margin:0 0 7px}
  code{font-size:12px;background:var(--card);border:1px solid var(--line);padding:1px 5px;border-radius:4px}
</style>
</head>
<body>
<div class="wrap">

  <header>
    <h1>K-Beauty Bliss — build progress</h1>
    <p class="sub">Version {$e($version)} &middot; generated {$e($generated)}</p>
  </header>

  <section class="total">
    <div class="totrow">
      <span class="totnum">{$overall}%</span>
      <span class="totlab">across {$phaseCount} phases<br>counted from the plan, not typed in</span>
    </div>
    <div class="track"><div class="fill most" style="width:{$overall}%"></div></div>
  </section>

{$flightHtml}
  <div class="rows">
{$bars}  </div>

  <footer>
    <p>Every number here is counted out of <code>KBB-Master-Plan.md</code> by
       <code>tools/progress-dashboard/build.php</code>. Nothing on this page is
       typed by hand, so the page and the plan cannot disagree &mdash; the
       previous dashboard was written by hand and still claimed 2.60.107 one
       hundred and thirteen packages later.</p>
    <p>A part-done item counts as half. An item struck through &mdash; decided
       against, or answered as not wanted &mdash; is left out of the total
       entirely, because a thing nobody wants is not outstanding work.</p>
  </footer>

</div>
</body>
</html>
HTML;

file_put_contents($root . '/KBB-Progress-Dashboard.html', $html);

printf("Wrote KBB-Progress-Dashboard.html — %d phases, %d%% overall (%s items counted)\n", $phaseCount, $overall, number_format($totAll));
