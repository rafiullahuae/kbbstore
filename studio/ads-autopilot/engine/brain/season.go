package brain

import (
	"sort"
	"time"
)

// Season is a UAE/GCC shopping window. Dates marked Approx are announced
// late (moon sighting, retailer calendars) and should be confirmed by the
// research job each year; the brain says "approx" wherever it shows them.
type Season struct {
	Name         string
	NameAR       string
	Start, End   string // YYYY-MM-DD inclusive
	Approx       bool
	PrepDays     int     // creative must be live this many days before Start
	CPATolerance float64 // target CPA multiplier while inside (CPMs rise in peaks)
	Angle        string  // the creative angle the window suggests
	Kind         string  // peak | gifting | payday | religious
}

// Calendar 2026-27. Sources: Khaleej Times / The National / Gulf News on 2027
// Ramadan & Eid dates (expected, moon sighting); UAE National Day 2 Dec is
// fixed (holiday 2-3 Dec); White Friday & DSF are retailer/festival calendars
// announced each year (approx here). Payday is an ASSUMPTION (UAE salaries
// mostly land 25th-1st), to be replaced by the shop's own order rhythm.
var calendar = []Season{
	{Name: "11.11 Singles Day", NameAR: "عروض ١١.١١", Start: "2026-11-09", End: "2026-11-12", Approx: false, PrepDays: 7, CPATolerance: 1.1, Angle: "gift-yourself", Kind: "peak"},
	{Name: "White Friday", NameAR: "الجمعة البيضاء", Start: "2026-11-20", End: "2026-12-01", Approx: true, PrepDays: 10, CPATolerance: 1.15, Angle: "stock-up value sets", Kind: "peak"},
	{Name: "UAE National Day", NameAR: "عيد الاتحاد", Start: "2026-11-30", End: "2026-12-03", Approx: false, PrepDays: 10, CPATolerance: 1.1, Angle: "celebration glow, UAE pride (no flag misuse)", Kind: "peak"},
	{Name: "Dubai Shopping Festival", NameAR: "مهرجان دبي للتسوق", Start: "2026-12-11", End: "2027-01-11", Approx: true, PrepDays: 10, CPATolerance: 1.1, Angle: "festival gifting, winter skin barrier", Kind: "peak"},
	{Name: "New Year gifting", NameAR: "هدايا رأس السنة", Start: "2026-12-20", End: "2027-01-01", Approx: false, PrepDays: 7, CPATolerance: 1.05, Angle: "gift sets", Kind: "gifting"},
	{Name: "Pre-Ramadan stock-up", NameAR: "استعدادات رمضان", Start: "2027-01-25", End: "2027-02-07", Approx: true, PrepDays: 10, CPATolerance: 1.05, Angle: "routine for long fasting days: hydration", Kind: "religious"},
	{Name: "Ramadan", NameAR: "رمضان", Start: "2027-02-08", End: "2027-03-08", Approx: true, PrepDays: 14, CPATolerance: 1.2, Angle: "suhoor/iftar routine, hydration, calm evening ritual; post-iftar hours", Kind: "religious"},
	{Name: "Eid al-Fitr", NameAR: "عيد الفطر", Start: "2027-02-27", End: "2027-03-12", Approx: true, PrepDays: 12, CPATolerance: 1.2, Angle: "Eid glow, gifting, last-10-nights orders before Eid", Kind: "gifting"},
	{Name: "Mother's Day (Arab world)", NameAR: "عيد الأم", Start: "2027-03-12", End: "2027-03-21", Approx: false, PrepDays: 10, CPATolerance: 1.1, Angle: "gift for mum", Kind: "gifting"},
	{Name: "Summer skin (heat & SPF)", NameAR: "عناية الصيف", Start: "2027-05-15", End: "2027-08-31", Approx: false, PrepDays: 7, CPATolerance: 1.0, Angle: "lightweight, oil control, sunscreen textures (no SPF claims beyond the label)", Kind: "peak"},
	{Name: "Eid al-Adha", NameAR: "عيد الأضحى", Start: "2027-08-08", End: "2027-08-17", Approx: true, PrepDays: 10, CPATolerance: 1.15, Angle: "Eid glow, gifting", Kind: "gifting"},
	{Name: "UAE National Day", NameAR: "عيد الاتحاد", Start: "2027-11-29", End: "2027-12-03", Approx: false, PrepDays: 10, CPATolerance: 1.1, Angle: "celebration glow", Kind: "peak"},
}

// SeasonAt returns the active season with the highest tolerance, or nil.
func SeasonAt(t time.Time) *Season {
	d := t.Format("2006-01-02")
	var best *Season
	for i := range calendar {
		s := &calendar[i]
		if d >= s.Start && d <= s.End && (best == nil || s.CPATolerance > best.CPATolerance) {
			best = s
		}
	}
	return best
}

// IsPayday: assumption -- the 25th through the 1st of the next month.
func IsPayday(t time.Time) bool { return t.Day() >= 25 || t.Day() == 1 }

// Upcoming returns seasons active now or starting within `days`, sorted by start.
func Upcoming(t time.Time, days int) []SeasonPush {
	from := t.Format("2006-01-02")
	to := t.AddDate(0, 0, days).Format("2006-01-02")
	var out []SeasonPush
	for _, s := range calendar {
		if s.End < from || s.Start > to {
			continue
		}
		st, _ := time.Parse("2006-01-02", s.Start)
		out = append(out, SeasonPush{Name: s.Name, NameAR: s.NameAR, Start: s.Start, End: s.End, Approx: s.Approx,
			PrepFrom: st.AddDate(0, 0, -s.PrepDays).Format("2006-01-02"), Action: "Creative live by prep date; angle: " + s.Angle})
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Start < out[j].Start })
	return out
}
