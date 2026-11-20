package executor

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"sort"
	"sync"
	"time"

	"kaa/model"
)

// Ledger: what the executor has done, kept in the data folder (ledger.json) so a restart, a retried cycle or a
// replayed proposal never does the same thing twice.
//   - Done: idempotency key → result. A proposal whose key is here is answered from here, with no Meta call.
//   - Tmp: the brain's tmp: ids → the real Meta ids they became.
//   - Edits / Budgets: per object, the times of edits and of budget changes (Meta's 30 s and 4-per-hour rules).
//   - Changes: per object, the last significant change (the owner's cooldown).
type Ledger struct {
	mu      sync.Mutex
	Done    map[string]LedgerEntry `json:"done"`
	Tmp     map[string]string      `json:"tmp"`
	Edits   map[string][]time.Time `json:"edits"`
	Budgets map[string][]time.Time `json:"budgets"`
	Changes map[string]time.Time   `json:"changes"`
	dirty   bool
}

type LedgerEntry struct {
	Kind   string    `json:"kind"`
	Target string    `json:"target"`
	MetaID string    `json:"meta_id,omitempty"`
	At     time.Time `json:"at"`
}

func NewLedger() *Ledger {
	return &Ledger{Done: map[string]LedgerEntry{}, Tmp: map[string]string{}, Edits: map[string][]time.Time{}, Budgets: map[string][]time.Time{}, Changes: map[string]time.Time{}}
}

func (l *Ledger) fix() {
	if l.Done == nil {
		l.Done = map[string]LedgerEntry{}
	}
	if l.Tmp == nil {
		l.Tmp = map[string]string{}
	}
	if l.Edits == nil {
		l.Edits = map[string][]time.Time{}
	}
	if l.Budgets == nil {
		l.Budgets = map[string][]time.Time{}
	}
	if l.Changes == nil {
		l.Changes = map[string]time.Time{}
	}
}

// Key: the idempotency key of a proposal: sha256 over its id, kind, target and canonical params. The same proposal
// replayed (a retried cycle, a crash between Meta's answer and our write) gets the same key; Meta gets it too, in
// X-Client-Idempotent-Key, for creates.
func Key(p model.Proposal) string {
	keys := make([]string, 0, len(p.Params))
	for k := range p.Params {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	canon := []any{p.ID, p.Kind, p.Target}
	for _, k := range keys {
		canon = append(canon, k, p.Params[k])
	}
	b, _ := json.Marshal(canon)
	h := sha256.Sum256(b)
	return "kaa-" + hex.EncodeToString(h[:20])
}

func (l *Ledger) Lookup(key string) (LedgerEntry, bool) {
	l.mu.Lock()
	defer l.mu.Unlock()
	l.fix()
	e, ok := l.Done[key]
	return e, ok
}

func (l *Ledger) Record(key string, e LedgerEntry) {
	l.mu.Lock()
	defer l.mu.Unlock()
	l.fix()
	l.Done[key] = e
	l.dirty = true
}

func (l *Ledger) MapTmp(tmp, real string) {
	l.mu.Lock()
	defer l.mu.Unlock()
	l.fix()
	l.Tmp[tmp] = real
	l.dirty = true
}

func (l *Ledger) Resolve(id string) string {
	l.mu.Lock()
	defer l.mu.Unlock()
	l.fix()
	if r, ok := l.Tmp[id]; ok {
		return r
	}
	return id
}

func (l *Ledger) NoteEdit(id string, at time.Time, budget, significant bool) {
	l.mu.Lock()
	defer l.mu.Unlock()
	l.fix()
	l.Edits[id] = trim(append(l.Edits[id], at), at)
	if budget {
		l.Budgets[id] = trim(append(l.Budgets[id], at), at)
	}
	if significant {
		l.Changes[id] = at
	}
	l.dirty = true
}

func trim(ts []time.Time, now time.Time) []time.Time {
	out := ts[:0]
	for _, t := range ts {
		if now.Sub(t) < 2*time.Hour {
			out = append(out, t)
		}
	}
	return out
}

func (l *Ledger) LastEdit(id string) time.Time {
	l.mu.Lock()
	defer l.mu.Unlock()
	var last time.Time
	for _, t := range l.Edits[id] {
		if t.After(last) {
			last = t
		}
	}
	return last
}

func (l *Ledger) LastChange(id string) time.Time {
	l.mu.Lock()
	defer l.mu.Unlock()
	return l.Changes[id]
}

func (l *Ledger) BudgetChangesWithin(id string, now time.Time, d time.Duration) int {
	l.mu.Lock()
	defer l.mu.Unlock()
	n := 0
	for _, t := range l.Budgets[id] {
		if now.Sub(t) < d {
			n++
		}
	}
	return n
}

// Snapshot returns a copy safe to marshal.
func (l *Ledger) Snapshot() *Ledger {
	l.mu.Lock()
	defer l.mu.Unlock()
	l.fix()
	b, _ := json.Marshal(struct {
		Done    map[string]LedgerEntry `json:"done"`
		Tmp     map[string]string      `json:"tmp"`
		Edits   map[string][]time.Time `json:"edits"`
		Budgets map[string][]time.Time `json:"budgets"`
		Changes map[string]time.Time   `json:"changes"`
	}{l.Done, l.Tmp, l.Edits, l.Budgets, l.Changes})
	c := NewLedger()
	_ = json.Unmarshal(b, c)
	return c
}
