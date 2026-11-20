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
// highlight.js syntax-highlights AI Chat code blocks (enhanceCodeBlocks() in the
// ai-chat blade applies it post-stream, never per-frame). /lib/common keeps the
// bundle to the ~40 common languages. One dark theme: code blocks are dark in
// both light and dark mode, matching the existing chat card aesthetic.
import hljs from 'highlight.js/lib/common';
import 'highlight.js/styles/github-dark.css';
window.hljs = hljs;
// Notify any waiting Alpine components that marked is now available (the Vite
// module is deferred; AI Chat's parseMd may run before this executes).
window.dispatchEvent(new Event('marked-ready'));

// Incidents table: freeze the ID + name columns. The title column's sticky
// offset must equal the ID column's real rendered width (its content is the
// bulk-select checkbox + the ID link, so it exceeds the 80px width hint).
// ponytail: measured at runtime instead of hardcoding, so content changes
// (longer IDs, checkbox padding) can't drift the offset out of sync.
const syncStickyIdWidths = () => {
    document.querySelectorAll('.fi-ta-table:has(.sticky-col-id)').forEach((table) => {
        // This branch's PROJ-022 expand chevron is the FIRST sticky cell —
        // its width pays into the ID and title offsets. Tables without the
        // expand column (Issues, …) keep --sticky-expand-w at 0.
        const expand = table.querySelector('td.sticky-col-expand') ?? table.querySelector('th.sticky-col-expand');
        if (expand) table.style.setProperty('--sticky-expand-w', `${expand.offsetWidth}px`);
        const cell = table.querySelector('td.sticky-col-id') ?? table.querySelector('th.sticky-col-id');
        if (cell) table.style.setProperty('--sticky-col-id-w', `${cell.offsetWidth}px`);
    });
};
['load', 'resize'].forEach((e) => window.addEventListener(e, syncStickyIdWidths));
['livewire:navigated', 'livewire:update'].forEach((e) => document.addEventListener(e, syncStickyIdWidths));
