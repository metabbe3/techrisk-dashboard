<?php

// Team-viewer role (owner 2026-11-20): maps an Entra directory `department`
// string to the responsible-team category name used on incidents. Lookup is
// case-insensitive on the trimmed department (SyncUserTeams normalizes both
// sides) — no guessing. `users:sync-teams` reports every unmapped non-empty
// department so HR renames are never silently dropped; fix the map there and
// re-run.
//
// 32-team grouping approved by the owner 2026-11-20 (the 11 prod teams +
// business teams — "put ALL OF IT into category responsible team"):
// Dev folds Fire/FIRE/Fire-QA/Bright/Bright-QA/Engineering; TS folds
// Customer Care; Company Loss folds Fraud/AML/Customer Fund; Risk Strategy
// folds Compliance/Legal-and-Compliance variants; 3rd Party covers parent-
// company/board rows (Emtek, ANT Group, Radikari, President Commissioner) —
// other vendor users are assigned manually.
return [
    // ── The 11 prod teams ─────────────────────────────────────────────
    'Fire-Dev' => 'Dev',
    'Bright-Dev' => 'Dev',
    'Fire' => 'Dev',
    'FIRE' => 'Dev',
    'Bright' => 'Dev',
    'Fire-QA' => 'Dev',
    'Bright-QA' => 'Dev',
    'Engineering' => 'Dev',

    'Quality Assurance Engineering' => 'QA',

    'Security' => 'IT Sec',
    'it security' => 'IT Sec',

    'Site Reliability Engineering & AI Ops' => 'SRE',
    'Site Reliability Engineering' => 'SRE',

    'Infrastructure Data Center' => 'IDC',

    'Corporate IT' => 'IT Office',
    'Technology' => 'IT Office',
    'IT' => 'IT Office',
    'Tech' => 'IT Office',
    'Tech Operations' => 'IT Office',
    'Technical Risk' => 'IT Office',

    'Data' => 'Data',

    'Technical Support' => 'TS',
    'Customer Care' => 'TS',
    'Customer Service' => 'TS',
    'CS' => 'TS',

    'Customer Fund' => 'Company Loss',
    'Fraud Investigation & Recovery' => 'Company Loss',
    'Fraud Prevention & Monitoring' => 'Company Loss',
    'Fraud Management & Authorization' => 'Company Loss',
    'AML Operations' => 'Company Loss',
    'AML Operations & CFM' => 'Company Loss',
    'CFM' => 'Company Loss',

    'Enterprise Risk Management' => 'Risk Strategy',
    'Risk Architecture & Engineering' => 'Risk Strategy',
    'Enterprise Risk Management & Risk Analytics' => 'Risk Strategy',
    'Financial Services Risk Management' => 'Risk Strategy',
    'Risk Monitoring' => 'Risk Strategy',
    'Risk Management' => 'Risk Strategy',
    'Risk Product & Assessment' => 'Risk Strategy',
    'Compliance' => 'Risk Strategy',
    'Financial Crime Compliance' => 'Risk Strategy',
    'Legal & Compliance' => 'Risk Strategy',
    'Corporate Legal & Compliance' => 'Risk Strategy',

    'Emtek' => '3rd Party',
    'ANT Group' => '3rd Party',
    'Radikari' => '3rd Party',
    'President Commissioner' => '3rd Party',

    // ── Business teams (owner: "put ALL OF IT into category") ────────
    'Project Management' => 'Project Management',
    'Finance & Accounting' => 'Finance',
    'Finance' => 'Finance',
    'Tech Financial Management' => 'Finance',
    'Liquidity & Treasury (LIT)' => 'Finance',
    'Product Design' => 'Product Design',
    'Product Designer' => 'Product Design',
    'Business Automation' => 'Business Automation',
    'Category Management' => 'Category Management',
    'GA & Procurement' => 'GA & Procurement',
    'Internal Audit' => 'Internal Audit',
    'Creative' => 'Creative',
    'Marketing' => 'Marketing',
    'Marketing Insight' => 'Marketing',
    'Digital Marketing' => 'Marketing',
    'People Acquisition & Organization Development' => 'People Ops',
    'People Operations, Services, and Governance' => 'People Ops',
    'People Learning Enrichment' => 'People Ops',
    'people' => 'People Ops',
    'Legal' => 'Legal',
    'Legal Operations' => 'Legal',
    'Communications' => 'Communications',
    'Communication' => 'Communications',
    'Corporate Communications' => 'Communications',
    'Network & Ecosystem Outreach (NEO)' => 'NEO',
    'Channel Partnership' => 'NEO',
    'Channel' => 'NEO',
    'Merchant' => 'NEO',
    // Mini-program work sits with the channel/ecosystem group (owner table
    // omitted it; closest fit — move if wrong).
    'Integrated Mini Program Acceleration & Channel Transformation' => 'NEO',
    'UED - Payment' => 'UED',
    'UED - B2B & Financial Services' => 'UED',
    'Business Operations' => 'Business Operations',
    'Operations' => 'Business Operations',
    'Operation' => 'Business Operations',
    'OM' => 'Business Operations',
    'Business & Market Intelligence' => 'Business & Market Intelligence',
    'System & Process Advancement, Reinvention, and Knowledge (SPARK)' => 'Innovation Programs',
    'Advanced Reinvention & Commercialization (ARC)' => 'Innovation Programs',
    'Project Ideation & Concept Implementation (PACE)' => 'Innovation Programs',
    'Innovation' => 'Innovation Programs',
    'Public Policy & Government Relations' => 'Public Policy & Government Relations',
    'Financial Service' => 'Financial Service',
    'CEO Office' => 'Executive Office',
    'Corporate Strategy' => 'Executive Office',
    'Corporate Development' => 'Corporate Development & Sales',
    'Sales' => 'Corporate Development & Sales',
];
