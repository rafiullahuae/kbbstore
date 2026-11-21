package exchange

// The Mac's own checks on what comes back from the cloud. The cloud ran the app's quality gate
// (app/js/creative/quality.js) on every render; here the Mac does not take its word for it: the measured numbers are
// held against the SAME thresholds (Thresholds mirrors quality.js THRESHOLDS — a test pins them equal), and the file
// itself is opened: a real PNG of exactly the format's size that is not one flat colour, or a real MP4 (ftyp) for
// a 9:16 motion ad.

import (
	"bytes"
	"errors"
	"fmt"
	"image"
	"image/png"
	"math"
)

// Thresholds: quality.js THRESHOLDS (product share, contrast, text density).
var Thresholds = struct{ Product, ContrastSmall, ContrastLarge, Density float64 }{0.4, 4.5, 3.0, 0.22}

var Formats = map[string][2]int{"1:1": {1080, 1080}, "4:5": {1080, 1350}, "9:16": {1080, 1920}}

// CheckQuality: the cloud's gate said ok AND its numbers clear the thresholds.
func CheckQuality(q Quality, lang string) []string {
	var out []string
	if !q.OK {
		out = append(out, "the quality gate refused it in the cloud")
	}
	c := q.Checks
	if c.ProductShare < Thresholds.Product {
		out = append(out, fmt.Sprintf("product only %.0f%% of the frame (needs %.0f%%)", c.ProductShare*100, Thresholds.Product*100))
	}
	if c.MinContrast < Thresholds.ContrastLarge {
		out = append(out, fmt.Sprintf("text contrast %.1f (needs %.1f)", c.MinContrast, Thresholds.ContrastLarge))
	}
	if c.SafeViolations > 0 {
		out = append(out, fmt.Sprintf("%d text item(s) outside the safe zones", c.SafeViolations))
	}
	if c.TextDensity > Thresholds.Density {
		out = append(out, fmt.Sprintf("text covers %.0f%% (max %.0f%%)", c.TextDensity*100, Thresholds.Density*100))
	}
	if lang == "ar" && (c.Arabic == nil || !c.Arabic.OK) {
		out = append(out, "Arabic fonts / joining not verified")
	}
	if q.Score < 50 {
		out = append(out, fmt.Sprintf("quality score %d (needs 50)", q.Score))
	}
	return out
}

// CheckMedia: the file is what the render says it is.
func CheckMedia(r Render, b []byte) error {
	if len(b) == 0 || len(b) > MaxFile {
		return errors.New("the file is empty or too big")
	}
	switch r.Kind {
	case "png":
		wh, ok := Formats[r.Format]
		if !ok {
			return fmt.Errorf("unknown format %q", r.Format)
		}
		im, err := png.Decode(bytes.NewReader(b))
		if err != nil {
			return fmt.Errorf("not a PNG: %v", err)
		}
		if bd := im.Bounds(); bd.Dx() != wh[0] || bd.Dy() != wh[1] {
			return fmt.Errorf("%dx%d, a %s ad is %dx%d", bd.Dx(), bd.Dy(), r.Format, wh[0], wh[1])
		}
		if flat(im) {
			return errors.New("the picture is one flat colour (nothing was drawn)")
		}
	case "mp4":
		if r.Format != "9:16" {
			return errors.New("motion ads are 9:16")
		}
		if len(b) < 1024 || string(b[4:8]) != "ftyp" {
			return errors.New("not an MP4 file")
		}
	default:
		return fmt.Errorf("unknown kind %q", r.Kind)
	}
	return nil
}

// flat: luminance spread over a 24x24 grid of samples.
func flat(im image.Image) bool {
	b := im.Bounds()
	var sum, sq float64
	n := 0.0
	for y := 0; y < 24; y++ {
		for x := 0; x < 24; x++ {
			r, g, bl, _ := im.At(b.Min.X+(x*b.Dx())/24, b.Min.Y+(y*b.Dy())/24).RGBA()
			l := 0.2126*float64(r>>8) + 0.7152*float64(g>>8) + 0.0722*float64(bl>>8)
			sum += l
			sq += l * l
			n++
		}
	}
	m := sum / n
	return math.Sqrt(math.Max(0, sq/n-m*m)) < 3
}
