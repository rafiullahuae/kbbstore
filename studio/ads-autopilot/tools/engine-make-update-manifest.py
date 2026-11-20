#!/usr/bin/env python3
"""update.json for the packages Settings → Updates installs (engine/update.go checks every byte of it).

Full package (tools/package-mac.sh): every file in the folder, with its size and sha256; the notes are the
"## <version>" block of tools/release-notes.txt.

  python3 -I tools/engine-make-update-manifest.py "dist/KBB Ads Autopilot" <version> tools/release-notes.txt [minVersion]

Patch (tools/package-patch.sh): <out> receives only the files of <to> that are new or changed against <from>, and an
update.json (format 2, kind patch) naming the exact version it applies to, the files it removes and EVERY file of the
new version (result) -- the engine stages the patch over the installed copy and refuses unless the outcome is exactly
that list.

  python3 -I tools/engine-make-update-manifest.py --patch <from folder> <to folder> <out folder> <notes file>
"""
import datetime
import hashlib
import json
import os
import re
import shutil
import subprocess
import sys

APP = "KBB Ads Autopilot"
ENGINES = ("engine/kaa-mac-arm64", "engine/kaa-mac-amd64")
VERSION_RE = re.compile(r"^\d{1,4}(\.\d{1,4}){1,3}$")


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


def listing(folder):
    """every file under folder but update.json: {rel: {sha256, size}}; links and hidden files refuse"""
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
    return files


def version_of(folder):
    with open(os.path.join(folder, "VERSION"), encoding="utf-8") as f:
        v = f.read().strip()
    if not VERSION_RE.match(v):
        sys.exit(f"{folder}/VERSION {v!r} is not like 1.3.0")
    return v


def commit():
    try:
        return subprocess.run(["git", "rev-parse", "--short=12", "HEAD"], capture_output=True, text=True, check=True).stdout.strip()
    except Exception:
        return ""


def write(folder, man):
    with open(os.path.join(folder, "update.json"), "w", encoding="utf-8") as f:
        json.dump(man, f, indent=1, ensure_ascii=False, sort_keys=True)
        f.write("\n")


def full(folder, version, notes_path, minv=""):
    if not VERSION_RE.match(version):
        sys.exit(f"version {version!r} is not like 1.3.0")
    notes = notes_for(notes_path, version)
    if not notes:
        sys.exit(f"no release notes for {version}: add a '## {version}' block to {notes_path}")
    files = listing(folder)
    for e in ENGINES + ("app/index.html", "VERSION"):
        if e not in files:
            sys.exit(f"{e} is missing from {folder}")
    write(folder, {
        "format": 1, "app": APP, "version": version, "minVersion": minv, "date": datetime.date.today().isoformat(),
        "commit": commit(), "notes": notes, "files": files,
        "signature": None,  # reserved: a later engine may require a signature over this file without this field
    })
    print(f"update.json: {version}, {len(files)} files, {len(notes)} notes")


def patch(src, dst, out, notes_path):
    vf, vt = version_of(src), version_of(dst)
    if tuple(map(int, vt.split("."))) <= tuple(map(int, vf.split("."))):
        sys.exit(f"the new version ({vt}) must be newer than the one the patch applies to ({vf})")
    notes = notes_for(notes_path, vt)
    if not notes:
        sys.exit(f"no release notes for {vt}: add a '## {vt}' block to {notes_path}")
    old, new = listing(src), listing(dst)
    for e in ENGINES + ("app/index.html", "VERSION"):
        if e not in new:
            sys.exit(f"{e} is missing from {dst}")
    carry = {k: v for k, v in new.items() if old.get(k) != v}
    removed = sorted(k for k in old if k not in new)
    if os.path.exists(out):
        shutil.rmtree(out)
    for rel in carry:
        d = os.path.join(out, *rel.split("/"))
        os.makedirs(os.path.dirname(d), exist_ok=True)
        shutil.copy2(os.path.join(dst, *rel.split("/")), d)
    os.makedirs(out, exist_ok=True)
    write(out, {
        "format": 2, "kind": "patch", "app": APP, "from": vf, "version": vt, "date": datetime.date.today().isoformat(),
        "commit": commit(), "notes": notes, "files": carry, "removed": removed, "result": new, "signature": None,
    })
    size = sum(v["size"] for v in carry.values())
    engines = [e for e in ENGINES if e in carry]
    print(f"patch {vf} → {vt}: {len(carry)} files ({size} bytes), {len(removed)} removed, engine {'changes' if engines else 'unchanged'}")


def main():
    a = sys.argv[1:]
    if a[:1] == ["--patch"] and len(a) == 5:
        patch(*a[1:])
    elif len(a) >= 3 and not a[0].startswith("-"):
        full(a[0], a[1], a[2], a[3] if len(a) > 3 else "")
    else:
        sys.exit(__doc__)


if __name__ == "__main__":
    main()
