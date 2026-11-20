// brain-dry: run the brain offline on a JSON CycleInput (stdin) and print the
// CycleOutput (stdout). Rules-only unless ANTHROPIC_API_KEY is set AND -claude
// is passed. Used by tools/brain-fake-engine.cjs so the UI shows the real
// rules layer's verdicts, and by LANE ENGINE to dry-run a cycle on recorded
// data. -lint reads {"lang","primary_text","headline","description","cta"}
// and prints the linter's result.
package main

import (
	"context"
	"encoding/json"
	"flag"
	"fmt"
	"os"

	"kaa/brain"
)

func main() {
	lint := flag.Bool("lint", false, "lint one copy set instead of running a cycle")
	useClaude := flag.Bool("claude", false, "call Claude (needs ANTHROPIC_API_KEY); default rules-only")
	flag.Parse()
	dec := json.NewDecoder(os.Stdin)
	enc := json.NewEncoder(os.Stdout)
	enc.SetIndent("", " ")
	if *lint {
		var c struct {
			Lang, PrimaryText, Headline, Description, CTA string
			Promo                                         bool
		}
		var raw map[string]any
		if err := dec.Decode(&raw); err != nil {
			fail(err)
		}
		get := func(k string) string { s, _ := raw[k].(string); return s }
		c.Lang, c.PrimaryText, c.Headline, c.Description, c.CTA = get("lang"), get("primary_text"), get("headline"), get("description"), get("cta")
		c.Promo, _ = raw["promo"].(bool)
		_ = enc.Encode(brain.Lint(brain.CopyFields{PrimaryText: c.PrimaryText, Headline: c.Headline, Description: c.Description, CTA: c.CTA},
			brain.LintOptions{Lang: c.Lang, PromoApproved: c.Promo}))
		return
	}
	var in brain.CycleInput
	if err := dec.Decode(&in); err != nil {
		fail(err)
	}
	key := ""
	if *useClaude {
		key = os.Getenv("ANTHROPIC_API_KEY")
	}
	out, err := brain.New(key).Cycle(context.Background(), in)
	if err != nil {
		fail(err)
	}
	_ = enc.Encode(out)
}

func fail(err error) {
	fmt.Fprintln(os.Stderr, "brain-dry:", err)
	os.Exit(1)
}
