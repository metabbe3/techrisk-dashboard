<?php

namespace Database\Seeders;

use App\Models\ActionImprovement;
use App\Models\Incident;
use App\Models\Label;
use App\Models\StatusUpdate;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Event;

class DummyIncidentSeeder extends Seeder
{
    public function run(): void
    {
        // SECURITY: Never run in production!
        if (app()->environment('production')) {
            $this->command->error('DummyIncidentSeeder CANNOT run in production environment!');
            $this->command->warn('This seeder creates fake test data with dummy financial figures.');

            return;
        }

        // Get or create admin user for PIC
        $admin = User::where('email', 'admin@example.com')->first();
        if (! $admin) {
            $admin = User::create([
                'name' => 'Admin User',
                'email' => 'admin@example.com',
                'password' => bcrypt('password'),
            ]);
        }

        // Get or create labels
        $labels = Label::firstOrCreate(['name' => 'Payment']);
        $label2 = Label::firstOrCreate(['name' => 'API']);
        $label3 = Label::firstOrCreate(['name' => 'High Priority']);

        // Disable event observers during seeding to avoid notification issues
        Incident::unsetEventDispatcher();

        // Create dummy incident
        $incident = Incident::create([
            'title' => 'Payment Gateway API Timeout Incident',
            'summary' => 'A critical incident where the payment gateway API experienced intermittent timeouts during peak hours, resulting in failed transactions and user complaints. The issue was traced to a missing connection pool configuration in the load balancer.',
            'no' => 'INC-2025-001',
            'root_cause' => 'The load balancer was configured with insufficient connection pool settings for the payment gateway API. During peak traffic hours (09:00-11:00 and 14:00-16:00), the maximum connections were exhausted, causing new requests to timeout. Additionally, the retry logic in the payment service was not properly implemented, leading to duplicate charges in some cases.',
            'severity' => 'P1',
            'incident_type' => 'Tech',
            'incident_source' => 'Internal',
            'goc_upload' => true,
            'teams_upload' => true,
            'discovered_at' => '2025-01-15 09:30:00',
            'stop_bleeding_at' => '2025-01-15 11:45:00',
            'incident_date' => '2025-01-15',
            'entry_date_tech_risk' => '2025-01-15',
            'pic_id' => $admin->id,
            'reported_by' => 'John Doe (Engineering Team)',
            'third_party_client' => 'Payment Gateway Provider X',
            'potential_fund_loss' => 50000000,
            'fund_loss' => 12500000,
        ]);

        // Attach labels
        $incident->labels()->attach([$labels->id, $label2->id, $label3->id]);

        // Create status updates
        StatusUpdate::create([
            'incident_id' => $incident->id,
            'status' => 'Open',
            'notes' => 'Issue detected - payment transactions failing with timeout errors. Monitoring team alerted.',
            'updated_at' => '2025-01-15 09:30:00',
        ]);

        StatusUpdate::create([
            'incident_id' => $incident->id,
            'status' => 'In progress',
            'notes' => 'Root cause identified - connection pool exhaustion in load balancer. Implemented temporary fix by increasing pool size.',
            'updated_at' => '2025-01-15 10:15:00',
        ]);

        StatusUpdate::create([
            'incident_id' => $incident->id,
            'status' => 'In progress',
            'notes' => 'Service restored. Monitoring transaction success rates. Investigating duplicate charge issue.',
            'updated_at' => '2025-01-15 11:45:00',
        ]);

        StatusUpdate::create([
            'incident_id' => $incident->id,
            'status' => 'Finalization',
            'notes' => 'Post-incident review completed. Permanent fix scheduled for next sprint. All affected users compensated.',
            'updated_at' => '2025-01-16 14:00:00',
        ]);

        // Create action improvements
        ActionImprovement::create([
            'incident_id' => $incident->id,
            'title' => 'Implement Connection Pool Monitoring',
            'detail' => 'Set up real-time monitoring and alerting for connection pool metrics. Configure alerts at 70%, 85%, and 95% utilization.',
            'due_date' => '2025-02-01',
            'pic_email' => ['infra-team@example.com', 'sre@example.com'],
            'reminder' => true,
            'reminder_frequency' => 'daily',
            'status' => 'done',
        ]);

        ActionImprovement::create([
            'incident_id' => $incident->id,
            'title' => 'Review and Fix Retry Logic',
            'detail' => 'Audit all services that interact with external APIs. Ensure proper retry logic with exponential backoff and idempotency keys to prevent duplicate charges.',
            'due_date' => '2025-02-15',
            'pic_email' => ['backend-team@example.com'],
            'reminder' => true,
            'reminder_frequency' => 'weekly',
            'status' => 'pending',
        ]);

        ActionImprovement::create([
            'incident_id' => $incident->id,
            'title' => 'Load Balancer Configuration Review',
            'detail' => 'Comprehensive review of all load balancer configurations. Document optimal settings for each service type.',
            'due_date' => '2025-02-28',
            'pic_email' => ['infra-team@example.com', 'architecture@example.com'],
            'reminder' => true,
            'reminder_frequency' => 'weekly',
            'status' => 'pending',
        ]);

        ActionImprovement::create([
            'incident_id' => $incident->id,
            'title' => 'User Compensation Process',
            'detail' => 'Define and implement a standard process for compensating users affected by service failures. Include automated charge reversals and notification system.',
            'due_date' => '2025-01-30',
            'pic_email' => ['cs-team@example.com', 'finance@example.com'],
            'reminder' => false,
            'reminder_frequency' => null,
            'status' => 'done',
        ]);

        // Note: Investigation documents skipped due to schema mismatch
        // The investigation_documents table uses file_path, markdown_path, etc.
        // not title/content/document_type/status

        $this->command->info('Dummy incident created successfully!');
        $this->command->info('Incident ID: INC-2025-001');
        $this->command->info('API Endpoints:');

        $baseUrl = config('app.url');
        $this->command->info("  JSON:   {$baseUrl}/api/v1/incidents-by-no/INC-2025-001");
        $this->command->info("  Markdown: {$baseUrl}/api/v1/incidents-by-no/INC-2025-001/markdown");
    }
}
