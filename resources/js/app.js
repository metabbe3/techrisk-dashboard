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

    // DOHA Tickets list (owner 2026-11-25): ref/title/bank freeze on
    // horizontal scroll — the bank offset pays for the measured ref+title
    // widths.
    document.querySelectorAll('.fi-ta-table:has(.sticky-col-doha-ref)').forEach((table) => {
        const ref = table.querySelector('th.sticky-col-doha-ref') ?? table.querySelector('td.sticky-col-doha-ref');
        if (ref) table.style.setProperty('--doha-ref-w', `${ref.offsetWidth}px`);
        const title = table.querySelector('th.sticky-col-doha-title') ?? table.querySelector('td.sticky-col-doha-title');
        if (title) table.style.setProperty('--doha-title-w', `${title.offsetWidth}px`);
    });
};
['load', 'resize'].forEach((e) => window.addEventListener(e, syncStickyIdWidths));
['livewire:navigated', 'livewire:update'].forEach((e) => document.addEventListener(e, syncStickyIdWidths));

// Financial Impact money fields (owner 2026-11-20): the browser-side twin of
// FinancialImpactFormSchema::parse()/format() — same id-ID rules, display only.
// The authoritative parse stays server-side (the dehydrator); this just makes
// the pasted value LOOK right before it's ever sent.
window.trMoneyFormat = (raw) => {
    const s = String(raw).replace(/[^0-9.,]/g, '');
    if (!s || !/[0-9]/.test(s)) return raw;

    let int;
    let dec = '';
    const comma = s.lastIndexOf(',');
    if (comma !== -1) {
        int = s.slice(0, comma);
        dec = s.slice(comma + 1).replace(/,/g, '');
    } else {
        int = s;
        const dot = s.lastIndexOf('.');
        if (dot !== -1 && s.slice(dot + 1).length <= 2) {
            int = s.slice(0, dot);
            dec = s.slice(dot + 1);
        }
    }
    int = int.replace(/[.,]/g, '') || '0';
    dec = dec.slice(0, 2).padEnd(2, '0');
    const n = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(parseInt(int, 10) || 0);
    return `${n},${dec}`;
};


// ---------------------------------------------------------------------------
// Adaptive scrollbars (owner 2026-11-23).
//
// 1) PAGE: the native root bar is hidden (CSS `html::-webkit-scrollbar{0}` —
//    zero gutter, so nothing is reserved and no gap appears at the right
//    edge). A fixed OVERLAY rail + thumb is painted instead and follows the
//    document scroll. Idle = faint; active (cursor within the right 32px,
//    wheel / keyboard scrolling, or dragging the thumb) = solid; it fades
//    ~1.2s after inactivity. Dragging the thumb scrolls the page.
//
// 2) INNER SCROLLERS: every scrollable element gets `.tr-scroller`; scrolling
//    or hovering it reveals its thumb (CSS owns the paint, JS the state).
// ---------------------------------------------------------------------------
(() => {
    const IDLE_MS = 1200;
    let rail = null;
    let thumb = null;

    const ensureRail = () => {
        if (rail && document.body.contains(rail)) {
            return;
        }
        rail = document.createElement('div');
        rail.className = 'tr-vscroll';
        thumb = document.createElement('div');
        thumb.className = 'tr-vscroll-thumb';
        rail.appendChild(thumb);
        document.body.appendChild(rail);

        let dragging = false;
        let startY = 0;
        let startScroll = 0;
        thumb.addEventListener('pointerdown', (event) => {
            dragging = true;
            startY = event.clientY;
            startScroll = window.scrollY;
            thumb.setPointerCapture(event.pointerId);
            setActive(60000);
        });
        thumb.addEventListener('pointermove', (event) => {
            if (!dragging) {
                return;
            }
            const doc = document.documentElement;
            const maxScroll = doc.scrollHeight - window.innerHeight;
            const thumbHeight = Math.max(32, (window.innerHeight / doc.scrollHeight) * window.innerHeight);
            const ratio = (event.clientY - startY) / Math.max(1, window.innerHeight - thumbHeight);
            window.scrollTo({ top: Math.max(0, startScroll + ratio * maxScroll) });
        });
        const endDrag = () => {
            if (dragging) {
                dragging = false;
                setActive();
            }
        };
        thumb.addEventListener('pointerup', endDrag);
        thumb.addEventListener('pointercancel', endDrag);
    };

    const layoutRail = () => {
        ensureRail();
        const doc = document.documentElement;
        const maxScroll = doc.scrollHeight - window.innerHeight;
        if (maxScroll <= 0) {
            rail.style.display = 'none';
            return;
        }
        rail.style.display = '';
        const trackHeight = window.innerHeight;
        const thumbHeight = Math.max(32, (window.innerHeight / doc.scrollHeight) * trackHeight);
        thumb.style.height = `${thumbHeight}px`;
        const progress = window.scrollY / Math.max(1, maxScroll);
        thumb.style.top = `${Math.min(trackHeight - thumbHeight, progress * (trackHeight - thumbHeight))}px`;
    };

    let idleTimer = null;
    const setActive = (holdMs = IDLE_MS) => {
        ensureRail();
        rail.classList.add('tr-scroll-active');
        clearTimeout(idleTimer);
        idleTimer = setTimeout(() => rail.classList.remove('tr-scroll-active'), holdMs);
    };

    const markScrollers = () => {
        document.querySelectorAll('*').forEach((el) => {
            if (el.scrollHeight > el.clientHeight + 2 || el.scrollWidth > el.clientWidth + 2) {
                el.classList.add('tr-scroller');
            }
        });
    };

    document.addEventListener('scroll', (event) => {
        const target = event.target;
        if (target === document || target === document.documentElement || target === window) {
            layoutRail();
            setActive();
            return;
        }
        if (target instanceof HTMLElement) {
            target.classList.add('tr-scroller', 'tr-scroll-active');
            clearTimeout(target.__trScrollTimer);
            target.__trScrollTimer = setTimeout(() => target.classList.remove('tr-scroll-active'), IDLE_MS);
        }
    }, { capture: true, passive: true });

    document.addEventListener('mousemove', (event) => {
        if (window.innerWidth - event.clientX <= 32) {
            setActive();
        }
    }, { passive: true });

    document.addEventListener('wheel', (event) => {
        const inRail = window.innerWidth - event.clientX <= 32;
        if (inRail || event.target === document.body || event.target === document.documentElement) {
            setActive();
        }
    }, { passive: true });

    document.addEventListener('keydown', (event) => {
        if (['ArrowDown', 'ArrowUp', 'PageDown', 'PageUp', 'Home', 'End', ' '].includes(event.key)) {
            setActive();
            layoutRail();
        }
    });

    window.addEventListener('resize', layoutRail);
    ['load', 'livewire:navigated'].forEach((event) => window.addEventListener(event, () => {
        layoutRail();
        markScrollers();
    }));
    new MutationObserver(() => layoutRail()).observe(document.documentElement, { childList: true, subtree: true });
})();
