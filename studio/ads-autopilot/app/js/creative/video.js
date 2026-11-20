// The owner's videos: upload once (the engine copies the file into
// "~/KBB Ads Autopilot Data/videos/"), measure it in the browser, sample a
// handful of small frames (first 3 s densely, then spread) and ask the engine
// to analyse them (local checks + Claude vision). Frames are 320 px JPEGs.
import { api } from '../api.js';

const FRAME_W = 320;

export function sampleTimes(d) {
  const t = [0.1, 0.6, 1.2, 1.8, 2.5, 3.2].filter((x) => x < d);
  for (const f of [0.35, 0.55, 0.75, 0.95]) if (d * f > 3.4) t.push(Math.min(d - 0.1, d * f));
  return t.slice(0, 10);
}

// seek, giving up after 3 s so a damaged file cannot hang the page
function seek(v, s) { return new Promise((ok) => { const h = () => { v.removeEventListener('seeked', h); clearTimeout(tm); ok(); }; const tm = setTimeout(h, 3000); v.addEventListener('seeked', h); v.currentTime = s; }); }

// measure + frames, all local
export async function probe(file) {
  const url = URL.createObjectURL(file);
  const v = document.createElement('video');
  v.muted = true; v.preload = 'auto'; v.playsInline = true; v.src = url;
  await new Promise((ok, no) => { v.onloadeddata = ok; v.onerror = () => no(new Error('This video cannot be read here (codec?)')); });
  // WebM straight from a recorder has no duration in its header (Infinity):
  // seek far past the end once and the browser works it out.
  if (!isFinite(v.duration)) { await seek(v, 1e7); await seek(v, 0); }
  const d = isFinite(v.duration) ? v.duration : 0, w = v.videoWidth, h = v.videoHeight;
  const cv = document.createElement('canvas');
  cv.width = FRAME_W; cv.height = Math.round(FRAME_W * h / Math.max(1, w));
  const x = cv.getContext('2d');
  const frames = [];
  for (const s of sampleTimes(d)) {
    await seek(v, s);
    x.drawImage(v, 0, 0, cv.width, cv.height);
    frames.push({ t: Math.round(s * 10) / 10, jpeg: cv.toDataURL('image/jpeg', 0.72).split(',')[1] });
  }
  let hasAudio = true;
  if (typeof v.mozHasAudio === 'boolean') hasAudio = v.mozHasAudio;
  else if (v.audioTracks) hasAudio = v.audioTracks.length > 0;
  else if (file.size < 60e6) { try { const ac = new OfflineAudioContext(1, 1, 22050); await ac.decodeAudioData(await file.arrayBuffer()); } catch { hasAudio = false; } }
  v.pause(); v.removeAttribute('src'); v.load(); // stop the element before the URL goes
  URL.revokeObjectURL(url);
  return { duration_s: Math.round(d * 100) / 100, width: w, height: h, has_audio: hasAudio, frames };
}

// upload + analyse one file. status: 'new' | 'past'; results: owner's rough numbers (optional)
export async function addVideo(file, status, results, notes, onStep) {
  onStep && onStep('upload');
  const v = await api('/api/brain/x/videos', { method: 'POST', body: file, headers: { 'X-Filename': encodeURIComponent(file.name), 'X-Status': status, 'content-type': file.type || 'application/octet-stream' } });
  onStep && onStep('frames');
  const p = await probe(file);
  onStep && onStep('analyse');
  return api(`/api/brain/x/videos/${v.id}/analyse`, { method: 'POST', body: Object.assign(p, { results: results || null, notes: notes || '' }) });
}
