<?php

namespace App\Services\Ai\Tools;

use App\Contracts\AgentToolInterface;
use App\Models\AgentTool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExternalToolExecutor
{
    public function execute(AgentTool $tool, array $arguments): string
    {
        return match ($tool->executor_type) {
            'http' => $this->executeHttpCall($tool, $arguments),
            'class' => $this->executeClassCall($tool, $arguments),
            default => throw new \InvalidArgumentException("Unknown executor type: {$tool->executor_type}"),
        };
    }

    private function executeHttpCall(AgentTool $tool, array $arguments): string
    {
        $config = $tool->http_config;

        if (empty($config) || empty($config['url'])) {
            return "Tool '{$tool->name}' is not properly configured: missing URL.";
        }

        try {
            $url = $this->resolvePlaceholders($config['url'], $arguments);
            $method = strtoupper($config['method'] ?? 'GET');
            $timeout = (int) ($config['timeout'] ?? 10);

            // SSRF guard: the URL (and its {placeholders}) can be influenced by
            // LLM-controlled arguments, which in turn consume untrusted text.
            // Block non-http(s) schemes, private/loopback/link-local/metadata
            // hosts, and (optionally) anything outside a per-tool allowlist.
            $allowedHosts = $config['allowed_hosts'] ?? null;
            if ($this->isUrlBlocked($url, $allowedHosts)) {
                Log::warning("[ExternalTool] Blocked SSRF attempt for '{$tool->name}'", ['url' => $url]);

                return "External tool '{$tool->display_name}' was blocked: target host is not allowed.";
            }

            $http = Http::timeout($timeout);

            // Apply authentication
            $http = $this->applyAuth($http, $config);

            // Apply custom headers
            if (! empty($config['headers']) && is_array($config['headers'])) {
                $http = $http->withHeaders($config['headers']);
            }

            // Build request body from template
            $body = null;
            if ($method !== 'GET' && ! empty($config['body_template'])) {
                $body = $this->resolvePlaceholders($config['body_template'], $arguments);
                $body = json_decode($body, true) ?? $body;
            }

            // For GET requests, add arguments as query params if no path params
            $query = $method === 'GET' ? $arguments : [];

            $response = match ($method) {
                'GET' => $http->get($url, $query),
                'POST' => $http->post($url, $body ?? $arguments),
                'PUT' => $http->put($url, $body ?? $arguments),
                'PATCH' => $http->patch($url, $body ?? $arguments),
                'DELETE' => $http->delete($url, $body ?? $arguments),
                default => $http->get($url, $query),
            };

            if ($response->failed()) {
                Log::warning("[ExternalTool] HTTP call failed for '{$tool->name}'", [
                    'status' => $response->status(),
                    'url' => $url,
                ]);

                return "External tool '{$tool->display_name}' returned HTTP {$response->status()}.";
            }

            $data = $response->json();

            return $this->extractResponse($data, $config['response_mapping'] ?? null);

        } catch (\Throwable $e) {
            Log::warning("[ExternalTool] Execution failed for '{$tool->name}'", [
                'error' => $e->getMessage(),
            ]);

            return "External tool '{$tool->display_name}' failed: {$e->getMessage()}";
        }
    }

    private function executeClassCall(AgentTool $tool, array $arguments): string
    {
        $class = $tool->custom_class;

        if (empty($class) || ! class_exists($class)) {
            return "Tool '{$tool->name}' custom class '{$class}' not found.";
        }

        $instance = app($class);

        if (! ($instance instanceof AgentToolInterface)) {
            return "Tool '{$tool->name}' class must implement AgentToolInterface.";
        }

        try {
            return $instance->execute($arguments);
        } catch (\Throwable $e) {
            Log::warning("[ExternalTool] Class execution failed for '{$tool->name}'", [
                'error' => $e->getMessage(),
            ]);

            return "External tool '{$tool->display_name}' failed: {$e->getMessage()}";
        }
    }

    private function resolvePlaceholders(string $template, array $arguments): string
    {
        return preg_replace_callback('/\{(\w+)\}/', function ($matches) use ($arguments) {
            $key = $matches[1];

            return (string) ($arguments[$key] ?? $matches[0]);
        }, $template);
    }

    /**
     * Reject URLs that are unsafe to fetch (SSRF defense). Blocks non-http(s)
     * schemes, hosts that resolve to private/loopback/link-local/metadata IPs,
     * and any host outside an optional allowlist. Fails closed (blocks) when the
     * host can't be resolved. Residual risk: DNS-rebinding could race resolution;
     * pinning the resolved IP at connect (curl RESOLVE) is the full mitigation.
     */
    private function isUrlBlocked(string $url, ?array $allowedHosts = null): bool
    {
        $parsed = parse_url($url);
        $scheme = strtolower($parsed['scheme'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true)) {
            return true;
        }

        $host = strtolower($parsed['host'] ?? '');
        if ($host === '') {
            return true;
        }

        if ($allowedHosts !== null) {
            $allowed = array_map('strtolower', $allowedHosts);

            if (! in_array($host, $allowed, true)) {
                return true;
            }
        }

        // IP literal → check directly; hostname → resolve and check every record.
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        if (empty($ips)) {
            return true;
        }

        foreach ($ips as $ip) {
            if ($this->isPrivateOrReservedIp($ip)) {
                return true;
            }
        }

        return false;
    }

    private function isPrivateOrReservedIp(string $ip): bool
    {
        // filter_var returns false when the IP IS private/reserved (NO_PRIV | NO_RES).
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    private function applyAuth($http, array $config)
    {
        $authType = $config['auth_type'] ?? 'none';
        $authKeyEnv = $config['auth_key_env'] ?? null;

        if ($authType === 'none' || empty($authKeyEnv)) {
            return $http;
        }

        $authValue = env($authKeyEnv);

        if (empty($authValue)) {
            return $http;
        }

        return match ($authType) {
            'bearer' => $http->withToken($authValue),
            'basic' => $http->withBasicAuth(
                $config['auth_username_env'] ? env($config['auth_username_env']) ?? '' : '',
                $authValue
            ),
            'api_key' => $http->withHeaders([$config['auth_header_name'] ?? 'X-API-Key' => $authValue]),
            default => $http,
        };
    }

    private function extractResponse(mixed $data, ?string $mapping): string
    {
        if (is_string($data)) {
            return $data;
        }

        if (empty($mapping)) {
            return is_array($data)
                ? collect($data)->take(10)->map(fn ($item) => is_array($item) ? json_encode($item, JSON_PRETTY_PRINT) : (string) $item)->implode("\n")
                : (string) $data;
        }

        // Simple dot-notation path extraction: $.results[*].title
        $extracted = $this->extractByPath($data, $mapping);

        if (empty($extracted)) {
            return 'No results found.';
        }

        if (is_array($extracted)) {
            return collect($extracted)->take(10)->map(function ($item) {
                if (is_array($item)) {
                    return collect($item)->map(fn ($v, $k) => "{$k}: {$v}")->implode(' | ');
                }

                return (string) $item;
            })->implode("\n");
        }

        return (string) $extracted;
    }

    private function extractByPath(mixed $data, string $path): mixed
    {
        // Strip leading $. if present
        $path = ltrim($path, '$.');
        $parts = explode('.', $path);

        $current = $data;
        foreach ($parts as $part) {
            if ($part === '*' || $part === '[*]') {
                if (is_array($current)) {
                    $current = array_values($current);
                }

                break;
            }

            if (is_array($current) && isset($current[$part])) {
                $current = $current[$part];
            } else {
                return null;
            }
        }

        return $current;
    }
}
