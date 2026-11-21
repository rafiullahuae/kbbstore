package main

// playbook.go — the owner's Playbook per ad account (GET/PUT /api/playbook) and the feed's first-seen dates.
//
// The Playbook is enforced HERE, in code, before any planner sees a product: Acct.Cycle hands the brain (and the
// funnel builder) the shop AFTER model.Playbook.Filter, so an avoided product cannot be proposed, written about or
// launched. Changes apply at the next cycle and are logged ("config"), with the old and new value.

import (
	"encoding/json"
	"fmt"
	"net/http"
	"sort"
	"strings"

	"kaa/model"
)

// playbook: this account's Playbook (defaults until the owner saves one). Normalised on every read.
func (ac *Acct) playbook() model.Playbook {
	ac.mu.RLock()
	pb := ac.pb
	ac.mu.RUnlock()
	if pb == nil {
		d := model.DefaultPlaybook()
		return d.Normalize()
	}
	return pb.Normalize()
}

func (ac *Acct) loadPlaybook() {
	var pb model.Playbook // a missing file loads as nothing (store.Load answers nil): only a saved one counts
	if ac.st.Load("playbook", &pb) == nil && pb.Version > 0 {
		ac.pb = &pb
	}
}

// filteredShop: the shop as this account's planner may see it, and what the filter took out.
func (ac *Acct) filteredShop(s model.ShopSnapshot) (model.ShopSnapshot, model.Playbook, []model.Excluded) {
	pb := ac.playbook()
	f, ex := pb.Filter(s)
	return f, pb, ex
}

// diffPlaybook: the owner-readable list of what changed (for the log).
func diffPlaybook(a, b model.Playbook) []string {
	ja, jb := map[string]any{}, map[string]any{}
	x, _ := json.Marshal(a)
	y, _ := json.Marshal(b)
	_ = json.Unmarshal(x, &ja)
	_ = json.Unmarshal(y, &jb)
	var out []string
	for k, v := range jb {
		if k == "updated_at" || k == "version" {
			continue
		}
		if fmt.Sprint(ja[k]) != fmt.Sprint(v) {
			s := fmt.Sprint(v)
			if len(s) > 160 {
				s = s[:160] + "…"
			}
			out = append(out, k+" → "+s)
		}
	}
	sort.Strings(out)
	return out
}

func (s *server) playbookAPI(w http.ResponseWriter, r *http.Request, ac *Acct) {
	switch r.Method {
	case http.MethodGet:
	case http.MethodPut:
		var in struct {
			model.Playbook
			Reset bool `json:"reset"`
		}
		cur := ac.playbook()
		in.Playbook = cur // fields not sent keep their value
		if err := readJSON(r, &in, 256<<10); err != nil {
			jsonErr(w, 400, err.Error())
			return
		}
		next := in.Playbook
		if in.Reset {
			next = model.DefaultPlaybook()
		}
		next = next.Normalize()
		next.UpdatedAt, next.Version = s.app.now().UTC(), cur.Version+1
		ch := diffPlaybook(cur, next)
		ac.mu.Lock()
		ac.pb = &next
		ac.stateJSON = nil
		ac.mu.Unlock()
		if err := ac.st.Save("playbook", next); err != nil {
			jsonErr(w, 500, err.Error())
			return
		}
		what := "playbook saved"
		if in.Reset {
			what = "playbook reset to the defaults"
		}
		ac.st.Append("config", fmt.Sprintf("%s (v%d): %s — applies at the next cycle", what, next.Version, strings.Join(ch, "; ")), map[string]any{"changes": ch, "playbook": next})
		if cur.Knobs.Activity() != next.Knobs.Activity() { // the activity level: the job queue follows at once (volume.go)
			ac.cadenceNow()
		}
	default:
		jsonErr(w, 405, "GET or PUT")
		return
	}
	a := s.app
	a.mu.RLock()
	shop := a.shopSnap
	a.mu.RUnlock()
	_, pb, ex := ac.filteredShop(shop)
	writeJSON(w, map[string]any{"account": ac.id, "playbook": pb, "defaults": model.DefaultPlaybook().Normalize(), "excluded": ex,
		"products": len(shop.Products), "allowed": len(shop.Products) - len(ex)})
}

// ── first seen: the newness signal ──

// stampFirstSeen: the day the engine first saw each product id in the feed (kept app-wide, never moved).
func (a *App) stampFirstSeen(snap *model.ShopSnapshot) {
	today := a.now().UTC().Format("2006-01-02")
	seen := map[string]string{}
	_ = a.st.Load("firstseen", &seen)
	changed := false
	for i := range snap.Products {
		id := snap.Products[i].ID
		d, ok := seen[id]
		if !ok {
			d, seen[id], changed = today, today, true
		}
		snap.Products[i].FirstSeen = d
	}
	if changed {
		_ = a.st.Save("firstseen", seen)
	}
}
