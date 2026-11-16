// KBB Studio — shared helpers for the check scripts: a throwaway studio folder, the engine, Chrome.
'use strict';
const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawn } = require('child_process');

const ROOT = path.resolve(__dirname, '..');
const BIN = path.join(ROOT, 'engine', 'kbbstudio-linux-amd64');

function copyDir(src, dst) {
  fs.mkdirSync(dst, { recursive: true });
  for (const e of fs.readdirSync(src, { withFileTypes: true })) {
    const s = path.join(src, e.name), d = path.join(dst, e.name);
    if (e.isDirectory()) copyDir(s, d); else if (e.isFile()) fs.copyFileSync(s, d);
  }
}

/** a fresh studio folder (app, vendor, brands, library, model manifest copied; workspaces empty) */
function makeStudio() {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'kbbstudio-'));
  for (const d of ['app', 'vendor', 'brands', 'library']) if (fs.existsSync(path.join(ROOT, d))) copyDir(path.join(ROOT, d), path.join(dir, d));
  fs.mkdirSync(path.join(dir, 'models'), { recursive: true });
  fs.copyFileSync(path.join(ROOT, 'models', 'manifest.json'), path.join(dir, 'models', 'manifest.json'));
  return dir;
}

function startEngine(dir, extra = []) {
  return new Promise((res, rej) => {
    const args = ['--dir', dir, '--no-browser', ...(extra.includes('--idle') ? [] : ['--idle', '600s']), ...extra];
    const p = spawn(BIN, args, { stdio: ['ignore', 'pipe', 'pipe'] });
    let buf = '';
    p.stdout.on('data', async (d) => {
      buf += d;
      const m = /running at (http:\/\/[^\s]+)\/app\//.exec(buf);
      if (m && !p.url) {
        p.url = m[1];
        const html = await (await fetch(p.url + '/app/')).text();
        p.token = /rs-token" content="([^"]+)/.exec(html)[1];
        res(p);
      }
    });
    p.stderr.on('data', (d) => { p.log = (p.log || '') + d; });
    p.on('exit', (c) => { p.exited = true; if (!p.url) rej(new Error('engine exited ' + c + ' ' + (p.log || ''))); });
  });
}
const stopEngine = (e) => new Promise((r) => { if (e.exited) return r(); e.on('exit', r); e.kill('SIGTERM'); });

async function launchChrome() {
  const { chromium } = require('playwright');
  return chromium.launch({
    executablePath: fs.existsSync('/usr/bin/google-chrome') ? '/usr/bin/google-chrome' : undefined,
    args: ['--autoplay-policy=no-user-gesture-required', '--disable-features=MediaRouter'],
  });
}

module.exports = { ROOT, BIN, makeStudio, startEngine, stopEngine, launchChrome };
