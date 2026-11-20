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
	e.notify(acct, Notice{Kind: "needed", Title: title + " due — start creating", Req: r.ID, Body: r.Title, BodyAR: r.TitleAR})
	return r.ID
}

// Notify: a Mac notification + the creation feed (occasion prep and reminders).
func (e *Engine) Notify(acct, title, body, bodyAR string) {
	e.notify(acct, Notice{Kind: "due_soon", Title: title, Body: body, BodyAR: bodyAR})
}
