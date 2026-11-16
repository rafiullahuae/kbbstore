#!/bin/bash
# Builds the engine for both kinds of Mac (and Linux for the test scripts). Needs only Go 1.24, no modules.
set -euo pipefail
cd "$(dirname "$0")"
export CGO_ENABLED=0
GOOS=darwin GOARCH=arm64 go build -trimpath -ldflags "-s -w" -o kbbstudio-mac-arm64 .
GOOS=darwin GOARCH=amd64 go build -trimpath -ldflags "-s -w" -o kbbstudio-mac-amd64 .
GOOS=linux GOARCH=amd64 go build -trimpath -ldflags "-s -w" -o kbbstudio-linux-amd64 .
ls -la kbbstudio-*
