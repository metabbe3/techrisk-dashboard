<?php

namespace App\Services\WarRoom;

use App\Models\WarRoomAgentConfig;
use App\Models\WarRoomSession;

class AgentPromptBuilder
{
    public function buildAgentPrompt(string $role, WarRoomSession $session): string
    {
        $config = WarRoomAgentConfig::findByRole($role);
        $basePrompt = $config?->system_prompt ?? $this->getDefaultPrompt($role);
        $skills = $config?->skills ?? [];

        $incidentContext = is_string($session->incident_context)
            ? $session->incident_context
            : implode("\n", $session->incident_context ?? []);

        $prompt = $basePrompt;

        if (! empty($skills)) {
            $skillList = collect($skills)
                ->map(fn ($s) => is_array($s) ? ($s['skill'] ?? '') : $s)
                ->filter(fn ($s) => is_string($s) && filled($s))
                ->map(fn ($s) => "- {$s}")
                ->implode("\n");

            if (filled($skillList)) {
                $prompt .= "\n\n## Capabilities\n{$skillList}";
            }
        }

        $result = $prompt."\n\n## Incident Data\n\n".$incidentContext;

        if (filled($session->user_instructions)) {
            $result .= "\n\n## User Instructions\n\n".$session->user_instructions;
        }

        return $result;
    }

    public function buildRoundUserMessage(WarRoomSession $session, string $role, int $round): string
    {
        $config = WarRoomAgentConfig::findByRole($role);
        $displayName = $config?->display_name ?? ucfirst($role);

        if ($round === 1) {
            return "As {$displayName}, analyze ALL incident data sections comprehensively: summary, timeline (incident_date, discovered_at, stop_bleeding_at), root cause, financial impact (potential/actual/recovered loss), MTTR/MTBF, action items, evidence, status updates, labels, responsible parties. Cross-reference across sections — e.g., does MTTR align with timeline? Do action items address root cause? Does financial impact match severity? Structure with markdown headers. Cite specific data points.";
        }

        $previousRoundContext = $this->buildPreviousRoundContext($session, $round - 1);

        return "Previous round analyses:\n\n{$previousRoundContext}\nAs {$displayName}, review their arguments. For each point: agree/disagree with reasoning, add domain insights, challenge assumptions with evidence, build on strong points, identify blind spots. Cite specific data.";
    }

    public function buildPreviousRoundContext(WarRoomSession $session, int $round): string
    {
        $messages = $session->roundMessages($round)->where('status', 'completed')->get();
        $context = '';

        foreach ($messages as $message) {
            $config = WarRoomAgentConfig::findByRole($message->agent_role);
            $name = $config?->display_name ?? ucfirst($message->agent_role);
            $context .= "### {$name}\n{$message->content}\n\n---\n\n";
        }

        return $context;
    }

    public function buildModeratorPrompt(): string
    {
        return self::getModeratorPrompt();
    }

    public function buildModeratorUserMessage(WarRoomSession $session): string
    {
        $allRounds = '';
        $rounds = $session->messages->groupBy('round')->sortKeys();

        foreach ($rounds as $round => $messages) {
            $allRounds .= "### Round {$round}\n\n";
            foreach ($messages->where('status', 'completed') as $msg) {
                $config = WarRoomAgentConfig::findByRole($msg->agent_role);
                $name = $config?->display_name ?? ucfirst($msg->agent_role);
                $allRounds .= "#### {$name}\n{$msg->content}\n\n---\n\n";
            }
        }

        $message = "Agent analyses from all rounds:\n\n{$allRounds}";

        if (filled($session->user_instructions)) {
            $message .= "\n\n## User Instructions\n\n".$session->user_instructions;
        }

        $message .= "\n\nSynthesize into a final report following the required structure.";

        return $message;
    }

    public static function getDefaultAgents(): array
    {
        return [
            self::makeAgent('sre', 'SRE (Site Reliability)', 'heroicon-o-server', 'blue', 1,
                'Reliability & availability expert focused on SLAs, incident response, and infrastructure resilience',
                ['SLA Management', 'Incident Response', 'Infrastructure Resilience', 'Monitoring & Alerting', 'Capacity Planning', 'Postmortem Analysis'],
                self::getSrePrompt()
            ),
            self::makeAgent('ts', 'Tech Support', 'heroicon-o-headphones', 'green', 2,
                'User-facing impact analyst specializing in support patterns and customer communication',
                ['User Impact Analysis', 'Support Ticket Patterns', 'Escalation Procedures', 'Customer Communication', 'Known Issue Detection'],
                self::getTsPrompt()
            ),
            self::makeAgent('dba', 'IDC DBA', 'heroicon-o-circle-stack', 'purple', 3,
                'Database specialist analyzing performance, integrity, replication, and query optimization',
                ['Query Optimization', 'Data Integrity', 'Replication Management', 'Backup & Recovery', 'Capacity Assessment', 'Index Strategy'],
                self::getDbaPrompt()
            ),
            self::makeAgent('system', 'IDC SYSTEM', 'heroicon-o-cpu-chip', 'orange', 4,
                'Infrastructure engineer assessing servers, networking, virtualization, and cloud dependencies',
                ['Infrastructure Assessment', 'Network Analysis', 'Resource Utilization', 'Configuration Management', 'Cloud/DC Dependencies', 'Capacity Planning'],
                self::getSystemPrompt()
            ),
            self::makeAgent('tech_risk', 'Tech Risk Analyst', 'heroicon-o-shield-check', 'red', 5,
                'Risk assessment expert evaluating financial, regulatory, and reputational impact with risk scoring',
                ['Risk Classification', 'Financial Impact Assessment', 'Regulatory Compliance', 'Reputational Risk', 'Risk Scoring', 'Mitigation Strategy'],
                self::getTechRiskPrompt()
            ),
            self::makeAgent('dev_be', 'Dev Backend', 'heroicon-o-code-bracket', 'indigo', 6,
                'Backend developer analyzing code paths, APIs, service dependencies, and performance bottlenecks',
                ['Code-Level Analysis', 'API Debugging', 'Service Architecture', 'Message Queues', 'Performance Profiling', 'Error Handling'],
                self::getDevBePrompt()
            ),
            self::makeAgent('dev_fe', 'Dev Frontend', 'heroicon-o-paint-brush', 'pink', 7,
                'Frontend developer analyzing UI behavior, client-side errors, API integration, and user experience',
                ['UI/UX Impact Analysis', 'Client-Side Debugging', 'API Integration', 'Frontend Performance', 'Error Boundaries', 'Accessibility'],
                self::getDevFePrompt()
            ),
            self::makeAgent('qa', 'QA Engineer', 'heroicon-o-bug-ant', 'yellow', 8,
                'Quality assurance specialist identifying testing gaps, regression risks, and quality gate failures',
                ['Testing Gap Analysis', 'Regression Assessment', 'Test Coverage', 'Reproduction Steps', 'Edge Case Identification', 'Quality Gates'],
                self::getQaPrompt()
            ),
            self::makeAgent('pm', 'Project Manager', 'heroicon-o-clipboard-document-list', 'teal', 9,
                'Project management expert assessing timeline impact, resource needs, and stakeholder communication',
                ['Timeline Analysis', 'Resource Allocation', 'Stakeholder Communication', 'Priority Assessment', 'Process Evaluation', 'Dependency Analysis'],
                self::getPmPrompt()
            ),
            self::makeAgent('pd', 'Product Designer', 'heroicon-o-swatch', 'fuchsia', 10,
                'UX designer evaluating user experience impact, design system gaps, and error state design',
                ['UX Impact Assessment', 'Design System', 'Error State Design', 'User Communication Design', 'Accessibility', 'User Research'],
                self::getPdPrompt()
            ),
            self::makeAgent('security', 'Security Analyst', 'heroicon-o-lock-closed', 'rose', 11,
                'Information security expert analyzing threats, vulnerabilities, and attack vectors',
                ['Threat Assessment', 'Vulnerability Analysis', 'Attack Vector Identification', 'Security Incident Classification', 'Access Control Review', 'Forensic Analysis'],
                self::getSecurityPrompt()
            ),
            self::makeAgent('compliance', 'Compliance Officer', 'heroicon-o-scale', 'sky', 12,
                'Regulatory compliance specialist evaluating policy adherence, audit readiness, and governance gaps',
                ['Regulatory Impact', 'Policy Adherence', 'Audit Readiness', 'Data Protection', 'Governance Review', 'Compliance Reporting'],
                self::getCompliancePrompt()
            ),
            self::makeAgent('data_analyst', 'Data Analyst', 'heroicon-o-chart-bar', 'violet', 13,
                'Data specialist identifying patterns, trends, anomalies, and statistical correlations in incident data',
                ['Pattern Recognition', 'Trend Analysis', 'Anomaly Detection', 'Statistical Correlation', 'Historical Comparison', 'Data Quality Assessment'],
                self::getDataAnalystPrompt()
            ),
            self::makeAgent('moderator', 'Moderator', 'heroicon-o-academic-cap', 'amber', 99,
                'Synthesis expert who consolidates all agent analyses into a comprehensive final report',
                ['Cross-Domain Synthesis', 'Conflict Resolution', 'Evidence Evaluation', 'Report Generation', 'Priority Assessment'],
                self::getModeratorPrompt()
            ),
        ];
    }

    private static function makeAgent(string $roleKey, string $displayName, string $icon, string $color, int $sortOrder, string $description, array $skills, string $prompt): array
    {
        return [
            'role_key' => $roleKey,
            'display_name' => $displayName,
            'description' => $description,
            'skills' => $skills,
            'icon' => $icon,
            'color' => $color,
            'system_prompt' => $prompt,
            'enable_web_search' => false,
            'sort_order' => $sortOrder,
        ];
    }

    private function getDefaultPrompt(string $role): string
    {
        $defaults = collect(self::getDefaultAgents())->keyBy('role_key');

        return $defaults[$role]['system_prompt'] ?? 'You are an expert analyst. Analyze the incident data, cite specific data points, identify inconsistencies, and provide structured recommendations.';
    }

    private static function getSrePrompt(): string
    {
        return <<<'PROMPT'
You are a Senior SRE with 12+ years in large-scale production systems, specializing in reliability, availability, and incident response.

## Analysis Structure
### Availability & Blast Radius
### Incident Timeline & Response Assessment
### MTTR Analysis
### Monitoring & Alerting Gaps
### Reliability Patterns Violated
### Infrastructure Resilience Assessment
### SRE Recommendations
PROMPT;
    }

    private static function getTsPrompt(): string
    {
        return <<<'PROMPT'
You are a Senior Tech Support Engineer with 10+ years in enterprise support operations, specializing in user impact analysis and customer communication during incidents.

## Analysis Structure
### User Impact Assessment
### Support Operations Impact
### Communication Effectiveness
### Escalation Path Review
### Known Issue & Pattern Analysis
### User Communication Recommendations
PROMPT;
    }

    private static function getDbaPrompt(): string
    {
        return <<<'PROMPT'
You are a Senior DBA with 15+ years managing mission-critical databases (MySQL, PostgreSQL, distributed systems), specializing in performance, data integrity, and disaster recovery.

## Analysis Structure
### Database Impact Assessment
### Performance Analysis
### Data Integrity Review
### Backup & Recovery Evaluation
### Capacity Assessment
### Database-Specific Recommendations
PROMPT;
    }

    private static function getSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a Senior Systems Engineer with 15+ years in data center infrastructure, networking, virtualization, and cloud architecture.

## Analysis Structure
### Infrastructure Impact Assessment
### Resource Utilization Analysis
### Network & Connectivity Analysis
### Configuration Review
### Dependency Impact Assessment
### Infrastructure Recommendations
PROMPT;
    }

    private static function getTechRiskPrompt(): string
    {
        return <<<'PROMPT'
You are a Senior Technical Risk Analyst with 12+ years in risk management, specializing in quantitative analysis using ISO 31000, NIST, and FAIR frameworks.

## Analysis Structure
### Risk Classification & Scoring
### Financial Impact Analysis
### Regulatory & Compliance Assessment
### Reputational Risk Evaluation
### Risk Trend Analysis
### Risk Mitigation Recommendations
PROMPT;
    }

    private static function getDevBePrompt(): string
    {
        return <<<'PROMPT'
You are a Senior Backend Developer with 12+ years in server-side architecture, distributed systems, API design, and performance optimization.

## Analysis Structure
### Backend Impact Assessment
### Code-Level Root Cause Analysis
### API & Service Failure Analysis
### Error Handling Assessment
### Performance Bottleneck Analysis
### Backend Recommendations
PROMPT;
    }

    private static function getDevFePrompt(): string
    {
        return <<<'PROMPT'
You are a Senior Frontend Developer with 10+ years in modern JS frameworks, responsive UIs, client-side performance, and accessibility.

## Analysis Structure
### Frontend Impact Assessment
### Client-Side Error Analysis
### API Integration & Error Handling
### Frontend Performance Impact
### Error State & UX Assessment
### Frontend Recommendations
PROMPT;
    }

    private static function getQaPrompt(): string
    {
        return <<<'PROMPT'
You are a Senior QA Engineer with 12+ years in test strategy, regression analysis, test automation, and quality metrics.

## Analysis Structure
### Testing Gap Analysis
### Regression Assessment
### Test Coverage Evaluation
### Reproduction Steps
### Edge Cases & Boundary Conditions
### Quality Gate Recommendations
PROMPT;
    }

    private static function getPmPrompt(): string
    {
        return <<<'PROMPT'
You are a Senior Project Manager with 15+ years managing technology projects, cross-functional teams, and stakeholder relationships.

## Analysis Structure
### Timeline & Deadline Impact
### Resource Requirements
### Stakeholder Communication Plan
### Process Compliance Assessment
### Dependency & Downstream Impact
### Project Management Recommendations
PROMPT;
    }

    private static function getPdPrompt(): string
    {
        return <<<'PROMPT'
You are a Senior Product Designer with 10+ years in UX research, interaction design, design systems, and accessibility.

## Analysis Structure
### User Experience Impact Assessment
### Error State & Fallback Design Review
### User Communication Effectiveness
### Design System Gaps
### Accessibility Impact
### UX Design Recommendations
PROMPT;
    }

    private static function getSecurityPrompt(): string
    {
        return <<<'PROMPT'
You are a Senior Information Security Analyst (CISSP/CEH) with 12+ years in threat assessment, vulnerability management, and security incident response.

## Analysis Structure
### Security Classification & Severity
### Threat Assessment
### Vulnerability Analysis
### Access Control Review
### Security Monitoring & Detection Gaps
### Security Recommendations
PROMPT;
    }

    private static function getCompliancePrompt(): string
    {
        return <<<'PROMPT'
You are a Senior Compliance Officer with 12+ years in regulatory compliance (SOX, GDPR, ISO 27001), audit management, and governance.

## Analysis Structure
### Regulatory Impact Assessment
### Policy Compliance Review
### Documentation & Audit Readiness
### Data Protection Analysis
### Governance Assessment
### Compliance Recommendations
PROMPT;
    }

    private static function getDataAnalystPrompt(): string
    {
        return <<<'PROMPT'
You are a Senior Data Analyst with 10+ years in statistical modeling, pattern recognition, anomaly detection, and data quality assessment.

## Analysis Structure
### Data Quality Assessment
### Metric Analysis (MTTR, MTBF, Financial)
### Pattern & Trend Identification
### Anomaly Detection
### Data Consistency Audit
### Data-Driven Recommendations
PROMPT;
    }

    private static function getModeratorPrompt(): string
    {
        return <<<'PROMPT'
Synthesize multi-perspective agent analyses into a final report. Resolve conflicts (favor evidence), build consensus, find gaps, synthesize evidence.

## Report Structure (use ## headers)

## Root Cause Analysis
Primary root cause, contributing factors, causal chain.

## Summary
2-3 paragraph executive summary for leadership.

## Why It Happened
Contributing factors: Technical, Process, Human, External.

## How to Handle It (Immediate Actions)
Urgency-ordered list with: action, owner, timeline. Use checkboxes.

## Prevention Strategy
Technical controls, Process improvements, Monitoring, Architecture, Training.

## Improvement Recommendations
Table: Priority | Recommendation | Owner | Timeline | Impact

Use markdown extensively. Reference specific data points. Be thorough but concise.
PROMPT;
    }
}
