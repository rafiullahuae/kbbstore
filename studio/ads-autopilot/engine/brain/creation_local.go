package brain

// Creation on this Mac with Claude Code (the engine's half is engine/claudecode.go). The owner (Nov 2026): "i clicked
// on start, each time i need to press enter in the claude app? why don't the claude work auto when the laptop is on".
// A request raised in "With me in Claude (Max)" mode is made by `claude -p` on his Max login, through the same MCP
// connector the Claude desktop app uses -- so the same linter, quality gate and approval rules. Here: the request's
// local state (shown live on its card), and the notices the owner can close.

import (
	"fmt"
	"time"
)

// SetLocal: a request's Claude-Code-on-this-Mac state moves (queued | working | done | failed | ""). note: what the
// card says (the reason on a failure). n: ads saved. No-op for an unknown id; the open tabs see it at once.
func (e *Engine) SetLocal(acct, id, state, note string, n int) {
	e.mu.Lock()
	reqs := e.creations(acct)
	ch := false
	for i := range reqs {
		r := &reqs[i]
		if r.ID != id || (r.Local == state && r.LocalNote == note && r.LocalN == n) {
			continue
		}
		r.Local, r.LocalNote, r.LocalN, r.LocalAt = state, note, n, time.Now().UTC()
		ch = true
	}
	if ch {
		e.saveCreations(acct, reqs)
	}
	e.mu.Unlock()
	if ch {
		e.push(acct)
	}
}

// NotifyRef: a notice tied to something the owner can settle (an occasion package): ClearNotices(ref) takes it away.
func (e *Engine) NotifyRef(acct, ref, title, body, bodyAR string) {
	e.notify(acct, Notice{Kind: "due_soon", Title: title, Body: body, BodyAR: bodyAR, Ref: ref})
}

// ClearNotices: every notice about ref is closed (the occasion package was approved). Returns how many.
func (e *Engine) ClearNotices(acct, ref string) int {
	if ref == "" {
		return 0
	}
	return e.closeNotices(acct, func(n Notice) bool { return n.Ref == ref })
}

// DismissNotice: the owner's ✕ on one notice (its id, or its time for a notice saved before ids existed).
func (e *Engine) DismissNotice(acct, id string) bool {
	return e.closeNotices(acct, func(n Notice) bool { return id != "" && (n.ID == id || n.At.Format(time.RFC3339Nano) == id) }) > 0
}

func (e *Engine) closeNotices(acct string, match func(Notice) bool) int {
	e.mu.Lock()
	var ns []Notice
	e.load(acct, "notices", &ns)
	k := 0
	for i := range ns {
		if !ns[i].Dismissed && match(ns[i]) {
			ns[i].Dismissed = true
			k++
		}
	}
	if k > 0 {
		e.save(acct, "notices", ns)
	}
	e.mu.Unlock()
	if k > 0 {
		e.push(acct)
	}
	return k
}

// LocalDone: the run's notice ("Made by Claude Code on this Mac").
func (e *Engine) LocalDone(acct, reqID, title, titleAR string, saved, held int) {
	body := fmt.Sprintf("%d ads ready for \"%s\": Creatives → Made with Claude.", saved, title)
	bodyAR := fmt.Sprintf("%d إعلانات جاهزة لـ «%s»: الإعلانات ← صُنعت مع Claude.", saved, titleAR)
	if held > 0 {
		body += fmt.Sprintf(" %d held back by the quality check.", held)
		bodyAR += fmt.Sprintf(" أوقف فحص الجودة %d.", held)
	}
	e.notify(acct, Notice{Kind: "local_done", Title: "Made by Claude Code on this Mac", Req: reqID, Body: body, BodyAR: bodyAR})
}
