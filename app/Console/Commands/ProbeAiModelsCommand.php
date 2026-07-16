<?php

namespace App\Console\Commands;

use App\Services\Ai\Concerns\InteractsWithAiApi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Probe every model the LiteLLM gateway knows, one by one, to find the ones our
 * app breaks on. The health ping (1-token, no tools) can't detect models that
 * are reachable but REJECT tool-calling — which is the suspected cause of many
 * "erroring" models, since chat/agent requests attach `tools`.
 *
 * Credentials come from the app's configured source (AiSetting/.env), never
 * assumed present in .env directly.
 */
class ProbeAiModelsCommand extends Command
{
    use InteractsWithAiApi;

    protected $signature = 'ai:probe-models
                            {--model= : probe a single model id instead of all}
                            {--limit=0 : probe only the first N models (0 = all)}
                            {--plain-only : skip the with-tools probe}';

    protected $description = 'Probe each gateway model: plain completion vs with-tools, to find tool-incompatible models';

    public function handle(): int
    {
        if (blank($this->getApiKey()) || blank($this->getBaseUrl())) {
            $this->error('AI gateway not configured (api_key/base_url empty). Set them on the AI Settings page or in .env.');

            return 1;
        }

        $base = rtrim($this->getBaseUrl(), '/');

        $models = $this->option('model')
            ? collect([$this->option('model')])
            : $this->enumerateModels($base);

        if ($models->isEmpty()) {
            $this->error('No models returned by GET /v1/models.');

            return 1;
        }

        if (($limit = (int) $this->option('limit')) > 0) {
            $models = $models->take($limit);
        }

        $this->info('Probing '.$models->count()." model(s) at {$base} ...");
        $this->line('');

        $rows = [];
        foreach ($models as $id) {
            $plain = $this->probe($base, $id, false);

            if ($this->option('plain-only') || ! $plain['ok']) {
                $tools = ['ok' => null, 'status' => 0, 'err' => $plain['ok'] ? null : 'skipped (plain failed)'];
            } else {
                $tools = $this->probe($base, $id, true);
            }

            $rows[] = [
                'model' => $id,
                'plain' => $plain['ok'] ? 'OK' : 'HTTP '.$plain['status'],
                'tools' => $tools['ok'] === null ? 'skipped' : ($tools['ok'] ? 'OK' : 'HTTP '.$tools['status']),
                'verdict' => $this->verdict($plain, $tools),
                'error' => Str::limit($plain['ok'] ? ($tools['err'] ?? '') : ($plain['err'] ?? ''), 120),
                '_plain' => $plain,
                '_tools' => $tools,
            ];
        }

        // Single-model mode: dump full errors so they can be debugged directly.
        if ($this->option('model') && isset($rows[0])) {
            $this->line('');
            $this->line('PLAIN full error: '.($rows[0]['_plain']['err'] ?? '(OK)'));
            $this->line('TOOLS full error: '.($rows[0]['_tools']['err'] ?? '(OK)'));
        }

        // Strip the debug sub-arrays before rendering the table.
        $rows = array_map(function ($r) {
            unset($r['_plain'], $r['_tools']);

            return $r;
        }, $rows);

        $order = ['tools_unsupported' => 0, 'plain_err' => 1, 'ok' => 2, 'skipped' => 3];
        $sorted = collect($rows)->sortBy(fn ($r) => $order[$r['verdict']] ?? 9)->values();

        $this->table(['model', 'plain', 'with_tools', 'verdict', 'error'], $sorted->map(fn ($r) => array_values($r))->all());

        $capable = collect($rows)->filter(fn ($r) => $r['verdict'] === 'ok')->pluck('model');
        $toolBreakers = collect($rows)->filter(fn ($r) => $r['verdict'] === 'tools_unsupported')->pluck('model');

        $this->line('');
        $this->info('TOOL-CAPABLE ('.$capable->count().'): '.$capable->implode(', '));
        if ($toolBreakers->isNotEmpty()) {
            $this->warn('TOOLS-UNSUPPORTED — do NOT send tools to these ('.$toolBreakers->count().'): '.$toolBreakers->implode(', '));
        }

        return 0;
    }

    private function enumerateModels(string $base): \Illuminate\Support\Collection
    {
        $res = Http::withHeaders($this->buildHeaders())->timeout(30)->get("{$base}/v1/models");

        if (! $res->successful()) {
            $this->error('GET /v1/models failed: HTTP '.$res->status().' '.$res->body());

            return collect();
        }

        return collect($res->json('data') ?? [])->pluck('id')->filter()->unique()->values();
    }

    /**
     * @return array{ok:bool, status:int, err:?string}
     */
    private function probe(string $base, string $model, bool $withTools): array
    {
        // Minimal payload — matches pingModel. temperature/max_tokens/etc. are
        // omitted because the gateway rejects them per-provider, which would
        // mask the real signal (we want to know if the MODEL responds, not
        // whether it likes our params).
        $payload = [
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => 'Say OK.']],
        ];

        if ($withTools) {
            $payload['tools'] = [[
                'type' => 'function',
                'function' => [
                    'name' => 'noop',
                    'description' => 'no-op tool used only to test function-calling support',
                    'parameters' => ['type' => 'object', 'properties' => new \stdClass, 'required' => []],
                ],
            ]];
        }

        try {
            $r = Http::withHeaders($this->buildHeaders())->timeout(30)->post("{$base}/chat/completions", $payload);
            $err = null;
            if (! $r->successful()) {
                $body = $r->json();
                $err = $body['error']['message'] ?? ($body['message'] ?? $r->body());
            }

            return ['ok' => $r->successful(), 'status' => $r->status(), 'err' => $err];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'err' => $e->getMessage()];
        }
    }

    private function verdict(array $plain, array $tools): string
    {
        if (! $plain['ok']) {
            return 'plain_err';
        }
        if ($tools['ok'] === null) {
            return 'skipped';
        }

        return $tools['ok'] ? 'ok' : 'tools_unsupported';
    }
}
