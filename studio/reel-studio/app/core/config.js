// KBB Studio — core/config.js (CONTRACT, owned by R1)
// The app's name lives HERE ONLY (the owner may rename it): UI title, dialogs and file names read APP_NAME.
// The engine and the launcher carry the same string in engine/main.go (appName) and the .command file name.

export const APP_NAME = 'KBB Studio';
export const APP_VERSION = '0.1.0';

/** Workspaces the studio seeds on first run (the owner, 16 Nov). brandRef = folder under brands/ (R3's kits). */
export const SEED_WORKSPACES = [
  {
    name: 'KBB', fullName: 'K-Beauty Bliss', industry: 'Beauty / K-beauty retail', brandRef: 'kbb',
    colors: { primary: '#e8508b', secondary: '#2b1a24', accent: '#f6c1d4', text: '#ffffff', bg: '#000000' },
    contact: 'kbeautybliss.com',
  },
  {
    name: 'Planet Careers', fullName: 'Planet Careers', industry: 'Immigration & study-visa consultancy', brandRef: 'planet-careers',
    colors: { primary: '#0b2545', secondary: '#13a89e', accent: '#f2c14e', text: '#ffffff', bg: '#0b2545' },
    contact: '',
  },
];

/** Folders inside the studio folder (mirrors the engine's allowlist). */
export const DIRS = { workspaces: 'workspaces', brands: 'brands', library: 'library', models: 'models', vendor: 'vendor' };
export const wsDir = (slug) => `${DIRS.workspaces}/${slug}`;
export const projectsDir = (slug) => `${wsDir(slug)}/projects`;
export const exportsDir = (slug) => `${wsDir(slug)}/exports`;
export const projectDir = (slug, projectId) => `${projectsDir(slug)}/${projectId}`;
