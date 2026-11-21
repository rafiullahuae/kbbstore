// Package exchange: the hand-off between the Mac app and the scheduled cloud Claude Code task that makes ads while
// the Mac is closed (the owner, Nov 2026: "if the job is sent to claude for creation, the claude should not stop even
// i'm away, and when i come online ... the system should sync back everything").
//
// The exchange is a plain private GitHub repository (default rafiullahuae/kbb-ad-exchange). No secrets ever go in
// it: product catalogue facts, the owner's Playbook wording, aggregated lessons, and the ads that come back.
//
//	jobs/<account>/<job>/brief.json      everything the creator needs (Brief)          written by the Mac
//	jobs/<account>/<job>/status.json     queued | withdrawn                             written by the Mac
//	jobs/<account>/<job>/claim.json      {worker, at}: one worker owns it for 3 h       written by the worker
//	results/<account>/<job>/ads.json     the ideas (copy per language) + every render   written by the worker
//	results/<account>/<job>/*.png|*.mp4  the rendered creatives
//	results/<account>/<job>/done.json    who / when / plan: the job is finished         written by the worker, LAST
//	feedback/<account>/<job>/import.json what the Mac kept, refused and why, and what the owner did with them
//
// A job id is the creation request's id, so pushing the same request twice is the same job (idempotent). A job is
// claimed by committing claim.json; a ref update that is not a fast-forward fails, so two workers that both try find
// out on push and the loser re-reads and walks away. A claim older than ClaimTTL with no done.json can be taken over.
package exchange

import (
	"encoding/json"
	"errors"
	"fmt"
	"regexp"
	"sort"
	"strings"
	"time"
)

const (
	DefaultOwner = "rafiullahuae"
	DefaultRepo  = "kbb-ad-exchange"
	ClaimTTL     = 3 * time.Hour
	MaxFile      = 40 << 20 // a render bigger than this is refused (Meta's own image limit is 30 MB)
)

var (
	segRe  = regexp.MustCompile(`^[A-Za-z0-9_-]{1,64}$`)
	fileRe = regexp.MustCompile(`^[a-z0-9][a-z0-9_.-]{0,80}\.(png|mp4)$`)
	slugRe = regexp.MustCompile(`^[A-Za-z0-9](?:[A-Za-z0-9._-]{0,99})$`)
)

// ValidRepo: a GitHub owner and repository name.
func ValidRepo(owner, repo string) error {
	if !slugRe.MatchString(owner) || !slugRe.MatchString(repo) || strings.Contains(owner, "..") || strings.Contains(repo, "..") {
		return errors.New("owner and repository are GitHub names (letters, digits, - _ .), e.g. rafiullahuae / kbb-ad-exchange")
	}
	return nil
}

// ValidToken: the shape of a GitHub token (fine-grained github_pat_… preferred; classic ghp_… accepted, then
// refused by Check when it reaches other private repositories).
func ValidToken(t string) error {
	if !(strings.HasPrefix(t, "github_pat_") || strings.HasPrefix(t, "ghp_")) || len(t) < 30 || len(t) > 255 {
		return errors.New("GitHub keys start with github_pat_ (fine-grained, recommended) or ghp_: create one with the button on this card")
	}
	for _, r := range t {
		if !(r == '_' || r >= '0' && r <= '9' || r >= 'a' && r <= 'z' || r >= 'A' && r <= 'Z') {
			return errors.New("the key has a character GitHub keys do not have: paste it again")
		}
	}
	return nil
}

func JobDir(acct, job string) string    { return "jobs/" + acct + "/" + job }
func ResultDir(acct, job string) string { return "results/" + acct + "/" + job }
func FeedDir(acct, job string) string   { return "feedback/" + acct + "/" + job }

// ValidJob: account and job ids are single safe path segments.
func ValidJob(acct, job string) bool { return segRe.MatchString(acct) && segRe.MatchString(job) }

// ── the files ──

type Product struct {
	ID       string   `json:"id"`
	Title    string   `json:"title"`
	Brand    string   `json:"brand,omitempty"`
	Category string   `json:"category,omitempty"`
	PriceAED float64  `json:"price_aed"`
	SaleAED  float64  `json:"sale_aed,omitempty"`
	PriceEN  string   `json:"price_en"`        // as the designs print it ("AED 89")
	PriceAR  string   `json:"price_ar"`        // ("89 درهم")
	Facts    string   `json:"facts,omitempty"` // the shop's own description: benefits, ingredients, how to use
	Photo    string   `json:"photo"`           // https://kbeautybliss.com/… product photo (the renderer cuts it out)
	Photos   []string `json:"photos,omitempty"`
	URL      string   `json:"url,omitempty"`
	Trending bool     `json:"trending,omitempty"`
}

type Linter struct {
	Mode          string   `json:"mode"` // strict | balanced | relaxed
	PromoApproved bool     `json:"promo_approved"`
	Rules         []string `json:"rules"`
}

// Brief: everything the cloud creator needs, nothing else (no keys, no customers, no orders).
type Brief struct {
	Version           int               `json:"version"`
	JobID             string            `json:"job_id"`
	Account           string            `json:"account"`
	At                time.Time         `json:"at"`
	Title             string            `json:"title"`
	TitleAR           string            `json:"title_ar"`
	Count             int               `json:"count"` // ideas wanted (each in every language and format)
	Langs             []string          `json:"langs"`
	Formats           []string          `json:"formats"`
	Motion            bool              `json:"motion"` // also one 9:16 motion version (MP4) per idea
	Angle             string            `json:"angle"`
	Occasion          string            `json:"occasion,omitempty"`
	Products          []Product         `json:"products"`      // the products this job is for
	MoreProducts      []Product         `json:"more_products"` // others it may pair them with (routines, collections)
	Offers            []string          `json:"offers"`        // what the copy may say about offers (empty: none)
	OwnerInstructions string            `json:"owner_instructions_always_obey"`
	Linter            Linter            `json:"linter"`
	DesignFamilies    []string          `json:"design_families"`
	BrandColours      map[string]string `json:"brand_colours"`
	Lessons           string            `json:"lessons,omitempty"`
	Winning           []string          `json:"winning_patterns,omitempty"`
	Research          []string          `json:"research,omitempty"`
	Extra             string            `json:"extra,omitempty"`
	Output            string            `json:"output"` // what to write back (the ads.json shape)
}

type Status struct {
	State string    `json:"state"` // queued | withdrawn | failed
	At    time.Time `json:"at"`
	Note  string    `json:"note,omitempty"`
}

type Claim struct {
	Worker string    `json:"worker"`
	At     time.Time `json:"at"`
}

// Set / Idea: the same JSON as the app's Create with Claude ideas (brain.CreateIdea), so an import is an authored
// session like the ones made in the Claude desktop app.
type Set struct {
	Lang        string   `json:"lang"`
	Headline    string   `json:"headline"`
	PrimaryText string   `json:"primary_text"`
	Sub         string   `json:"sub"`
	Bullets     []string `json:"bullets"`
	Badge       string   `json:"badge"`
	CTA         string   `json:"cta"`
	Hooks       []string `json:"hooks"`
}

type Idea struct {
	ProductIDs []string `json:"product_ids"`
	Angle      string   `json:"angle"`
	Hook       string   `json:"hook"`
	Template   string   `json:"template"`
	Palette    []string `json:"palette"`
	ShowPrice  bool     `json:"show_price"`
	Formats    []string `json:"formats"`
	Motion     bool     `json:"motion"`
	Scene      string   `json:"scene"`
	Sets       []Set    `json:"sets"`
}

// Quality: the app's own quality gate (app/js/creative/quality.js score()), run on the render in the cloud.
type Quality struct {
	OK     bool     `json:"ok"`
	Score  int      `json:"score"`
	Fails  []string `json:"fails"`
	Checks struct {
		ProductShare   float64 `json:"product_share"`
		MinContrast    float64 `json:"min_contrast"`
		SafeViolations int     `json:"safe_violations"`
		TextDensity    float64 `json:"text_density"`
		Arabic         *struct {
			OK bool `json:"ok"`
		} `json:"arabic"`
		Cutout string `json:"cutout"`
	} `json:"checks"`
}

type Render struct {
	Idea    int     `json:"idea"` // 1-based
	Lang    string  `json:"lang"`
	Format  string  `json:"format"`
	Kind    string  `json:"kind"` // png | mp4
	File    string  `json:"file"`
	Family  string  `json:"family"`
	Quality Quality `json:"quality"`
}

type Ads struct {
	JobID   string   `json:"job_id"`
	Worker  string   `json:"worker"`
	Ideas   []Idea   `json:"ideas"`
	Renders []Render `json:"renders"`
	Refused []Render `json:"refused,omitempty"` // renders the cloud's own gate refused (kept for the record)
}

type Done struct {
	JobID    string    `json:"job_id"`
	Worker   string    `json:"worker"`
	Plan     string    `json:"plan"` // "Claude Max (scheduled Claude Code task)"
	At       time.Time `json:"at"`
	Rendered int       `json:"rendered"`
	Refused  int       `json:"refused"`
	Error    string    `json:"error,omitempty"`
}

type Rejection struct {
	File string `json:"file,omitempty"`
	Why  string `json:"why"`
}

type Feedback struct {
	JobID    string      `json:"job_id"`
	At       time.Time   `json:"at"`
	Imported []string    `json:"imported"` // files kept
	Rejected []Rejection `json:"rejected"` // files the Mac refused, and why (the worker learns from these)
	OwnerRan []string    `json:"owner_ran,omitempty"`
	Removed  []string    `json:"owner_removed,omitempty"`
	Edits    []string    `json:"owner_edits,omitempty"`
	Ignored  string      `json:"ignored,omitempty"` // the whole job was not imported (withdrawn first)
}

// ── reading a snapshot ──

// Job: one job as the snapshot shows it.
type Job struct {
	Account, ID string
	Status      Status
	Claim       *Claim
	Done        bool
	HasBrief    bool
	Feedback    bool
}

// State: what a job is doing now (for counts and the UI).
func (j Job) State(now time.Time) string {
	switch {
	case j.Status.State == "withdrawn":
		return "withdrawn"
	case j.Done:
		return "finished"
	case j.Status.State == "failed":
		return "failed"
	case j.Claim != nil && now.Sub(j.Claim.At) < ClaimTTL:
		return "working"
	default:
		return "waiting"
	}
}

// Jobs: every job in a snapshot (reads status.json and claim.json, which are tiny).
func Jobs(r Remote, s *Snapshot) ([]Job, error) {
	by := map[string]*Job{}
	for p := range s.Files {
		parts := strings.Split(p, "/")
		if len(parts) != 4 || (parts[0] != "jobs" && parts[0] != "results") || !ValidJob(parts[1], parts[2]) {
			continue
		}
		k := parts[1] + "/" + parts[2]
		j := by[k]
		if j == nil {
			j = &Job{Account: parts[1], ID: parts[2]}
			by[k] = j
		}
		switch {
		case parts[0] == "jobs" && parts[3] == "brief.json":
			j.HasBrief = true
		case parts[0] == "jobs" && parts[3] == "status.json":
			_ = ReadJSON(r, s, p, &j.Status)
		case parts[0] == "jobs" && parts[3] == "claim.json":
			var c Claim
			if ReadJSON(r, s, p, &c) == nil {
				j.Claim = &c
			}
		case parts[0] == "results" && parts[3] == "done.json":
			j.Done = true
		}
	}
	for p := range s.Files {
		parts := strings.Split(p, "/")
		if len(parts) == 4 && parts[0] == "feedback" && parts[3] == "import.json" {
			if j := by[parts[1]+"/"+parts[2]]; j != nil {
				j.Feedback = true
			}
		}
	}
	out := make([]Job, 0, len(by))
	for _, j := range by {
		if j.HasBrief {
			out = append(out, *j)
		}
	}
	sort.Slice(out, func(a, b int) bool { return out[a].Account+out[a].ID < out[b].Account+out[b].ID })
	return out, nil
}

func ReadJSON(r Remote, s *Snapshot, p string, v any) error {
	e, ok := s.Files[p]
	if !ok {
		return fmt.Errorf("%s: not in the exchange", p)
	}
	if e.Size > MaxFile {
		return fmt.Errorf("%s: too big", p)
	}
	b, err := r.Read(e)
	if err != nil {
		return err
	}
	return json.Unmarshal(b, v)
}

// ValidFile: a render file name the worker may write.
func ValidFile(name string) bool { return fileRe.MatchString(name) }

// ── writing ──

// Claim: take a job for a worker (the protocol the cloud worker follows; Go has it for tests and tools). Returns
// false when another worker holds a fresh claim, the job is finished or withdrawn. A lost race (the ref moved) is
// re-read once.
func ClaimJob(r Remote, acct, job, worker string, now time.Time) (bool, error) {
	for try := 0; try < 3; try++ {
		s, err := r.Snapshot()
		if err != nil {
			return false, err
		}
		js, _ := Jobs(r, s)
		var j *Job
		for i := range js {
			if js[i].Account == acct && js[i].ID == job {
				j = &js[i]
			}
		}
		if j == nil || j.State(now) != "waiting" {
			return false, nil
		}
		b, _ := json.MarshalIndent(Claim{Worker: worker, At: now.UTC()}, "", " ")
		err = r.Commit(s, "claim "+acct+"/"+job+" by "+worker, map[string][]byte{JobDir(acct, job) + "/claim.json": b}, nil)
		if errors.Is(err, ErrConflict) {
			continue
		}
		return err == nil, err
	}
	return false, ErrConflict
}

func MustJSON(v any) []byte {
	b, _ := json.MarshalIndent(v, "", " ")
	return append(b, '\n')
}
