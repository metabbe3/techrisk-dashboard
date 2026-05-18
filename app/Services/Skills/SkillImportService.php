<?php

namespace App\Services\Skills;

use App\Models\Skill;
use Illuminate\Support\Facades\File;

class SkillImportService
{
    public int $created = 0;

    public int $updated = 0;

    public int $skipped = 0;

    public function importFromRepository(string $repoPath): array
    {
        $this->created = 0;
        $this->updated = 0;
        $this->skipped = 0;

        if (! is_dir($repoPath)) {
            return ['error' => "Directory not found: {$repoPath}"];
        }

        $this->importSkills($repoPath);
        $this->importRoles($repoPath);

        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
        ];
    }

    protected function importSkills(string $repoPath): void
    {
        $skillsDir = $repoPath.'/skills';

        if (! is_dir($skillsDir)) {
            return;
        }

        $domains = File::directories($skillsDir);

        foreach ($domains as $domainPath) {
            $domain = basename($domainPath);
            $skillDirs = File::directories($domainPath);

            foreach ($skillDirs as $skillPath) {
                $skillFile = $skillPath.'/SKILL.md';

                if (! file_exists($skillFile)) {
                    $this->skipped++;

                    continue;
                }

                $this->importSkillFile($skillFile, $domain, basename($skillPath));
            }
        }
    }

    protected function importRoles(string $repoPath): void
    {
        $rolesDir = $repoPath.'/roles';

        if (! is_dir($rolesDir)) {
            return;
        }

        $roleDirs = File::directories($rolesDir);

        foreach ($roleDirs as $rolePath) {
            $skillFile = $rolePath.'/SKILL.md';

            if (! file_exists($skillFile)) {
                $this->skipped++;

                continue;
            }

            $roleName = basename($rolePath);
            $this->importSkillFile($skillFile, 'role', "role-{$roleName}");
        }
    }

    protected function importSkillFile(string $filePath, string $domain, string $sourceId): void
    {
        $content = File::get($filePath);
        $parsed = $this->parseSkillMarkdown($content);

        if (! $parsed) {
            $this->skipped++;

            return;
        }

        $frontmatter = $parsed['frontmatter'];
        $body = $parsed['body'];

        $name = $frontmatter['name'] ?? $sourceId;
        $displayName = $this->deriveDisplayName($name, $frontmatter);

        $data = [
            'name' => $name,
            'display_name' => $displayName,
            'description' => $frontmatter['description'] ?? null,
            'domain' => $domain,
            'content' => trim($body) ?: null,
            'frameworks' => $this->parseArrayField($frontmatter['frameworks'] ?? null),
            'tags' => $this->parseArrayField($frontmatter['tags'] ?? null),
            'difficulty' => $frontmatter['difficulty'] ?? null,
            'is_active' => true,
            'source' => 'unitoneai',
            'source_id' => $sourceId,
            'version' => $frontmatter['version'] ?? null,
        ];

        $existing = Skill::where('name', $name)->first();

        if ($existing) {
            $existing->update($data);
            $this->updated++;
        } else {
            $data['sort_order'] = 0;
            Skill::create($data);
            $this->created++;
        }
    }

    protected function parseSkillMarkdown(string $content): ?array
    {
        if (! preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)/s', $content, $matches)) {
            return null;
        }

        $yaml = $matches[1];
        $body = $matches[2];

        $frontmatter = $this->parseYaml($yaml);

        return [
            'frontmatter' => $frontmatter,
            'body' => trim($body),
        ];
    }

    protected function parseYaml(string $yaml): array
    {
        $result = [];
        $lines = explode("\n", $yaml);
        $currentKey = null;
        $inMultiline = false;
        $multilineValue = '';

        foreach ($lines as $line) {
            if ($inMultiline) {
                if (str_starts_with(trim($line), '- ') && ! str_starts_with($line, ' ')) {
                    $multilineValue .= trim(substr(trim($line), 2))."\n";

                    continue;
                }
                if (trim($line) === '' || preg_match('/^\S/', $line)) {
                    $result[$currentKey] = trim($multilineValue);
                    $inMultiline = false;
                    $multilineValue = '';
                } else {
                    $multilineValue .= trim($line)."\n";

                    continue;
                }
            }

            if (preg_match('/^(\w+):\s*(.*)$/', $line, $m)) {
                $key = $m[1];
                $value = trim($m[2]);

                if ($value === '' || $value === '|' || $value === '>') {
                    $currentKey = $key;
                    $inMultiline = true;
                    $multilineValue = '';

                    continue;
                }

                if (preg_match('/^\[(.+)\]$/', $value, $arrMatch)) {
                    $items = array_map('trim', explode(',', $arrMatch[1]));
                    $result[$key] = $items;

                    continue;
                }

                $result[$key] = $value;
            }
        }

        if ($inMultiline && $currentKey) {
            $result[$currentKey] = trim($multilineValue);
        }

        return $result;
    }

    protected function parseArrayField($value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $items = array_map('trim', explode(',', $value));

            return array_filter($items, fn ($i) => filled($i)) ?: null;
        }

        return null;
    }

    protected function deriveDisplayName(string $name, array $frontmatter): string
    {
        if (isset($frontmatter['description']) && is_string($frontmatter['description'])) {
            $firstLine = explode("\n", trim($frontmatter['description']))[0];
            $clean = preg_replace('/\s*Auto-invoked.*$/i', '', $firstLine);
            $clean = trim($clean, ' .');

            if (filled($clean) && mb_strlen($clean) < 100) {
                return $clean;
            }
        }

        return ucwords(str_replace(['-', '_'], ' ', $name));
    }
}
