#!/usr/bin/env python3
# Lane CPU -- /usr/bin/time stand-in: run a command, append wall / user / sys CPU
# (including every child it waited for) and max RSS to <out>.
#   python3 tools/cpu-time.py <out> cmd args...
import resource, subprocess, sys, time
out, cmd = sys.argv[1], sys.argv[2:]
r0 = resource.getrusage(resource.RUSAGE_CHILDREN)
t0 = time.monotonic()
with open(out, 'a') as fh:
    subprocess.run(cmd, stdout=fh, stderr=subprocess.STDOUT)
wall = time.monotonic() - t0
r1 = resource.getrusage(resource.RUSAGE_CHILDREN)
with open(out, 'a') as fh:
    fh.write('TIME %.3f s wall  %.3f s user  %.3f s sys  %d KB maxrss\n' % (
        wall, r1.ru_utime - r0.ru_utime, r1.ru_stime - r0.ru_stime, r1.ru_maxrss))
