// Mermaid renders AI Chat / War Room sequence + flow diagrams. Vendored locally
// (was loaded from a CDN: third-party dependency, render-blocking, version drift).
// Loaded as a page-scoped Vite entry only on the pages that render diagrams, so
// it is not bundled into the global app.js payload. Consumers guard with
// `typeof mermaid !== 'undefined'` and render on demand.
import mermaid from 'mermaid';
window.mermaid = mermaid;
