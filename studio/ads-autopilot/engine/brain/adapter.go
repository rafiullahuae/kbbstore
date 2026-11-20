package brain

// Integration with LANE ENGINE (engine/model): New(d) is what engine/brain_wire.go
// returns. It converts the engine's contract types to the brain's working
// types and back, sends every Claude call through the engine's LLM client (key
// in the Keychain, usage logged there), keeps the brain's own state (rich
// research, trending products, videos, the last full cycle per account, wording
// settings) under DataDir/brain/, and serves the brain's extra routes under
// /api/brain/x/ (ServeBrain, mounted by brain_wire.go).

import (
	"context"
	"encoding/json"
	"fmt"
	"math"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"time"

	"kaa/model"
)

// Engine is the model.Brain (and model.Copywriter) the engine runs.
type Engine struct {
	B    *Brain
	deps model.BrainDeps
	dir  string
	mu   sync.Mutex
}

// New builds the brain for the engine. All Claude traffic goes through d.LLM.
func New(d model.BrainDeps) model.Brain {
	b := NewBrain("")
	b.Claude.LLM = d.LLM
	dir := filepath.Join(d.DataDir, "brain")
	_ = os.MkdirAll(dir, 0o700)
	return &Engine{B: b, deps: d, dir: dir}
}

func (e *Engine) log(kind, s string, data any) {
	if e.deps.Log != nil {
		e.deps.Log(kind, s, data)
	}
}

// ---------- persistence (small JSON files, 0600) ----------

func (e *Engine) path(acct, name string) string {
	if acct == "" {
		return filepath.Join(e.dir, name+".json")
	}
	return filepath.Join(e.dir, safeName(acct), name+".json")
}

var unsafe = regexp.MustCompile(`[^A-Za-z0-9_-]`)

func safeName(s string) string { return unsafe.ReplaceAllString(s, "_") }

func (e *Engine) load(acct, name string, v any) bool {
	b, err := os.ReadFile(e.path(acct, name))
	return err == nil && json.Unmarshal(b, v) == nil
}

func (e *Engine) save(acct, name string, v any) {
	p := e.path(acct, name)
	_ = os.MkdirAll(filepath.Dir(p), 0o700)
	b, _ := json.Marshal(v)
	tmp := p + ".tmp"
	if os.WriteFile(tmp, b, 0o600) == nil {
		_ = os.Rename(tmp, p)
	}
}

// AccountSettings: brain-owned per-account settings (Settings -> Limits -> Ad wording rules).
type AccountSettings struct {
	AdWording     string `json:"ad_wording"`     // strict | balanced | relaxed
	PromoApproved bool   `json:"promo_approved"` // an authorised promotion is running
	// Creation (creation.go): "with_me" (default: the owner creates with Claude on his Max plan; the app notifies
	// him) | "api" (the autopilot writes new ads itself through the API, using credits). FallbackHours > 0: a request
	// he has not started within that many hours is created through the API after all (OFF by default, the owner).
	CreationMode  string `json:"creation_mode"`
	FallbackHours int    `json:"fallback_hours"`
}

func (e *Engine) settings(acct string) AccountSettings {
	s := AccountSettings{AdWording: Balanced}
	e.load(acct, "settings", &s)
	if s.AdWording != Strict && s.AdWording != Relaxed {
		s.AdWording = Balanced
	}
	normCreation(&s)
	return s
}

// Wording: the owner's ad-wording rule. The engine's /api/limits (Limits.Wording) is the source; the brain's own
// settings file is kept only as the fallback for an account whose limits do not carry it yet.
func Wording(l model.Limits, st AccountSettings) string {
	switch l.Wording {
	case Strict, Balanced, Relaxed:
		return l.Wording
	}
	return st.AdWording
}

// TrendingProductIDs: the shop's products that are trending now (the engine's first launch picks them first).
func (e *Engine) TrendingProductIDs() []string { return e.trending().Selling }

// ---------- model.Brain ----------

func (e *Engine) Cycle(ctx context.Context, in model.CycleInput) (model.CycleOutput, error) {
	acct := in.Account.ID
	bin := e.FromModel(in)
	b := e.B.clone() // per-call copy: settings differ per account
	st := e.settings(acct)
	b.PromoApproved = st.PromoApproved
	if in.Playbook != nil {
		b.Rules, b.Econ = withKnobs(b.Rules, b.Econ, in.Playbook.Knobs)
		if !in.Playbook.OffersAllowed { // the owner's "Offers: not allowed" beats the promotion switch
			b.PromoApproved = false
		}
	}
	b.DeferCopy = st.CreationMode == CreationWithMe
	// daily trending refresh rides on the cycle when it is stale (never blocks a cycle on failure)
	if b.hasClaude() && TrendingDue(e.trending(), bin.Now) {
		if tr, err := b.Trending(ctx, bin.Shop, bin.Now); err == nil {
			e.save("", "trending", tr)
			bin.Trending = &tr
			e.log("research", fmt.Sprintf("trending products: %d items, %d in the shop, %d to consider stocking", len(tr.Items), len(tr.Selling), len(tr.ConsiderStocking)), nil)
		}
	}
	out, err := b.Cycle(ctx, bin)
	if err != nil {
		return model.CycleOutput{}, err
	}
	// keep designs the UI has not rendered yet
	var prev CycleOutput
	if e.load(acct, "last_cycle", &prev) {
		done := map[string]bool{}
		_ = e.load(acct, "designs_done", &done)
		for _, d := range prev.Designs {
			if !done[d.ID] {
				out.Designs = append(out.Designs, d)
			}
		}
	}
	e.creationAfterCycle(ctx, acct, b, bin, &out, st)
	e.save(acct, "last_cycle", out)
	if len(out.Designs) > 0 { // archive: the library shows each creative from its design
		arch := []DesignSpec{}
		e.load(acct, "designs_archive", &arch)
		seen := map[string]bool{}
		for _, d := range arch {
			seen[d.ID] = true
		}
		for _, d := range out.Designs {
			if !seen[d.ID] {
				arch = append(arch, d)
			}
		}
		if len(arch) > 300 {
			arch = arch[len(arch)-300:]
		}
		e.save(acct, "designs_archive", arch)
	}
	return ToModel(out), nil
}

func (e *Engine) Research(ctx context.Context, in model.ResearchInput) (model.Research, error) {
	prev := e.richResearch()
	var focus []string
	if in.Focus != "" {
		focus = []string{in.Focus}
	}
	shop := shopFromModel(in.Shop)
	r, err := e.B.Research(ctx, ResearchInput{Now: in.Now, Shop: shop, Previous: prev, Focus: focus})
	if err == nil {
		e.save("", "research", r)
		u := e.B.LastResearchUsage()
		e.log("research", fmt.Sprintf("market research: %d notes (%d web searches, $%.3f)", len(r.Notes), u.WebSearches, u.CostUSD), nil)
	}
	// trending products ride on every research run (launch + schedule)
	if tr, terr := e.B.Trending(ctx, shop, in.Now); terr == nil {
		e.save("", "trending", tr)
	} else if err == nil {
		e.log("error", "trending products: "+terr.Error(), nil)
	}
	return ResearchToModel(r), err
}

func (e *Engine) Copy(ctx context.Context, in model.CopyInput) (model.CopyOutput, error) {
	res, err := e.B.Copy(ctx, CopyRequest{ProductIDs: in.ProductIDs, Angle: in.Angle, Brief: in.Brief}, shopFromModel(in.Shop), e.richResearch())
	if err != nil {
		return model.CopyOutput{}, err
	}
	var out model.CopyOutput
	for _, s := range res.Sets {
		out.Variants = append(out.Variants, model.Creative{ID: s.ID, Lang: s.Lang, Headline: s.Headline, PrimaryText: s.PrimaryText, CTA: s.CTA,
			ProductIDs: s.ProductIDs, Angle: s.Angle, Source: "brain", CreatedAt: time.Now()})
	}
	return out, nil
}

func (e *Engine) richResearch() Research {
	var r Research
	e.load("", "research", &r)
	return r
}

func (e *Engine) trending() TrendingReport {
	var t TrendingReport
	e.load("", "trending", &t)
	return t
}

// ---------- conversions ----------

var arRe = regexp.MustCompile(`[\x{0600}-\x{06FF}]`)

func langOf(name string) string {
	u := strings.ToUpper(name)
	switch {
	case arRe.MatchString(name), strings.HasPrefix(u, "AR"), strings.Contains(u, " AR "), strings.Contains(u, "ARABIC"), strings.Contains(u, "·AR"), strings.Contains(u, "| AR"):
		return "ar"
	case strings.HasPrefix(u, "EN"), strings.Contains(u, " EN "), strings.Contains(u, "ENGLISH"), strings.Contains(u, "| EN"):
		return "en"
	}
	return ""
}

func shopFromModel(s model.ShopSnapshot) ShopSnapshot {
	out := ShopSnapshot{OrdersSource: s.OrdersSource, FetchedAt: s.FetchedAt}
	for _, p := range s.Products {
		m := 0.0
		if p.MarginPct != nil {
			m = *p.MarginPct
		}
		out.Products = append(out.Products, Product{ID: p.ID, Title: p.Title, Brand: p.Brand, Type: p.Category, PriceAED: p.PriceAED, SalePrice: p.SaleAED,
			InStock: p.InStock, Stock: -1, MarginPct: m, Image: p.Image, URL: p.URL})
	}
	for _, o := range s.Orders {
		out.Orders = append(out.Orders, OrderDay{Date: o.Date, Orders: o.Orders, NetRevenue: o.NetRevenueAED, Refunds: o.RefundsAED})
	}
	return out
}

func parseT(s string) time.Time {
	for _, f := range []string{time.RFC3339, "2006-01-02T15:04:05-0700", "2006-01-02"} {
		if t, err := time.Parse(f, s); err == nil {
			return t
		}
	}
	return time.Time{}
}

// FromModel: the engine's CycleInput -> the brain's.
func (e *Engine) FromModel(in model.CycleInput) CycleInput {
	acct := in.Account.ID
	st := e.settings(acct)
	st.AdWording = Wording(in.Limits, st)
	out := CycleInput{Now: in.Now, Account: Account{ID: acct, Name: in.Account.Name, Currency: in.Account.Currency, Timezone: in.Account.Timezone, SpendCapAED: in.Account.SpendCapAED},
		Limits: Limits{DailyAED: in.Limits.DailyAED, MonthlyAED: in.Limits.MonthlyAED, MaxBudgetStepPct: in.Limits.MaxBudgetStepPct, CooldownHours: in.Limits.CooldownHours,
			MaxActiveAds: in.Limits.MaxActiveAds, Countries: in.Limits.Countries, Autopilot: in.Limits.Autopilot, AdWording: st.AdWording},
		Shop: shopFromModel(in.Shop)}
	lib := map[string]string{}
	for _, c := range in.Creatives {
		out.Creatives = append(out.Creatives, Creative{ID: c.ID, Lang: c.Lang, Format: c.Format, Headline: c.Headline, PrimaryText: c.PrimaryText, CTA: c.CTA, Link: c.Link,
			ProductIDs: c.ProductIDs, Angle: c.Angle, Source: c.Source, CreatedAt: c.CreatedAt, LintOK: true, Approved: c.Approved})
		lib[c.ID] = c.Lang
		if c.MetaID != "" {
			lib[c.MetaID] = c.Lang
		}
	}
	for _, c := range in.Tree {
		mc := Campaign{ID: c.ID, Name: c.Name, Status: c.Status, Objective: c.Objective, DailyBudgetAED: c.DailyBudgetAED, MadeByApp: c.Managed, CreatedAt: parseT(c.CreatedTime)}
		for _, s := range c.AdSets {
			ms := AdSet{ID: s.ID, CampaignID: s.CampaignID, Name: s.Name, Status: s.Status, DailyBudgetAED: s.DailyBudgetAED, BidAmountAED: s.BidAmountAED,
				Countries: s.Countries, Language: langOf(s.Name), LearningStage: s.LearningStage}
			for _, a := range s.Ads {
				var fb []string
				for k, v := range a.ReviewFeedback {
					fb = append(fb, k+": "+v)
				}
				ms.Ads = append(ms.Ads, Ad{ID: a.ID, AdSetID: a.AdSetID, Name: a.Name, Status: a.Status, EffectiveState: a.EffectiveStatus, CreativeID: a.CreativeID,
					Language: firstNonEmpty(lib[a.CreativeID], langOf(a.Name)), ReviewFeedback: strings.Join(fb, "; ")})
			}
			mc.AdSets = append(mc.AdSets, ms)
		}
		out.Tree = append(out.Tree, mc)
	}
	conv := func(r model.MetricRow, level string) MetricRow {
		return MetricRow{Date: r.Date, Level: level, ObjectID: r.ObjectID, SpendAED: r.SpendAED, Impressions: r.Impressions, Reach: r.Reach, Clicks: r.Clicks,
			Purchases: int64(math.Round(r.Purchases)), RevenueAED: r.PurchaseValueAED, Frequency: r.Frequency, Settling: r.Settling}
	}
	for _, r := range in.Metrics {
		out.Metrics = append(out.Metrics, conv(r, r.Level))
	}
	for _, r := range in.LongHistory {
		out.Metrics = append(out.Metrics, conv(r, "history"))
	}
	for _, h := range in.History {
		var d map[string]any
		_ = json.Unmarshal(h.Data, &d)
		obj := ""
		for _, k := range []string{"target", "object", "id"} {
			if s, ok := d[k].(string); ok && s != "" {
				obj = s
				break
			}
		}
		out.History = append(out.History, LogEntry{ID: fmt.Sprint(h.Seq), Time: h.At, Kind: h.Kind, Object: obj, Summary: h.Summary})
	}
	out.Research = e.richResearch()
	if len(out.Research.Notes) == 0 {
		out.Research = researchFromModel(in.Research)
	}
	var vids []VideoAsset
	e.load(acct, "videos", &vids)
	out.Videos = vids
	tr := e.trending()
	if !tr.UpdatedAt.IsZero() {
		out.Trending = &tr
	}
	if len(in.Learnings) > 0 {
		out.PortfolioLearnings = in.Learnings
	}
	if in.Playbook != nil {
		pb := *in.Playbook
		out.Playbook = &pb
		out.Ranked = RankFor(out, in.Shop, pb, feedBaseline(in.Shop), nil)
	}
	out.JobNotes = in.JobNotes
	return out
}

// ResearchToModel / researchFromModel: the rich notes (AR + EN, angles,
// several sources) folded into the engine's simpler shape and back.
func ResearchToModel(r Research) model.Research {
	out := model.Research{UpdatedAt: r.UpdatedAt}
	seen := map[string]bool{}
	for _, n := range r.Notes {
		src := ""
		for _, s := range n.Sources {
			if src == "" {
				src = s.URL
			}
			if !seen[s.URL] {
				seen[s.URL] = true
				out.Sources = append(out.Sources, s.URL)
			}
		}
		body := n.Body
		if n.BodyAR != "" {
			body += "\n\n" + n.TitleAR + ": " + n.BodyAR
		}
		note := model.Note{Date: n.Date.Format("2006-01-02"), Title: n.Title, Body: body, Source: src}
		switch n.Topic {
		case "competitor":
			out.Competitors = append(out.Competitors, note)
		case "arabic_audience":
			out.ArabicInsights = append(out.ArabicInsights, note)
		default:
			out.Trends = append(out.Trends, note)
		}
		for _, a := range n.Angles {
			out.Angles = append(out.Angles, model.Note{Date: note.Date, Title: a, Body: "from: " + n.Title, Source: src})
		}
	}
	return out
}

func researchFromModel(r model.Research) Research {
	out := Research{UpdatedAt: r.UpdatedAt}
	add := func(topic string, ns []model.Note) {
		for i, n := range ns {
			d := parseT(n.Date)
			note := ResearchNote{ID: fmt.Sprintf("%s-%d", topic, i), Topic: topic, Title: n.Title, Body: n.Body, Date: d, Strength: 0.5}
			if n.Source != "" {
				note.Sources = []Source{{URL: n.Source}}
			}
			out.Notes = append(out.Notes, note)
		}
	}
	add("trend", r.Trends)
	add("competitor", r.Competitors)
	add("arabic_audience", r.ArabicInsights)
	return out
}

// formatToEngine: the brain's format ids -> upload_creative.format.
var formatToEngine = map[string]string{"single_image": "image", "single_video": "video", "reels_stories": "video", "carousel": "carousel", "collection": "collection",
	"catalog": "catalog", "flexible": "flexible", "click_to_whatsapp": "image", "click_to_messenger": "image", "whatsapp_status": "video", "partnership": "video"}

func tmpID(s string) string {
	if strings.HasPrefix(s, "new:") {
		return "tmp:" + strings.TrimPrefix(s, "new:")
	}
	return s
}

// ToModel: the brain's CycleOutput -> the engine's (plan as steps; params in
// the executor's names, AED). The full output stays in DataDir/brain for the UI.
func ToModel(o CycleOutput) model.CycleOutput {
	pl := model.Plan{UpdatedAt: o.Plan.GeneratedAt, Summary: strings.TrimSpace(o.Plan.Summary + "\n" + o.Plan.SummaryAR)}
	for _, t := range o.Plan.Tests {
		pl.Steps = append(pl.Steps, model.PlanStep{When: "batch day", Title: fmt.Sprintf("%s test: %s", strings.ToUpper(t.Lang), t.Name),
			Detail: strings.TrimSpace(fmt.Sprintf("%s · angle %s · %s · %s · %s", t.Hypothesis, t.Angle, t.Format, t.Audience, t.Template)),
			Kind:   "test", Date: o.BatchDate, Lang: t.Lang, Format: t.Format, Angle: t.Angle, ProductIDs: t.ProductIDs, Why: t.Hypothesis})
	}
	for _, v := range o.Verdicts { // a tiring ad gets a fresh concept on the next batch day
		if v.Verdict == "fatigued" && v.Level == "ad" {
			pl.Steps = append(pl.Steps, model.PlanStep{When: "batch day", Title: "Refresh a tiring ad", Detail: v.Why, Kind: "refresh", Date: o.BatchDate, Target: v.Object, Why: v.Why})
		}
	}
	for _, a := range o.Plan.Allocation {
		pl.Steps = append(pl.Steps, model.PlanStep{When: "daily", Title: fmt.Sprintf("%s: %.0f%% (AED %.0f/day)", a.Bucket, a.SharePct, a.DailyAED), Detail: a.Why})
	}
	for _, s := range o.Plan.Seasonal {
		pl.Steps = append(pl.Steps, model.PlanStep{When: s.PrepFrom, Title: s.Name + " " + s.NameAR, Detail: s.Action, Kind: "seasonal", Date: s.PrepFrom, Why: s.Action})
	}
	out := model.CycleOutput{Plan: pl, Report: o.Report}
	for _, p := range o.Proposals {
		q := model.Proposal{ID: p.ID, Kind: p.Kind, Target: tmpID(p.Target), Why: p.Why, Confidence: p.Confidence, Evidence: p.Evidence, Params: map[string]any{}}
		for k, v := range p.Params {
			q.Params[k] = v
		}
		pp := q.Params
		if v, ok := pp["daily_budget_aed"]; ok {
			pp["daily_budget"] = v
			delete(pp, "daily_budget_aed")
		}
		if v, ok := pp["bid_amount_aed"]; ok {
			pp["bid_amount"] = v
			delete(pp, "bid_amount_aed")
		}
		for _, k := range []string{"adset_id", "campaign_id", "creative_id"} {
			if s, ok := pp[k].(string); ok {
				pp[k] = tmpID(s)
			}
		}
		switch p.Kind {
		case "create_campaign":
			if pp["format"] == "catalog" {
				pp["type"] = "catalog"
			} else {
				pp["type"] = "sales"
			}
		case "create_adset":
			if _, ok := pp["advantage_audience"]; !ok {
				pp["advantage_audience"] = 1
			}
			switch pp["audience"] {
			case "women_gcc_ar", "women_uae_en", nil:
				pp["genders"] = []int{2}
				pp["age_range"] = []int{18, 45}
			}
			if _, ok := pp["destination"]; !ok {
				pp["destination"] = "website"
			}
			if pl, ok := pp["placements"].([]any); ok && len(pl) > 0 && pl[0] != "advantage_placements" {
				pp["placements"] = map[string]any{"publisher_platforms": []string{"facebook", "instagram"},
					"instagram_positions": []string{"reels", "story", "stream", "explore"}, "facebook_positions": []string{"facebook_reels", "story", "feed"}}
			} else {
				delete(pp, "placements") // Advantage+ placements
			}
		case "upload_creative":
			f, _ := pp["format"].(string)
			if pp["media"] == "video" {
				f = "single_video"
				// the owner's video goes up from the data folder (executor: video_path), with its Arabic copy
				if path, ok := pp["path"].(string); ok {
					pp["video_path"] = path
					delete(pp, "path")
				}
				vid := fmt.Sprint(pp["video_id"])
				for _, lang := range []string{"ar", "en"} {
					for _, cs := range o.CopySets {
						if cs.VideoID == vid && cs.Lang == lang && pp["primary_text"] == nil {
							pp["primary_text"], pp["headline"] = cs.PrimaryText, cs.Headline
						}
					}
				}
			}
			if ef, ok := formatToEngine[f]; ok {
				pp["format"] = ef
			} else if f == "" {
				pp["format"] = "image"
			}
		case "create_ad":
			if cr, ok := pp["creative_id"].(string); ok && strings.HasPrefix(cr, "video:") { // the upload of that video
				for _, u := range o.Proposals {
					if u.Kind == "upload_creative" && fmt.Sprint(u.Params["video_id"]) == strings.TrimPrefix(cr, "video:") {
						pp["creative_id"] = tmpID(u.Target)
					}
				}
			}
			if _, ok := pp["name"]; !ok {
				pp["name"] = "KAA: " + strings.TrimPrefix(p.Target, "new:")
			}
		}
		out.Proposals = append(out.Proposals, q)
	}
	if out.Proposals == nil {
		out.Proposals = []model.Proposal{}
	}
	return out
}
