package model

// occasions.go — the Occasions calendar (the owner, 20 Nov 2026: "the system must sense the upcoming UAE occasions
// like National Day, Black Friday, New Year, Eid, Ramadan and many other main international days, and prepare the
// ads in advance and give me for approval").
//
// Three kinds of date: fixed (month/day), hijri (the tabular Islamic calendar — the real date follows the moon
// sighting, so these are ESTIMATED, ±1–2 days, until confirmed) and rule (Black Friday = the day after the fourth
// Thursday of November, Cyber Monday 3 days later). The owner edits the list in Strategy → Occasions (switch on/off,
// lead time, his offer, add his own).

import (
	"fmt"
	"math"
	"sort"
	"strings"
	"time"
)

type Occasion struct {
	ID        string   `json:"id"`
	Name      string   `json:"name"`
	NameAR    string   `json:"name_ar"`
	Kind      string   `json:"kind"` // fixed | hijri | rule
	Month     int      `json:"month,omitempty"`
	Day       int      `json:"day,omitempty"`
	Rule      string   `json:"rule,omitempty"` // black_friday | cyber_monday
	Days      int      `json:"days"`           // how long it lasts
	LeadDays  int      `json:"lead_days"`      // creatives ready this many days before
	Countries []string `json:"countries,omitempty"`
	Angle     string   `json:"angle"`
	Tone      string   `json:"tone"`
	Products  []string `json:"products,omitempty"` // keywords that fit (gift set, spf, hydrating ...)
	Offer     string   `json:"offer,omitempty"`    // the owner's offer for it; empty = no discount wording
	On        bool     `json:"on"`
	Custom    bool     `json:"custom,omitempty"`
}

type OccasionInstance struct {
	ID        string `json:"id"` // <occasion id>-<start date>
	Occasion  string `json:"occasion"`
	Name      string `json:"name"`
	NameAR    string `json:"name_ar"`
	Start     string `json:"start"`
	End       string `json:"end"`
	PrepFrom  string `json:"prep_from"`
	Estimated bool   `json:"estimated"`
	Angle     string `json:"angle"`
	Tone      string `json:"tone"`
	Offer     string `json:"offer,omitempty"`
}

var gift = []string{"set", "gift", "kit", "duo", "trio", "mini"}

// DefaultOccasions: UAE + GCC + international. Tone notes keep the creative culturally right.
func DefaultOccasions() []Occasion {
	respectful := "Respectful and calm; no party or music-heavy tone; modest imagery."
	return []Occasion{
		{ID: "flag-day", Name: "UAE Flag Day", NameAR: "يوم العلم", Kind: "fixed", Month: 11, Day: 3, Days: 1, LeadDays: 14, Countries: []string{"AE"}, Angle: "proud, local", Tone: "Brand-safe: UAE colours as accents only, never the flag on a product.", On: true},
		{ID: "singles-day", Name: "11.11 Singles' Day", NameAR: "عروض ١١.١١", Kind: "fixed", Month: 11, Day: 11, Days: 1, LeadDays: 14, Angle: "treat yourself", Tone: "Playful, value-led.", On: true},
		{ID: "white-friday", Name: "White Friday / Black Friday", NameAR: "الجمعة البيضاء", Kind: "rule", Rule: "black_friday", Days: 4, LeadDays: 21, Angle: "stock up on your routine", Tone: "Value and choice; offers only if you enter one.", Products: gift, On: true},
		{ID: "cyber-monday", Name: "Cyber Monday", NameAR: "الاثنين الإلكتروني", Kind: "rule", Rule: "cyber_monday", Days: 1, LeadDays: 14, Angle: "last chance online", Tone: "Short and direct.", On: true},
		{ID: "commemoration", Name: "Commemoration Day", NameAR: "يوم الشهيد", Kind: "fixed", Month: 12, Day: 1, Days: 1, LeadDays: 14, Countries: []string{"AE"}, Angle: "no sales push", Tone: "Remembrance day: no promotional tone; pause seasonal pushes.", On: false},
		{ID: "national-day", Name: "UAE National Day", NameAR: "عيد الاتحاد", Kind: "fixed", Month: 12, Day: 2, Days: 2, LeadDays: 14, Countries: []string{"AE"}, Angle: "celebration glow, UAE pride", Tone: "Festive and proud; UAE colours only as brand-safe accents, never the flag or emblem on products.", On: true},
		{ID: "qatar-national-day", Name: "Qatar National Day", NameAR: "اليوم الوطني القطري", Kind: "fixed", Month: 12, Day: 18, Days: 1, LeadDays: 14, Countries: []string{"QA"}, Angle: "celebration glow", Tone: "Proud and festive.", On: true},
		{ID: "dsf", Name: "Dubai Shopping Festival", NameAR: "مهرجان دبي للتسوق", Kind: "fixed", Month: 12, Day: 6, Days: 38, LeadDays: 14, Countries: []string{"AE"}, Angle: "festival gifting, winter skin barrier", Tone: "Festive.", Products: gift, On: true},
		{ID: "new-year", Name: "New Year", NameAR: "رأس السنة", Kind: "fixed", Month: 12, Day: 24, Days: 9, LeadDays: 14, Angle: "new-year routine, gift sets", Tone: "Fresh start.", Products: gift, On: true},
		{ID: "valentines", Name: "Valentine's Day", NameAR: "عيد الحب", Kind: "fixed", Month: 2, Day: 14, Days: 1, LeadDays: 14, Angle: "gift for her", Tone: "Gifting, tasteful; no romance imagery.", Products: gift, On: true},
		{ID: "founding-day", Name: "Saudi Founding Day", NameAR: "يوم التأسيس", Kind: "fixed", Month: 2, Day: 22, Days: 1, LeadDays: 14, Countries: []string{"SA"}, Angle: "heritage pride", Tone: "Proud.", On: true},
		{ID: "kuwait-national-day", Name: "Kuwait National Day", NameAR: "العيد الوطني الكويتي", Kind: "fixed", Month: 2, Day: 25, Days: 2, LeadDays: 14, Countries: []string{"KW"}, Angle: "celebration", Tone: "Festive.", On: true},
		{ID: "womens-day", Name: "International Women's Day", NameAR: "يوم المرأة العالمي", Kind: "fixed", Month: 3, Day: 8, Days: 1, LeadDays: 14, Angle: "self-care, celebrate her", Tone: "Warm and empowering.", On: true},
		{ID: "mothers-day", Name: "Mother's Day (UAE)", NameAR: "عيد الأم", Kind: "fixed", Month: 3, Day: 21, Days: 1, LeadDays: 14, Angle: "a gift for mum", Tone: "Warm, family.", Products: gift, On: true},
		{ID: "ramadan", Name: "Ramadan", NameAR: "رمضان", Kind: "hijri", Month: 9, Day: 1, Days: 29, LeadDays: 21, Angle: "suhoor/iftar routine, hydration, calm evening ritual", Tone: respectful, Products: []string{"hydrat", "moistur", "lip", "barrier", "cream"}, On: true},
		{ID: "eid-al-fitr", Name: "Eid al-Fitr", NameAR: "عيد الفطر", Kind: "hijri", Month: 10, Day: 1, Days: 3, LeadDays: 21, Angle: "Eid glow and gifting", Tone: "Joyful but modest; family and gifting.", Products: gift, On: true},
		{ID: "summer", Name: "Summer skin (heat & SPF)", NameAR: "عناية الصيف", Kind: "fixed", Month: 5, Day: 15, Days: 108, LeadDays: 14, Angle: "lightweight, oil control, sunscreen textures", Tone: "Fresh; no SPF claims beyond the label.", Products: []string{"sun", "spf", "gel", "oil", "light"}, On: true},
		{ID: "hijri-new-year", Name: "Hijri New Year", NameAR: "رأس السنة الهجرية", Kind: "hijri", Month: 1, Day: 1, Days: 1, LeadDays: 14, Angle: "new beginnings", Tone: respectful, On: false},
		{ID: "dss", Name: "Dubai Summer Surprises", NameAR: "مفاجآت صيف دبي", Kind: "fixed", Month: 7, Day: 1, Days: 45, LeadDays: 14, Countries: []string{"AE"}, Angle: "summer treats", Tone: "Fun, family.", Products: []string{"sun", "spf", "mini"}, On: true},
		{ID: "eid-al-adha", Name: "Eid al-Adha", NameAR: "عيد الأضحى", Kind: "hijri", Month: 12, Day: 10, Days: 4, LeadDays: 21, Angle: "Eid glow and gifting", Tone: "Joyful but modest; family and gifting.", Products: gift, On: true},
		{ID: "prophet-birthday", Name: "Prophet's Birthday", NameAR: "المولد النبوي", Kind: "hijri", Month: 3, Day: 12, Days: 1, LeadDays: 14, Angle: "none (low-key)", Tone: "Low-key and respectful: no sales push.", On: false},
		{ID: "emirati-womens-day", Name: "Emirati Women's Day", NameAR: "يوم المرأة الإماراتية", Kind: "fixed", Month: 8, Day: 28, Days: 1, LeadDays: 14, Countries: []string{"AE"}, Angle: "celebrate her", Tone: "Warm, proud.", On: true},
		{ID: "back-to-school", Name: "Back to school", NameAR: "العودة إلى المدارس", Kind: "fixed", Month: 8, Day: 20, Days: 20, LeadDays: 14, Angle: "easy routine for busy mornings", Tone: "Practical.", On: true},
		{ID: "saudi-national-day", Name: "Saudi National Day", NameAR: "اليوم الوطني السعودي", Kind: "fixed", Month: 9, Day: 23, Days: 1, LeadDays: 14, Countries: []string{"SA"}, Angle: "celebration", Tone: "Proud; green accents only, never the flag.", On: true},
		{ID: "winter", Name: "Winter (dry-skin season)", NameAR: "عناية الشتاء", Kind: "fixed", Month: 11, Day: 15, Days: 90, LeadDays: 14, Angle: "barrier repair, rich hydration", Tone: "Cosy.", Products: []string{"cream", "barrier", "ceramide", "hydrat", "moistur"}, On: true},
	}
}

// ── the tabular Islamic calendar (civil epoch) → Gregorian. Estimated: the real date follows the moon. ──

func islamicToJDN(y, m, d int) int {
	return d + int(math.Ceil(29.5*float64(m-1))) + (y-1)*354 + (3+11*y)/30 + 1948440 - 1
}

func jdnToTime(j int) time.Time {
	// Fliegel–Van Flandern
	l := j + 68569
	n := 4 * l / 146097
	l = l - (146097*n+3)/4
	i := 4000 * (l + 1) / 1461001
	l = l - 1461*i/4 + 31
	k := 80 * l / 2447
	d := l - 2447*k/80
	l = k / 11
	m := k + 2 - 12*l
	y := 100*(n-49) + i + l
	return time.Date(y, time.Month(m), d, 0, 0, 0, 0, time.UTC)
}

// HijriToGregorian: the estimated Gregorian date of a Hijri date.
func HijriToGregorian(y, m, d int) time.Time { return jdnToTime(islamicToJDN(y, m, d)) }

func blackFriday(y int) time.Time {
	t := time.Date(y, 11, 1, 0, 0, 0, 0, time.UTC)
	for t.Weekday() != time.Thursday {
		t = t.AddDate(0, 0, 1)
	}
	return t.AddDate(0, 0, 22) // 4th Thursday + 1
}

// Instances: the occasion's dates whose prep window or run overlaps [from, from+days], soonest first.
func (o Occasion) Instances(from time.Time, days int) []OccasionInstance {
	from = time.Date(from.Year(), from.Month(), from.Day(), 0, 0, 0, 0, time.UTC)
	to := from.AddDate(0, 0, days)
	var starts []time.Time
	est := false
	switch o.Kind {
	case "fixed":
		for y := from.Year() - 1; y <= to.Year()+1; y++ {
			starts = append(starts, time.Date(y, time.Month(o.Month), o.Day, 0, 0, 0, 0, time.UTC))
		}
	case "rule":
		for y := from.Year() - 1; y <= to.Year()+1; y++ {
			bf := blackFriday(y)
			if o.Rule == "cyber_monday" {
				bf = bf.AddDate(0, 0, 3)
			}
			starts = append(starts, bf)
		}
	case "hijri":
		est = true
		hy := int(float64(from.Year()-622)*33/32) + 1
		for y := hy - 2; y <= hy+2; y++ {
			starts = append(starts, HijriToGregorian(y, o.Month, o.Day))
		}
	}
	var out []OccasionInstance
	for _, s := range starts {
		e := s.AddDate(0, 0, maxInt(o.Days, 1)-1)
		lead := maxInt(o.LeadDays, 0)
		prep := s.AddDate(0, 0, -lead)
		if e.Before(from) || prep.After(to) {
			continue
		}
		out = append(out, OccasionInstance{ID: o.ID + "-" + s.Format("2006-01-02"), Occasion: o.ID, Name: o.Name, NameAR: o.NameAR, Start: s.Format("2006-01-02"),
			End: e.Format("2006-01-02"), PrepFrom: prep.Format("2006-01-02"), Estimated: est, Angle: o.Angle, Tone: o.Tone, Offer: o.Offer})
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Start < out[j].Start })
	return out
}

func maxInt(a, b int) int {
	if a > b {
		return a
	}
	return b
}

// Applies: the occasion matters for an account targeting these countries (no countries = everywhere).
func (o Occasion) Applies(countries []string) bool {
	if len(o.Countries) == 0 {
		return true
	}
	for _, c := range o.Countries {
		for _, x := range countries {
			if strings.EqualFold(c, x) {
				return true
			}
		}
	}
	return false
}

// OccasionsOf: the playbook's occasions (the defaults until the owner edits them).
func (p Playbook) OccasionsOf() []Occasion {
	if len(p.Occasions) == 0 {
		return DefaultOccasions()
	}
	return p.Occasions
}

// Upcoming occasions for these countries within days, soonest first.
func (p Playbook) UpcomingOccasions(now time.Time, days int, countries []string) []OccasionInstance {
	var out []OccasionInstance
	for _, o := range p.OccasionsOf() {
		if o.On && o.Applies(countries) {
			out = append(out, o.Instances(now, days)...)
		}
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Start < out[j].Start })
	return out
}

func normOccasions(os []Occasion) []Occasion {
	if len(os) == 0 {
		return nil
	}
	var out []Occasion
	seen := map[string]bool{}
	for _, o := range os {
		o.ID = strings.ToLower(strings.TrimSpace(o.ID))
		if o.ID == "" {
			o.ID = strings.Trim(strings.Map(func(r rune) rune {
				if r >= 'a' && r <= 'z' || r >= '0' && r <= '9' {
					return r
				}
				return '-'
			}, strings.ToLower(o.Name)), "-")
		}
		if o.ID == "" || seen[o.ID] || len(out) >= 80 {
			continue
		}
		o.Name, o.NameAR = cleanText(o.Name, 80), cleanText(o.NameAR, 80)
		o.Angle, o.Tone, o.Offer = cleanText(o.Angle, 200), cleanText(o.Tone, 300), cleanText(o.Offer, 200)
		if o.Kind != "hijri" && o.Kind != "rule" {
			o.Kind = "fixed"
		}
		if o.Month < 1 || o.Month > 12 || o.Day < 1 || o.Day > 31 {
			if o.Kind != "rule" {
				continue
			}
		}
		if o.Kind == "rule" && o.Rule != "black_friday" && o.Rule != "cyber_monday" {
			continue
		}
		if o.Days < 1 || o.Days > 120 {
			o.Days = 1
		}
		if o.LeadDays < 0 || o.LeadDays > 60 {
			o.LeadDays = 14
		}
		var ps []string
		for _, k := range o.Products {
			if k = cleanText(strings.ToLower(k), 40); k != "" && len(ps) < 20 {
				ps = append(ps, k)
			}
		}
		o.Products = ps
		seen[o.ID] = true
		out = append(out, o)
	}
	return out
}

var _ = fmt.Sprint
