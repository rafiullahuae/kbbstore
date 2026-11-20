package main

// proposals.go — the owner approves ONE proposal (Approvals → Approve / Not now), the creative library's media
// files, and the owner's videos read from the data folder for upload_creative {video_path}.
//
// Every cycle keeps its proposals with what the executor did to each (accounts/<act>/proposals.json). In Practice
// mode they wait as "shadow"; POST /api/proposals/{id}/approve runs that proposal — plus the not-yet-made objects it
// names (its tmp: campaign / ad set / creative / product set / audience) — through the executor with every check,
// as if the account were in Auto for that one call. Not now marks it "rejected"; neither ever re-plans.

import (
	"context"
	"errors"
	"fmt"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"

	"kaa/executor"
	"kaa/model"
)

type PendingItem struct {
	model.Proposal
	Status string           `json:"status"` // shadow | refused | failed | done | duplicate | rejected
	Result *executor.Result `json:"result,omitempty"`
	At     time.Time        `json:"decided_at,omitempty"`
	By     string           `json:"by,omitempty"` // cycle | owner
}

type PendingSet struct {
	CycleAt time.Time     `json:"cycle_at"`
	Mode    string        `json:"mode"`
	Items   []PendingItem `json:"proposals"`
}

var proposalIDRe = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9:._-]{0,119}$`)

// keepProposals: called at the end of every cycle with what the executor answered.
func (ac *Acct) keepProposals(at time.Time, mode string, props []model.Proposal, results []executor.Result) {
	byID := map[string]executor.Result{}
	for _, r := range results {
		byID[r.ProposalID] = r
	}
	ps := PendingSet{CycleAt: at.UTC(), Mode: mode, Items: []PendingItem{}}
	for _, p := range props {
		it := PendingItem{Proposal: p, Status: "refused", By: "cycle"}
		if r, ok := byID[p.ID]; ok {
			r := r
			it.Status, it.Result = r.Status, &r
		}
		ps.Items = append(ps.Items, it)
	}
	ac.mu.Lock()
	ac.pending = ps
	ac.stateJSON = nil
	ac.mu.Unlock()
	_ = ac.st.Save("proposals", ps)
}

func (ac *Acct) Pending() PendingSet {
	ac.mu.RLock()
	defer ac.mu.RUnlock()
	ps := ac.pending
	ps.Items = append([]PendingItem(nil), ps.Items...)
	if ps.Items == nil {
		ps.Items = []PendingItem{}
	}
	return ps
}

// waiting: a proposal the owner can still act on.
func waiting(status string) bool {
	return status == "shadow" || status == "failed" || status == "refused"
}

// withDeps: the proposal and, before it, every waiting proposal it names by target (recursively), in plan order.
func withDeps(items []PendingItem, idx int) []int {
	need := map[int]bool{idx: true}
	var walk func(i int)
	walk = func(i int) {
		refs := map[string]bool{}
		collectStrings(items[i].Params, refs)
		for j := range items {
			if j != i && !need[j] && refs[items[j].Target] && items[j].Status != "done" && items[j].Status != "duplicate" && items[j].Status != "rejected" {
				need[j] = true
				walk(j)
			}
		}
	}
	walk(idx)
	var out []int
	for i := range items {
		if need[i] {
			out = append(out, i)
		}
	}
	return out
}

func collectStrings(v any, into map[string]bool) {
	switch x := v.(type) {
	case string:
		into[x] = true
	case []any:
		for _, e := range x {
			collectStrings(e, into)
		}
	case []string:
		for _, e := range x {
			into[e] = true
		}
	case map[string]any:
		for _, e := range x {
			collectStrings(e, into)
		}
	}
}

var errNotWaiting = errors.New("this proposal is not waiting for a decision")

// Decide: approve (run it now through the executor) or reject one proposal of the last cycle.
func (ac *Acct) Decide(ctx context.Context, id string, approve bool) (map[string]any, int, error) {
	if !ac.work.TryLock() {
		return nil, 409, errBusy
	}
	defer ac.work.Unlock()
	a := ac.app
	ps := ac.Pending()
	idx := -1
	for i := range ps.Items {
		if ps.Items[i].ID == id {
			idx = i
		}
	}
	if idx < 0 {
		return nil, 404, fmt.Errorf("no proposal %q in the last plan", id)
	}
	if !waiting(ps.Items[idx].Status) {
		return nil, 409, fmt.Errorf("%w (it is %s)", errNotWaiting, ps.Items[idx].Status)
	}
	now := a.now().UTC()
	if !approve {
		ps.Items[idx].Status, ps.Items[idx].At, ps.Items[idx].By = "rejected", now, "owner"
		ac.savePending(ps)
		ac.st.Append("approval", "owner: not now — "+ps.Items[idx].Kind+" "+ps.Items[idx].Target, ps.Items[idx])
		return map[string]any{"account": ac.id, "id": id, "status": "rejected"}, 200, nil
	}
	st := ac.Settings()
	switch {
	case !st.Automated:
		return nil, 409, errors.New("this account is not automated by the app (Portfolio → switch it on first)")
	case !st.Limits.Set():
		return nil, 409, errors.New("set the daily and monthly limits first: nothing launches without them")
	case st.Limits.Autopilot == model.AutopilotOff:
		return nil, 409, errors.New("the autopilot is off for this account (Practice or Auto runs approvals)")
	}
	if err := ac.Sync(ctx); err != nil { // the checks see Meta as it is now
		return nil, 502, fmt.Errorf("cannot read the account before acting: %w", err)
	}
	order := withDeps(ps.Items, idx)
	var props []model.Proposal
	for _, i := range order {
		props = append(props, ps.Items[i].Proposal)
	}
	env := ac.env(model.AutopilotAuto)
	ac.mu.RLock()
	ex := ac.exec
	ac.mu.RUnlock()
	results := ex.Run(ctx, env, props)
	counts := map[string]int{}
	for k, r := range results {
		r := r
		i := order[k]
		ps.Items[i].Status, ps.Items[i].Result, ps.Items[i].At, ps.Items[i].By = r.Status, &r, now, "owner"
		counts[r.Status]++
	}
	ac.savePending(ps)
	ac.st.Append("approval", fmt.Sprintf("owner approved %s %s (+%d it needs): %v", ps.Items[idx].Kind, ps.Items[idx].Target, len(order)-1, counts), results)
	ac.dirty()
	return map[string]any{"account": ac.id, "id": id, "ran": len(results), "counts": counts, "results": results}, 200, nil
}

func (ac *Acct) savePending(ps PendingSet) {
	ac.mu.Lock()
	ac.pending = ps
	ac.stateJSON = nil
	ac.mu.Unlock()
	_ = ac.st.Save("proposals", ps)
}

// env: what the executor's checks see for this account now. mode auto = the owner's own decision (still every
// limit, cooldown, rate and policy check); shadow = log only.
func (ac *Acct) env(mode string) executor.Env {
	a := ac.app
	a.mu.RLock()
	globalD, globalM := a.cfg.GlobalDailyAED, a.cfg.GlobalMonthlyAED
	usage := a.meta.Usage()
	br := a.brain
	a.mu.RUnlock()
	ac.mu.RLock()
	st := ac.settings
	tree := cloneTree(ac.tree)
	ac.mu.RUnlock()
	effective := st.Limits
	if !st.Automated {
		effective.Autopilot = model.AutopilotOff
	} else if effective.Autopilot != model.AutopilotOff {
		effective.Autopilot = mode
	}
	oc, om := ac.others()
	env := executor.Env{Now: a.now(), Limits: effective, Tree: tree, MonthSpendAED: ac.monthSpend(), Ledger: ac.ledger, Usage: usage, Loc: ac.loc(),
		Links: st.Links, GlobalDailyAED: globalD, GlobalMonthlyAED: globalM, OtherCommitted: oc, OtherMonthProjected: om}
	if l, ok := br.(interface{ Lint(model.Proposal) []string }); ok {
		env.Lint = func(p model.Proposal) []string { return append(executor.BuiltinLint(p), l.Lint(p)...) }
	}
	return env
}

func (s *server) proposalsAPI(w http.ResponseWriter, r *http.Request, p string) {
	ac := s.acct(w, r)
	if ac == nil {
		return
	}
	if p == "/api/proposals" {
		if r.Method != http.MethodGet {
			jsonErr(w, 405, "GET")
			return
		}
		writeJSON(w, ac.Pending())
		return
	}
	rest := strings.TrimPrefix(p, "/api/proposals/")
	i := strings.LastIndex(rest, "/")
	if r.Method != http.MethodPost || i < 0 {
		jsonErr(w, 404, "POST /api/proposals/{id}/approve or /reject")
		return
	}
	id, verb := rest[:i], rest[i+1:]
	if !proposalIDRe.MatchString(id) || (verb != "approve" && verb != "reject") {
		jsonErr(w, 404, "POST /api/proposals/{id}/approve or /reject")
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), 10*time.Minute)
	defer cancel()
	out, code, err := ac.Decide(ctx, id, verb == "approve")
	if err != nil {
		jsonErr(w, code, err.Error())
		return
	}
	writeJSON(w, out)
}

// ── media files ──

// creativeFile: GET /api/creatives/{id}/file — the stored PNG / MP4 of one creative of this account (Range works,
// so a video plays in the library). Only ids in the account's own library; the path is built from the id, never
// taken from the request.
func (s *server) creativeFile(w http.ResponseWriter, r *http.Request, id string) {
	ac := s.acct(w, r)
	if ac == nil {
		return
	}
	if !creativeIDRe.MatchString(id) {
		jsonErr(w, 404, "no such creative")
		return
	}
	var cr *model.Creative
	for _, c := range ac.creatives.all() {
		if c.ID == id {
			c := c
			cr = &c
		}
	}
	if cr == nil || (cr.Media != "png" && cr.Media != "mp4") {
		jsonErr(w, 404, "this creative has no stored picture or video")
		return
	}
	f, err := os.Open(ac.creatives.mediaPath(*cr))
	if err != nil {
		jsonErr(w, 404, "the creative's file is missing from the data folder")
		return
	}
	defer f.Close()
	fi, err := f.Stat()
	if err != nil || !fi.Mode().IsRegular() {
		jsonErr(w, 404, "no such file")
		return
	}
	ct := map[string]string{"png": "image/png", "mp4": "video/mp4"}[cr.Media]
	w.Header().Set("Content-Type", ct)
	w.Header().Set("Cache-Control", "private, max-age=86400, immutable")
	w.Header().Set("Content-Security-Policy", "default-src 'none'; sandbox")
	http.ServeContent(w, r, "", fi.ModTime(), f)
}

var coverPathRe = regexp.MustCompile(`^videos/[A-Za-z0-9][A-Za-z0-9_-]{0,80}\.jpg$`)

const maxOwnerVideo = 4 << 30 // Meta's own ceiling for a video ad upload

// videoFile: the executor's way to the owner's videos (upload_creative {video_path}). Only videos/<name>.<ext>
// (+ its .jpg cover), only regular files, only inside the data folder's videos/ after symlinks are resolved.
func (a *App) videoFile(rel string) (executor.ReaderAtCloser, int64, error) {
	if !executor.VideoPathRe.MatchString(rel) && !coverPathRe.MatchString(rel) {
		return nil, 0, fmt.Errorf("%q is not one of the owner's videos", rel)
	}
	root, err := filepath.EvalSymlinks(filepath.Join(a.st.Dir, "videos"))
	if err != nil {
		return nil, 0, errors.New("no videos folder in the data folder yet")
	}
	real, err := filepath.EvalSymlinks(filepath.Join(a.st.Dir, filepath.FromSlash(rel)))
	if err != nil {
		return nil, 0, fmt.Errorf("%s is not in the data folder", rel)
	}
	if !strings.HasPrefix(real, root+string(os.PathSeparator)) {
		return nil, 0, fmt.Errorf("%s points outside the videos folder", rel)
	}
	f, err := os.Open(real)
	if err != nil {
		return nil, 0, err
	}
	fi, err := f.Stat()
	if err != nil || !fi.Mode().IsRegular() || fi.Size() == 0 || fi.Size() > maxOwnerVideo {
		f.Close()
		return nil, 0, fmt.Errorf("%s is not a video file up to 4 GB", rel)
	}
	return f, fi.Size(), nil
}
