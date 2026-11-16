/* KBB Ad Studio · cut-out runtime (built into ad-studio-cutout.js by build.py; the owner keeps it next to ad-studio.html).
 *
 * "✂ Cut out the person" on a worker photo: MediaPipe Tasks Vision 0.10.21 + the selfie segmenter model (Apache-2.0,
 * Google; the same files KBB Studio's Look tab runs, from studio/reel-studio/vendor/mediapipe). Everything is inside this
 * file as gzip + base64 and unpacked into blob: URLs on the first cut-out -- no network request, works from file://.
 * The page loads this file only when the owner presses the button, so the studio itself stays ~2 MB.
 */
(function () {
  'use strict';
  var PACK = /*PACK*/{};
  function unz(b64, type) {
    var bin = atob(b64), u = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) u[i] = bin.charCodeAt(i);
    return new Response(new Blob([u]).stream().pipeThrough(new DecompressionStream('gzip'))).arrayBuffer().then(function (b) { return new Blob([b], { type: type }); });
  }
  var segP = null;
  function segmenter() {
    return segP || (segP = Promise.all([unz(PACK.bundle, 'text/javascript'), unz(PACK.loader, 'text/javascript'), unz(PACK.wasm, 'application/wasm'), unz(PACK.model, 'application/octet-stream')]).then(function (b) {
      var bu = URL.createObjectURL(b[0]), lu = URL.createObjectURL(b[1]), wu = URL.createObjectURL(b[2]);
      return Promise.all([import(bu), b[3].arrayBuffer()]).then(function (r) {
        var V = r[0];
        return V.ImageSegmenter.createFromOptions({ wasmLoaderPath: lu, wasmBinaryPath: wu }, {
          baseOptions: { modelAssetBuffer: new Uint8Array(r[1]), delegate: 'CPU' }, runningMode: 'IMAGE', outputConfidenceMasks: true, outputCategoryMask: false });
      });
    }).catch(function (e) { segP = null; throw e; }));
  }
  // cut(canvas): keeps the person, makes the rest transparent (soft edge), in place
  function cut(cv) {
    return segmenter().then(function (s) {
      var r = s.segment(cv), m = r.confidenceMasks[r.confidenceMasks.length - 1], w = m.width, h = m.height, a = m.getAsFloat32Array();
      var mc = document.createElement('canvas'); mc.width = w; mc.height = h;
      var mx = mc.getContext('2d'), id = mx.createImageData(w, h), d = id.data, n = 0;
      for (var i = 0; i < a.length; i++) {
        var t = (a[i] - .3) / .4, al = t <= 0 ? 0 : t >= 1 ? 1 : t * t * (3 - 2 * t);
        d[i * 4 + 3] = Math.round(al * 255); if (al > .5) n++;
      }
      if (r.close) r.close();
      if (n < a.length * .01) throw new Error('no person found in this photo');
      mx.putImageData(id, 0, 0);
      var cx = cv.getContext('2d'); cx.save(); cx.globalCompositeOperation = 'destination-in'; cx.imageSmoothingQuality = 'high';
      cx.drawImage(mc, 0, 0, cv.width, cv.height); cx.restore();
      return cv;
    });
  }
  window.KBB_CUTOUT = { cut: cut, version: '0.10.21' };
})();
