# Product

## Register

product

## Platform

web

## Users

This is an internal tool, used by four audiences across the incident lifecycle. **Tech / IT operations engineers** are the daily, in-the-tool users — they triage and resolve incidents under time pressure and are the register every screen must hold up for. **Risk & compliance officers** use it for oversight: tracking exposure, fund loss, recurrence, and producing audit-grade reporting. **Engineering teams** own the longer arc — root-cause analysis, post-mortems, and tracked remediation actions. **Leadership / executives** are occasional readers who consume trends, metrics, and weekly reports rather than triage. The interface must serve the pressured operator first and stay legible when the stakes rise, while remaining scannable for the oversight and leadership audiences who read, not drive.

## Product Purpose

TechRisk Portal is the single source of truth for a technical incident's full lifecycle — from first signal through AI-assisted triage, war-room coordination, resolution, and post-mortem. It exists so that operational and technical risk stops living in chat threads, spreadsheets, and tribal memory, and instead gets captured, triaged, resolved, and learned from in one place. Success looks like faster, calmer incident response: incidents triaged and assigned sooner, fund-loss and severity exposure visible as it develops, and every closed incident leaving behind a traceable action and a lesson rather than a hole.

## Positioning

The single operational surface where a technical incident moves from first signal to a closed, learned-from post-mortem — AI-triaged, coordinated through a war room, and never lost between people or tools.

## Brand Personality

Calm and authoritative. Trustworthy, institutional, and unflappable — the tool a pressured on-call engineer reaches for at 3am and the tool a risk officer shows an auditor at 9am. Dense information should read as reassuring competence, not frantic noise. Voice is precise and direct: no cheerleading, no marketing warmth, no exclamation marks. Severity and urgency come through structure and semantic meaning, never through visual alarm. The personality borrows the quiet confidence of a well-run trading desk or a control room, not the surface polish of a SaaS landing page.

## Anti-references

- **Generic admin template.** The default Bootstrap / out-of-the-box Filament look with no identity — beige utility, no considered craft, nothing that signals this is a serious institutional tool. (The committed Indigo palette and custom token ramp exist precisely to refuse this; protect them.)
- **Toy / gamified.** Confetti, playful badges, emoji overload, celebratory animations. They undercut the gravity of fund loss, severity, and operational risk.
- **Enterprise bloat.** "Everything visible at once" dashboards with no hierarchy — intimidating, undifferentiated density where the urgent and the trivial carry equal visual weight.
- **Consumer-marketing bright.** Loud gradients, splashy hero treatments, big rounded marketing cards. Wrong register for an internal tool people use to manage loss and risk.

## Design Principles

- **Calm under pressure.** When severity rises, the interface must stay legible and unflappable. Urgency is communicated through hierarchy and semantic color, never through animation, red flashing, or visual noise. A screen that panics the user has failed.
- **One source of truth, end to end.** An incident's entire arc — signal, triage, war room, resolution, actions, post-mortem — lives in one record. Design must make the lifecycle traceable so nothing falls between triage and closure.
- **Density without clutter.** Information-dense by default (these are expert operators), but every screen earns its hierarchy. Experts should scan, not decode. If everything is emphasized, nothing is.
- **Considered, not templated.** Reject the default admin look. Every custom surface should carry craft and identity — this is a serious institutional tool, and the interface should feel like one was deliberately made, not assembled from scaffolding.
- **Meaning over decoration.** Semantic color (severity, status, fund-loss exposure) carries real information, so it must be used precisely and never decoratively. Color is never the only signal — pair it with text, icon, or position so the meaning survives color-blindness and greyscale.

## Accessibility & Inclusion

Target **WCAG 2.1 AA**. Particular attention to two areas this tool leans on hard: **semantic color** (severity, status, heat-matrix, fund-loss exposure must never rely on color alone — always paired with label, icon, or position), and **reduced motion** (the dashboard uses real-time updates and chart animations; every animation needs a `prefers-reduced-motion` fallback). Dense data tables and the kanban board need strong focus states and keyboard reachability for operators who work without a mouse. Contrast on muted labels against tinted surfaces is a known risk area — body text must hold ≥4.5:1.
