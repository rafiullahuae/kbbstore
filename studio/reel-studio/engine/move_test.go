package main

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func moveServer(t *testing.T) *server {
	t.Helper()
	s := dataServer(t)
	ws := filepath.Join(s.data, "workspaces")
	put(t, filepath.Join(ws, "kbb", "workspace.json"), `{"id":"kbb","name":"KBB"}`)
	put(t, filepath.Join(ws, "planet-careers", "workspace.json"), `{"id":"planet-careers","name":"Planet Careers"}`)
	put(t, filepath.Join(ws, "kbb", "brand", "logo.png"), "PNGLOGO")
	put(t, filepath.Join(ws, "kbb", "projects", "p1", "media", "m1-clip.mp4"), "MP4DATA")
	put(t, filepath.Join(ws, "kbb", "projects", "p1", "project.json"), `{"v":1,"id":"p1","workspace":"kbb","brand":"kbb","name":"Serum reel","fps":30,"big":12345678901234567,
 "media":[{"id":"m1","src":"workspaces/kbb/projects/p1/media/m1-clip.mp4"},{"id":"m2","src":"library/music/a.mp3"}],
 "logo":"workspaces/kbb/brand/logo.png","again":"/workspaces/kbb/brand/logo.png","gone":"workspaces/kbb/brand/missing.png",
 "other":"workspaces/planet-careers/brand/x.png"}`)
	return s
}

func TestMoveReel(t *testing.T) {
	s := moveServer(t)
	ws := filepath.Join(s.data, "workspaces")
	r := call(s, "POST", "/api/projects/move", `{"from":"kbb","to":"planet-careers","id":"p1"}`)
	if r.Code != 200 || !strings.Contains(r.Body.String(), `"toName":"Planet Careers"`) {
		t.Fatalf("%d %s", r.Code, r.Body)
	}
	if _, err := os.Stat(filepath.Join(ws, "kbb", "projects", "p1")); err == nil {
		t.Fatal("the reel is still in the old company")
	}
	dst := filepath.Join(ws, "planet-careers", "projects", "p1")
	if readAll(t, filepath.Join(dst, "media", "m1-clip.mp4")) != "MP4DATA" {
		t.Fatal("media did not go with the reel")
	}
	var p map[string]any
	if err := json.Unmarshal([]byte(readAll(t, filepath.Join(dst, "project.json"))), &p); err != nil {
		t.Fatal(err)
	}
	if p["workspace"] != "planet-careers" || p["brand"] != "kbb" {
		t.Fatalf("workspace %v brand %v (the brand -- its look -- is kept)", p["workspace"], p["brand"])
	}
	m := p["media"].([]any)
	if m[0].(map[string]any)["src"] != "workspaces/planet-careers/projects/p1/media/m1-clip.mp4" || m[1].(map[string]any)["src"] != "library/music/a.mp3" {
		t.Fatalf("media paths %v", m)
	}
	if p["logo"] != "workspaces/planet-careers/projects/p1/media/from-kbb/brand_logo.png" || p["again"] != "/workspaces/planet-careers/projects/p1/media/from-kbb/brand_logo.png" {
		t.Fatalf("logo %v / %v", p["logo"], p["again"])
	}
	if readAll(t, filepath.Join(dst, "media", "from-kbb", "brand_logo.png")) != "PNGLOGO" || readAll(t, filepath.Join(ws, "kbb", "brand", "logo.png")) != "PNGLOGO" {
		t.Fatal("the old company's logo is copied into the reel, and stays where it was")
	}
	if p["gone"] != "workspaces/kbb/brand/missing.png" || p["other"] != "workspaces/planet-careers/brand/x.png" {
		t.Fatal("paths that are not files of the old company are left as they are")
	}
	if !strings.Contains(readAll(t, filepath.Join(dst, "project.json")), "12345678901234567") {
		t.Fatal("numbers must survive exactly")
	}
	// a tab that still has the reel open cannot write it back into the old company
	if r := call(s, "PUT", "/api/write?path=workspaces/kbb/projects/p1/project.json", "{}"); r.Code != 409 || !strings.Contains(r.Body.String(), "Planet Careers") {
		t.Fatalf("stale save: %d %s", r.Code, r.Body)
	}
	if _, err := os.Stat(filepath.Join(ws, "kbb", "projects", "p1")); err == nil {
		t.Fatal("a stale save brought the reel back")
	}
	// and back again: then saves there are fine again
	if r := call(s, "POST", "/api/projects/move", `{"from":"planet-careers","to":"kbb","id":"p1"}`); r.Code != 200 {
		t.Fatalf("move back: %d %s", r.Code, r.Body)
	}
	if r := call(s, "PUT", "/api/write?path=workspaces/kbb/projects/p1/project.json", `{"id":"p1"}`); r.Code != 200 {
		t.Fatalf("save after moving back: %d %s", r.Code, r.Body)
	}
}

func TestMoveReelRefusals(t *testing.T) {
	s := moveServer(t)
	ws := filepath.Join(s.data, "workspaces")
	put(t, filepath.Join(ws, "planet-careers", "projects", "p1", "project.json"), `{"id":"p1","name":"theirs"}`)
	before := treeSum(t, s.data)
	for body, want := range map[string]int{
		`{"from":"kbb","to":"planet-careers","id":"p1"}`:         409, // same id in the target: never overwritten
		`{"from":"kbb","to":"kbb","id":"p1"}`:                    400,
		`{"from":"kbb","to":"nobody","id":"p1"}`:                 404,
		`{"from":"kbb","to":"planet-careers","id":"nope"}`:       404,
		`{"from":"kbb","to":"planet-careers","id":"../p1"}`:      400,
		`{"from":"kbb","to":"planet-careers","id":".hidden"}`:    400,
		`{"from":"../kbb","to":"planet-careers","id":"p1"}`:      400,
		`{"from":"kbb","to":"planet-careers/../kbb","id":"p1"}`:  400,
		`{"from":"kbb","to":"Planet Careers","id":"p1"}`:         400,
		`{"from":"kbb","to":"planet-careers","id":"p1/../../x"}`: 400,
		`not json`: 400,
	} {
		r := call(s, "POST", "/api/projects/move", body)
		if r.Code != want {
			t.Errorf("%s: want %d, got %d %s", body, want, r.Code, r.Body)
		}
	}
	if r := call(s, "POST", "/api/projects/move", `{"from":"kbb","to":"planet-careers","id":"p1"}`); !strings.Contains(r.Body.String(), "already has a reel") {
		t.Errorf("collision message: %s", r.Body)
	}
	if treeSum(t, s.data) != before {
		t.Fatal("a refused move changed something")
	}
}

func TestCopyReel(t *testing.T) {
	s := moveServer(t)
	ws := filepath.Join(s.data, "workspaces")
	orig := readAll(t, filepath.Join(ws, "kbb", "projects", "p1", "project.json"))
	r := call(s, "POST", "/api/projects/move", `{"from":"kbb","to":"planet-careers","id":"p1","copy":true}`)
	if r.Code != 200 {
		t.Fatalf("%d %s", r.Code, r.Body)
	}
	if readAll(t, filepath.Join(ws, "kbb", "projects", "p1", "project.json")) != orig {
		t.Fatal("a copy changed the original")
	}
	if readAll(t, filepath.Join(ws, "planet-careers", "projects", "p1", "media", "m1-clip.mp4")) != "MP4DATA" {
		t.Fatal("the copy has no media")
	}
	if r := call(s, "PUT", "/api/write?path=workspaces/kbb/projects/p1/project.json", orig); r.Code != 200 {
		t.Fatalf("the original stays editable after a copy: %d", r.Code)
	}
}

// the owner, 19 Nov: "allow us to duplicate the project if required" — a copy in the same company under a new id
// and name; its own media paths point into its own folder, the original is untouched, the company's other files
// (the logo) are still used where they are.
func TestDuplicateReel(t *testing.T) {
	s := moveServer(t)
	ws := filepath.Join(s.data, "workspaces")
	orig := readAll(t, filepath.Join(ws, "kbb", "projects", "p1", "project.json"))
	r := call(s, "POST", "/api/projects/move", `{"from":"kbb","to":"kbb","id":"p1","copy":true,"newId":"p2","name":"Serum reel (copy)"}`)
	if r.Code != 200 || !strings.Contains(r.Body.String(), `"id":"p2"`) {
		t.Fatalf("%d %s", r.Code, r.Body)
	}
	if readAll(t, filepath.Join(ws, "kbb", "projects", "p1", "project.json")) != orig {
		t.Fatal("duplicating changed the original")
	}
	if readAll(t, filepath.Join(ws, "kbb", "projects", "p2", "media", "m1-clip.mp4")) != "MP4DATA" {
		t.Fatal("the duplicate has no media")
	}
	var p map[string]any
	if err := json.Unmarshal([]byte(readAll(t, filepath.Join(ws, "kbb", "projects", "p2", "project.json"))), &p); err != nil {
		t.Fatal(err)
	}
	src := p["media"].([]any)[0].(map[string]any)["src"]
	if p["id"] != "p2" || p["name"] != "Serum reel (copy)" || p["workspace"] != "kbb" || src != "workspaces/kbb/projects/p2/media/m1-clip.mp4" || p["logo"] != "workspaces/kbb/brand/logo.png" {
		t.Fatalf("duplicate: id %v name %v ws %v media %v logo %v", p["id"], p["name"], p["workspace"], src, p["logo"])
	}
	if _, err := os.Stat(filepath.Join(ws, "kbb", "projects", "p2", "media", "from-kbb")); err == nil {
		t.Fatal("a duplicate in the same company brought the company's own files along")
	}
	// refusals: a move with a new id, the same id, an id that exists, a bad id; nothing written
	for _, body := range []string{
		`{"from":"kbb","to":"kbb","id":"p1","copy":false,"newId":"p3"}`,
		`{"from":"kbb","to":"kbb","id":"p1","copy":true,"newId":"p1"}`,
		`{"from":"kbb","to":"kbb","id":"p1","copy":true,"newId":"p2"}`,
		`{"from":"kbb","to":"kbb","id":"p1","copy":true,"newId":"../x"}`,
	} {
		if r := call(s, "POST", "/api/projects/move", body); r.Code < 400 {
			t.Fatalf("accepted %s: %d", body, r.Code)
		}
	}
	if _, err := os.Stat(filepath.Join(ws, "kbb", "projects", "p3")); err == nil {
		t.Fatal("a refused request left a folder")
	}
}
