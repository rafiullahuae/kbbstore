package main

// exchange_wire.go — cloud creation (the owner, Nov 2026: "if the job is sent to claude for creation, the claude
// should not stop even i'm away, and when i come online or open my laptop, the system should sync back everything").
//
// A creation request raised in "With me in Claude (Max)" mode is ALSO pushed to the exchange (a private GitHub
// repository, engine/exchange) as a job. A scheduled Claude Code task on the owner's Max plan (studio/ads-autopilot/
// cloud/WORKER.md) claims it every 2 hours, writes the copy, renders every creative with the app's own renderer and
// quality gate, and pushes the results. This side, on start and on wake (the warm-up's "cloud" step), every 15 min
// while open, and right after a request appears or closes:
//
//	push      open requests -> jobs/<acct>/<id>/brief.json + status.json (queued); idempotent (the id is the job)
//	withdraw  a request he made himself first (done here), dismissed or written by the API -> status withdrawn
//	pull      results with done.json -> each render re-checked HERE (product ids exist and are in stock, the
//	          account's linter on the copy, the quality gate's numbers against its thresholds, the file opened):
//	          kept ones become library creatives (origin cloud_max) and one Made-with-Claude session that answers
//	          the request ("Made in the cloud on your Max plan"); refused ones are logged in Activity
//	feedback  feedback/<acct>/<id>/import.json: kept, refused and why, then what he did (ran, removed, edits)
//
// The key: Settings → Connections → Cloud creation (GitHub): a fine-grained token for that one repository with
// Contents read/write, kept ONLY in the secrets store (Keychain on the Mac). Nothing else is ever written: no key, no
// customer, no order — catalogue facts, his Playbook wording, aggregated lessons.
//
//	GET    /api/exchange          status (connected, last push / pull, jobs waiting / working / finished / failed)
//	PUT    /api/exchange          {owner, repo, token?}: validated against GitHub before the key is kept
//	DELETE /api/exchange          forget the key (the jobs stay in the repository)
//	POST   /api/exchange/sync     sync now
//	POST   /api/exchange/test-request  -mock only: raise a creation request {product_ids, angle}

import (
	"context"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/url"
	"os"
	"sort"
	"strings"
	"sync"
	"time"

	"kaa/brain"
	"kaa/exchange"
	"kaa/model"
	"kaa/secrets"
)

// githubAPI: GitHub's REST API (the Go tests point it at exchange.FakeGitHub).
var githubAPI = "https://api.github.com"

const exchangeEvery = 15 * time.Minute

type xjob struct {
	Title     string               `json:"title,omitempty"`
	Brief     string               `json:"brief,omitempty"` // hash of the brief pushed
	Pushed    time.Time            `json:"pushed,omitempty"`
	Withdrawn bool                 `json:"withdrawn,omitempty"`
	State     string               `json:"state,omitempty"` // waiting | working | finished | failed | withdrawn | imported | ignored
	Worker    string               `json:"worker,omitempty"`
	Imported  time.Time            `json:"imported,omitempty"`
	Kept      []string             `json:"kept,omitempty"` // creative ids
	Rejected  []exchange.Rejection `json:"rejected,omitempty"`
	Feedback  string               `json:"feedback,omitempty"` // hash of the last feedback pushed
}

type exchangeCfg struct {
	Owner     string           `json:"owner"`
	Repo      string           `json:"repo"`
	CheckedAt time.Time        `json:"checked_at,omitempty"`
	Expires   string           `json:"expires,omitempty"`
	LastPush  time.Time        `json:"last_push,omitempty"`
	LastPull  time.Time        `json:"last_pull,omitempty"`
	LastSync  time.Time        `json:"last_sync,omitempty"`
	LastError string           `json:"last_error,omitempty"`
	Counts    map[string]int   `json:"counts"`
	Jobs      map[string]*xjob `json:"jobs"`
}

type exchangeSync struct {
	mu    sync.Mutex // cfg
	run   sync.Mutex // one sync at a time
	cfg   exchangeCfg
	ready bool
	timer *time.Timer
}

var exchanges = struct {
	sync.Mutex
	m map[*App]*exchangeSync
}{m: map[*App]*exchangeSync{}}

func (a *App) xs() *exchangeSync {
	exchanges.Lock()
	defer exchanges.Unlock()
	x := exchanges.m[a]
	if x == nil {
		x = &exchangeSync{}
		exchanges.m[a] = x
	}
	if !x.ready {
		_ = a.st.Load("exchange", &x.cfg)
		if x.cfg.Owner == "" || x.cfg.Repo == "" {
			x.cfg.Owner, x.cfg.Repo = exchange.DefaultOwner, exchange.DefaultRepo
		}
		if x.cfg.Jobs == nil {
			x.cfg.Jobs = map[string]*xjob{}
		}
		x.ready = true
	}
	return x
}

func (x *exchangeSync) save(a *App) { _ = a.st.Save("exchange", x.cfg) }

// exRemote: the exchange, or nil when no key is saved. -mock with KAA_EXCHANGE_DIR: a folder (the self-test).
func (a *App) exRemote() exchange.Remote {
	if d := os.Getenv("KAA_EXCHANGE_DIR"); d != "" && mockURL != "" {
		return exchange.Dir{Root: d}
	}
	tok, err := a.sec.Get("github_token")
	if err != nil || tok == "" {
		return nil
	}
	x := a.xs()
	x.mu.Lock()
	o, r := x.cfg.Owner, x.cfg.Repo
	x.mu.Unlock()
	return exchange.NewGitHub(githubAPI, o, r, tok)
}

func (a *App) brainEngine() *brain.Engine {
	a.mu.RLock()
	defer a.mu.RUnlock()
	eb, _ := a.brain.(*brain.Engine)
	return eb
}

// brainInput: what the brain's own routes see for this account (brain_wire.go uses the same).
func (ac *Acct) brainInput() model.CycleInput {
	a := ac.app
	a.mu.RLock()
	shopSnap, research, learnings := a.shopSnap, a.research, a.learnings
	a.mu.RUnlock()
	ac.mu.RLock()
	in := model.CycleInput{Now: a.now(), Limits: ac.settings.Limits, Account: ac.accountRef(), Tree: cloneTree(ac.tree), Shop: shopSnap, Research: research, Links: ac.settings.Links, Learnings: learnings,
		Audiences: append([]model.Audience(nil), ac.audiences...)}
	ac.mu.RUnlock()
	fshop, pb, _ := ac.filteredShop(shopSnap)
	in.Shop, in.Playbook, in.JobNotes = fshop, &pb, ac.jobNotes()
	in.Metrics, in.Creatives = ac.metricRows(), ac.creatives.all()
	in.History = ac.st.Recent(200, "execution", "check", "cycle", "kill", "alert", "safety")
	in.LongHistory, in.Creatives37 = ac.longHistory()
	return in
}

func hashBytes(b []byte) string { h := sha256.Sum256(b); return hex.EncodeToString(h[:])[:16] }

// exchangeSoon: a request appeared or closed — sync in a few seconds (several at once are one sync).
func (a *App) exchangeSoon() {
	x := a.xs()
	x.mu.Lock()
	defer x.mu.Unlock()
	if x.timer != nil {
		x.timer.Stop()
	}
	x.timer = time.AfterFunc(5*time.Second, func() { _, _ = a.exchangeSync(context.Background(), "request") })
}

// startExchange: the every-15-minutes sync while the app is open (start and wake are the warm-up's "cloud" step).
func (a *App) startExchange(stop <-chan struct{}) {
	brain.CreationChanged = func(string) { a.exchangeSoon() }
	go func() {
		t := time.NewTicker(exchangeEvery)
		defer t.Stop()
		for {
			select {
			case <-stop:
				return
			case <-t.C:
				if a.exRemote() != nil {
					_, _ = a.exchangeSync(context.Background(), "timer")
				}
			}
		}
	}()
}

var errNoExchange = errors.New("cloud creation is not connected (Settings → Connections → Cloud creation)")

// exchangeSync: push, withdraw, pull + import, feedback. One at a time; every run is a line in Activity.
func (a *App) exchangeSync(ctx context.Context, reason string) (map[string]any, error) {
	_ = ctx
	r := a.exRemote()
	if r == nil {
		return nil, errNoExchange
	}
	eb := a.brainEngine()
	if eb == nil {
		return nil, errors.New("the brain is not running")
	}
	x := a.xs()
	x.run.Lock()
	defer x.run.Unlock()
	sum := map[string]any{"reason": reason}
	err := a.exchangeRun(r, eb, x, sum)
	x.mu.Lock()
	x.cfg.LastSync = a.now().UTC()
	x.cfg.LastError = ""
	if err != nil {
		x.cfg.LastError = err.Error()
	}
	x.save(a)
	x.mu.Unlock()
	line := fmt.Sprintf("cloud creation sync (%s): pushed %v, withdrew %v, imported %v ads, refused %v", reason, sum["pushed"], sum["withdrawn"], sum["imported"], sum["refused"])
	if err != nil {
		line = "cloud creation sync (" + reason + ") failed: " + err.Error()
	}
	a.st.Append("sync", line, sum)
	if a.publish != nil {
		a.publish("connections", map[string]any{"account": "", "data": map[string]any{"exchange": true}})
	}
	return sum, err
}

func commitRetry(r exchange.Remote, msg string, put map[string][]byte) error {
	if len(put) == 0 {
		return nil
	}
	var err error
	for i := 0; i < 4; i++ {
		var s *exchange.Snapshot
		if s, err = r.Snapshot(); err != nil {
			return err
		}
		if err = r.Commit(s, msg, put, nil); !errors.Is(err, exchange.ErrConflict) {
			return err
		}
	}
	return err
}

func (a *App) exchangeRun(r exchange.Remote, eb *brain.Engine, x *exchangeSync, sum map[string]any) error {
	snap, err := r.Snapshot()
	if err != nil {
		return err
	}
	jobs, _ := exchange.Jobs(r, snap)
	remote := map[string]exchange.Job{}
	for _, j := range jobs {
		remote[j.Account+"/"+j.ID] = j
	}
	now := a.now().UTC()
	put := map[string][]byte{}
	pushed, withdrawn, imported, refused := 0, 0, 0, 0
	type pull struct {
		ac  *Acct
		req *brain.CreationRequest
		job exchange.Job
	}
	var pulls []pull
	x.mu.Lock()
	cfg := &x.cfg
	x.mu.Unlock()
	for _, ac := range a.Accounts() {
		reqs := eb.CreationRequests(ac.id)
		byID := map[string]*brain.CreationRequest{}
		for i := range reqs {
			req := &reqs[i]
			byID[req.ID] = req
			key := ac.id + "/" + req.ID
			if !exchange.ValidJob(ac.id, req.ID) {
				continue
			}
			open := req.Status == "open" || req.Status == "started"
			rj, there := remote[key]
			x.mu.Lock()
			j := cfg.Jobs[key]
			if j == nil && (open || there) {
				j = &xjob{Title: req.Title}
				cfg.Jobs[key] = j
			}
			x.mu.Unlock()
			switch {
			case open && !there && !(j != nil && j.Withdrawn):
				b := eb.CloudBrief(ac.id, ac.brainInput(), *req)
				bb := exchange.MustJSON(b)
				put[exchange.JobDir(ac.id, req.ID)+"/brief.json"] = bb
				put[exchange.JobDir(ac.id, req.ID)+"/status.json"] = exchange.MustJSON(exchange.Status{State: "queued", At: now})
				j.Brief, j.Pushed, j.State = hashBytes(bb), now, "waiting"
				pushed++
				eb.SetCloud(ac.id, req.ID, "queued", "")
			case !open && there && req.Cloud != "imported" && rj.Status.State != "withdrawn" && j != nil && j.Imported.IsZero():
				// made here first, dismissed, or written by the API: the cloud does not make it (again)
				put[exchange.JobDir(ac.id, req.ID)+"/status.json"] = exchange.MustJSON(exchange.Status{State: "withdrawn", At: now, Note: "the owner's app closed this request (" + req.Status + ")"})
				j.Withdrawn, j.State = true, "withdrawn"
				withdrawn++
				eb.SetCloud(ac.id, req.ID, "withdrawn", "")
			case open && there:
				st := rj.State(now)
				by := ""
				if rj.Claim != nil {
					by = rj.Claim.Worker
				}
				if j != nil {
					j.State, j.Worker = st, by
				}
				switch st {
				case "waiting":
					eb.SetCloud(ac.id, req.ID, "queued", "")
				case "working", "failed":
					eb.SetCloud(ac.id, req.ID, st, by)
				}
			}
		}
		for key, rj := range remote { // finished jobs of this account not imported yet
			if rj.Account != ac.id || !rj.Done {
				continue
			}
			x.mu.Lock()
			j := cfg.Jobs[key]
			x.mu.Unlock()
			if j != nil && !j.Imported.IsZero() {
				continue
			}
			pulls = append(pulls, pull{ac, byID[rj.ID], rj})
		}
	}
	if err := commitRetry(r, fmt.Sprintf("KBB Ads Autopilot: %d new job(s), %d withdrawn", pushed, withdrawn), put); err != nil {
		return fmt.Errorf("push: %w", err)
	}
	if len(put) > 0 {
		x.mu.Lock()
		cfg.LastPush = now
		x.mu.Unlock()
	}
	// pull + import
	fput := map[string][]byte{}
	sort.Slice(pulls, func(i, k int) bool { return pulls[i].job.ID < pulls[k].job.ID })
	for _, p := range pulls {
		key := p.ac.id + "/" + p.job.ID
		fb, n, rej := a.importJob(r, snap, eb, p.ac, p.req, p.job)
		imported += n
		refused += rej
		x.mu.Lock()
		j := cfg.Jobs[key]
		if j == nil {
			j = &xjob{}
			cfg.Jobs[key] = j
		}
		j.Imported, j.Kept, j.Rejected, j.State = now, fb.Imported, fb.Rejected, "imported"
		if fb.Ignored != "" {
			j.State = "ignored"
		}
		b := exchange.MustJSON(fb)
		j.Feedback = hashBytes(b)
		x.mu.Unlock()
		fput[exchange.FeedDir(p.ac.id, p.job.ID)+"/import.json"] = b
	}
	// what the owner did with imported ads since: ran, removed, edits (the worker reads it before its next job)
	x.mu.Lock()
	keys := make([]string, 0, len(cfg.Jobs))
	for k := range cfg.Jobs {
		keys = append(keys, k)
	}
	x.mu.Unlock()
	sort.Strings(keys)
	for _, key := range keys {
		x.mu.Lock()
		j := cfg.Jobs[key]
		x.mu.Unlock()
		if j == nil || j.State != "imported" || j.Imported.Equal(now) {
			continue
		}
		parts := strings.SplitN(key, "/", 2)
		ac, err := a.Acct(parts[0])
		if err != nil {
			continue
		}
		fb := a.ownerFeedback(eb, ac, parts[1], j)
		b := exchange.MustJSON(fb)
		fbHash := hashBytes(b)
		if fbHash != j.Feedback {
			fput[exchange.FeedDir(parts[0], parts[1])+"/import.json"] = b
			x.mu.Lock()
			j.Feedback = fbHash
			x.mu.Unlock()
		}
	}
	if err := commitRetry(r, fmt.Sprintf("KBB Ads Autopilot: feedback on %d job(s)", len(fput)), fput); err != nil {
		return fmt.Errorf("feedback: %w", err)
	}
	// counts, from a fresh look
	counts := map[string]int{"waiting": 0, "working": 0, "finished": 0, "failed": 0, "withdrawn": 0}
	if s2, err := r.Snapshot(); err == nil {
		js, _ := exchange.Jobs(r, s2)
		for _, j := range js {
			counts[j.State(now)]++
		}
	}
	x.mu.Lock()
	cfg.LastPull, cfg.Counts = now, counts
	x.mu.Unlock()
	sum["pushed"], sum["withdrawn"], sum["imported"], sum["refused"], sum["counts"] = pushed, withdrawn, imported, refused, counts
	return nil
}

// ownerFeedback: the import result plus what the owner did since (Run it, removed from the library, revisions).
func (a *App) ownerFeedback(eb *brain.Engine, ac *Acct, job string, j *xjob) exchange.Feedback {
	fb := exchange.Feedback{JobID: job, At: j.Imported, Rejected: j.Rejected}
	lib := map[string]model.Creative{}
	for _, c := range ac.creatives.all() {
		lib[c.ID] = c
	}
	for _, id := range j.Kept {
		fb.Imported = append(fb.Imported, id)
		c, ok := lib[id]
		switch {
		case !ok:
			fb.Removed = append(fb.Removed, id)
		case c.Approved:
			fb.OwnerRan = append(fb.OwnerRan, id)
		}
	}
	if s := eb.SessionByRequest(ac.id, job); s != nil {
		for _, t := range s.Turns {
			if t.Kind == "edit" {
				fb.Edits = append(fb.Edits, t.Text)
			}
		}
	}
	return fb
}

// importJob: every render of a finished job through the Mac's own checks; what passes joins the library.
func (a *App) importJob(r exchange.Remote, snap *exchange.Snapshot, eb *brain.Engine, ac *Acct, req *brain.CreationRequest, job exchange.Job) (exchange.Feedback, int, int) {
	fb := exchange.Feedback{JobID: job.ID, At: a.now().UTC(), Imported: []string{}, Rejected: []exchange.Rejection{}}
	dir := exchange.ResultDir(ac.id, job.ID)
	reject := func(file, why string) {
		fb.Rejected = append(fb.Rejected, exchange.Rejection{File: file, Why: why})
		ac.st.Append("alert", "cloud ad refused ("+job.ID+" "+file+"): "+why, map[string]string{"job": job.ID, "file": file})
	}
	if req != nil && req.Status != "open" && req.Status != "started" && req.Cloud != "imported" {
		fb.Ignored = "the request was closed here first (" + req.Status + "): nothing imported"
		ac.st.Append("sync", "cloud job "+job.ID+" finished after you closed it here: not imported", nil)
		return fb, 0, 0
	}
	var ads exchange.Ads
	var done exchange.Done
	if err := exchange.ReadJSON(r, snap, dir+"/ads.json", &ads); err != nil {
		reject("ads.json", "unreadable: "+err.Error())
		return fb, 0, 1
	}
	_ = exchange.ReadJSON(r, snap, dir+"/done.json", &done)
	worker := firstNonEmptyStr(done.Worker, ads.Worker, "cloud")
	if done.Error != "" {
		reject("done.json", "the cloud could not make it: "+done.Error)
	}
	in := ac.brainInput()
	stock := map[string]model.Product{}
	for _, p := range in.Shop.Products {
		if p.InStock {
			stock[p.ID] = p
		}
	}
	wording, promo := eb.CloudWording(ac.id, in.Limits)
	ideaBad := map[int]string{}
	setBad := map[string]string{} // "i/lang"
	for i, idea := range ads.Ideas {
		if len(idea.ProductIDs) == 0 {
			ideaBad[i+1] = "no product"
		}
		for _, id := range idea.ProductIDs {
			if _, ok := stock[id]; !ok {
				ideaBad[i+1] = "product " + id + " is not in the shop's in-stock catalogue"
			}
		}
		for _, s := range idea.Sets {
			var cs brain.CreateSet
			b, _ := json.Marshal(s)
			_ = json.Unmarshal(b, &cs)
			if p := brain.CloudSetProblems(cs, wording, promo); len(p) > 0 {
				setBad[fmt.Sprintf("%d/%s", i+1, s.Lang)] = "the " + wording + " wording rules: " + strings.Join(p, "; ")
			}
		}
	}
	keptSets := map[string]bool{}
	var saved []string
	have := map[string]bool{}
	for _, c := range ac.creatives.all() {
		have[c.ID] = true
	}
	for _, rd := range ads.Renders {
		if rd.Idea < 1 || rd.Idea > len(ads.Ideas) || !exchange.ValidFile(rd.File) {
			reject(rd.File, "not a render of this job (idea or file name)")
			continue
		}
		idea := ads.Ideas[rd.Idea-1]
		var set *exchange.Set
		for k := range idea.Sets {
			if idea.Sets[k].Lang == rd.Lang {
				set = &idea.Sets[k]
			}
		}
		if set == nil {
			reject(rd.File, "no "+rd.Lang+" copy for idea "+fmt.Sprint(rd.Idea))
			continue
		}
		if why := ideaBad[rd.Idea]; why != "" {
			reject(rd.File, why)
			continue
		}
		if why := setBad[fmt.Sprintf("%d/%s", rd.Idea, rd.Lang)]; why != "" {
			reject(rd.File, why)
			continue
		}
		if q := exchange.CheckQuality(rd.Quality, rd.Lang); len(q) > 0 { // a motion ad carries its 9:16 still's gate
			reject(rd.File, "quality gate: "+strings.Join(q, "; "))
			continue
		}
		e, ok := snap.Files[dir+"/"+rd.File]
		if !ok {
			reject(rd.File, "the file is missing")
			continue
		}
		b, err := r.Read(e)
		if err == nil {
			err = exchange.CheckMedia(rd, b)
		}
		if err != nil {
			reject(rd.File, err.Error())
			continue
		}
		id := "cl-" + hashBytes([]byte(ac.id + "/" + job.ID + "/" + rd.File))[:12]
		if !have[id] {
			link := "https://kbeautybliss.com/"
			if p, ok := stock[idea.ProductIDs[0]]; ok && strings.HasPrefix(p.URL, "https://kbeautybliss.com/") {
				link = p.URL
			}
			cr := model.Creative{ID: id, Lang: rd.Lang, Format: rd.Format, Headline: set.Headline, PrimaryText: set.PrimaryText, CTA: firstNonEmptyStr(set.CTA, "SHOP_NOW"),
				Link: link, ProductIDs: idea.ProductIDs, Angle: idea.Angle, Source: "owner", Origin: "cloud_max"}
			if rd.Kind == "mp4" {
				cr.Video = base64.StdEncoding.EncodeToString(b)
			} else {
				cr.PNG = base64.StdEncoding.EncodeToString(b)
			}
			if _, err := ac.creatives.Add(cr, a.now()); err != nil {
				reject(rd.File, "the library refused it: "+err.Error())
				continue
			}
			have[id] = true
		}
		saved = append(saved, id)
		fb.Imported = append(fb.Imported, id)
		keptSets[fmt.Sprintf("%d/%s", rd.Idea, rd.Lang)] = true
	}
	var ideas []brain.CreateIdea
	for i, idea := range ads.Ideas {
		var bi brain.CreateIdea
		b, _ := json.Marshal(idea)
		_ = json.Unmarshal(b, &bi)
		var sets []brain.CreateSet
		for _, s := range bi.Sets {
			if keptSets[fmt.Sprintf("%d/%s", i+1, s.Lang)] {
				sets = append(sets, s)
			}
		}
		if len(sets) > 0 {
			bi.Sets = sets
			ideas = append(ideas, bi)
		}
	}
	if len(saved) > 0 {
		ac.dirty()
		reqID := job.ID
		if _, err := eb.ImportCloud(ac.id, reqID, worker, ideas, saved, in); err != nil {
			ac.st.Append("alert", "cloud job "+job.ID+": "+err.Error(), nil)
		}
		ac.st.Append("sync", fmt.Sprintf("made in the cloud on your Max plan: %d ad(s) for %s imported, %d refused", len(saved), job.ID, len(fb.Rejected)), map[string]any{"job": job.ID, "worker": worker, "kept": saved})
	} else if req != nil {
		eb.SetCloud(ac.id, req.ID, "failed", worker)
	}
	return fb, len(saved), len(fb.Rejected)
}

func firstNonEmptyStr(s ...string) string {
	for _, x := range s {
		if x != "" {
			return x
		}
	}
	return ""
}

// tokenURL: GitHub's fine-grained token page, pre-filled with the parameters GitHub documents (docs.github.com,
// "Pre-filling fine-grained personal access token details using URL parameters": name ≤ 40 chars, description,
// target_name = the owner of the repositories, expires_in = 1..366 or none, and one parameter per permission:
// contents=write). Repository selection has NO parameter: the card says "Only select repositories → <repo>".
func tokenURL(owner, repo string) string {
	q := url.Values{}
	q.Set("name", "KBB Ads cloud creation")
	q.Set("description", "KBB Ads Autopilot: jobs and ads through "+owner+"/"+repo+" only. Repository access: Only select repositories → "+repo+".")
	q.Set("target_name", owner)
	q.Set("expires_in", "366")
	q.Set("contents", "write")
	return "https://github.com/settings/personal-access-tokens/new?" + q.Encode()
}

func (a *App) exchangeView() map[string]any {
	x := a.xs()
	x.mu.Lock()
	c := x.cfg
	jobs := make([]map[string]any, 0, len(c.Jobs))
	for k, j := range c.Jobs {
		parts := strings.SplitN(k, "/", 2)
		jobs = append(jobs, map[string]any{"account": parts[0], "id": parts[1], "title": j.Title, "state": j.State, "worker": j.Worker, "pushed": j.Pushed, "imported": j.Imported, "kept": len(j.Kept), "rejected": j.Rejected})
	}
	x.mu.Unlock()
	sort.Slice(jobs, func(i, k int) bool { return fmt.Sprint(jobs[i]["pushed"]) > fmt.Sprint(jobs[k]["pushed"]) })
	if len(jobs) > 20 {
		jobs = jobs[:20]
	}
	tok := secrets.Describe(a.sec, "github_token")
	local := os.Getenv("KAA_EXCHANGE_DIR") != "" && mockURL != ""
	return map[string]any{"owner": c.Owner, "repo": c.Repo, "default": map[string]string{"owner": exchange.DefaultOwner, "repo": exchange.DefaultRepo},
		"token": tok, "connected": (tok.Set && !c.CheckedAt.IsZero()) || local, "checked_at": c.CheckedAt, "expires": c.Expires,
		"last_push": c.LastPush, "last_pull": c.LastPull, "last_sync": c.LastSync, "last_error": c.LastError, "counts": c.Counts, "jobs": jobs,
		"every_min": int(exchangeEvery / time.Minute), "token_url": tokenURL(c.Owner, c.Repo)}
}

func (s *server) exchangeAPI(w http.ResponseWriter, r *http.Request, p string) {
	a := s.app
	switch {
	case p == "/api/exchange" && r.Method == http.MethodGet:
		writeJSON(w, a.exchangeView())
	case p == "/api/exchange" && r.Method == http.MethodPut:
		var in struct{ Owner, Repo, Token string }
		if err := readJSON(r, &in, 8192); err != nil {
			jsonErr(w, 400, err.Error())
			return
		}
		in.Owner, in.Repo, in.Token = strings.TrimSpace(in.Owner), strings.TrimSpace(in.Repo), strings.TrimSpace(in.Token)
		if err := exchange.ValidRepo(in.Owner, in.Repo); err != nil {
			jsonErr(w, 400, err.Error())
			return
		}
		tok := in.Token
		if tok != "" {
			if err := exchange.ValidToken(tok); err != nil {
				jsonErr(w, 400, err.Error())
				return
			}
			if err := secrets.CheckFormat("github_token", tok); err != nil {
				jsonErr(w, 400, err.Error())
				return
			}
		} else if v, _ := a.sec.Get("github_token"); v != "" {
			tok = v
		} else {
			jsonErr(w, 400, "paste the GitHub key (github_pat_…) first")
			return
		}
		g := exchange.NewGitHub(githubAPI, in.Owner, in.Repo, tok)
		info, err := g.Check()
		if err != nil { // the key is NOT kept when it does not reach the repository as it must
			jsonErr(w, 400, err.Error())
			return
		}
		if in.Token != "" {
			if err := a.sec.Set("github_token", tok); err != nil {
				jsonErr(w, 500, err.Error())
				return
			}
		}
		x := a.xs()
		x.mu.Lock()
		x.cfg.Owner, x.cfg.Repo, x.cfg.CheckedAt, x.cfg.Expires, x.cfg.LastError = in.Owner, in.Repo, a.now().UTC(), fmt.Sprint(info["expires"]), ""
		x.save(a)
		x.mu.Unlock()
		a.st.Append("config", "cloud creation connected to "+in.Owner+"/"+in.Repo, map[string]any{"repo": in.Owner + "/" + in.Repo})
		go func() { _, _ = a.exchangeSync(context.Background(), "connected") }()
		writeJSON(w, a.exchangeView())
	case p == "/api/exchange" && r.Method == http.MethodDelete:
		_ = a.sec.Delete("github_token")
		x := a.xs()
		x.mu.Lock()
		x.cfg.CheckedAt = time.Time{}
		x.save(a)
		x.mu.Unlock()
		a.st.Append("config", "cloud creation disconnected (the GitHub key was removed)", nil)
		writeJSON(w, a.exchangeView())
	case p == "/api/exchange/sync" && r.Method == http.MethodPost:
		if _, err := a.exchangeSync(r.Context(), "now"); err != nil {
			code := 502
			if errors.Is(err, errNoExchange) {
				code = 409
			}
			jsonErr(w, code, err.Error())
			return
		}
		writeJSON(w, a.exchangeView())
	case p == "/api/exchange/test-request" && r.Method == http.MethodPost && mockURL != "":
		ac := s.acct(w, r)
		if ac == nil {
			return
		}
		var in struct {
			ProductIDs []string `json:"product_ids"`
			Angle      string   `json:"angle"`
		}
		_ = readJSON(r, &in, 8192)
		eb := a.brainEngine()
		if eb == nil {
			jsonErr(w, 501, "no brain")
			return
		}
		writeJSON(w, eb.AddCreationRequest(ac.id, ac.brainInput(), in.ProductIDs, in.Angle))
	default:
		jsonErr(w, 404, "no such endpoint")
	}
}
