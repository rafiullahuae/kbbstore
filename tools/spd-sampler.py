#!/usr/bin/env python3
"""Lane SP: send SIGUSR1 every ~1 ms to the PID in LABEL's prof.pid (tools/spd-prof.php sample)."""
import os, signal, sys, time
f = sys.argv[1]
while not os.path.exists(f):
    time.sleep(0.01)
time.sleep(0.05)
pid = int(open(f).read())
while os.path.exists(f):
    try:
        os.kill(pid, signal.SIGUSR1)
    except ProcessLookupError:
        break
    time.sleep(0.001)
