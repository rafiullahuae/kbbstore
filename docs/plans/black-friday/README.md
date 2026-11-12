# Black Friday · Super Sale banner · previews

The owner, 12 Nov 2026: "you didn't build the Black Friday template for Super sale
page banner. please give me multiple designs to choose from."

Previews only; nothing on the shop changed. Each design is drawn ON the real banner
(the preview shop's /super-sale/, the 11.11 layout which carries the big-word slot)
by one stylesheet and its words (`designs.cjs`), which is exactly the shape of a
template in `App\Support\SaleTemplates`. Once a letter is picked it becomes a
template in Appearance -> Super Sale banner -> Template, with its Arabic words.

| | Design | Words |
|---|---|---|
| A | Black & Gold Foil | THE BIGGEST SALE OF THE YEAR · BLACK FRIDAY |
| B | Neon Night 27.11 | 27.11 · BLACK FRIDAY |
| C | Red Tag | LIMITED TIME DEALS · SALE · BLACK FRIDAY |
| D | Noir Blush | GLOW FOR LESS · BLACK FRIDAY |
| E | Monochrome Bold | THE SALE YOU WAITED FOR · BLACK FRIDAY |

Black Friday 2026 is Friday 27 November, hence B's "27.11".

Files: `black-friday-designs.jpg` (all five, computer + phone), `preview.html`,
`shots/<letter>-<1280|390>.png`. Run:
```sh
sh tools/sot-preview.sh 10141 && SOT_TPL=m1 sh tools/sot-tinker.sh
NODE_PATH=$PWD/node_modules node docs/plans/black-friday/shots.cjs
```
