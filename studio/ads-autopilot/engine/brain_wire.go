package main

import (
	"net/http"
	"os"
	"strings"
	"testing"

	"kaa/brain"
	"kaa/brainstub"
	"kaa/model"
)

// newBrain: LANE BRAIN's engine/brain. The engine's own tests (and KAA_BRAIN=stub)
// keep the deterministic brainstub: they test the engine's executor and
// scheduler, not the brain, and were written against the stub's proposals.
func newBrain(d model.BrainDeps) model.Brain {
	if testing.Testing() || os.Getenv("KAA_BRAIN") == "stub" {
		return brainstub.New(d)
	}
	return brain.New(d)
}

// brainExtra mounts the brain's own routes under /api/brain/x/ (videos,
// trending products, learned profile, the full last cycle for the UI, wording
// settings), behind the same token/origin checks and account scoping as every
// other account endpoint. api.go calls it before its 404.
func (s *server) brainExtra(w http.ResponseWriter, r *http.Request, p string) bool {
	if !strings.HasPrefix(p, "/api/brain/x/") {
		return false
	}
	a := s.app
	a.mu.RLock()
	eb, ok := a.brain.(*brain.Engine)
	a.mu.RUnlock()
	if !ok {
		jsonErr(w, 501, "this brain has no extra routes")
		return true
	}
	ac := s.acct(w, r)
	if ac == nil {
		return true
	}
	ac.mu.RLock()
	id := ac.id // never empty, even before the first sync (the brain keys its files by it)
	ac.mu.RUnlock()
	eb.ServeBrain(w, r, id, func() model.CycleInput {
		a.mu.RLock()
		shopSnap, research, learnings := a.shopSnap, a.research, a.learnings
		a.mu.RUnlock()
		ac.mu.RLock()
		in := model.CycleInput{Now: a.now(), Limits: ac.settings.Limits, Account: ac.accountRef(), Tree: cloneTree(ac.tree), Shop: shopSnap, Research: research, Links: ac.settings.Links, Learnings: learnings}
		ac.mu.RUnlock()
		in.Metrics, in.Creatives = ac.metricRows(), ac.creatives.all()
		in.History = ac.st.Recent(200, "execution", "check", "cycle", "kill", "alert", "safety")
		in.LongHistory, in.Creatives37 = ac.longHistory()
		return in
	})
	return true
}
