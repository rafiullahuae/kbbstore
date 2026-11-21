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
	if p == "/api/brain/x/img" && mockURL != "" { // -mock: the fake feed's photos do not exist; never fetch the shop
		w.Header().Set("Content-Type", "image/png")
		w.Header().Set("Cache-Control", "private, max-age=3600")
		_, _ = w.Write(mockPhoto())
		return true
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
	eb.ServeBrain(w, r, id, ac.brainInput) // the same input the cloud briefs use (exchange_wire.go)
	return true
}
