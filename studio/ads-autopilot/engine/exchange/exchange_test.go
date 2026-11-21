package exchange

import (
	"os"
	"regexp"
	"strconv"
	"strings"
	"testing"
	"time"
)

const tok = "github_pat_11TESTTESTTEST0123456789abcdefghijklmnopqrstuvwxyz"

// An empty repository gets its first commit through the contents API, then the commit lands on top of it.
// MUTATION: return nil instead of ErrConflict after the README → the jobs are never written, red.
func TestEmptyRepoFirstCommit(t *testing.T) {
	f := NewFakeGitHub(tok)
	defer f.Close()
	g := NewGitHub(f.URL, DefaultOwner, DefaultRepo, tok)
	put := map[string][]byte{"jobs/act_1/cq1/brief.json": []byte("{}"), "jobs/act_1/cq1/status.json": MustJSON(Status{State: "queued"})}
	for i := 0; ; i++ {
		s, err := g.Snapshot()
		if err != nil {
			t.Fatal(err)
		}
		if err = g.Commit(s, "x", put, nil); err == nil {
			break
		} else if err != ErrConflict || i > 2 {
			t.Fatal(err)
		}
	}
	if fs := f.Files(); len(fs) != 3 || fs["README.md"] == nil {
		t.Fatalf("%v", fs)
	}
}

// Two workers that read the same head: the second ref update is not a fast-forward, it re-reads, sees the claim and
// walks away. A claim older than ClaimTTL is taken over. MUTATION: force:true / skip the 422 → both claim, red.
func TestClaimRace(t *testing.T) {
	f := NewFakeGitHub(tok)
	defer f.Close()
	f.Put(map[string][]byte{"jobs/a/j/brief.json": []byte("{}"), "jobs/a/j/status.json": MustJSON(Status{State: "queued"})})
	g := NewGitHub(f.URL, DefaultOwner, DefaultRepo, tok)
	now := time.Now()
	f.BeforeRef = func() { // the other worker's claim lands between our read and our ref update
		f.Put(map[string][]byte{"jobs/a/j/claim.json": MustJSON(Claim{Worker: "other", At: now})})
	}
	ok, err := ClaimJob(g, "a", "j", "me", now)
	if err != nil || ok {
		t.Fatalf("won a lost race: %v %v", ok, err)
	}
	if !strings.Contains(string(f.Files()["jobs/a/j/claim.json"]), "other") {
		t.Fatal("the winner's claim was overwritten")
	}
	if ok, _ := ClaimJob(g, "a", "j", "me", now.Add(ClaimTTL+time.Minute)); !ok {
		t.Fatal("a stale claim was not taken over")
	}
	f.Put(map[string][]byte{"results/a/j/done.json": []byte("{}")})
	if ok, _ := ClaimJob(g, "a", "j", "late", now.Add(10*ClaimTTL)); ok {
		t.Fatal("a finished job was claimed")
	}
}

func TestValidation(t *testing.T) {
	if ValidToken("ghp_short") == nil || ValidToken("sk-ant-api03-aaaaaaaaaaaaaaaaaaaaaaaaaaaa") == nil || ValidToken(tok) != nil || ValidToken(tok+" x") == nil {
		t.Fatal("token shapes")
	}
	if ValidRepo("rafiullahuae", "kbb-ad-exchange") != nil || ValidRepo("a/b", "c") == nil || ValidRepo("x", "..") == nil {
		t.Fatal("repo names")
	}
	if ValidJob("act_1", "../x") || !ValidJob("act_1", "cq1a") || ValidFile("../a.png") || !ValidFile("i1-ar-4x5.png") || ValidFile("a.exe") {
		t.Fatal("paths")
	}
}

// The Mac's thresholds are quality.js's: change one there without here and this is red.
func TestThresholdsMatchQualityJS(t *testing.T) {
	b, err := os.ReadFile("../../app/js/creative/quality.js")
	if err != nil {
		t.Fatal(err)
	}
	m := regexp.MustCompile(`THRESHOLDS = \{ product: ([\d.]+), contrastSmall: ([\d.]+), contrastLarge: ([\d.]+), density: ([\d.]+)`).FindStringSubmatch(string(b))
	if m == nil {
		t.Fatal("THRESHOLDS not found in quality.js")
	}
	want := []float64{Thresholds.Product, Thresholds.ContrastSmall, Thresholds.ContrastLarge, Thresholds.Density}
	for i, w := range want {
		if v, _ := strconv.ParseFloat(m[i+1], 64); v != w {
			t.Fatalf("threshold %d: quality.js %v, Go %v", i, v, w)
		}
	}
}
