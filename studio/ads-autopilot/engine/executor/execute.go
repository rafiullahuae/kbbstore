package executor

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"net/url"
	"strconv"
	"strings"
	"sync"
	"time"

	"kaa/meta"
	"kaa/model"
)

// CreativeStore: the creative library in the data folder (engine side).
type CreativeStore interface {
	Get(id string) (model.Creative, []byte, error) // the creative and its media bytes (png or mp4)
	Put(c model.Creative) error
}

type Executor struct {
	Meta      *meta.Client
	Act       func() string             // act_…
	Links     func() model.AccountLinks // the account's Page / Instagram / pixel / catalog / WhatsApp
	Ledger    *Ledger
	Creatives CreativeStore
	Log       func(kind, summary string, data any)
	Persist   func(*Ledger)
	Safety    func(ctx context.Context) error // the Meta-side safety net, run after any write

	mu sync.Mutex // one batch at a time
}

type Result struct {
	ProposalID string   `json:"proposal_id"`
	Kind       string   `json:"kind"`
	Target     string   `json:"target"`
	Key        string   `json:"key"`
	Status     string   `json:"status"` // done | refused | shadow | failed | duplicate
	Decision   Decision `json:"decision"`
	MetaID     string   `json:"meta_id,omitempty"`
	Error      string   `json:"error,omitempty"`
	Steps      []string `json:"steps,omitempty"`
}

var bidStrategies = map[string]bool{"LOWEST_COST_WITHOUT_CAP": true, "COST_CAP": true, "LOWEST_COST_WITH_BID_CAP": true, "LOWEST_COST_WITH_MIN_ROAS": true}

func (x *Executor) log(kind, summary string, data any) {
	if x.Log != nil {
		x.Log(kind, summary, data)
	}
}

// Run checks and (when allowed) executes a batch of proposals in order. env is the state the checks see; it is
// updated as the batch goes (new objects, budgets committed, ads activated) so proposal 5 is checked against what
// proposals 1-4 did.
func (x *Executor) Run(ctx context.Context, env Env, props []model.Proposal) []Result {
	x.mu.Lock()
	defer x.mu.Unlock()
	if env.Ledger == nil {
		env.Ledger = x.Ledger
	}
	env.Tree = cloneTree(env.Tree)
	var out []Result
	wrote := false
	for _, p := range props {
		if ctx.Err() != nil {
			break
		}
		r := x.one(ctx, &env, p)
		if r.Status == "done" {
			wrote = true
		}
		out = append(out, r)
	}
	if x.Persist != nil {
		x.Persist(x.Ledger)
	}
	if wrote && x.Safety != nil {
		if err := x.Safety(ctx); err != nil {
			x.log("alert", "safety net after changes: "+err.Error(), nil)
		}
	}
	return out
}

func (x *Executor) resolve(p model.Proposal) model.Proposal {
	q := p
	q.Params = map[string]any{}
	for k, v := range p.Params {
		q.Params[k] = v
	}
	if strings.HasPrefix(q.Target, "tmp:") {
		if r := x.Ledger.Resolve(q.Target); r != q.Target && p.Kind != model.KindCreateCampaign && p.Kind != model.KindCreateAdSet && p.Kind != model.KindCreateAd {
			q.Target = r
		}
	}
	for _, k := range []string{"campaign_id", "adset_id", "creative_id", "origin_audience_id", "product_set_id"} {
		if s, ok := q.Params[k].(string); ok && strings.HasPrefix(s, "tmp:") {
			q.Params[k] = x.Ledger.Resolve(s)
		}
	}
	for _, k := range []string{"custom_audiences", "excluded_custom_audiences"} {
		if xs, ok := q.Params[k].([]any); ok {
			out := make([]any, len(xs))
			for i, v := range xs {
				if s, ok := v.(string); ok && strings.HasPrefix(s, "tmp:") {
					v = x.Ledger.Resolve(s)
				}
				out[i] = v
			}
			q.Params[k] = out
		}
	}
	return q
}

func (x *Executor) one(ctx context.Context, env *Env, p0 model.Proposal) Result {
	key := Key(p0)
	r := Result{ProposalID: p0.ID, Kind: p0.Kind, Target: p0.Target, Key: key}
	x.log("proposal", fmt.Sprintf("%s %s", p0.Kind, p0.Target), p0)
	if e, ok := x.Ledger.Lookup(key); ok {
		r.Status, r.MetaID = "duplicate", e.MetaID
		r.Decision = Decision{OK: false, Stage: "idempotency", Reasons: []string{"already done at " + e.At.Format(time.RFC3339) + "; not repeated"}}
		x.log("check", "duplicate: "+p0.Kind+" "+p0.Target, r)
		return r
	}
	p := x.resolve(p0)
	if strings.HasPrefix(p.Target, "tmp:") && (p.Kind == model.KindPause || p.Kind == model.KindResume || p.Kind == model.KindSetBudget || p.Kind == model.KindSetBid || p.Kind == model.KindArchive) {
		r.Status, r.Decision = "refused", refuse("limits", "%s was never created", p.Target)
		x.log("check", "refused: "+p.Kind+" "+p.Target, r)
		return r
	}
	d := Check(p, *env)
	r.Decision = d
	x.log("check", fmt.Sprintf("%s %s: %s", p.Kind, p.Target, d.Stage), r)
	if !d.OK {
		r.Status = "refused"
		if d.Stage == "shadow" {
			r.Status = "shadow"
		}
		return r
	}
	metaID, steps, err := x.apply(ctx, env, p, key)
	r.Steps = steps
	if err != nil {
		r.Status, r.Error = "failed", err.Error()
		x.log("execution", fmt.Sprintf("FAILED %s %s: %v", p.Kind, p.Target, err), r)
		return r
	}
	r.Status, r.MetaID = "done", metaID
	x.Ledger.Record(key, LedgerEntry{Kind: p.Kind, Target: p.Target, MetaID: metaID, At: env.Now})
	if x.Persist != nil {
		x.Persist(x.Ledger) // at once: a crash after this point must not replay a create
	}
	x.log("execution", fmt.Sprintf("done %s %s → %s", p.Kind, p.Target, metaID), r)
	return r
}

func filsStr(aed float64) (string, error) {
	f, err := Fils(aed)
	if err != nil {
		return "", err
	}
	return strconv.FormatInt(f, 10), nil
}

func managedName(n string) string {
	n = strings.TrimSpace(n)
	if strings.HasPrefix(n, meta.ManagedPrefix) {
		return n
	}
	if n == "" {
		n = "Autopilot"
	}
	return meta.ManagedPrefix + " " + n
}

func jsonStr(v any) string {
	b, _ := json.Marshal(v)
	return string(b)
}

func (x *Executor) apply(ctx context.Context, env *Env, p model.Proposal, key string) (string, []string, error) {
	act := x.Act()
	if act == "" {
		return "", nil, errors.New("no ad account chosen")
	}
	now := env.Now
	switch p.Kind {
	case model.KindCreateCampaign:
		return x.createCampaign(ctx, env, act, p, key)
	case model.KindCreateAdSet:
		return x.createAdSet(ctx, env, act, p, key)
	case model.KindUploadCreative:
		return x.createCreative(ctx, act, p, key)
	case model.KindCreateAudience:
		return x.createAudience(ctx, act, p)
	case model.KindCreateLookalike:
		return x.createLookalike(ctx, act, p)
	case model.KindCreateProductSet:
		return x.createProductSet(ctx, p)

	case model.KindCreateAd:
		sid := str(p.Params, "adset_id")
		sn := find(env.Tree, sid)
		if sn == nil || sn.level != "adset" || !sn.campaign.Managed {
			return "", nil, fmt.Errorf("ad set %s is not one the app made", sid)
		}
		crID := str(p.Params, "creative_id")
		if strings.HasPrefix(crID, "tmp:") {
			return "", nil, fmt.Errorf("creative %s was never made (upload_creative first)", crID)
		}
		if x.Creatives != nil {
			if c, _, err := x.Creatives.Get(crID); err == nil {
				if c.MetaID == "" {
					return "", nil, fmt.Errorf("creative %s is not uploaded yet (upload_creative first)", crID)
				}
				crID = c.MetaID
			}
		}
		if crID == "" {
			return "", nil, errors.New("create_ad needs creative_id")
		}
		name := managedName(str(p.Params, "name"))
		id, err := x.Meta.Create(ctx, act, "ads", url.Values{"name": {name}, "adset_id": {sid}, "creative": {jsonStr(map[string]string{"creative_id": crID})}, "status": {"PAUSED"}}, key)
		if err != nil {
			return "", nil, err
		}
		if strings.HasPrefix(p.Target, "tmp:") {
			x.Ledger.MapTmp(p.Target, id)
		}
		sn.adset.Ads = append(sn.adset.Ads, model.Ad{ID: id, AdSetID: sid, CampaignID: sn.campaign.ID, Name: name, Status: "PAUSED", EffectiveStatus: "PAUSED", CreativeID: crID})
		steps := []string{"created PAUSED"}
		if v, ok := p.Params["activate"].(bool); ok && !v {
			return id, steps, nil
		}
		// PAUSED → ACTIVE is its own checked step, top down: campaign, ad set, ad
		for _, oid := range []string{sn.campaign.ID, sid, id} {
			n := find(env.Tree, oid)
			st := n.campaign.Status
			if n.level == "adset" {
				st = n.adset.Status
			} else if n.level == "ad" {
				st = n.ad.Status
			}
			if st == "ACTIVE" {
				continue
			}
			ap := model.Proposal{ID: p.ID + ":activate:" + oid, Kind: model.KindResume, Target: oid}
			d := Check(ap, *env)
			x.log("check", fmt.Sprintf("activate %s %s: %s", n.level, oid, d.Stage), d)
			if !d.OK {
				steps = append(steps, fmt.Sprintf("left PAUSED: %s %s (%s)", n.level, oid, strings.Join(d.Reasons, "; ")))
				return id, steps, nil
			}
			if err := x.Meta.Update(ctx, oid, url.Values{"status": {"ACTIVE"}}); err != nil {
				steps = append(steps, fmt.Sprintf("activation of %s %s failed: %v", n.level, oid, err))
				return id, steps, nil
			}
			x.Ledger.NoteEdit(oid, now, false, true)
			x.afterStatus(env, n, "ACTIVE")
			steps = append(steps, "activated "+n.level+" "+oid)
		}
		return id, steps, nil

	case model.KindPause, model.KindResume, model.KindArchive:
		status := map[string]string{model.KindPause: "PAUSED", model.KindResume: "ACTIVE", model.KindArchive: "ARCHIVED"}[p.Kind]
		n := find(env.Tree, p.Target)
		if err := x.Meta.Update(ctx, p.Target, url.Values{"status": {status}}); err != nil {
			return "", nil, err
		}
		x.Ledger.NoteEdit(p.Target, now, false, p.Kind == model.KindResume)
		if n != nil {
			x.afterStatus(env, n, status)
		}
		return p.Target, []string{"status " + status}, nil

	case model.KindSetBudget:
		b, _ := num(p.Params, "daily_budget")
		fs, err := filsStr(b)
		if err != nil {
			return "", nil, err
		}
		n := find(env.Tree, p.Target)
		was := isRunningNode(n)
		old := budgetOf(n)
		if err := x.Meta.Update(ctx, p.Target, url.Values{"daily_budget": {fs}}); err != nil {
			return "", nil, err
		}
		x.Ledger.NoteEdit(p.Target, now, true, true)
		if n.level == "campaign" {
			n.campaign.DailyBudgetAED = b
		} else {
			n.adset.DailyBudgetAED = b
		}
		_, _ = was, old // the tree itself now carries the new budget for the next check in this batch
		return p.Target, []string{"daily budget " + fs + " fils"}, nil

	case model.KindSetBid:
		b, _ := num(p.Params, "bid_amount")
		fs, err := filsStr(b)
		if err != nil {
			return "", nil, err
		}
		if err := x.Meta.Update(ctx, p.Target, url.Values{"bid_amount": {fs}}); err != nil {
			return "", nil, err
		}
		x.Ledger.NoteEdit(p.Target, now, false, true)
		if n := find(env.Tree, p.Target); n != nil && n.adset != nil {
			n.adset.BidAmountAED = b
		}
		return p.Target, []string{"bid " + fs + " fils"}, nil
	}
	return "", nil, fmt.Errorf("no executor for %s", p.Kind)
}

func (x *Executor) afterStatus(env *Env, n *node, status string) {
	switch n.level {
	case "campaign":
		n.campaign.Status, n.campaign.EffectiveStatus = status, status
	case "adset":
		n.adset.Status, n.adset.EffectiveStatus = status, status
	case "ad":
		if status == "ACTIVE" && n.ad.Status != "ACTIVE" {
			env.ActivatedNow++
		}
		n.ad.Status, n.ad.EffectiveStatus = status, status
	}
}

func b64(b []byte) string { return base64.StdEncoding.EncodeToString(b) }

func cloneTree(t []model.Campaign) []model.Campaign {
	b, _ := json.Marshal(t)
	var out []model.Campaign
	_ = json.Unmarshal(b, &out)
	return out
}
