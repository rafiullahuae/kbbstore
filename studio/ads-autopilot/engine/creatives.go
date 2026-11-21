package main

import (
	"bytes"
	"encoding/base64"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"time"

	"kaa/model"
	"kaa/store"
)

// creativeLib: the creative library in the data folder: creatives/index.json plus one media file per creative
// (creatives/<id>.png or .mp4). Lists never carry the media.
type creativeLib struct {
	mu   sync.Mutex
	st   *store.Store
	list []model.Creative
}

var creativeIDRe = regexp.MustCompile(`^[a-z0-9][a-z0-9_-]{2,63}$`)

const (
	maxPNG   = 12 << 20
	maxVideo = 60 << 20
)

func newCreativeLib(st *store.Store) *creativeLib {
	c := &creativeLib{st: st}
	_ = st.Load("creatives/index", &c.list)
	return c
}

func (c *creativeLib) all() []model.Creative {
	c.mu.Lock()
	defer c.mu.Unlock()
	return append([]model.Creative(nil), c.list...)
}

func (c *creativeLib) mediaPath(cr model.Creative) string {
	return filepath.Join(c.st.Dir, "creatives", cr.ID+"."+cr.Media)
}

// Get implements executor.CreativeStore.
func (c *creativeLib) Get(id string) (model.Creative, []byte, error) {
	c.mu.Lock()
	defer c.mu.Unlock()
	for _, cr := range c.list {
		if cr.ID == id {
			if cr.Media == "" {
				return cr, nil, nil
			}
			b, err := os.ReadFile(c.mediaPath(cr))
			return cr, b, err
		}
	}
	return model.Creative{}, nil, fmt.Errorf("no creative %q in the library", id)
}

// Put implements executor.CreativeStore (updates Meta ids after an upload).
func (c *creativeLib) Put(cr model.Creative) error {
	c.mu.Lock()
	defer c.mu.Unlock()
	cr.PNG, cr.Video = "", ""
	for i := range c.list {
		if c.list[i].ID == cr.ID {
			if cur := c.list[i]; cur.MediaRev > cr.MediaRev { // redrawn since this copy was read: its upload ids are stale
				cr.Media, cr.MediaRev, cr.OldMetaIDs, cr.RepricedAt = cur.Media, cur.MediaRev, cur.OldMetaIDs, cur.RepricedAt
				cr.ImageHash, cr.VideoID, cr.MetaID = cur.ImageHash, cur.VideoID, cur.MetaID
			}
			c.list[i] = cr
			return c.st.Save("creatives/index", c.list)
		}
	}
	c.list = append(c.list, cr)
	return c.st.Save("creatives/index", c.list)
}

// Add validates and stores a creative posted by the UI or the brain.
func (c *creativeLib) Add(cr model.Creative, now time.Time) (model.Creative, error) {
	if cr.ID == "" {
		cr.ID = "cr-" + randHex(6)
	}
	cr.ID = strings.ToLower(cr.ID)
	if !creativeIDRe.MatchString(cr.ID) {
		return cr, errors.New("id: 3-64 lowercase letters, digits, - or _")
	}
	if cr.Lang != "ar" && cr.Lang != "en" {
		return cr, errors.New(`lang must be "ar" or "en"`)
	}
	if cr.Format != "1:1" && cr.Format != "4:5" && cr.Format != "9:16" {
		return cr, errors.New(`format must be "1:1", "4:5" or "9:16"`)
	}
	if cr.Source != "brain" && cr.Source != "owner" {
		return cr, errors.New(`source must be "brain" or "owner"`)
	}
	if !strings.HasPrefix(cr.Link, "https://kbeautybliss.com/") {
		return cr, errors.New("link must be a page of https://kbeautybliss.com/")
	}
	if len(cr.Headline) > 255 || len(cr.PrimaryText) > 2200 || cr.Headline == "" || cr.PrimaryText == "" {
		return cr, errors.New("headline (≤255) and primary_text (≤2200) are both needed")
	}
	if cr.CTA == "" {
		cr.CTA = "SHOP_NOW"
	}
	if !regexp.MustCompile(`^[A-Z_]{2,40}$`).MatchString(cr.CTA) {
		return cr, errors.New("cta must be a Meta call-to-action type such as SHOP_NOW")
	}
	var media []byte
	switch {
	case cr.PNG != "" && cr.Video != "":
		return cr, errors.New("a creative has a picture or a video, not both")
	case cr.PNG != "":
		b, err := base64.StdEncoding.DecodeString(cr.PNG)
		if err != nil || len(b) > maxPNG || !bytes.HasPrefix(b, []byte("\x89PNG\r\n\x1a\n")) {
			return cr, fmt.Errorf("png must be base64 of a PNG file up to %d MB", maxPNG>>20)
		}
		media, cr.Media = b, "png"
	case cr.Video != "":
		b, err := base64.StdEncoding.DecodeString(cr.Video)
		if err != nil || len(b) > maxVideo || len(b) < 12 || string(b[4:8]) != "ftyp" {
			return cr, fmt.Errorf("video must be base64 of an MP4 file up to %d MB", maxVideo>>20)
		}
		media, cr.Media = b, "mp4"
	}
	cr.PNG, cr.Video = "", ""
	cr.CreatedAt = now.UTC()
	cr.MetaID, cr.ImageHash, cr.VideoID, cr.Used, cr.Approved = "", "", "", false, false
	c.mu.Lock()
	defer c.mu.Unlock()
	for _, x := range c.list {
		if x.ID == cr.ID {
			// the same picture posted twice (a retried save: Create with Claude names each by its content) is the
			// same creative, not a second one
			if strings.HasPrefix(cr.ID, "cc-") && x.Lang == cr.Lang && x.Format == cr.Format && x.Headline == cr.Headline {
				return x, nil
			}
			return cr, fmt.Errorf("a creative %q already exists", cr.ID)
		}
	}
	if media != nil {
		if err := store.WriteAtomic(c.mediaPath(cr), media, 0o600); err != nil {
			return cr, err
		}
	}
	c.list = append(c.list, cr)
	return cr, c.st.Save("creatives/index", c.list)
}

// ReplaceMedia redraws a creative in place (reprice.go): same id, same copy, same place in every package and queue;
// only the picture / video changes. Meta's ids for the old media are dropped (the next upload sends the new one)
// and the old Meta creative id is kept, so a live ad still showing it can be swapped. Returns the old Meta id.
func (c *creativeLib) ReplaceMedia(id, png, video string, now time.Time) (model.Creative, string, error) {
	var media []byte
	kind := ""
	switch {
	case png != "" && video != "":
		return model.Creative{}, "", errors.New("a picture or a video, not both")
	case png != "":
		b, err := base64.StdEncoding.DecodeString(png)
		if err != nil || len(b) > maxPNG || !bytes.HasPrefix(b, []byte("\x89PNG\r\n\x1a\n")) {
			return model.Creative{}, "", fmt.Errorf("png must be base64 of a PNG file up to %d MB", maxPNG>>20)
		}
		media, kind = b, "png"
	case video != "":
		b, err := base64.StdEncoding.DecodeString(video)
		if err != nil || len(b) > maxVideo || len(b) < 12 || string(b[4:8]) != "ftyp" {
			return model.Creative{}, "", fmt.Errorf("video must be base64 of an MP4 file up to %d MB", maxVideo>>20)
		}
		media, kind = b, "mp4"
	default:
		return model.Creative{}, "", errors.New("send png or video")
	}
	c.mu.Lock()
	defer c.mu.Unlock()
	for i := range c.list {
		cr := c.list[i]
		if cr.ID != id {
			continue
		}
		if cr.Media != kind {
			return cr, "", fmt.Errorf("creative %s holds a %q, not a %s", id, cr.Media, kind)
		}
		if err := store.WriteAtomic(c.mediaPath(cr), media, 0o600); err != nil {
			return cr, "", err
		}
		old := cr.MetaID
		if old != "" {
			cr.OldMetaIDs = append(cr.OldMetaIDs, old)
		}
		cr.ImageHash, cr.VideoID, cr.MetaID = "", "", ""
		cr.MediaRev++
		cr.RepricedAt = now.UTC().Format(time.RFC3339)
		c.list[i] = cr
		return cr, old, c.st.Save("creatives/index", c.list)
	}
	return model.Creative{}, "", fmt.Errorf("no creative %q in the library", id)
}
