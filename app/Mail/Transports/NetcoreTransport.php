<?php

namespace App\Mail\Transports;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * Sends mail through the Netcore Cloud (Pepipost) v5 API.
 *
 * Netcore uses an `api_key` header (not Bearer) and a JSON payload of
 * from / subject / content[] / personalizations[]. Registered as the
 * `netcore` mail transport via Mail::extend() in AppServiceProvider.
 */
class NetcoreTransport implements TransportInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $timeout = 30,
    ) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        // Global runtime kill-switch (admin-toggleable from Email Settings).
        if (! Setting::get('netcore_enabled', true)) {
            Log::info('[Netcore] Email suppressed (netcore_enabled=false).');

            return null;
        }

        $apiKey = Setting::get('netcore_api_key', $this->apiKey);
        $baseUrl = Setting::get('netcore_base_url', $this->baseUrl);

        if (blank($apiKey)) {
            throw new TransportException('Netcore API key is not configured (Email Settings or NETCORE_API_KEY).');
        }

        if (! $message instanceof Email) {
            throw new TransportException('Netcore transport requires an Email message instance.');
        }

        $payload = $this->buildPayload($message);

        try {
            $response = Http::withHeaders([
                'api_key' => $apiKey,
                'content-type' => 'application/json',
            ])
                ->timeout($this->timeout)
                ->post(rtrim($baseUrl, '/').'/v5/mail/send', $payload);
        } catch (\Throwable $e) {
            throw new TransportException('Netcore request failed: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw new TransportException(
                'Netcore request failed: HTTP '.$response->status().' — '.$response->body()
            );
        }

        return new SentMessage($message, $envelope ?? Envelope::create($message));
    }

    private function buildPayload(Email $email): array
    {
        $from = $email->getFrom()[0] ?? null;

        $content = [];
        if ($html = $this->bodyToString($email->getHtmlBody())) {
            $content[] = ['type' => 'html', 'value' => $html];
        }
        if ($text = $this->bodyToString($email->getTextBody())) {
            $content[] = ['type' => 'plain', 'value' => $text];
        }

        $personalization = ['to' => $this->mapAddresses($email->getTo() ?: [])];
        if ($cc = $email->getCc()) {
            $personalization['cc'] = $this->mapAddresses($cc);
        }
        if ($bcc = $email->getBcc()) {
            $personalization['bcc'] = $this->mapAddresses($bcc);
        }

        $payload = [
            'from' => [
                'email' => $from?->getAddress() ?? config('mail.from.address'),
                'name' => $from?->getName() ?: config('mail.from.name'),
            ],
            'subject' => $email->getSubject() ?? '(no subject)',
            'content' => $content ?: [['type' => 'plain', 'value' => '']],
            'personalizations' => [$personalization],
        ];

        if ($replyTo = $email->getReplyTo()) {
            $payload['reply_to'] = $this->mapAddresses($replyTo);
        }

        return $payload;
    }

    /**
     * @param  Address[]  $addresses
     * @return array<int, array{email: string, name?: string}>
     */
    private function mapAddresses(array $addresses): array
    {
        return collect($addresses)->map(fn (Address $a) => array_filter([
            'email' => $a->getAddress(),
            'name' => $a->getName(),
        ], fn ($v) => filled($v)))->values()->all();
    }

    private function bodyToString($body): ?string
    {
        if ($body === null) {
            return null;
        }

        return is_resource($body) ? stream_get_contents($body) : $body;
    }

    public function __toString(): string
    {
        return 'netcore://'.$this->baseUrl;
    }

    public function reset(): void
    {
        // no stateful resources to reset
    }
}
