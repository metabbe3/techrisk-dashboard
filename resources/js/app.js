import './bootstrap';

// SortableJS powers the incident Kanban board drag-and-drop.
// Vendored locally (was loaded from a CDN, which broke behind firewalls/offline
// and on CDN outages). Exposed on window for the board's inline script.
import Sortable from 'sortablejs';
window.Sortable = Sortable;

// Marked renders AI Chat markdown → HTML. Vendored locally (same CDN-fragility fix).
import { marked } from 'marked';
window.marked = marked;
// DOMPurify sanitizes that HTML before it is bound via x-html (AI output is
// model-influenced). parseMd() guards on typeof so parsing still works if this
// ever fails to load — it just won't be sanitized.
import DOMPurify from 'dompurify';
window.DOMPurify = DOMPurify;
// Notify any waiting Alpine components that marked is now available (the Vite
// module is deferred; AI Chat's parseMd may run before this executes).
window.dispatchEvent(new Event('marked-ready'));
