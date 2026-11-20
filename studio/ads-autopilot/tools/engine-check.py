#!/usr/bin/env python3
"""LANE ENGINE end-to-end check: builds the engine, starts it with -mock (a fake Meta, fake Claude and fake shop feed
in the engine's own process -- never the real services) and drives:

  launch → connect → accounts → limits → creative → sync → /api/state speed → shadow cycle → auto cycle (launch:
  PAUSED creates, checked activation) → Meta safety net (spend_cap + KAA: rules, idempotent) → error injection
  (613 rate limit retried, 190 token refused) → history import → kill → a cycle after kill writes nothing

and prints the numbers. Exit 0 only when every check holds.

usage: python3 -I tools/engine-check.py            (from studio/ads-autopilot)
"""
import base64
import json
import os
import shutil
import subprocess
import sys
import tempfile
import time
import urllib.request
import urllib.error

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
PNG = base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC")
failures = []


def check(cond, what):
    print(("  ok   " if cond else "  FAIL ") + what)
    if not cond:
        failures.append(what)


def main():
    work = tempfile.mkdtemp(prefix="kaa-check-")
    exe = os.path.join(work, "kaa")
    # KAA_BRAIN=stub: this check tests the executor and safety net against the stub brain's fixed proposals (the real
    # brain end to end is tools/e2e-real-brain.py)
    env = dict(os.environ, CGO_ENABLED="0", KAA_SECRETS_FILE="1", KAA_BRAIN="stub")
    subprocess.run(["go", "build", "-o", exe, "."], cwd=os.path.join(ROOT, "engine"), env=env, check=True)
    data = os.path.join(work, "data")
    proc = subprocess.Popen([exe, "-dir", ROOT, "-data", data, "-mock", "-no-browser", "-no-scheduler", "-port", "0", "-idle", "10m"],
                            stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, env=env)
    base = None
    t0 = time.time()
    while time.time() - t0 < 20:
        line = proc.stdout.readline()
        if "running at" in line:
            base = line.split("running at ")[1].strip().rstrip("/").rsplit("/app", 1)[0]
            break
    if not base:
        print("engine did not start")
        proc.kill()
        sys.exit(1)
    try:
        run(base)
    finally:
        try:
            call(base, "POST", "/api/stop", {}, tok=TOKEN[0])
        except Exception:
            pass
        try:
            proc.wait(5)
        except Exception:
            proc.kill()
        shutil.rmtree(work, ignore_errors=True)
    print("\n%s: %d failure(s)" % ("PASS" if not failures else "FAIL", len(failures)))
    sys.exit(1 if failures else 0)


TOKEN = [""]


def call(base, method, path, body=None, tok=None, account=None):
    req = urllib.request.Request(base + path, method=method, data=None if body is None else json.dumps(body).encode())
    if tok:
        req.add_header("X-KAA", tok)
    if account:
        req.add_header("X-KAA-Account", account)
    req.add_header("Content-Type", "application/json")
    try:
        with urllib.request.urlopen(req, timeout=120) as r:
            return r.status, json.loads(r.read() or b"null")
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read() or b"null")


def run(base):
    s, info = call(base, "GET", "/api/info")
    check(s == 200 and info["apiLevel"] >= 1 and len(info["token"]) == 32, "info: token + apiLevel %s, version %s" % (info.get("apiLevel"), info.get("version")))
    tok = TOKEN[0] = info["token"]
    s, _ = call(base, "GET", "/api/state")
    check(s == 401, "state without the token is refused (%d)" % s)
    conns = None
    for _ in range(40):
        s, conns = call(base, "GET", "/api/connections", tok=tok)
        if conns and conns["meta"]["ok"] and conns["claude"]["ok"] and conns["shop"]["ok"]:
            break
        time.sleep(0.25)
    check(conns["meta"]["ok"] and conns["claude"]["ok"] and conns["shop"]["ok"],
          "auto-connect: meta (%d ad accounts, tier %s), claude (%s), shop (%d products)" % (conns["meta"]["ad_accounts"], conns["meta"].get("access_tier"), conns["claude"]["model"], conns["shop"]["products"]))
    check("EAAMOCK" not in json.dumps(conns), "no key in /api/connections (token shows as last4 %s)" % conns["meta"]["token"]["last4"])
    s, accs = call(base, "GET", "/api/accounts", tok=tok)
    act = accs["selected"]
    check(s == 200 and act.startswith("act_") and len(accs["businesses"]) == 1, "portfolio: %d business, %d ad account(s), selected %s" % (len(accs["businesses"]), len(accs["accounts"]), act))
    s, lim = call(base, "PUT", "/api/limits", {"daily_aed": 300, "monthly_aed": 6000, "target_cpa_aed": 60, "autopilot": "auto", "countries": ["AE", "SA"]}, tok=tok)
    check(s == 200 and lim["max_budget_step_pct"] == 20 and lim["cooldown_hours"] == 72, "limits saved: 300/day 6000/month, step 20%, cooldown 72 h")
    s, bad = call(base, "PUT", "/api/limits", {"daily_aed": -5}, tok=tok)
    check(s == 400, "a negative limit is refused (%s)" % bad.get("error"))
    s, cr = call(base, "POST", "/api/creatives", {"id": "cr-ar-1", "lang": "ar", "format": "4:5", "png": base64.b64encode(PNG).decode(), "headline": "بشرة مرطبة",
                                                 "primary_text": "روتين كوري خفيف", "cta": "SHOP_NOW", "link": "https://kbeautybliss.com/product/cosrx-low-ph/", "product_ids": ["3195"], "angle": "hydration", "source": "owner"}, tok=tok)
    check(s == 200 and cr["media"] == "png" and "png" not in cr, "creative stored (media kept in the data folder, not echoed)")
    s, _ = call(base, "POST", "/api/sync", {}, tok=tok)
    check(s == 200, "sync")
    times = []
    for _ in range(50):
        t = time.perf_counter()
        s, st = call(base, "GET", "/api/state", tok=tok)
        times.append((time.perf_counter() - t) * 1000)
    times.sort()
    check(times[len(times) // 2] < 20 and times[-1] < 60, "/api/state p50 %.2f ms, max %.2f ms (from memory)" % (times[len(times) // 2], times[-1]))
    check(st["kpis"]["30d"]["spend"] > 0, "KPIs 30d: spend %.2f AED, purchases %.0f, CPA %.2f, ROAS %.2f" % (st["kpis"]["30d"]["spend"], st["kpis"]["30d"]["purchases"], st["kpis"]["30d"]["cpa"], st["kpis"]["30d"]["roas"]))

    s, sh = call(base, "POST", "/api/cycle", {"mode": "shadow"}, tok=tok)
    s, w0 = call(base, "GET", "/api/test/meta-writes", tok=tok)
    creates0 = [w for w in w0 if w["path"].endswith(("/campaigns", "/adsets", "/ads"))]
    check(sh["counts"].get("shadow", 0) >= 1 and sh["counts"].get("done", 0) == 0 and not creates0, "shadow cycle: %s, Meta objects created: %d" % (sh["counts"], len(creates0)))
    s, cy = call(base, "POST", "/api/cycle", {"mode": "auto"}, tok=tok)
    s, w1 = call(base, "GET", "/api/test/meta-writes", tok=tok)
    creates = [w for w in w1 if w["path"].endswith(("/campaigns", "/adsets", "/ads")) and w["status"] == 200]
    acts = [w for w in w1 if w["form"].get("status") == "ACTIVE"]
    check(cy["counts"].get("done") == 4 and all(w["form"]["status"] == "PAUSED" and w["idem"] for w in creates) and len(acts) == 3,
          "auto cycle: %s; %d creates all PAUSED with idempotency keys, then %d checked activations; %d ms" % (cy["counts"], len(creates), len(acts), cy["ms"]))
    camp = [w for w in creates if w["path"].endswith("/campaigns")][0]
    check(camp["form"]["daily_budget"] == "24000", "AED 240 → %s fils on the wire" % camp["form"]["daily_budget"])
    caps = [w["form"]["spend_cap"] for w in w1 if "spend_cap" in w["form"]]
    rules = [w for w in w1 if w["path"].endswith("/adrules_library") and w["status"] == 200]
    check(caps and all(float(c) > 0 for c in caps), "spend_cap written %s (AED, never 0)" % caps)
    check(len(rules) == 4, "%d KAA: Automated Rules created on Meta" % len(rules))
    n = len(w1)
    s, rep = call(base, "POST", "/api/safety", {}, tok=tok)
    s, w2 = call(base, "GET", "/api/test/meta-writes", tok=tok)
    check(s == 200 and len(w2) == n, "safety net again: %d writes (idempotent); rules %s" % (len(w2) - n, [r["action"] for r in rep["rules"]]))

    call(base, "POST", "/api/test/meta-inject", {"method": "GET", "path": "/insights", "code": 613, "subcode": 1487742, "count": 2, "message": "(#613) Calls to this api have exceeded the rate limit."}, tok=tok)
    s, _ = call(base, "POST", "/api/sync", {}, tok=tok)
    check(s == 200, "rate limit 613 twice → retried with backoff, sync ok")
    call(base, "POST", "/api/test/meta-inject", {"method": "GET", "path": "/insights", "code": 80004, "count": 1, "message": "There have been too many calls from this ad-account."}, tok=tok)
    s, _ = call(base, "POST", "/api/sync", {}, tok=tok)
    check(s == 200, "BUC 80004 → retried, sync ok")
    call(base, "POST", "/api/test/meta-inject", {"method": "GET", "path": act, "code": 190, "subcode": 463, "count": 9, "message": "Error validating access token: Session has expired."}, tok=tok)
    s, e = call(base, "POST", "/api/sync", {}, tok=tok)
    check(s == 502 and "190" in e.get("error", ""), "token expired (190) → not retried, refused: %s" % e.get("error", "")[:70])
    for _ in range(9):  # use up the injected 190s
        call(base, "POST", "/api/sync", {}, tok=tok)

    s, bf = call(base, "POST", "/api/backfill", {}, tok=tok)
    t = time.time()
    while bf.get("state") != "done" and time.time() - t < 60:
        time.sleep(0.3)
        s, bf = call(base, "GET", "/api/backfill", tok=tok)
    check(bf.get("state") == "done" and bf["done"] == bf["total"], "history import: %s months×levels, %d rows, state %s" % (bf.get("total"), bf.get("rows", 0), bf.get("state")))
    s, ls = call(base, "GET", "/api/learnings", tok=tok)
    check(s == 200 and len(ls) > 0, "portfolio learnings: %d aggregates (%s)" % (len(ls), ", ".join(sorted({l["dimension"] for l in ls}))))

    s, k = call(base, "POST", "/api/kill", {}, tok=tok)
    paused = sum(len(x["paused"]) for x in k["accounts"])
    check(paused == 1, "kill (all accounts): %d campaign(s) of the app's paused" % paused)
    s, w3 = call(base, "GET", "/api/test/meta-writes", tok=tok)
    s, cy2 = call(base, "POST", "/api/cycle", {"mode": "auto"}, tok=tok)
    s, w4 = call(base, "GET", "/api/test/meta-writes", tok=tok)
    new = [w for w in w4[len(w3):] if not w["path"].endswith(("adrules_library",)) and "spend_cap" not in w["form"]]
    check(cy2["counts"].get("done", 0) == 0 and not new, "after kill: cycle %s, %d writes" % (cy2["counts"], len(new)))
    s, st = call(base, "GET", "/api/state", tok=tok)
    check(st["limits"]["autopilot"] == "off", "autopilot is off after kill")
    s, log = call(base, "GET", "/api/log?limit=2000", tok=tok)
    kinds = {}
    for e in log["entries"]:
        kinds[e["kind"]] = kinds.get(e["kind"], 0) + 1
    check(not any("EAAMOCK" in json.dumps(e) for e in log["entries"]), "audit log: %d entries %s, no key in it" % (len(log["entries"]), kinds))


if __name__ == "__main__":
    main()
