<?php

namespace App\Services\WarRoom;

class WarRoomToolRegistry
{
    public function getToolDefinitions(?array $enabledTools = null): array
    {
        $allTools = [
            $this->searchIncidents(),
            $this->getIncidentDetails(),
            $this->findSimilarIncidents(),
            $this->getActionItems(),
            $this->webSearch(),
            $this->getStats(),
        ];

        if ($enabledTools === null) {
            return $allTools;
        }

        return array_values(array_filter($allTools, function (array $tool) use ($enabledTools) {
            return in_array($tool['function']['name'], $enabledTools);
        }));
    }

    public function getAllToolNames(): array
    {
        return [
            'search_incidents',
            'get_incident_details',
            'find_similar_incidents',
            'get_action_items',
            'web_search',
            'get_stats',
        ];
    }

    private function searchIncidents(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'search_incidents',
                'description' => 'Search incidents by filters. Returns a list of matching incidents with key fields (ID, title, severity, status, date). Use this to find related incidents, check historical patterns, or get context on similar issues.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'severity' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Filter by severity levels: P1, P2, P3, P4, G, X1-X4',
                        ],
                        'status' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Filter by status: Open, In progress, Finalization, Completed',
                        ],
                        'date_from' => [
                            'type' => 'string',
                            'description' => 'Start date (YYYY-MM-DD)',
                        ],
                        'date_to' => [
                            'type' => 'string',
                            'description' => 'End date (YYYY-MM-DD)',
                        ],
                        'query' => [
                            'type' => 'string',
                            'description' => 'Text search across title, summary, and root cause fields',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Max results to return (default: 10, max: 20)',
                        ],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }

    private function getIncidentDetails(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'get_incident_details',
                'description' => 'Get full details for a specific incident by its display number (e.g., "2026_IN_0001"). Returns complete incident data including timeline, root cause, financial impact, action items, and evidence.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'incident_no' => [
                            'type' => 'string',
                            'description' => 'The incident display number (e.g., "2026_IN_0001")',
                        ],
                    ],
                    'required' => ['incident_no'],
                ],
            ],
        ];
    }

    private function findSimilarIncidents(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'find_similar_incidents',
                'description' => 'Find incidents similar to a given incident based on root cause, labels, severity, and affected systems. Useful for identifying recurring patterns or checking if this is a repeat issue.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'incident_no' => [
                            'type' => 'string',
                            'description' => 'The incident number to find similar incidents for',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Max similar incidents to return (default: 5, max: 10)',
                        ],
                    ],
                    'required' => ['incident_no'],
                ],
            ],
        ];
    }

    private function getActionItems(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'get_action_items',
                'description' => 'Retrieve action improvements and remediation items for one or more incidents. Returns action title, detail, assignee, due date, and completion status.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'incident_no' => [
                            'type' => 'string',
                            'description' => 'The incident number to get actions for',
                        ],
                        'status' => [
                            'type' => 'string',
                            'enum' => ['all', 'pending', 'done'],
                            'description' => 'Filter by completion status (default: all)',
                        ],
                    ],
                    'required' => ['incident_no'],
                ],
            ],
        ];
    }

    private function webSearch(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'web_search',
                'description' => 'Search the internet for information related to the incident. Use this to find external references, known issues, vendor advisories, or best practices for the technologies involved. You can search from multiple angles using additional_queries.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Primary search query (be specific and technical)',
                        ],
                        'additional_queries' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Optional extra search queries for multi-angle coverage (max 2). Use different angles e.g. root cause, industry benchmarks, remediation.',
                        ],
                        'context' => [
                            'type' => 'string',
                            'description' => 'Brief context about what you are looking for and why',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
        ];
    }

    private function getStats(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'get_stats',
                'description' => 'Get aggregate incident statistics for the current year. Returns total incidents, open incidents, fund loss, average MTTR, breakdown by severity and status. Useful for comparing the current incident against overall trends.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'period' => [
                            'type' => 'string',
                            'enum' => ['this_month', 'this_quarter', 'this_year'],
                            'description' => 'Time period for statistics (default: this_year)',
                        ],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }
}
