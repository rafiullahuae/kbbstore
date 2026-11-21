package main

// regen.go — the owner, 21 Nov: "in approvals also i need re-schedule or re-generate, which reset and ready for
// re-generation" / "generate should give options, the ad copy, the creatives or complete".
//
// POST /api/jobs/{id}/regenerate {what}: on an occasion package that has not gone live yet.
//   copy       every ad of the package gets new words now (Claude's copy when the API mode is on, else the
//              benefit-first template), with today's selling price; the pictures stay; same library ids
//   creatives  new designs made on his Max plan (a fresh creation request); when they are saved they REPLACE the
//              package's ads (attachRegen); the words stay until then
//   all        both
// Every choice puts the package back to "waiting for your approval": nothing regenerated goes live unseen.
// Reschedule is the existing PUT /api/jobs/{id} {when}.

import (
	"context"
	"errors"
	"fmt"
	"time"

	"kaa/brain"
)

func (ac *Acct) regenerate(ctx context.Context, j *Job, what string, now time.Time) error {
	if j.Kind != "occasion" || j.Occasion == nil {
		return errors.New("regenerate works on an occasion package")
	}
	if j.Status != "planned" && j.Status != "approved" && j.Status != "waiting" {
		return fmt.Errorf("this package already ran (%s): make new ads with Create with Claude instead", j.Status)
	}
	if what != "copy" && what != "creatives" && what != "all" {
		return errors.New("what: copy, creatives or all")
	}
	if what == "creatives" || what == "all" {
		ac.app.mu.RLock()
		eb, ok := ac.app.brain.(*brain.Engine)
		ac.app.mu.RUnlock()
		if !ok {
			return errors.New("this brain makes no designs")
		}
		rid, err := eb.OccasionRegen(ac.id, j.Occasion.Name+" ads", j.Occasion.NameAR, ownerCopyBrief(ac.playbook(), j.Why), j.ProductIDs)
		if err != nil {
			return err
		}
		j.CreationReq = rid
		j.logf(now, "new designs requested from Claude on your Max plan (%s): they replace this package's ads when saved", rid)
	}
	if what == "copy" || what == "all" {
		n, err := ac.regenCopy(ctx, j)
		if err != nil {
			return err
		}
		j.logf(now, "new ad copy for %d ad(s), with today's prices", n)
	}
	if j.Status == "approved" {
		j.Status = "planned"
	}
	j.Rejected, j.Waiting, j.RaiseTo = nil, "", 0
	j.logf(now, "regenerated (%s): waiting for your approval again", what)
	return nil
}

// regenCopy: new words for every ad of the package, in place (same ids, same pictures).
func (ac *Acct) regenCopy(ctx context.Context, j *Job) (int, error) {
	rb := ac.newRebuilder(ctx, ac.Pending())
	pb := *rb.in.Playbook
	prods := map[string]int{}
	for i, p := range rb.in.Shop.Products {
		prods[p.ID] = i
	}
	n := 0
	for _, cid := range j.Creatives {
		c, _, err := ac.creatives.Get(cid)
		if err != nil || len(c.ProductIDs) == 0 {
			continue
		}
		i, ok := prods[c.ProductIDs[0]]
		if !ok {
			continue
		}
		p := rb.in.Shop.Products[i]
		h, t := fallbackCopy(p, c.Lang, pb)
		if cp, ok := rb.copyOf(p)[c.Lang]; ok && cp[0] != "" && cp[1] != "" {
			h, t = cp[0], cp[1]
		}
		// the package has not gone live (regenerate checks its status): no live ad to swap, the new words upload
		// with it when it is approved
		if _, _, err := ac.creatives.SetCopy(cid, h, t); err != nil {
			return n, err
		}
		n++
	}
	return n, nil
}

// attachRegen: designs saved for a package's regenerate request replace its ads (the feed 4:5 picture of each
// language and idea; 1:1 when an idea has no 4:5). Run before the packages are shown and at every cycle.
func (ac *Acct) attachRegen() {
	ac.app.mu.RLock()
	eb, ok := ac.app.brain.(*brain.Engine)
	ac.app.mu.RUnlock()
	if !ok {
		return
	}
	js := ac.jobSet()
	changed := false
	now := ac.app.now()
	for i := range js.Jobs {
		j := &js.Jobs[i]
		if j.Kind != "occasion" || j.CreationReq == "" || !open(j.Status) {
			continue
		}
		s := eb.SessionByRequest(ac.id, j.CreationReq)
		if s == nil || len(s.Saved) == 0 {
			continue
		}
		lib := map[string]bool{}
		for _, c := range ac.creatives.all() {
			if c.Media == "png" {
				lib[c.ID] = true
			}
		}
		var pick []string
		for _, want := range []string{"-4x5", "-1x1"} {
			for _, id := range s.Saved {
				if lib[id] && len(id) > 4 && id[len(id)-4:] == want {
					pick = append(pick, id)
				}
			}
			if len(pick) > 0 {
				break
			}
		}
		if len(pick) == 0 {
			continue
		}
		if len(pick) > 12 {
			pick = pick[:12]
		}
		j.Creatives, j.Rejected, j.CreationReq = pick, nil, ""
		j.logf(now, "%d new designed ad(s) from Claude attached; waiting for your approval", len(pick))
		changed = true
	}
	if changed {
		ac.saveJobs(js)
	}
}
