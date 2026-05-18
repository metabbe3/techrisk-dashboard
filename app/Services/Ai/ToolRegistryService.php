<?php

namespace App\Services\Ai;

use App\Models\AgentTool;
use App\Services\Ai\Tools\ExternalToolExecutor;
use App\Services\WarRoom\WarRoomToolExecutor;
use App\Services\WarRoom\WarRoomToolRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ToolRegistryService
{
    public function __construct(
        private WarRoomToolRegistry $internalRegistry,
        private WarRoomToolExecutor $internalExecutor,
        private ExternalToolExecutor $externalExecutor,
    ) {}

    public function getToolDefinitions(?array $enabledTools = null): array
    {
        $definitions = [];

        // Internal tools (hardcoded)
        $internalDefs = $this->internalRegistry->getToolDefinitions($enabledTools);
        foreach ($internalDefs as $def) {
            $definitions[] = $def;
        }

        // External tools (database)
        $externalTools = $this->getExternalToolsCached();
        foreach ($externalTools as $tool) {
            if ($enabledTools !== null && ! in_array($tool->name, $enabledTools)) {
                continue;
            }
            $definitions[] = $tool->toToolDefinition();
        }

        return $definitions;
    }

    public function executeToolCall(array $toolCall): array
    {
        $name = $toolCall['function']['name'] ?? '';
        $callId = $toolCall['id'] ?? '';

        // Check if it's an internal tool
        if (in_array($name, $this->internalRegistry->getAllToolNames())) {
            return $this->internalExecutor->execute($toolCall);
        }

        // Check if it's an external tool
        $externalTool = AgentTool::where('name', $name)->where('is_active', true)->first();

        if ($externalTool) {
            $arguments = json_decode($toolCall['function']['arguments'] ?? '{}', true) ?? [];

            try {
                $result = $this->externalExecutor->execute($externalTool, $arguments);
            } catch (\Throwable $e) {
                Log::warning("[ToolRegistry] External tool '{$name}' failed", [
                    'error' => $e->getMessage(),
                ]);
                $result = "Tool execution failed: {$e->getMessage()}";
            }

            return [
                'role' => 'tool',
                'tool_call_id' => $callId,
                'content' => is_string($result) ? $result : json_encode($result),
            ];
        }

        return [
            'role' => 'tool',
            'tool_call_id' => $callId,
            'content' => "Unknown tool: {$name}",
        ];
    }

    public function getAllToolNames(): array
    {
        $names = $this->internalRegistry->getAllToolNames();

        $externalTools = $this->getExternalToolsCached();
        foreach ($externalTools as $tool) {
            $names[] = $tool->name;
        }

        return $names;
    }

    public function getAllToolsWithMeta(): array
    {
        $tools = [];

        // Internal tools
        foreach ($this->internalRegistry->getAllToolNames() as $name) {
            $tools[] = [
                'name' => $name,
                'category' => 'internal_db',
                'source' => 'internal',
            ];
        }

        // External tools
        $externalTools = $this->getExternalToolsCached();
        foreach ($externalTools as $tool) {
            $tools[] = [
                'name' => $tool->name,
                'display_name' => $tool->display_name,
                'category' => $tool->category,
                'source' => 'external',
                'executor_type' => $tool->executor_type,
            ];
        }

        return $tools;
    }

    public function getExternalToolsCached()
    {
        return Cache::remember('agent_tools_external_active', 300, fn () => AgentTool::active()->ordered()->get());
    }

    public function clearCache(): void
    {
        Cache::forget('agent_tools_external_active');
    }
}
