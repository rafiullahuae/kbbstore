#!/usr/bin/env node
// KBB Studio — Lane C: fetch the models the content checks run (once, into tools/out/c-models/<repo>/<path>, never
// committed), exactly the files models/manifest.json lists, size- and sha256-checked. The app itself downloads them
// through the engine's Setup screen; this only saves the check from re-downloading on every run.
//   default: media-tagger (CLIP, 156 MB) + copy-llm-lite (Qwen2.5-0.5B int8, 519 MB) — the headless check has no WebGPU
// usage: node tools/c-fetch-models.cjs [model ids...]
'use strict';
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..');
const DEST = path.join(ROOT, 'tools', 'out', 'c-models');
const man = JSON.parse(fs.readFileSync(path.join(ROOT, 'models', 'manifest.json'), 'utf8'));
const ids = process.argv.slice(2).length ? process.argv.slice(2) : ['media-tagger', 'copy-llm-lite'];
const sha = (f) => crypto.createHash('sha256').update(fs.readFileSync(f)).digest('hex');
for (const id of ids) {
  const m = man.models.find((x) => x.id === id); if (!m) throw new Error('not in the manifest: ' + id);
  for (const f of m.files) {
    const out = path.join(DEST, m.repo, f.path);
    if (fs.existsSync(out) && fs.statSync(out).size === f.size && (!f.sha256 || sha(out) === f.sha256)) continue;
    fs.mkdirSync(path.dirname(out), { recursive: true });
    const url = `https://huggingface.co/${m.repo}/resolve/${man.revision || 'main'}/${f.path}`;
    process.stdout.write(`${id}: ${f.path} (${(f.size / 1e6).toFixed(1)} MB)… `);
    execFileSync('curl', ['-sSfL', '--retry', '4', '-o', out + '.part', url]);
    const got = fs.statSync(out + '.part').size;
    if (got !== f.size) throw new Error(`${f.path}: ${got} bytes, expected ${f.size}`);
    if (f.sha256 && sha(out + '.part') !== f.sha256) throw new Error(f.path + ': sha256 mismatch');
    fs.renameSync(out + '.part', out);
    console.log('ok');
  }
  console.log(id, 'ready', (m.size / 1e6).toFixed(0), 'MB');
}
