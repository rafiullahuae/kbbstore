"""Lane FW: peak requests per 10 s / 60 s from tools/fw451-router.php's log.
python3 tools/fw451-peak.py <log>"""
import sys
rows = [l.rstrip('\n').split('\t') for l in open(sys.argv[1]) if l.count('\t') == 3]
ts = [(float(t), 'prefetch' in p.lower()) for t, p, m, u in rows]
def peak(window, which):
    xs = sorted(t for t, pf in ts if which(pf))
    best, j = 0, 0
    for i, t in enumerate(xs):
        while xs[j] <= t - window:
            j += 1
        best = max(best, i - j + 1)
    return best
print({'requests': len(ts), 'prefetch': sum(1 for _, pf in ts if pf),
       'page_10s': peak(10, lambda pf: not pf), 'page_60s': peak(60, lambda pf: not pf),
       'prefetch_10s': peak(10, lambda pf: pf), 'all_10s': peak(10, lambda pf: True), 'all_60s': peak(60, lambda pf: True)})
