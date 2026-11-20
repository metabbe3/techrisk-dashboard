<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\Transports\NetcoreTransport;
use App\Models\Incident;
use App\Models\InvestigationFormRequest;
use App\Models\Setting;
use App\Models\User;
use App\Support\InvestigationFormMailFactory;
use App\Support\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/**
 * The From NAME the investigation-form mails actually carry. The save →
 * Setting → apply chain is pinned in EmailSettingsTest; these pin the LAST
 * hop — the envelope the transport reads ($from->getName()) — plus the one
 * production trap: a mailer resolved BEFORE the save keeps the old name
 * until its process restarts (that is why save() calls queue:restart).
 */
class InvestigationFormMailFromTest extends TestCase
{
    use RefreshDatabase;

    private const CAPTURE = [];

    /**
     * A transport that records the sent Symfony Emails so the From header
     * can be asserted exactly as NetcoreTransport would read it.
     */
    private function captureTransport(string $key): TransportInterface
    {
        config()->set('mail.mailers.capture', ['transport' => 'capture']);

        return new class($key) implements TransportInterface
        {
            public function __construct(private readonly string $key) {}

            public function send(RawMessage $message, ?Envelope $envelope = null): ?\Symfony\Component\Mailer\SentMessage
            {
                config()->set("mail.capture.{$this->key}", $message);

                return null;
            }

            public function __toString(): string
            {
                return 'capture://'.$this->key;
            }
        };
    }

    private function sendRequestMail(string $captureKey): void
    {
        // The mailer NAME must exist in mail.mailers before the manager
        // resolves it; Mail::extend only supplies the transport creator.
        config()->set('mail.mailers.capture', ['transport' => 'capture']);
        $incident = Incident::factory()->create();
        $request = InvestigationFormRequest::create([
            'incident_id' => $incident->id,
            'team' => 'Payments',
            'status' => InvestigationFormRequest::STATUS_PENDING,
            'expires_at' => now()->addDays(14),
            'created_by' => User::factory()->create()->id,
        ]);
        $recipient = $request->recipients()->create([
            'email' => 'pic@team.id',
            'token_hash' => '',
            'emailed_at' => now(),
        ]);
        $plain = $recipient->linkToken();

        ['mail' => $mail] = InvestigationFormMailFactory::requestMail($recipient, $plain, '');

        Mail::extend('capture', fn () => $this->captureTransport($captureKey));
        Mail::to('pic@team.id')->send($mail);
    }

    public function test_the_envelope_from_name_follows_the_saved_setting(): void
    {
        Setting::set('mail_from_name', 'Technichal Risk');
        config(['mail.from.name' => 'Example']); // the pre-save default
        MailSettings::apply();

        config(['mail.default' => 'capture']);
        $this->sendRequestMail('after');

        /** @var \Symfony\Component\Mime\Email $sent */
        $sent = config('mail.capture.after');
        $this->assertNotNull($sent);
        $from = $sent->getFrom()[0];
        $this->assertSame('noreply-techrisk@dana.id', $from->getAddress());
        $this->assertSame('Technichal Risk', $from->getName());
    }

    public function test_a_mailer_resolved_before_the_save_keeps_the_old_name_until_restart(): void
    {
        // The prod trap: a queue worker that resolved its mailer BEFORE the
        // save keeps that mailer (and its alwaysFrom) for the whole process
        // lifetime — MailSettings::apply() changes config, but the Manager
        // caches the resolved Mailer per name. This is exactly why save()
        // must queue:restart (kill) every worker.
        config(['mail.from.name' => 'Example', 'mail.default' => 'capture']);
        $this->sendRequestMail('stale'); // worker boot + first send

        Setting::set('mail_from_name', 'Technichal Risk');
        MailSettings::apply(); // what save() does, MINUS queue:restart
        $this->sendRequestMail('stale'); // same key — the second send overwrites the capture

        $name = config('mail.capture.stale')?->getFrom()[0]?->getName();

        $this->assertSame(
            'Example',
            $name,
            'a mailer resolved before the save never re-reads config — only a process restart picks the new name',
        );
    }

    public function test_netcore_payload_carries_the_saved_name(): void
    {
        Setting::set('mail_from_name', 'Technichal Risk');
        MailSettings::apply();
        config(['mail.from.name' => 'Technichal Risk', 'mail.from.address' => 'noreply-techrisk@dana.id']);

        $email = new \Symfony\Component\Mime\Email;
        $email->from(new \Symfony\Component\Mime\Address('noreply-techrisk@dana.id', 'Technichal Risk'));
        $email->to('pic@team.id');
        $email->subject('s');
        $email->text('body');

        $transport = new NetcoreTransport('key', 'https://netcore.test');
        $payload = (new \ReflectionMethod($transport, 'buildPayload'))->invoke($transport, $email);

        $this->assertSame('Technichal Risk', $payload['from']['name']);
        $this->assertSame('noreply-techrisk@dana.id', $payload['from']['email']);
    }
}
