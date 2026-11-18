<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Skill;
use App\Models\WarRoomAgentConfig;
use App\Traits\ApiResponser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/skills — the DingTalk agent's window into the dashboard skill
 * library (Filament → Skills) and the War Room persona configs. On-demand
 * progressive disclosure: the index is a cheap catalog (metadata only), the
 * show route loads ONE body capped at 48k chars. Persona rows (role-<key>)
 * expose the system prompt + attached skill NAMES — the caller load_skill's
 * each body it actually needs, so the bundle itself stays small.
 */
class SkillController extends Controller
{
    use ApiResponser;

    /** Reply budget: bodies larger than this go out truncated (flag set). */
    private const MAX_CONTENT_CHARS = 48_000;

    public function index(Request $request): JsonResponse
    {
        $skills = Skill::active()->ordered()
            ->get(['name', 'display_name', 'description', 'domain', 'frameworks', 'tags'])
            ->map(fn (Skill $s) => [
                'name' => $s->name,
                'display_name' => $s->display_name,
                'description' => $s->description,
                'domain' => $s->domain,
                'frameworks' => $s->frameworks ?? [],
                'tags' => $s->tags ?? [],
            ]);

        $roles = WarRoomAgentConfig::active()->ordered()
            ->get(['role_key', 'display_name', 'description'])
            ->map(fn (WarRoomAgentConfig $a) => [
                'name' => 'role-'.$a->role_key,
                'display_name' => $a->display_name,
                'description' => $a->description,
                'domain' => 'role',
                'frameworks' => [],
                'tags' => [],
            ]);

        return $this->successResponse($roles->concat($skills)->values()->all(), 'Skills catalog retrieved.');
    }

    public function show(Request $request, string $name): JsonResponse
    {
        if (str_starts_with($name, 'role-')) {
            return $this->showRole(substr($name, strlen('role-')), $name);
        }

        $skill = Skill::active()->where('name', $name)->first();
        if ($skill === null) {
            return $this->errorResponse('Skill not found — call list_skills for what exists.', 404);
        }

        return $this->skillPayload($skill->name, (string) $skill->content);
    }

    /** A persona bundle: the system prompt + attached skill NAMES (never bodies). */
    private function showRole(string $roleKey, string $name): JsonResponse
    {
        $agent = WarRoomAgentConfig::active()->where('role_key', $roleKey)->first();
        if ($agent === null) {
            return $this->errorResponse('Persona not found — call list_skills for what exists.', 404);
        }

        $parts = ["## Persona system prompt\n\n".(string) $agent->system_prompt];
        $skillNames = $agent->skillRecords()->where('is_active', true)->pluck('name')->all();
        if ($skillNames !== []) {
            $parts[] = "## Attached skills (load with load_skill)\n- ".implode("\n- ", $skillNames);
        }

        return $this->skillPayload($name, implode("\n\n", $parts));
    }

    private function skillPayload(string $name, string $content): JsonResponse
    {
        $truncated = mb_strlen($content) > self::MAX_CONTENT_CHARS;
        if ($truncated) {
            $content = mb_substr($content, 0, self::MAX_CONTENT_CHARS);
        }

        return $this->successResponse([
            'name' => $name,
            'content' => $content,
            'truncated' => $truncated,
            'chars' => mb_strlen($content),
        ], 'Skill retrieved.');
    }
}
