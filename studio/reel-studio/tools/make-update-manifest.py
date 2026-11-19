#!/usr/bin/env python3
"""Writes <folder>/update.json for tools/package-mac.sh: what Configure → Updates checks before it installs a package
(engine/update.go). Every file in the folder is listed with its size and sha256; the notes are the "## <version>"
block of tools/release-notes.txt.

usage: python3 -I tools/make-update-manifest.py "dist/KBB Studio" <version> tools/release-notes.txt [minVersion]
"""
import datetime
import hashlib
import json
import os
import re
import sys

ENGINES = ("engine/kbbstudio-mac-arm64", "engine/kbbstudio-mac-amd64")


def notes_for(path, version):
    out, on = [], False
    with open(path, encoding="utf-8") as f:
        for line in f:
            line = line.rstrip("\n")
            m = re.match(r"^##\s+(\S+)\s*$", line)
            if m:
                on = m.group(1) == version
                continue
            if on and line.strip().startswith("- "):
                out.append(line.strip()[2:].strip())
    return out


def main():
    if len(sys.argv) < 4:
        sys.exit(__doc__)
    folder, version, notes_path = sys.argv[1], sys.argv[2], sys.argv[3]
    minv = sys.argv[4] if len(sys.argv) > 4 else ""
    if not re.match(r"^\d{1,4}(\.\d{1,4}){1,3}$", version):
        sys.exit(f"version {version!r} is not like 1.3.0")
    notes = notes_for(notes_path, version)
    if not notes:
        sys.exit(f"no release notes for {version}: add a '## {version}' block to {notes_path}")
    files = {}
    for root, dirs, names in os.walk(folder):
        dirs.sort()
        for n in sorted(names):
            p = os.path.join(root, n)
            rel = os.path.relpath(p, folder).replace(os.sep, "/")
            if rel == "update.json":
                continue
            if os.path.islink(p) or not os.path.isfile(p):
                sys.exit(f"{rel}: not a plain file (an update refuses links)")
            if any(part.startswith(".") for part in rel.split("/")):
                sys.exit(f"{rel}: hidden files are not shipped (an update refuses them)")
            h = hashlib.sha256()
            with open(p, "rb") as f:
                for chunk in iter(lambda: f.read(1 << 20), b""):
                    h.update(chunk)
            files[rel] = {"sha256": h.hexdigest(), "size": os.path.getsize(p)}
    for e in ENGINES + ("app/index.html",):
        if e not in files:
            sys.exit(f"{e} is missing from {folder}")
    man = {
        "format": 1,
        "app": "KBB Studio",
        "version": version,
        "minVersion": minv,
        "date": datetime.date.today().isoformat(),
        "notes": notes,
        "files": files,
        "signature": None,  # reserved: a later engine may require a signature over this file without this field
    }
    with open(os.path.join(folder, "update.json"), "w", encoding="utf-8") as f:
        json.dump(man, f, indent=1, ensure_ascii=False, sort_keys=True)
        f.write("\n")
    print(f"update.json: {version}, {len(files)} files, {len(notes)} notes")


if __name__ == "__main__":
    main()
