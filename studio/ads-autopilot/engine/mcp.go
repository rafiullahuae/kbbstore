package main

// mcp.go — the Claude desktop connector. The owner (Nov 2026) has a Claude Max plan and wants ad CREATION to run on
// it, with him present. A Max login may only be used by Claude's own apps, so Claude (desktop / Code) drives this app
// through a LOCAL MCP server: Claude desktop starts `engine -mcp -data <data folder>` (claude_desktop_config.json,
// mcpServers; stdio transport, newline-delimited JSON-RPC 2.0) and this process forwards each tool call to the engine
// the owner opened (127.0.0.1, its address in <data>/engine.json, its per-launch token from /api/info).
//
// The tools are thin wrappers over the same pipeline as the in-app box: the catalogue check, the policy linter, the
// app's local renderer and quality gate, and the executor (limits, Practice/Auto). Nothing here calls the Claude API:
// the words come from the owner's own Claude. Every write is idempotent (a key derived from the call's arguments), so
// a retried tool call returns the first call's result. Render progress streams as MCP progress notifications.

import (
	"bufio"
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"
)

var mcpProtocols = []string{"2026-07-28", "2025-11-25", "2025-06-18", "2025-03-26", "2024-11-05"}

type mcpConn struct {
	data string
	out  io.Writer
	wmu  sync.Mutex
	hc   *http.Client
}

type rpcMsg struct {
	JSONRPC string          `json:"jsonrpc"`
	ID      json.RawMessage `json:"id,omitempty"`
	Method  string          `json:"method,omitempty"`
	Params  json.RawMessage `json:"params,omitempty"`
}

func runMCP(dataDir string, in io.Reader, out io.Writer) int {
	c := &mcpConn{data: dataDir, out: out, hc: &http.Client{Timeout: 6 * time.Minute}}
	sc := bufio.NewScanner(in)
	sc.Buffer(make([]byte, 1<<20), 16<<20)
	var wg sync.WaitGroup
	for sc.Scan() {
		line := bytes.TrimSpace(sc.Bytes())
		if len(line) == 0 {
			continue
		}
		var m rpcMsg
		if err := json.Unmarshal(line, &m); err != nil {
			c.send(map[string]any{"jsonrpc": "2.0", "id": nil, "error": map[string]any{"code": -32700, "message": "parse error"}})
			continue
		}
		if len(m.ID) == 0 { // a notification (initialized, cancelled): nothing to answer
			continue
		}
		wg.Add(1)
		go func(m rpcMsg) { defer wg.Done(); c.handle(m) }(m)
	}
	wg.Wait()
	return 0
}

func (c *mcpConn) send(v any) {
	b, _ := json.Marshal(v)
	c.wmu.Lock()
	_, _ = c.out.Write(append(b, '\n'))
	c.wmu.Unlock()
}

func (c *mcpConn) reply(id json.RawMessage, result any) {
	c.send(map[string]any{"jsonrpc": "2.0", "id": id, "result": result})
}

func (c *mcpConn) handle(m rpcMsg) {
	switch m.Method {
	case "initialize":
		var p struct {
			ProtocolVersion string `json:"protocolVersion"`
		}
		_ = json.Unmarshal(m.Params, &p)
		v := "2025-06-18"
		for _, x := range mcpProtocols {
			if x == p.ProtocolVersion {
				v = x
			}
		}
		c.reply(m.ID, map[string]any{"protocolVersion": v, "capabilities": map[string]any{"tools": map[string]any{"listChanged": false}},
			"serverInfo": map[string]any{"name": mcpName, "title": "KBB Ads Autopilot", "version": version},
			"instructions": "KBB Ads Autopilot makes Meta ads for kbeautybliss.com (Korean skincare, UAE/GCC, mostly Arab women). " +
				"Start with get_brief. Write Arabic first (Modern Standard Arabic, warm, Gulf-friendly, feminine address) and English; " +
				"create_ad_set renders previews in the owner's open app; revise changes only what he asks; save_to_library and run_it only when he says so."})
		go c.seen("initialize")
	case "ping":
		c.reply(m.ID, map[string]any{})
	case "tools/list":
		c.reply(m.ID, map[string]any{"tools": mcpTools()})
	case "tools/call":
		var p struct {
			Name      string         `json:"name"`
			Arguments map[string]any `json:"arguments"`
			Meta      struct {
				ProgressToken any `json:"progressToken"`
			} `json:"_meta"`
		}
		if err := json.Unmarshal(m.Params, &p); err != nil {
			c.send(map[string]any{"jsonrpc": "2.0", "id": m.ID, "error": map[string]any{"code": -32602, "message": "bad params"}})
			return
		}
		progress := func(done, total int, msg string) {
			if p.Meta.ProgressToken == nil {
				return
			}
			c.send(map[string]any{"jsonrpc": "2.0", "method": "notifications/progress", "params": map[string]any{"progressToken": p.Meta.ProgressToken, "progress": done, "total": total, "message": msg}})
		}
		content, err := c.call(p.Name, p.Arguments, progress)
		if err != nil {
			c.reply(m.ID, map[string]any{"content": []any{map[string]any{"type": "text", "text": err.Error()}}, "isError": true})
			return
		}
		c.reply(m.ID, map[string]any{"content": content, "isError": false})
	default:
		c.send(map[string]any{"jsonrpc": "2.0", "id": m.ID, "error": map[string]any{"code": -32601, "message": "method not found: " + m.Method}})
	}
}

// ── the tools ──

func mcpTools() []any {
	s := func() map[string]any { return map[string]any{"type": "string"} }
	arr := func(t map[string]any) map[string]any { return map[string]any{"type": "array", "items": t} }
	b := func() map[string]any { return map[string]any{"type": "boolean"} }
	o := func(props map[string]any, req ...string) map[string]any {
		m := map[string]any{"type": "object", "properties": props}
		if len(req) > 0 {
			m["required"] = req
		}
		return m
	}
	copySet := o(map[string]any{"headline": s(), "primary_text": s(), "sub": s(), "bullets": arr(s()), "badge": s(), "cta": map[string]any{"type": "string", "enum": []string{"SHOP_NOW", "LEARN_MORE", "ORDER_NOW", "BUY_NOW"}}})
	fams := []string{"hero", "glow", "ingredient", "offer", "routine", "editorial", "petals", "texture", "qa", "collection"}
	ideaProps := map[string]any{
		"product_ids": arr(s()), "angle": s(), "hook": s(), "template": map[string]any{"type": "string", "enum": fams},
		"palette":    map[string]any{"type": "array", "items": s(), "description": "2-4 hex colours like #F7C6D9; empty = from the product photo"},
		"show_price": b(), "formats": arr(map[string]any{"type": "string", "enum": []string{"1:1", "4:5", "9:16"}}), "motion": b(),
		"scene": map[string]any{"type": "string", "description": "optional AI background prompt: the set only (no product, text, logos or faces)"},
		"ar":    copySet, "en": copySet,
	}
	ro := map[string]any{"readOnlyHint": true, "openWorldHint": false}
	return []any{
		map[string]any{"name": "get_brief", "title": "What is needed now", "description": "Open creation requests, products to push (trending first), ads that are tiring, research highlights and the brand + policy rules. Call this first.", "inputSchema": o(map[string]any{}), "annotations": ro},
		map[string]any{"name": "list_products", "title": "Shop products", "description": "In-stock products with a photo (id, title, brand, type, price AED). Optional query filters by words.", "inputSchema": o(map[string]any{"query": s()}), "annotations": ro},
		map[string]any{"name": "get_research", "title": "Market research", "description": "The autopilot's latest market notes (trends, competitors, Arabic audience) with angles.", "inputSchema": o(map[string]any{}), "annotations": ro},
		map[string]any{"name": "create_ad_set", "title": "Create ads (rendered in the app)", "description": "Make 1-5 ad ideas. Each: product ids from list_products, angle, hook, design family, formats, and Arabic (ar) + English (en) copy. The app checks the wording (policy linter), renders every size locally and returns previews and a session_id. Same arguments again = the same result.",
			"inputSchema": o(map[string]any{"ideas": arr(o(ideaProps, "product_ids", "ar", "en")), "request_id": map[string]any{"type": "string", "description": "the creation request it answers (from get_brief)"}, "prompt": map[string]any{"type": "string", "description": "the owner's words, for the history"}}, "ideas"),
			"annotations": map[string]any{"readOnlyHint": false, "destructiveHint": false, "idempotentHint": true, "openWorldHint": false}},
		map[string]any{"name": "revise", "title": "Change something", "description": "Change only the fields you send on a session's ideas (e.g. {ar:{headline}, en:{headline}} for a shorter headline, palette for colours, show_price, product_ids to swap the product). idea = 1-based, 0/absent = all. Returns new previews of what changed.",
			"inputSchema": o(map[string]any{"session_id": s(), "idea": map[string]any{"type": "integer"}, "changes": o(ideaProps), "instruction": map[string]any{"type": "string", "description": "the owner's words"}}, "session_id", "changes"),
			"annotations": map[string]any{"readOnlyHint": false, "destructiveHint": false, "idempotentHint": true, "openWorldHint": false}},
		map[string]any{"name": "save_to_library", "title": "Save to the library", "description": "Renders every format at full size, runs the quality gate and saves the ones that pass to the account's creative library. Returns creative ids. Idempotent.",
			"inputSchema": o(map[string]any{"session_id": s()}, "session_id"), "annotations": map[string]any{"readOnlyHint": false, "destructiveHint": false, "idempotentHint": true, "openWorldHint": false}},
		map[string]any{"name": "run_it", "title": "Hand to the autopilot", "description": "Approves the saved creatives for the autopilot's next cycle (inside the owner's limits). launch_now=true also starts a small test ad set now through the executor's checks (needs Practice or Auto, limits set). Only when the owner says so. Idempotent.",
			"inputSchema": o(map[string]any{"session_id": s(), "creative_ids": arr(s()), "launch_now": b()}, "session_id"), "annotations": map[string]any{"readOnlyHint": false, "destructiveHint": false, "idempotentHint": true, "openWorldHint": true}},
	}
}

func text(v any) map[string]any {
	if s, ok := v.(string); ok {
		return map[string]any{"type": "text", "text": s}
	}
	b, _ := json.MarshalIndent(v, "", " ")
	return map[string]any{"type": "text", "text": string(b)}
}

func argKey(tool string, args map[string]any) string {
	b, _ := json.Marshal(args) // map keys are sorted: the same arguments give the same key
	h := sha256.Sum256(append([]byte(tool+"|"), b...))
	return "mcp-" + hex.EncodeToString(h[:])[:20]
}

func (c *mcpConn) call(name string, args map[string]any, progress func(int, int, string)) ([]any, error) {
	go c.seen(name)
	switch name {
	case "get_brief":
		var v any
		if err := c.get("/api/brain/x/brief", &v); err != nil {
			return nil, err
		}
		return []any{text(v)}, nil
	case "list_products":
		var ps []map[string]any
		if err := c.get("/api/brain/x/products", &ps); err != nil {
			return nil, err
		}
		q := strings.ToLower(strings.TrimSpace(fmt.Sprint(args["query"])))
		if q == "" || q == "<nil>" {
			return []any{text(ps)}, nil
		}
		var out []map[string]any
		for _, p := range ps {
			hay := strings.ToLower(fmt.Sprint(p["title"], " ", p["brand"], " ", p["type"]))
			all := true
			for _, w := range strings.Fields(q) {
				all = all && strings.Contains(hay, w)
			}
			if all {
				out = append(out, p)
			}
		}
		return []any{text(out)}, nil
	case "get_research":
		var r struct {
			Notes []map[string]any `json:"notes"`
		}
		if err := c.get("/api/brain/x/research", &r); err != nil {
			return nil, err
		}
		var out []map[string]any
		for _, n := range r.Notes {
			if len(out) < 15 {
				out = append(out, map[string]any{"topic": n["topic"], "title": n["title"], "title_ar": n["title_ar"], "body": n["body"], "angles": n["angles"]})
			}
		}
		return []any{text(out)}, nil
	case "create_ad_set":
		ideas, _ := args["ideas"].([]any)
		if len(ideas) == 0 || len(ideas) > 5 {
			return nil, errors.New("send 1-5 ideas")
		}
		var authored []map[string]any
		for _, x := range ideas {
			m, _ := x.(map[string]any)
			authored = append(authored, ideaFromArgs(m))
		}
		body := map[string]any{"authored": authored, "key": argKey(name, args), "request_id": str(args["request_id"]), "prompt": str(args["prompt"])}
		s, err := c.create(body, progress)
		if err != nil {
			return nil, err
		}
		return c.preview(s, nil, progress)
	case "revise":
		sid := str(args["session_id"])
		ch, _ := args["changes"].(map[string]any)
		if sid == "" || len(ch) == 0 {
			return nil, errors.New("send session_id and changes")
		}
		rv := ideaFromArgs(ch)
		rev := map[string]any{}
		for k, v := range rv { // only what was sent
			if k == "sets" {
				continue
			}
			rev[k] = v
		}
		for _, l := range []string{"ar", "en"} {
			if m, ok := ch[l].(map[string]any); ok {
				rev[l] = m
			}
		}
		if n, ok := args["idea"].(float64); ok {
			rev["idea"] = int(n)
		}
		body := map[string]any{"session_id": sid, "revise": rev, "key": argKey(name, args), "edit": str(args["instruction"])}
		s, err := c.create(body, progress)
		if err != nil {
			return nil, err
		}
		var changed []string
		if ts, _ := s["turns"].([]any); len(ts) > 0 {
			if t, _ := ts[len(ts)-1].(map[string]any); t != nil {
				if cs, _ := t["changed"].([]any); cs != nil {
					for _, x := range cs {
						changed = append(changed, fmt.Sprint(x))
					}
				}
			}
		}
		return c.preview(s, changed, progress)
	case "save_to_library":
		sid := str(args["session_id"])
		if sid == "" {
			return nil, errors.New("send session_id")
		}
		var ss []map[string]any
		_ = c.get("/api/brain/x/create", &ss)
		rev := 0
		for _, s := range ss {
			if s["id"] == sid {
				ts, _ := s["turns"].([]any)
				rev = len(ts)
			}
		}
		res, err := c.render(sid, "save", rev, progress)
		if err != nil {
			return nil, err
		}
		ids, _ := res["creative_ids"].([]any)
		_ = c.post("/api/brain/x/create/"+sid, "PUT", map[string]any{"saved": ids}, nil)
		return []any{text(map[string]any{"saved_creative_ids": ids, "held_back_by_quality_check": res["refused"], "next": "run_it when the owner says so"})}, nil
	case "run_it":
		sid := str(args["session_id"])
		var ids []any
		if x, ok := args["creative_ids"].([]any); ok && len(x) > 0 {
			ids = x
		} else {
			var ss []map[string]any
			if err := c.get("/api/brain/x/create", &ss); err != nil {
				return nil, err
			}
			for _, s := range ss {
				if s["id"] == sid {
					ids, _ = s["saved"].([]any)
				}
			}
		}
		if len(ids) == 0 {
			return nil, errors.New("nothing saved for this session yet: call save_to_library first")
		}
		launch, _ := args["launch_now"].(bool)
		var out map[string]any
		if err := c.post("/api/launch", "POST", map[string]any{"creative_ids": ids, "launch": launch, "key": argKey(name, args)}, &out); err != nil {
			return nil, err
		}
		props, _ := out["proposals"].([]any)
		_ = c.post("/api/brain/x/create/"+sid, "PUT", map[string]any{"ran": ids, "proposals": props}, nil)
		return []any{text(out)}, nil
	}
	return nil, fmt.Errorf("no tool %q", name)
}

func str(v any) string {
	if s, ok := v.(string); ok {
		return s
	}
	return ""
}

// ideaFromArgs: the tool's {.., ar:{..}, en:{..}} → the engine's idea (sets[]).
func ideaFromArgs(m map[string]any) map[string]any {
	out := map[string]any{}
	for k, v := range m {
		if k != "ar" && k != "en" {
			out[k] = v
		}
	}
	var sets []any
	for _, l := range []string{"ar", "en"} {
		if s, ok := m[l].(map[string]any); ok {
			x := map[string]any{"lang": l}
			for k, v := range s {
				x[k] = v
			}
			sets = append(sets, x)
		}
	}
	if sets != nil {
		out["sets"] = sets
	}
	return out
}

// create: POST /api/brain/x/create and read its NDJSON to the session.
func (c *mcpConn) create(body map[string]any, progress func(int, int, string)) (map[string]any, error) {
	progress(0, 3, "checking the wording")
	var sess map[string]any
	err := c.stream("/api/brain/x/create", body, func(line map[string]any) error {
		switch line["step"] {
		case "error":
			return errors.New(fmt.Sprint(line["error"]))
		case "done":
			sess, _ = line["session"].(map[string]any)
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	if sess == nil {
		return nil, errors.New("the engine did not answer with a session; try again")
	}
	progress(1, 3, "drawing in the app")
	return sess, nil
}

func (c *mcpConn) render(sid, op string, rev int, progress func(int, int, string)) (map[string]any, error) {
	var res map[string]any
	err := c.stream("/api/render", map[string]any{"session_id": sid, "op": op, "rev": rev}, func(line map[string]any) error {
		switch line["step"] {
		case "error":
			return errors.New(fmt.Sprint(line["error"]))
		case "progress":
			d, _ := line["done"].(float64)
			t, _ := line["total"].(float64)
			progress(int(d), int(t), "drawing "+fmt.Sprint(int(d))+"/"+fmt.Sprint(int(t)))
		case "done":
			res, _ = line["result"].(map[string]any)
		}
		return nil
	})
	if err != nil {
		return nil, err
	}
	if res == nil {
		return nil, errors.New("the app did not return the pictures; try again")
	}
	if e := str(res["error"]); e != "" {
		return nil, errors.New(e)
	}
	return res, nil
}

func (c *mcpConn) preview(s map[string]any, changed []string, progress func(int, int, string)) ([]any, error) {
	sid := str(s["id"])
	ts, _ := s["turns"].([]any)
	res, err := c.render(sid, "preview", len(ts), progress)
	if err != nil {
		return nil, fmt.Errorf("saved as session %s, but: %v", sid, err)
	}
	var designs []map[string]any
	if ds, _ := s["designs"].([]any); ds != nil {
		for _, d := range ds {
			m, _ := d.(map[string]any)
			designs = append(designs, map[string]any{"id": m["id"], "idea": m["idea"], "lang": m["lang"], "headline": m["headline"], "template": m["template"], "formats": m["formats"]})
		}
	}
	summary := map[string]any{"session_id": sid, "designs": designs, "quality": res["quality"], "next": "Show the owner the previews. revise to change something; save_to_library when he likes them."}
	if changed != nil {
		summary["changed"] = changed
	}
	content := []any{text(summary)}
	if pv, _ := res["previews"].([]any); pv != nil {
		for i, p := range pv {
			m, _ := p.(map[string]any)
			if i >= 8 || str(m["jpeg"]) == "" {
				continue
			}
			content = append(content, map[string]any{"type": "image", "data": str(m["jpeg"]), "mimeType": "image/jpeg"})
		}
	}
	progress(3, 3, "ready")
	return content, nil
}

// ── HTTP to the running engine ──

func (c *mcpConn) engine() (string, string, error) {
	dir := c.data
	if dir == "" {
		home, _ := os.UserHomeDir()
		dir = filepath.Join(home, "KBB Ads Autopilot Data")
	}
	b, err := os.ReadFile(filepath.Join(dir, "engine.json"))
	var e struct{ Addr string }
	if err != nil || json.Unmarshal(b, &e) != nil || e.Addr == "" {
		return "", "", errors.New("KBB Ads Autopilot is not open. Open it (Start KBB Ads Autopilot), then try again.")
	}
	r, err := c.hc.Get("http://" + e.Addr + "/api/info")
	if err != nil {
		return "", "", errors.New("KBB Ads Autopilot is not open. Open it (Start KBB Ads Autopilot), then try again.")
	}
	defer r.Body.Close()
	var info struct{ Token string }
	if json.NewDecoder(r.Body).Decode(&info) != nil || info.Token == "" {
		return "", "", errors.New("KBB Ads Autopilot did not answer; open it again")
	}
	return e.Addr, info.Token, nil
}

func (c *mcpConn) do(method, path string, body any) (*http.Response, error) {
	addr, tok, err := c.engine()
	if err != nil {
		return nil, err
	}
	var rd io.Reader
	if body != nil {
		b, _ := json.Marshal(body)
		rd = bytes.NewReader(b)
	}
	req, _ := http.NewRequest(method, "http://"+addr+path, rd)
	req.Header.Set("X-KAA", tok)
	req.Header.Set("Content-Type", "application/json")
	r, err := c.hc.Do(req)
	if err != nil {
		return nil, fmt.Errorf("KBB Ads Autopilot stopped answering (%v); open it and try again", err)
	}
	if r.StatusCode >= 400 {
		defer r.Body.Close()
		var e struct{ Error string }
		_ = json.NewDecoder(io.LimitReader(r.Body, 1<<20)).Decode(&e)
		if e.Error == "" {
			e.Error = r.Status
		}
		return nil, errors.New(e.Error)
	}
	return r, nil
}

func (c *mcpConn) get(path string, v any) error { return c.post(path, "GET", nil, v) }

func (c *mcpConn) post(path, method string, body, v any) error {
	r, err := c.do(method, path, body)
	if err != nil {
		return err
	}
	defer r.Body.Close()
	if v == nil {
		return nil
	}
	return json.NewDecoder(r.Body).Decode(v)
}

func (c *mcpConn) stream(path string, body any, each func(map[string]any) error) error {
	r, err := c.do("POST", path, body)
	if err != nil {
		return err
	}
	defer r.Body.Close()
	sc := bufio.NewScanner(r.Body)
	sc.Buffer(make([]byte, 1<<20), 64<<20)
	for sc.Scan() {
		var m map[string]any
		if json.Unmarshal(sc.Bytes(), &m) != nil {
			continue
		}
		if err := each(m); err != nil {
			return err
		}
	}
	return sc.Err()
}

func (c *mcpConn) seen(tool string) {
	_ = c.post("/api/mcp/seen", "POST", map[string]any{"tool": tool}, nil)
}
