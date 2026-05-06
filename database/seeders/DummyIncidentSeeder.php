<?php

namespace Database\Seeders;

use App\Models\ActionImprovement;
use App\Models\Incident;
use App\Models\Label;
use App\Models\StatusUpdate;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class DummyIncidentSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->error('Cannot run in production!');

            return;
        }

        Incident::unsetEventDispatcher();

        // Users
        $admin = User::firstOrCreate(
            ['email' => 'admin@techrisk.com'],
            ['name' => 'Admin', 'password' => bcrypt('password')]
        );

        $pics = collect([
            User::firstOrCreate(['email' => 'ahmad@techrisk.com'], ['name' => 'Ahmad Rizki', 'password' => bcrypt('password')]),
            User::firstOrCreate(['email' => 'siti@techrisk.com'], ['name' => 'Siti Nurhaliza', 'password' => bcrypt('password')]),
            User::firstOrCreate(['email' => 'budi@techrisk.com'], ['name' => 'Budi Santoso', 'password' => bcrypt('password')]),
        ]);

        // Labels
        $labelNames = ['Payment', 'API', 'High Priority', 'Security', 'Database', 'Network', 'Mobile', 'Fraud', 'Configuration', 'Monitoring'];
        $labels = collect($labelNames)->map(fn ($name) => Label::firstOrCreate(['name' => $name]));

        $incidents = [];

        // 2025 incidents (20)
        $data2025 = [
            ['2025-01-10 09:00', 'P1', 'Tech', 'Completed', 'Non fundLoss',    null, 0, 0, 0, 'Payment API timeout during peak hours', 'Connection pool exhaustion in load balancer', 'API', 'Internal'],
            ['2025-01-25 14:30', 'P2', 'Tech', 'Completed', 'Non fundLoss',    null, 0, 0, 0, 'Database replication lag spike', 'Replication slot not properly monitored', 'Database', 'Internal'],
            ['2025-02-05 10:00', 'P1', 'Non-tech', 'Completed', 'Confirmed loss', null, 75000000, 30000000, 20000000, 'Unauthorized wire transfer via social engineering', 'Staff fell for phishing email, credentials compromised', 'Fraud', 'External'],
            ['2025-02-20 08:15', 'P3', 'Tech', 'Completed', 'Non fundLoss',    null, 0, 0, 0, 'CDN cache invalidation delay', 'Cache purge API rate limited', 'Network', 'Internal'],
            ['2025-03-03 16:45', 'P2', 'Tech', 'Completed', 'Potential recovery', null, 40000000, 15000000, 35000000, 'Mobile app double charge on top-up', 'Race condition in payment processing', 'Mobile', 'Internal'],
            ['2025-03-18 11:00', 'P4', 'Tech', 'Completed', 'Non fundLoss',    null, 0, 0, 0, 'Dashboard chart rendering slow', 'Unoptimized SQL query for monthly stats', 'Database', 'Internal'],
            ['2025-04-01 07:30', 'P1', 'Tech', 'Completed', 'Non fundLoss',    null, 0, 0, 0, 'SMS OTP service outage', 'Vendor API endpoint changed without notice', 'API', 'External'],
            ['2025-04-15 13:00', 'P2', 'Non-tech', 'Completed', 'Confirmed loss', null, 50000000, 25000000, 10000000, 'Internal fraud — fake refund processing', 'Collusion between customer service and external party', 'Fraud', 'Internal'],
            ['2025-05-02 09:20', 'P3', 'Tech', 'Completed', 'Non fundLoss',    null, 0, 0, 0, 'Email notification service degraded', 'SMTP relay hit rate limit', 'Configuration', 'External'],
            ['2025-05-20 10:00', 'P1', 'Tech', 'Completed', 'Potential recovery', null, 60000000, 10000000, 55000000, 'Fund transfer to wrong account', 'UI bug allowed account number edit after confirmation', 'Payment', 'Internal'],
            ['2025-06-08 14:00', 'P4', 'Tech', 'Completed', 'Non fundLoss',    null, 0, 0, 0, 'Scheduled maintenance window exceeded', 'Migration script took longer than estimated', 'Database', 'Internal'],
            ['2025-06-22 08:00', 'P2', 'Tech', 'Completed', 'Non fundLoss',    null, 0, 0, 0, 'Kubernetes pod crash loop', 'OOM kill due to memory leak in Go service', 'Monitoring', 'Internal'],
            ['2025-07-10 15:30', 'P3', 'Non-tech', 'Completed', 'Non fundLoss', null, 0, 0, 0, 'Vendor SLA breach — ATM downtime', 'Vendor failed patching schedule', 'Network', 'External'],
            ['2025-07-28 11:45', 'P1', 'Tech', 'Completed', 'Non fundLoss',    null, 0, 0, 0, 'Core banking API latency spike', 'DNS resolution failure at primary provider', 'Network', 'External'],
            ['2025-08-12 09:00', 'P4', 'Tech', 'Completed', 'Non fundLoss',    null, 0, 0, 0, 'Log aggregation pipeline delay', 'Elasticsearch cluster rebalancing', 'Monitoring', 'Internal'],
            ['2025-08-30 16:00', 'P2', 'Tech', 'Completed', 'Confirmed loss',   null, 30000000, 15000000, 5000000, 'QR payment tampering incident', 'Insufficient signature validation on QR codes', 'Payment', 'Internal'],
            ['2025-09-15 10:30', 'P3', 'Tech', 'Completed', 'Non fundLoss',    null, 0, 0, 0, 'Redis cluster failover incident', 'Sentinel quorum lost during network partition', 'Database', 'Internal'],
            ['2025-10-01 08:00', 'P1', 'Non-tech', 'Completed', 'Potential recovery', null, 80000000, 20000000, 70000000, 'Card skimming device found at branch ATM', 'Physical security inspection missed device', 'Security', 'External'],
            ['2025-11-05 13:30', 'P2', 'Tech', 'Completed', 'Non fundLoss',    null, 0, 0, 0, 'API gateway rate limiting misconfigured', 'Config pushed to production without review', 'Configuration', 'Internal'],
            ['2025-11-20 07:00', 'P4', 'Tech', 'Completed', 'Non fundLoss',    null, 0, 0, 0, 'Certificate expiry warning missed', 'Monitoring alert threshold set too low', 'Security', 'Internal'],
        ];

        foreach ($data2025 as $i => $d) {
            $incidents[] = $this->createIncident(
                date: $d[0], severity: $d[1], type: $d[2], status: $d[3],
                fundStatus: $d[4], classification: 'Incident', pic: $pics[$i % 3],
                potentialLoss: $d[5], fundLoss: $d[6], recoveredFund: $d[7],
                title: $d[9], rootCause: $d[10], labelKeyword: $d[11], source: $d[12],
                no: '2025_IN_'.str_pad($i + 1, 4, '0', STR_PAD_LEFT)
            );
        }

        // 2026 incidents (25)
        $data2026 = [
            ['2026-01-08 10:00', 'P1', 'Tech', 'Completed', 'Non fundLoss',      null, 0, 0, 0, 'Core banking service outage 4 hours', 'Database deadlock from unoptimized batch job', 'Database', 'Internal'],
            ['2026-01-22 14:00', 'P2', 'Tech', 'Completed', 'Confirmed loss',     null, 45000000, 20000000, 8000000, 'Suspicious login led to unauthorized transfers', 'MFA bypass via SIM swap attack', 'Security', 'External'],
            ['2026-02-03 09:30', 'P3', 'Tech', 'Completed', 'Non fundLoss',      null, 0, 0, 0, 'Web app session timeout too short', 'Session config overwritten during deploy', 'Configuration', 'Internal'],
            ['2026-02-18 11:00', 'P1', 'Tech', 'Completed', 'Potential recovery',  null, 55000000, 15000000, 40000000, 'Batch payment processing error', 'Duplicate detection flag not set on batch jobs', 'Payment', 'Internal'],
            ['2026-03-02 08:00',  'P4', 'Tech', 'Completed', 'Non fundLoss',      null, 0, 0, 0, 'CI/CD pipeline timeout', 'Runner capacity insufficient for parallel jobs', 'Monitoring', 'Internal'],
            ['2026-03-15 15:00', 'P2', 'Non-tech', 'Completed', 'Non fundLoss',   null, 0, 0, 0, 'Third-party vendor data leak', 'Vendor exposed API key in public repo', 'Security', 'External'],
            ['2026-03-28 10:30', 'P3', 'Tech', 'Completed', 'Non fundLoss',      null, 0, 0, 0, 'Mobile app crash on iOS 19', 'Deprecated API call in WKWebView', 'Mobile', 'Internal'],
            ['2026-04-05 07:00',  'P1', 'Tech', 'Completed', 'Non fundLoss',      null, 0, 0, 0, 'DDoS attack on payment gateway', 'Insufficient rate limiting at edge', 'Network', 'External'],
            ['2026-04-12 13:00', 'P2', 'Tech', 'Finalization', 'Confirmed loss',  null, 35000000, 18000000, 0, 'ATM cash dispenser malfunction', 'Firmware bug caused double dispensing', 'Payment', 'Internal'],
            ['2026-04-20 09:00',  'P4', 'Tech', 'Completed', 'Non fundLoss',      null, 0, 0, 0, 'Grafana dashboard not loading', 'Proxy misconfiguration after infra upgrade', 'Monitoring', 'Internal'],
            ['2026-04-28 16:00', 'P1', 'Non-tech', 'In progress', 'Potential recovery', null, 90000000, 30000000, 0, 'Insider threat — data exfiltration attempt', 'Employee accessed systems beyond role scope', 'Security', 'Internal'],
            ['2026-05-01 08:30',  'P3', 'Tech', 'In progress', 'Non fundLoss',   null, 0, 0, 0, 'Kubernetes node not ready', 'Disk pressure from uncleaned log files', 'Monitoring', 'Internal'],
            ['2026-05-03 11:00', 'P2', 'Tech', 'Open', 'Non fundLoss',          null, 0, 0, 0, 'API response time degradation', 'Slow query from new reporting feature', 'API', 'Internal'],
        ];

        foreach ($data2026 as $i => $d) {
            $incidents[] = $this->createIncident(
                date: $d[0], severity: $d[1], type: $d[2], status: $d[3],
                fundStatus: $d[4], classification: 'Incident', pic: $pics[$i % 3],
                potentialLoss: $d[5], fundLoss: $d[6], recoveredFund: $d[7],
                title: $d[9], rootCause: $d[10], labelKeyword: $d[11], source: $d[12],
                no: '2026_IN_'.str_pad($i + 1, 4, '0', STR_PAD_LEFT)
            );
        }

        // Non Incident severity (2026) — for Non Incident tab
        for ($i = 1; $i <= 4; $i++) {
            $incidents[] = $this->createIncident(
                date: "2026-0{$i}-15 10:00", severity: 'Non Incident', type: 'Non-tech',
                status: 'Completed', fundStatus: 'Non fundLoss', classification: 'Incident',
                pic: $pics[$i % 3], potentialLoss: null, fundLoss: 0, recoveredFund: 0,
                title: "False alarm #{$i} — monitoring threshold adjusted",
                rootCause: 'Alert threshold too sensitive, no actual incident',
                labelKeyword: 'Monitoring', source: 'Internal',
                no: '2026_IN_NI_'.str_pad($i, 3, '0', STR_PAD_LEFT)
            );
        }

        // Issues (2026)
        $issues = [
            ['2026-01-20 10:00', 'P3', 'Tech', 'Completed', 'Non fundLoss', 'SSO certificate renewal oversight', 'Certificate auto-renewal not configured', 'Configuration', 'Internal'],
            ['2026-02-14 09:00', 'P2', 'Tech', 'Completed', 'Non fundLoss', 'Load balancer health check false positive', 'Health check endpoint returning 200 on error', 'Network', 'Internal'],
            ['2026-03-10 14:00', 'P4', 'Tech', 'Completed', 'Non fundLoss', 'Staging environment database drift', 'Schema migration not applied to staging', 'Database', 'Internal'],
            ['2026-04-08 11:00', 'P3', 'Non-tech', 'Finalization', 'Non fundLoss', 'Vendor contract renewal overdue', 'Procurement team missed renewal date', 'API', 'External'],
            ['2026-05-02 08:00',  'P2', 'Tech', 'Open', 'Non fundLoss', 'Memory leak in notification service', 'Unbounded goroutine growth under load', 'API', 'Internal'],
            ['2026-04-25 15:00', 'P4', 'Tech', 'Completed', 'Non fundLoss', 'Backup job skipped due to disk space', 'Monitoring didn\'t alert on disk usage', 'Database', 'Internal'],
            ['2026-03-22 13:30', 'P3', 'Tech', 'Completed', 'Non fundLoss', 'DNS resolution intermittent failures', 'Recursive resolver cache corruption', 'Network', 'External'],
            ['2026-02-28 16:00', 'P4', 'Tech', 'Completed', 'Non fundLoss', 'Test environment port conflict', 'Two services bound to same port after restart', 'Configuration', 'Internal'],
            ['2026-01-30 09:30', 'P3', 'Non-tech', 'Completed', 'Non fundLoss', 'Compliance audit finding — access review', 'Quarterly access review not completed', 'Security', 'Internal'],
            ['2026-04-15 10:30', 'P2', 'Tech', 'In progress', 'Non fundLoss', 'Microservice deployment rollback needed', 'Canary metrics indicated regression', 'API', 'Internal'],
        ];

        foreach ($issues as $i => $d) {
            $incidents[] = $this->createIncident(
                date: $d[0], severity: $d[1], type: $d[2], status: $d[3],
                fundStatus: $d[4], classification: 'Issue', pic: $pics[$i % 3],
                potentialLoss: null, fundLoss: 0, recoveredFund: 0,
                title: $d[5], rootCause: $d[6], labelKeyword: $d[7], source: $d[8],
                no: '2026_IS_'.str_pad($i + 1, 4, '0', STR_PAD_LEFT)
            );
        }

        // Action improvements for each incident
        $aiTemplates = [
            ['Implement automated monitoring alert', 'Configure real-time alerts with escalation policy'],
            ['Update runbook documentation', 'Document step-by-step resolution procedure'],
            ['Conduct root cause analysis review', 'Schedule post-incident review with all stakeholders'],
            ['Implement preventive control', 'Add automated checks to prevent recurrence'],
            ['Schedule team training', 'Organize knowledge sharing session on lessons learned'],
        ];

        foreach ($incidents as $incident) {
            $count = rand(1, 3);
            for ($j = 0; $j < $count; $j++) {
                $template = $aiTemplates[($incident->id + $j) % count($aiTemplates)];
                ActionImprovement::create([
                    'incident_id' => $incident->id,
                    'title' => $template[0],
                    'detail' => $template[1],
                    'due_date' => Carbon::parse($incident->incident_date)->addWeeks(rand(2, 8))->format('Y-m-d'),
                    'pic_email' => [$incident->pic?->email ?? 'admin@techrisk.com'],
                    'reminder' => true,
                    'reminder_frequency' => $j === 0 ? 'daily' : 'weekly',
                    'status' => $incident->incident_status === 'Completed' ? (rand(0, 1) ? 'done' : 'pending') : 'pending',
                ]);
            }
        }

        $incidentCount = count($incidents);
        $this->command->info("Created {$incidentCount} incidents (incl. issues and non-incidents) with action improvements.");
    }

    private function createIncident(
        string $date,
        string $severity,
        string $type,
        string $status,
        string $fundStatus,
        string $classification,
        $pic,
        $potentialLoss,
        $fundLoss,
        $recoveredFund,
        string $title,
        string $rootCause,
        string $labelKeyword,
        string $source,
        string $no
    ): Incident {
        $incidentDate = Carbon::parse($date);
        $stopBleedingAt = null;

        // Calculate stop_bleeding_at based on severity and fund status
        if ($status !== 'Open') {
            if (in_array($fundStatus, ['Confirmed loss', 'Potential recovery'])) {
                // Fund loss: stop bleeding in days
                $days = match ($severity) {
                    'P1' => rand(1, 3),
                    'P2' => rand(3, 7),
                    'P3' => rand(5, 14),
                    default => rand(1, 7),
                };
                $stopBleedingAt = $incidentDate->copy()->addDays($days)->setTime(rand(14, 17), rand(0, 59));
            } else {
                // Non-fund: stop bleeding in minutes/hours
                $minutes = match ($severity) {
                    'P1' => rand(30, 120),
                    'P2' => rand(60, 480),
                    'P3' => rand(120, 1440),
                    'P4' => rand(60, 2880),
                    default => rand(60, 480),
                };
                $stopBleedingAt = $incidentDate->copy()->addMinutes($minutes);
            }
        }

        $incident = Incident::create([
            'no' => $no,
            'title' => $title,
            'summary' => "Summary: {$title}. Investigation found that {$rootCause}. Impact was assessed and containment measures were applied promptly.",
            'root_cause' => $rootCause,
            'severity' => $severity,
            'classification' => $classification,
            'incident_type' => $type,
            'incident_source' => $source,
            'incident_status' => $status,
            'fund_status' => $fundStatus,
            'incident_date' => $incidentDate,
            'entry_date_tech_risk' => $incidentDate->copy()->addHours(rand(0, 24)),
            'discovered_at' => $incidentDate,
            'stop_bleeding_at' => $stopBleedingAt,
            'pic_id' => $pic->id,
            'reported_by' => $pic->name,
            'potential_fund_loss' => $potentialLoss,
            'fund_loss' => $fundLoss,
            'recovered_fund' => $recoveredFund,
            'goc_upload' => rand(0, 1) === 1,
            'teams_upload' => rand(0, 1) === 1,
        ]);

        // Attach matching label
        $label = Label::where('name', $labelKeyword)->first();
        if ($label) {
            $incident->labels()->attach($label->id);
        }
        // Attach a random second label
        $randomLabel = Label::inRandomOrder()->where('id', '!=', $label?->id)->first();
        if ($randomLabel) {
            $incident->labels()->attach($randomLabel->id);
        }

        return $incident;
    }
}
