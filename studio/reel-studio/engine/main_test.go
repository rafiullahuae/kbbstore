package main

import "testing"

// Hugging Face sends model weights to its CDNs; only its own hosts may be followed.
func TestRedirectOK(t *testing.T) {
	for _, h := range []string{"huggingface.co", "cdn-lfs.huggingface.co", "cas-bridge.xethub.hf.co", "us.aws.cdn.hf.co", "eu.aws.cdn.hf.co", "cdn-lfs-us-1.hf.co"} {
		if !redirectOK(h) {
			t.Errorf("%s should be allowed", h)
		}
	}
	for _, h := range []string{"hf.co.evil.com", "evilhf.co", "evilhuggingface.co", "example.com", "127.0.0.1", "::1", "", "x.hf.co@evil.com"} {
		if redirectOK(h) {
			t.Errorf("%s should be refused", h)
		}
	}
}
