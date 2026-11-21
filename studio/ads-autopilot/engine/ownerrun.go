package main

// ownerrun.go — the owner, 21 Nov: "I pressed Run on two routines, what will happen next? where i can see the
// status of it, in the ad account i can not see any those ads".
//
// Until 0.6.4 "Run it" only marked the creatives approved, and the next cycle's planner was TOLD to prefer them:
// nothing made it happen and nothing showed whether it did. Now "Run it" makes a job, "Run your ads made with
// Claude", which puts exactly those ads live in the prospecting ad set (upload, ad, switched on), inside his limits
// (room by rotating the weakest ad out when Max active ads is reached, slotsFor), and shows on Plan → Upcoming
// jobs with every step. It starts at once in Auto and in Practice (he approved it by pressing Run it); Off waits.
// Creatives approved before this version and not live yet get the same job at the next cycle (ownerBacklog).

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"sort"
	"strings"
	"time"

	"kaa/model"
)

const msgOwnerRunDone = "every ad of it is already on Meta"

// liveMeta: the Meta creative ids that an ad in the account shows (any status: it exists on Meta).
func liveMeta(tree []model.Campaign) map[string]bool {
	m := map[string]bool{}
	for _, c := range tree {
		for _, s := range c.AdSets {
			for _, a := range s.Ads {
				if a.CreativeID != "" {
					m[a.CreativeID] = true
				}
			}
		}
	}
	return m
}

func ownerRunID(ids []string) string {
	s := append([]string(nil), ids...)
	sort.Strings(s)
	h := sha256.Sum256([]byte(strings.Join(s, ",")))
	return "run-" + hex.EncodeToString(h[:])[:10]
}

// queueOwnerRun: the job for creatives he pressed Run it on. Returns its id ("" when every one is already on Meta).
func (ac *Acct) queueOwnerRun(ids []string, why string) string {
	if len(ids) == 0 {
		return ""
	}
	if len(ids) > 12 {
		ids = ids[:12]
	}
	now := ac.app.now()
	id := ownerRunID(ids)
	js := ac.jobSet()
	for _, j := range js.Jobs {
		if j.ID == id {
			return id // already queued (a second press does nothing new)
		}
	}
	j := Job{ID: id, Kind: "owner_run", Title: fmt.Sprintf("Run your %d ad(s) made with Claude", len(ids)), TitleAR: fmt.Sprintf("تشغيل %d إعلان صنعتِه مع Claude", len(ids)),
		Why: why, When: now.Add(-time.Minute), Status: "approved", // due in the cycle that queues it (its clock was read first)
		Creatives: ids, Source: "owner", Format: "image", UpdatedAt: now.UTC()}
	j.logf(now, "queued: %d ad(s) you pressed Run it on go into the prospecting ad set, inside your limits", len(ids))
	js.Jobs = append(js.Jobs, j)
	ac.saveJobs(js)
	return id
}

// ownerRunProposals: upload + ad for each of the job's creatives that is not on Meta yet.
func (ac *Acct) ownerRunProposals(in model.CycleInput, j Job, pid func(string) string) ([]model.Proposal, string) {
	live := liveMeta(in.Tree)
	rej := map[string]bool{}
	for _, r := range j.Rejected {
		rej[r] = true
	}
	lib := ac.creatives.all()
	var ps []model.Proposal
	for i, cid := range j.Creatives {
		if rej[cid] {
			continue
		}
		c, _, err := ac.creatives.Get(cid)
		if err != nil || (c.MetaID != "" && live[c.MetaID]) {
			continue
		}
		set := ac.prospectingSet(in, c.Lang)
		if set == "" {
			return nil, "needs the prospecting campaign first (the first launch: Approvals → First launch)"
		}
		up := model.Proposal{ID: pid(fmt.Sprintf("cr%d", i)), Kind: model.KindUploadCreative, Target: cid,
			Params: map[string]any{"creative_id": cid, "format": "image", "headline": c.Headline, "primary_text": c.PrimaryText}, Why: j.Title, Confidence: 0.7}
		if c.Media == "mp4" { // a video needs its cover: the 9:16 picture of the same design
			up.Params["format"] = "video"
			for _, x := range lib {
				if x.Media == "png" && x.Format == "9:16" && x.Lang == c.Lang && x.Headline == c.Headline {
					up.Params["thumbnail_creative_id"] = x.ID
					break
				}
			}
			if up.Params["thumbnail_creative_id"] == nil {
				continue // no cover in the library: the pictures of the set still run
			}
		}
		name := fmt.Sprintf("Made with Claude · %s %s · %s", strings.ToUpper(c.Lang), map[bool]string{true: "video", false: c.Format}[c.Media == "mp4"], shortTitleOf(c))
		ps = append(ps, up, model.Proposal{ID: pid(fmt.Sprintf("ad%d", i)), Kind: model.KindCreateAd, Target: fmt.Sprintf("tmp:%s-ad%d", j.ID, i),
			Params: map[string]any{"name": name, "adset_id": set, "creative_id": cid}, Why: j.Title, Confidence: 0.7})
	}
	if len(ps) == 0 {
		return nil, msgOwnerRunDone
	}
	return ps, ""
}

// ownerBacklog: creatives he approved (Run it) before 0.6.4 that are not on Meta and in no run job yet.
func (ac *Acct) ownerBacklog(in model.CycleInput) {
	live := liveMeta(in.Tree)
	queued := map[string]bool{}
	for _, j := range ac.jobSet().Jobs {
		if j.Kind == "owner_run" || j.Kind == "occasion" {
			for _, c := range j.Creatives {
				queued[c] = true
			}
		}
	}
	var ids []string
	for _, c := range ac.creatives.all() {
		if c.Approved && c.Media != "" && !queued[c.ID] && !(c.MetaID != "" && live[c.MetaID]) {
			ids = append(ids, c.ID)
		}
	}
	for len(ids) > 0 {
		n := len(ids)
		if n > 12 {
			n = 12
		}
		ac.queueOwnerRun(ids[:n], "You pressed Run it on these: they go live now (before this version Run it only marked them for the planner).")
		ids = ids[n:]
	}
}

// runOwnerJobNow: in Auto the job starts at once (every executor check, his limits), in the background.
func (ac *Acct) runOwnerJobNow(id string) {
	go func() {
		ctx, cancel := context.WithTimeout(context.Background(), 10*time.Minute)
		defer cancel()
		for i := 0; i < 20; i++ { // a cycle may hold the account: wait for it, then run
			_, code, err := ac.runJobNow(ctx, id)
			if err == nil || code != 409 || err != errBusy {
				if err != nil {
					ac.st.Append("alert", "Run your ads: "+err.Error(), map[string]string{"job": id})
				}
				return
			}
			time.Sleep(15 * time.Second)
		}
	}()
}
