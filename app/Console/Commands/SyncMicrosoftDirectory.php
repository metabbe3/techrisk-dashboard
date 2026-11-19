<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncMicrosoftDirectory extends Command
{
    protected $signature = 'users:sync-directory {--dry-run : List what would be synced without writing}';

    protected $description = 'Pull the org directory (name, email, job title, team, org-chart manager) from Microsoft Entra ID via Graph and upsert users (login-disabled, like PIC directory rows)';

    public function handle(): int
    {
        $config = config('services.microsoft');

        foreach (['client_id', 'client_secret', 'tenant'] as $key) {
            if (empty($config[$key])) {
                $this->error("services.microsoft.{$key} is not configured (.env: MICROSOFT_".strtoupper($key).').');

                return self::FAILURE;
            }
        }

        // App-only token against the same app registration the SSO login uses.
        // Requires the Application permission User.Read.All + admin consent.
        $token = Http::asForm()->post(
            "https://login.microsoftonline.com/{$config['tenant']}/oauth2/v2.0/token",
            [
                'client_id' => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'grant_type' => 'client_credentials',
                'scope' => 'https://graph.microsoft.com/.default',
            ],
        );

        if (! $token->successful() || ! $accessToken = $token->json('access_token')) {
            $this->error('Token request failed: '.$token->body());

            return self::FAILURE;
        }

        $rows = [];
        $url = 'https://graph.microsoft.com/v1.0/users?'.http_build_query([
            '$select' => 'displayName,mail,userPrincipalName,accountEnabled,jobTitle,department',
            // Direct manager per row — every org member is synced, so the
            // 1-level links chain into the full org chart locally.
            '$expand' => 'manager($select=mail,userPrincipalName)',
            '$filter' => 'accountEnabled eq true',
            '$top' => '999',
        ]);

        while ($url !== null) {
            $response = Http::withToken($accessToken)->get($url);

            if (! $response->successful()) {
                if ($response->json('error.code') === 'Authorization_RequestDenied') {
                    // App-only token carries no Graph roles — the registration
                    // lacks the APPLICATION permission User.Read.All (or its
                    // admin consent). Delegated SSO scopes can't help here.
                    $this->error('App registration lacks the Graph APPLICATION permission User.Read.All (admin-consented) — Entra admin center → App registrations → API permissions → add it, then Grant admin consent.');
                } else {
                    $this->error('Graph request failed: '.$response->body());
                }

                return self::FAILURE;
            }

            foreach ($response->json('value', []) as $entry) {
                // mail is null when Exchange isn't provisioned — UPN is the fallback.
                $email = $entry['mail'] ?? null;
                if (empty($email) && str_contains((string) ($entry['userPrincipalName'] ?? ''), '@')) {
                    $email = $entry['userPrincipalName'];
                }
                if (empty($email)) {
                    continue;
                }

                $manager = $entry['manager'] ?? null;
                $managerEmail = null;
                if (is_array($manager)) {
                    $managerEmail = $manager['mail'] ?? null;
                    if (empty($managerEmail) && str_contains((string) ($manager['userPrincipalName'] ?? ''), '@')) {
                        $managerEmail = $manager['userPrincipalName'];
                    }
                }

                $rows[] = [
                    'name' => str_replace(['<', '>'], '', trim((string) ($entry['displayName'] ?? ''))) ?: strtok((string) $email, '@'),
                    'email' => strtolower(trim((string) $email)),
                    'job_title' => $this->clean($entry['jobTitle'] ?? null),
                    'department' => $this->clean($entry['department'] ?? null),
                    'manager_email' => $managerEmail !== null ? strtolower(trim((string) $managerEmail)) : null,
                ];
            }

            // ⚠ the key is the LITERAL '@odata.nextLink' (top level) —
            // json('@odata.nextLink') would split on the dot and return null.
            $url = $response->json()['@odata.nextLink'] ?? null;
        }

        if ($this->option('dry-run')) {
            $this->info(count($rows).' directory entries found. Sample:');
            foreach (array_slice($rows, 0, 10) as $row) {
                $this->line(sprintf(
                    '  %s <%s>%s%s',
                    $row['name'],
                    $row['email'],
                    $row['job_title'] !== null ? ' — '.$row['job_title'] : '',
                    $row['department'] !== null ? ' ('.$row['department'].')' : '',
                ));
            }

            return self::SUCCESS;
        }

        $stats = User::syncDirectoryRows($rows);
        $this->info(sprintf(
            '%d users: %d created, %d/%d manager links resolved (existing users keep name/roles/login untouched).',
            $stats['total'],
            $stats['created'],
            $stats['managers'],
            $stats['referenced'],
        ));

        return self::SUCCESS;
    }

    private function clean(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : str_replace(['<', '>'], '', $value);
    }
}
