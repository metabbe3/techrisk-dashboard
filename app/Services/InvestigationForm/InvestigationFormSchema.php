<?php

declare(strict_types=1);

namespace App\Services\InvestigationForm;

/**
 * Ground truth: "Incident Investigation Form - Template 2025 v1.0.docx".
 * One map driving the intake form, the AI extraction prompt's field list,
 * the required-field setting and both renderers.
 */
class InvestigationFormSchema
{
    /** Verbatim from the template's Statement of Truth section. */
    public const STATEMENT_OF_TRUTH = 'I fill in all required information based on actual event without any alteration or modification, and I declare that all information stated in this form are true and realize the legal impact if providing false statement.';

    public const SECTION_HEADER = 'header';

    public const SECTION_SUMMARY = 'summary';

    public const SECTION_CHRONOLOGY = 'chronology';

    public const SECTION_ACTION_ITEMS = 'action_items';

    /**
     * section_key => [label, fields (key => question/label)]. Chronology rows
     * are {time, event}; action rows are {item, due_date, verification, owner}.
     */
    public const SECTIONS = [
        'header' => [
            'label' => 'Header',
            'fields' => [
                'incident_name' => 'Incident name',
                'business_unit' => 'Business unit',
                'technology' => 'Technology',
                'incident_date' => 'Incident date',
                'name' => 'Name (investigator)',
                'impacted_domain' => 'Impacted domain',
                'job_role' => 'Job role',
                'duration' => 'Duration',
                'job_function' => 'Job function related to the incident',
                'statement_date' => 'Statement date',
            ],
        ],
        'summary' => [
            'label' => 'Incident Summary',
            'fields' => [
                'description' => 'Description',
                'business_flow' => 'Business flow',
                'business_impact' => 'Business impact',
                'occurred_time' => 'Occurred time',
                'discovery_time' => 'Discovery time',
                'discovery_mechanism' => 'Discovery mechanism',
                'stop_bleeding_time' => 'Stop bleeding time',
            ],
        ],
        'chronology' => [
            'label' => 'Incident Chronology & Screen Capture',
            'fields' => [
                'time' => 'Time',
                'event' => 'Event',
            ],
        ],
        'development_process' => [
            'label' => 'Development Process',
            'fields' => [
                'q1' => 'Is the business/prod design reasonable or not?',
                'q2' => "Can it be prevented in SA review? If yes, why doesn't it detect?",
                'q3' => 'Please provide the link of SA review',
                'q4' => "Can it be prevented in Fund Risk analysis? If yes, why doesn't it detect?",
                'q5' => "Can it be prevented in UT/CI? If yes, why doesn't it detect?",
                'q6' => "Can it be prevented in DEV testing? If yes, why doesn't it detect?",
            ],
        ],
        'testing_process' => [
            'label' => 'Testing Process',
            'fields' => [
                'q1' => "Can it be prevented in TA review? If yes, why doesn't it detect?",
                'q2' => "Can it be prevented in Fund Risk analysis? If yes, why doesn't it detect?",
                'q3' => "Can it be prevented in manual testing? If yes, why doesn't it detect?",
                'q4' => "Can it be prevented in automatic testing? If yes, why doesn't it detect?",
                'q5' => "Can it be prevented in SIT regression testing? If yes, why doesn't it detect?",
                'q6' => "Can it be prevented in pre-verification? If yes, why doesn't it detect?",
                'q7' => "Can it be prevented in pilot-verification? If yes, why doesn't it detect?",
                'q8' => "Can it be prevented in grey-verification? If yes, why doesn't it detect?",
            ],
        ],
        'deployment_process' => [
            'label' => 'Deployment Process',
            'fields' => [
                'q1' => "Whether the change/deployment is expected? If it's an online change, whether it has the online change ticket?",
                'q2' => 'Whether the change ticket content includes monitor/grey/rollback solution?',
                'q3' => 'Whether the impact of the change/deployment is well-evaluated? If no, which point is not well-evaluated?',
                'q4' => 'Whether the change/deployment violates the operation and maintain red line? (3 Golden Principle/Blue-Green Deployment)',
            ],
        ],
        'fund_check_monitoring' => [
            'label' => 'Fund Check Alerts and Monitoring',
            'fields' => [
                'q1' => 'Whether the real-time monitoring detect it? Whether the real-time monitoring is completed? Whether we can do some improvements?',
                'q2' => 'Whether the real-time monitoring alarm in time? How long is for the discovery duration? Whether we can do some improvement?',
                'q3' => 'Whether the real-time monitoring has noise for this case?',
                'q4' => 'Whether the T+1 fund check detect it? Whether the T+1 fund check is completed? Whether we can do some improvements?',
                'q5' => 'Whether the T+1 fund check alarm in time? How long is for the discovery duration? Whether we can do some improvement?',
                'q6' => 'Whether the T+1 fund check alarm has noise for this case?',
                'q7' => 'Whether the error/exception log is completed? Whether we can optimize the logs?',
            ],
        ],
        'contingency_process' => [
            'label' => 'Contingency Process',
            'fields' => [
                'q1' => 'Is the contingency handling timely? If no, what are the block points?',
                'q2' => 'Is the contingency handling duration reasonable?',
                'q3' => 'Is there a contingency plan? If no, can we add a new contingency plan?',
                'q4' => 'Is the contingency plan effective?',
                'q5' => 'Is contingency handling resource coordination and cooperation smoothly? If no, what are the block points?',
            ],
        ],
        'internal_control' => [
            'label' => 'Internal Control (Maker Checker)',
            'fields' => [
                'q1' => 'Is there any approval during the process? Please explain.',
                'q2' => 'Please inform the maker & checker during the process.',
                'q3' => 'Is the application/system supporting the approval process? Please explain.',
            ],
        ],
        'grc_review' => [
            'label' => 'GRC Review',
            'fields' => [
                'q1' => 'Is there any related governance (SOP, Work Instruction) regarding existing internal control and the discrepancies? Please explain.',
                'q2' => 'Is the existing internal control need to be reviewed from segregation of duties, span of control and four eyes principles side?',
                'q3' => 'Is existing internal control effective to reduce the risk to lower risk? Please explain.',
            ],
        ],
        'action_items' => [
            'label' => 'Action Improvement',
            'fields' => [
                'item' => 'Action item',
                'due_date' => 'Due date',
                'verification' => 'Verification standard',
                'owner' => 'Owner',
            ],
        ],
    ];

    /** Sections whose value is a list of rows rather than one answer per field. */
    public const LIST_SECTIONS = ['chronology', 'action_items'];

    /** Q&A sections (fixed question lists — chronology/action rows are free-form). */
    public static function qaSections(): array
    {
        return array_values(array_diff(array_keys(self::SECTIONS), self::LIST_SECTIONS));
    }

    /** Flatten to "section.field" => question for settings/validation. */
    public static function flatFields(): array
    {
        $flat = [];
        foreach (self::SECTIONS as $sectionKey => $section) {
            foreach ($section['fields'] as $fieldKey => $label) {
                $flat["{$sectionKey}.{$fieldKey}"] = $label;
            }
        }

        return $flat;
    }
}
