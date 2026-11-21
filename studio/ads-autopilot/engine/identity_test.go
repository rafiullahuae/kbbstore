package main

import (
	"context"
	"encoding/json"
	"strings"
	"testing"

	"kaa/metamock"
	"kaa/model"
)

// The owner, 22 Nov: "all the ads ran from wrong page ... all the ads must run from K-Beauty Bliss page and also
// from kbeauty.bliss instagram. like my ads." His own ad runs from Page 200000000009 + Instagram 500000000009; the
// app was linked to another Page. The next cycle takes his ads' Page / Instagram, every creative it makes carries
// them, and nothing made with the old Page is reused. A Page he picks by hand is never changed.
// MUTATION: drop the syncIdentity call in Cycle → the creatives carry 200000000001, red.
func TestAdsRunFromTheOwnersPage(t *testing.T) {
	ac, m := testApp(t)
	ac.app.brain = quietBrain{}
	ctx := context.Background()
	for _, o := range m.Objects("ads") { // his own ad
		if n, _ := o["name"].(string); strings.HasPrefix(n, "Owner") {
			cr, _ := o["creative"].(map[string]any)
			m.SetCreativeIdentity(cr["id"].(string), "200000000009", "500000000009")
		}
	}
	if _, err := ac.Cycle(ctx, model.AutopilotAuto); err != nil {
		t.Fatal(err)
	}
	ac.mu.RLock()
	l := ac.settings.Links
	ac.mu.RUnlock()
	if l.PageID != "200000000009" || l.InstagramUserID != "500000000009" {
		t.Fatalf("links %s / %s, want the Page / Instagram of his own ads", l.PageID, l.InstagramUserID)
	}
	n, links := 0, 0
	for _, w := range m.Writes() {
		if strings.HasSuffix(w.Path, "/adcreatives") {
			n++
			var spec map[string]any
			_ = json.Unmarshal([]byte(w.Form["object_story_spec"]), &spec)
			if spec["page_id"] != "200000000009" || w.Form["instagram_user_id"] != "500000000009" {
				t.Fatalf("a creative made with page %v / instagram %q", spec["page_id"], w.Form["instagram_user_id"])
			}
			// every ad goes to the super-sale page in its language (the owner, 22 Nov)
			if ld, ok := spec["link_data"].(map[string]any); ok {
				link, _ := ld["link"].(string)
				msg, _ := ld["message"].(string)
				ar := strings.ContainsAny(msg, "ابتثجحخدذرزسشصضطظعغفقكلمنهوي")
				want := "https://kbeautybliss.com/super-sale/"
				if ar {
					want = "https://kbeautybliss.com/ar/super-sale/"
				}
				if link != want {
					t.Fatalf("an ad links to %q, want %q (arabic %v)", link, want, ar)
				}
				links++
			}
		}
	}
	if n == 0 || links == 0 {
		t.Fatalf("no creative / link was made (%d / %d): the check proves nothing", n, links)
	}
	// by hand: kept
	ac.mu.Lock()
	ac.settings.Links.PageID, ac.settings.Links.IdentityByHand = "200000000001", true
	ac.mu.Unlock()
	if msg := ac.syncIdentity(ctx); msg != "" || ac.settings.Links.PageID != "200000000001" {
		t.Fatalf("a Page picked by hand was changed: %q", msg)
	}
	_ = metamock.OwnerPageID
}
