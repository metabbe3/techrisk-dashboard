<?php

declare(strict_types=1);

namespace App\Enums;

enum ApiEndpoint: string
{
    case INCIDENTS = 'incidents';
    case INCIDENTS_BY_NO = 'incidents-by-no';
    case INCIDENTS_MARKDOWN = 'incidents-markdown';
    case LABELS = 'labels';
    case INCIDENT_TYPES = 'incident-types';
    case ACTION_IMPROVEMENTS = 'action-improvements';
    case AI_EXPORT = 'ai-export';
    case CATEGORIES = 'categories';
    case USERS = 'users';

    /**
     * Get all endpoints as an array for select options
     */
    public static function all(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get friendly labels for display
     */
    public function label(): string
    {
        return match ($this) {
            self::INCIDENTS => 'Incidents (List & View)',
            self::INCIDENTS_BY_NO => 'Incidents by Number',
            self::INCIDENTS_MARKDOWN => 'Incidents Markdown Export',
            self::LABELS => 'Labels (Reference Data)',
            self::INCIDENT_TYPES => 'Incident Types (Reference Data)',
            self::ACTION_IMPROVEMENTS => 'Action Improvements',
            self::AI_EXPORT => 'AI Bulk Export',
            self::CATEGORIES => 'Categories (Reference Data)',
            self::USERS => 'Users (Reference Data)',
        };
    }

    /**
     * Get route pattern for endpoint matching
     */
    public function routePattern(): string
    {
        return match ($this) {
            self::INCIDENTS => 'api/v1/incidents',
            self::INCIDENTS_BY_NO => 'api/v1/incidents-by-no',
            self::INCIDENTS_MARKDOWN => 'api/v1/incidents-by-no',
            self::LABELS => 'api/v1/labels',
            self::INCIDENT_TYPES => 'api/v1/incident-types',
            self::ACTION_IMPROVEMENTS => 'api/v1/action-improvements',
            self::AI_EXPORT => 'api/v1/ai/export',
            self::CATEGORIES => 'api/v1/categories',
            self::USERS => 'api/v1/users',
        };
    }

    /**
     * Check if a given route path matches this endpoint
     */
    public function matchesRoute(string $path): bool
    {
        $pattern = str_replace('api/', '', $this->routePattern());
        $path = str_replace('api/', '', $path);

        // Exact-segment match only: str_contains let an `incidents`-scoped
        // token authorize `v1/incidents-by-no`. Subpaths (`v1/incidents/5`)
        // stay in scope; sibling routes sharing the prefix do not.
        return $path === $pattern || str_starts_with($path, $pattern.'/');
    }
}
