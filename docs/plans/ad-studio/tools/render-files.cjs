// Draws full-HD files of a few templates through the editor's own download path (html-to-image),
// so the picture that leaves the app can be looked at: node tools/render-files.cjs out-dir id:fmt ...
const { chromium } = require('playwright');
const path = require('path'), fs = require('fs');
(async () => {
  const out = path.resolve(process.argv[2]); fs.mkdirSync(out, { recursive: true });
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const p = await b.newPage({ viewport: { width: 1280, height: 900 } });
  await p.goto('file://' + path.join(__dirname, '..', 'ad-studio.html')); await p.waitForFunction(() => document.documentElement.dataset.ready === '1');
  for (const job of process.argv.slice(3)) {
    const [id, fmt] = job.split(':');
    const data = await p.evaluate(async ([id, fmt]) => { const S = window.KBB_STUDIO; S.useTemplate(id); const blob = await S.renderFile(fmt, 0, 'jpg');
      return await new Promise((r) => { const fr = new FileReader(); fr.onload = () => r(fr.result.split(',')[1]); fr.readAsDataURL(blob); }); }, [id, fmt]);
    fs.writeFileSync(path.join(out, id + '-' + fmt + '.jpg'), Buffer.from(data, 'base64')); console.log('wrote', id, fmt);
  }
  await b.close();
})();
