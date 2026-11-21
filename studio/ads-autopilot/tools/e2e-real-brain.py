#!/usr/bin/env python3
"""LANE ENGINE end-to-end check with the REAL brain: the engine with engine/brain (not the stub), the fake Meta
(several ad accounts) and the fake Claude answering with scripted tool calls (metamock/claude.go) -- never the real
services. It drives the owner's whole first week:

  connect → accounts (3) → history import → research (web search + notes + trending) → plan preview (planner +
  copy) → video analysis → Practice: what the starter set WOULD launch (no Meta writes; one proposal "Not now") →
  Auto: the starter launches at once (PAUSED creates with idempotency keys, a lost answer replayed without a
  duplicate, checked PAUSED→ACTIVE, AED→fils) → 8 days later on seeded insights: the loser is paused and the winner
  scaled +20% → the account switch (each account its own data and limits) → kill → nothing after kill

and prints the numbers. Exit 0 only when every check holds.

usage: python3 -I tools/e2e-real-brain.py            (from studio/ads-autopilot)
"""
import base64
import json
import os
import shutil
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
JPEG = base64.b64decode("/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=")
failures = []
TOKEN = [""]
ACT = "act_1000000001"


def check(cond, what):
    print(("  ok   " if cond else "  FAIL ") + what)
    if not cond:
        failures.append(what)


def call(base, method, path, body=None, account=None, raw=None, headers=None):
    data = raw if raw is not None else (None if body is None else json.dumps(body).encode())
    req = urllib.request.Request(base + path, method=method, data=data)
    req.add_header("X-KAA", TOKEN[0])
    if account:
        req.add_header("X-KAA-Account", account)
    for k, v in (headers or {}).items():
        req.add_header(k, v)
    if raw is None:
        req.add_header("Content-Type", "application/json")
    try:
        with urllib.request.urlopen(req, timeout=300) as r:
            return r.status, json.loads(r.read() or b"null")
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read() or b"null")


def writes(base):
    return call(base, "GET", "/api/test/meta-writes")[1]


def main():
    work = tempfile.mkdtemp(prefix="kaa-e2e-")
    exe = os.path.join(work, "kaa")
    env = dict(os.environ, CGO_ENABLED="0", KAA_SECRETS_FILE="1")
    env.pop("KAA_BRAIN", None)  # the REAL brain
    subprocess.run(["go", "build", "-o", exe, "."], cwd=os.path.join(ROOT, "engine"), env=env, check=True)
    proc = subprocess.Popen([exe, "-dir", ROOT, "-data", os.path.join(work, "data"), "-mock", "-no-browser", "-no-scheduler", "-port", "0", "-idle", "10m"],
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
            call(base, "POST", "/api/stop", {})
        except Exception:
            pass
        try:
            proc.wait(5)
        except Exception:
            proc.kill()
        if failures or sys.exc_info()[0]:
            print("--- engine output (tail) ---\n" + (proc.stdout.read() or "")[-4000:])
        shutil.rmtree(work, ignore_errors=True)
    print("\n%s: %d failure(s)" % ("PASS" if not failures else "FAIL", len(failures)))
    sys.exit(1 if failures else 0)


def run(base):
    s, info = call(base, "GET", "/api/info")
    TOKEN[0] = info["token"]
    for _ in range(40):
        s, conns = call(base, "GET", "/api/connections")
        if conns and conns["meta"]["ok"] and conns["claude"]["ok"] and conns["shop"]["ok"]:
            break
        time.sleep(0.25)
    check(conns["meta"]["ok"] and conns["claude"]["ok"] and conns["shop"]["ok"], "connect: meta %d ad accounts, claude %s, shop %d products" % (conns["meta"]["ad_accounts"], conns["claude"]["model"], conns["shop"]["products"]))
    s, accs = call(base, "GET", "/api/accounts")
    check(len(accs["accounts"]) == 3 and accs["selected"] == ACT, "portfolio: %d ad accounts %s, selected %s" % (len(accs["accounts"]), [a["name"] for a in accs["accounts"]], accs["selected"]))

    # history import
    s, bf = call(base, "POST", "/api/backfill", {})
    t = time.time()
    while bf.get("state") != "done" and time.time() - t < 90:
        time.sleep(0.3)
        s, bf = call(base, "GET", "/api/backfill")
    check(bf.get("state") == "done", "history import: %s parts, %d rows" % (bf.get("total"), bf.get("rows", 0)))

    # research: web search → notes, and trending products
    t = time.perf_counter()
    s, rs = call(base, "POST", "/api/brain/research", {})
    ms = (time.perf_counter() - t) * 1000
    s, tr = call(base, "GET", "/api/brain/x/trending")
    check(s == 200 and len(rs.get("trends", [])) + len(rs.get("arabic_insights", [])) + len(rs.get("competitors", [])) >= 3 and len(rs.get("sources", [])) >= 2 and len(tr.get("selling", [])) >= 1,
          "research: %d trends, %d Arabic insights, %d competitors, %d sources; trending in the shop %s (%.0f ms)" % (len(rs.get("trends", [])), len(rs.get("arabic_insights", [])), len(rs.get("competitors", [])), len(rs.get("sources", [])), tr.get("selling"), ms))

    # limits: Practice, with the wording rule on /api/limits
    s, lim = call(base, "PUT", "/api/limits", {"daily_aed": 300, "monthly_aed": 9000, "target_cpa_aed": 60, "autopilot": "shadow", "countries": ["AE", "SA"], "wording": "strict"})
    check(s == 200 and lim.get("wording") == "strict", "limits: 300/day, 9000/month, Practice, wording %s" % lim.get("wording"))
    # self-tuning "ask me": it may propose a threshold change but never applies one by itself (checked at the end)
    s, pbk = call(base, "PUT", "/api/playbook", {"self_tune": "ask"})
    check(s == 200 and pbk["playbook"]["self_tune"] == "ask" and pbk["playbook"]["coupon_code"] == "GLOW" and pbk["playbook"]["free_gift"],
          "playbook: self-tuning ask-me; offers free gift + code %s; %d of %d products allowed" % (pbk["playbook"]["coupon_code"], pbk["allowed"], pbk["products"]))
    s, bad = call(base, "PUT", "/api/limits", {"wording": "loose"})
    check(s == 400, "an unknown wording rule is refused (%s)" % (bad or {}).get("error"))

    # creation mode "with me in Claude" (the default): the planner's copy is NOT written through the API; it waits
    # for the owner as a creation request
    s, pl = call(base, "POST", "/api/brain/plan", {})
    s, cyc = call(base, "GET", "/api/brain/x/cycle")
    check(len(cyc.get("creation_needed") or []) >= 1 and not (cyc.get("copy_sets") or []),
          "with-me mode: %d creation request(s), %d copy sets written through the API" % (len(cyc.get("creation_needed") or []), len(cyc.get("copy_sets") or [])))
    # plan preview in "Automatic (API)" mode (planner + copy through the scripted Claude)
    call(base, "PUT", "/api/brain/x/settings", {"creation_mode": "api"})
    s, pl = call(base, "POST", "/api/brain/plan", {})
    if s != 200:
        print("       plan preview answered %d: %s" % (s, str(pl)[:300]))
    s, cyc = call(base, "GET", "/api/brain/x/cycle")
    check(cyc.get("model") not in ("", "rules-only") and len(cyc.get("copy_sets") or []) >= 1 and len(cyc.get("designs") or []) >= 1,
          "plan preview: model %s, %d copy sets, %d designs to render, %d proposals, dropped %s" % (cyc.get("model"), len(cyc.get("copy_sets") or []), len(cyc.get("designs") or []), len(pl.get("Proposals") or pl.get("proposals") or []), cyc.get("dropped")))

    # video analysis (vision through the scripted Claude)
    mp4 = b"\x00\x00\x00\x18ftypmp42" + b"\x00" * 2048
    s, v = call(base, "POST", "/api/brain/x/videos", raw=mp4, headers={"X-Filename": "morning-routine.mp4", "Content-Type": "application/octet-stream"})
    s, va = call(base, "POST", "/api/brain/x/videos/%s/analyse" % v["id"], {"duration_s": 12, "width": 1080, "height": 1920, "frames": [{"t": 0.5, "jpeg": base64.b64encode(JPEG).decode()}]})
    a = va.get("analysis") or {}
    check(s == 200 and a.get("aspect") == "9:16" and not str(a.get("model", "")).startswith("local-only"), "video analysis: %s, score %s, model %s, products %s" % (a.get("aspect"), a.get("score"), a.get("model"), [p.get("product_id") for p in a.get("products") or []]))

    # Practice: what the first launch WOULD do
    n0 = len(writes(base))
    t = time.perf_counter()
    s, sh = call(base, "POST", "/api/cycle", {"mode": "shadow"})
    ms = (time.perf_counter() - t) * 1000
    w = writes(base)[n0:]
    creates = [x for x in w if x["path"].endswith(("/campaigns", "/adsets", "/ads", "/adcreatives"))]
    s, pend = call(base, "GET", "/api/proposals")
    starter = [p for p in pend["proposals"] if p["id"].startswith("starter-")]
    kinds = {}
    for p in starter:
        kinds[p["kind"]] = kinds.get(p["kind"], 0) + 1
    s, st = call(base, "GET", "/api/state")
    check(sh.get("starter") and not creates and all(p["status"] == "shadow" for p in starter) and st["starter"]["state"] == "practice",
          "Practice: the starter set it WOULD launch: %d proposals %s, all shadow; Meta creates %d; %s (%.0f ms)" % (len(starter), kinds, len(creates), sh.get("starter", {}).get("why"), ms))
    ar = [p for p in starter if p["kind"] == "create_ad" and " AR " in " " + p["params"]["name"] + " "]
    en = [p for p in starter if p["kind"] == "create_ad" and " EN " in " " + p["params"]["name"] + " "]
    check(len(ar) >= 3 and len(en) == 1, "Arabic first: %d Arabic ads (+catalog), %d English" % (len(ar), len(en)))
    other = [p for p in pend["proposals"] if not p["id"].startswith("starter-") and p["status"] == "shadow"]
    target = (other or starter)[-1]
    s, rj = call(base, "POST", "/api/proposals/%s/reject" % target["id"], {})
    s, pend2 = call(base, "GET", "/api/proposals")
    check(s == 200 and [p for p in pend2["proposals"] if p["id"] == target["id"]][0]["status"] == "rejected", "Not now on %s %s → rejected, nothing written" % (target["kind"], target["id"]))

    # Auto: the starter launches now (not on Monday), one lost answer replayed without a duplicate
    call(base, "PUT", "/api/limits", {"autopilot": "auto"})
    call(base, "POST", "/api/test/meta-inject", {"method": "POST", "path": "/campaigns", "code": 1, "status": 500, "is_transient": True, "after_write": True, "message": "An unknown error occurred"})
    n0 = len(writes(base))
    t = time.perf_counter()
    s, cy = call(base, "POST", "/api/cycle", {"mode": "auto"})
    ms = (time.perf_counter() - t) * 1000
    w = writes(base)[n0:]
    creates = [x for x in w if x["path"].endswith(("/campaigns", "/adsets", "/ads")) and x["status"] == 200]
    camps = [x for x in w if x["path"].endswith("/campaigns")]
    acts = [x for x in w if x["form"].get("status") == "ACTIVE"]
    s, st = call(base, "GET", "/api/state")
    app_camps = [c for c in st["campaigns"] if c["managed"]]
    sales = [c for c in app_camps if "Advantage+ sales" in c["name"]]
    check(st["starter"]["state"] == "launched" and len(app_camps) == 2 and all(c["status"] == "ACTIVE" for c in app_camps) and set(cy["counts"]) == {"done"},
          "Auto: starter launched in the first cycle: %s; %d app campaigns ACTIVE (%s) in %.0f ms" % (cy["counts"], len(app_camps), ", ".join(c["name"] for c in app_camps), ms))
    check(creates and all(x["form"]["status"] == "PAUSED" and x["idem"] for x in creates) and len(acts) >= 3,
          "%d creates, all PAUSED with idempotency keys; %d checked activations (campaign → ad set → ad)" % (len(creates), len(acts)))
    check(len(camps) == 3 and len(app_camps) == 2 and camps[0]["idem"] == camps[1]["idem"], "lost answer on the first campaign create: %d POSTs, replayed with the same key → %d campaigns, no duplicate" % (len(camps), len(app_camps)))
    fils = [x["form"]["daily_budget"] for x in camps if x["status"] == 200]
    check(sales and fils and str(int(round(sales[0]["daily_budget"] * 100))) in fils, "budgets on the wire: %s fils (sales AED %.2f/day)" % (fils, sales[0]["daily_budget"] if sales else 0))
    call(base, "PUT", "/api/limits", {"max_active_ads": 10})  # room for the funnel's next pieces
    s, cy2 = call(base, "POST", "/api/cycle", {"mode": "auto"})
    w2 = writes(base)[n0 + len(w):]
    again = [x for x in w2 if x["path"].endswith("/campaigns") and x["status"] == 200]
    grown = [x["form"].get("name", "") for x in w2 if x["path"].endswith(("/adsets", "/customaudiences")) and x["status"] == 200]
    check(not cy2.get("starter") and not again, "the next cycle launches no second funnel (%d campaign creates; starter %s); the funnel grows by its jobs: %s" % (len(again), st["starter"]["state"], grown))
    s, dup = call(base, "POST", "/api/proposals/%s/approve" % [p for p in starter if p["kind"] == "create_campaign"][0]["id"], {})
    check(s in (404, 409), "approving an already-launched proposal is refused (%d %s)" % (s, (dup or {}).get("error", "")[:60]))
    s, lib = call(base, "GET", "/api/creatives")
    st_ids = {p["target"] for p in starter if p["kind"] == "upload_creative" and p["params"].get("format") == "image"}
    stc = [c for c in lib if c.get("starter") and c["id"] in st_ids]
    n_ads = len(st_ids)
    check(len(stc) == n_ads >= 3 and all(c.get("meta_creative_id") for c in stc), "starter creatives in the library: %d, each uploaded (%s)" % (len(stc), ", ".join("%s %s" % (c["lang"], c["headline"][:24]) for c in stc)))

    # the professional funnel for this seeded account (GET /api/funnel), as Meta received it
    s, fn = call(base, "GET", "/api/funnel")
    pieces = {p["key"]: p for p in fn["pieces"]}
    check(all(pieces[k]["status"] == "live" for k in ("prospecting", "existing", "retargeting", "lookalike")),
          "funnel (%s tier): %s" % (fn["tier"], ", ".join("%s %s (%d objects, %d settings)" % (k, p["status"], len(p["objects"]), len(p["settings"])) for k, p in pieces.items())))
    allw = writes(base)
    sets = {x["form"]["name"]: x["form"] for x in allw if x["path"].endswith("/adsets") and x["status"] == 200}
    newc = [f for n, f in sets.items() if "new customers" in n]
    exist = [f for n, f in sets.items() if "existing customers" in n]
    lal = [f for n, f in sets.items() if "lookalike" in n]
    cat = [f for n, f in sets.items() if "Retargeting" in n]
    auds = [x["form"] for x in allw if x["path"].endswith("/customaudiences") and x["status"] == 200]
    check(newc and '"excluded_custom_audiences"' in newc[0]["targeting"] and '"CLICK_THROUGH"' in newc[0]["attribution_spec"]
          and exist and exist[0].get("daily_spend_cap") and '"targeting_relaxation_types"' in exist[0]["targeting"]
          and cat and '"product_audience_specs"' in cat[0]["targeting"] and lal and '"custom_audiences"' in lal[0]["targeting"] and '"advantage_audience":1' in lal[0]["targeting"]
          and any('"exclusions"' in a.get("rule", "") for a in auds) and sum(1 for a in auds if a.get("subtype") == "LOOKALIKE") == 2,
          "on the wire: new customers exclude buyers + 7d-click/1d-view; existing customers capped at %s fils (strict audience); catalog retargeting product_audience_specs; 2 lookalikes as suggestions; past-buyers audience with an exclusion rule"
          % (exist[0].get("daily_spend_cap") if exist else None))
    # 3 simulated days: no duplicate funnel, predictions recorded, occasions still waiting
    n_c0 = len([x for x in writes(base) if x["path"].endswith("/campaigns") and x["status"] == 200])
    for d in range(3):
        call(base, "POST", "/api/test/clock", {"advance_hours": 24})
        s, cyd = call(base, "POST", "/api/cycle", {"mode": "auto"})
        if s != 200:
            print("       day %d cycle: %d %s" % (d + 1, s, str(cyd)[:200]))
    n_c1 = len([x for x in writes(base) if x["path"].endswith("/campaigns") and x["status"] == 200])
    s, lj = call(base, "GET", "/api/brain/x/learning")
    s, occ = call(base, "GET", "/api/occasions")
    waiting = [j for j in occ["packages"] if j["status"] == "planned"]
    s, st3 = call(base, "GET", "/api/state")
    live_occ = [a["name"] for c in st3["campaigns"] for s_ in c["adsets"] for a in s_["ads"] if "Occasion" in a["name"]]
    check(n_c1 == n_c0 and (lj.get("open", 0) + lj.get("scored", 0)) >= 1 and waiting and not live_occ,
          "3 days later: %d new campaigns; learning ledger %d open / %d scored predictions; %d occasion package(s) waiting, %d live without approval"
          % (n_c1 - n_c0, lj.get("open", 0), lj.get("scored", 0), len(waiting), len(live_occ)))

    # 8 days later: seeded insights make one ad a loser and the sales campaign a winner (picked from the account as
    # it is now: the 3 days above already stopped non-buyers on the rules)
    s, st = call(base, "GET", "/api/state")
    sales = [c for c in st["campaigns"] if c["managed"] and "Advantage+ sales" in c["name"]]
    loser = None
    for c in sales:
        for a_ in c["adsets"]:
            for ad in a_["ads"]:
                if ad["status"] == "ACTIVE" and loser is None and "AR" in ad["name"]:
                    loser = ad
    call(base, "POST", "/api/test/meta-tag", {"id": loser["id"], "tag": "LOSER"})
    call(base, "POST", "/api/test/meta-tag", {"id": sales[0]["id"], "tag": "WINNER"})
    s, clk = call(base, "POST", "/api/test/clock", {"advance_hours": 192})
    n1 = len(writes(base))
    s, cy3 = call(base, "POST", "/api/cycle", {"mode": "auto"})
    w3 = writes(base)[n1:]
    res = cy3.get("results") or []
    paused = [r for r in res if r["kind"] == "pause" and r["target"] == loser["id"] and r["status"] == "done"]
    scaled = [r for r in res if r["kind"] == "set_budget" and r["target"] == sales[0]["id"] and r["status"] == "done"]
    newb = [x["form"].get("daily_budget") for x in w3 if x["path"].endswith(sales[0]["id"]) and "daily_budget" in x["form"]]
    check(paused, "day 8 (%s): loser ad %s paused (%s)" % (clk["now"][:10], loser["id"], (paused[0]["decision"]["stage"] if paused else [r for r in res if r["target"] == loser["id"]])))
    want = str(int(round(sales[0]["daily_budget"] * 1.2 * 100)))
    check(scaled and newb == [want], "winner campaign scaled AED %.0f → %.0f/day (+20%%): %s fils on the wire" % (sales[0]["daily_budget"], sales[0]["daily_budget"] * 1.2, newb))
    print("       day-8 cycle: %s" % sorted("%s %s %s" % (r["kind"], r["target"], r["status"]) for r in res))
    if not paused:
        s, cyc8 = call(base, "GET", "/api/brain/x/cycle")
        print("       loser verdict: %s; dropped %s" % ([v for v in cyc8.get("verdicts", []) if v["object"] == loser["id"]], cyc8.get("dropped")))
        s, st8 = call(base, "GET", "/api/state")
        print("       loser now: %s" % [(a["id"], a["status"], a.get("effective_status"), s_["name"]) for c in st8["campaigns"] for s_ in c["adsets"] for a in s_["ads"] if a["id"] == loser["id"]])
        s, lg = call(base, "GET", "/api/log?limit=3000")
        print("       log: %s" % [e["summary"][:120] for e in lg["entries"] if loser["id"] in json.dumps(e.get("data"))][-6:])

    # the owner approves the UAE National Day package: its ads (AR + EN, one rejected) go live through every check
    call(base, "PUT", "/api/limits", {"max_active_ads": 30})
    s, occ = call(base, "GET", "/api/occasions")
    nd = [j for j in occ["packages"] if j["status"] == "planned" and j.get("occasion", {}).get("occasion") == "national-day"]
    if nd:
        j = nd[0]
        call(base, "POST", "/api/jobs/%s/reject-ad" % j["id"], {"creative_id": j["creatives"][0]})
        s, ap = call(base, "POST", "/api/jobs/%s/approve" % j["id"], {})
        s, st4 = call(base, "GET", "/api/state")
        live_occ = [a["name"] for c in st4["campaigns"] for s_ in c["adsets"] for a in s_["ads"] if "Occasion" in a["name"]]
        check(s == 200 and len(live_occ) == len(j["creatives"]) - 1, "National Day approved (one ad rejected): %d occasion ads live, %s" % (len(live_occ), (ap.get("run") or {}).get("counts") or ap.get("run_error") or ap.get("job", {}).get("status")))
    else:
        check(False, "no National Day package to approve: %s" % [j.get("id") for j in occ["packages"]])

    simulate_10_days(base)

    # the account switch: each account its own data and limits
    s, sel = call(base, "POST", "/api/accounts/select", {"account": "act_1000000002"})
    t = time.time()
    while time.time() - t < 20:  # an account never read is synced in the background on the switch
        s, st2 = call(base, "GET", "/api/state")
        if not str(st2.get("last_sync", "0001")).startswith("0001"):
            break
        time.sleep(0.2)
    print("       switch: synced %.1f s after the select" % (time.time() - t))
    s, lim2 = call(base, "GET", "/api/limits")
    check(st2["account_id"] == "act_1000000002" and any("Wholesale" in c["name"] for c in st2["campaigns"]) and not any(c["managed"] for c in st2["campaigns"]) and lim2["daily_aed"] == 0,
          "switch to %s: its own campaigns %s, its own limits (daily %s)" % (st2["account_id"], [c["name"] for c in st2["campaigns"]], lim2["daily_aed"]))
    call(base, "PUT", "/api/limits", {"daily_aed": 50, "monthly_aed": 1000, "autopilot": "auto"})
    n2 = len(writes(base))
    s, cy4 = call(base, "POST", "/api/cycle", {"mode": "auto"})
    w4 = [x for x in writes(base)[n2:] if x["path"].startswith("act_1000000002/") or "/act_1000000002/" in x["path"]]
    s, l1 = call(base, "GET", "/api/limits", account=ACT)
    check(not w4 and l1["daily_aed"] == 300, "account 2 is not automated: its cycle wrote %d times (%s); account 1 limits untouched (%s/day)" % (len(w4), cy4.get("counts"), l1["daily_aed"]))
    call(base, "POST", "/api/accounts/select", {"account": ACT})

    # kill
    s, k = call(base, "POST", "/api/kill", {})
    paused_n = sum(len(x["paused"]) for x in k["accounts"])
    n3 = len(writes(base))
    s, cy5 = call(base, "POST", "/api/cycle", {"mode": "auto"}, account=ACT)
    new = [x for x in writes(base)[n3:] if not x["path"].endswith("adrules_library") and "spend_cap" not in x["form"] and "evaluation_spec" not in x["form"]]  # the safety net may refresh its rules
    check(paused_n == 2 and not new, "kill: %d app campaigns paused; a cycle after it wrote %d" % (paused_n, len(new)) + (" %s" % [(x["path"], x["form"]) for x in new] if new else ""))

    s, lj = call(base, "GET", "/api/brain/x/learning")
    check(not (lj.get("tuned") or {}).get("applied") and lj.get("self_tune") == "ask",
          "ask-me self-tuning never applied by itself (tuned %s, changes %s)" % (lj.get("tuned"), (lj.get("changes") or [])[-1:]))
    s, cc = call(base, "GET", "/api/test/claude-calls")
    k_ = cc.get("kinds") or {}
    check(all(k_.get(x, 0) >= 1 for x in ("plan", "copy", "research-search", "research-notes", "trending-search", "trending-items", "video")),
          "scripted Claude answered: %s" % json.dumps(k_, sort_keys=True))
    s, log = call(base, "GET", "/api/log?limit=3000")
    check(not any("EAAMOCK" in json.dumps(e) or "sk-ant-mock" in json.dumps(e) for e in log["entries"]), "audit log: %d entries, no key in it" % len(log["entries"]))


def simulate_10_days(base):
    """10 days at the Active level (the owner, 21 Nov 2026: "create more campaigns / ads ... plan super well on daily
    basis and monitor closely"): every morning the daily plan, then the day's cycle and the hourly check. Counts live
    ads per day, batches, early stops / rule stops and refills, and checks no limit is ever breached."""
    from datetime import datetime, timedelta, timezone
    MAXADS, DAILY = 14, 300
    call(base, "PUT", "/api/limits", {"max_active_ads": MAXADS})
    s, st = call(base, "GET", "/api/state")
    for c in st["campaigns"]:  # the day-8 seeding made every ad of the sales campaign a winner; back to the mock's own mix
        call(base, "POST", "/api/test/meta-tag", {"id": c["id"], "tag": ""})
    s, pb = call(base, "PUT", "/api/playbook", {"knobs": {"activity_level": "active"}})
    check(s == 200 and pb["playbook"]["knobs"]["activity_level"] == "active", "10-day simulation: Active level, max %d active ads, AED %d/day" % (MAXADS, DAILY))
    s, t = call(base, "GET", "/api/today")
    now = datetime.fromisoformat(t["now"].replace("Z", "+00:00"))
    dub = now + timedelta(hours=4)
    ahead = (10.0 - dub.hour - dub.minute / 60.0) % 24  # each simulated day at 10:00 Dubai, after the 09:00 batch
    call(base, "POST", "/api/test/clock", {"advance_hours": round(ahead, 2)})
    s, lg0 = call(base, "GET", "/api/log?limit=3000")
    seq0 = max([e["seq"] for e in lg0["entries"]] or [0])
    rows, breach, plans = [], [], set()
    for day in range(10):
        if day:
            call(base, "POST", "/api/test/clock", {"advance_hours": 24})
        s1, tp = call(base, "POST", "/api/today/plan", {})
        s2, cy = call(base, "POST", "/api/cycle", {"mode": "auto"})
        s3, ck = call(base, "POST", "/api/today/check", {})
        if s1 != 200 or s2 != 200 or s3 != 200:
            print("       day %d: plan %d cycle %d check %d %s" % (day + 1, s1, s2, s3, str(cy)[:200]))
        dp = tp.get("daily_plan") or {}
        plans.add(dp.get("date"))
        s, st = call(base, "GET", "/api/state")
        s, vol = call(base, "GET", "/api/volume")
        v = vol["volume"]
        active = sum(1 for c in st["campaigns"] for a_ in c["adsets"] for ad in a_["ads"] if ad["status"] == "ACTIVE" and a_["status"] == "ACTIVE" and c["status"] == "ACTIVE")
        committed = sum(c.get("daily_budget") or 0 for c in st["campaigns"] if c["status"] == "ACTIVE") + sum(a_.get("daily_budget") or 0 for c in st["campaigns"] if c["status"] == "ACTIVE" for a_ in c["adsets"] if a_["status"] == "ACTIVE")
        s, js = call(base, "GET", "/api/jobs")
        date = dp.get("date", "").replace("-", "")
        batch = [j for j in js["jobs"] if j["id"].startswith("vol-%s-" % date) and "fill" not in j["id"] and j["status"] == "done"]
        fills = [j for j in js["jobs"] if j["id"].startswith("vol-%s-fill" % date) and j["status"] == "done"]
        waiting = [j["waiting"] for j in js["jobs"] if j["id"].startswith("vol-%s" % date) and j["status"] in ("planned", "approved") and j.get("waiting")]
        rows.append((dp.get("date"), v["live"], v["live_min"], v["live_max"], active, round(committed), len(batch), len(fills), len(waiting)))
        if active > MAXADS or committed > DAILY + 0.01:
            breach.append((dp.get("date"), active, committed))
        if waiting:
            print("       %s waiting: %s" % (dp.get("date"), sorted(set(waiting))[:3]))
    if os.environ.get("KAA_SIM_DEBUG"):
        for j in js["jobs"]:
            if j["id"].startswith("vol-") or j["kind"] == "test":
                print("       job %s %s %s | %s | %s" % (j["id"], j["status"], j["when"][:16], j.get("waiting", ""), j.get("result", "")[:90]))
    s, lg = call(base, "GET", "/api/log?limit=3000")
    new = [e for e in lg["entries"] if e["seq"] > seq0]
    kills = [e for e in new if e["kind"] == "kill"]
    early = [e for e in kills if "early stop" in e["summary"]]
    stops = [e for e in new if e["kind"] == "execution" and "pause" in e["summary"]]
    skips = [e for e in new if e["kind"] == "volume" and "not planned" in e["summary"]]
    print("       day        live  target  active  AED/day  batch  refill  waiting")
    for r in rows:
        print("       %s  %4d  %2d–%-3d  %6d  %7d  %5d  %6d  %7d" % r)
    batches = [r for r in rows if r[6] > 0]
    in_range = [r for r in rows[2:] if r[2] <= r[1] <= r[3]]
    check(len(plans) == 10, "a daily plan every day: %d distinct dates" % len(plans))
    check(not breach, "no limit breached in 10 days (max active ads %d, AED %d/day): %s" % (MAXADS, DAILY, breach or "none"))
    check(len([r for r in batches if r[6] >= 3]) >= 2, "twice-weekly batches: %d batch days (%s), %d new ads in batches, %d refills"
          % (len(batches), ", ".join("%s: %d" % (r[0], r[6]) for r in batches), sum(r[6] for r in rows), sum(r[7] for r in rows)))
    check(len(in_range) >= 6, "live ads inside the target on %d of the last 8 days (%s)" % (len(in_range), [r[1] for r in rows]))
    print("       stops: %d by the rules/watch (%d early, first 72 h), %d pauses executed (incl. rotation for new batches); skipped plans logged: %d" % (len(kills), len(early), len(stops), len(skips)))


if __name__ == "__main__":
    main()
