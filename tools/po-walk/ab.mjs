/* Lane PO: before/after server ms, request by request INTERLEAVED, so both
   previews see the same machine load.   node tools/po-walk/ab.mjs PORT_A PORT_B [N] */
const [A, B, N = '40'] = process.argv.slice(2);
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';
const med = (a) => { const s = [...a].sort((x, y) => x - y); return s[Math.floor(s.length / 2)]; };
for (const path of ['/product/co-glow-serum', '/product-category/po-serums/', '/brands/po-anua/']) {
  const ms = { [A]: [], [B]: [] }, q = { [A]: 0, [B]: 0 }, bytes = { [A]: 0, [B]: 0 };
  for (let i = 0; i < Number(N); i++) {
    for (const p of (i % 2 ? [A, B] : [B, A])) {
      const r = await fetch(`http://127.0.0.1:${p}${path}`, { headers: { 'User-Agent': UA } });
      const body = await r.text();
      ms[p].push(Number(r.headers.get('x-co-ms'))); q[p] = r.headers.get('x-co-queries'); bytes[p] = body.length;
    }
  }
  console.log(`${path.padEnd(30)} before ${med(ms[A])} ms ${q[A]} q ${bytes[A]} B | after ${med(ms[B])} ms ${q[B]} q ${bytes[B]} B`);
}
