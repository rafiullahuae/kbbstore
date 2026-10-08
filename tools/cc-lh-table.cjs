/* Lane CC: median table for one tag of tools/cc-lh-ab.sh.  node tools/cc-lh-table.cjs <tag> */
// median table for a tag from runs.jsonl
const fs=require('fs');const tag=process.argv[2];
const rows=fs.readFileSync(require('path').join(__dirname, '..', 'storage/lh-logs/lh/runs.jsonl'),'utf8').trim().split('\n').map(JSON.parse).filter(r=>r.label.startsWith(tag+'-'));
const g={};for(const r of rows){const m=r.label.match(/^.*?-(head|new)-(\w+)-\d+$/);const k=m[2]+'|'+r.profile;((g[k]||={})[m[1]]||=[]).push(r)}
const med=a=>{const s=a.filter(v=>v!=null).sort((x,y)=>x-y);return s.length?s[(s.length-1)>>1]:null};
const M=['fcp','lcp','si','tbt','cls','blockKib','blockN','kib'];
console.log('page|profile n '+M.map(m=>m+'(head→new)').join(' '));
for(const [k,v] of Object.entries(g)){const h=v.head||[],n=v.new||[];console.log(k.padEnd(18),h.length+'/'+n.length,M.map(m=>`${m}=${med(h.map(r=>r[m]))}→${med(n.map(r=>r[m]))}`).join(' '))}
