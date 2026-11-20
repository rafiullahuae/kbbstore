package brain

import (
	"context"
	"encoding/json"
	"fmt"
	"math"
	"sort"
	"strings"
	"sync"
	"time"
)

// Brain implements the contract's interface:
//
//	Cycle(ctx, CycleInput) (CycleOutput, error)
//	Research(ctx, ResearchInput) (Research, error)
//
// plus Copy (POST /api/brain/copy). It NEVER calls Meta: it returns
// Proposals; the engine's executor decides.
type Brain struct {
	Claude        *Claude
	Econ          EconConfig
	Rules         RulesConfig
	PlannerModel  string
	SmallModel    string
	CopyModel     string
	PromoApproved bool // owner-confirmed authorised promotion (Settings); unlocks discount wording
	MaxCopyPerRun int
	// DeferCopy: creation mode "with me in Claude (Max)" -- the planner's copy requests are NOT written through the
	// API; they come back as CycleOutput.CreationNeeded and the adapter turns them into Creation requests.
	DeferCopy bool

	mu           sync.Mutex
	lastResearch Usage
}

func NewBrain(apiKey string) *Brain {
	return &Brain{
		Claude: &Claude{APIKey: apiKey, Fallback: true}, Econ: DefaultEcon(), Rules: DefaultRules(),
		PlannerModel: ModelPlanner, SmallModel: ModelSmall, CopyModel: ModelPlanner, MaxCopyPerRun: 3,
	}
}

func (b *Brain) hasClaude() bool {
	return b.Claude != nil && (b.Claude.APIKey != "" || b.Claude.LLM != nil)
}

// Cycle: rules first (always), then the planner (when Claude is connected).
// If Claude is missing, refuses, or returns something unusable, the cycle
// still produces the rules layer's proposals and a rules-only plan; an LLM
// failure never blocks a pause.
func (b *Brain) Cycle(ctx context.Context, in CycleInput) (CycleOutput, error) {
	if in.Now.IsZero() {
		in.Now = time.Now()
	}
	if in.Profile == nil {
		p := BuildProfile(in.Account.ID, in)
		in.Profile = &p
	}
	econ := ComputeEconomics(b.Econ, in.Shop, in.Now)
	rr := Evaluate(in, econ, b.Rules)
	if in.Playbook != nil {
		languageSlots(&rr, in.Playbook.Languages)
	}
	out := CycleOutput{Verdicts: rr.Verdicts, Economics: econ, Proposals: rr.Proposals, Model: "rules-only", Profile: in.Profile}
	out.Plan = b.rulesPlan(in, rr)
	out.BatchDate = nextWeekday(in.Now, b.Rules.BatchWeekday)

	var llmErr error
	var nEN, nAR string
	if b.hasClaude() {
		nEN, nAR, llmErr = b.plan(ctx, in, rr, &out)
	}
	if llmErr != nil {
		out.Dropped = append(out.Dropped, "planner: "+llmErr.Error()+" -- rules-only cycle")
	}
	out.Report = b.report(in, rr, out, nEN, nAR, llmErr)
	// JSON arrays, never null: the UI and the engine iterate them directly
	if out.Verdicts == nil {
		out.Verdicts = []Verdict{}
	}
	if out.Proposals == nil {
		out.Proposals = []Proposal{}
	}
	if out.Dropped == nil {
		out.Dropped = []string{}
	}
	return out, nil
}

// ---------- planner ----------

type plannerAnswer struct {
	SummaryEN string `json:"summary_en"`
	SummaryAR string `json:"summary_ar"`
	Tests     []struct {
		Name       string   `json:"name"`
		Hypothesis string   `json:"hypothesis"`
		Lang       string   `json:"lang"`
		Angle      string   `json:"angle"`
		ProductIDs []string `json:"product_ids"`
		Template   string   `json:"template"`
		BudgetAED  float64  `json:"budget_aed"`
		Format     string   `json:"format"`
		Audience   string   `json:"audience"`
	} `json:"tests"`
	Allocation []struct {
		Bucket   string  `json:"bucket"`
		SharePct float64 `json:"share_pct"`
		Why      string  `json:"why"`
	} `json:"allocation"`
	Watch     []string `json:"watch"`
	Proposals []struct {
		Kind       string         `json:"kind"`
		Target     string         `json:"target"`
		Params     map[string]any `json:"params"`
		Why        string         `json:"why"`
		Confidence float64        `json:"confidence"`
		Evidence   []string       `json:"evidence"`
	} `json:"proposals"`
	CopyRequests []CopyRequest `json:"copy_requests"`
	ReportEN     string        `json:"report_en"`
	ReportAR     string        `json:"report_ar"`
	Learnings    []string      `json:"learnings"`
}

func (b *Brain) plan(ctx context.Context, in CycleInput, rr RulesResult, out *CycleOutput) (string, string, error) {
	payload := plannerInput(in, rr, b.PromoApproved)
	user, _ := json.Marshal(payload)
	req := Request{
		Purpose: "plan", Model: b.PlannerModel, MaxTokens: 16000, System: CachedSystem(PlannerSystem()),
		Messages: []Message{{Role: "user", Content: "Cycle input (computed by code; all numbers AED):\n" + string(user)}},
		Output:   JSONOutput(plannerSchema(), "medium"),
	}
	resp, err := b.Claude.Send(ctx, req)
	AddUsage(&out.Usage, b.PlannerModel, resp)
	if err != nil {
		return "", "", err
	}
	ans, perr := parseAnswer(resp)
	known := knownObjects(in)
	var props []Proposal
	var dropped []string
	if perr == nil {
		props, dropped = b.validate(ans, in, rr, known)
	}
	// One repair round: unparseable, or more than half the proposals invalid.
	if perr != nil || (len(ans.Proposals) > 0 && len(dropped)*2 > len(ans.Proposals)) {
		why := "Your answer was not valid JSON for the schema."
		if perr == nil {
			why = "These proposals failed the checks: " + strings.Join(dropped, " | ")
		}
		var prev []any
		for _, r := range resp.Raw {
			prev = append(prev, r)
		}
		req.Messages = append(req.Messages, Message{Role: "assistant", Content: prev},
			Message{Role: "user", Content: why + "\nReturn the complete corrected JSON. Drop any proposal you cannot fix."})
		resp2, err2 := b.Claude.Send(ctx, req)
		AddUsage(&out.Usage, b.PlannerModel, resp2)
		if err2 == nil {
			if a2, e2 := parseAnswer(resp2); e2 == nil {
				ans, perr = a2, nil
				props, dropped = b.validate(ans, in, rr, known)
			}
		}
	}
	if perr != nil {
		return "", "", perr
	}
	out.Model = b.PlannerModel
	props, pbDrop := checkPlaybook(props, in) // the owner's structured rules, checked on the answer in code
	dropped = append(dropped, pbDrop...)
	out.Dropped = append(out.Dropped, dropped...)
	out.Proposals = mergeProposals(out.Proposals, props)
	out.Learnings = clean(ans.Learnings, 8)
	b.applyPlan(&out.Plan, ans, in, rr)

	// Copy for the requested ideas, then a design spec for each linted set.
	reqs := ans.CopyRequests
	if len(reqs) > b.MaxCopyPerRun {
		reqs = reqs[:b.MaxCopyPerRun]
	}
	for i, cr := range reqs {
		cr.PairID = fmt.Sprintf("p%s-%d", in.Now.Format("060102"), i)
		cr.Wording = in.Limits.AdWording
		cr.Brief = OwnerBrief(in.Playbook, strings.TrimSpace(lessonsBrief(in.Lessons)+"\n"+cr.Brief))
		if in.Playbook != nil { // a copy request may only name products the playbook allows
			allowed := map[string]bool{}
			for _, p := range in.Shop.Products {
				allowed[p.ID] = true
			}
			var keep []string
			for _, id := range cr.ProductIDs {
				if allowed[id] {
					keep = append(keep, id)
				}
			}
			if len(cr.ProductIDs) > 0 && len(keep) == 0 {
				out.Dropped = append(out.Dropped, fmt.Sprintf("copy %d: only products your playbook does not allow", i))
				continue
			}
			cr.ProductIDs = keep
		}
		if cr.VideoID != "" {
			for j := range in.Videos {
				if in.Videos[j].ID == cr.VideoID {
					cr.Video = &in.Videos[j]
				}
			}
			if cr.Video == nil {
				out.Dropped = append(out.Dropped, fmt.Sprintf("copy %d: unknown video %s", i, cr.VideoID))
				continue
			}
		}
		if b.DeferCopy { // the owner makes these with Claude (Max); nothing is written through the API
			out.CreationNeeded = append(out.CreationNeeded, cr)
			continue
		}
		sets, designs, usage, err := b.copyFor(ctx, cr, in.Shop, in.Research)
		addUsageTotals(&out.Usage, usage)
		if err != nil {
			out.Dropped = append(out.Dropped, fmt.Sprintf("copy %d: %v", i, err))
			continue
		}
		out.CopySets = append(out.CopySets, sets...)
		out.Designs = append(out.Designs, designs...)
	}
	// "copy:<n>" creative ids resolve to the pair the copy step made. Deferred copy has no pair yet: those ads wait
	// for the owner's creation (it reaches the autopilot through the library as approved creatives).
	if b.DeferCopy {
		var keep []Proposal
		for _, p := range out.Proposals {
			if id, ok := p.Params["creative_id"].(string); ok && strings.HasPrefix(id, "copy:") {
				out.Dropped = append(out.Dropped, p.ID+": waits for the owner's creation in Claude")
				continue
			}
			keep = append(keep, p)
		}
		out.Proposals = keep
	}
	for i := range out.Proposals {
		if id, ok := out.Proposals[i].Params["creative_id"].(string); ok && strings.HasPrefix(id, "copy:") {
			var n int
			fmt.Sscanf(id, "copy:%d", &n)
			out.Proposals[i].Params["creative_pair_id"] = fmt.Sprintf("p%s-%d", in.Now.Format("060102"), n)
		}
	}
	out.Plan.Summary, out.Plan.SummaryAR = ans.SummaryEN, ans.SummaryAR
	return ans.ReportEN, ans.ReportAR, nil
}

func parseAnswer(r *Response) (plannerAnswer, error) {
	var a plannerAnswer
	switch r.StopReason {
	case "refusal":
		cat := ""
		if r.StopDetails != nil {
			cat = r.StopDetails.Category
		}
		return a, fmt.Errorf("model refused (%s)", cat)
	case "max_tokens":
		return a, fmt.Errorf("answer cut off at max_tokens")
	}
	t := strings.TrimSpace(r.Text())
	if err := json.Unmarshal([]byte(t), &a); err != nil {
		return a, fmt.Errorf("unparseable answer: %v", err)
	}
	return a, nil
}

type objInfo struct {
	managed             bool
	level, status, lang string
	budget, bid         float64
	parent              string
}

func knownObjects(in CycleInput) map[string]objInfo {
	m := map[string]objInfo{}
	for _, c := range in.Tree {
		m[c.ID] = objInfo{level: "campaign", status: c.Status, budget: c.DailyBudgetAED, managed: c.MadeByApp}
		for _, s := range c.AdSets {
			m[s.ID] = objInfo{level: "adset", status: s.Status, lang: s.Language, budget: s.DailyBudgetAED, bid: s.BidAmountAED, parent: c.ID, managed: c.MadeByApp}
			for _, a := range s.Ads {
				m[a.ID] = objInfo{level: "ad", status: a.Status, lang: firstNonEmpty(a.Language, s.Language), parent: s.ID, managed: c.MadeByApp}
			}
		}
	}
	return m
}

// validate turns the planner's proposals into contract Proposals, dropping
// anything the brain itself can already see is wrong. The executor checks
// everything again; this is so the plan the owner reads is honest.
func (b *Brain) validate(a plannerAnswer, in CycleInput, rr RulesResult, known map[string]objInfo) ([]Proposal, []string) {
	verd := map[string]Verdict{}
	for _, v := range rr.Verdicts {
		verd[v.Object] = v
	}
	lib := map[string]bool{}
	for _, c := range in.Creatives {
		lib[c.ID] = true
	}
	vids := map[string]VideoAsset{}
	for _, v := range in.Videos {
		vids[v.ID] = v
	}
	allowed := map[string]bool{}
	for _, k := range proposalKinds {
		allowed[k] = true
	}
	countries := map[string]bool{}
	for _, c := range in.Limits.Countries {
		countries[strings.ToUpper(c)] = true
	}
	step := in.Limits.MaxBudgetStepPct
	if step <= 0 {
		step = b.Rules.DefaultStepPct
	}
	newIDs := map[string]bool{}
	for _, p := range a.Proposals {
		if strings.HasPrefix(p.Target, "new:") {
			newIDs[p.Target] = true
		}
	}
	var out []Proposal
	var drop []string
	for i, p := range a.Proposals {
		bad := func(why string) { drop = append(drop, fmt.Sprintf("#%d %s %s: %s", i, p.Kind, p.Target, why)) }
		params := map[string]any{}
		for k, v := range p.Params {
			if v != nil {
				params[k] = v
			}
		}
		if !allowed[p.Kind] {
			bad("kind not allowed")
			continue
		}
		if strings.TrimSpace(p.Why) == "" {
			bad("no reason given")
			continue
		}
		info, exists := known[p.Target]
		isNew := strings.HasPrefix(p.Target, "new:")
		if strings.HasPrefix(p.Kind, "create_") || p.Kind == "upload_creative" {
			if !rr.LimitsSet {
				bad("no limits set: nothing launches")
				continue
			}
			if !isNew {
				bad("new objects need a new:<name> target")
				continue
			}
		} else if !exists {
			bad("unknown object id")
			continue
		}
		if exists && !info.managed {
			bad("belongs to the owner's own campaign; the app only changes what it made")
			continue
		}
		v := verd[p.Target]
		switch p.Kind {
		case "pause", "archive":
			if v.Verdict == "keep" && contains(v.Signals, "winner") {
				bad("the statistics say this is a winner")
				continue
			}
		case "resume":
			if v.Verdict == "kill" {
				bad("the rules just killed it")
				continue
			}
		case "set_budget":
			nb, ok := params["daily_budget_aed"].(float64)
			if !ok || nb <= 0 || math.IsNaN(nb) {
				bad("needs a positive daily_budget_aed")
				continue
			}
			if v.Verdict == "learning" || v.Verdict == "settling" {
				bad("object is learning/settling; no budget edits")
				continue
			}
			if info.budget > 0 && math.Abs(nb-info.budget)/info.budget > step/100+1e-9 {
				bad(fmt.Sprintf("change %.0f%% is over the %.0f%% step", math.Abs(nb-info.budget)/info.budget*100, step))
				continue
			}
			if nb > in.Limits.DailyAED && in.Limits.DailyAED > 0 {
				bad("above the daily limit")
				continue
			}
			params["from_aed"] = info.budget
			params["level"] = info.level
			if nb > info.budget {
				params["direction"] = "up"
			} else {
				params["direction"] = "down"
			}
		case "set_bid":
			if info.bid <= 0 {
				bad("ad set has no bid to change")
				continue
			}
		case "create_campaign":
			params["objective"] = "OUTCOME_SALES"
			nb, _ := params["daily_budget_aed"].(float64)
			if nb <= 0 || nb > in.Limits.DailyAED {
				bad("campaign budget missing or above the daily limit")
				continue
			}
		case "create_adset":
			if _, ok := params["audience"]; !ok {
				if params["lang"] == "en" {
					params["audience"] = "women_uae_en"
				} else {
					params["audience"] = "women_gcc_ar"
				}
			}
			if _, ok := params["placements"]; !ok {
				params["placements"] = []any{"advantage_placements"}
			}
			cid, _ := params["campaign_id"].(string)
			if _, ok := known[cid]; !ok && !newIDs[cid] {
				bad("create_adset needs an existing or new campaign_id")
				continue
			}
			if cs, ok := params["countries"].([]any); ok {
				okC := true
				for _, c := range cs {
					s, _ := c.(string)
					if len(countries) > 0 && !countries[strings.ToUpper(s)] {
						okC = false
					}
				}
				if !okC {
					bad("country outside the owner's list")
					continue
				}
			}
		case "upload_creative":
			if params["media"] == "video" {
				v, ok := vids[fmt.Sprint(params["video_id"])]
				if !ok {
					bad("unknown video_id")
					continue
				}
				if !videoRunnable(v) {
					bad("video not analysed or has a blocking issue")
					continue
				}
				if v.MetaVideoID != "" {
					bad("video already uploaded to Meta")
					continue
				}
				params["path"] = v.Path
			}
		case "create_ad":
			if !validFormat(params, in.Shop, &bad) {
				continue
			}
			as, _ := params["adset_id"].(string)
			if _, ok := known[as]; !ok && !newIDs[as] {
				bad("create_ad needs an existing or new adset_id")
				continue
			}
			if vv := verd[as]; vv.Verdict == "learning" {
				bad("ad set is learning; new ads wait")
				continue
			}
			cr, _ := params["creative_id"].(string)
			if strings.HasPrefix(cr, "video:") {
				if v, ok := vids[strings.TrimPrefix(cr, "video:")]; !ok || !videoRunnable(v) {
					bad("video creative unknown or blocked")
					continue
				}
			} else if !lib[cr] && !strings.HasPrefix(cr, "copy:") {
				bad("creative_id not in the library")
				continue
			}
			if !rr.BatchDay {
				bad("new ads go out on batch day only")
				continue
			}
		}
		conf := math.Max(0, math.Min(1, p.Confidence))
		out = append(out, Proposal{ID: fmt.Sprintf("c%s-%d", in.Now.Format("0102"), i), Kind: p.Kind, Target: p.Target,
			Params: params, Why: p.Why, Confidence: round2(conf), Evidence: clean(p.Evidence, 5)})
	}
	return out, drop
}

// mergeProposals keeps the rules layer's proposals and adds the planner's
// unless they touch the same object with the same kind, or a budget move
// would collide with a creative change on the same ad set the same day.
func mergeProposals(rules, llm []Proposal) []Proposal {
	seen := map[string]bool{}
	budgetTouched := map[string]bool{}
	for _, p := range rules {
		seen[p.Kind+"|"+p.Target] = true
		if p.Kind == "set_budget" {
			budgetTouched[p.Target] = true
		}
	}
	out := append([]Proposal{}, rules...)
	for _, p := range llm {
		if seen[p.Kind+"|"+p.Target] {
			continue
		}
		if p.Kind == "create_ad" {
			if as, _ := p.Params["adset_id"].(string); budgetTouched[as] {
				continue
			}
		}
		seen[p.Kind+"|"+p.Target] = true
		out = append(out, p)
	}
	return out
}

func (b *Brain) applyPlan(pl *Plan, a plannerAnswer, in CycleInput, rr RulesResult) {
	if len(a.Tests) > 0 {
		pl.Tests = nil
		for _, t := range a.Tests {
			if len(pl.Tests) >= 10 {
				break
			}
			lang := t.Lang
			if lang != "ar" && lang != "en" {
				lang = "ar"
			}
			pl.Tests = append(pl.Tests, PlanTest{Name: t.Name, Hypothesis: t.Hypothesis, Lang: lang, Angle: t.Angle,
				ProductIDs: t.ProductIDs, Template: oneOf(t.Template, templateIDs, "clean"), BudgetAED: round2(math.Max(0, t.BudgetAED)), Status: "planned",
				Format: oneOf(t.Format, formatIDs, "single_image"), Audience: oneOf(t.Audience, audienceIDs, "women_gcc_ar")})
		}
	}
	if len(a.Allocation) > 0 {
		var sum float64
		for _, x := range a.Allocation {
			sum += math.Max(0, x.SharePct)
		}
		if sum > 0 {
			pl.Allocation = nil
			for _, x := range a.Allocation {
				sh := math.Max(0, x.SharePct) / sum * 100
				pl.Allocation = append(pl.Allocation, Allocation{Bucket: x.Bucket, SharePct: round2(sh), DailyAED: round2(in.Limits.DailyAED * sh / 100), Why: x.Why})
			}
		}
	}
	pl.Watch = append(clean(a.Watch, 6), pl.Watch...)
}

// rulesPlan is the plan without Claude: allocation from the configured
// shares, tests from the free slots and the research angles, seasons from
// the calendar. The planner replaces tests/allocation when it answers.
func (b *Brain) rulesPlan(in CycleInput, rr RulesResult) Plan {
	y, w := in.Now.ISOWeek()
	pl := Plan{Week: fmt.Sprintf("%d-W%02d", y, w), GeneratedAt: in.Now, Seasonal: Upcoming(in.Now, 60)}
	test := b.Rules.TestSharePct
	ar := rr.ArabicShare
	pros := 100 - test
	pl.Allocation = []Allocation{
		{Bucket: "Arabic prospecting", SharePct: round2(pros * ar), Why: "Owner: Arabic audience first"},
		{Bucket: "English prospecting", SharePct: round2(pros * (1 - ar)), Why: "Residents and expats who shop in English"},
		{Bucket: "Creative tests", SharePct: test, Why: "New concepts every week; Meta rewards creative diversity"},
	}
	for i := range pl.Allocation {
		pl.Allocation[i].DailyAED = round2(in.Limits.DailyAED * pl.Allocation[i].SharePct / 100)
	}
	angles := topAngles(in.Research, 6)
	if len(in.Lessons) > 0 { // learn.go: ~80% of new concepts on winning angles, every 5th on an untested one
		if ex := exploreAngles(in.Lessons, angles, 10); len(ex) > 0 {
			angles = ex
		}
	}
	prods := pickProducts(in.Shop, in.Research, 6)
	slots := []string{}
	for i := 0; i < rr.NewAdSlots["ar"]; i++ {
		slots = append(slots, "ar")
	}
	for i := 0; i < rr.NewAdSlots["en"]; i++ {
		slots = append(slots, "en")
	}
	if len(slots) == 0 { // off batch day: show next batch's intent
		slots = []string{"ar", "ar", "en"}
	}
	for i, lang := range slots {
		angle := "K-beauty routine"
		if len(angles) > 0 {
			angle = angles[i%len(angles)]
		}
		var pids []string
		if len(prods) > 0 {
			pids = []string{prods[i%len(prods)].ID}
		}
		pl.Tests = append(pl.Tests, PlanTest{Name: fmt.Sprintf("%s test %d", strings.ToUpper(lang), i+1), Lang: lang, Angle: angle,
			ProductIDs: pids, Template: templateIDs[i%len(templateIDs)], Status: "planned",
			Hypothesis: "A distinct concept on this angle beats the account's current CPA."})
	}
	for _, v := range rr.Verdicts {
		if v.Verdict == "fatigued" || v.Verdict == "kill" {
			pl.Watch = append(pl.Watch, fmt.Sprintf("%s %s: %s", v.Level, v.Object, v.Why))
		}
	}
	pl.Watch = append(pl.Watch, rr.Alerts...)
	if len(pl.Watch) > 10 {
		pl.Watch = pl.Watch[:10]
	}
	pl.Summary = fmt.Sprintf("Week %s: %d tests planned (%.0f%% Arabic), target CPA AED %.0f (break-even %.0f).", pl.Week, len(pl.Tests), ar*100, rr.Econ.TargetCPA, rr.Econ.BreakEvenCPA)
	pl.SummaryAR = fmt.Sprintf("أسبوع %s: %d اختبارات مخطّطة (%.0f٪ بالعربية)، تكلفة الطلب المستهدفة %.0f درهم (نقطة التعادل %.0f).", pl.Week, len(pl.Tests), ar*100, rr.Econ.TargetCPA, rr.Econ.BreakEvenCPA)
	return pl
}

// ---------- report ----------

func (b *Brain) report(in CycleInput, rr RulesResult, out CycleOutput, nEN, nAR string, llmErr error) string {
	today := in.Now.Format("2006-01-02")
	y := in.Now.AddDate(0, 0, -1).Format("2006-01-02")
	var yd, d7 Agg
	from7 := in.Now.AddDate(0, 0, -7).Format("2006-01-02")
	for _, r := range in.Metrics {
		if r.Level != "campaign" {
			continue
		}
		if r.Date == y {
			yd.add(r)
		}
		if r.Date >= from7 && r.Date < today {
			d7.add(r)
		}
	}
	cnt := map[string]int{}
	for _, p := range out.Proposals {
		cnt[p.Kind]++
	}
	en := fmt.Sprintf("Yesterday: AED %.0f spent, %d purchases, ROAS %.2f. Last 7 days: AED %.0f, %d purchases, ROAS %.2f (target %.2f, break-even %.2f). Proposed: %d pauses, %d budget changes, %d new ads.",
		yd.Spend, yd.Purch, yd.ROAS(), d7.Spend, d7.Purch, d7.ROAS(), rr.Econ.TargetROAS, rr.Econ.BreakEvenROAS, cnt["pause"], cnt["set_budget"], cnt["create_ad"])
	ar := fmt.Sprintf("أمس: إنفاق %.0f درهم، %d طلبات، العائد على الإنفاق %.2f. آخر ٧ أيام: %.0f درهم، %d طلبات، العائد %.2f (المستهدف %.2f، نقطة التعادل %.2f). المقترح: إيقاف %d، تعديل ميزانية %d، إعلانات جديدة %d.",
		yd.Spend, yd.Purch, yd.ROAS(), d7.Spend, d7.Purch, d7.ROAS(), rr.Econ.TargetROAS, rr.Econ.BreakEvenROAS, cnt["pause"], cnt["set_budget"], cnt["create_ad"])
	if nEN == "" {
		nEN = out.Plan.Summary
		nAR = out.Plan.SummaryAR
		if llmErr != nil {
			nEN += " (Claude was not available this cycle; the statistics rules ran on their own.)"
			nAR += " (لم يكن Claude متاحاً في هذه الدورة؛ عملت قواعد الإحصاء وحدها.)"
		}
	}
	for _, a := range rr.Alerts {
		en += " Alert: " + a
	}
	return "EN\n" + en + "\n" + strings.TrimSpace(nEN) + "\n\nAR\n" + ar + "\n" + strings.TrimSpace(nAR)
}

// ---------- planner input ----------

func plannerInput(in CycleInput, rr RulesResult, promo bool) map[string]any {
	verd := map[string]Verdict{}
	for _, v := range rr.Verdicts {
		verd[v.Object] = v
	}
	var tree []map[string]any
	for _, c := range in.Tree {
		if c.Status == "ARCHIVED" || c.Status == "DELETED" {
			continue
		}
		var sets []map[string]any
		for _, s := range c.AdSets {
			var ads []map[string]any
			for _, a := range s.Ads {
				if a.Status == "ARCHIVED" || a.Status == "DELETED" {
					continue
				}
				v := verd[a.ID]
				ads = append(ads, map[string]any{"id": a.ID, "name": a.Name, "status": a.Status, "review": a.ReviewFeedback,
					"lang": firstNonEmpty(a.Language, s.Language), "creative_id": a.CreativeID, "verdict": v.Verdict, "why": v.Why,
					"spend": v.SpendAED, "purchases": v.Purch, "cpa": v.CPA, "roas": v.ROAS, "ctr": v.CTR, "p_beats_target": v.PWinTgt})
			}
			v := verd[s.ID]
			sets = append(sets, map[string]any{"id": s.ID, "name": s.Name, "status": s.Status, "daily_budget_aed": s.DailyBudgetAED,
				"lang": s.Language, "countries": s.Countries, "learning_stage": s.LearningStage, "verdict": v.Verdict, "why": v.Why, "ads": ads})
		}
		v := verd[c.ID]
		tree = append(tree, map[string]any{"id": c.ID, "name": c.Name, "status": c.Status, "objective": c.Objective,
			"daily_budget_aed": c.DailyBudgetAED, "made_by_app": c.MadeByApp, "verdict": v.Verdict, "adsets": sets})
	}
	var lib []map[string]any
	for i, c := range approvedFirst(in.Creatives) {
		if i >= 60 {
			break
		}
		lib = append(lib, map[string]any{"id": c.ID, "pair_id": c.PairID, "lang": c.Lang, "format": c.Format, "angle": c.Angle,
			"template": c.Template, "headline": c.Headline, "product_ids": c.ProductIDs, "owner_approved": c.Approved})
	}
	var notes []map[string]any
	for i, n := range in.Research.Notes {
		if i >= 15 {
			break
		}
		notes = append(notes, map[string]any{"topic": n.Topic, "title": n.Title, "angles": n.Angles, "date": n.Date.Format("2006-01-02")})
	}
	var hist []map[string]any
	var learned []string
	for i := len(in.History) - 1; i >= 0 && len(hist) < 40; i-- {
		h := in.History[i]
		if h.Kind == "brain.learning" {
			if len(learned) < 20 {
				learned = append(learned, h.Summary)
			}
			continue
		}
		hist = append(hist, map[string]any{"at": h.Time.Format("2006-01-02 15:04"), "kind": h.Kind, "object": h.Object, "summary": h.Summary, "outcome": h.Outcome})
	}
	var prods []map[string]any
	if len(in.Ranked) > 0 { // fresh-first ranking (playbook.go): score, its parts and the why, best first
		for i, p := range in.Ranked {
			if i >= 40 {
				break
			}
			prods = append(prods, map[string]any{"id": p.ID, "title": p.Title, "brand": p.Brand, "type": p.Category, "price_aed": p.PriceAED,
				"score": p.Score, "pinned": p.Pinned, "trending": p.Trending, "age_days": p.AgeDays, "units_14d": p.Units14, "why": p.Why})
		}
	} else {
		for _, p := range pickProductsT(in.Shop, in.Research, in.Trending, 40) {
			prods = append(prods, map[string]any{"id": p.ID, "title": p.Title, "brand": p.Brand, "type": p.Type, "price_aed": p.PriceAED,
				"margin_pct": p.MarginPct, "stock": p.Stock})
		}
	}
	season := SeasonAt(in.Now)
	sname := ""
	if season != nil {
		sname = season.Name
	}
	return map[string]any{
		"owner_playbook": ownerBlock(in.Playbook, in.JobNotes),
		"lessons":        lessonsBrief(in.Lessons),
		"date":           in.Now.Format("2006-01-02 Mon"), "season_now": sname, "payday_window": IsPayday(in.Now),
		"upcoming_seasons": Upcoming(in.Now, 60), "economics": rr.Econ,
		"limits": in.Limits, "limits_set": rr.LimitsSet, "ad_wording_rules": firstNonEmpty(in.Limits.AdWording, Balanced), "promotion_approved": promo,
		"evaluation_window": rr.Window, "account_ctr": rr.AccountCTR, "account_cpa": rr.AccountCPA,
		"arabic_share": rr.ArabicShare, "batch_day": rr.BatchDay, "new_ad_slots": rr.NewAdSlots,
		"language_30d": langSummary(rr.Lang), "alerts": rr.Alerts,
		"rules_proposals_already_queued": rr.Proposals, "tree": tree, "learning_table": rr.Learning,
		"past_learnings": learned, "recent_history": hist, "research": notes, "creative_library": lib, "products": prods,
		"orders_source": in.Shop.OrdersSource, "videos": videoSummary(in.Videos, rr.Econ),
		"learned_profile": profileBrief(in.Profile), "portfolio_profile": profileBrief(in.Portfolio),
		"trending_products": trendingBrief(in.Trending), "portfolio_learnings": in.PortfolioLearnings,
	}
}

func langSummary(m map[string]Agg) map[string]any {
	out := map[string]any{}
	for k, a := range m {
		out[k] = map[string]any{"spend": round2(a.Spend), "purchases": a.Purch, "cpa": round2(a.CPA()), "roas": round2(a.ROAS()), "ctr": round4(a.CTR())}
	}
	return out
}

// heroBrands: the K-beauty brands GCC shoppers search for most (prior; the
// research job and the shop's own sales replace it).
var heroBrands = []string{"COSRX", "Beauty of Joseon", "Anua", "SKIN1004", "Torriden", "Round Lab", "Medicube", "Laneige", "Isntree", "Some By Mi", "Numbuzin", "Axis-Y", "Dr.Jart+", "Innisfree", "Mixsoon", "Haruharu", "Purito", "Skinfood", "Klairs", "Missha"}

// pickProducts chooses candidates for ads: in stock with a photo, boosted
// when the title/description mentions a researched angle/ingredient, then
// hero brands, then mid-range price. Deterministic.
func pickProducts(shop ShopSnapshot, r Research, n int) []Product {
	return pickProductsT(shop, r, nil, n)
}

// pickProductsT: trending products the shop sells come first.
func pickProductsT(shop ShopSnapshot, r Research, tr *TrendingReport, n int) []Product {
	hot := map[string]bool{}
	if tr != nil {
		for _, id := range tr.Selling {
			hot[id] = true
		}
	}
	terms := map[string]bool{}
	for _, note := range r.Notes {
		for _, a := range note.Angles {
			for _, w := range strings.Fields(strings.ToLower(a)) {
				if len(w) >= 5 {
					terms[w] = true
				}
			}
		}
	}
	type sc struct {
		p Product
		s float64
	}
	var all []sc
	for _, p := range shop.Products {
		if !p.InStock || p.Image == "" || p.PriceAED <= 0 {
			continue
		}
		s := 0.0
		lt := strings.ToLower(p.Title + " " + p.Description)
		for t := range terms {
			if strings.Contains(lt, t) {
				s += 2
			}
		}
		for i, bname := range heroBrands {
			if strings.EqualFold(p.Brand, bname) || strings.HasPrefix(strings.ToLower(p.Title), strings.ToLower(bname)) {
				s += 3 - float64(i)*0.1
				break
			}
		}
		if p.PriceAED >= 40 && p.PriceAED <= 160 {
			s += 1
		}
		if p.MarginPct > 0 {
			s += p.MarginPct / 50
		}
		if hot[p.ID] {
			s += 6 // trending now and in stock
		}
		all = append(all, sc{p, s})
	}
	sort.SliceStable(all, func(i, j int) bool {
		if all[i].s != all[j].s {
			return all[i].s > all[j].s
		}
		return all[i].p.ID < all[j].p.ID
	})
	var out []Product
	for i := 0; i < len(all) && len(out) < n; i++ {
		out = append(out, all[i].p)
	}
	return out
}

func topAngles(r Research, n int) []string {
	seen := map[string]bool{}
	var out []string
	for _, note := range r.Notes {
		for _, a := range note.Angles {
			k := strings.ToLower(strings.TrimSpace(a))
			if k == "" || seen[k] {
				continue
			}
			seen[k] = true
			out = append(out, a)
			if len(out) >= n {
				return out
			}
		}
	}
	return out
}

func clean(s []string, n int) []string {
	var out []string
	for _, x := range s {
		x = strings.TrimSpace(x)
		if x != "" {
			out = append(out, x)
		}
		if len(out) >= n {
			break
		}
	}
	return out
}

func contains(s []string, x string) bool {
	for _, v := range s {
		if v == x {
			return true
		}
	}
	return false
}

func oneOf(v string, allowed []string, def string) string {
	for _, a := range allowed {
		if a == v {
			return v
		}
	}
	return def
}

func addUsageTotals(u *Usage, x Usage) {
	u.InputTokens += x.InputTokens
	u.OutputTokens += x.OutputTokens
	u.CacheReadTokens += x.CacheReadTokens
	u.CacheWriteTokens += x.CacheWriteTokens
	u.WebSearches += x.WebSearches
	u.CostUSD = round4(u.CostUSD + x.CostUSD)
}

// validFormat fills a missing format from the creative and checks what each
// format needs that the brain can see (products for carousel/collection).
func validFormat(params map[string]any, shop ShopSnapshot, bad *func(string)) bool {
	cr, _ := params["creative_id"].(string)
	f, _ := params["format"].(string)
	if f == "" {
		f = "single_image"
		if strings.HasPrefix(cr, "video:") {
			f = "single_video"
		}
		params["format"] = f
	}
	switch f {
	case "carousel", "collection":
		ids, _ := params["product_ids"].([]any)
		n := 0
		for _, id := range ids {
			if len(productsByID(shop, []string{fmt.Sprint(id)})) == 1 {
				n++
			}
		}
		if n < 2 || n > 10 {
			(*bad)(f + " needs 2-10 in-stock products with photos")
			return false
		}
	}
	if NotOffered[f] || (params["destination"] != nil && params["destination"] != "website") {
		(*bad)(f + ": chat ads are not offered by the shop (the owner's decision)")
		return false
	}
	return true
}

// PlannerSystem is the stable planner prompt plus the static format,
// audience and cost playbooks (deterministic JSON: the cache prefix holds).
func PlannerSystem() string {
	type f struct {
		ID, When   string
		Needs      []string
		Placements []string
	}
	var fs []f
	for _, x := range AdFormats {
		fs = append(fs, f{x.ID, x.When, x.Needs, x.Placements})
	}
	type a struct{ ID, Name, When string }
	var as []a
	for _, x := range AudiencePresets {
		as = append(as, a{x.ID, x.Name, x.When})
	}
	fj, _ := json.Marshal(fs)
	aj, _ := json.Marshal(as)
	return plannerSystem + "\n\n" + ownerRule + "\n\n" + AgencyPrompt() + "\nAD FORMATS you can use (params.format; pick per goal):\n" + string(fj) +
		"\n\nAUDIENCES (params.audience on create_adset; tests carry one):\n" + string(aj) +
		"\n\nCOST PLAYBOOK (low CPC/CPM, high CTR, women in the GCC):\n- " + strings.Join(CostStrategy, "\n- ")
}

// profileBrief: the profile trimmed for the prompt (top cuts only).
func profileBrief(p *LearningProfile) any {
	if p == nil {
		return nil
	}
	top := func(s []Slice) []Slice {
		if len(s) > 6 {
			return s[:6]
		}
		return s
	}
	return map[string]any{"account": p.Account, "history_days": p.Days, "baselines": p.Baselines, "by_lang": top(p.ByLang),
		"by_angle": top(p.ByAngle), "by_template": top(p.ByTemplate), "by_format": top(p.ByFormat), "by_placement": top(p.ByPlacement),
		"by_season": top(p.BySeason), "by_weekday": p.ByWeekday, "creative_half_life_days": p.HalfLifeDays, "fatigue_curve": p.FatigueCurve,
		"winners": clean(p.Winners, 8), "evidence": clean(p.Evidence, 8)}
}

// clone copies the configuration (not the mutex/usage state) for one call.
func (b *Brain) clone() *Brain {
	return &Brain{Claude: b.Claude, Econ: b.Econ, Rules: b.Rules, PlannerModel: b.PlannerModel, SmallModel: b.SmallModel,
		CopyModel: b.CopyModel, PromoApproved: b.PromoApproved, MaxCopyPerRun: b.MaxCopyPerRun, DeferCopy: b.DeferCopy}
}

// nextWeekday: today when it is that weekday, else the next one (YYYY-MM-DD).
func nextWeekday(t time.Time, wd time.Weekday) string {
	d := (int(wd) - int(t.Weekday()) + 7) % 7
	return t.AddDate(0, 0, d).Format("2006-01-02")
}
