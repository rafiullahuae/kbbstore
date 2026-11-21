package brain

import (
	"fmt"
	"strings"
	"time"
)

// OccasionCreation: an occasion package raises a creation request with its brief ("With me in Claude" mode) so the
// owner can make richer designs for it in Claude; the engine has already prepared template / API-copy ads, so nothing
// waits on it. Returns the request id ("" in API mode or when one is already open for it).
func (e *Engine) OccasionCreation(acct, title, titleAR, brief string, productIDs []string) string {
	st := e.settings(acct)
	if st.CreationMode != CreationWithMe {
		return ""
	}
	e.mu.Lock()
	reqs := e.creations(acct)
	for _, r := range reqs {
		if openReq(r.Status) && r.Angle == title {
			e.mu.Unlock()
			return ""
		}
	}
	r := CreationRequest{ID: fmt.Sprintf("cq%x", hash32(fmt.Sprint(time.Now().UnixNano(), title))), At: time.Now(), Status: "open", Count: 3, Lang: "both", Format: "ads",
		ProductIDs: productIDs, Product: strings.Join(productIDs, ", "), Angle: title, Title: title + " — start creating", TitleAR: titleAR + " — ابدئي الإنشاء"}
	r.Brief = creationBrief(r, brief)
	e.saveCreations(acct, append([]CreationRequest{r}, reqs...))
	e.mu.Unlock()
	if CreationChanged != nil {
		CreationChanged(acct) // Claude Code on this Mac and the cloud pick it up at once
	}
	e.notify(acct, Notice{Kind: "needed", Title: title + " due — start creating", Req: r.ID, Body: r.Title, BodyAR: r.TitleAR})
	return r.ID
}

// CreationWithMe: whether new ad copy for this account is made by the owner with Claude (the default, his Max
// plan) rather than through the API. The engine's product picker asks it before it writes anything new.
func (e *Engine) CreationWithMe(acct string) bool {
	return e.settings(acct).CreationMode == CreationWithMe
}

// Notify: a Mac notification + the creation feed (occasion prep and reminders).
func (e *Engine) Notify(acct, title, body, bodyAR string) {
	e.notify(acct, Notice{Kind: "due_soon", Title: title, Body: body, BodyAR: bodyAR})
}

// OccasionRegen: the owner's "Regenerate → Creatives / Complete" on an occasion package (21 Nov). A NEW creation
// request for its products, made on his Max plan (Claude Code on this Mac, the Claude app or the cloud); an open
// request for the same package is withdrawn first, so exactly one is being made. The engine attaches the saved
// designs to the package when they arrive (engine/regen.go). Refused in API mode: designs come from his Max plan.
func (e *Engine) OccasionRegen(acct, title, titleAR, brief string, productIDs []string) (string, error) {
	if e.settings(acct).CreationMode != CreationWithMe {
		return "", fmt.Errorf("new designs are made with Claude on your Max plan: Settings → Limits → Creation → With me in Claude")
	}
	e.mu.Lock()
	reqs := e.creations(acct)
	for i := range reqs {
		if openReq(reqs[i].Status) && reqs[i].Angle == title {
			reqs[i].Status = "dismissed"
		}
	}
	r := CreationRequest{ID: fmt.Sprintf("cq%x", hash32(fmt.Sprint(time.Now().UnixNano(), title))), At: time.Now(), Status: "open", Count: 3, Lang: "both", Format: "ads",
		ProductIDs: productIDs, Product: strings.Join(productIDs, ", "), Angle: title, Title: title + " — new designs", TitleAR: titleAR + " — تصاميم جديدة"}
	r.Brief = creationBrief(r, brief)
	e.saveCreations(acct, append([]CreationRequest{r}, reqs...))
	e.mu.Unlock()
	if CreationChanged != nil {
		CreationChanged(acct)
	}
	e.notify(acct, Notice{Kind: "needed", Title: title + " — new designs being made", Req: r.ID, Body: r.Title, BodyAR: r.TitleAR})
	return r.ID, nil
}
