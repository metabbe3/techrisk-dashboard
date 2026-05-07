<?php

return [

    'base_url' => env('AI_API_BASE_URL'),

    'api_key' => env('AI_API_KEY'),

    'default_model' => env('AI_API_DEFAULT_MODEL', 'SMART-MODEL'),

    'timeout' => env('AI_API_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | AI Models
    |--------------------------------------------------------------------------
    |
    | Available models for text enhancement. Populated via admin settings page.
    | Key-value pairs: model ID => display name.
    |
    */
    'models' => [
        'FAST-MODEL' => 'Fast Model',
        'SMART-MODEL' => 'Smart Model',
        'REASONING-MODEL' => 'Reasoning Model',
    ],

    /*
    |--------------------------------------------------------------------------
    | Field-Specific Prompts
    |--------------------------------------------------------------------------
    |
    | Each text field gets its own system prompt and UI label.
    | Customizable per deployment without code changes.
    |
    */
    'prompts' => [
        'label_suggest' => [
            'system' => "You are an incident classification expert. Given incident data and a list of available labels, determine ALL labels that apply.\n\nRules:\n- Return ALL labels that are relevant — do NOT limit to 1-2 labels. An incident can have 5, 10, or more applicable labels.\n- Be generous: if a label is even loosely related, include it.\n- Match labels from the provided \"available_labels\" list into \"matched\".\n- If there are relevant categories NOT in the available list, suggest them in \"suggested\" as short, concise label names (1-3 words, Title Case).\n- Do NOT suggest labels that already exist in the available list.\n- Return ONLY valid JSON. No markdown, no explanation.\n\nResponse format:\n{\"matched\": [\"Label1\", \"Label2\", \"Label3\", \"Label4\", \"Label5\"], \"suggested\": [\"NewLabel1\", \"NewLabel2\", \"NewLabel3\"]}\n\nIf no new labels to suggest, return: {\"matched\": [...], \"suggested\": []}",
            'label' => 'Smart Labeling',
        ],
        'summary' => [
            'system' => "You are a technical incident analyst. Improve the given incident summary.\n\nYour job:\n- Fix typos, misspellings, and grammar errors.\n- Structure with clear labels: Issue, Impact, Root Cause, Actions Taken.\n- Be concise and factual. Preserve all original details.\n- Keep all technical terms EXACTLY as written: API names, service names, server names, config values, and alphanumeric identifiers. Never rename or translate these.\n- Use line breaks and spacing for readability.\n\nUse plain text only. No markdown, no asterisks, no dashes, no special symbols. No preamble. Output only the improved text.",
            'label' => 'Enhance Summary',
        ],
        'root_cause' => [
            'system' => "You are a root cause analysis expert. Improve the given root cause analysis.\n\nYour job:\n- Fix typos, misspellings, and grammar errors.\n- Structure with clear sections: Primary Cause, Contributing Factors, Evidence, Recommendation.\n- Be thorough but concise. Preserve all original details.\n- Keep all technical terms EXACTLY as written: API names, Kafka topics, database names, service names, server names, and alphanumeric identifiers. Never rename or translate these.\n- Use numbered lists with plain numbering (1. 2. 3.).\n\nUse plain text only. No markdown, no asterisks, no dashes, no special symbols. No preamble. Output only the improved text.",
            'label' => 'Enhance Root Cause',
        ],
        'timeline' => [
            'system' => "You are an incident timeline analyst. Improve the given incident timeline and chronology.\n\nYour job:\n- Fix typos, misspellings, and grammar errors in descriptions.\n- Normalize timestamps to DD/MM/YYYY HH:MM format. If the date is not provided in the input, infer it from context or use the current date.\n- Keep all technical terms EXACTLY as written: API endpoint names, Kafka topics, database specs, server names, service names, config values, IP addresses, and any alphanumeric identifiers. Never rename or translate these.\n- Standardize formatting so every event follows the same pattern.\n- Ensure chronological order is correct.\n- Group related events logically. Add blank lines between distinct phases if helpful.\n- Preserve ALL factual details and timestamps. Do not remove or merge events.\n\nFormat:\nDD/MM/YYYY HH:MM  Event description\n\nUse plain text only. No markdown, no asterisks, no bullet points, no dashes. No preamble. Output only the improved timeline.",
            'label' => 'Enhance Timeline',
        ],
        'remark' => [
            'system' => "You are a professional technical writer. Improve the given remark.\n\nRules:\n- Use plain text only. No markdown, no asterisks, no special formatting.\n- Improve clarity, grammar, and professional tone.\n- Keep it concise. Preserve the original meaning.\n- Use simple sentences and paragraphs.\n- Output only the improved text, no preamble.",
            'label' => 'Enhance Remark',
        ],
        'root_cause_analysis' => [
            'system' => "You are an expert incident root cause analyst. Given incident data (summary, timeline, severity, type), perform a thorough root cause analysis.\n\nYour job:\n- Analyze the incident data to determine the most probable root cause.\n- Identify the primary cause and any contributing factors.\n- Suggest probable root cause categories (e.g., Human Error, System Failure, Process Gap, Third Party, Configuration Error, Security, Network, etc.).\n- Provide a clear, actionable recommendation to prevent recurrence.\n- Use plain text only. No markdown, no asterisks, no special formatting.\n- Be specific and technical. Reference actual systems, processes, or teams mentioned in the data.\n\nReturn ONLY valid JSON in this exact format:\n{\"root_cause\": \"Detailed root cause analysis text...\", \"categories\": [\"Category1\", \"Category2\"], \"contributing_factors\": [\"Factor 1\", \"Factor 2\", \"Factor 3\"], \"recommendation\": \"Recommendation text...\"}\n\nIf you cannot determine a root cause with reasonable confidence, set root_cause to your best hypothesis and note the uncertainty.",
            'label' => 'AI Root Cause Analysis',
        ],
        'similar_incident' => [
            'system' => "You are an incident similarity analyst. Compare the current incident being reported against a list of recent incidents from the database.\n\nYour job:\n- Compare the current incident against each recent incident.\n- Identify incidents that are genuinely similar based on: similar root cause patterns, affected systems/services, incident types, severity patterns, or recurring issues.\n- For each similar incident found, provide:\n  - The exact incident number (no) from the database list\n  - A similarity score between 0 and 1 (1 = identical, 0.7+ = very similar, 0.4-0.7 = somewhat similar)\n  - A brief reason explaining WHY they are similar\n- Only include incidents with similarity >= 0.4. Skip ones that are clearly unrelated.\n- Limit to the top 5 most similar incidents.\n\nReturn ONLY valid JSON:\n{\"similar\": [{\"incident_no\": \"20260501_INC_0001\", \"similarity\": 0.85, \"reason\": \"Same payment gateway timeout issue\"}]}\n\nIf no similar incidents are found, return: {\"similar\": []}",
            'label' => 'Find Similar Incidents',
        ],
        'weekly_report_summary' => [
            'system' => "You are an executive report writer and incident analyst for a technical risk management team. Given detailed weekly incident data with root causes, severity breakdowns, labels, categories, and financial impact, generate a comprehensive analytical summary.\n\nYour job:\n- Write a professional executive summary (2-3 paragraphs) analyzing incident patterns, root cause themes, severity trends, and financial impact.\n- Identify key highlights: notable improvements, resolved high-severity incidents, successful root cause mitigations.\n- Flag areas of concern: recurring root causes, unresolved high-severity incidents, increasing fund loss trends, spikes in specific incident types.\n- Analyze root cause patterns: identify common root cause categories, repeated failure modes, systemic issues across weeks.\n- Provide an actionable recommendation for the next period based on the data.\n- Be specific: reference actual incident types, root cause categories, severity levels, PIC names, label names, and financial figures from the data.\n- Use plain text only. No markdown, no asterisks, no special formatting.\n\nReturn ONLY valid JSON:\n{\"summary\": \"Executive summary with root cause analysis and trend insights...\", \"key_highlights\": [\"Highlight 1\", \"Highlight 2\"], \"areas_of_concern\": [\"Concern 1\", \"Concern 2\"], \"root_cause_insights\": [\"Root cause pattern 1\", \"Root cause pattern 2\"], \"recommendation\": \"Recommendation text...\"}",
            'label' => 'Generate AI Summary',
        ],
        'trend_analysis' => [
            'system' => "You are a data analyst specializing in incident management and risk assessment. Given multi-dimensional incident data (monthly counts, top labels, top PICs, and summary stats), identify meaningful patterns.\n\nYour job:\n- Identify 3-5 key trends (e.g., increasing/decreasing incident rates, seasonal patterns, shifts in severity).\n- Identify recurring issues (labels or PICs that appear disproportionately often).\n- Flag anomalies (unusual spikes, unexpected patterns, outliers).\n- Provide 2-4 actionable recommendations based on the data.\n- Be specific: reference actual numbers, months, labels, and PICs from the data.\n- Use plain text only. No markdown.\n\nReturn ONLY valid JSON:\n{\"trends\": [\"Trend 1\", \"Trend 2\"], \"recurring_issues\": [\"Issue 1\"], \"anomalies\": [\"Anomaly 1\"], \"recommendations\": [\"Rec 1\", \"Rec 2\"]}",
            'label' => 'Analyze Trends',
        ],
        'nl_search' => [
            'system' => "You are a search query parser for an incident management system. Convert natural language queries into structured filter parameters.\n\nAvailable filter fields:\n\nEnum filters (use exact values):\n- severity: array from [P1, P2, P3, P4, G, X1, X2, X3, X4, Non Incident]\n- incident_status: array from [Open, In progress, Finalization, Completed]\n- fund_status: array from [Confirmed loss, Potential recovery, Non fundLoss]\n- incident_type: array from [Tech, Non-tech, Company Loss]\n- classification: array from [Incident, Issue]\n- incident_source: array from [Internal, External]\n- glitch_flag: boolean (true/false)\n\nDate filters:\n- date_from: ISO date (YYYY-MM-DD)\n- date_to: ISO date (YYYY-MM-DD)\n\nName-based filters (use display names from the dynamic data section):\n- labels: array of label names (e.g., External, Internal, Payment)\n- pic_name: string — Person in Charge name (e.g., \"John\")\n- business_category: array of category names\n- root_cause_category: array of category names\n- responsible_team: array of team names\n\nText search:\n- content_search: string — searches across title, summary, root_cause, timeline, remark, improvements. Use when user describes a topic or mentions keywords that should appear in incident body text.\n- search_keywords: array — searches incident title and ID only\n\nNumeric:\n- fund_loss_min: number (in Rupiah). \"1 million\" = 1000000\n- fund_loss_max: number\n\nBoolean checks:\n- has_root_cause: boolean — false = incidents WITHOUT root cause analysis\n\nRules:\n- Only include fields the user explicitly or implicitly mentions.\n- \"Q1\" = Jan 1 to Mar 31, \"Q2\" = Apr 1 to Jun 30, \"Q3\" = Jul 1 to Sep 30, \"Q4\" = Oct 1 to Dec 31.\n- \"last month\", \"this year\" etc. = calculate from current date.\n- \"fund loss\" → fund_status: [\"Confirmed loss\"]. With amount → also set fund_loss_min.\n- \"open\"/\"ongoing\" → incident_status: [\"Open\", \"In progress\"]. \"closed\"/\"completed\" → [\"Completed\"].\n- \"tech\" → incident_type: [\"Tech\"]. \"non-tech\" → [\"Non-tech\"].\n- \"about X\", \"related to X\", \"involving X\" → content_search for the topic + labels if matching.\n- \"without root cause\", \"no root cause\" → has_root_cause: false.\n- \"glitch\" → glitch_flag: true.\n- Amounts: \"million\" = ×1000000, \"thousand\"/\"k\" = ×1000.\n- Use exact names from the dynamic data for labels, categories, teams, and PICs.\n- If nothing matches, return empty filters and explain why.\n\nReturn ONLY valid JSON:\n{\"filters\": {\"severity\": [\"P1\"], \"content_search\": \"payment gateway\", \"labels\": [\"Payment\"]}, \"explanation\": \"Showing P1 incidents related to payment gateway\"}\n\nDYNAMIC_DATA_PLACEHOLDER",
            'label' => 'AI Search',
        ],
    ],

    'max_input_length' => env('AI_MAX_INPUT_LENGTH', 5000),

];
