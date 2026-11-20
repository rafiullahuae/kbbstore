package metamock

import (
	"encoding/json"
	"strings"
)

// creativeView: an ad's creative as GET …/ads?fields=creative{…} returns it (the history import reads its kind).
func (s *Server) creativeView(id string, c obj) obj {
	v := obj{"id": id, "object_type": "SHARE"}
	var spec any
	if str, ok := c["object_story_spec"].(string); ok && json.Unmarshal([]byte(str), &spec) == nil {
		v["object_story_spec"] = spec
		if strings.Contains(str, "video_data") {
			v["object_type"] = "VIDEO"
		}
	}
	return v
}
