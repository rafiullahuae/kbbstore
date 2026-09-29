# Lane PERF — the Lighthouse reports behind the numbers

Open these in a browser. Each is the run of its five whose Performance score
**is** the median quoted in `docs/PERF-PAGESPEED.md` §4 — mobile pair 4, desktop
before pair 5 and desktop after pair 1 — saved exactly as Lighthouse wrote it,
so the report you read and the number in the table are the same run:

| file | what it is |
|---|---|
| `before-mobile.html` | the commit this lane branched from, mobile profile |
| `after-mobile.html` | its tip, mobile profile |
| `before-desktop.html` | the commit this lane branched from, desktop profile |
| `after-desktop.html` | its tip, desktop profile |

    before-mobile    Performance 76    after-mobile    Performance 86
    before-desktop   Performance 88    after-desktop   Performance 99

One run is one run: the table's other rows are medians of five and are the
numbers to quote. These are here so the audits behind them can be read rather
than taken on trust.

**Read the run header before the score.** CPU throttling is set to the 1.2×
PageSpeed Insights' own report names, not Lighthouse's 4× default, because this
container runs six other agents and at 4× the Total Blocking Time measures the
load average rather than the shop. PSI recorded TBT 0 ms on both profiles;
`docs/PERF-PAGESPEED.md` §4 says what that does to the comparison and gives the
score recomputed with TBT held at the 1.0 the live shop already earns.

The fixture is `tools/perf-seed.php` — the owner's homepage reproduced in the
three things his report blamed, not his live content.
