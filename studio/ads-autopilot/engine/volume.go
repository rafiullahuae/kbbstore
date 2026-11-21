package main

// volume.go — how much the autopilot runs (the owner, 21 Nov 2026: "the system should create more campaigns / ad etc.
// so we can have good amount of ads and run and generate the results").
//
// Reconciled with the low-budget playbook (brain/agency.go): MORE ADS AND MORE TESTS inside few, well-fed campaigns —
// never many starved ones. An ad set leaves Meta's learning phase after ~50 results a week, so a new campaign is only
// built when the budget can feed it that (target CPA × 50 ÷ 7 per day) AND that much is still free under his daily
// limit; otherwise the same concepts go into the main campaign as more ads. The levels (Strategy → Rules → Activity
// level; model.Knobs.ActivityLevel):
//
//	Steady      today's behaviour: one weekly batch on the batch day, the planner decides the count.
//	Active      (default, he asked for more) 8–12 live prospecting ads, a test batch of 4–6 on Mon and Thu, losers
//	            refilled the same day, winners iterated (new hook, same concept) within 48 h, a 2nd prospecting
//	            campaign only when the budget feeds its learning.
//	Aggressive  12–20 live ads, batches Mon / Wed / Fri, up to 3 prospecting campaigns when the budget feeds them.
//
// Never above his limits: the live-ad target is cut to his Max active ads (Settings → Limits) and says so; every ad
// still goes through the executor's checks; new ads go into the existing budgets (no new spend), and a new campaign's
// budget must fit under the daily limit. Every job it plans or cannot plan is written to Activity with the reason.

import (
	"context"
	"fmt"
	"math"
	"net/http"
	"sort"
	"strings"
	"time"

	"kaa/executor"
	"kaa/model"
)

type CampaignSlot struct {
	N       int     `json:"n"`
	Name    string  `json:"name"`
	NameAR  string  `json:"name_ar"`
	Status  string  `json:"status"` // live | planned | needs_budget | not_at_this_level
	NeedAED float64 `json:"need_aed"`
	HaveAED float64 `json:"have_aed"`
	Why     string  `json:"why"`
	WhyAR   string  `json:"why_ar"`
}

type VolumeTarget struct {
	Level          string         `json:"level"`
	LiveMin        int            `json:"live_min"`
	LiveMax        int            `json:"live_max"`
	LevelMin       int            `json:"level_min"`
	LevelMax       int            `json:"level_max"`
	LimitCapped    bool           `json:"limit_capped"`
	MaxActiveAds   int            `json:"max_active_ads"`
	BatchMin       int            `json:"batch_min"`
	BatchMax       int            `json:"batch_max"`
	BatchDays      []string       `json:"batch_days"`
	IterateHours   int            `json:"iterate_hours"`
	RefillSameDay  bool           `json:"refill_same_day"`
	MaxCampaigns   int            `json:"max_campaigns"`
	Campaigns      []CampaignSlot `json:"campaigns"`
	NeedPerCampAED float64        `json:"need_per_campaign_aed"`
	ProspectingAED float64        `json:"prospecting_aed"`
	FreeAED        float64        `json:"free_aed"`
	TargetCPA      float64        `json:"target_cpa"`
	ConvWeek       int            `json:"conv_week"`
	Live           int            `json:"live"`
	OtherLive      int            `json:"other_live"`
	Why            string         `json:"why"`
	WhyAR          string         `json:"why_ar"`
}

var levelSpec = map[string]struct {
	min, max, bmin, bmax, camps int
	days                        []string
}{
	model.ActivitySteady:     {0, 0, 0, 0, 1, nil},
	model.ActivityActive:     {8, 12, 4, 6, 2, []string{"mon", "thu"}},
	model.ActivityAggressive: {12, 20, 4, 6, 3, []string{"mon", "wed", "fri"}},
}

var dayNames = map[string][2]string{"mon": {"Mon", "الاثنين"}, "tue": {"Tue", "الثلاثاء"}, "wed": {"Wed", "الأربعاء"}, "thu": {"Thu", "الخميس"}, "fri": {"Fri", "الجمعة"}, "sat": {"Sat", "السبت"}, "sun": {"Sun", "الأحد"}}

// occasionReserve: ad slots kept free for the funnel's missing pieces, the occasion packages he approved, and those
// starting within 3 days (their ads go live when he approves; the cadence must not have taken every slot under his
// Max active ads by then). A batch still goes out by rotating (one out for one in), so it never needs these slots.
func occasionReserve(js JobSet, now time.Time) int {
	n := 0
	for _, j := range js.Jobs {
		// the funnel's missing pieces come before more of the same: their ads keep a slot (the lookalike ad set's 2,
		// the existing-customer ad set's 1) unless they wait on something else (an audience, a link)
		if (j.Kind == "funnel_lookalike" || j.Kind == "funnel_existing") && open(j.Status) && (j.Waiting == "" || strings.HasPrefix(j.Waiting, "no free ad slot")) {
			n += map[string]int{"funnel_lookalike": 2, "funnel_existing": 1}[j.Kind]
			continue
		}
		if j.Kind != "occasion" || !open(j.Status) || j.Occasion == nil {
			continue
		}
		today, soon := now.Format("2006-01-02"), now.AddDate(0, 0, 3).Format("2006-01-02")
		if j.Status == "approved" && j.Occasion.End >= today || j.Occasion.Start >= today && j.Occasion.Start <= soon {
			n += max(0, len(j.Creatives)-len(j.Rejected))
		}
	}
	return n
}

// capTo: the live-ad target cut to room (when he has a Max active ads).
func (v *VolumeTarget) capTo(room int) {
	if v.MaxActiveAds <= 0 || v.LiveMax == 0 {
		return
	}
	room = max(room, 0)
	if v.LiveMax > room {
		v.LiveMax, v.LimitCapped = room, true
	}
	v.LiveMin = min(v.LiveMin, v.LiveMax)
}

// unbuilt: extra campaigns 2..n not built yet (each needs its learning budget free under the daily limit).
func unbuilt(live map[int]bool, n int) int {
	k := 0
	for i := 2; i <= n; i++ {
		if !live[i] {
			k++
		}
	}
	return k
}

func cap1(s string) string {
	if s == "" {
		return s
	}
	return strings.ToUpper(s[:1]) + s[1:]
}

// prospecting campaigns the app made, in order (the main one first; #2 / #3 by name)
func extraN(name string) int {
	switch {
	case strings.Contains(name, "Prospecting #2"):
		return 2
	case strings.Contains(name, "Prospecting #3"):
		return 3
	}
	return 0
}

// liveProspecting: ACTIVE ads in running ad sets of the app's prospecting campaigns, and every other ACTIVE ad.
func liveProspecting(tree []model.Campaign) (prosp, other int) {
	for _, c := range tree {
		for _, s := range c.AdSets {
			for _, a := range s.Ads {
				if a.Status != "ACTIVE" || s.Status != "ACTIVE" || c.Status != "ACTIVE" {
					continue
				}
				if c.Managed && c.Objective == "OUTCOME_SALES" && !strings.Contains(s.Name, "existing customers") {
					prosp++
				} else {
					other++
				}
			}
		}
	}
	return
}

// volumeFor: the level's targets for this account as it is now. Pure (for the tests).
func volumeFor(pb model.Playbook, lim model.Limits, tCPA float64, tree []model.Campaign) VolumeTarget {
	lvl := pb.Knobs.Activity()
	sp := levelSpec[lvl]
	v := VolumeTarget{Level: lvl, LiveMin: sp.min, LiveMax: sp.max, LevelMin: sp.min, LevelMax: sp.max, BatchMin: sp.bmin, BatchMax: sp.bmax,
		BatchDays: sp.days, MaxCampaigns: sp.camps, MaxActiveAds: lim.MaxActiveAds, TargetCPA: tCPA, ConvWeek: pb.Knobs.ConvWeek()}
	v.Live, v.OtherLive = liveProspecting(tree)
	if lvl == model.ActivitySteady {
		bd := pb.Knobs.BatchDay
		if bd == "" {
			bd = "mon"
		}
		v.BatchDays = []string{bd}
		v.BatchMax = pb.Knobs.MaxNewAdsBatch
		if v.BatchMax == 0 {
			v.BatchMax = 4
		}
	} else {
		v.IterateHours, v.RefillSameDay = 48, true
	}
	if lim.MaxActiveAds > 0 && v.LiveMax > 0 {
		room := lim.MaxActiveAds - v.OtherLive
		if room < 0 {
			room = 0
		}
		if v.LiveMax > room {
			v.LiveMax, v.LimitCapped = room, true
			if v.LiveMin > room {
				v.LiveMin = room
			}
		}
	}
	split := pb.SplitFor(lim.DailyAED)
	v.ProspectingAED = math.Round(lim.DailyAED*split.Prospecting) / 100
	v.FreeAED = math.Max(0, math.Round((lim.DailyAED-executor.CommittedDaily(tree))*100)/100)
	if tCPA > 0 {
		v.NeedPerCampAED = math.Ceil(tCPA * float64(v.ConvWeek) / 7)
	}
	live := map[int]bool{}
	for _, c := range tree {
		if c.Managed && c.Objective == "OUTCOME_SALES" && (c.Status == "ACTIVE" || c.Status == "PAUSED") {
			if n := extraN(c.Name); n > 0 {
				live[n] = true
			} else if !strings.Contains(c.Name, "Click-to") {
				live[1] = true
			}
		}
	}
	ord := map[int][2]string{2: {"2nd", "الثانية"}, 3: {"3rd", "الثالثة"}}
	v.Campaigns = []CampaignSlot{{N: 1, Name: "Prospecting · Advantage+ sales (main)", NameAR: "جذب عملاء جدد · Advantage+ (الرئيسية)", Status: map[bool]string{true: "live", false: "planned"}[live[1]],
		Why: "Every new ad goes here first: one well-fed campaign learns faster than several thin ones.", WhyAR: "كل إعلان جديد يدخل هنا أولاً: حملة واحدة بميزانية كافية تتعلّم أسرع من عدة حملات ضعيفة."}}
	for n := 2; n <= 3; n++ {
		s := CampaignSlot{N: n, Name: fmt.Sprintf("Prospecting #%d · New arrivals & trending", n), NameAR: fmt.Sprintf("حملة جذب %s · الجديد والرائج", ord[n][1]), NeedAED: v.NeedPerCampAED * float64(n), HaveAED: v.ProspectingAED}
		switch {
		case live[n]:
			s.Status, s.Why, s.WhyAR = "live", "Built: its budget feeds its learning.", "مبنية: ميزانيتها تكفي لتعلّمها."
		case n > v.MaxCampaigns:
			s.Status = "not_at_this_level"
			s.Why = fmt.Sprintf("%s runs up to %d prospecting campaign(s); Aggressive allows 3 when the budget feeds them.", cap1(lvl), v.MaxCampaigns)
			s.WhyAR = fmt.Sprintf("هذا المستوى يشغّل حتى %d حملة جذب؛ المستوى الجريء يسمح بـ ٣ إذا كفت الميزانية.", v.MaxCampaigns)
		case v.NeedPerCampAED <= 0 || v.ProspectingAED < s.NeedAED || v.FreeAED < v.NeedPerCampAED*float64(unbuilt(live, n)):
			s.Status = "needs_budget"
			s.Why = fmt.Sprintf("A %s campaign needs AED %.0f/day to learn (~%d purchases a week each at target CPA AED %.0f, so AED %.0f/day for %d campaigns); you're at AED %.0f/day for prospecting and AED %.0f/day free under your daily limit. Its concepts run inside the main campaign as more ads instead.",
				ord[n][0], v.NeedPerCampAED, v.ConvWeek, tCPA, s.NeedAED, n, v.ProspectingAED, v.FreeAED)
			s.WhyAR = fmt.Sprintf("الحملة %s تحتاج %.0f درهم يومياً لتتعلّم (نحو %d طلباً أسبوعياً لكل حملة)؛ ميزانية الجذب لديك %.0f درهم يومياً والمتاح تحت حدّك اليومي %.0f درهم. أفكارها تعمل داخل الحملة الرئيسية كإعلانات إضافية.",
				ord[n][1], v.NeedPerCampAED, v.ConvWeek, v.ProspectingAED, v.FreeAED)
		default:
			s.Status = "planned"
			s.Why = fmt.Sprintf("Your budget feeds it: AED %.0f/day for %d campaigns, you have AED %.0f for prospecting and AED %.0f free. Plan → Upcoming jobs builds it.", s.NeedAED, n, v.ProspectingAED, v.FreeAED)
			s.WhyAR = fmt.Sprintf("الميزانية تكفي: %.0f درهم يومياً لـ %d حملات، ولديك %.0f للجذب و%.0f متاحة. تُبنى من الخطة.", s.NeedAED, n, v.ProspectingAED, v.FreeAED)
		}
		v.Campaigns = append(v.Campaigns, s)
		if s.Status == "needs_budget" || s.Status == "not_at_this_level" {
			for n2 := n + 1; n2 <= 3; n2++ { // a 3rd never comes before a 2nd
				v.Campaigns = append(v.Campaigns, CampaignSlot{N: n2, Name: fmt.Sprintf("Prospecting #%d · New arrivals & trending", n2), NameAR: fmt.Sprintf("حملة جذب %s", ord[n2][1]),
					Status: map[bool]string{true: "not_at_this_level", false: "needs_budget"}[n2 > v.MaxCampaigns], NeedAED: v.NeedPerCampAED * float64(n2), HaveAED: v.ProspectingAED,
					Why: fmt.Sprintf("Needs the %s first (AED %.0f/day for %d campaigns).", ord[n][0], v.NeedPerCampAED*float64(n2), n2), WhyAR: "تأتي بعد الحملة السابقة."})
			}
			break
		}
	}
	days := []string{}
	daysAR := []string{}
	for _, d := range v.BatchDays {
		days, daysAR = append(days, dayNames[d][0]), append(daysAR, dayNames[d][1])
	}
	switch lvl {
	case model.ActivitySteady:
		v.Why = fmt.Sprintf("Steady: one batch of up to %d new ads every %s; the planner decides how many.", v.BatchMax, strings.Join(days, ""))
		v.WhyAR = fmt.Sprintf("هادئ: دفعة واحدة حتى %d إعلانات جديدة كل %s؛ المخطِّط يقرّر العدد.", v.BatchMax, strings.Join(daysAR, ""))
	default:
		v.Why = fmt.Sprintf("%s: %d–%d live ads in few campaigns, a test batch of %d–%d new ads every %s, losers refilled the same day, winners get a new hook within 48 h. More ads, not more campaigns: a campaign needs ~%d purchases a week to learn.",
			cap1(lvl), v.LevelMin, v.LevelMax, v.BatchMin, v.BatchMax, strings.Join(days, " + "), v.ConvWeek)
		v.WhyAR = fmt.Sprintf("%s: %d–%d إعلاناً نشطاً في حملات قليلة، دفعة اختبار من %d–%d إعلانات كل %s، استبدال الخاسر في اليوم نفسه، وجملة جذب جديدة للرابح خلال ٤٨ ساعة. إعلانات أكثر لا حملات أكثر: الحملة تحتاج نحو %d طلباً أسبوعياً لتتعلّم.",
			map[string]string{"active": "نشِط", "aggressive": "جريء"}[lvl], v.LevelMin, v.LevelMax, v.BatchMin, v.BatchMax, strings.Join(daysAR, " و"), v.ConvWeek)
		if v.LimitCapped {
			v.Why += fmt.Sprintf(" Your Max active ads (Settings → Limits) is %d, so it aims for %d–%d live prospecting ads; raise it to reach %d–%d.", lim.MaxActiveAds, v.LiveMin, v.LiveMax, v.LevelMin, v.LevelMax)
			v.WhyAR += fmt.Sprintf(" الحد الأقصى للإعلانات النشطة لديك %d، لذا الهدف %d–%d؛ ارفعيه للوصول إلى %d–%d.", lim.MaxActiveAds, v.LiveMin, v.LiveMax, v.LevelMin, v.LevelMax)
		}
	}
	return v
}

// ── the cadence: batch, refill, iterate and extra-campaign jobs ──

var conceptAngles = []struct{ en, ar string }{
	{"benefit-first: what it does for her skin type", "الفائدة أولاً: ماذا يقدّم لنوع بشرتها"},
	{"hero ingredient and the Korean-skincare angle", "المكوّن الأساسي والأسلوب الكوري"},
	{"routine step: where it fits morning or night", "خطوة الروتين: مكانه صباحاً أو مساءً"},
	{"texture and feel, UGC style", "القوام والإحساس بأسلوب محتوى المستخدمين"},
	{"new arrival / trending now", "وصل حديثاً / رائج الآن"},
	{"why buy from K-Beauty Bliss: authentic, UAE delivery, free gift + code", "لماذا من K-Beauty Bliss: أصلي، توصيل داخل الإمارات، هدية مجانية + كود"},
}

// aspects: the formats rotated across a batch (single images in 9:16 / 4:5 / 1:1, and sets as carousels).
var aspects = []string{"9:16", "4:5", "1:1", "carousel", "9:16", "4:5"}

func volKind(k string) bool { return k == "batch" || k == "reword" }

func dayKey(t time.Time) string { return t.Format("20060102") }

// planCadence: the level's jobs for the coming week, refreshed from the account as it is now. Owner edits and
// decisions are kept (upsert); planned, unedited cadence jobs the plan no longer wants are removed (logged).
func (ac *Acct) planCadence(in model.CycleInput, js *JobSet, now time.Time) {
	if in.Playbook == nil {
		return
	}
	pb := *in.Playbook
	loc := ac.loc()
	tCPA := ac.targetCPA(in)
	v := volumeFor(pb, in.Limits, tCPA, in.Tree)
	v.capTo(in.Limits.MaxActiveAds - v.OtherLive - occasionReserve(*js, now)) // an occasion package and a funnel piece keep their slots
	f := findFunnel(in.Tree, in.Audiences)
	want := map[string]bool{}
	logf := func(s string, a ...any) {
		ac.st.Append("volume", fmt.Sprintf(s, a...), map[string]any{"level": v.Level})
	}
	defer func() { // drop what the plan no longer wants (a level change, a batch now full)
		var keep []Job
		for i := range js.Jobs {
			j := &js.Jobs[i]
			if volKind(j.Kind) && strings.HasPrefix(j.ID, "vol-") && j.Status == "planned" && !j.Edited && now.Sub(j.When) > 48*time.Hour {
				j.Status, j.Result = "skipped", "expired: not run within 48 h of its time"+map[bool]string{true: " (" + j.Waiting + ")", false: ""}[j.Waiting != ""]
				j.logf(now, "%s", j.Result)
				logf("plan: %s %s", j.Title, j.Result)
			}
		}
		for _, j := range js.Jobs {
			if (volKind(j.Kind) || j.Kind == "funnel_campaign") && strings.HasPrefix(j.ID, "vol-") && j.Status == "planned" && !j.Edited && !want[j.ID] && j.When.After(now) {
				logf("plan: %s removed (%s level no longer needs it)", j.Title, v.Level)
				continue
			}
			keep = append(keep, j)
		}
		js.Jobs = keep
	}()
	if v.Level == model.ActivitySteady || !in.Limits.Set() {
		return
	}
	if f.prosp == nil { // the first launch builds the main campaign; the cadence starts after it
		return
	}
	// products already taken: live ads, and every open job's own products
	taken := map[string]bool{}
	lib := map[string]model.Creative{}
	for _, c := range in.Creatives {
		lib[c.ID], lib[c.MetaID] = c, c
	}
	for _, c := range in.Tree {
		for _, s := range c.AdSets {
			for _, a := range s.Ads {
				if a.Status == "ACTIVE" {
					for _, id := range lib[a.CreativeID].ProductIDs {
						taken[id] = true
					}
				}
			}
		}
	}
	pending := 0 // new ads already planned and not run yet (due today or overdue)
	endToday := time.Date(now.In(loc).Year(), now.In(loc).Month(), now.In(loc).Day(), 23, 59, 0, 0, loc)
	for _, j := range js.Jobs {
		if !open(j.Status) {
			continue
		}
		for _, id := range j.ProductIDs {
			taken[id] = true
		}
		if (volKind(j.Kind) || j.Kind == "test") && !j.When.After(endToday) {
			pending++
		}
	}
	ranked := ac.rankedFor(in)
	// pick: products for one job. A job due today takes products no live ad and no other job has; a later batch only
	// avoids its own batch (it is planned again, with fresh choices, every cycle until its day); a small shop that has
	// run out of untaken products reuses one with a different concept rather than skipping the ad.
	var batchTaken map[string]bool
	reserve := true
	pick := func(n int) []string {
		var out []string
		for pass := 0; pass < 2 && len(out) < n; pass++ {
			for _, id := range ranked {
				if len(out) >= n || contains(out, id) || batchTaken[id] || (pass == 0 && taken[id]) {
					continue
				}
				out = append(out, id)
			}
		}
		for _, id := range out {
			batchTaken[id] = true
			if reserve {
				taken[id] = true
			}
		}
		return out
	}
	share := pb.Knobs.ArabicSharePct
	if share <= 0 {
		share = 70
	}
	langs := map[string]bool{}
	for _, l := range pb.Languages {
		langs[l] = true
	}
	langFor := func(i, n int) string {
		ar := int(math.Round(float64(n) * share / 100))
		if ar < 1 {
			ar = 1
		}
		if (i < ar && langs["ar"]) || !langs["en"] {
			return "ar"
		}
		return "en"
	}
	add := func(j Job) bool {
		if j.Format == "carousel" {
			j.ProductIDs = pick(3)
			if len(j.ProductIDs) < 2 {
				j.Format = "image"
			}
		}
		if j.Format != "carousel" && len(j.ProductIDs) == 0 {
			j.ProductIDs = pick(1)
		}
		if len(j.ProductIDs) == 0 {
			logf("plan: %s not planned — no in-stock product your playbook allows that is not already in an ad", j.Title)
			return false
		}
		want[j.ID] = true
		j.Source, j.Audience, j.UpdatedAt = "volume", "inside the prospecting campaign (Advantage+ audience)", now.UTC()
		if j.Status == "" {
			j.Status = "planned"
		}
		upsert(js, j, now)
		return true
	}
	plannerOn := map[string]int{} // the planner's own test jobs count toward the batch of their day
	for _, j := range js.Jobs {
		if j.Kind == "test" && open(j.Status) {
			plannerOn[dayKey(j.When.In(loc))]++
		}
	}
	// 1. the test batches of the coming 7 days
	first := true
	for d := 0; d < 7; d++ {
		day := now.In(loc).AddDate(0, 0, d)
		wd := strings.ToLower(day.Weekday().String()[:3])
		if !contains(v.BatchDays, wd) {
			continue
		}
		at := time.Date(day.Year(), day.Month(), day.Day(), 9, 0, 0, 0, loc)
		dk := dayKey(day)
		if d == 0 && now.In(loc).Hour() >= 18 { // too late today: the refill covers the gap, the next batch day takes it
			continue
		}
		size := v.BatchMin
		batchTaken, reserve = map[string]bool{}, d == 0
		if first {
			gap := v.LiveMax - v.Live - pending
			size = max(v.BatchMin, min(v.BatchMax, gap))
			first = false
		}
		size -= plannerOn[dk]
		for i := 0; i < size; i++ {
			lang := langFor(i, size)
			asp := aspects[i%len(aspects)]
			ang := conceptAngles[(i+int(day.Weekday()))%len(conceptAngles)]
			j := Job{ID: fmt.Sprintf("vol-%s-%d", dk, i+1), Kind: "batch", When: at, Lang: lang, Format: map[bool]string{true: "carousel", false: "image"}[asp == "carousel"],
				Title:   fmt.Sprintf("Test batch %s · %d/%d · %s · %s", dayNames[wd][0], i+1, size, strings.ToUpper(lang), asp),
				TitleAR: fmt.Sprintf("دفعة اختبار %s · %d/%d · %s", dayNames[wd][1], i+1, size, map[string]string{"ar": "عربي", "en": "إنجليزي"}[lang]),
				Why: fmt.Sprintf("%s level: a batch of %d new concepts on %s (%d live now, target %d–%d). Concept: %s; format %s.",
					cap1(v.Level), size, dayNames[wd][0], v.Live, v.LiveMin, v.LiveMax, ang.en, asp),
				CopyDirection: fmt.Sprintf("Concept: %s. Format %s%s. Arabic hook in the first 2 seconds when Arabic.", ang.en, asp, map[bool]string{true: " vertical (Reels / Stories)", false: ""}[asp == "9:16"])}
			add(j)
		}
	}
	// 2. refill today: a stopped loser's slot does not stay empty until the next batch
	batchTaken, reserve = map[string]bool{}, true
	if gap := v.LiveMin - v.Live - pending; gap > 0 && now.In(loc).Hour() < 21 {
		gap = min(gap, 6)
		for i := 0; i < gap; i++ {
			lang := langFor(i, gap)
			ang := conceptAngles[(i+3)%len(conceptAngles)]
			j := Job{ID: fmt.Sprintf("vol-%s-fill%d", dayKey(now.In(loc)), i+1), Kind: "batch", When: now, Lang: lang, Format: "image",
				Title: fmt.Sprintf("Refill today · %s · live %d < %d", strings.ToUpper(lang), v.Live, v.LiveMin), TitleAR: fmt.Sprintf("تعويض اليوم · %d إعلانات نشطة من %d", v.Live, v.LiveMin),
				Why:           fmt.Sprintf("Only %d live prospecting ads (target %d–%d): a stopped or rejected ad's slot is refilled the same day.", v.Live, v.LiveMin, v.LiveMax),
				CopyDirection: "Concept: " + ang.en + "."}
			add(j)
		}
	}
	// 3. winners: a new hook on the same concept within 48 h
	for _, w := range winners(in, tCPA, loc) {
		c := lib[w.creative]
		if len(c.ProductIDs) == 0 {
			continue
		}
		_, wk := now.In(loc).ISOWeek()
		id := fmt.Sprintf("vol-iter-%s-%d", jobPID.ReplaceAllString(w.ad, "-"), wk/2)
		at := now.Add(2 * time.Hour)
		j := Job{ID: id, Kind: "batch", When: at, Lang: firstNonEmpty(c.Lang, "ar"), Format: "image", ProductIDs: allowedOnly(c.ProductIDs, in.Shop), Target: "",
			Title: fmt.Sprintf("Iterate a winner · new hook · %s", w.name), TitleAR: "تطوير إعلان رابح · جملة جذب جديدة",
			Why:           fmt.Sprintf("Winner: %.0f purchases at AED %.0f CPA (target %.0f). A new hook on the same concept within 48 h keeps it fresh.", w.purch, w.cpa, tCPA),
			CopyDirection: fmt.Sprintf("New hook on the winning concept (%s): same product and offer, a different first line and opening visual.", firstNonEmpty(c.Angle, "the same angle"))}
		if len(j.ProductIDs) > 0 {
			want[j.ID] = true
			j.Source, j.Status, j.UpdatedAt = "volume", "planned", now.UTC()
			upsert(js, j, now)
		}
	}
	// 4. a 2nd / 3rd prospecting campaign, only when its budget feeds its learning
	for _, s := range v.Campaigns {
		if s.N < 2 || s.Status != "planned" {
			continue
		}
		id := fmt.Sprintf("vol-campaign-%d", s.N)
		want[id] = true
		upsert(js, Job{ID: id, Kind: "funnel_campaign", Title: fmt.Sprintf("Add prospecting campaign #%d: new arrivals & trending", s.N), TitleAR: s.NameAR,
			Why: s.Why, When: now, Status: "planned", BudgetAED: math.Min(v.NeedPerCampAED, v.FreeAED), Source: "volume", ProductIDs: []string{}, Lang: "ar", Format: "image", UpdatedAt: now.UTC()}, now)
	}
}

type winner struct {
	ad, creative, name string
	purch, cpa         float64
}

// winners: app ads with >= 3 settled purchases in 14 days at or under the target CPA.
func winners(in model.CycleInput, tCPA float64, loc *time.Location) []winner {
	if tCPA <= 0 {
		return nil
	}
	now := in.Now.In(loc)
	from, to := now.AddDate(0, 0, -16).Format("2006-01-02"), now.AddDate(0, 0, -2).Format("2006-01-02")
	sp, pu := map[string]float64{}, map[string]float64{}
	for _, r := range in.Metrics {
		if r.Level == "ad" && r.Date >= from && r.Date < to {
			sp[r.ObjectID] += r.SpendAED
			pu[r.ObjectID] += r.Purchases
		}
	}
	var out []winner
	for _, c := range in.Tree {
		if !c.Managed {
			continue
		}
		for _, s := range c.AdSets {
			for _, a := range s.Ads {
				if a.Status == "ACTIVE" && pu[a.ID] >= 3 && sp[a.ID]/pu[a.ID] <= tCPA {
					out = append(out, winner{a.ID, a.CreativeID, a.Name, pu[a.ID], sp[a.ID] / pu[a.ID]})
				}
			}
		}
	}
	sort.Slice(out, func(i, k int) bool { return out[i].purch > out[k].purch })
	if len(out) > 3 {
		out = out[:3]
	}
	return out
}

// rankedFor: the allowed products, fresh-first (the same ranking the jobs use), that can carry an ad.
func (ac *Acct) rankedFor(in model.CycleInput) []string {
	by := map[string]model.Product{}
	for _, p := range in.Shop.Products {
		by[p.ID] = p
	}
	ac.app.mu.RLock()
	br := ac.app.brain
	ac.app.mu.RUnlock()
	var tr []string
	if t, ok := br.(interface{ TrendingProductIDs() []string }); ok {
		tr = t.TrendingProductIDs()
	}
	var out []string
	for _, r := range model.Rank(in.Shop.Products, *in.Playbook, model.RankSignals{Now: in.Now, Trending: tr, Baseline: firstSeenBaseline(in.Shop)}) {
		if p, ok := by[r.ID]; ok && p.InStock && executor.ShopURL(p.URL) && executor.ShopURL(p.Image) {
			out = append(out, r.ID)
		}
	}
	return out
}

// jobCreative: the creative a job's ad uses. A reword (a rejected ad) and a winner's new hook need NEW copy, never the
// library's existing ad for that product; everything else takes the existing one (or the template) as before.
func (ac *Acct) jobCreative(ctx context.Context, j Job, p model.Product, lang string, copyOf func(model.Product) map[string][2]string) (string, error) {
	if !volKind(j.Kind) {
		return ac.starterCreative(ctx, p, lang, copyOf)
	}
	if j.Kind != "reword" && !strings.HasPrefix(j.ID, "vol-iter-") { // his own ad for it (made with Claude for today's plan) first
		for _, c := range ac.creatives.all() {
			if !c.Used && !c.Starter && c.Media != "" && c.Lang == lang && contains(c.ProductIDs, p.ID) && strings.HasPrefix(c.Link, "https://kbeautybliss.com/") {
				return c.ID, nil
			}
		}
	}
	id := "st-" + strings.Trim(pidSafe.ReplaceAllString(strings.ToLower(p.ID), "-"), "-") + "-" + lang + "-" + fmt.Sprintf("%x", hash32(j.ID))[:6]
	if len(id) > 64 {
		id = id[:64]
	}
	for _, c := range ac.creatives.all() {
		if c.ID == id {
			return id, nil
		}
	}
	h, t := fallbackCopy(p, lang, ac.playbook())
	if cp, ok := copyOf(p)[lang]; ok && cp[0] != "" && cp[1] != "" {
		h, t = cp[0], cp[1]
	}
	cr := model.Creative{ID: id, Lang: lang, Format: "1:1", Headline: h, PrimaryText: t, CTA: "SHOP_NOW", Link: p.URL, ProductIDs: []string{p.ID},
		Angle: firstNonEmpty(j.CopyDirection, "new hook"), Source: "brain", ImageURL: p.Image, Starter: true}
	if _, err := ac.creatives.Add(cr, ac.app.now()); err != nil {
		return "", err
	}
	return id, nil
}

// rotateAfter: an ad has had its first 72 h (early read) and a day of the daily rules before a batch may rotate it out.
const rotateAfter = 96 * time.Hour

// roomFor: a batch at the live-ad target (or at his Max active ads) makes room by pausing the weakest ad old enough to
// have been judged (4+ days, not a winner); without one the job waits and says why. pending: new ads already taken
// by earlier jobs in this pass; paused: ads already chosen to make room.
func (ac *Acct) roomFor(in model.CycleInput, env executor.Env, j Job, n, pending int, paused map[string]bool, pid func(string) string) ([]model.Proposal, string) {
	if (!volKind(j.Kind) && j.Kind != "test") || n == 0 { // the planner's tests are new ads in the same campaign too
		return nil, ""
	}
	v := volumeFor(*in.Playbook, env.Limits, ac.targetCPA(in), env.Tree)
	reserve := occasionReserve(ac.jobSet(), env.Now)
	v.capTo(env.Limits.MaxActiveAds - v.OtherLive - reserve)
	over := 0
	if v.LiveMax > 0 {
		over = v.Live - len(paused) + pending + n - v.LiveMax
	}
	if over <= 0 {
		return nil, ""
	}
	over = min(over, n) // one out for one in: a batch rotates, it never cuts the account below where it was
	w := ac.watchState()
	ws := map[string]bool{}
	for _, x := range winners(in, ac.targetCPA(in), ac.loc()) {
		ws[x.ad] = true
	}
	sp, pu := map[string]float64{}, map[string]float64{}
	for _, r := range in.Metrics {
		if r.Level == "ad" {
			sp[r.ObjectID] += r.SpendAED
			pu[r.ObjectID] += r.Purchases
		}
	}
	type cand struct {
		id    string
		score float64
	}
	var cs []cand
	for _, c := range env.Tree {
		if !c.Managed || c.Objective != "OUTCOME_SALES" || c.Status != "ACTIVE" {
			continue
		}
		for _, s := range c.AdSets {
			for _, a := range s.Ads {
				seen, ok := w.FirstSeen[a.ID]
				if a.Status != "ACTIVE" || paused[a.ID] || ws[a.ID] || !ok || env.Now.Sub(seen) < rotateAfter {
					continue
				}
				score := pu[a.ID] * 1000 // fewest purchases first, then the most spent per purchase
				if pu[a.ID] > 0 {
					score -= sp[a.ID] / pu[a.ID]
				} else {
					score -= sp[a.ID]
				}
				cs = append(cs, cand{a.ID, score})
			}
		}
	}
	sort.Slice(cs, func(i, k int) bool { return cs[i].score < cs[k].score })
	if len(cs) < over {
		why := fmt.Sprintf("%d live ads, the target is %d: no ad old enough to rotate out yet (4+ days, not a winner)", v.Live-len(paused)+pending, v.LiveMax)
		if reserve > 0 {
			why += fmt.Sprintf("; %d slot(s) under your Max active ads are kept for an occasion package or a funnel piece", reserve)
		}
		return nil, why
	}
	var ps []model.Proposal
	for _, c := range cs[:over] {
		paused[c.id] = true
		ps = append(ps, model.Proposal{ID: pid("room-" + jobPID.ReplaceAllString(c.id, "-")), Kind: model.KindPause, Target: c.id, Params: map[string]any{"level": "ad"},
			Why: "Makes room for the new test batch: the weakest ad that has had 4+ days (fewest purchases, highest cost per purchase); winners are never rotated out.", Confidence: 0.6})
	}
	return ps, ""
}

// cadenceNow: the job queue re-planned for the level now (Strategy → Activity level); when a cycle is running it does
// the same at its end.
func (ac *Acct) cadenceNow() {
	if !ac.work.TryLock() {
		return
	}
	defer ac.work.Unlock()
	js := ac.jobSet()
	ac.planCadence(ac.input(), &js, ac.app.now())
	ac.saveJobs(js)
	ac.st.Append("volume", "activity level changed: the upcoming jobs were planned again for "+ac.playbook().Knobs.Activity(), nil)
}

// ── jobs at their times between the cycles ──

// runDue: the due jobs now, without a planner cycle (the scheduler, every few minutes while the app is open, so a
// 09:00 batch goes out at 09:00, not at the next 6-hourly cycle). Same gates as a cycle: Practice only logs.
func (ac *Acct) runDue(ctx context.Context) (int, error) {
	st := ac.Settings()
	if !st.Automated || !st.Limits.Set() || st.Limits.Autopilot == model.AutopilotOff {
		return 0, nil
	}
	if !ac.work.TryLock() {
		return 0, errBusy
	}
	defer ac.work.Unlock()
	if err := ac.Sync(ctx); err != nil {
		return 0, err
	}
	in := ac.input()
	mode := model.AutopilotShadow
	if st.Limits.Autopilot == model.AutopilotAuto {
		mode = model.AutopilotAuto
	}
	env := ac.env(mode)
	ps, owner := ac.dueJobs(ctx, in, env, env.Limits.Autopilot)
	if len(ps)+len(owner) == 0 {
		return 0, nil
	}
	ac.mu.RLock()
	ex := ac.exec
	ac.mu.RUnlock()
	var res []executor.Result
	if len(ps) > 0 {
		res = ex.Run(ctx, env, ps)
	}
	if len(owner) > 0 {
		res = append(res, ex.Run(ctx, ac.env(model.AutopilotAuto), owner)...)
	}
	all := append(ps, owner...)
	ac.app.mu.RLock()
	br := ac.app.brain
	ac.app.mu.RUnlock()
	if rl, ok := br.(interface {
		RecordLaunches(string, time.Time, []model.Creative, model.ShopSnapshot, []model.Proposal, map[string]bool)
	}); ok {
		done := map[string]bool{}
		for _, r := range res {
			if r.Status == "done" {
				done[r.ProposalID] = true
			}
		}
		if len(done) > 0 {
			rl.RecordLaunches(ac.id, ac.app.now(), ac.creatives.all(), in.Shop, all, done)
		}
	}
	ac.addPending(all, res)
	ac.noteJobs(res, env.Limits.Autopilot)
	ac.st.Append("volume", fmt.Sprintf("jobs at their time: %d steps (%s)", len(res), mode), map[string]any{"steps": len(res)})
	ac.dirty()
	return len(res), nil
}

// addPending: proposals run outside a cycle join the Approvals list (the cycle's own list is kept).
func (ac *Acct) addPending(props []model.Proposal, results []executor.Result) {
	by := map[string]executor.Result{}
	for _, r := range results {
		by[r.ProposalID] = r
	}
	ac.mu.Lock()
	ps := ac.pending
	ps.Items = append([]PendingItem(nil), ps.Items...)
	for _, p := range props {
		it := PendingItem{Proposal: p, Status: "refused", By: "cycle"}
		if r, ok := by[p.ID]; ok {
			r := r
			it.Status, it.Result = r.Status, &r
		}
		ps.Items = append(ps.Items, it)
	}
	if len(ps.Items) > 300 {
		ps.Items = ps.Items[len(ps.Items)-300:]
	}
	ac.pending, ac.stateJSON = ps, nil
	ac.mu.Unlock()
	_ = ac.st.Save("proposals", ps)
}

// hasDue: an open job is due (and not waiting for the owner).
func (ac *Acct) hasDue(now time.Time) bool {
	for _, j := range ac.jobSet().Jobs {
		if (j.Status == "planned" || j.Status == "approved") && !j.When.After(now) && j.ID != jobFirstLaunch && j.Kind != "occasion" {
			return true
		}
	}
	return false
}

// ── a 2nd / 3rd prospecting campaign ──

func (ac *Acct) extraCampaignProposals(in model.CycleInput, j Job, env executor.Env, pid func(string) string, tag string, adsInto func(string, []model.Product) ([]model.Proposal, string)) ([]model.Proposal, string) {
	n := 2
	if strings.HasSuffix(j.ID, "-3") {
		n = 3
	}
	v := volumeFor(*in.Playbook, env.Limits, ac.targetCPA(in), env.Tree)
	if n-1 >= len(v.Campaigns) || v.Campaigns[n-1].Status != "planned" {
		if n-1 < len(v.Campaigns) && v.Campaigns[n-1].Status == "live" {
			return nil, "already built"
		}
		return nil, "the budget no longer feeds it: " + v.Campaigns[min(n-1, len(v.Campaigns)-1)].Why
	}
	b := j.BudgetAED
	if b <= 0 || b > v.FreeAED {
		b = math.Min(v.NeedPerCampAED, v.FreeAED)
	}
	pb := *in.Playbook
	f := findFunnel(in.Tree, in.Audiences)
	countries := funnelCountries(pb, env.Limits.Countries)
	prods := ac.nextProducts(in, j, 3)
	if len(prods) == 0 {
		return nil, "no in-stock product your playbook allows"
	}
	pr := map[string]any{"name": fmt.Sprintf("Prospecting #%d AR+EN · new customers · %s", n, strings.Join(anyToStrings(countries), "+")), "campaign_id": "tmp:" + tag + "-camp",
		"countries": countries, "destination": "website", "advantage_audience": 1.0, "age_range": []any{float64(pb.AgeMin), float64(pb.AgeMax)}}
	if pb.Gender == "women" {
		pr["genders"] = []any{2.0}
	}
	if pb.ExcludeBuyersDays > 0 && f.buyers != nil {
		pr["excluded_custom_audiences"] = []any{f.buyers.ID}
	}
	ps := []model.Proposal{
		{ID: pid("camp"), Kind: model.KindCreateCampaign, Target: "tmp:" + tag + "-camp", Params: map[string]any{"name": fmt.Sprintf("Prospecting #%d · New arrivals & trending · Advantage+ sales", n), "daily_budget": b, "type": "sales", "bid_strategy": "LOWEST_COST_WITHOUT_CAP"}, Why: j.Why, Confidence: 0.6},
		{ID: pid("set"), Kind: model.KindCreateAdSet, Target: "tmp:" + tag + "-set", Params: pr, Why: j.Why, Confidence: 0.6},
	}
	more, why := adsInto("tmp:"+tag+"-set", prods)
	if why != "" {
		return nil, why
	}
	return append(ps, more...), ""
}

// oneEditPerAd: a rule's pause and a batch's room-making pause on the same ad in one run would edit it twice (Meta:
// one edit per object every 30 s); the rule's stays, it makes the same room.
func oneEditPerAd(ps []model.Proposal) []model.Proposal {
	seen := map[string]bool{}
	var out []model.Proposal
	for _, p := range ps {
		if p.Kind == model.KindPause {
			if seen[p.Target] {
				continue
			}
			seen[p.Target] = true
		}
		out = append(out, p)
	}
	return out
}

// ── API: GET /api/volume (Strategy → How much it runs) ──

func (s *server) volumeAPI(w http.ResponseWriter, r *http.Request, ac *Acct) {
	if r.Method != http.MethodGet {
		jsonErr(w, 405, "GET")
		return
	}
	in := ac.input()
	v := volumeFor(*in.Playbook, in.Limits, ac.targetCPA(in), in.Tree)
	next := []map[string]any{}
	for _, j := range ac.jobSet().Jobs {
		if open(j.Status) && (volKind(j.Kind) || j.Kind == "test" || j.Kind == "funnel_campaign") {
			next = append(next, map[string]any{"id": j.ID, "title": j.Title, "title_ar": j.TitleAR, "when": j.When, "status": j.Status, "waiting": j.Waiting})
		}
	}
	if len(next) > 30 {
		next = next[:30]
	}
	writeJSON(w, map[string]any{"account": ac.id, "volume": v, "upcoming": next, "levels": []string{model.ActivitySteady, model.ActivityActive, model.ActivityAggressive}})
}
