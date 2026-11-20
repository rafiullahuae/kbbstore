package brain

import (
	"context"
	"encoding/json"
	"fmt"
	"net/url"
	"sort"
	"strings"
	"time"
)

// ResearchEvery is how stale notes may get before the scheduler refreshes
// them (the engine's scheduler calls Research when Due says so).
const ResearchEvery = 72 * time.Hour

// Due reports whether research should be refreshed.
func Due(r Research, now time.Time) bool {
	return r.UpdatedAt.IsZero() || now.Sub(r.UpdatedAt) >= ResearchEvery
}

// Research runs two calls:
//  1. Sonnet + web_search (UAE-localised, max 12 searches) writes a sourced
//     briefing; we collect every URL it actually cited or searched.
//  2. Haiku turns the briefing into structured notes (structured output).
//
// Every note's sources are then checked in code against the URLs from step 1:
// a source the search never returned is removed, and a note left with no
// source is dropped. So no note can cite a page nobody read.
func (b *Brain) Research(ctx context.Context, in ResearchInput) (Research, error) {
	now := in.Now
	if now.IsZero() {
		now = time.Now()
	}
	if !b.hasClaude() {
		return in.Previous, ErrNoKey
	}
	focus := "all topics"
	if len(in.Focus) > 0 {
		focus = strings.Join(in.Focus, ", ")
	}
	var brands []string
	seen := map[string]bool{}
	for _, p := range pickProducts(in.Shop, Research{}, 60) {
		if p.Brand != "" && !seen[p.Brand] && len(brands) < 15 {
			seen[p.Brand] = true
			brands = append(brands, p.Brand)
		}
	}
	var prev []string
	for i, n := range in.Previous.Notes {
		if i < 20 {
			prev = append(prev, n.Date.Format("2006-01-02")+" "+n.Title)
		}
	}
	ask := fmt.Sprintf("Today is %s. Focus: %s. Brands the shop carries: %s. Earlier notes (refresh or extend, do not repeat): %s.\nResearch: (1) skincare trends and ingredients GCC/UAE shoppers want now; (2) formats and hooks that win for K-beauty on Instagram/TikTok now; (3) UAE/GCC K-beauty competitors and what they advertise (Meta Ad Library pages if reachable); (4) Arabic-speaking audience insights for beauty ads; (5) dates of upcoming UAE shopping moments (White Friday, DSF, Ramadan, Eid) for this season.",
		now.Format("2 January 2006"), focus, strings.Join(brands, ", "), strings.Join(prev, "; "))
	tool := map[string]any{"type": WebSearchTool, "name": "web_search", "max_uses": 12,
		"user_location": map[string]any{"type": "approximate", "country": "AE", "city": "Dubai", "timezone": "Asia/Dubai"}}
	req := Request{Model: b.PlannerModel, MaxTokens: 16000, System: CachedSystem(researchSystem),
		Messages: []Message{{Role: "user", Content: ask}}, Tools: []any{tool}, Output: map[string]any{"effort": "medium"}}
	var u Usage
	var brief strings.Builder
	urls := map[string]string{} // url -> title (everything search returned or text cited)
	for turn := 0; turn < 4; turn++ {
		resp, err := b.Claude.Send(ctx, req)
		AddUsage(&u, b.PlannerModel, resp)
		if err != nil {
			return in.Previous, err
		}
		collectURLs(resp, urls)
		for _, c := range resp.Blocks {
			if c.Type == "text" {
				brief.WriteString(c.Text)
				for _, ci := range c.Citations {
					if ci.URL != "" {
						brief.WriteString(" [" + ci.URL + "]")
					}
				}
			}
		}
		if resp.StopReason != "pause_turn" {
			if resp.StopReason == "refusal" {
				return in.Previous, fmt.Errorf("research refused")
			}
			break
		}
		var prevBlocks []any
		for _, r := range resp.Raw {
			prevBlocks = append(prevBlocks, r)
		}
		req.Messages = append(req.Messages, Message{Role: "assistant", Content: prevBlocks})
	}
	if brief.Len() == 0 {
		return in.Previous, fmt.Errorf("research returned no briefing")
	}
	// Step 2: normalise with the small model.
	nreq := Request{Model: b.SmallModel, MaxTokens: 12000, System: CachedSystem(normalizeSystem),
		Messages: []Message{{Role: "user", Content: "Briefing:\n" + brief.String()}}, Output: JSONOutput(researchSchema(), "low")}
	nresp, err := b.Claude.Send(ctx, nreq)
	AddUsage(&u, b.SmallModel, nresp)
	if err != nil {
		return in.Previous, err
	}
	raw := strings.TrimSpace(nresp.Text())
	var tagged struct {
		Notes []struct {
			Topic   string   `json:"topic"`
			Title   string   `json:"title"`
			TitleAR string   `json:"title_ar"`
			Body    string   `json:"body"`
			BodyAR  string   `json:"body_ar"`
			Angles  []string `json:"angles"`
			Sources []string `json:"source_urls"`
		} `json:"notes"`
	}
	if err := json.Unmarshal([]byte(raw), &tagged); err != nil {
		return in.Previous, fmt.Errorf("research notes JSON: %v", err)
	}
	fresh := Research{UpdatedAt: now}
	for i, n := range tagged.Notes {
		var srcs []Source
		for _, s := range n.Sources {
			if t, ok := matchURL(urls, s); ok {
				srcs = append(srcs, Source{URL: s, Title: t})
			}
		}
		if len(srcs) == 0 || strings.TrimSpace(n.Title) == "" {
			continue
		}
		// angles pass the linter too (they seed copy)
		var angles []string
		for _, an := range clean(n.Angles, 3) {
			if lr := Lint(CopyFields{Headline: an}, LintOptions{}); !lr.Blocked {
				angles = append(angles, an)
			}
		}
		strength := 0.4 + 0.2*float64(min(len(srcs), 3))
		fresh.Notes = append(fresh.Notes, ResearchNote{ID: fmt.Sprintf("n%s-%d", now.Format("060102"), i), Topic: n.Topic,
			Title: n.Title, TitleAR: n.TitleAR, Body: n.Body, BodyAR: n.BodyAR, Angles: angles, Sources: srcs, Date: now, Strength: round2(strength)})
	}
	if len(fresh.Notes) == 0 {
		return in.Previous, fmt.Errorf("no sourced notes survived the source check")
	}
	// Keep older notes that are < 45 days old and not superseded by topic+title.
	have := map[string]bool{}
	for _, n := range fresh.Notes {
		have[strings.ToLower(n.Title)] = true
	}
	for _, n := range in.Previous.Notes {
		if !have[strings.ToLower(n.Title)] && now.Sub(n.Date) < 45*24*time.Hour {
			fresh.Notes = append(fresh.Notes, n)
		}
	}
	sort.SliceStable(fresh.Notes, func(i, j int) bool { return fresh.Notes[i].Date.After(fresh.Notes[j].Date) })
	if len(fresh.Notes) > 60 {
		fresh.Notes = fresh.Notes[:60]
	}
	b.mu.Lock()
	b.lastResearch = u
	b.mu.Unlock()
	return fresh, nil
}

// LastResearchUsage: what the last research run cost (Research's signature is
// the contract's and has no usage return), for the engine to log.
func (b *Brain) LastResearchUsage() Usage {
	b.mu.Lock()
	defer b.mu.Unlock()
	return b.lastResearch
}

// collectURLs gathers URLs from web_search_tool_result blocks (a list on
// success, an error object otherwise) and from text citations.
func collectURLs(r *Response, into map[string]string) {
	for _, c := range r.Blocks {
		switch c.Type {
		case "web_search_tool_result":
			var tagged []struct {
				URL   string `json:"url"`
				Title string `json:"title"`
			}
			if err := json.Unmarshal(c.Content, &tagged); err == nil {
				for _, x := range tagged {
					if x.URL != "" {
						into[x.URL] = x.Title
					}
				}
			}
		case "text":
			for _, ci := range c.Citations {
				if ci.URL != "" {
					into[ci.URL] = ci.Title
				}
			}
		}
	}
}

// matchURL accepts exact URLs, or the same host+path ignoring scheme,
// "www." and a trailing slash.
func matchURL(urls map[string]string, s string) (string, bool) {
	if t, ok := urls[s]; ok {
		return t, true
	}
	k := urlKey(s)
	if k == "" {
		return "", false
	}
	for u, t := range urls {
		if urlKey(u) == k {
			return t, true
		}
	}
	return "", false
}

func urlKey(s string) string {
	u, err := url.Parse(strings.TrimSpace(s))
	if err != nil || u.Host == "" {
		return ""
	}
	return strings.TrimPrefix(strings.ToLower(u.Host), "www.") + strings.TrimSuffix(u.Path, "/")
}
