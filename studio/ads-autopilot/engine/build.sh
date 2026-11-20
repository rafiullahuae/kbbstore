#!/bin/bash
# Builds the engine for both kinds of Mac (and Linux for the check scripts). Go 1.24, standard library only, no cgo.
# The version is read from ../VERSION (the one place it lives) and stamped into the binary.
set -euo pipefail
cd "$(dirname "$0")"
VER="$(tr -d '[:space:]' < ../VERSION)"
[[ "$VER" =~ ^[0-9]{1,4}(\.[0-9]{1,4}){1,3}$ ]] || { echo "VERSION '$VER' is not like 0.1.0" >&2; exit 1; }
export CGO_ENABLED=0
LD="-s -w -X main.version=$VER"
GOOS=darwin GOARCH=arm64 go build -trimpath -ldflags "$LD" -o kaa-mac-arm64 .
GOOS=darwin GOARCH=amd64 go build -trimpath -ldflags "$LD" -o kaa-mac-amd64 .
GOOS=linux GOARCH=amd64 go build -trimpath -ldflags "$LD" -o kaa-linux-amd64 .
ls -la kaa-*
