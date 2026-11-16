// KBB Studio · STOCK PHOTOS (Work Abroad lane, 16 Nov 2026). The editor imports this file (core/modules.js MODULE_ENTRIES);
// it registers the 📷 Photos tab in the right column: search Pexels / Unsplash for worker photos by occupation with the
// owner's own free keys, save them into library/people/<occupation>/ with their credit and licence, add them to the reel
// or hand them to the Ad Studio. The panel loads on first open (app/stock/panel.js); the APIs are in app/stock/api.js.
import { registerModule } from '../core/modules.js';

registerModule({
  id: 'stock-photos', label: 'Photos', icon: '📷', tab: { label: 'Photos', icon: '📷', order: 70 },
  panel: (el, ctx) => import('./panel.js').then((m) => m.mountPanel(el, ctx)),
});
